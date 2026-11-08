<?php
/**
 * Z8 branch 9b (heikel/z8-vorlagen) on two WordPress sites: the other core
 * types of the kind post (decision 25), end to end through wp_site_push and
 * wp_site_revert. Site B is the staging site, in processes of its own; site
 * A, this process, the production site (as SyncContentTest).
 *
 * Both sites run Twenty Twenty-Five, the default theme of WordPress 7.1.3
 * (WP_DEFAULT_THEME, wp-includes/default-constants.php:437), which
 * wp_install() activates: a block theme with templates and template parts
 * as files, so the normal case of the branch (the same active block theme
 * on both sites) holds without a theme of the tests; the cases assert it.
 *
 * - The normal case: a template part changed on staging (the header, here
 *   as a row on both sites) and one new there (the footer, here only the
 *   file of the theme), a template new there (single, here only the file),
 *   the global styles changed there. They go over in one run, WordPress
 *   shows them here (get_block_template(), the user data of theme.json);
 *   the way back puts the changed ones back byte for byte and leaves the
 *   new rows as rests.
 * - A navigation and a reusable block changed on staging, a page that
 *   names both by their ids, a new unsynced pattern in a new pattern
 *   category: over and back, the ids by the pairs.
 * - The locks of the branch, found by the check before the first write: a
 *   template of another theme, a template part a template names that this
 *   site does not show, one of another theme, a new navigation that would
 *   become the one a navigation block without a menu shows, custom CSS
 *   with </style. A template part and a custom template the same run
 *   creates first are pending, not missing.
 * - The way back of a navigation that would move that navigation is left
 *   as «check needed».
 * - No deletion (decisions 21, 22).
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests\Integration;

use AB_MCP_IT_Sites;
use AB_MCP_Protected_Store;
use AB_MCP_REST_Controller;
use AB_MCP_Staging;
use AB_MCP_Sync_Anchor;
use AB_MCP_Sync_Backup;
use AB_MCP_Sync_Engine;
use AB_MCP_Sync_Exclusions;
use AB_MCP_Sync_Guard;
use AB_MCP_Sync_Import;
use AB_MCP_Sync_Pairs;
use AB_MCP_Sync_Post_Writer;
use AB_MCP_Sync_Report;
use AB_MCP_Sync_Resources;
use AB_MCP_Sync_Schema;
use AB_MCP_Tool_Registry;
use AB_MCP_Tools_Staging;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once AB_MCP_DIR . 'includes/sync/class-sync-import.php';
require_once AB_MCP_DIR . 'includes/sync/class-sync-pairs-tool.php';
require_once AB_MCP_DIR . 'includes/sync/class-sync-report.php';
require_once AB_MCP_DIR . 'includes/sync/class-sync-push.php';
require_once AB_MCP_DIR . 'includes/sync/class-sync-revert.php';
require_once AB_MCP_DIR . 'includes/tools/class-tools-staging.php';

final class SyncTemplatesTest extends TestCase {

	/** The theme both sites run. */
	private const THEME = 'twentytwentyfive';

	/** The date the objects made by insert carry, on both sites. */
	private const DATE = '2026-10-01 10:00:00';

	/** @var string|null DOCUMENT_ROOT before the case. */
	private $docroot_before;

	/** @var array[] The deletions the filters of a case saw (watch_deletions()). */
	private $deletions = array();

	protected function setUp(): void {
		AB_MCP_IT_Sites::reset( 'a' );
		AB_MCP_IT_Sites::reset( 'b' );
		self::wipe();
		$this->docroot_before     = $_SERVER['DOCUMENT_ROOT'] ?? null;
		$_SERVER['DOCUMENT_ROOT'] = AB_MCP_IT_Sites::docroot( 'a' );
		AB_MCP_Protected_Store::forget();
		AB_MCP_Sync_Schema::forget();
		AB_MCP_Sync_Backup::forget();
		AB_MCP_Sync_Guard::disarm();
		delete_option( AB_MCP_Sync_Guard::OPTION );
		delete_option( \AB_MCP_Change_Lock::OPTION );
		wp_set_current_user( self::admin() );
		self::assertIsArray( AB_MCP_Sync_Schema::install() );
		$this->deletions = array();
		// A page shown answers 200 with the title of the object (the sample
		// of decision 33); what php -S answers is the live probe's.
		AB_MCP_Sync_Engine::$probe = static function ( $url ) {
			return array(
				'status' => 200,
				'body'   => '<html><body>' . esc_html( (string) get_the_title( url_to_postid( (string) $url ) ) ) . '</body></html>',
			);
		};
		wp_clean_theme_json_cache();
	}

	protected function tearDown(): void {
		if ( null === $this->docroot_before ) {
			unset( $_SERVER['DOCUMENT_ROOT'] );
		} else {
			$_SERVER['DOCUMENT_ROOT'] = $this->docroot_before;
		}
		AB_MCP_Protected_Store::forget();
		AB_MCP_Sync_Backup::forget();
		AB_MCP_Sync_Guard::disarm();
		AB_MCP_Sync_Engine::$probe    = null;
		AB_MCP_Sync_Resources::$probe = null;
		AB_MCP_Sync_Resources::$time  = null;
		delete_option( \AB_MCP_Change_Lock::OPTION );
		remove_all_filters( 'pre_delete_post' );
		remove_all_filters( 'pre_trash_post' );
		remove_all_actions( 'pre_delete_term' );
		remove_all_actions( 'delete_option' );
		wp_clean_theme_json_cache();
	}

	public static function tearDownAfterClass(): void {
		AB_MCP_IT_Sites::reset( 'a' );
		AB_MCP_IT_Sites::reset( 'b' );
		self::wipe();
		AB_MCP_Protected_Store::forget();
		AB_MCP_Sync_Schema::forget();
	}

	/* ------------------------------------------------------------ helpers */

	private static function admin(): int {
		return (int) AB_MCP_IT_Sites::site( 'a' )['admin']['user_id'];
	}

	private static function wipe(): void {
		$root = (string) AB_MCP_IT_Sites::state()['root'];
		self::remove_tree( $root . '/ab-mcp-store', $root );
	}

	private static function remove_tree( string $path, string $root ): void {
		if ( 0 !== strpos( $path, $root . '/' ) ) {
			throw new RuntimeException( "Will not remove $path: it is not in AB_IT_ROOT." );
		}
		if ( is_link( $path ) || is_file( $path ) ) {
			unlink( $path );
			return;
		}
		if ( ! is_dir( $path ) ) {
			return;
		}
		foreach ( array_diff( (array) scandir( $path ), array( '.', '..' ) ) as $entry ) {
			self::remove_tree( $path . '/' . $entry, $root );
		}
		rmdir( $path );
	}

	/** Steps on site B. */
	private static function b( array $steps ): array {
		return \AB_MCP_IT_Sync_Steps::run( 'b', $steps );
	}

	/** B the staging site of A, A the production site of B with B's key confirmed. */
	private static function pair(): void {
		$b = self::b(
			array(
				array( 'op' => 'write_access' ),
				array(
					'op'          => 'staging_save',
					'role'        => 'staging',
					'partner_url' => AB_MCP_IT_Sites::url( 'a' ),
					'source'      => 'form',
				),
				array(
					'op' => 'pairing_code',
					'as' => 'code',
				),
			)
		);
		\AB_MCP_Settings::set( \AB_MCP_Site_Mode::KEY, \AB_MCP_Site_Mode::FULL );
		$saved = AB_MCP_Staging::save( 'production', AB_MCP_IT_Sites::url( 'b' ), 'staging', self::admin(), 'form' );
		self::assertIsArray( $saved, is_wp_error( $saved ) ? $saved->get_error_message() : '' );
		$prepared = AB_MCP_Sync_Anchor::prepare_from_code( $b['code'], self::admin() );
		self::assertIsArray( $prepared, is_wp_error( $prepared ) ? $prepared->get_error_message() : '' );
		self::assertIsArray( AB_MCP_Sync_Anchor::confirm( $prepared['fingerprint'], self::admin() ) );
	}

	/** A request to the REST server of A, in this process, as the site editor saves: its data. */
	private static function rest_a( string $method, string $route, array $body = array() ): array {
		$request = new \WP_REST_Request( $method, $route );
		foreach ( $body as $name => $value ) {
			$request->set_param( $name, $value );
		}
		$response = rest_do_request( $request );
		self::assertLessThan( 300, $response->get_status(), $route . ': ' . json_encode( $response->get_data() ) );
		return (array) rest_get_server()->response_to_data( $response, false );
	}

	/** The same on B, in a process of its own. */
	private static function rest_b( string $method, string $route, array $body = array() ): array {
		$out = self::b(
			array(
				array(
					'op'     => 'rest',
					'method' => $method,
					'route'  => $route,
					'body'   => $body,
					'as'     => 'r',
				),
			)
		)['r'];
		self::assertLessThan( 300, (int) $out['status'], $route . ': ' . json_encode( $out ) );
		return (array) $out['data'];
	}

	/** A post of A, in this process. */
	private static function on_a( array $args ): int {
		$id = wp_insert_post( wp_slash( $args ), true );
		self::assertIsInt( $id, is_wp_error( $id ) ? $id->get_error_message() : '' );
		return $id;
	}

	/** A post of B. */
	private static function on_b( array $args ): int {
		return (int) self::b(
			array(
				array(
					'op'   => 'insert_post',
					'args' => $args,
					'as'   => 'post',
				),
			)
		)['post'];
	}

	/** The arguments of a post of a type, the same on both sites but for $over. */
	private static function made( string $type, string $name, string $content, array $over = array() ): array {
		return array_merge(
			array(
				'post_type'     => $type,
				'post_status'   => 'publish',
				'post_title'    => ucfirst( $name ),
				'post_name'     => $name,
				'post_content'  => $content,
				'post_author'   => 1,
				'post_date'     => self::DATE,
				'post_date_gmt' => self::DATE,
			),
			$over
		);
	}

	/** B's packages of before removed (an account keeps three open exports at most). */
	private static function clear_b(): void {
		self::b( array( array( 'op' => 'store_cleanup', 'now' => time() + 2 * DAY_IN_SECONDS ) ) );
	}

	/** B packs its inventory and A takes it over: the package ids. */
	private static function staged(): array {
		self::clear_b();
		$ids    = array();
		$cursor = null;
		do {
			$step = array(
				'op'      => 'export',
				'purpose' => 'inventory',
				'as'      => 'package',
			);
			if ( null !== $cursor ) {
				$step['cursor'] = $cursor;
			}
			$package = self::b( array( $step ) )['package'];
			$cursor  = $package['cursor'];
			$ids[]   = self::import_package( $package, 'inventory', null );
		} while ( null !== $cursor && count( $ids ) < 50 );
		return $ids;
	}

	/** A package of B taken over here, chunk by chunk. */
	private static function import_package( array $package, string $purpose, ?string $report_hash ): string {
		$begin = array(
			'package_id' => $package['package_id'],
			'manifest'   => $package['manifest'],
			'purpose'    => $purpose,
		);
		if ( null !== $report_hash ) {
			$begin['report_hash'] = $report_hash;
		}
		$state = AB_MCP_Sync_Import::call( $begin );
		self::assertIsArray( $state, is_wp_error( $state ) ? $state->get_error_message() : '' );
		$steps = array();
		foreach ( range( 0, $package['chunks'] - 1 ) as $i ) {
			$steps[] = array(
				'op'         => 'export_chunk',
				'package_id' => $package['package_id'],
				'chunk'      => $i,
				'as'         => 'c' . $i,
			);
		}
		$data = self::b( $steps );
		foreach ( range( 0, $package['chunks'] - 1 ) as $i ) {
			$state = AB_MCP_Sync_Import::call(
				array(
					'package_id' => $package['package_id'],
					'chunk'      => $i,
					'data'       => $data[ 'c' . $i ]['data'],
				)
			);
			self::assertIsArray( $state, is_wp_error( $state ) ? $state->get_error_message() : '' );
		}
		self::assertTrue( $state['complete'] );
		return (string) $package['package_id'];
	}

	/** B packs the objects of a report and A takes them over: the package id. */
	private static function objects( string $report_hash, array $refs ): string {
		self::clear_b();
		$package = self::b(
			array(
				array(
					'op'          => 'export',
					'purpose'     => 'objects',
					'report_hash' => $report_hash,
					'refs'        => $refs,
					'as'          => 'package',
				),
			)
		)['package'];
		self::assertSame( 'objects', $package['purpose'], json_encode( $package ) );
		return self::import_package( $package, 'objects', $report_hash );
	}

	/** The definition of a tool of the staging group. */
	private static function def( string $name ): array {
		$r = new AB_MCP_Tool_Registry();
		AB_MCP_Tools_Staging::register( $r );
		return $r->get( $name );
	}

	/** A tool through the gate of the plugin, in this process. */
	private static function gated( string $name, array $a ) {
		return AB_MCP_REST_Controller::run_tool( $name, self::def( $name ), $a );
	}

	/** Every entry of these categories of a fresh proposal paired. */
	private static function paired( array $ids, array $categories = array( 'equal' ) ): void {
		$answer = self::gated( 'wp_site_pairs', array( 'action' => 'propose', 'packages' => $ids ) );
		while ( is_array( $answer ) && false === $answer['done'] ) {
			$answer = self::gated( 'wp_site_pairs', array( 'action' => 'propose', 'proposal_id' => $answer['proposal_id'] ) );
		}
		self::assertIsArray( $answer, is_wp_error( $answer ) ? $answer->get_error_message() : '' );
		$args    = array(
			'action'      => 'confirm',
			'proposal_id' => $answer['proposal_id'],
			'categories'  => $categories,
		);
		$preview = self::gated( 'wp_site_pairs', $args );
		self::assertIsArray( $preview, is_wp_error( $preview ) ? $preview->get_error_message() : '' );
		$done = self::gated( 'wp_site_pairs', $args + array( 'confirm_token' => $preview['confirm_token'] ) );
		self::assertIsArray( $done, is_wp_error( $done ) ? $done->get_error_message() : '' );
	}

	/** wp_site_diff in this process, worked out to its end and paged: every entry. */
	private static function report( array $ids ): array {
		$answer = AB_MCP_Sync_Report::call( array( 'packages' => $ids ) );
		$calls  = 1;
		while ( is_array( $answer ) && false === $answer['done'] ) {
			$answer = AB_MCP_Sync_Report::call( array( 'report_id' => $answer['report_id'] ) );
			++$calls;
			self::assertLessThan( 300, $calls );
		}
		self::assertIsArray( $answer, is_wp_error( $answer ) ? $answer->get_error_message() : '' );
		$all   = $answer['entries'];
		$page  = $answer;
		$pages = 0;
		while ( null !== $page['cursor'] ) {
			$page = AB_MCP_Sync_Report::call( array( 'report_id' => $answer['report_id'], 'cursor' => $page['cursor'] ) );
			self::assertIsArray( $page );
			$all = array_merge( $all, $page['entries'] );
			++$pages;
			self::assertLessThan( 300, $pages );
		}
		$answer['all'] = $all;
		return $answer;
	}

	/**
	 * The entry of an object: a post by its type and slug
	 * ('post:wp_template_part:header'), a term by its taxonomy and slug
	 * ('term:wp_pattern_category:hinweise'), on either side.
	 */
	private static function entry( array $entries, string $name ): array {
		$parts = explode( ':', $name );
		foreach ( $entries as $entry ) {
			foreach ( array( 'staging', 'live' ) as $side ) {
				$identity = $entry[ $side ]['identity'] ?? array();
				if ( 'post' === $parts[0] && 'post' === $entry['kind'] && ( $identity['post_type'] ?? null ) === $parts[1] && ( $identity['post_name'] ?? null ) === $parts[2] ) {
					return $entry;
				}
				if ( 'term' === $parts[0] && 'term' === $entry['kind'] && ( $identity['taxonomy'] ?? null ) === $parts[1] && ( $identity['slug'] ?? null ) === $parts[2] ) {
					return $entry;
				}
			}
		}
		self::fail( "No entry for $name." );
	}

	/** The report, the objects package and the arguments of a plan for these entries. */
	private static function planned( array $names, array $extra = array(), array $drafts = array() ): array {
		$report = self::report( self::staged() );
		$select = array();
		$refs   = array();
		foreach ( $names as $name ) {
			$entry    = self::entry( $report['all'], $name );
			$select[] = (int) $entry['n'];
			if ( isset( $entry['staging']['ref'] ) ) {
				$refs[] = array(
					'kind' => $entry['kind'],
					'ref'  => (string) $entry['staging']['ref'],
				);
			}
		}
		$package = self::objects( $report['report_hash'], $refs );
		$args    = array(
			'action'    => 'plan',
			'report_id' => $report['report_id'],
			'select'    => $select,
			'packages'  => array( $package ),
		) + $extra;
		if ( array() !== $drafts ) {
			$args['as_draft'] = array_map( static fn( $name ) => (int) self::entry( $report['all'], $name )['n'], $drafts );
		}
		return array(
			'report' => $report,
			'args'   => $args,
		);
	}

	/** A plan confirmed in this process: the run. */
	private static function begun( array $args ): array {
		$preview = self::gated( 'wp_site_push', $args );
		self::assertIsArray( $preview, is_wp_error( $preview ) ? $preview->get_error_message() : '' );
		self::assertTrue( $preview['confirm_required'] );
		$run = self::gated( 'wp_site_push', $args + array( 'confirm_token' => $preview['confirm_token'] ) );
		self::assertIsArray( $run, is_wp_error( $run ) ? $run->get_error_message() : '' );
		self::assertSame( 'open', $run['state'] );
		return $run;
	}

	/** Steps until the run is closed, at most $max. */
	private static function finish( int $run_id, int $max = 16 ): array {
		$answer = null;
		for ( $i = 0; $i < $max; $i++ ) {
			$answer = self::gated( 'wp_site_push', array( 'action' => 'next', 'run_id' => $run_id ) );
			self::assertIsArray( $answer, is_wp_error( $answer ) ? $answer->get_error_message() : '' );
			if ( in_array( $answer['state'], AB_MCP_Sync_Schema::CLOSED, true ) ) {
				return $answer;
			}
		}
		self::fail( 'The run did not close within ' . $max . ' steps: ' . json_encode( $answer ) );
	}

	/** The status of a run, its steps with their journals. */
	private static function run_status( int $run_id ): array {
		$status = self::gated( 'wp_site_push', array( 'action' => 'status', 'run_id' => $run_id ) );
		self::assertIsArray( $status, is_wp_error( $status ) ? $status->get_error_message() : '' );
		return $status;
	}

	/** The way back of a run, planned, confirmed and run to its close. */
	private static function back( int $run_id ): array {
		$args    = array(
			'action' => 'plan',
			'run_id' => $run_id,
		);
		$preview = self::gated( 'wp_site_revert', $args );
		self::assertIsArray( $preview, is_wp_error( $preview ) ? $preview->get_error_message() : '' );
		$begun = self::gated( 'wp_site_revert', $args + array( 'confirm_token' => $preview['confirm_token'] ) );
		self::assertIsArray( $begun, is_wp_error( $begun ) ? $begun->get_error_message() : '' );
		$answer = null;
		for ( $i = 0; $i < 16; $i++ ) {
			$answer = self::gated( 'wp_site_revert', array( 'action' => 'next', 'run_id' => (int) $begun['run_id'] ) );
			self::assertIsArray( $answer, is_wp_error( $answer ) ? $answer->get_error_message() : '' );
			if ( in_array( $answer['state'], AB_MCP_Sync_Schema::CLOSED, true ) ) {
				return self::gated( 'wp_site_revert', array( 'action' => 'status', 'run_id' => (int) $begun['run_id'] ) );
			}
		}
		self::fail( 'The way back did not close.' );
	}

	/** The write steps of a run. */
	private static function writes( array $status ): array {
		$out = array();
		foreach ( $status['steps'] as $step ) {
			if ( 'write' === $step['op'] ) {
				$out[] = $step;
			}
		}
		return $out;
	}

	/** The write step of a run for an object here, by its type and slug. */
	private static function write_of( array $status, string $type, string $slug ): array {
		foreach ( self::writes( $status ) as $step ) {
			$identity = $step['parts']['object']['identity'] ?? array();
			if ( ( $identity['post_type'] ?? null ) === $type && ( $identity['post_name'] ?? null ) === $slug ) {
				return $step;
			}
		}
		self::fail( "No write step for $type $slug: " . json_encode( $status ) );
	}

	/** The result of the check before the first write. */
	private static function check_message( array $status ): string {
		foreach ( $status['steps'] as $step ) {
			if ( 'check' === $step['op'] ) {
				return (string) json_encode( $step['result'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			}
		}
		return '';
	}

	/**
	 * The row of a post of A, past every cache, without the columns
	 * WordPress writes by itself (group B: post_modified and the like, which
	 * every save sets anew; AB_MCP_Sync_Exclusions).
	 */
	private static function row_a( int $id ): array {
		$rows = AB_MCP_IT_Sites::rows( 'a', 'posts', array( 'ID' => (string) $id ) );
		self::assertCount( 1, $rows, "Post $id of A." );
		$out = array();
		foreach ( $rows[0] as $column => $value ) {
			if ( ! AB_MCP_Sync_Exclusions::column( 'posts', (string) $column ) ) {
				$out[ $column ] = $value;
			}
		}
		return $out;
	}

	/** The id of the post of A of a type and slug (and theme), 0 for none. */
	private static function id_a( string $type, string $slug ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_name = %s ORDER BY ID ASC LIMIT 1", $type, $slug ) );
	}

	/** The slugs of the terms of a taxonomy a post of A carries, past every cache. */
	private static function terms_a( int $id, string $taxonomy ): array {
		global $wpdb;
		$slugs = $wpdb->get_col( $wpdb->prepare( "SELECT t.slug FROM {$wpdb->term_relationships} r INNER JOIN {$wpdb->term_taxonomy} x ON x.term_taxonomy_id = r.term_taxonomy_id INNER JOIN {$wpdb->terms} t ON t.term_id = x.term_id WHERE r.object_id = %d AND x.taxonomy = %s ORDER BY t.slug", $id, $taxonomy ) );
		return array_map( 'strval', (array) $slugs );
	}

	/** The values of a meta key of a post of A, past every cache. */
	private static function meta_a( int $id, string $key ): array {
		return array_column( AB_MCP_IT_Sites::rows( 'a', 'postmeta', array( 'post_id' => (string) $id, 'meta_key' => $key ) ), 'meta_value' );
	}

	/** The rests a way back names: kind and live ref. */
	private static function rests( array $back ): array {
		$out = array();
		foreach ( $back['steps'] as $step ) {
			if ( isset( $step['result']['rest'] ) ) {
				$out[] = array( $step['result']['rest']['kind'], (string) $step['result']['rest']['live_ref'] );
			}
		}
		sort( $out );
		return $out;
	}

	/** Filters that record every deletion of a post, a term or an option of the site. */
	private function watch_deletions(): void {
		add_filter(
			'pre_delete_post',
			function ( $check, $post ) {
				$this->deletions[] = array( 'delete_post', (int) ( is_object( $post ) ? $post->ID : $post ) );
				return $check;
			},
			1,
			2
		);
		add_filter(
			'pre_trash_post',
			function ( $check, $post ) {
				$this->deletions[] = array( 'trash_post', (int) ( is_object( $post ) ? $post->ID : $post ) );
				return $check;
			},
			1,
			2
		);
		add_action(
			'pre_delete_term',
			function ( $term, $taxonomy ) {
				$this->deletions[] = array( 'delete_term', (int) $term, (string) $taxonomy );
			},
			1,
			2
		);
		add_action(
			'delete_option',
			function ( $option ) {
				if ( ! AB_MCP_Sync_Exclusions::option( (string) $option ) ) {
					$this->deletions[] = array( 'delete_option', (string) $option );
				}
			},
			1,
			1
		);
	}

	/**
	 * The same dates for an object on both sites, as a staging site copied
	 * from this one has them: the site editor saves with the time of the
	 * save, which two sites rarely share to the second, and two objects of
	 * other dates are paired unclear (a person confirms their bases).
	 */
	private static function same_dates( int $id_a, int $id_b ): void {
		global $wpdb;
		$set = array(
			'post_date'     => self::DATE,
			'post_date_gmt' => self::DATE,
		);
		$wpdb->update( $wpdb->posts, $set, array( 'ID' => $id_a ) );
		clean_post_cache( $id_a );
		self::b(
			array(
				array(
					'op'   => 'raw_post',
					'post' => $id_b,
					'set'  => $set,
				),
			)
		);
	}

	/** The block of a paragraph. */
	private static function para( string $text ): string {
		return '<!-- wp:paragraph --><p>' . $text . '</p><!-- /wp:paragraph -->';
	}

	/* ------------------------------------------------- the normal case */

	public function testATemplateItsPartsAndTheGlobalStylesOfTheActiveThemeGoOverAndBack(): void {
		// The normal case of branch 9b: both sites run the same block theme.
		// The header is a row on both sites (customized in the site editor
		// before), paired; the footer and the template single are files of the
		// theme here and rows on staging (the core makes the row as the site
		// editor first saves it: wp_theme, the area, the meta origin); the
		// global styles are a row on both sites. On staging the header and the
		// global styles change, footer and single are saved. One run takes them
		// over, WordPress shows them here; the way back puts the header and the
		// global styles back byte for byte and leaves footer and single as rests.
		self::assertSame( self::THEME, get_stylesheet(), 'Site A runs the default theme of WordPress 7.1.3.' );
		self::assertTrue( wp_is_block_theme(), 'A block theme.' );
		self::assertSame( self::THEME, AB_MCP_IT_Sites::option_row( 'b', 'stylesheet' )['option_value'] ?? null, 'Site B runs the same.' );
		self::pair();
		$head = self::para( 'Kopf' );
		self::rest_a( 'POST', '/wp/v2/template-parts/' . self::THEME . '//header', array( 'content' => $head ) );
		self::rest_b( 'POST', '/wp/v2/template-parts/' . self::THEME . '//header', array( 'content' => $head ) );
		$styles_a = (int) \WP_Theme_JSON_Resolver::get_user_global_styles_post_id();
		$styles_b = (int) self::b( array( array( 'op' => 'global_styles_id', 'as' => 'id' ) ) )['id'];
		self::assertGreaterThan( 0, $styles_a );
		self::assertGreaterThan( 0, $styles_b );
		$header_a = self::id_a( 'wp_template_part', 'header' );
		$header_b = (int) self::rest_b( 'GET', '/wp/v2/template-parts/' . self::THEME . '//header', array( 'context' => 'edit' ) )['wp_id'];
		self::same_dates( $header_a, $header_b );
		self::same_dates( $styles_a, $styles_b );
		self::assertSame( array( self::THEME ), self::terms_a( $header_a, 'wp_theme' ), 'What the core makes as the site editor saves a part of the theme.' );
		self::assertSame( array( 'header' ), self::terms_a( $header_a, 'wp_template_part_area' ) );
		self::assertSame( array( 'theme' ), self::meta_a( $header_a, 'origin' ) );
		self::paired( self::staged() );
		$source = AB_MCP_Sync_Anchor::active()['source_id'];
		self::assertSame( 'active', AB_MCP_Sync_Pairs::find_live( $source, 'post', (string) $header_a )['state'], 'Equal on both sites: paired.' );
		self::assertSame( 'active', AB_MCP_Sync_Pairs::find_live( $source, 'post', (string) $styles_a )['state'] );
		// The changes on staging.
		self::rest_b( 'POST', '/wp/v2/template-parts/' . self::THEME . '//header', array( 'content' => self::para( 'Kopf neu' ) ) );
		self::rest_b( 'POST', '/wp/v2/template-parts/' . self::THEME . '//footer', array( 'content' => self::para( 'Fuss neu' ) ) );
		$single = '<!-- wp:template-part {"slug":"header","theme":"' . self::THEME . '","tagName":"header"} /-->' . self::para( 'Einzeln' ) . '<!-- wp:template-part {"slug":"footer","theme":"' . self::THEME . '","tagName":"footer"} /-->';
		self::rest_b( 'POST', '/wp/v2/templates/' . self::THEME . '//single', array( 'content' => $single ) );
		self::rest_b( 'POST', '/wp/v2/global-styles/' . $styles_b, array( 'styles' => array( 'color' => array( 'background' => '#112233' ) ) ) );
		self::assertSame( 0, self::id_a( 'wp_template_part', 'footer' ), 'Here the footer is the file of the theme only.' );
		self::assertSame( 0, self::id_a( 'wp_template', 'single' ) );
		$before_header = self::row_a( $header_a );
		$before_styles = self::row_a( $styles_a );
		// The user data of theme.json as this request holds it before the run
		// (WP_Theme_JSON_Resolver keeps it for the request).
		self::assertNull( \WP_Theme_JSON_Resolver::get_user_data()->get_raw_data()['styles']['color']['background'] ?? null );
		$names         = array( 'post:wp_template_part:header', 'post:wp_template_part:footer', 'post:wp_template:single', 'post:wp_global_styles:wp-global-styles-' . self::THEME );
		$planned       = self::planned( $names );
		self::assertSame( array( 'take_over', 'create', 'create', 'take_over' ), array_map( static fn( $n ) => self::entry( $planned['report']['all'], $n )['action'], $names ) );
		$this->watch_deletions();
		$run    = self::begun( $planned['args'] );
		$closed = self::finish( (int) $run['run_id'] );
		self::assertSame( 'closed_done', $closed['state'], json_encode( $closed ) );
		$status = self::run_status( (int) $run['run_id'] );
		foreach ( self::writes( $status ) as $step ) {
			self::assertSame( 'done', $step['state'], json_encode( $step ) );
			self::assertNull( $step['parts']['object']['deviated'] );
		}
		// The parts first, then the template (NAMED_FIRST), then the rest.
		self::assertSame( array( 'wp_template_part', 'wp_template_part', 'wp_template', 'wp_global_styles' ), array_map( static fn( $s ) => $s['parts']['object']['identity']['post_type'], self::writes( $status ) ) );
		// The global styles as this request reads them now: phase 7 emptied
		// the caches of theme.json (wp_clean_theme_json_cache()).
		self::assertSame( '#112233', \WP_Theme_JSON_Resolver::get_user_data()->get_raw_data()['styles']['color']['background'] ?? null, 'The global styles as the core reads them.' );
		// WordPress shows them here.
		wp_cache_flush();
		$header = get_block_template( self::THEME . '//header', 'wp_template_part' );
		self::assertSame( self::para( 'Kopf neu' ), $header->content );
		self::assertSame( 'header', $header->area );
		$footer = get_block_template( self::THEME . '//footer', 'wp_template_part' );
		self::assertSame( 'custom', $footer->source, 'The row now, not the file.' );
		self::assertSame( self::para( 'Fuss neu' ), $footer->content );
		self::assertSame( 'footer', $footer->area, 'The area went with it, a field of the part.' );
		$footer_a = self::id_a( 'wp_template_part', 'footer' );
		self::assertSame( array( 'theme' ), self::meta_a( $footer_a, 'origin' ), 'As the core wrote it on staging.' );
		$template = get_block_template( self::THEME . '//single', 'wp_template' );
		self::assertSame( 'custom', $template->source );
		self::assertSame( $single, $template->content );
		$single_a = self::id_a( 'wp_template', 'single' );
		self::assertSame( array( self::THEME ), self::terms_a( $single_a, 'wp_theme' ) );
		// Phase 7: the page cache of the whole site, the caches of theme.json.
		foreach ( $status['steps'] as $step ) {
			if ( 'post' === $step['op'] ) {
				self::assertContains( 'theme_json', $step['result']['objects'][ (string) $styles_a ] );
				self::assertContains( 'page_cache_site', $step['result']['objects'][ (string) $styles_a ] );
				self::assertContains( 'page_cache_site', $step['result']['objects'][ (string) $header_a ] );
				self::assertNotContains( 'theme_json', $step['result']['objects'][ (string) $header_a ] );
			}
			if ( 'verify' === $step['op'] ) {
				self::assertNull( $step['result']['sample'], 'No object of these types has a page of its own.' );
			}
		}
		// The next report sees them equal.
		$again = self::report( self::staged() );
		foreach ( $names as $name ) {
			self::assertSame( 'equal', self::entry( $again['all'], $name )['result'], $name );
		}
		// The way back.
		$back = self::back( (int) $run['run_id'] );
		self::assertSame( 'closed_findings', $back['state'], json_encode( $back ) );
		self::assertSame( $before_header, self::row_a( $header_a ), 'The header byte for byte as before the run.' );
		self::assertSame( $before_styles, self::row_a( $styles_a ), 'The global styles likewise.' );
		$expected = array( array( 'post', (string) $footer_a ), array( 'post', (string) $single_a ) );
		sort( $expected );
		self::assertSame( $expected, self::rests( $back ), 'The new rows are named as rests.' );
		self::assertSame( 'residual', AB_MCP_Sync_Pairs::find_live( $source, 'post', (string) $footer_a )['state'] );
		self::assertSame( 'residual', AB_MCP_Sync_Pairs::find_live( $source, 'post', (string) $single_a )['state'] );
		self::assertSame( 'active', AB_MCP_Sync_Pairs::find_live( $source, 'post', (string) $header_a )['state'] );
		wp_cache_flush();
		self::assertSame( 'custom', get_block_template( self::THEME . '//footer', 'wp_template_part' )->source, 'The new row stays as a rest.' );
		self::assertSame( array(), $this->deletions, 'Nothing was deleted, forward or back.' );
	}

	public function testANavigationAndAReusableBlockGoOverAndBackAndAPageNamesBothByTheirIds(): void {
		// The normal case of the two types that do not hang on the theme: a
		// navigation and a reusable block, paired, changed on staging; a page
		// new there that names both by their ids (core/block and
		// core/navigation with ref), rewritten by the pairs; a new unsynced
		// pattern (wp_pattern_sync_status, registered without an
		// auth_callback) in a new pattern category. The navigation keeps its
		// date: the one a navigation block without a menu of its own shows
		// stays the one it was. The way back puts navigation and block back
		// and leaves page, pattern and category as rests.
		self::pair();
		$link  = static fn( string $label, string $site ): string => '<!-- wp:navigation-link {"label":"' . $label . '","url":"' . AB_MCP_IT_Sites::url( $site ) . '/","kind":"custom","isTopLevelLink":true} /-->';
		$nav_a = self::on_a( self::made( 'wp_navigation', 'hauptmenu', $link( 'Start', 'a' ) ) );
		$nav_b = self::on_b( self::made( 'wp_navigation', 'hauptmenu', $link( 'Start', 'b' ) ) );
		$blk_a = self::on_a( self::made( 'wp_block', 'hinweis', self::para( 'Alt' ) ) );
		$blk_b = self::on_b( self::made( 'wp_block', 'hinweis', self::para( 'Alt' ) ) );
		self::paired( self::staged() );
		$source = AB_MCP_Sync_Anchor::active()['source_id'];
		self::assertSame( 'active', AB_MCP_Sync_Pairs::find_live( $source, 'post', (string) $nav_a )['state'], 'The navigations are one object by type and slug, the address of each site in its link.' );
		self::assertSame( 'active', AB_MCP_Sync_Pairs::find_live( $source, 'post', (string) $blk_a )['state'] );
		self::b(
			array(
				array(
					'op'   => 'update_post',
					'args' => array( 'ID' => $nav_b, 'post_content' => $link( 'Start', 'b' ) . $link( 'Kontakt', 'b' ) ),
				),
				array(
					'op'   => 'update_post',
					'args' => array( 'ID' => $blk_b, 'post_content' => self::para( 'Neu' ) ),
				),
				array(
					'op'       => 'insert_term',
					'name'     => 'Hinweise',
					'taxonomy' => 'wp_pattern_category',
					'as'       => 'category',
				),
				array(
					'op'   => 'insert_post',
					'args' => self::made( 'wp_block', 'vorlage', self::para( 'Vorlage' ), array( 'meta_input' => array( 'wp_pattern_sync_status' => 'unsynced' ) ) ),
					'as'   => 'pattern',
				),
				array(
					'op'       => 'set_terms',
					'post'     => '$pattern',
					'terms'    => array( '$category' ),
					'taxonomy' => 'wp_pattern_category',
				),
				array(
					'op'   => 'insert_post',
					'args' => self::made( 'page', 'mit-bezug', '<!-- wp:block {"ref":' . $blk_b . '} /--><!-- wp:navigation {"ref":' . $nav_b . '} /-->' ),
				),
			)
		);
		$before_nav = self::row_a( $nav_a );
		$before_blk = self::row_a( $blk_a );
		$names      = array( 'post:wp_navigation:hauptmenu', 'post:wp_block:hinweis', 'post:page:mit-bezug', 'post:wp_block:vorlage', 'term:wp_pattern_category:hinweise' );
		$planned    = self::planned( $names );
		self::assertSame( array( 'take_over', 'take_over', 'create', 'create', 'create' ), array_map( static fn( $n ) => self::entry( $planned['report']['all'], $n )['action'], $names ) );
		$this->watch_deletions();
		$run    = self::begun( $planned['args'] );
		$closed = self::finish( (int) $run['run_id'] );
		self::assertSame( 'closed_done', $closed['state'], json_encode( $closed ) );
		$status = self::run_status( (int) $run['run_id'] );
		foreach ( self::writes( $status ) as $step ) {
			self::assertSame( 'done', $step['state'], json_encode( $step ) );
		}
		self::assertSame( $link( 'Start', 'a' ) . $link( 'Kontakt', 'a' ), self::row_a( $nav_a )['post_content'], 'The addresses of this site.' );
		self::assertSame( self::para( 'Neu' ), self::row_a( $blk_a )['post_content'] );
		$page_a = self::id_a( 'page', 'mit-bezug' );
		self::assertSame( '<!-- wp:block {"ref":' . $blk_a . '} /--><!-- wp:navigation {"ref":' . $nav_a . '} /-->', self::row_a( $page_a )['post_content'], 'Both ids by their pairs.' );
		$pattern_a = self::id_a( 'wp_block', 'vorlage' );
		self::assertSame( array( 'unsynced' ), self::meta_a( $pattern_a, 'wp_pattern_sync_status' ), 'A key registered without an auth_callback goes with a new post.' );
		self::assertSame( array( 'hinweise' ), self::terms_a( $pattern_a, 'wp_pattern_category' ), 'In the category the same run made first.' );
		foreach ( $status['steps'] as $step ) {
			if ( 'verify' === $step['op'] ) {
				self::assertSame( $page_a, (int) $step['result']['sample']['live_ref'], 'The page is the sample, not the navigation or the block.' );
			}
		}
		$back = self::back( (int) $run['run_id'] );
		self::assertSame( 'closed_findings', $back['state'], json_encode( $back ) );
		self::assertSame( $before_nav, self::row_a( $nav_a ), 'The navigation byte for byte as before the run.' );
		self::assertSame( $before_blk, self::row_a( $blk_a ), 'The block likewise.' );
		$category_a = (int) get_term_by( 'slug', 'hinweise', 'wp_pattern_category' )->term_id;
		$expected   = array( array( 'post', (string) $page_a ), array( 'post', (string) $pattern_a ), array( 'term', (string) $category_a ) );
		sort( $expected );
		self::assertSame( $expected, self::rests( $back ) );
		self::assertSame( array(), $this->deletions, 'Nothing was deleted, forward or back.' );
	}

	/* ----------------------------------------------- the locks of 9b */

	public function testWhatTheBranchLocksIsFoundBeforeAnythingIsWritten(): void {
		// The locks of branch 9b, each with its way, found by the check before
		// the first write in one run (nothing written, the run closed
		// aborted): a template of another theme than the active one here
		// (post:theme_mismatch, never rewritten into this theme); a template
		// that names a template part this site does not show, and one that
		// names a part of another theme (post:reference_unmapped); a new
		// navigation that would become the one a navigation block without a
		// menu of its own shows (post:navigation_fallback); global styles
		// whose custom CSS holds </style (post:css_markup).
		self::pair();
		self::paired( self::staged() );
		$part = static fn( string $slug, ?string $theme ): string => '<!-- wp:template-part {"slug":"' . $slug . '"' . ( null === $theme ? '' : ',"theme":"' . $theme . '"' ) . '} /-->';
		// A template of two themes, the active one last (its identity then
		// names the active theme): made first, so the term of the other theme
		// comes first.
		self::b(
			array(
				array(
					'op'   => 'insert_post',
					'args' => array( 'post_type' => 'wp_template', 'post_status' => 'publish', 'post_title' => 'Search', 'post_name' => 'search', 'post_content' => self::para( 'Zwei Themes' ) ),
					'as'   => 'two',
				),
				array(
					'op'       => 'set_term_slugs',
					'post'     => '$two',
					'terms'    => array( 'twentytwentyfour', self::THEME ),
					'taxonomy' => 'wp_theme',
				),
				// A part in an area this site names otherwise (its term
				// Sidebar here, sidebar there).
				array(
					'op'   => 'insert_post',
					'args' => array( 'post_type' => 'wp_template_part', 'post_status' => 'publish', 'post_title' => 'Aside', 'post_name' => 'aside', 'post_content' => self::para( 'Seitlich' ) ),
					'as'   => 'aside',
				),
				array(
					'op'       => 'set_term_slugs',
					'post'     => '$aside',
					'terms'    => array( self::THEME ),
					'taxonomy' => 'wp_theme',
				),
				array(
					'op'       => 'set_term_slugs',
					'post'     => '$aside',
					'terms'    => array( 'sidebar' ),
					'taxonomy' => 'wp_template_part_area',
				),
			)
		);
		self::assertIsArray( wp_insert_term( 'Sidebar', 'wp_template_part_area', array( 'slug' => 'sidebar' ) ) );
		$styles_b = (int) self::b( array( array( 'op' => 'global_styles_id', 'as' => 'id' ) ) )['id'];
		self::rest_b( 'POST', '/wp/v2/template-parts', array( 'slug' => 'promo', 'title' => 'Promo', 'content' => self::para( 'Promo' ), 'area' => 'uncategorized' ) );
		// The part named inside a group, at a depth.
		self::rest_b( 'POST', '/wp/v2/templates/' . self::THEME . '//page', array( 'content' => '<!-- wp:group --><div class="wp-block-group">' . $part( 'promo', null ) . '</div><!-- /wp:group -->' . self::para( 'Seite' ) ) );
		self::rest_b( 'POST', '/wp/v2/templates/' . self::THEME . '//archive', array( 'content' => $part( 'header', 'twentytwentyfour' ) ) );
		self::b(
			array(
				array(
					'op'   => 'insert_post',
					'args' => array( 'post_type' => 'wp_template', 'post_status' => 'publish', 'post_title' => 'Index', 'post_name' => 'index', 'post_content' => self::para( 'Anderes Theme' ) ),
					'as'   => 'other',
				),
				array(
					'op'       => 'set_term_slugs',
					'post'     => '$other',
					'terms'    => array( 'twentytwentyfour' ),
					'taxonomy' => 'wp_theme',
				),
				array(
					'op'   => 'insert_post',
					'args' => array( 'post_type' => 'wp_navigation', 'post_status' => 'publish', 'post_title' => 'Neu', 'post_name' => 'neu', 'post_content' => '<!-- wp:navigation-link {"label":"Start","url":"/","kind":"custom","isTopLevelLink":true} /-->' ),
				),
				array(
					'op'   => 'update_post',
					'args' => array( 'ID' => $styles_b, 'post_content' => (string) wp_json_encode( array( 'version' => 3, 'isGlobalStylesUserThemeJSON' => true, 'styles' => array( 'css' => 'body{color:red}</style>' ) ), JSON_UNESCAPED_SLASHES ) ),
				),
			)
		);
		$names   = array( 'post:wp_template:index', 'post:wp_template:page', 'post:wp_template:archive', 'post:wp_navigation:neu', 'post:wp_global_styles:wp-global-styles-' . self::THEME, 'post:wp_template:search', 'post:wp_template_part:aside' );
		$planned = self::planned( $names );
		$index   = self::entry( $planned['report']['all'], 'post:wp_template:index' );
		self::assertSame( 'twentytwentyfour', $index['staging']['identity']['theme'], 'Its own identity: type, theme and slug.' );
		// The template of the other theme the plan names locked already, with
		// the way, and writes no step for it (a staging site keeps the rows of
		// every theme it tried; all leaves them out).
		$preview = self::gated( 'wp_site_push', $planned['args'] );
		self::assertIsArray( $preview, is_wp_error( $preview ) ? $preview->get_error_message() : '' );
		$locked = array_values( array_filter( $preview['preview']['locked'], static fn( $l ) => (int) $l['n'] === (int) $index['n'] ) );
		self::assertCount( 1, $locked, json_encode( $preview['preview']['locked'] ) );
		self::assertSame( 'Z8', $locked[0]['stage'] );
		self::assertStringContainsString( '(post:theme_mismatch) The wp_template index belongs to the theme twentytwentyfour, and the active theme of this site is ' . self::THEME . '.', $locked[0]['reason'] );
		self::assertStringContainsString( 'Activate the same theme on both sites', $locked[0]['reason'] );
		$run    = self::begun( $planned['args'] );
		$closed = self::finish( (int) $run['run_id'] );
		self::assertSame( 'closed_aborted', $closed['state'], json_encode( $closed ) );
		$message = self::check_message( self::run_status( (int) $run['run_id'] ) );
		self::assertStringContainsString( 'post:theme_mismatch', $message );
		self::assertStringNotContainsString( 'The wp_template index', $message, 'No step for it.' );
		self::assertStringContainsString( 'The template part ' . self::THEME . '//promo, which this site has neither as a template part nor as a file of its theme', $message );
		self::assertStringContainsString( 'The template part twentytwentyfour//header, of a theme that is not the active one here', $message );
		self::assertStringContainsString( 'post:navigation_fallback', $message );
		self::assertStringContainsString( 'A navigation block without a menu of its own shows none (WordPress would make one as a page is shown) now and would show this navigation after the write; the template ' . self::THEME . '//', $message );
		self::assertStringContainsString( 'shows a navigation block without a menu of its own', $message, 'The header of the theme (a file, its pattern) shows one: the move is seen.' );
		self::assertStringContainsString( 'post:css_markup', $message );
		self::assertStringContainsString( 'The CSS of styles.css in the global styles holds </style', $message );
		self::assertStringContainsString( 'The wp_template search of the staging site names the themes ' . self::THEME . ', twentytwentyfour, not the one theme ' . self::THEME, $message );
		self::assertStringContainsString( 'post:taxonomy_rule', $message );
		self::assertStringContainsString( 'The term sidebar of wp_template_part_area on this site is named Sidebar, and WordPress reads it by its name', $message );
		self::assertSame( 0, self::id_a( 'wp_template_part', 'aside' ) );
		foreach ( array( 'index', 'page', 'archive', 'search' ) as $slug ) {
			self::assertSame( 0, self::id_a( 'wp_template', $slug ), "Nothing written: $slug." );
		}
		self::assertSame( 0, self::id_a( 'wp_navigation', 'neu' ) );
		self::assertSame( 0, self::id_a( 'wp_global_styles', 'wp-global-styles-' . self::THEME ) );
	}

	public function testWhatTheSameRunCreatesFirstIsPendingAndANavigationAsDraftLeavesTheFallback(): void {
		// The ways the locks name: a template part the same run creates before
		// the template that names it, and a custom template the same run
		// creates before the page that uses it, are pending in the check
		// before the first write, not missing; the plan writes them first
		// (NAMED_FIRST), the boundary finds them made. A new navigation taken
		// over as draft (the confirmed choice) leaves the one a navigation
		// block without a menu shows as it was.
		self::pair();
		self::paired( self::staged() );
		self::rest_b( 'POST', '/wp/v2/template-parts', array( 'slug' => 'promo', 'title' => 'Promo', 'content' => self::para( 'Promo' ), 'area' => 'uncategorized' ) );
		// The custom template names the new part, and the sidebar of the
		// theme, a file of the theme only on both sites.
		self::rest_b( 'POST', '/wp/v2/templates', array( 'slug' => 'wp-custom-template-landing', 'title' => 'Landing', 'content' => '<!-- wp:template-part {"slug":"promo","theme":"' . self::THEME . '"} /-->' . self::para( 'Landing' ) . '<!-- wp:template-part {"slug":"sidebar","theme":"' . self::THEME . '"} /-->' ) );
		self::b(
			array(
				array(
					'op'   => 'insert_post',
					'args' => self::made( 'page', 'aktion', 'Die Aktion.', array( 'page_template' => 'wp-custom-template-landing' ) ),
				),
				array(
					'op'   => 'insert_post',
					'args' => array( 'post_type' => 'wp_navigation', 'post_status' => 'publish', 'post_title' => 'Neu', 'post_name' => 'neu', 'post_content' => '<!-- wp:navigation-link {"label":"Start","url":"/","kind":"custom","isTopLevelLink":true} /-->' ),
				),
			)
		);
		// The page first in the report, the part last: the plan orders them.
		$names   = array( 'post:page:aktion', 'post:wp_template:wp-custom-template-landing', 'post:wp_template_part:promo', 'post:wp_navigation:neu' );
		$planned = self::planned( $names, array(), array( 'post:wp_navigation:neu' ) );
		$run     = self::begun( $planned['args'] );
		$closed  = self::finish( (int) $run['run_id'] );
		self::assertSame( 'closed_done', $closed['state'], json_encode( $closed ) . self::check_message( self::run_status( (int) $run['run_id'] ) ) );
		$status = self::run_status( (int) $run['run_id'] );
		self::assertSame( array( 'wp_template_part', 'wp_template', 'page', 'wp_navigation' ), array_map( static fn( $s ) => $s['parts']['object']['identity']['post_type'], self::writes( $status ) ) );
		// The page waits for the custom template it names, the template for
		// the part it names (each created in the run: a dependency).
		$seq = array();
		foreach ( self::writes( $status ) as $step ) {
			$seq[ $step['parts']['object']['identity']['post_type'] ] = $step;
		}
		self::assertContains( (int) $seq['wp_template']['seq'], $seq['page']['depends'], json_encode( $seq['page'] ) );
		self::assertContains( (int) $seq['wp_template_part']['seq'], $seq['wp_template']['depends'] );
		wp_cache_flush();
		self::assertSame( 0, self::id_a( 'wp_template_part', 'sidebar' ), 'The sidebar is the file of the theme here.' );
		$landing = get_block_template( self::THEME . '//wp-custom-template-landing', 'wp_template' );
		self::assertSame( 'custom', $landing->source );
		self::assertTrue( $landing->is_custom );
		$page_a = self::id_a( 'page', 'aktion' );
		self::assertSame( array( 'wp-custom-template-landing' ), self::meta_a( $page_a, '_wp_page_template' ), 'The custom template the run made first, through page_template.' );
		self::assertSame( 'publish', get_post_status( $page_a ) );
		$nav_a = self::id_a( 'wp_navigation', 'neu' );
		self::assertSame( 'draft', get_post_status( $nav_a ), 'As draft: no navigation block shows it by itself.' );
		self::assertNull( AB_MCP_Sync_Post_Writer::fallback_moves( $nav_a, null, null ) );
	}

	public function testTheWayBackOfANavigationThatWouldMoveTheFallbackIsLeftForAPerson(): void {
		// Decision 21 on the way back as forward (branch 9b): a navigation
		// written with a later date stays the one a navigation block without
		// a menu of its own shows; another navigation published since, dated
		// between the two, would become that one as the row goes back to its
		// older date. The row is left, «check needed», with the way; the
		// order of the core read from the rows (fallback_moves()), a tie of
		// the latest date fail closed.
		self::pair();
		$link = '<!-- wp:navigation-link {"label":"Start","url":"/","kind":"custom","isTopLevelLink":true} /-->';
		$old  = array(
			'post_date'     => '2026-09-01 10:00:00',
			'post_date_gmt' => '2026-09-01 10:00:00',
		);
		$nav_a = self::on_a( self::made( 'wp_navigation', 'menu', $link, $old ) );
		$nav_b = self::on_b( self::made( 'wp_navigation', 'menu', $link, $old ) );
		self::paired( self::staged() );
		$new = '2026-10-05 10:00:00';
		self::b(
			array(
				array(
					'op'   => 'update_post',
					'args' => array( 'ID' => $nav_b, 'post_content' => $link . $link, 'post_date' => $new, 'post_date_gmt' => $new, 'edit_date' => true ),
				),
			)
		);
		$run    = self::begun( self::planned( array( 'post:wp_navigation:menu' ) )['args'] );
		$closed = self::finish( (int) $run['run_id'] );
		self::assertSame( 'closed_done', $closed['state'], json_encode( $closed ) );
		self::assertSame( $new, self::row_a( $nav_a )['post_date'], 'The only navigation stays the one shown, with its later date.' );
		// Another hand publishes a navigation dated between the two.
		$mid   = '2026-09-15 10:00:00';
		$other = self::on_a( self::made( 'wp_navigation', 'andere', $link, array( 'post_date' => $mid, 'post_date_gmt' => $mid ) ) );
		self::assertNull( AB_MCP_Sync_Post_Writer::fallback_moves( $nav_a, $new, $new ), 'Keeping its date it stays the one shown.' );
		self::assertStringContainsString( 'shows this navigation now and would show the navigation ' . $other . ' after the write', (string) AB_MCP_Sync_Post_Writer::fallback_moves( $nav_a, $new, '2026-09-01 10:00:00' ) );
		self::assertStringContainsString( 'share the latest date', (string) AB_MCP_Sync_Post_Writer::fallback_moves( 0, null, $new ), 'A tie of the latest date: the order of the core is not told.' );
		self::assertNull( AB_MCP_Sync_Post_Writer::fallback_moves( 0, null, null ), 'A draft moves nothing.' );
		$back = self::back( (int) $run['run_id'] );
		self::assertSame( 'closed_findings', $back['state'], json_encode( $back ) );
		$parts = array();
		foreach ( $back['steps'] as $step ) {
			foreach ( isset( $step['result']['parts'] ) && is_array( $step['result']['parts'] ) ? $step['result']['parts'] : array() as $part ) {
				$parts[ $part['part'] ] = $part;
			}
		}
		self::assertSame( 'check_needed', $parts['row']['state'] ?? null, json_encode( $back ) );
		self::assertStringContainsString( 'would change which navigation WordPress shows where a navigation block has no menu of its own', (string) json_encode( $back, JSON_UNESCAPED_SLASHES ) );
		self::assertSame( $new, self::row_a( $nav_a )['post_date'], 'The row is left as the run wrote it.' );
	}

	public function testTheRuleOfTheCustomCssIsTheOneOfTheSiteEditor(): void {
		// css_markup() is the rule of WP_REST_Global_Styles_Controller::
		// validate_custom_css() (protected in the core), step for step: held
		// here against the method itself on the edges of the rule.
		// Since PHP 8.1 a protected method is invoked by reflection as it is.
		$core = new \ReflectionMethod( \WP_REST_Global_Styles_Controller::class, 'validate_custom_css' );
		$controller = new \WP_REST_Global_Styles_Controller();
		$samples    = array( '', 'p{color:red}', 'a<b', '<', '</', '</s', '</styl', '</style', '</STYLE', '</style>', '</style ', "</style\t", "</style\n", '</style/', '</stylex', '</styles>', 'p{}</style>p{}', 'p{}<</style', 'x</St', '<<<', 'p { content: "</style>" }', "a\x00</style>" );
		foreach ( $samples as $css ) {
			self::assertSame( is_wp_error( $core->invoke( $controller, $css ) ), AB_MCP_Sync_Post_Writer::css_markup( $css ), json_encode( $css ) );
		}
		self::assertTrue( AB_MCP_Sync_Post_Writer::css_markup( 'body{}</style>' ) );
		self::assertFalse( AB_MCP_Sync_Post_Writer::css_markup( 'body{color:#112233}' ) );
	}

	public function testATemplateOfAThemeAFilterShowsInThisRequestOnlyIsLocked(): void {
		// The active theme is the one the option row holds as well as the one
		// get_stylesheet() gives: a filter that shows another theme in a
		// request (a theme switcher, a preview) makes no active theme
		// (inactive_theme(); post:theme_mismatch). In the plan: a template of
		// the theme the filter shows is locked by the row, one of the theme of
		// the row by the filter. At the check before the first write: the
		// filter come between the plan and the run locks it as well
		// (theme_refusal()).
		self::pair();
		self::paired( self::staged() );
		self::rest_b( 'POST', '/wp/v2/templates/' . self::THEME . '//single', array( 'content' => self::para( 'Fuenf' ) ) );
		self::b(
			array(
				array(
					'op'   => 'insert_post',
					'args' => array( 'post_type' => 'wp_template', 'post_status' => 'publish', 'post_title' => 'Index', 'post_name' => 'index', 'post_content' => self::para( 'Vier' ) ),
					'as'   => 'other',
				),
				array(
					'op'       => 'set_term_slugs',
					'post'     => '$other',
					'terms'    => array( 'twentytwentyfour' ),
					'taxonomy' => 'wp_theme',
				),
			)
		);
		$planned = self::planned( array( 'post:wp_template:index', 'post:wp_template:single' ) );
		$shown   = static fn() => 'twentytwentyfour';
		add_filter( 'stylesheet', $shown );
		try {
			self::assertSame( 'twentytwentyfour', get_stylesheet() );
			$refused = self::gated( 'wp_site_push', $planned['args'] );
		} finally {
			remove_filter( 'stylesheet', $shown );
		}
		self::assertInstanceOf( \WP_Error::class, $refused, 'Both locked: nothing to write.' );
		// The gate hands on the code and the text of a refusal (decision 32);
		// the text names each locked entry with its reason.
		$reasons = $refused->get_error_message();
		self::assertStringContainsString( 'The wp_template index belongs to the theme twentytwentyfour, and the active theme of this site is twentytwentyfour (the option holds ' . self::THEME . ')', $reasons );
		self::assertStringContainsString( 'The wp_template single belongs to the theme ' . self::THEME . ', and the active theme of this site is twentytwentyfour (the option holds ' . self::THEME . ')', $reasons );
		// Without the filter the plan takes single; the filter come since
		// locks it at the check.
		$run = self::begun( $planned['args'] );
		add_filter( 'stylesheet', $shown );
		try {
			$closed = self::finish( (int) $run['run_id'] );
		} finally {
			remove_filter( 'stylesheet', $shown );
		}
		self::assertSame( 'closed_aborted', $closed['state'], json_encode( $closed ) );
		$message = self::check_message( self::run_status( (int) $run['run_id'] ) );
		self::assertStringContainsString( 'post:theme_mismatch', $message );
		self::assertStringContainsString( 'The wp_template single belongs to the theme ' . self::THEME . ', and the active theme of this site is twentytwentyfour (the option holds ' . self::THEME . ')', $message );
		self::assertSame( 0, self::id_a( 'wp_template', 'index' ) );
		self::assertSame( 0, self::id_a( 'wp_template', 'single' ) );
	}

	/* ------------------------------------------ Codex round 1 of 9b */

	public function testTheWayBackOfTheGlobalStylesAsksTheRuleOfTheCustomCssAsForward(): void {
		// Codex round 1 of branch 9b (A): the global styles here hold custom
		// CSS of a block with </style (the controller of the core asks the top
		// level only); a push replaces them with clean styles; the way back
		// would write the backup through wp_update_post(), past the rule the
		// forward check holds (post:css_markup), and an account with
		// unfiltered_html meets no kses. The row is left, with the reason and
		// its value before; the styles stay as the run wrote them.
		self::pair();
		$dirty    = array( 'blocks' => array( 'core/paragraph' => array( 'css' => 'p{color:red}</style>' ) ) );
		$styles_a = (int) \WP_Theme_JSON_Resolver::get_user_global_styles_post_id();
		$styles_b = (int) self::b( array( array( 'op' => 'global_styles_id', 'as' => 'id' ) ) )['id'];
		self::rest_a( 'POST', '/wp/v2/global-styles/' . $styles_a, array( 'styles' => $dirty ) );
		self::rest_b( 'POST', '/wp/v2/global-styles/' . $styles_b, array( 'styles' => $dirty ) );
		self::same_dates( $styles_a, $styles_b );
		$before = self::row_a( $styles_a );
		self::assertSame( 'p{color:red}</style>', json_decode( $before['post_content'], true )['styles']['blocks']['core/paragraph']['css'], 'The core stored it (escaped as JSON).' );
		self::assertSame( 'post:css_markup', AB_MCP_Sync_Post_Writer::css_refusal( $before['post_content'] )->get_error_data()['lock']['key'], 'Forward the same content is locked.' );
		self::paired( self::staged() );
		self::rest_b( 'POST', '/wp/v2/global-styles/' . $styles_b, array( 'styles' => array( 'blocks' => array( 'core/paragraph' => array( 'css' => 'p{color:red}' ) ) ) ) );
		$run    = self::begun( self::planned( array( 'post:wp_global_styles:wp-global-styles-' . self::THEME ) )['args'] );
		$closed = self::finish( (int) $run['run_id'] );
		self::assertSame( 'closed_done', $closed['state'], json_encode( $closed ) );
		$clean = self::row_a( $styles_a )['post_content'];
		self::assertStringNotContainsString( '</style', $clean );
		$back = self::back( (int) $run['run_id'] );
		self::assertSame( 'closed_findings', $back['state'], json_encode( $back ) );
		$row = null;
		foreach ( $back['steps'] as $step ) {
			foreach ( isset( $step['result']['parts'] ) && is_array( $step['result']['parts'] ) ? $step['result']['parts'] : array() as $part ) {
				if ( 'row' === $part['part'] ) {
					$row = $part;
				}
			}
		}
		self::assertSame( 'left', $row['state'] ?? null, json_encode( $back ) );
		self::assertStringContainsString( 'post:css_markup', (string) $row['note'] );
		self::assertStringContainsString( 'styles.blocks.core/paragraph.css', (string) $row['note'] );
		self::assertArrayHasKey( 'value_before', $row, 'Its value before stands beside it.' );
		self::assertSame( $clean, self::row_a( $styles_a )['post_content'], 'Left as the run wrote it.' );
	}

	public function testANewNavigationEveryBlockNamesByItsRefGoesAndOneABlockWithoutAMenuWouldShowIsLocked(): void {
		// Codex round 1 of branch 9b (B): a new navigation published on
		// staging and named by its ref in the header there; its later date
		// makes it the one a navigation block without a menu of its own shows,
		// but after the run no template shows such a block (the header the run
		// writes names it by its ref, the footer of the theme has blocks of its
		// own): it goes, and back. Where such a block is shown after the run
		// (a page here, a page or a template the run writes), the move is seen
		// and locks (post:navigation_fallback).
		self::pair();
		self::paired( self::staged() );
		$link  = '<!-- wp:navigation-link {"label":"Start","url":"/","kind":"custom","isTopLevelLink":true} /-->';
		$nav_b = self::on_b( array( 'post_type' => 'wp_navigation', 'post_status' => 'publish', 'post_title' => 'Kopfmenue', 'post_name' => 'kopfmenue', 'post_content' => $link ) );
		self::rest_b( 'POST', '/wp/v2/template-parts/' . self::THEME . '//header', array( 'content' => '<!-- wp:navigation {"ref":' . $nav_b . '} /-->' ) );
		$planned = self::planned( array( 'post:wp_template_part:header', 'post:wp_navigation:kopfmenue' ) );
		$run     = self::begun( $planned['args'] );
		$closed  = self::finish( (int) $run['run_id'] );
		self::assertSame( 'closed_done', $closed['state'], json_encode( $closed ) . self::check_message( self::run_status( (int) $run['run_id'] ) ) );
		$status = self::run_status( (int) $run['run_id'] );
		self::assertSame( array( 'wp_navigation', 'wp_template_part' ), array_map( static fn( $s ) => $s['parts']['object']['identity']['post_type'], self::writes( $status ) ), 'The navigation first: the header names it.' );
		$nav_a    = self::id_a( 'wp_navigation', 'kopfmenue' );
		$header_a = self::id_a( 'wp_template_part', 'header' );
		self::assertSame( '<!-- wp:navigation {"ref":' . $nav_a . '} /-->', self::row_a( $header_a )['post_content'] );
		self::assertNull( AB_MCP_Sync_Post_Writer::fallback_shown( array() ), 'No template or post here shows a navigation block without a menu of its own.' );
		$back = self::back( (int) $run['run_id'] );
		self::assertSame( 'closed_findings', $back['state'], json_encode( $back ) );
		$expected = array( array( 'post', (string) $header_a ), array( 'post', (string) $nav_a ) );
		sort( $expected );
		self::assertSame( $expected, self::rests( $back ), 'Both new: rests.' );
		// A second new navigation: a page the run writes shows a block without
		// a menu; a template the run writes; a page here.
		$second = array( 'post_type' => 'wp_navigation', 'post_status' => 'publish', 'post_title' => 'Zweites', 'post_name' => 'zweites', 'post_content' => $link );
		self::on_b( $second );
		self::on_b( self::made( 'page', 'mit-menue', '<!-- wp:navigation /-->' ) );
		$locked = static function ( array $names ): string {
			$run    = self::begun( self::planned( $names )['args'] );
			$closed = self::finish( (int) $run['run_id'] );
			self::assertSame( 'closed_aborted', $closed['state'], json_encode( $closed ) );
			$message = self::check_message( self::run_status( (int) $run['run_id'] ) );
			self::assertStringContainsString( 'post:navigation_fallback', $message );
			return $message;
		};
		self::assertStringContainsString( 'the page ', $locked( array( 'post:wp_navigation:zweites', 'post:page:mit-menue' ) ) );
		self::rest_b( 'POST', '/wp/v2/templates/' . self::THEME . '//single', array( 'content' => '<!-- wp:navigation /-->' . self::para( 'Einzeln' ) ) );
		self::assertStringContainsString( 'the template ' . self::THEME . '//single the run writes shows a navigation block without a menu of its own', $locked( array( 'post:wp_navigation:zweites', 'post:wp_template:single' ) ) );
		$page_a = self::on_a( self::made( 'page', 'hier', '<!-- wp:group --><div class="wp-block-group"><!-- wp:navigation /--></div><!-- /wp:group -->' ) );
		self::assertStringContainsString( 'the page ' . $page_a . ' shows a navigation block without a menu of its own', $locked( array( 'post:wp_navigation:zweites' ) ) );
	}

	public function testATemplateANewPartAndWhatThePartNamesGoInTheOrderOfTheirReferences(): void {
		// Codex round 1 of branch 9b (B): a changed template names a new
		// template part by its slug (no theme: the active one); the part
		// names a new reusable block and a changed navigation by their refs.
		// Each reference the boundary asks is an edge of the order: the block
		// (created: a dependency) and the navigation (changed: before it, no
		// dependency) before the part, the part (created) before the
		// template. A second new part names only the changed navigation: it
		// is ready at once, and comes after the navigation all the same (the
		// rank alone would put it first). Over and back.
		self::pair();
		$page = self::para( 'Seite' );
		self::rest_a( 'POST', '/wp/v2/templates/' . self::THEME . '//page', array( 'content' => $page ) );
		self::rest_b( 'POST', '/wp/v2/templates/' . self::THEME . '//page', array( 'content' => $page ) );
		$template_a = self::id_a( 'wp_template', 'page' );
		$template_b = (int) self::rest_b( 'GET', '/wp/v2/templates/' . self::THEME . '//page', array( 'context' => 'edit' ) )['wp_id'];
		self::same_dates( $template_a, $template_b );
		$link  = '<!-- wp:navigation-link {"label":"Start","url":"/","kind":"custom","isTopLevelLink":true} /-->';
		$nav_a = self::on_a( self::made( 'wp_navigation', 'menue', $link ) );
		$nav_b = self::on_b( self::made( 'wp_navigation', 'menue', $link ) );
		self::paired( self::staged() );
		$source = AB_MCP_Sync_Anchor::active()['source_id'];
		self::assertSame( 'active', AB_MCP_Sync_Pairs::find_live( $source, 'post', (string) $template_a )['state'] );
		self::b(
			array(
				array(
					'op'   => 'update_post',
					'args' => array( 'ID' => $nav_b, 'post_content' => $link . $link ),
				),
			)
		);
		$block_b = self::on_b( self::made( 'wp_block', 'baustein', self::para( 'Baustein' ) ) );
		self::rest_b( 'POST', '/wp/v2/template-parts', array( 'slug' => 'promo', 'title' => 'Promo', 'content' => '<!-- wp:block {"ref":' . $block_b . '} /--><!-- wp:navigation {"ref":' . $nav_b . '} /-->', 'area' => 'uncategorized' ) );
		self::rest_b( 'POST', '/wp/v2/template-parts', array( 'slug' => 'hinweis', 'title' => 'Hinweis', 'content' => '<!-- wp:navigation {"ref":' . $nav_b . '} /-->', 'area' => 'uncategorized' ) );
		self::rest_b( 'POST', '/wp/v2/templates/' . self::THEME . '//page', array( 'content' => '<!-- wp:template-part {"slug":"promo"} /-->' . $page ) );
		$before  = self::row_a( $template_a );
		$names   = array( 'post:wp_template:page', 'post:wp_template_part:promo', 'post:wp_block:baustein', 'post:wp_navigation:menue', 'post:wp_template_part:hinweis' );
		$planned = self::planned( $names );
		self::assertSame( array( 'take_over', 'create', 'create', 'take_over', 'create' ), array_map( static fn( $n ) => self::entry( $planned['report']['all'], $n )['action'], $names ) );
		$run    = self::begun( $planned['args'] );
		$closed = self::finish( (int) $run['run_id'] );
		self::assertSame( 'closed_done', $closed['state'], json_encode( $closed ) . self::check_message( self::run_status( (int) $run['run_id'] ) ) );
		$status = self::run_status( (int) $run['run_id'] );
		$slugs = array_map( static fn( $s ) => $s['parts']['object']['identity']['post_name'], self::writes( $status ) );
		$at    = array_flip( $slugs );
		$order = implode( ', ', $slugs );
		self::assertLessThan( $at['promo'], $at['baustein'], 'The block before the part that names it: ' . $order );
		self::assertLessThan( $at['promo'], $at['menue'], 'The navigation before the part that names it: ' . $order );
		self::assertLessThan( $at['hinweis'], $at['menue'], 'The navigation before the other part as well: ' . $order );
		self::assertLessThan( $at['page'], $at['promo'], 'The part before the template that names it: ' . $order );
		$seq = array();
		foreach ( self::writes( $status ) as $step ) {
			$seq[ $step['parts']['object']['identity']['post_name'] ] = $step;
			self::assertSame( 'done', $step['state'], json_encode( $step ) );
		}
		self::assertContains( (int) $seq['baustein']['seq'], $seq['promo']['depends'], 'The new block: a dependency of the part.' );
		self::assertNotContains( (int) $seq['menue']['seq'], $seq['promo']['depends'], 'The changed navigation: before it, no dependency.' );
		self::assertNotContains( (int) $seq['menue']['seq'], $seq['hinweis']['depends'] );
		self::assertContains( (int) $seq['promo']['seq'], $seq['page']['depends'], 'The new part: a dependency of the template.' );
		$block_a   = self::id_a( 'wp_block', 'baustein' );
		$promo_a   = self::id_a( 'wp_template_part', 'promo' );
		$hinweis_a = self::id_a( 'wp_template_part', 'hinweis' );
		self::assertSame( '<!-- wp:block {"ref":' . $block_a . '} /--><!-- wp:navigation {"ref":' . $nav_a . '} /-->', self::row_a( $promo_a )['post_content'] );
		self::assertSame( '<!-- wp:template-part {"slug":"promo"} /-->' . $page, self::row_a( $template_a )['post_content'] );
		wp_cache_flush();
		self::assertStringContainsString( '"slug":"promo"', get_block_template( self::THEME . '//page', 'wp_template' )->content, 'WordPress shows it (with the theme it adds as it reads).' );
		$back = self::back( (int) $run['run_id'] );
		self::assertSame( 'closed_findings', $back['state'], json_encode( $back ) );
		self::assertSame( $before, self::row_a( $template_a ), 'The template byte for byte as before the run.' );
		$expected = array( array( 'post', (string) $block_a ), array( 'post', (string) $promo_a ), array( 'post', (string) $hinweis_a ) );
		sort( $expected );
		self::assertSame( $expected, self::rests( $back ) );
	}

	/* ------------------------------------------ Codex round 2 of 9b */

	/** The types of the objects of a run, by the order of their steps (from its plan: a step that failed early has no identity in its journal). */
	private static function planned_types( int $run_id ): array {
		$run     = \AB_MCP_Sync_Run::get( $run_id );
		$objects = is_array( $run ) && isset( $run['plan']['objects'] ) ? $run['plan']['objects'] : array();
		usort( $objects, static fn( $a, $b ) => (int) $a['seq'] <=> (int) $b['seq'] );
		return array_map( static fn( $o ) => (string) $o['subtype'], $objects );
	}

	/** One call of next on a run: its answer. */
	private static function next( int $run_id ): array {
		$answer = self::gated( 'wp_site_push', array( 'action' => 'next', 'run_id' => $run_id ) );
		self::assertIsArray( $answer, is_wp_error( $answer ) ? $answer->get_error_message() : '' );
		return $answer;
	}

	public function testANewNavigationAsksWhatTheStepsBeforeItLeftAndAPartThatFailedKeepsItsBlockWithoutAMenu(): void {
		// Codex round 2 of branch 9b (1): here the header shows a navigation
		// block without a menu (navigation elf, the only one, shows); on
		// staging the header names elf by its ref, and a newer navigation is
		// new. The check before the first write counts the header as the run
		// writes it. The header step comes first and fails (another hand
		// changed its title after the check); the step of the new navigation
		// then reads the header as it is here, without a menu, and is refused
		// (post:navigation_fallback): the new navigation is not written.
		self::pair();
		$link  = '<!-- wp:navigation-link {"label":"Start","url":"/","kind":"custom","isTopLevelLink":true} /-->';
		$elf_a = self::on_a( self::made( 'wp_navigation', 'elf', $link ) );
		$elf_b = self::on_b( self::made( 'wp_navigation', 'elf', $link ) );
		self::rest_a( 'POST', '/wp/v2/template-parts/' . self::THEME . '//header', array( 'content' => '<!-- wp:navigation /-->' ) );
		self::rest_b( 'POST', '/wp/v2/template-parts/' . self::THEME . '//header', array( 'content' => '<!-- wp:navigation /-->' ) );
		$header_a = self::id_a( 'wp_template_part', 'header' );
		$header_b = (int) self::rest_b( 'GET', '/wp/v2/template-parts/' . self::THEME . '//header', array( 'context' => 'edit' ) )['wp_id'];
		self::same_dates( $header_a, $header_b );
		self::paired( self::staged() );
		self::rest_b( 'POST', '/wp/v2/template-parts/' . self::THEME . '//header', array( 'content' => '<!-- wp:navigation {"ref":' . $elf_b . '} /-->' ) );
		self::on_b( array( 'post_type' => 'wp_navigation', 'post_status' => 'publish', 'post_title' => 'Neu', 'post_name' => 'neu-m', 'post_content' => $link ) );
		$run = self::begun( self::planned( array( 'post:wp_template_part:header', 'post:wp_navigation:neu-m' ) )['args'] );
		$id  = (int) $run['run_id'];
		self::next( $id );
		self::assertStringContainsString( '"findings":[]', self::check_message( self::run_status( $id ) ), 'The check counts the header as the run writes it.' );
		global $wpdb;
		$wpdb->update( $wpdb->posts, array( 'post_title' => 'Kopf, fremd' ), array( 'ID' => $header_a ) );
		clean_post_cache( $header_a );
		$closed = self::finish( $id );
		self::assertSame( 'closed_findings', $closed['state'], json_encode( $closed ) );
		$writes = self::writes( self::run_status( $id ) );
		self::assertSame( array( 'wp_template_part', 'wp_navigation' ), self::planned_types( $id ), 'The header first, the navigation last.' );
		self::assertSame( 'failed', $writes[0]['state'], json_encode( $writes[0] ) );
		self::assertSame( 'failed', $writes[1]['state'], json_encode( $writes[1] ) );
		self::assertStringContainsString( 'post:navigation_fallback', (string) json_encode( $writes[1]['result'] ) );
		self::assertStringContainsString( 'shows a navigation block without a menu of its own', (string) json_encode( $writes[1]['result'] ) );
		self::assertSame( 0, self::id_a( 'wp_navigation', 'neu-m' ), 'The new navigation is not written.' );
		self::assertSame( '<!-- wp:navigation /-->', self::row_a( $header_a )['post_content'] );
		self::assertGreaterThan( 0, $elf_a );
	}

	public function testAPartTheThemeHasAsAFileIsNoDependencyAndTheTemplateGoesWhereTheRowOfThePartFails(): void {
		// Codex round 2 of branch 9b (2): Twenty Twenty-Five has the footer as
		// a file. The run makes its first row (the footer saved on staging)
		// and changes a template that names it. The edge is an order, no
		// dependency: the footer resolves here without its step. The row
		// fails (another hand saved the footer here after the check: the slug
		// is taken); the template goes all the same.
		self::pair();
		$page = self::para( 'Seite' );
		self::rest_a( 'POST', '/wp/v2/templates/' . self::THEME . '//page', array( 'content' => $page ) );
		self::rest_b( 'POST', '/wp/v2/templates/' . self::THEME . '//page', array( 'content' => $page ) );
		$template_a = self::id_a( 'wp_template', 'page' );
		$template_b = (int) self::rest_b( 'GET', '/wp/v2/templates/' . self::THEME . '//page', array( 'context' => 'edit' ) )['wp_id'];
		self::same_dates( $template_a, $template_b );
		self::paired( self::staged() );
		self::rest_b( 'POST', '/wp/v2/template-parts/' . self::THEME . '//footer', array( 'content' => self::para( 'Fuss neu' ) ) );
		self::rest_b( 'POST', '/wp/v2/templates/' . self::THEME . '//page', array( 'content' => $page . '<!-- wp:template-part {"slug":"footer","theme":"' . self::THEME . '"} /-->' ) );
		$run    = self::begun( self::planned( array( 'post:wp_template_part:footer', 'post:wp_template:page' ) )['args'] );
		$id     = (int) $run['run_id'];
		$status = self::run_status( $id );
		$writes = self::writes( $status );
		self::assertSame( array( 'wp_template_part', 'wp_template' ), self::planned_types( $id ), 'The part first all the same.' );
		self::assertNotContains( (int) $writes[0]['seq'], $writes[1]['depends'], 'The footer resolves here as a file: no dependency.' );
		self::next( $id );
		self::rest_a( 'POST', '/wp/v2/template-parts/' . self::THEME . '//footer', array( 'content' => self::para( 'Fuss, fremd' ) ) );
		wp_cache_flush();
		$closed = self::finish( $id );
		self::assertSame( 'closed_findings', $closed['state'], json_encode( $closed ) );
		$writes = self::writes( self::run_status( $id ) );
		self::assertSame( 'failed', $writes[0]['state'], json_encode( $writes[0] ) );
		self::assertSame( 'done', $writes[1]['state'], json_encode( $writes[1] ) );
		self::assertSame( $page . '<!-- wp:template-part {"slug":"footer","theme":"' . self::THEME . '"} /-->', self::row_a( $template_a )['post_content'] );
	}

	public function testAPageThatDropsItsLastNavigationBlockLetsANewNavigationGo(): void {
		// Codex round 2 of branch 9b (3): here a page shows a navigation block
		// without a menu (the header names the navigation basis by its ref).
		// On staging the page drops that block, and a newer navigation is new.
		// The page as the run writes it counts, though it names no navigation
		// any more: nothing shows the move, the navigation goes, and back.
		self::pair();
		$link    = '<!-- wp:navigation-link {"label":"Start","url":"/","kind":"custom","isTopLevelLink":true} /-->';
		$basis_a = self::on_a( self::made( 'wp_navigation', 'basis', $link ) );
		$basis_b = self::on_b( self::made( 'wp_navigation', 'basis', $link ) );
		self::rest_a( 'POST', '/wp/v2/template-parts/' . self::THEME . '//header', array( 'content' => '<!-- wp:navigation {"ref":' . $basis_a . '} /-->' ) );
		self::rest_b( 'POST', '/wp/v2/template-parts/' . self::THEME . '//header', array( 'content' => '<!-- wp:navigation {"ref":' . $basis_b . '} /-->' ) );
		$header_a = self::id_a( 'wp_template_part', 'header' );
		$header_b = (int) self::rest_b( 'GET', '/wp/v2/template-parts/' . self::THEME . '//header', array( 'context' => 'edit' ) )['wp_id'];
		self::same_dates( $header_a, $header_b );
		$page_a = self::on_a( self::made( 'page', 'menueseite', '<!-- wp:navigation /-->' ) );
		$page_b = self::on_b( self::made( 'page', 'menueseite', '<!-- wp:navigation /-->' ) );
		self::paired( self::staged() );
		self::assertSame( 'active', AB_MCP_Sync_Pairs::find_live( AB_MCP_Sync_Anchor::active()['source_id'], 'post', (string) $page_a )['state'] );
		self::assertNotNull( AB_MCP_Sync_Post_Writer::fallback_shown( array() ), 'Here the page shows a block without a menu.' );
		self::b(
			array(
				array(
					'op'   => 'update_post',
					'args' => array( 'ID' => $page_b, 'post_content' => self::para( 'Ohne Menue' ) ),
				),
			)
		);
		self::on_b( array( 'post_type' => 'wp_navigation', 'post_status' => 'publish', 'post_title' => 'Neu', 'post_name' => 'neu', 'post_content' => $link ) );
		$run    = self::begun( self::planned( array( 'post:page:menueseite', 'post:wp_navigation:neu' ) )['args'] );
		$closed = self::finish( (int) $run['run_id'] );
		self::assertSame( 'closed_done', $closed['state'], json_encode( $closed ) . self::check_message( self::run_status( (int) $run['run_id'] ) ) );
		self::assertSame( array( 'page', 'wp_navigation' ), array_map( static fn( $s ) => $s['parts']['object']['identity']['post_type'], self::writes( self::run_status( (int) $run['run_id'] ) ) ) );
		$neu_a = self::id_a( 'wp_navigation', 'neu' );
		self::assertGreaterThan( 0, $neu_a );
		self::assertNull( AB_MCP_Sync_Post_Writer::fallback_shown( array() ) );
		$back = self::back( (int) $run['run_id'] );
		self::assertSame( 'closed_findings', $back['state'], json_encode( $back ) );
		self::assertSame( '<!-- wp:navigation /-->', self::row_a( $page_a )['post_content'], 'The page back as before.' );
		self::assertSame( array( array( 'post', (string) $neu_a ) ), self::rests( $back ) );
	}

	public function testATokenWhoseDependenciesNoLongerHoldIsStale(): void {
		// Codex round 2 of branch 9b (2): whether an edge is a dependency rests
		// on this site (a part it has as a row or a file), and a dependency
		// decides which step is skipped: the state of the token covers the
		// dependencies of each step. A row of the part saved here between the
		// preview and the confirmation makes the token stale.
		self::pair();
		self::paired( self::staged() );
		self::rest_b( 'POST', '/wp/v2/template-parts', array( 'slug' => 'promo', 'title' => 'Promo', 'content' => self::para( 'Promo' ), 'area' => 'uncategorized' ) );
		self::rest_b( 'POST', '/wp/v2/templates/' . self::THEME . '//single', array( 'content' => '<!-- wp:template-part {"slug":"promo","theme":"' . self::THEME . '"} /-->' ) );
		$args    = self::planned( array( 'post:wp_template_part:promo', 'post:wp_template:single' ) )['args'];
		$preview = self::gated( 'wp_site_push', $args );
		self::assertIsArray( $preview, is_wp_error( $preview ) ? $preview->get_error_message() : '' );
		self::rest_a( 'POST', '/wp/v2/template-parts', array( 'slug' => 'promo', 'title' => 'Promo', 'content' => self::para( 'Promo hier' ), 'area' => 'uncategorized' ) );
		wp_cache_flush();
		$stale = self::gated( 'wp_site_push', $args + array( 'confirm_token' => $preview['confirm_token'] ) );
		self::assertInstanceOf( \WP_Error::class, $stale, 'The old token confirms another dependency than it showed.' );
		self::assertSame( 'ab_mcp_confirm_mismatch', $stale->get_error_code() );
	}
}

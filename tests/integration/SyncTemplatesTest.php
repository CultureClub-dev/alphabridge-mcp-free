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
				return (string) json_encode( $step['result'] );
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
		self::assertSame( array( self::THEME ), self::terms_a( $header_a, 'wp_theme' ), 'What the core makes as the site editor saves a part of the theme.' );
		self::assertSame( array( 'header' ), self::terms_a( $header_a, 'wp_template_part_area' ) );
		self::assertSame( array( 'theme' ), self::meta_a( $header_a, 'origin' ) );
		self::paired( self::staged(), array( 'equal', 'differs' ) );
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
		self::assertSame( '#112233', \WP_Theme_JSON_Resolver::get_user_data()->get_raw_data()['styles']['color']['background'] ?? null, 'The global styles as the core reads them.' );
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
		$source = AB_MCP_Sync_Anchor::active()['source_id'];
		self::assertSame( 'residual', AB_MCP_Sync_Pairs::find_live( $source, 'post', (string) $footer_a )['state'] );
		self::assertSame( 'residual', AB_MCP_Sync_Pairs::find_live( $source, 'post', (string) $single_a )['state'] );
		self::assertSame( 'active', AB_MCP_Sync_Pairs::find_live( $source, 'post', (string) $header_a )['state'] );
		wp_cache_flush();
		self::assertSame( 'custom', get_block_template( self::THEME . '//footer', 'wp_template_part' )->source, 'The new row stays as a rest.' );
		self::assertSame( array(), $this->deletions, 'Nothing was deleted, forward or back.' );
	}
}

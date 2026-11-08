<?php
/**
 * Z8 branch 9 (heikel/z8-inhalte) on two WordPress sites: the kind term and
 * the full kind post, end to end through wp_site_push and wp_site_revert.
 * Site B is the staging site, in processes of its own; site A, this
 * process, the production site (as tests/integration/SyncRunTest.php).
 *
 * - Terms (decision 25, A4): a changed category (name, description,
 *   parent) and a new tag go over; the way back puts the changed one back
 *   through wp_update_term() and leaves the new one as a rest, its pair
 *   residual. A new category, a new child of it and a new post in the
 *   child go in one run, parents first. The meta of a term goes by its
 *   right and back raw; a new term with meta is locked by the report.
 * - The field rule of the post (decision 28): a protected key of a plugin
 *   equal on both sides stays untouched and no longer locks the post; one
 *   that differs locks it before anything is written, until a rule of the
 *   site (an auth filter) gives the right, then it goes over and back. A
 *   new post with a plain key goes; one whose right the new post refuses
 *   (map_meta_cap at the object, after it was made) stays as a rest.
 * - The slug: a new page whose slug a post in the trash holds is locked
 *   before anything is written (WordPress would rename the post in the
 *   trash).
 * - The builder lock (decision 31): a WPBakery page is locked in the
 *   report, a Kadence page goes over.
 * - No deletion (decisions 21, 22): no step and no step of the way back
 *   deletes or trashes a post, deletes a term or an option of the site.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests\Integration;

use AB_MCP_IT_Sites;
use AB_MCP_IT_Sync_Steps;
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
use AB_MCP_Sync_Report;
use AB_MCP_Sync_Resources;
use AB_MCP_Sync_Run;
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

final class SyncContentTest extends TestCase {

	/** The date every post of these tests carries, on both sites. */
	private const DATE = '2026-10-01 10:00:00';

	/** @var string|null DOCUMENT_ROOT before the case. */
	private $docroot_before;

	/** @var array[] The deletions the filters of a case saw (no_deletions()). */
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
		remove_all_filters( 'map_meta_cap' );
		remove_all_filters( 'auth_post_meta__seo_title' );
		remove_all_filters( 'wp_revisions_to_keep' );
		remove_all_filters( 'pre_delete_post' );
		remove_all_filters( 'pre_trash_post' );
		remove_all_actions( 'pre_delete_term' );
		remove_all_actions( 'delete_option' );
		delete_option( 'nav_menu_options' );
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
		return AB_MCP_IT_Sync_Steps::run( 'b', $steps );
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

	/** The arguments of a page, the same on both sites but for $over. */
	private static function page( string $name, string $content, array $over = array() ): array {
		return array_merge(
			array(
				'post_type'     => 'page',
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

	/** A term of A, in this process. */
	private static function term_a( string $name, string $taxonomy, array $args = array() ): int {
		$term = wp_insert_term( $name, $taxonomy, $args );
		self::assertIsArray( $term, is_wp_error( $term ) ? $term->get_error_message() : '' );
		return (int) $term['term_id'];
	}

	/** A term of B. */
	private static function term_b( string $name, string $taxonomy, array $args = array() ): int {
		return (int) self::b(
			array(
				array(
					'op'       => 'insert_term',
					'name'     => $name,
					'taxonomy' => $taxonomy,
					'args'     => $args,
					'as'       => 'term',
				),
			)
		)['term'];
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
	 * The entry of an object: a post by its slug ('post:about'), a term by
	 * its taxonomy and slug ('term:category:news'), on either side.
	 */
	private static function entry( array $entries, string $name ): array {
		$parts = explode( ':', $name );
		foreach ( $entries as $entry ) {
			foreach ( array( 'staging', 'live' ) as $side ) {
				$identity = $entry[ $side ]['identity'] ?? array();
				if ( 'post' === $parts[0] && 'post' === $entry['kind'] && ( $identity['post_name'] ?? null ) === $parts[1] ) {
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
	private static function planned( array $names, array $extra = array() ): array {
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
		return array(
			'report' => $report,
			'args'   => array(
				'action'    => 'plan',
				'report_id' => $report['report_id'],
				'select'    => $select,
				'packages'  => array( $package ),
			) + $extra,
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
	private static function finish( int $run_id, int $max = 14 ): array {
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
		for ( $i = 0; $i < 14; $i++ ) {
			$answer = self::gated( 'wp_site_revert', array( 'action' => 'next', 'run_id' => (int) $begun['run_id'] ) );
			self::assertIsArray( $answer, is_wp_error( $answer ) ? $answer->get_error_message() : '' );
			if ( in_array( $answer['state'], AB_MCP_Sync_Schema::CLOSED, true ) ) {
				return self::gated( 'wp_site_revert', array( 'action' => 'status', 'run_id' => (int) $begun['run_id'] ) );
			}
		}
		self::fail( 'The way back did not close.' );
	}

	/** The row of a term of A, read past every cache. */
	private static function term_row_a( int $id ): array {
		$terms = AB_MCP_IT_Sites::rows( 'a', 'terms', array( 'term_id' => (string) $id ) );
		$tax   = AB_MCP_IT_Sites::rows( 'a', 'term_taxonomy', array( 'term_id' => (string) $id ) );
		self::assertCount( 1, $terms, "Term $id of A." );
		self::assertCount( 1, $tax );
		unset( $tax[0]['count'] );
		return $terms[0] + $tax[0];
	}

	/** The term of A with this slug in this taxonomy, read through the API (fresh). */
	private static function term_id_a( string $slug, string $taxonomy ): int {
		clean_term_cache( array(), $taxonomy, true );
		$term = get_term_by( 'slug', $slug, $taxonomy );
		self::assertIsObject( $term, "$taxonomy $slug on A." );
		return (int) $term->term_id;
	}

	/** A meta value of a post or term of A, read past every cache. */
	private static function meta_a( string $type, int $id, string $key ): array {
		return array_column( AB_MCP_IT_Sites::rows( 'a', $type . 'meta', array( $type . '_id' => (string) $id, 'meta_key' => $key ) ), 'meta_value' );
	}

	/** The write steps of a status, by their kind and op. */
	private static function writes( array $status ): array {
		$out = array();
		foreach ( $status['steps'] as $step ) {
			if ( 'write' === $step['op'] ) {
				$out[] = $step;
			}
		}
		return $out;
	}

	/** The message of the check before the first write, where it found something. */
	private static function check_message( array $status ): string {
		foreach ( $status['steps'] as $step ) {
			if ( 'check' === $step['op'] ) {
				return (string) json_encode( $step['result'] );
			}
		}
		return '';
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
				// The bookkeeping of the takeover and of WordPress (group B:
				// the lock of a boundary, transients, the cache of the hierarchy
				// of the categories clean_term_cache() empties as a term
				// changes) is no option of the site.
				if ( ! AB_MCP_Sync_Exclusions::option( (string) $option ) ) {
					$this->deletions[] = array( 'delete_option', (string) $option );
				}
			},
			1,
			1
		);
	}

	/* ------------------------------------------------------------- terms */

	public function testAChangedCategoryAndANewTagGoOverAndTheWayBackPutsTheChangedOneBackAndLeavesTheNewOneAsARest(): void {
		// The ordinary term (decision 25, A4): a category paired on both
		// sites, changed on staging in name, description and parent, and a
		// tag new on staging. Both go over in one run without a deviation;
		// the way back puts the category back byte for byte through
		// wp_update_term() and leaves the new tag as a rest, its pair
		// residual. No step deletes anything.
		self::pair();
		$parent_a = self::term_a( 'Topics', 'category' );
		$news_a   = self::term_a( 'News', 'category', array( 'description' => 'Old news.' ) );
		$parent_b = self::term_b( 'Topics', 'category' );
		$news_b   = self::term_b( 'News', 'category', array( 'description' => 'Old news.' ) );
		self::paired( self::staged() );
		self::b(
			array(
				array(
					'op'       => 'update_term',
					'term'     => $news_b,
					'taxonomy' => 'category',
					'args'     => array( 'name' => 'News & Views', 'description' => 'Fresh <em>news</em>.', 'parent' => $parent_b ),
				),
			)
		);
		self::term_b( 'Hint', 'post_tag', array( 'description' => 'A hint.' ) );
		$before  = self::term_row_a( $news_a );
		$planned = self::planned( array( 'term:category:news', 'term:post_tag:hint' ) );
		self::assertSame( 'take_over', self::entry( $planned['report']['all'], 'term:category:news' )['action'] );
		self::assertSame( 'create', self::entry( $planned['report']['all'], 'term:post_tag:hint' )['action'] );
		$this->watch_deletions();
		$run    = self::begun( $planned['args'] );
		$closed = self::finish( (int) $run['run_id'] );
		self::assertSame( 'closed_done', $closed['state'], json_encode( $closed ) );
		foreach ( self::writes( self::run_status( (int) $run['run_id'] ) ) as $step ) {
			self::assertSame( 'done', $step['state'], json_encode( $step ) );
			self::assertSame( 'term', $step['parts']['object']['kind'] );
			self::assertNull( $step['parts']['object']['deviated'] );
			foreach ( $step['parts']['parts'] as $part ) {
				self::assertSame( 'read', $part['state'], $part['part'] );
			}
		}
		$hint_a = self::term_id_a( 'hint', 'post_tag' );
		// Phase 7 emptied the caches of each term by the writer of its kind.
		foreach ( self::run_status( (int) $run['run_id'] )['steps'] as $step ) {
			if ( 'post' === $step['op'] ) {
				self::assertSame( array( 'term:' . $news_a, 'term:' . $hint_a ), array_keys( $step['result']['objects'] ) );
				self::assertSame( array( 'term', 'term_meta' ), $step['result']['objects'][ 'term:' . $news_a ] );
			}
		}
		$after = self::term_row_a( $news_a );
		self::assertSame( 'News &amp; Views', $after['name'], 'As WordPress stored it on staging.' );
		self::assertSame( 'Fresh <em>news</em>.', $after['description'] );
		self::assertSame( (string) $parent_a, $after['parent'], 'The parent by its pair.' );
		self::assertSame( 'news', $after['slug'] );
		self::assertSame( 'A hint.', self::term_row_a( $hint_a )['description'] );
		$source = AB_MCP_Sync_Anchor::active()['source_id'];
		self::assertSame( 'active', AB_MCP_Sync_Pairs::find_live( $source, 'term', (string) $hint_a )['state'] );
		self::assertSame( 'active', AB_MCP_Sync_Pairs::find_live( $source, 'term', (string) $news_a )['state'] );
		// The next report sees both as equal.
		$again = self::report( self::staged() );
		self::assertSame( 'equal', self::entry( $again['all'], 'term:category:news' )['result'] );
		self::assertSame( 'equal', self::entry( $again['all'], 'term:post_tag:hint' )['result'] );
		// The way back: the category back as it was, the new tag a rest.
		$back = self::back( (int) $run['run_id'] );
		self::assertSame( 'closed_findings', $back['state'], json_encode( $back ) );
		self::assertSame( $before, self::term_row_a( $news_a ), 'Byte for byte as before the run.' );
		self::assertSame( 'active', AB_MCP_Sync_Pairs::find_live( $source, 'term', (string) $news_a )['state'], 'Back to its snapshot.' );
		self::assertSame( 'residual', AB_MCP_Sync_Pairs::find_live( $source, 'term', (string) $hint_a )['state'] );
		self::assertSame( 'A hint.', self::term_row_a( $hint_a )['description'], 'The new tag stays.' );
		$rests = array();
		foreach ( $back['steps'] as $step ) {
			if ( isset( $step['result']['rest'] ) ) {
				$rests[] = $step['result']['rest'];
			}
		}
		self::assertSame( array( array( 'term', (string) $hint_a ) ), array_map( static fn( $r ) => array( $r['kind'], $r['live_ref'] ), $rests ) );
		self::assertSame( array(), $this->deletions, 'Nothing was deleted, forward or back.' );
	}

	public function testANewCategoryWithANewChildAndANewPostInTheChildGoInOneRunParentsFirst(): void {
		// Eltern zuerst (decision 16, 25): three objects new on staging that
		// need each other in one direction (the child the parent, the post the
		// child) are written in that order in one run, each mapped by the pair
		// its target's step made.
		self::pair();
		self::paired( self::staged() );
		$topics = self::term_b( 'Topics', 'category' );
		$garden = self::term_b( 'Garden', 'category', array( 'parent' => $topics ) );
		self::on_b( self::page( 'tips', 'Water in the morning.', array( 'post_type' => 'post', 'post_category' => array( $garden ) ) ) );
		$planned = self::planned( array( 'post:tips', 'term:category:garden', 'term:category:topics' ) );
		$run     = self::begun( $planned['args'] );
		$order   = array();
		foreach ( $run['plan']['objects'] ?? array() as $object ) {
			$order[] = $object['kind'] . ':' . $object['staging_ref'];
		}
		$closed = self::finish( (int) $run['run_id'] );
		self::assertSame( 'closed_done', $closed['state'], json_encode( $closed ) );
		$steps = self::writes( self::run_status( (int) $run['run_id'] ) );
		self::assertSame( array( 'term', 'term', 'post' ), array_map( static fn( $s ) => $s['parts']['object']['kind'], $steps ), 'The terms first.' );
		self::assertSame( (string) $topics, $steps[0]['parts']['object']['staging_ref'], 'The parent before the child.' );
		$topics_a = self::term_id_a( 'topics', 'category' );
		$garden_a = self::term_id_a( 'garden', 'category' );
		self::assertSame( (string) $topics_a, self::term_row_a( $garden_a )['parent'] );
		$tips = get_page_by_path( 'tips', OBJECT, 'post' );
		self::assertIsObject( $tips );
		self::assertSame( array( $garden_a ), wp_get_post_categories( (int) $tips->ID ), 'The post in the new child, by the pair its step made.' );
		unset( $order );
	}

	public function testTwoCategoriesThatSwapTheirPlacesGoOverAndBackAndAnOrdinaryMoveGoesAsWell(): void {
		// Codex round 1 on branch 9, B4: here Beta is under Alpha; on staging
		// Beta is a root and Alpha under Beta. The check asks a loop of the
		// state the run leaves (before, against the state here, Alpha under
		// Beta closed a loop and the whole run was aborted); the plan writes
		// Beta first (the parent the run moves), so no state in between
		// closes a loop that wp_update_term() would break by changing the
		// other term. The way back goes from the last step to the first:
		// Alpha back to the root, then Beta back under Alpha. An ordinary
		// move (Beta from Alpha to Gamma) goes as well.
		self::pair();
		$alpha_a = self::term_a( 'Alpha', 'category' );
		$beta_a  = self::term_a( 'Beta', 'category', array( 'parent' => $alpha_a ) );
		$gamma_a = self::term_a( 'Gamma', 'category' );
		$alpha_b = self::term_b( 'Alpha', 'category' );
		$beta_b  = self::term_b( 'Beta', 'category', array( 'parent' => $alpha_b ) );
		$gamma_b = self::term_b( 'Gamma', 'category' );
		self::paired( self::staged() );
		self::b(
			array(
				array( 'op' => 'update_term', 'term' => $beta_b, 'taxonomy' => 'category', 'args' => array( 'parent' => 0 ) ),
				array( 'op' => 'update_term', 'term' => $alpha_b, 'taxonomy' => 'category', 'args' => array( 'parent' => $beta_b ) ),
			)
		);
		$run    = self::begun( self::planned( array( 'term:category:alpha', 'term:category:beta' ) )['args'] );
		$closed = self::finish( (int) $run['run_id'] );
		self::assertSame( 'closed_done', $closed['state'], json_encode( $closed ) . self::check_message( self::run_status( (int) $run['run_id'] ) ) );
		$steps = self::writes( self::run_status( (int) $run['run_id'] ) );
		self::assertSame( array( (string) $beta_b, (string) $alpha_b ), array_map( static fn( $s ) => $s['parts']['object']['staging_ref'], $steps ), 'Beta first.' );
		self::assertSame( (string) $beta_a, self::term_row_a( $alpha_a )['parent'] );
		self::assertSame( '0', self::term_row_a( $beta_a )['parent'] );
		$back = self::back( (int) $run['run_id'] );
		self::assertSame( 'closed_reverted', $back['state'], json_encode( $back ) );
		self::assertSame( '0', self::term_row_a( $alpha_a )['parent'], 'Alpha back to the root.' );
		self::assertSame( (string) $alpha_a, self::term_row_a( $beta_a )['parent'], 'Beta back under Alpha.' );
		// An ordinary move: Beta from Alpha to Gamma (Alpha a root again on staging).
		self::b(
			array(
				array( 'op' => 'update_term', 'term' => $alpha_b, 'taxonomy' => 'category', 'args' => array( 'parent' => 0 ) ),
				array( 'op' => 'update_term', 'term' => $beta_b, 'taxonomy' => 'category', 'args' => array( 'parent' => $gamma_b ) ),
			)
		);
		$moved  = self::begun( self::planned( array( 'term:category:beta' ) )['args'] );
		$closed = self::finish( (int) $moved['run_id'] );
		self::assertSame( 'closed_done', $closed['state'], json_encode( $closed ) );
		self::assertSame( (string) $gamma_a, self::term_row_a( $beta_a )['parent'] );
		self::assertSame( '0', self::term_row_a( $alpha_a )['parent'], 'Alpha untouched.' );
	}

	public function testAChainOfCategoriesTurnedOverGoesOverAndBack(): void {
		// Codex round 2 on branch 9, B4: here Alpha is the root, Beta under
		// Alpha, Gamma under Beta; on staging Beta is the root, Gamma under
		// Beta, Alpha under Gamma. Only Alpha and Beta change, Gamma keeps
		// its parent and has no step. The terms are written from the root of
		// the tree the run leaves down: Beta (depth 0) before Alpha (depth 2,
		// under Gamma under Beta); before, the order knew the direct parent
		// only, Alpha went first and closed a loop (term:parent), and Beta
		// was moved alone. The way back goes from the last step to the first.
		self::pair();
		$alpha_a = self::term_a( 'Alpha', 'category' );
		$beta_a  = self::term_a( 'Beta', 'category', array( 'parent' => $alpha_a ) );
		$gamma_a = self::term_a( 'Gamma', 'category', array( 'parent' => $beta_a ) );
		$alpha_b = self::term_b( 'Alpha', 'category' );
		$beta_b  = self::term_b( 'Beta', 'category', array( 'parent' => $alpha_b ) );
		$gamma_b = self::term_b( 'Gamma', 'category', array( 'parent' => $beta_b ) );
		self::paired( self::staged() );
		self::b(
			array(
				array( 'op' => 'update_term', 'term' => $beta_b, 'taxonomy' => 'category', 'args' => array( 'parent' => 0 ) ),
				array( 'op' => 'update_term', 'term' => $alpha_b, 'taxonomy' => 'category', 'args' => array( 'parent' => $gamma_b ) ),
			)
		);
		$run    = self::begun( self::planned( array( 'term:category:alpha', 'term:category:beta' ) )['args'] );
		$closed = self::finish( (int) $run['run_id'] );
		self::assertSame( 'closed_done', $closed['state'], json_encode( $closed ) . self::check_message( self::run_status( (int) $run['run_id'] ) ) );
		$steps = self::writes( self::run_status( (int) $run['run_id'] ) );
		self::assertSame( array( (string) $beta_b, (string) $alpha_b ), array_map( static fn( $s ) => $s['parts']['object']['staging_ref'], $steps ), 'Beta first.' );
		self::assertSame( (string) $gamma_a, self::term_row_a( $alpha_a )['parent'] );
		self::assertSame( '0', self::term_row_a( $beta_a )['parent'] );
		self::assertSame( (string) $beta_a, self::term_row_a( $gamma_a )['parent'], 'Gamma untouched.' );
		$back = self::back( (int) $run['run_id'] );
		self::assertSame( 'closed_reverted', $back['state'], json_encode( $back ) );
		self::assertSame( '0', self::term_row_a( $alpha_a )['parent'] );
		self::assertSame( (string) $alpha_a, self::term_row_a( $beta_a )['parent'] );
		self::assertSame( (string) $beta_a, self::term_row_a( $gamma_a )['parent'] );
	}

	public function testAChildWhoseParentStepFailsGoesAllTheSame(): void {
		// Codex round 2 on branch 9, B4: a parent and its child change their
		// descriptions only. After the check another hand changes the
		// description of the parent here: its step fails on the fresh hash,
		// as it should. The child goes all the same: the order of the terms
		// is no dependency (before, an edge to the parent skipped the child).
		self::pair();
		$topics_a = self::term_a( 'Topics', 'category', array( 'description' => 'All topics.' ) );
		$garden_a = self::term_a( 'Garden', 'category', array( 'parent' => $topics_a, 'description' => 'Plants.' ) );
		$topics_b = self::term_b( 'Topics', 'category', array( 'description' => 'All topics.' ) );
		$garden_b = self::term_b( 'Garden', 'category', array( 'parent' => $topics_b, 'description' => 'Plants.' ) );
		self::paired( self::staged() );
		self::b(
			array(
				array( 'op' => 'update_term', 'term' => $topics_b, 'taxonomy' => 'category', 'args' => array( 'description' => 'All topics, on staging.' ) ),
				array( 'op' => 'update_term', 'term' => $garden_b, 'taxonomy' => 'category', 'args' => array( 'description' => 'Plants, on staging.' ) ),
			)
		);
		$run    = self::begun( self::planned( array( 'term:category:topics', 'term:category:garden' ) )['args'] );
		$run_id = (int) $run['run_id'];
		$check  = self::gated( 'wp_site_push', array( 'action' => 'next', 'run_id' => $run_id ) );
		self::assertIsArray( $check, is_wp_error( $check ) ? $check->get_error_message() : '' );
		self::assertSame( 'done', $check['step']['state'], json_encode( $check ) );
		// Another hand, after the check.
		$changed = wp_update_term( $topics_a, 'category', array( 'description' => 'Changed here.' ) );
		self::assertIsArray( $changed, is_wp_error( $changed ) ? $changed->get_error_message() : '' );
		$closed = self::finish( $run_id );
		self::assertSame( 'closed_findings', $closed['state'], json_encode( $closed ) );
		$refs  = array_column( AB_MCP_Sync_Run::get( $run_id )['plan']['objects'], 'staging_ref', 'seq' );
		$steps = array();
		foreach ( self::writes( self::run_status( $run_id ) ) as $step ) {
			$steps[ (string) $refs[ (int) $step['seq'] ] ] = $step['state'];
		}
		self::assertNotSame( 'done', $steps[ (string) $topics_b ], json_encode( $steps ) );
		self::assertSame( 'done', $steps[ (string) $garden_b ], 'The child goes: ' . json_encode( $steps ) );
		self::assertSame( 'Plants, on staging.', self::term_row_a( $garden_a )['description'] );
		self::assertSame( 'Changed here.', self::term_row_a( $topics_a )['description'], 'The other hand\'s change stands.' );
	}

	public function testATokenWhoseOrderOfStepsNoLongerHoldsIsStale(): void {
		// Codex round 3 on branch 9, B: only the descriptions of Alpha and
		// Beta are chosen, Alpha first in the report; here Root → Pole →
		// Alpha and Quay → Beta. The preview writes Beta (depth 1) before
		// Alpha (depth 2). Before the confirmation another hand hangs Pole,
		// which is not chosen, to the root: Alpha and Beta are unchanged, yet
		// the order turns (both of depth 1, by the report). The token covers
		// the order of the steps it confirms: stale, the same refusal and
		// way as for any change of the state (a fresh preview). Before, the
		// old token went through with another order than the one shown.
		self::pair();
		$root_a  = self::term_a( 'Root', 'category' );
		$pole_a  = self::term_a( 'Pole', 'category', array( 'parent' => $root_a ) );
		$alpha_a = self::term_a( 'Alpha', 'category', array( 'parent' => $pole_a, 'description' => 'A.' ) );
		$quay_a  = self::term_a( 'Quay', 'category' );
		$beta_a  = self::term_a( 'Beta', 'category', array( 'parent' => $quay_a, 'description' => 'B.' ) );
		$root_b  = self::term_b( 'Root', 'category' );
		$pole_b  = self::term_b( 'Pole', 'category', array( 'parent' => $root_b ) );
		$alpha_b = self::term_b( 'Alpha', 'category', array( 'parent' => $pole_b, 'description' => 'A.' ) );
		$quay_b  = self::term_b( 'Quay', 'category' );
		$beta_b  = self::term_b( 'Beta', 'category', array( 'parent' => $quay_b, 'description' => 'B.' ) );
		self::paired( self::staged() );
		self::b(
			array(
				array( 'op' => 'update_term', 'term' => $alpha_b, 'taxonomy' => 'category', 'args' => array( 'description' => 'A, on staging.' ) ),
				array( 'op' => 'update_term', 'term' => $beta_b, 'taxonomy' => 'category', 'args' => array( 'description' => 'B, on staging.' ) ),
			)
		);
		$args    = self::planned( array( 'term:category:alpha', 'term:category:beta' ) )['args'];
		$order   = static function ( array $preview ): array {
			$out = array();
			foreach ( $preview['preview']['steps'] ?? $preview['steps'] ?? array() as $step ) {
				if ( isset( $step['object'] ) ) {
					$out[] = (string) $step['object']['staging_ref'];
				}
			}
			return $out;
		};
		$preview = self::gated( 'wp_site_push', $args );
		self::assertIsArray( $preview, is_wp_error( $preview ) ? $preview->get_error_message() : '' );
		self::assertSame( array( (string) $beta_b, (string) $alpha_b ), $order( $preview ), 'Beta first: ' . json_encode( $preview ) );
		// Another hand hangs Pole to the root; Alpha and Beta stay as they are.
		$moved = wp_update_term( $pole_a, 'category', array( 'parent' => 0 ) );
		self::assertIsArray( $moved, is_wp_error( $moved ) ? $moved->get_error_message() : '' );
		$stale = self::gated( 'wp_site_push', $args + array( 'confirm_token' => $preview['confirm_token'] ) );
		self::assertInstanceOf( \WP_Error::class, $stale, 'The old token confirms another order than it showed.' );
		self::assertSame( 'ab_mcp_confirm_mismatch', $stale->get_error_code() );
		self::assertNull( AB_MCP_Sync_Run::open_run(), 'No run was made.' );
		// A fresh preview shows the order as it is now and confirms.
		$again = self::gated( 'wp_site_push', $args );
		self::assertSame( array( (string) $alpha_b, (string) $beta_b ), $order( $again ), 'Both of depth 1 now: by the report.' );
		$run = self::gated( 'wp_site_push', $args + array( 'confirm_token' => $again['confirm_token'] ) );
		self::assertIsArray( $run, is_wp_error( $run ) ? $run->get_error_message() : '' );
		self::assertSame( 'closed_done', self::finish( (int) $run['run_id'] )['state'] );
		self::assertSame( 'A, on staging.', self::term_row_a( $alpha_a )['description'] );
		self::assertSame( 'B, on staging.', self::term_row_a( $beta_a )['description'] );
	}

	public function testTheMetaOfATermGoesByItsRightAndBackAndANewTermWithMetaIsLocked(): void {
		// Term meta (decision 28): a key that differs is written with the
		// right edit_term_meta at the term and goes back raw, byte for byte;
		// a key equal on both sides stays untouched; a new term with meta is
		// locked in the report (term:create_with_meta, Z8d).
		self::pair();
		$shop_a = self::term_a( 'Shop', 'category' );
		add_term_meta( $shop_a, 'color', 'red' );
		add_term_meta( $shop_a, 'size', 'L' );
		add_term_meta( $shop_a, 'api_key', 'live-key' );
		$shop_b = self::term_b( 'Shop', 'category' );
		self::b(
			array(
				array( 'op' => 'add_term_meta', 'term' => $shop_b, 'key' => 'color', 'value' => 'red' ),
				array( 'op' => 'add_term_meta', 'term' => $shop_b, 'key' => 'size', 'value' => 'L' ),
				// A key with the name of a credential: never packed, never
				// compared, never written (B9).
				array( 'op' => 'add_term_meta', 'term' => $shop_b, 'key' => 'api_key', 'value' => 'staging-key' ),
			)
		);
		self::paired( self::staged() );
		$size_rows = AB_MCP_IT_Sites::rows( 'a', 'termmeta', array( 'term_id' => (string) $shop_a, 'meta_key' => 'size' ) );
		$promo     = self::term_b( 'Promo', 'post_tag' );
		self::b(
			array(
				array( 'op' => 'update_term_meta', 'term' => $shop_b, 'key' => 'color', 'value' => 'blue' ),
				array( 'op' => 'add_term_meta', 'term' => $promo, 'key' => 'badge', 'value' => '1' ),
			)
		);
		$planned = self::planned( array( 'term:category:shop' ) );
		$entry   = self::entry( $planned['report']['all'], 'term:post_tag:promo' );
		self::assertSame( 'locked', $entry['action'], json_encode( $entry ) );
		self::assertSame( 'term:create_with_meta', $entry['locks'][0]['key'] );
		$run    = self::begun( $planned['args'] );
		$closed = self::finish( (int) $run['run_id'] );
		self::assertSame( 'closed_done', $closed['state'], json_encode( $closed ) );
		$steps = self::writes( self::run_status( (int) $run['run_id'] ) );
		self::assertSame( array( 'row', 'meta:color' ), array_column( $steps[0]['parts']['parts'], 'part' ), 'Only the key that differs is a part.' );
		self::assertSame( array( 'blue' ), self::meta_a( 'term', $shop_a, 'color' ) );
		self::assertSame( array( 'live-key' ), self::meta_a( 'term', $shop_a, 'api_key' ), 'The credential of this site untouched.' );
		self::assertSame( $size_rows, AB_MCP_IT_Sites::rows( 'a', 'termmeta', array( 'term_id' => (string) $shop_a, 'meta_key' => 'size' ) ), 'The key equal on both sides is untouched, its row as it was.' );
		$back = self::back( (int) $run['run_id'] );
		self::assertSame( 'closed_reverted', $back['state'], json_encode( $back ) );
		self::assertSame( array( 'red' ), self::meta_a( 'term', $shop_a, 'color' ) );
	}

	public function testAnotherWriterOfATermIsRefusedWhileItsStepWrites(): void {
		// The lock of a boundary for terms (decision 19; branch 9): while the
		// step of a category writes it (and while the way back puts it back),
		// another request that saves the term through the REST API gets the
		// message of the boundary (423), and one that writes its meta is
		// refused (the short-circuit filters of term meta); the step itself
		// passes. The classic way of terms (wp_update_term() of another
		// request) has no filter to refuse with (GRENZEN-INHALTE.md).
		self::pair();
		$news_a = self::term_a( 'News', 'category' );
		add_term_meta( $news_a, 'color', 'red' );
		$news_b = self::term_b( 'News', 'category' );
		self::b( array( array( 'op' => 'add_term_meta', 'term' => $news_b, 'key' => 'color', 'value' => 'red' ) ) );
		self::paired( self::staged() );
		self::b( array( array( 'op' => 'update_term', 'term' => $news_b, 'taxonomy' => 'category', 'args' => array( 'description' => 'Fresh.' ) ) ) );
		$run  = self::begun( self::planned( array( 'term:category:news' ) )['args'] );
		$seen = array();
		$hook = static function ( $term_id ) use ( $news_a, &$seen ) {
			if ( (int) $term_id !== $news_a ) {
				return;
			}
			$rest   = AB_MCP_IT_Sites::request( 'a', 'POST', '/wp/v2/categories/' . $news_a, array(), array( 'description' => 'By hand.' ) );
			$meta   = AB_MCP_IT_Sync_Steps::run( 'a', array( array( 'op' => 'update_term_meta', 'term' => $news_a, 'key' => 'color', 'value' => 'green', 'as' => 'm' ) ) );
			$seen[] = array( $rest['status'], $meta['m'] );
		};
		add_action( 'edit_terms', $hook, 10, 1 );
		try {
			$closed = self::finish( (int) $run['run_id'] );
			self::assertSame( 'closed_done', $closed['state'], json_encode( $closed ) );
			self::assertSame( array( array( 423, false ) ), $seen, 'Both refused while the step writes.' );
			self::assertSame( 'Fresh.', self::term_row_a( $news_a )['description'] );
			self::assertSame( array( 'red' ), self::meta_a( 'term', $news_a, 'color' ) );
			$back = self::back( (int) $run['run_id'] );
			self::assertSame( 'closed_reverted', $back['state'], json_encode( $back ) );
			self::assertSame( array( array( 423, false ), array( 423, false ) ), $seen, 'Both refused while the way back puts it back.' );
		} finally {
			remove_action( 'edit_terms', $hook, 10 );
		}
		self::assertSame( '', self::term_row_a( $news_a )['description'] );
		self::assertSame( array( 'red' ), self::meta_a( 'term', $news_a, 'color' ) );
		// Once no boundary holds, the other writer passes.
		self::assertSame( 200, AB_MCP_IT_Sites::request( 'a', 'POST', '/wp/v2/categories/' . $news_a, array(), array( 'description' => 'By hand.' ) )['status'] );
	}

	public function testAnObjectWithTheNameOfACredentialNeverLandsInAPackageOfObjects(): void {
		// B9 (decision 9) in the package of objects (the finding of the lead on
		// class-sync-export.php:437; branch 9). An option is named as a
		// credential as a whole: since branch 11 options go into a package of
		// objects, and one with the name of a credential is left out of it,
		// counted (credentials.names), its name and its value nowhere in it.
		// The kinds whose units can be (posts, terms) know B9 by their units:
		// a meta row whose key is the name of a credential is neither in the
		// form nor in the content of the line, and its value goes nowhere
		// into the package.
		self::pair();
		self::paired( self::staged() );
		$option = self::b(
			array(
				array( 'op' => 'update_option', 'name' => 'stripe_secret_key', 'value' => 'sk_live_51option' ),
				array(
					'op'          => 'export',
					'purpose'     => 'objects',
					'report_hash' => str_repeat( 'a', 64 ),
					'refs'        => array( array( 'kind' => 'option', 'ref' => 'stripe_secret_key' ) ),
					'as'          => 'package',
				),
			)
		)['package'];
		self::assertSame( 1, $option['credentials']['names'], (string) json_encode( $option['credentials'] ) );
		self::assertStringNotContainsString( 'sk_live_51option', (string) json_encode( $option ), 'No value of a credential in the package.' );
		self::assertStringNotContainsString( 'stripe_secret_key', (string) json_encode( $option ), 'Nor its name.' );
		self::clear_b();
		$post = self::on_b( self::page( 'pay', 'Pay here.', array( 'meta_input' => array( 'api_key' => 'sk_live_51postmeta', 'note' => 'x' ) ) ) );
		$term = self::term_b( 'Vault', 'category' );
		self::b( array( array( 'op' => 'add_term_meta', 'term' => $term, 'key' => 'api_key', 'value' => 'sk_live_51termmeta' ) ) );
		self::clear_b();
		$package = self::b(
			array(
				array(
					'op'          => 'export',
					'purpose'     => 'objects',
					'report_hash' => str_repeat( 'a', 64 ),
					'refs'        => array(
						array( 'kind' => 'post', 'ref' => (string) $post ),
						array( 'kind' => 'term', 'ref' => (string) $term ),
					),
					'as'          => 'package',
				),
			)
		)['package'];
		$taken = AB_MCP_Sync_Import::taken( self::import_package( $package, 'objects', str_repeat( 'a', 64 ) ), 'objects' );
		self::assertIsArray( $taken, is_wp_error( $taken ) ? $taken->get_error_message() : '' );
		self::assertCount( 2, $taken['lines'] );
		foreach ( $taken['lines'] as $line ) {
			self::assertSame( 'z8', $line['status'], 'With the status of the inventory, as the inventory gives it.' );
			self::assertArrayHasKey( 'hash', $line, 'Not held: the object goes, without its credential.' );
			self::assertNotContains( 'api_key', array_column( $line['content']['meta'], 0 ), $line['kind'] );
			self::assertNotContains( 'api_key', array_column( $line['form']['meta'], 0 ), $line['kind'] );
		}
		$all = json_encode( $taken );
		foreach ( array( 'sk_live_51option', 'sk_live_51postmeta', 'sk_live_51termmeta' ) as $secret ) {
			self::assertStringNotContainsString( $secret, (string) $all, 'No value of a credential in the package.' );
		}
	}

	/* ------------------------------------------------- the field rule */

	public function testATakeOverOfAPageDeletesNoRevisionOfItWhereTheSiteKeepsANumberOfThem(): void {
		// Branch 11 (the lead after Codex round 1, as A3 for the CSS): where
		// this site keeps a number of revisions (one here, as
		// WP_POST_REVISIONS = 1 would), wp_save_post_revision() deletes the
		// oldest as a post is saved. The page keeps every revision it has,
		// forward and on the way back (nothing is deleted in Z8).
		self::pair();
		$about_a = self::on_a( self::page( 'about', 'About us.' ) );
		wp_update_post( array( 'ID' => $about_a, 'post_content' => 'About us, once.' ) );
		wp_update_post( array( 'ID' => $about_a, 'post_content' => 'About us.' ) );
		self::on_b( self::page( 'about', 'About us.' ) );
		self::paired( self::staged() );
		$b_id = (int) self::entry( self::report( self::staged() )['all'], 'post:about' )['staging']['ref'];
		self::b( array( array( 'op' => 'update_post', 'args' => array( 'ID' => $b_id, 'post_content' => 'About us, new.' ) ) ) );
		$revisions = array_keys( wp_get_post_revisions( $about_a ) );
		sort( $revisions );
		self::assertGreaterThan( 1, count( $revisions ) );
		add_filter( 'wp_revisions_to_keep', static fn( $num, $post ) => 'page' === $post->post_type ? 1 : $num, 10, 2 );
		$kept = static function () use ( $revisions ): array {
			global $wpdb;
			return array_map( 'intval', $wpdb->get_col( 'SELECT ID FROM ' . $wpdb->posts . ' WHERE ID IN (' . implode( ',', array_map( 'intval', $revisions ) ) . ') ORDER BY ID' ) );
		};
		$run    = self::begun( self::planned( array( 'post:about' ) )['args'] );
		$closed = self::finish( (int) $run['run_id'] );
		self::assertSame( 'closed_done', $closed['state'], json_encode( $closed ) );
		self::assertSame( 'About us, new.', get_post( $about_a )->post_content );
		self::assertSame( $revisions, $kept(), 'No revision of the page was deleted.' );
		$back = self::back( (int) $run['run_id'] );
		self::assertSame( 'closed_reverted', $back['state'], json_encode( $back ) );
		self::assertSame( $revisions, $kept(), 'Nor on the way back.' );
	}

	public function testAPluginKeyEqualOnBothSidesStaysUntouchedAndOneThatDiffersWaitsForARuleOfTheSite(): void {
		// Decision 28 (branch 9): a protected key of a plugin (_seo_title)
		// equal on both sides is no part of the step: the page goes over, the
		// row of the key untouched (before, any such key locked the page). A
		// value that differs: map_meta_cap refuses the protected key, the page
		// is locked before anything is written (*:meta_without_rule). With a
		// rule of the site that gives the right (auth_post_meta__seo_title),
		// the key goes over and back.
		self::pair();
		$about_a = self::on_a( self::page( 'about', 'About us.', array( 'meta_input' => array( '_seo_title' => 'About', 'note' => 'a' ) ) ) );
		self::on_b( self::page( 'about', 'About us.', array( 'meta_input' => array( '_seo_title' => 'About', 'note' => 'a' ) ) ) );
		self::paired( self::staged() );
		$b_id = (int) self::entry( self::report( self::staged() )['all'], 'post:about' )['staging']['ref'];
		self::b( array( array( 'op' => 'update_post', 'args' => array( 'ID' => $b_id, 'post_content' => 'About us, new.' ) ), array( 'op' => 'update_meta', 'post' => $b_id, 'key' => 'note', 'value' => 'b' ) ) );
		$seo_rows = AB_MCP_IT_Sites::rows( 'a', 'postmeta', array( 'post_id' => (string) $about_a, 'meta_key' => '_seo_title' ) );
		$run      = self::begun( self::planned( array( 'post:about' ) )['args'] );
		$closed   = self::finish( (int) $run['run_id'] );
		self::assertSame( 'closed_done', $closed['state'], json_encode( $closed ) );
		self::assertSame( array( 'row', 'meta:note' ), array_column( self::writes( self::run_status( (int) $run['run_id'] ) )[0]['parts']['parts'], 'part' ) );
		self::assertSame( array( 'b' ), self::meta_a( 'post', $about_a, 'note' ) );
		self::assertSame( $seo_rows, AB_MCP_IT_Sites::rows( 'a', 'postmeta', array( 'post_id' => (string) $about_a, 'meta_key' => '_seo_title' ) ), 'Untouched.' );
		// The plugin key changed on staging: locked before the first write.
		self::b( array( array( 'op' => 'update_meta', 'post' => $b_id, 'key' => '_seo_title', 'value' => 'About, new' ) ) );
		$locked = self::begun( self::planned( array( 'post:about' ) )['args'] );
		$closed = self::finish( (int) $locked['run_id'] );
		self::assertSame( 'closed_aborted', $closed['state'], json_encode( $closed ) );
		self::assertStringContainsString( '*:meta_without_rule', self::check_message( self::run_status( (int) $locked['run_id'] ) ) );
		self::assertSame( array( 'About' ), self::meta_a( 'post', $about_a, '_seo_title' ), 'Nothing written.' );
		// A rule of this site gives the right: the key goes over and back.
		add_filter( 'auth_post_meta__seo_title', '__return_true' );
		$run    = self::begun( self::planned( array( 'post:about' ) )['args'] );
		$closed = self::finish( (int) $run['run_id'] );
		self::assertSame( 'closed_done', $closed['state'], json_encode( $closed ) );
		self::assertSame( array( 'About, new' ), self::meta_a( 'post', $about_a, '_seo_title' ) );
		$back = self::back( (int) $run['run_id'] );
		self::assertSame( 'closed_reverted', $back['state'], json_encode( $back ) );
		self::assertSame( array( 'About' ), self::meta_a( 'post', $about_a, '_seo_title' ) );
	}

	public function testAHookThatChangesAKeyEqualOnBothSidesWithTheRowDeviatesTheObjectAndTheWayBackLeavesIt(): void {
		// Codex round 1 on branch 9, A1: the note is equal on both sides, so
		// no part of the step (decision 28); a save_post hook of this site
		// changes it as the row is written. The request ends in the call of
		// the next part (the color), where it gets there. Before, only the
		// parts of the journal were compared around the row call: the row was
		// saved as the step's own, and the way back of the interrupted run
		// put the title back over an object another hand changed within the
		// step (decision A). Now the whole state read before and after the
		// row call is compared: the object deviates at the row, before the
		// next call, and the way back leaves everything.
		self::pair();
		$about_a = self::on_a( self::page( 'about', 'About us.', array( 'meta_input' => array( 'note' => 'same', 'color' => 'red' ) ) ) );
		self::on_b( self::page( 'about', 'About us.', array( 'meta_input' => array( 'note' => 'same', 'color' => 'red' ) ) ) );
		self::paired( self::staged() );
		$b_id = (int) self::entry( self::report( self::staged() )['all'], 'post:about' )['staging']['ref'];
		self::b( array( array( 'op' => 'update_post', 'args' => array( 'ID' => $b_id, 'post_title' => 'About us, new' ) ), array( 'op' => 'update_meta', 'post' => $b_id, 'key' => 'color', 'value' => 'blue' ) ) );
		$run    = self::begun( self::planned( array( 'post:about' ) )['args'] );
		$run_id = (int) $run['run_id'];
		$check  = self::gated( 'wp_site_push', array( 'action' => 'next', 'run_id' => $run_id ) );
		self::assertIsArray( $check, is_wp_error( $check ) ? $check->get_error_message() : '' );
		self::assertSame( 'done', $check['step']['state'], json_encode( $check ) );
		try {
			AB_MCP_IT_Sync_Steps::run(
				'a',
				array(
					array(
						'op'        => 'push',
						'args'      => array( 'action' => 'next', 'run_id' => $run_id ),
						'exit_at'   => 'updated_post_meta',
						'exit_key'  => 'color',
						'hook_meta' => array( 'post' => $about_a, 'key' => 'note', 'value' => 'hooked' ),
					),
				)
			);
		} catch ( RuntimeException $e ) {
			// The request ended in the call of the color (the build before).
			self::assertStringContainsString( 'gave no JSON', $e->getMessage() );
		}
		AB_MCP_Protected_Store::forget();
		AB_MCP_Sync_Backup::forget();
		wp_cache_flush();
		self::assertSame( array( 'hooked' ), self::meta_a( 'post', $about_a, 'note' ), 'The hook changed the note with the row.' );
		self::assertSame( 'About us, new', AB_MCP_IT_Sites::rows( 'a', 'posts', array( 'ID' => (string) $about_a ) )[0]['post_title'] );
		if ( 'interrupted' !== self::run_status( $run_id )['state'] ) {
			self::finish( $run_id );
		}
		$back = self::back( $run_id );
		self::assertSame( 'About us, new', AB_MCP_IT_Sites::rows( 'a', 'posts', array( 'ID' => (string) $about_a ) )[0]['post_title'], 'The way back leaves the row of the deviated object: ' . json_encode( $back ) );
		self::assertSame( array( 'hooked' ), self::meta_a( 'post', $about_a, 'note' ), 'The note stays as the hook set it.' );
		self::assertSame( array( 'red' ), self::meta_a( 'post', $about_a, 'color' ), 'The color was never written.' );
		$write = self::writes( self::run_status( $run_id ) )[0];
		self::assertSame( 'row', $write['parts']['object']['deviated']['part'], json_encode( $write ) );
		self::assertStringContainsString( 'changed the part meta:note besides it', $write['parts']['object']['deviated']['why'] );
	}

	public function testAPageBuilderKeyGoesBackOnlyWithTheRightToStoreUnfilteredHtml(): void {
		// GitHub Codex round 1 on free#138 (P2): a key of a page builder
		// (ab_mcp_builder_markup_meta_keys) is written forward only with
		// unfiltered_html (precheck()). The way back wrote it raw
		// (restore_meta()) with the post and meta rights alone, also where the
		// account had lost unfiltered_html since the run wrote
		// (DISALLOW_UNFILTERED_HTML). Now restore_right() asks it as forward:
		// without it the part is left with its value before and named; with
		// it the key goes back as before.
		$markup = static fn( $keys ) => array_merge( (array) $keys, array( 'hero_markup' ) );
		add_filter( 'ab_mcp_builder_markup_meta_keys', $markup );
		self::pair();
		$team_a  = self::on_a( self::page( 'team', 'Our team.', array( 'meta_input' => array( 'hero_markup' => 'grid one' ) ) ) );
		$about_a = self::on_a( self::page( 'about', 'About us.', array( 'meta_input' => array( 'hero_markup' => 'grid one' ) ) ) );
		self::on_b( self::page( 'team', 'Our team.', array( 'meta_input' => array( 'hero_markup' => 'grid one' ) ) ) );
		self::on_b( self::page( 'about', 'About us.', array( 'meta_input' => array( 'hero_markup' => 'grid one' ) ) ) );
		self::paired( self::staged() );
		$entries = self::report( self::staged() )['all'];
		foreach ( array( 'team', 'about' ) as $name ) {
			$b_id = (int) self::entry( $entries, 'post:' . $name )['staging']['ref'];
			self::b( array( array( 'op' => 'update_meta', 'post' => $b_id, 'key' => 'hero_markup', 'value' => 'grid two' ) ) );
		}
		// The ordinary way back, with the right: the key goes back.
		$run = self::begun( self::planned( array( 'post:team' ) )['args'] );
		self::assertSame( 'closed_done', self::finish( (int) $run['run_id'] )['state'] );
		self::assertSame( array( 'grid two' ), self::meta_a( 'post', $team_a, 'hero_markup' ) );
		$back = self::back( (int) $run['run_id'] );
		self::assertSame( 'closed_reverted', $back['state'], json_encode( $back ) );
		self::assertSame( array( 'grid one' ), self::meta_a( 'post', $team_a, 'hero_markup' ) );
		// The right taken away between the run and its way back.
		$run = self::begun( self::planned( array( 'post:about' ) )['args'] );
		self::assertSame( 'closed_done', self::finish( (int) $run['run_id'] )['state'] );
		self::assertSame( array( 'grid two' ), self::meta_a( 'post', $about_a, 'hero_markup' ) );
		$deny = static fn( $caps, $cap ) => 'unfiltered_html' === $cap ? array( 'do_not_allow' ) : $caps;
		add_filter( 'map_meta_cap', $deny, 10, 2 );
		$back = self::back( (int) $run['run_id'] );
		remove_filter( 'map_meta_cap', $deny, 10 );
		remove_filter( 'ab_mcp_builder_markup_meta_keys', $markup );
		self::assertSame( 'closed_findings', $back['state'], json_encode( $back ) );
		$parts = array();
		foreach ( $back['steps'] as $step ) {
			if ( 'revert' === $step['op'] ) {
				$parts = array_column( $step['result']['parts'], null, 'part' );
			}
		}
		self::assertSame( 'left', $parts['meta:hero_markup']['state'], json_encode( $parts ) );
		self::assertStringContainsString( 'write the page-builder meta key hero_markup of the post (unfiltered_html)', $parts['meta:hero_markup']['note'] );
		self::assertSame( '"grid one"', $parts['meta:hero_markup']['value_before'] );
		self::assertSame( array( 'grid two' ), self::meta_a( 'post', $about_a, 'hero_markup' ), 'Nothing written without the right.' );
	}

	public function testANewPostWithAPlainKeyGoesAndOneWhoseRightTheNewPostRefusesStaysAsARest(): void {
		// Decision 28: a new post takes the keys whose right follows the right
		// at the post itself (branch 9: before, any meta locked a new post).
		// The right is asked at the post right before each call; where the
		// new post refuses it (a rule of the site, through map_meta_cap), the
		// call is not made, the object deviates and stays as a rest, its
		// pair residual (decision 28: a failure only after the object was
		// made).
		self::pair();
		self::paired( self::staged() );
		self::on_b( self::page( 'offer', 'An offer.', array( 'meta_input' => array( 'note' => 'x' ) ) ) );
		$run    = self::begun( self::planned( array( 'post:offer' ) )['args'] );
		$closed = self::finish( (int) $run['run_id'] );
		self::assertSame( 'closed_done', $closed['state'], json_encode( $closed ) );
		$offer = get_page_by_path( 'offer' );
		self::assertSame( array( 'x' ), self::meta_a( 'post', (int) $offer->ID, 'note' ) );
		// The right refused at the new post.
		self::on_b( self::page( 'deal', 'A deal.', array( 'meta_input' => array( 'note' => 'y' ) ) ) );
		add_filter(
			'map_meta_cap',
			static function ( $caps, $cap, $user_id, $args ) {
				return 'add_post_meta' === $cap && 'note' === ( $args[1] ?? null ) ? array( 'do_not_allow' ) : $caps;
			},
			10,
			4
		);
		$run    = self::begun( self::planned( array( 'post:deal' ) )['args'] );
		$closed = self::finish( (int) $run['run_id'] );
		self::assertSame( 'closed_findings', $closed['state'], json_encode( $closed ) );
		$step = self::writes( self::run_status( (int) $run['run_id'] ) )[0];
		self::assertSame( 'unclear', $step['state'], json_encode( $step ) );
		self::assertStringContainsString( 'add_post_meta refused', (string) json_encode( $step['parts'] ) );
		$deal = get_page_by_path( 'deal' );
		self::assertIsObject( $deal, 'The page was made and stays.' );
		self::assertSame( array(), self::meta_a( 'post', (int) $deal->ID, 'note' ) );
		self::assertSame( (string) $deal->ID, $step['result']['rest']['live_ref'], 'Named as a rest.' );
		self::assertSame( 'residual', AB_MCP_Sync_Pairs::find_live( AB_MCP_Sync_Anchor::active()['source_id'], 'post', (string) $deal->ID )['state'] );
	}

	/* ------------------------------------------------------- the slug */

	public function testANewPageWhoseSlugAPostInTheTrashHoldsIsLockedBeforeAnythingIsWritten(): void {
		// The slug (branch 9): wp_insert_post() would rename a post in the
		// trash with the slug (__trashed), a row of another post; the check
		// before the first write locks the new page instead
		// (post:slug_collision, with the post in the trash).
		self::pair();
		$trashed = self::on_a( self::page( 'promo', 'Old promo.', array( 'post_type' => 'post', 'post_status' => 'trash' ) ) );
		self::assertSame( 'promo', get_post_field( 'post_name', $trashed, 'raw' ) );
		self::paired( self::staged() );
		self::on_b( self::page( 'promo', 'The promo.' ) );
		$run    = self::begun( self::planned( array( 'post:promo' ) )['args'] );
		$closed = self::finish( (int) $run['run_id'] );
		self::assertSame( 'closed_aborted', $closed['state'], json_encode( $closed ) );
		$message = self::check_message( self::run_status( (int) $run['run_id'] ) );
		self::assertStringContainsString( 'post:slug_collision', $message );
		self::assertStringContainsString( sprintf( 'The post %d of this site lies in the trash', $trashed ), $message );
		self::assertSame( 'promo', AB_MCP_IT_Sites::rows( 'a', 'posts', array( 'ID' => (string) $trashed ) )[0]['post_name'], 'The post in the trash keeps its slug.' );
		self::assertNull( get_page_by_path( 'promo' ), 'No page made.' );
	}

	/* ----------------------------------------------- the builder lock */

	public function testAWpBakeryPageIsLockedAndAKadencePageGoesOver(): void {
		// Decision 31: a page of a builder that is not released is locked in
		// the report (post:builder, Z8c) and no plan writes it; a page of a
		// released block library (Kadence) goes over.
		self::pair();
		self::paired( self::staged() );
		self::on_b( self::page( 'bakery', '[vc_row][vc_column]Hi[/vc_column][/vc_row]', array( 'meta_input' => array( '_wpb_vc_js_status' => 'true' ) ) ) );
		self::on_b( self::page( 'kadence', '<!-- wp:kadence/rowlayout {"uniqueID":"_a1"} --><div class="kb-row-layout-wrap">Hi</div><!-- /wp:kadence/rowlayout -->' ) );
		$planned = self::planned( array( 'post:kadence' ) );
		$bakery  = self::entry( $planned['report']['all'], 'post:bakery' );
		self::assertSame( 'locked', $bakery['action'], json_encode( $bakery ) );
		self::assertSame( 'post:builder', $bakery['locks'][0]['key'] );
		$refused = self::gated( 'wp_site_push', array_merge( $planned['args'], array( 'select' => array( (int) $bakery['n'] ) ) ) );
		self::assertInstanceOf( \WP_Error::class, $refused, 'A plan of the locked page writes nothing.' );
		$run    = self::begun( $planned['args'] );
		$closed = self::finish( (int) $run['run_id'] );
		self::assertSame( 'closed_done', $closed['state'], json_encode( $closed ) );
		$kadence = get_page_by_path( 'kadence' );
		self::assertIsObject( $kadence );
		self::assertStringContainsString( '<!-- wp:kadence/rowlayout', (string) get_post_field( 'post_content', $kadence->ID, 'raw' ) );
		self::assertNull( get_page_by_path( 'bakery' ) );
	}
}

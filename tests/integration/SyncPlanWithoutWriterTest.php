<?php
/**
 * The rules of N-c of the seam without the registration of a kind (first
 * step T3, I6; D6 of the build sheet): what the core does where the writer
 * of a kind is not registered, here only (after the move, a kind this plugin
 * no longer has). One case for each function of N-c, on two WordPress sites
 * as tests/integration/SyncContentTest.php (B the staging site, A this
 * process):
 *
 * - (a) planned_parents(): a run that updates a term, then term no longer
 *   registered. The check before the first write asks the writer of the
 *   term for its rule planned_parent() before its loop and finds none; the
 *   loop then finds «No step of this version writes the kind term.», the run
 *   is aborted, nothing is written, no error. planned_parents() itself, with
 *   the entry of the run, gives the parent of the term with its writer and
 *   nothing without it.
 * - (b) plan(): a new post and a new attachment under it (its weak parent),
 *   attachment not registered, all=true: the attachment is named as locked
 *   with «The kind attachment is not written by a run of this version.», not
 *   refused; the post is planned alone, without a parent step and without
 *   parent_later, and the reserve of the backup counts it alone (the bytes of
 *   the plan of the post alone).
 * - (c) order() and (d) term_depths(): a new category, a new page and a new
 *   post, term not registered: the category is locked, the page and the post
 *   keep the order they have beside it with every writer; no depth is set and
 *   plan() ends without an error (the same report). term_depths() itself,
 *   before plan() removes the depths, gives the depth of the term with its
 *   writer and nothing without it, the term among its items.
 * - (e) I1, plan() with post alone, by its subtypes post and page (1a
 *   :775–:777): one entry of each other kind (term, attachment, menu,
 *   menu_item, widget_area, custom_css, option) and of each other type of
 *   post (wp_block, wp_template, wp_template_part, wp_global_styles,
 *   wp_navigation) beside a new post. Each is locked with «The kind … is
 *   not written by a run of this version.» (the types as post), the post
 *   alone is planned, plan() and the preview give no error.
 * - (f) I1, boundary(): a run that updates a term, planned, confirmed and
 *   checked with every writer, then post alone registered. The step of the
 *   term fails with «No step of this version writes the kind term.», the
 *   term stays as it was, no error. How the run ends is not asserted (R1).
 * - (g) run_post(), C1: the same run checked and written with every writer,
 *   then term no longer registered. Phase 7 takes the written term as before
 *   the first step of T6 (head c95eda5): by the key post with the journal of
 *   a post, so the writer of post empties it; its step is done, no error.
 *   How the run ends is not asserted (R1).
 *
 * The registrations made as the engine loads are kept before each case and
 * put back after it (reflection), for the other tests of the process.
 *
 * R1 open (the seam, first step): where a case here asserts that a run
 * is closed or refused because a kind has no writer or the code has no
 * entrance, it describes the state of the first step, not what must hold
 * once the licence of the plugin that registered a kind ends. What
 * follows from R1 is open: once the licence is renewed and the kind is
 * registered again, a run left standing must go on or be taken back, and
 * its backups must not expire until then.
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
use AB_MCP_Sync_Engine;
use AB_MCP_Sync_Guard;
use AB_MCP_Sync_Import;
use AB_MCP_Sync_Push;
use AB_MCP_Sync_Report;
use AB_MCP_Sync_Run;
use AB_MCP_Sync_Schema;
use AB_MCP_Sync_Writers;
use AB_MCP_Tool_Registry;
use AB_MCP_Tools_Staging;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;

require_once AB_MCP_DIR . 'includes/sync/class-sync-import.php';
require_once AB_MCP_DIR . 'includes/sync/class-sync-pairs-tool.php';
require_once AB_MCP_DIR . 'includes/sync/class-sync-report.php';
require_once AB_MCP_DIR . 'includes/sync/class-sync-push.php';
require_once AB_MCP_DIR . 'includes/tools/class-tools-staging.php';

final class SyncPlanWithoutWriterTest extends TestCase {

	/** The uploads of this class on site B (case (b)), under its WordPress folder. */
	private const UPLOADS = 'wp-content/uploads-ohne-schreiber';

	/** The date every object of these tests carries. */
	private const DATE = '2026-10-01 10:00:00';

	/** The kinds of the engine but post (I1). */
	private const OTHER_KINDS = array( 'term', 'attachment', 'menu', 'menu_item', 'widget_area', 'custom_css', 'option' );

	/** The types of post the engine writes but post and page (I1). */
	private const OTHER_TYPES = array( 'wp_block', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation' );

	/** @var array|null The table of the writers as the engine registered it. */
	private $kept = null;

	/** @var string|null DOCUMENT_ROOT before the case. */
	private $docroot_before;

	protected function setUp(): void {
		AB_MCP_IT_Sites::reset( 'a' );
		AB_MCP_IT_Sites::reset( 'b' );
		self::wipe();
		$this->docroot_before     = $_SERVER['DOCUMENT_ROOT'] ?? null;
		$_SERVER['DOCUMENT_ROOT'] = AB_MCP_IT_Sites::docroot( 'a' );
		AB_MCP_Protected_Store::forget();
		AB_MCP_Sync_Schema::forget();
		AB_MCP_Sync_Guard::disarm();
		delete_option( AB_MCP_Sync_Guard::OPTION );
		delete_option( \AB_MCP_Change_Lock::OPTION );
		wp_set_current_user( self::admin() );
		self::assertIsArray( AB_MCP_Sync_Schema::install() );
		$this->kept = ( new ReflectionProperty( AB_MCP_Sync_Writers::class, 'writers' ) )->getValue();
	}

	protected function tearDown(): void {
		( new ReflectionProperty( AB_MCP_Sync_Writers::class, 'writers' ) )->setValue( null, $this->kept );
		if ( null === $this->docroot_before ) {
			unset( $_SERVER['DOCUMENT_ROOT'] );
		} else {
			$_SERVER['DOCUMENT_ROOT'] = $this->docroot_before;
		}
		AB_MCP_Protected_Store::forget();
		AB_MCP_Sync_Guard::disarm();
		delete_option( \AB_MCP_Change_Lock::OPTION );
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

	/** The protected store of both sites and the uploads of this class on B removed. */
	private static function wipe(): void {
		$root = (string) AB_MCP_IT_Sites::state()['root'];
		self::remove_tree( $root . '/ab-mcp-store', $root );
		self::remove_tree( AB_MCP_IT_Sites::docroot( 'b' ) . '/' . self::UPLOADS, $root );
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

	/** One step on B that makes an object: its id. */
	private static function on_b( array $step ): int {
		return (int) self::b( array( $step + array( 'as' => 'id' ) ) )['id'];
	}

	/** A post or page of B, published. */
	private static function post_b( string $type, string $name ): int {
		$args = array(
			'post_type'     => $type,
			'post_status'   => 'publish',
			'post_title'    => ucfirst( $name ),
			'post_name'     => $name,
			'post_content'  => 'Text.',
			'post_author'   => 1,
			'post_date'     => self::DATE,
			'post_date_gmt' => self::DATE,
		);
		return self::on_b( array( 'op' => 'insert_post', 'args' => $args ) );
	}

	/** B packs its inventory and A takes it over: the package ids. */
	private static function staged(): array {
		self::b( array( array( 'op' => 'store_cleanup', 'now' => time() + 2 * DAY_IN_SECONDS ) ) );
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

	/** A tool of the staging group through the gate of the plugin, in this process. */
	private static function gated( string $name, array $a ) {
		$r = new AB_MCP_Tool_Registry();
		AB_MCP_Tools_Staging::register( $r );
		return AB_MCP_REST_Controller::run_tool( $name, $r->get( $name ), $a );
	}

	/** Every equal entry of a fresh proposal paired. */
	private static function paired( array $ids ): void {
		$answer = self::gated( 'wp_site_pairs', array( 'action' => 'propose', 'packages' => $ids ) );
		while ( is_array( $answer ) && false === $answer['done'] ) {
			$answer = self::gated( 'wp_site_pairs', array( 'action' => 'propose', 'proposal_id' => $answer['proposal_id'] ) );
		}
		self::assertIsArray( $answer, is_wp_error( $answer ) ? $answer->get_error_message() : '' );
		$args    = array(
			'action'      => 'confirm',
			'proposal_id' => $answer['proposal_id'],
			'categories'  => array( 'equal' ),
		);
		$preview = self::gated( 'wp_site_pairs', $args );
		self::assertIsArray( $preview, is_wp_error( $preview ) ? $preview->get_error_message() : '' );
		$done = self::gated( 'wp_site_pairs', $args + array( 'confirm_token' => $preview['confirm_token'] ) );
		self::assertIsArray( $done, is_wp_error( $done ) ? $done->get_error_message() : '' );
	}

	/** wp_site_diff in this process, worked out to its end and paged: every entry in all. */
	private static function report( array $ids ): array {
		$answer = AB_MCP_Sync_Report::call( array( 'packages' => $ids ) );
		for ( $calls = 1; is_array( $answer ) && false === $answer['done'] && $calls < 300; $calls++ ) {
			$answer = AB_MCP_Sync_Report::call( array( 'report_id' => $answer['report_id'] ) );
		}
		self::assertIsArray( $answer, is_wp_error( $answer ) ? $answer->get_error_message() : '' );
		self::assertTrue( $answer['done'] );
		$all  = $answer['entries'];
		$page = $answer;
		for ( $pages = 0; null !== $page['cursor'] && $pages < 300; $pages++ ) {
			$page = AB_MCP_Sync_Report::call( array( 'report_id' => $answer['report_id'], 'cursor' => $page['cursor'] ) );
			self::assertIsArray( $page );
			$all = array_merge( $all, $page['entries'] );
		}
		$answer['all'] = $all;
		return $answer;
	}

	/** The entry of an object of this kind and subtype by the name of its staging side (slug, or the file of an attachment). */
	private static function entry( array $report, string $kind, string $subtype, string $name ): array {
		foreach ( $report['all'] as $entry ) {
			$identity = $entry['staging']['identity'] ?? array();
			if ( $kind === $entry['kind'] && $subtype === $entry['subtype'] && in_array( $name, array( $identity['post_name'] ?? null, $identity['slug'] ?? null, $identity['file'] ?? null ), true ) ) {
				return $entry;
			}
		}
		self::fail( "No entry of the $kind $subtype $name in the report." );
	}

	/** The arguments of a plan of these entries of the report, with the package of their objects. */
	private static function args( array $report, array $entries ): array {
		$refs = array();
		foreach ( $entries as $entry ) {
			$refs[] = array(
				'kind' => $entry['kind'],
				'ref'  => (string) $entry['staging']['ref'],
			);
		}
		self::b( array( array( 'op' => 'store_cleanup', 'now' => time() + 2 * DAY_IN_SECONDS ) ) );
		$package = self::b(
			array(
				array(
					'op'          => 'export',
					'purpose'     => 'objects',
					'report_hash' => $report['report_hash'],
					'refs'        => $refs,
					'as'          => 'package',
				),
			)
		)['package'];
		return array(
			'action'    => 'plan',
			'report_id' => $report['report_id'],
			'select'    => array_map( static fn( $e ) => (int) $e['n'], $entries ),
			'packages'  => array( self::import_package( $package, 'objects', $report['report_hash'] ) ),
		);
	}

	/** The writers of this plugin registered again, all but the kind $kind (tearDown() puts the table back). */
	private static function without( string $kind ): void {
		AB_MCP_Sync_Writers::reset();
		foreach ( AB_MCP_Sync_Engine::WRITERS as $other => $class ) {
			if ( $kind !== $other ) {
				self::assertTrue( AB_MCP_Sync_Writers::register( $other, AB_MCP_Sync_Writers::WHOLE, $class ), $other );
			}
		}
		self::assertNull( AB_MCP_Sync_Engine::writer( $kind, '' ) );
	}

	/** Post alone registered, by its subtypes post and page, as U1 allows it (tearDown() puts the table back). */
	private static function only_post(): void {
		AB_MCP_Sync_Writers::reset();
		self::assertTrue( AB_MCP_Sync_Writers::register( 'post', array( 'post', 'page' ), AB_MCP_Sync_Engine::WRITERS['post'] ) );
		self::assertNull( AB_MCP_Sync_Engine::writer( 'post', 'wp_block' ) );
	}

	/**
	 * One object of each other kind and type on B for a takeover, and a new
	 * post: the description, a text widget and the additional CSS equal on
	 * both sites and paired, then changed on B (a new additional CSS is
	 * locked in the report already); a category, an image, a menu with a
	 * link, a reusable block, a navigation, the template single, the part
	 * header and the global styles of the theme new on B (both sites run the
	 * same theme).
	 */
	private static function others_on_b(): void {
		$widget               = array(
			2              => array( 'title' => 'Hinweis', 'text' => 'Alt', 'filter' => false, 'visual' => false ),
			'_multiwidget' => 1,
		);
		$areas                = get_option( 'sidebars_widgets' );
		$areas['sidebar-1'][] = 'text-2';
		update_option( 'blogdescription', 'Things' );
		update_option( 'widget_text', $widget );
		update_option( 'sidebars_widgets', $areas );
		wp_update_custom_css_post( 'body { color: #111111; }' );
		self::b(
			array(
				array( 'op' => 'update_option', 'name' => 'blogdescription', 'value' => 'Things' ),
				array( 'op' => 'update_option', 'name' => 'widget_text', 'value' => $widget ),
				array( 'op' => 'update_option', 'name' => 'sidebars_widgets', 'value' => $areas ),
				array( 'op' => 'custom_css', 'css' => 'body { color: #111111; }' ),
			)
		);
		self::paired( self::staged() );
		$widget[2]['text'] = 'Neu';
		$menu              = self::on_b( array( 'op' => 'nav_menu', 'name' => 'Main' ) );
		$link              = array( 'menu-item-title' => 'Shop', 'menu-item-url' => 'https://example.test/shop/', 'menu-item-type' => 'custom', 'menu-item-status' => 'publish', 'menu-item-position' => 1 );
		$para              = array( 'content' => '<!-- wp:paragraph --><p>Neu</p><!-- /wp:paragraph -->' );
		self::b(
			array(
				array( 'op' => 'update_option', 'name' => 'blogdescription', 'value' => 'Other things' ),
				array( 'op' => 'update_option', 'name' => 'widget_text', 'value' => $widget ),
				array( 'op' => 'custom_css', 'css' => 'body { color: #222222; }' ),
				array( 'op' => 'update_option', 'name' => 'upload_path', 'value' => self::UPLOADS ),
				array( 'op' => 'insert_term', 'name' => 'Topics', 'taxonomy' => 'category' ),
				array( 'op' => 'upload', 'name' => 'bild.jpg', 'mime' => 'image/jpeg', 'image' => array( 'w' => 8, 'h' => 8, 'rgb' => array( 10, 80, 160 ) ), 'args' => array( 'post_title' => 'Bild' ) ),
				array( 'op' => 'nav_menu_item', 'menu' => $menu, 'args' => $link ),
				array( 'op' => 'global_styles_id' ),
				array( 'op' => 'rest', 'method' => 'POST', 'route' => '/wp/v2/templates/' . get_stylesheet() . '//single', 'body' => $para ),
				array( 'op' => 'rest', 'method' => 'POST', 'route' => '/wp/v2/template-parts/' . get_stylesheet() . '//header', 'body' => $para ),
			)
		);
		self::post_b( 'wp_block', 'hinweis' );
		self::post_b( 'wp_navigation', 'hauptmenu' );
		self::post_b( 'post', 'tips' );
	}

	/** The entries of the report of another kind or type that a takeover writes (take_over or create). */
	private static function others( array $report ): array {
		$other = static fn( array $e ): bool => in_array( $e['kind'], self::OTHER_KINDS, true ) || ( 'post' === $e['kind'] && in_array( $e['subtype'], self::OTHER_TYPES, true ) );
		return array_values( array_filter( $report['all'], static fn( $e ) => $other( $e ) && in_array( $e['action'], array( 'take_over', 'create' ), true ) ) );
	}

	/** The objects of a plan as kind:staging_ref, in the order of its steps. */
	private static function order_of( array $plan ): array {
		return array_map( static fn( $o ) => $o['kind'] . ':' . $o['staging_ref'], $plan['objects'] );
	}

	/* -------------------------------------------------------------- cases */

	public function testACheckWhoseTermHasNoWriterFindsItWithoutAnErrorAndWritesNothing(): void {
		// (a) planned_parents(), run before the loop of the check, asks the
		// writer of the term for its rule; without a writer it asks none.
		self::pair();
		$news_a = (int) wp_insert_term( 'News', 'category' )['term_id'];
		$news_b = self::on_b( array( 'op' => 'insert_term', 'name' => 'News', 'taxonomy' => 'category' ) );
		self::paired( self::staged() );
		self::b( array( array( 'op' => 'update_term', 'term' => $news_b, 'taxonomy' => 'category', 'args' => array( 'description' => 'Fresh news.' ) ) ) );
		$report  = self::report( self::staged() );
		$args    = self::args( $report, array( self::entry( $report, 'term', 'category', 'news' ) ) );
		$preview = self::gated( 'wp_site_push', $args );
		self::assertIsArray( $preview, is_wp_error( $preview ) ? $preview->get_error_message() : '' );
		$run = self::gated( 'wp_site_push', $args + array( 'confirm_token' => $preview['confirm_token'] ) );
		self::assertIsArray( $run, is_wp_error( $run ) ? $run->get_error_message() : '' );
		$before = AB_MCP_IT_Sites::rows( 'a', 'term_taxonomy', array( 'term_id' => (string) $news_a ) );
		// The rule itself, with the entry of the run (A2 of Astra): the
		// loaded writer of the term must not answer once it is unregistered.
		$stored = AB_MCP_Sync_Run::get( (int) $run['run_id'] );
		$lines  = AB_MCP_Sync_Engine::lines( $stored );
		$ctx    = AB_MCP_Sync_Engine::context( $stored );
		self::assertIsArray( $lines );
		self::assertIsArray( $ctx );
		$parents = new ReflectionMethod( AB_MCP_Sync_Engine::class, 'planned_parents' );
		self::assertSame( array( $news_a => 0 ), $parents->invoke( null, $stored['plan']['objects'], $lines, $ctx ), 'With its writer the parent of the term here (a root).' );
		self::without( 'term' );
		self::assertSame( array(), $parents->invoke( null, $stored['plan']['objects'], $lines, $ctx ), 'Without its writer no rule gives a parent.' );
		$answer = null;
		for ( $i = 0; $i < 6 && ! in_array( $answer['state'] ?? null, AB_MCP_Sync_Schema::CLOSED, true ); $i++ ) {
			$answer = self::gated( 'wp_site_push', array( 'action' => 'next', 'run_id' => (int) $run['run_id'] ) );
			self::assertIsArray( $answer, is_wp_error( $answer ) ? $answer->get_error_message() : '' );
		}
		self::assertSame( 'closed_aborted', $answer['state'], json_encode( $answer ) );
		$status = self::gated( 'wp_site_push', array( 'action' => 'status', 'run_id' => (int) $run['run_id'] ) );
		self::assertIsArray( $status );
		$check = array_values( array_filter( $status['steps'], static fn( $s ) => 'check' === $s['op'] ) )[0];
		self::assertSame( array( 'No step of this version writes the kind term.' ), array_column( $check['result']['findings'], 'message' ) );
		self::assertSame( array(), array_values( array_filter( $status['steps'], static fn( $s ) => 'write' === $s['op'] && 'done' === $s['state'] ) ), 'No write step is done.' );
		self::assertSame( $before, AB_MCP_IT_Sites::rows( 'a', 'term_taxonomy', array( 'term_id' => (string) $news_a ) ), 'The term as it was.' );
	}

	public function testAnAttachmentWithoutAWriterIsNamedAsLockedAndThePostAboveItIsPlannedAlone(): void {
		// (b) plan(): the weak parent (N-c no. 3) and the objects of the
		// space of posts the run creates (no. 2) without the attachment.
		self::pair();
		self::paired( self::staged() );
		self::b( array( array( 'op' => 'update_option', 'name' => 'upload_path', 'value' => self::UPLOADS ) ) );
		$post = self::post_b( 'post', 'tips' );
		self::on_b( array( 'op' => 'upload', 'name' => 'bild.jpg', 'mime' => 'image/jpeg', 'image' => array( 'w' => 8, 'h' => 8, 'rgb' => array( 10, 80, 160 ) ), 'args' => array( 'post_title' => 'Bild', 'post_parent' => $post ) ) );
		$report = self::report( self::staged() );
		$entry  = self::entry( $report, 'post', 'post', 'tips' );
		$bild   = self::entry( $report, 'attachment', 'attachment', '2026/10/bild.jpg' );
		$args   = self::args( $report, array( $entry ) );
		$alone  = AB_MCP_Sync_Push::plan( $args );
		self::assertIsArray( $alone, is_wp_error( $alone ) ? $alone->get_error_message() : '' );
		self::without( 'attachment' );
		$all = AB_MCP_Sync_Push::plan( array( 'all' => true ) + array_diff_key( $args, array( 'select' => true ) ) );
		self::assertIsArray( $all, is_wp_error( $all ) ? $all->get_error_message() : '' );
		self::assertSame( array(), $all['locked'] );
		// all=true names what a takeover does not write (here also two
		// options of the site); of the two entries of this case, the
		// attachment alone.
		$named = array_filter( $all['skipped'], static fn( $l ) => in_array( $l['n'], array( (int) $entry['n'], (int) $bild['n'] ), true ) );
		self::assertSame( array( array( (int) $bild['n'], 'attachment', 'The kind attachment is not written by a run of this version.', 'Z8d' ) ), array_values( array_map( static fn( $l ) => array( $l['n'], $l['kind'], $l['reason'], $l['stage'] ), $named ) ), 'Named, not refused.' );
		self::assertSame( array( 'post:' . $entry['staging']['ref'] ), self::order_of( $all ) );
		self::assertArrayNotHasKey( 'parent_later', $all['objects'][0] );
		// plan() removes refs from every object. A weak parent (no. 3) gives
		// refs or parent_later to the item of an attachment only, which
		// without its writer is no item (skipped above); the parent step and
		// the bytes of the reserve are checked below.
		self::assertArrayNotHasKey( 'refs', $all['objects'][0] );
		self::assertSame( array( 'check', 'write', 'post', 'verify' ), array_column( $all['steps'], 'op' ), 'No parent step.' );
		self::assertSame( $alone['bytes'], $all['bytes'], 'The reserve of the backup counts the post alone.' );
	}

	public function testATermWithoutAWriterIsLockedAndTheOtherObjectsKeepTheirOrderWithoutADepth(): void {
		// (c) order() and (d) term_depths(): the rank of a term (no. 5) and
		// the depths (no. 6 to 8) of a plan that holds no term.
		self::pair();
		self::paired( self::staged() );
		self::on_b( array( 'op' => 'insert_term', 'name' => 'Topics', 'taxonomy' => 'category' ) );
		self::post_b( 'page', 'about' );
		self::post_b( 'post', 'tips' );
		$report  = self::report( self::staged() );
		$topics  = self::entry( $report, 'term', 'category', 'topics' );
		$args    = self::args( $report, array( $topics, self::entry( $report, 'post', 'page', 'about' ), self::entry( $report, 'post', 'post', 'tips' ) ) );
		$every   = AB_MCP_Sync_Push::plan( $args );
		self::assertIsArray( $every, is_wp_error( $every ) ? $every->get_error_message() : '' );
		self::assertSame( 'term:' . $topics['staging']['ref'], self::order_of( $every )[0], 'With its writer the term comes first.' );
		// The rule itself, before plan() removes the depths (A1 of Astra),
		// with the objects of this plan, the term among them.
		$depths = new ReflectionMethod( AB_MCP_Sync_Push::class, 'term_depths' );
		$items  = array_column( $every['objects'], null, 'n' );
		$mapped = static fn( string $staging ): ?int => null;
		self::assertSame( array( (int) $topics['n'] => 0 ), $depths->invoke( null, $items, $mapped ), 'With its writer the depth of the term (a root).' );
		self::without( 'term' );
		self::assertSame( array(), $depths->invoke( null, $items, $mapped ), 'Without its writer no rule gives a depth, not even for the term.' );
		$bare = AB_MCP_Sync_Push::plan( $args );
		self::assertIsArray( $bare, is_wp_error( $bare ) ? $bare->get_error_message() : '' );
		self::assertSame( array( array( (int) $topics['n'], 'term', 'The kind term is not written by a run of this version.', 'Z8d' ) ), array_map( static fn( $l ) => array( $l['n'], $l['kind'], $l['reason'], $l['stage'] ), $bare['locked'] ) );
		self::assertSame( array_slice( self::order_of( $every ), 1 ), self::order_of( $bare ), 'The page and the post in the order they have beside the term.' );
		// plan() removes the depth from every object (as it removes refs),
		// so this line describes the plan and cannot turn red; the depths
		// are checked through term_depths() above.
		self::assertSame( array(), array_filter( array_map( static fn( $o ) => isset( $o['depth'] ), $bare['objects'] ) ), 'No depth.' );
	}

	/**
	 * Prüft: (e) with post alone registered, by its subtypes post and page,
	 * plan() locks one entry of each other kind and of each other type of
	 * post with the sentence of verdict() and the stage Z8d, these twelve
	 * and no other entry (the message names each entry missing or too
	 * many); the post alone is planned, so no object of those kinds and
	 * types is in the plan, and none is written. plan() and the preview give
	 * no error.
	 * No run here: a run with post alone, by its subtypes, is (P3) in
	 * SyncWayBackWithoutWriterTest.
	 * Sonst: after the move a kind or type Free no longer has would stay
	 * writable in Free.
	 */
	public function testEveryOtherKindAndTypeIsLockedInThePlanWhereOnlyPostIsRegisteredByItsSubtypes(): void {
		self::pair();
		self::others_on_b();
		$report = self::report( self::staged() );
		$tips   = self::entry( $report, 'post', 'post', 'tips' );
		$args   = self::args( $report, array_merge( self::others( $report ), array( $tips ) ) );
		self::only_post();
		$plan = AB_MCP_Sync_Push::plan( $args );
		self::assertIsArray( $plan, is_wp_error( $plan ) ? $plan->get_error_message() : '' );
		$preview = self::gated( 'wp_site_push', $args );
		self::assertIsArray( $preview, is_wp_error( $preview ) ? $preview->get_error_message() : '' );
		$locked = array_map( static fn( $l ) => array( $l['kind'], $l['subtype'], $l['reason'], $l['stage'] ), $plan['locked'] );
		sort( $locked );
		$says     = static fn( string $kind ): array => array( "The kind $kind is not written by a run of this version.", 'Z8d' );
		$expected = array(
			array_merge( array( 'attachment', 'attachment' ), $says( 'attachment' ) ),
			array_merge( array( 'custom_css', '' ), $says( 'custom_css' ) ),
			array_merge( array( 'menu', 'nav_menu' ), $says( 'menu' ) ),
			array_merge( array( 'menu_item', '' ), $says( 'menu_item' ) ),
			array_merge( array( 'option', '' ), $says( 'option' ) ),
			array_merge( array( 'post', 'wp_block' ), $says( 'post' ) ),
			array_merge( array( 'post', 'wp_global_styles' ), $says( 'post' ) ),
			array_merge( array( 'post', 'wp_navigation' ), $says( 'post' ) ),
			array_merge( array( 'post', 'wp_template' ), $says( 'post' ) ),
			array_merge( array( 'post', 'wp_template_part' ), $says( 'post' ) ),
			array_merge( array( 'term', 'category' ), $says( 'term' ) ),
			array_merge( array( 'widget_area', '' ), $says( 'widget_area' ) ),
		);
		$rows     = static fn( array $list ): array => array_map( static fn( $row ) => (string) json_encode( $row ), $list );
		$missing  = implode( ' ', array_diff( $rows( $expected ), $rows( $locked ) ) );
		$surplus  = implode( ' ', array_diff( $rows( $locked ), $rows( $expected ) ) );
		self::assertSame( $expected, $locked, "Each other kind and type locked by the registration, and no other entry. Missing: $missing Too many: $surplus" );
		self::assertSame( array( 'post:' . $tips['staging']['ref'] ), self::order_of( $plan ), 'The post alone is planned.' );
	}

	/**
	 * Prüft: (f) a step of a kind whose writer is gone after the check
	 * reaches the write boundary (boundary()), which the lock of the plan
	 * and the check (check_item()) hide otherwise: the step fails with the
	 * sentence the core has for a kind no step writes, the answer is no
	 * error, the term stays as it was. How the run goes on and ends is not
	 * asserted (R1).
	 * Sonst: a step of a kind Free no longer has would be written past the
	 * write boundary, or end with an error because its writer is missing.
	 */
	public function testWithoutAWriterAtTheWriteBoundaryTheStepFailsAndNothingIsWritten(): void {
		self::pair();
		$news_a = (int) wp_insert_term( 'News', 'category' )['term_id'];
		$news_b = self::on_b( array( 'op' => 'insert_term', 'name' => 'News', 'taxonomy' => 'category' ) );
		self::paired( self::staged() );
		self::b( array( array( 'op' => 'update_term', 'term' => $news_b, 'taxonomy' => 'category', 'args' => array( 'description' => 'Fresh news.' ) ) ) );
		$report  = self::report( self::staged() );
		$args    = self::args( $report, array( self::entry( $report, 'term', 'category', 'news' ) ) );
		$preview = self::gated( 'wp_site_push', $args );
		self::assertIsArray( $preview, is_wp_error( $preview ) ? $preview->get_error_message() : '' );
		$run = self::gated( 'wp_site_push', $args + array( 'confirm_token' => $preview['confirm_token'] ) );
		self::assertIsArray( $run, is_wp_error( $run ) ? $run->get_error_message() : '' );
		$next  = static fn() => self::gated( 'wp_site_push', array( 'action' => 'next', 'run_id' => (int) $run['run_id'] ) );
		$check = $next();
		self::assertIsArray( $check, is_wp_error( $check ) ? $check->get_error_message() : '' );
		self::assertSame( array( 'check', 'done' ), array( $check['step']['op'], $check['step']['state'] ), 'The check, with every writer.' );
		$before = AB_MCP_IT_Sites::rows( 'a', 'term_taxonomy', array( 'term_id' => (string) $news_a ) );
		self::only_post();
		$failed = $next();
		self::assertIsArray( $failed, is_wp_error( $failed ) ? $failed->get_error_code() . ': ' . $failed->get_error_message() : '' );
		self::assertSame( array( 'write', 'failed', 'Not written: No step of this version writes the kind term.' ), array( $failed['step']['op'], $failed['step']['state'], $failed['step']['result']['message'] ) );
		self::assertSame( $before, AB_MCP_IT_Sites::rows( 'a', 'term_taxonomy', array( 'term_id' => (string) $news_a ) ), 'The term as it was.' );
	}

	/**
	 * Prüft: (g) C1, phase 7 (run_post()) of a run that wrote a term with
	 * every writer, the writer of the term gone after the write (the other
	 * seven kinds whole): the step post answers without an error and is
	 * done, as at the head c95eda5 (the key post with the journal of a post,
	 * so the writer of post). Phase 8 and the end of the run are not
	 * asserted (R1).
	 * Sonst: phase 7 would keep the key post but ask the journal of the term
	 * for its writer, find none and end with an internal error, each next
	 * again.
	 */
	public function testPhaseSevenOfAWrittenTermWhoseWriterIsGoneIsDoneWithoutAnError(): void {
		self::pair();
		wp_insert_term( 'News', 'category' );
		$news_b = self::on_b( array( 'op' => 'insert_term', 'name' => 'News', 'taxonomy' => 'category' ) );
		self::paired( self::staged() );
		self::b( array( array( 'op' => 'update_term', 'term' => $news_b, 'taxonomy' => 'category', 'args' => array( 'description' => 'Fresh news.' ) ) ) );
		$report  = self::report( self::staged() );
		$args    = self::args( $report, array( self::entry( $report, 'term', 'category', 'news' ) ) );
		$preview = self::gated( 'wp_site_push', $args );
		self::assertIsArray( $preview, is_wp_error( $preview ) ? $preview->get_error_message() : '' );
		$run = self::gated( 'wp_site_push', $args + array( 'confirm_token' => $preview['confirm_token'] ) );
		self::assertIsArray( $run, is_wp_error( $run ) ? $run->get_error_message() : '' );
		$next = static fn() => self::gated( 'wp_site_push', array( 'action' => 'next', 'run_id' => (int) $run['run_id'] ) );
		foreach ( array( 'check', 'write' ) as $op ) {
			$answer = $next();
			self::assertIsArray( $answer, is_wp_error( $answer ) ? $answer->get_error_message() : '' );
			self::assertSame( array( $op, 'done' ), array( $answer['step']['op'], $answer['step']['state'] ), "The step $op, with every writer." );
		}
		self::without( 'term' );
		$post = $next();
		self::assertIsArray( $post, is_wp_error( $post ) ? $post->get_error_code() . ': ' . $post->get_error_message() : '' );
		self::assertSame( array( 'post', 'done' ), array( $post['step']['op'], $post['step']['state'] ), 'Phase 7 with the writer of post, as at c95eda5.' );
	}
}

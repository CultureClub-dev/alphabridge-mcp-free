<?php
/**
 * The way back where the writer of a kind is not registered (I2 and I3 of
 * the seam, step T5): on two WordPress sites as
 * tests/integration/SyncPlanWithoutWriterTest.php (B the staging site, A
 * this process), a way back planned or led on with post alone registered,
 * by its subtypes post and page (as U1 allows it), here only (after the
 * move, a kind this plugin no longer has):
 *
 * - (I2) the journals a run with every writer left of fourteen objects,
 *   each paired and changed on staging: a post, a page and one of each
 *   other kind and type (I1). Taken back (a) as the whole run and (b) over
 *   steps, the steps of the kind post chosen together, then each other
 *   step alone: neither the plan nor the confirmation of a way back is an
 *   internal error, each next answers without a WP_Error, post and page go
 *   back, and nothing of another kind or type is written or put back. The
 *   plans with their previews, the confirmations, the states and texts of
 *   the steps and the ends of the runs are read to go on, not asserted.
 * - (I3) the way back of three categories, interrupted at its first step
 *   and led on with action=plan and its token: no answer from then on is an
 *   internal error, each next answers without a WP_Error, and nothing of a
 *   term is put back, the steps still open as little as the one
 *   interrupted. The plan and the confirmation of the continuation, its
 *   preview, the states and texts of the steps and the end of the run are
 *   read to go on, not asserted.
 *
 * (P3, step T6) A run with post alone registered, by its subtypes, forward
 * and back: a post (its text, and a category set on B), a page (its text)
 * and a new page. No answer is an error, the run closes as done, the
 * category of the post is written on A, phase 7 empties the caches of its
 * taxonomies, and the pair of the new page is based on its hash here; the
 * token of the way back binds the row of the post (a change after the
 * preview refuses the confirmation), and the way back, run to its end,
 * leaves the post and the page as before the run. No stand-in for the test
 * of the move with the real registrations.
 *
 * As the setup, not as a promise about the plan: each way back and the
 * continuation run at least one step under next, so each reaches
 * revert_boundary(); a plan of no step would leave a case green without it.
 *
 * The registrations made as the engine loads are kept before each case and
 * put back after it (reflection), for the other tests of the process.
 *
 * R2 open (the seam, first step): the cases here assert only that no
 * object of a kind without a writer is written or put back. How the way
 * back plans, continues and ends the steps of such a kind is the state
 * of the first step, not what must hold after the move; that is open.
 * Once R2 is decided, the cases (I2, I3) are changed with it.
 * Open with R2 as well: a step goes back once only, so the step of such a
 * kind that failed for want of its writer is spent and is not planned
 * again once the kind is registered anew.
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
use AB_MCP_Sync_Report;
use AB_MCP_Sync_Run;
use AB_MCP_Sync_Schema;
use AB_MCP_Sync_Writers;
use AB_MCP_Tool_Registry;
use AB_MCP_Tools_Staging;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;

require_once AB_MCP_DIR . 'includes/sync/class-sync-import.php';
require_once AB_MCP_DIR . 'includes/sync/class-sync-pairs-tool.php';
require_once AB_MCP_DIR . 'includes/sync/class-sync-report.php';
require_once AB_MCP_DIR . 'includes/sync/class-sync-push.php';
require_once AB_MCP_DIR . 'includes/tools/class-tools-staging.php';

final class SyncWayBackWithoutWriterTest extends TestCase {

	/**
	 * The uploads of both sites in I2 (the option upload_path), a root of
	 * this class alone, removed before each case: another class may leave
	 * files in wp-content/uploads, and the image is to have one name on both
	 * sites (as SyncMediaTest has its own).
	 */
	private const UPLOADS = 'wp-content/uploads-rueckweg-ohne-schreiber';

	/** The date of every object of I2 on both sites, so that they are paired equal. */
	private const DATE = '2026-10-01 10:00:00';

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

	/** The protected store of both sites and the uploads of this class on both removed. */
	private static function wipe(): void {
		$root = (string) AB_MCP_IT_Sites::state()['root'];
		self::remove_tree( $root . '/ab-mcp-store', $root );
		foreach ( array( 'a', 'b' ) as $site ) {
			self::remove_tree( AB_MCP_IT_Sites::docroot( $site ) . '/' . self::UPLOADS, $root );
		}
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

	/** Post alone registered, by its subtypes post and page, as U1 allows it (tearDown() puts the table back). */
	private static function only_post(): void {
		AB_MCP_Sync_Writers::reset();
		self::assertTrue( AB_MCP_Sync_Writers::register( 'post', array( 'post', 'page' ), AB_MCP_Sync_Engine::WRITERS['post'] ) );
		self::assertNull( AB_MCP_Sync_Engine::writer( 'post', 'wp_block' ) );
	}

	/** The way back of a run planned and confirmed in this process. */
	private static function revert( int $run_id ): array {
		$args    = array(
			'action' => 'plan',
			'run_id' => $run_id,
		);
		$preview = self::gated( 'wp_site_revert', $args );
		self::assertIsArray( $preview, is_wp_error( $preview ) ? $preview->get_error_message() : '' );
		$begun = self::gated( 'wp_site_revert', $args + array( 'confirm_token' => $preview['confirm_token'] ) );
		self::assertIsArray( $begun, is_wp_error( $begun ) ? $begun->get_error_message() : '' );
		return $begun;
	}

	/** A run of these plan arguments, confirmed and led to its close, each answer no error: its id. */
	private static function forward( array $args ): int {
		$preview = self::gated( 'wp_site_push', $args );
		self::assertIsArray( $preview, is_wp_error( $preview ) ? $preview->get_error_message() : '' );
		$run = self::gated( 'wp_site_push', $args + array( 'confirm_token' => $preview['confirm_token'] ) );
		self::assertIsArray( $run, is_wp_error( $run ) ? $run->get_error_message() : '' );
		for ( $i = 0; $i < 24; $i++ ) {
			$answer = self::gated( 'wp_site_push', array( 'action' => 'next', 'run_id' => (int) $run['run_id'] ) );
			self::assertIsArray( $answer, is_wp_error( $answer ) ? $answer->get_error_message() : '' );
			if ( in_array( $answer['state'], AB_MCP_Sync_Schema::CLOSED, true ) ) {
				return (int) $run['run_id'];
			}
		}
		self::fail( 'The run closes.' );
	}

	/** The rows of these terms of A in term_taxonomy, by the keys given. */
	private static function terms_a( array $ids ): array {
		return array_map( static fn( int $id ): array => AB_MCP_IT_Sites::rows( 'a', 'term_taxonomy', array( 'term_id' => (string) $id ) ), $ids );
	}

	/** An answer that is no internal error (ab_mcp_internal), whatever else it is. */
	private static function not_internal( $answer, string $what ): void {
		self::assertNotSame( 'ab_mcp_internal', is_wp_error( $answer ) ? $answer->get_error_code() : null, $what . ( is_wp_error( $answer ) ? ' ' . $answer->get_error_message() : '' ) );
	}

	/** The same steps on both sites, each in a process of its own, then the caches of this process forgotten: the results by site. */
	private static function both( array $steps ): array {
		$out = array(
			'a' => AB_MCP_IT_Sync_Steps::run( 'a', $steps ),
			'b' => AB_MCP_IT_Sync_Steps::run( 'b', $steps ),
		);
		wp_cache_flush();
		wp_upload_dir( null, false, true );
		return $out;
	}

	/** The block of a paragraph. */
	private static function para( string $text ): string {
		return '<!-- wp:paragraph --><p>' . $text . '</p><!-- /wp:paragraph -->';
	}

	/** The arguments of a published post of a type, of the date of I2. */
	private static function post( string $type, string $name, string $content ): array {
		return array(
			'post_type'     => $type,
			'post_status'   => 'publish',
			'post_title'    => ucfirst( $name ),
			'post_name'     => $name,
			'post_content'  => $content,
			'post_author'   => 1,
			'post_date'     => self::DATE,
			'post_date_gmt' => self::DATE,
		);
	}

	/**
	 * The setup of I2, with every writer: fourteen objects alike on both
	 * sites and paired, then changed on B, each a takeover (update) in one
	 * run led to its close: a post, a page, a category, a menu with a link,
	 * an image, the tagline, a text widget in sidebar-1, the additional CSS,
	 * a reusable block, a navigation, the part header, the template single
	 * and the global styles of the theme (both sites run the same theme;
	 * the three the site editor saves get the date of the others on both).
	 *
	 * @return array{0:int,1:array,2:array,3:array} The run, the ids of A by name, the rows of A before the run and as it wrote them.
	 */
	private static function mixed(): array {
		self::pair();
		self::both( array( array( 'op' => 'update_option', 'name' => 'upload_path', 'value' => self::UPLOADS ) ) );
		$theme                = get_stylesheet();
		$widget               = array(
			2              => array( 'title' => 'Hinweis', 'text' => 'Alt', 'filter' => false, 'visual' => false ),
			'_multiwidget' => 1,
		);
		$areas                = get_option( 'sidebars_widgets' );
		$areas['sidebar-1'][] = 'text-2';
		$link                 = array( 'menu-item-title' => 'Shop', 'menu-item-url' => 'https://example.test/shop/', 'menu-item-type' => 'custom', 'menu-item-status' => 'publish', 'menu-item-position' => 1 );
		$nav                  = static fn( string $label ): string => '<!-- wp:navigation-link {"label":"' . $label . '","url":"https://example.test/","kind":"custom","isTopLevelLink":true} /-->';
		$made                 = self::both(
			array(
				array( 'op' => 'insert_post', 'args' => self::post( 'post', 'notes', self::para( 'Alt' ) ), 'as' => 'post' ),
				array( 'op' => 'insert_post', 'args' => self::post( 'page', 'about', self::para( 'Alt' ) ), 'as' => 'page' ),
				array( 'op' => 'insert_post', 'args' => self::post( 'wp_block', 'hinweis', self::para( 'Alt' ) ), 'as' => 'wp_block' ),
				array( 'op' => 'insert_post', 'args' => self::post( 'wp_navigation', 'hauptmenu', $nav( 'Start' ) ), 'as' => 'wp_navigation' ),
				array( 'op' => 'insert_term', 'name' => 'Topics', 'taxonomy' => 'category', 'as' => 'term' ),
				array( 'op' => 'nav_menu', 'name' => 'Main', 'as' => 'menu' ),
				array( 'op' => 'nav_menu_item', 'menu' => '$menu', 'args' => $link, 'as' => 'menu_item' ),
				array( 'op' => 'upload', 'name' => 'bild.jpg', 'mime' => 'image/jpeg', 'image' => array( 'w' => 8, 'h' => 8, 'rgb' => array( 10, 80, 160 ) ), 'args' => array( 'post_title' => 'Bild' ), 'as' => 'attachment' ),
				array( 'op' => 'update_option', 'name' => 'blogdescription', 'value' => 'Things' ),
				array( 'op' => 'update_option', 'name' => 'widget_text', 'value' => $widget ),
				array( 'op' => 'update_option', 'name' => 'sidebars_widgets', 'value' => $areas ),
				array( 'op' => 'custom_css', 'css' => 'body { color: #111111; }', 'as' => 'custom_css' ),
				array( 'op' => 'rest', 'method' => 'POST', 'route' => '/wp/v2/template-parts/' . $theme . '//header', 'body' => array( 'content' => self::para( 'Kopf' ) ), 'as' => 'wp_template_part' ),
				array( 'op' => 'rest', 'method' => 'POST', 'route' => '/wp/v2/templates/' . $theme . '//single', 'body' => array( 'content' => self::para( 'Einzeln' ) ), 'as' => 'wp_template' ),
				array( 'op' => 'global_styles_id', 'as' => 'wp_global_styles' ),
			)
		);
		$ids = array();
		foreach ( array( 'a', 'b' ) as $site ) {
			foreach ( array( 'post', 'page', 'wp_block', 'wp_navigation', 'term', 'menu', 'menu_item', 'attachment', 'custom_css', 'wp_template_part', 'wp_template', 'wp_global_styles' ) as $name ) {
				$ids[ $site ][ $name ] = (int) ( is_array( $made[ $site ][ $name ] ) ? $made[ $site ][ $name ]['data']['wp_id'] : $made[ $site ][ $name ] );
			}
			$ids[ $site ] += array( 'option' => 'blogdescription', 'widget_area' => 'sidebar-1' );
			$dated         = array();
			foreach ( array( 'wp_template_part', 'wp_template', 'wp_global_styles' ) as $name ) {
				$dated[] = array( 'op' => 'raw_post', 'post' => $ids[ $site ][ $name ], 'set' => array( 'post_date' => self::DATE, 'post_date_gmt' => self::DATE ) );
			}
			AB_MCP_IT_Sync_Steps::run( $site, $dated );
		}
		wp_cache_flush();
		self::paired( self::staged() );
		$b                       = $ids['b'];
		$widget[2]['text']       = 'Neu';
		$link['menu-item-title'] = 'Laden';
		self::b(
			array(
				array( 'op' => 'update_post', 'args' => array( 'ID' => $b['post'], 'post_content' => self::para( 'Neu' ) ) ),
				array( 'op' => 'update_post', 'args' => array( 'ID' => $b['page'], 'post_content' => self::para( 'Neu' ) ) ),
				array( 'op' => 'update_post', 'args' => array( 'ID' => $b['wp_block'], 'post_content' => self::para( 'Neu' ) ) ),
				array( 'op' => 'update_post', 'args' => array( 'ID' => $b['wp_navigation'], 'post_content' => $nav( 'Start' ) . $nav( 'Kontakt' ) ) ),
				array( 'op' => 'update_post', 'args' => array( 'ID' => $b['attachment'], 'post_title' => 'Bild 2026' ) ),
				array( 'op' => 'update_term', 'term' => $b['term'], 'taxonomy' => 'category', 'args' => array( 'description' => 'Fresh topics.' ) ),
				array( 'op' => 'update_term', 'term' => $b['menu'], 'taxonomy' => 'nav_menu', 'args' => array( 'description' => 'Fresh menu.' ) ),
				array( 'op' => 'nav_menu_item', 'menu' => $b['menu'], 'item' => $b['menu_item'], 'args' => $link ),
				array( 'op' => 'update_option', 'name' => 'blogdescription', 'value' => 'Other things' ),
				array( 'op' => 'update_option', 'name' => 'widget_text', 'value' => $widget ),
				array( 'op' => 'custom_css', 'css' => 'body { color: #222222; }' ),
				array( 'op' => 'rest', 'method' => 'POST', 'route' => '/wp/v2/template-parts/' . $theme . '//header', 'body' => array( 'content' => self::para( 'Kopf neu' ) ) ),
				array( 'op' => 'rest', 'method' => 'POST', 'route' => '/wp/v2/templates/' . $theme . '//single', 'body' => array( 'content' => self::para( 'Einzeln neu' ) ) ),
				array( 'op' => 'rest', 'method' => 'POST', 'route' => '/wp/v2/global-styles/' . $b['wp_global_styles'], 'body' => array( 'styles' => array( 'color' => array( 'background' => '#112233' ) ) ) ),
			)
		);
		$report  = self::report( self::staged() );
		$entries = array_values( array_filter( $report['all'], static fn( array $e ): bool => 'take_over' === $e['action'] ) );
		self::assertCount( 14, $entries, 'The fourteen objects, each a takeover (the setup, with every writer).' );
		$before  = self::rows_a( $ids['a'] );
		$push    = self::forward( self::args( $report, $entries ) );
		$written = self::rows_a( $ids['a'] );
		foreach ( $before as $name => $rows ) {
			self::assertNotSame( $rows, $written[ $name ], "The forward run wrote the $name, so a way back would show." );
		}
		return array( $push, $ids['a'], $before, $written );
	}

	/** The rows of A of the objects of I2 by name: the post and the page in posts, every other post in posts, postmeta and term_relationships (a menu item, an attachment and a template keep data there), the category and the menu in terms and term_taxonomy, the tagline and the widget area as options. */
	private static function rows_a( array $ids ): array {
		$out = array();
		foreach ( $ids as $name => $id ) {
			if ( in_array( $name, array( 'term', 'menu' ), true ) ) {
				$out[ $name ] = array( AB_MCP_IT_Sites::rows( 'a', 'terms', array( 'term_id' => (string) $id ) ), AB_MCP_IT_Sites::rows( 'a', 'term_taxonomy', array( 'term_id' => (string) $id ) ) );
			} elseif ( 'option' === $name ) {
				$out[ $name ] = AB_MCP_IT_Sites::rows( 'a', 'options', array( 'option_name' => 'blogdescription' ) );
			} elseif ( 'widget_area' === $name ) {
				$out[ $name ] = array( AB_MCP_IT_Sites::rows( 'a', 'options', array( 'option_name' => 'widget_text' ) ), AB_MCP_IT_Sites::rows( 'a', 'options', array( 'option_name' => 'sidebars_widgets' ) ) );
			} elseif ( in_array( $name, array( 'post', 'page' ), true ) ) {
				$out[ $name ] = AB_MCP_IT_Sites::rows( 'a', 'posts', array( 'ID' => (string) $id ) );
			} else {
				$out[ $name ] = array( AB_MCP_IT_Sites::rows( 'a', 'posts', array( 'ID' => (string) $id ) ), AB_MCP_IT_Sites::rows( 'a', 'postmeta', array( 'post_id' => (string) $id ) ), AB_MCP_IT_Sites::rows( 'a', 'term_relationships', array( 'object_id' => (string) $id ) ) );
			}
		}
		return $out;
	}

	/**
	 * The way back of the run, of these steps or of every one, planned and
	 * confirmed: neither answer an internal error (their content read to go
	 * on, not asserted, R2), then next as often as the confirmation names
	 * steps planned (read), each answer an array; as the setup, at least one
	 * of them runs a step, so revert_boundary() is reached (not the plan).
	 */
	private static function way_back( int $push, ?array $steps ): void {
		$args = array(
			'action' => 'plan',
			'run_id' => $push,
		) + ( null === $steps ? array() : array( 'steps' => $steps ) );
		$plan = self::gated( 'wp_site_revert', $args );
		self::not_internal( $plan, 'The plan of the way back.' );
		$led = self::gated( 'wp_site_revert', $args + array( 'confirm_token' => $plan['confirm_token'] ) );
		self::not_internal( $led, 'The confirmation of the way back.' );
		$ran = 0;
		for ( $i = 0; $i < $led['counts']['planned']; $i++ ) {
			$answer = self::gated( 'wp_site_revert', array( 'action' => 'next', 'run_id' => (int) $led['run_id'] ) );
			self::assertIsArray( $answer, is_wp_error( $answer ) ? $answer->get_error_code() . ': ' . $answer->get_error_message() : '' );
			$ran += (int) isset( $answer['step'] );
		}
		self::assertGreaterThan( 0, $ran, 'A step of the way back ran under next, so revert_boundary() was reached (the setup, not a promise about the plan).' );
	}

	/**
	 * After the ways back with post alone: the post and the page as before
	 * the run but for the time of the change (the way back sets it anew),
	 * every other object of A as the run wrote it.
	 */
	private static function back_and_left( array $ids, array $before, array $written ): void {
		$unchanged = static fn( array $rows ): array => array_map( static fn( array $row ): array => array_diff_key( $row, array_flip( array( 'post_modified', 'post_modified_gmt' ) ) ), $rows );
		foreach ( self::rows_a( $ids ) as $name => $rows ) {
			if ( in_array( $name, array( 'post', 'page' ), true ) ) {
				self::assertSame( $unchanged( $before[ $name ] ), $unchanged( $rows ), "The $name went back: post and page are registered." );
			} else {
				self::assertSame( $written[ $name ], $rows, "Nothing of the $name written or put back: its rows as the run wrote them." );
			}
		}
	}

	/* -------------------------------------------------------------- cases */

	/**
	 * Prüft: (I2 a) the way back of a whole mixed run whose journals name a
	 * post, a page and one object of each other kind and type, planned with
	 * post alone registered, by its subtypes: neither the plan nor the
	 * confirmation is an internal error, each next answers without a
	 * WP_Error, the post and the page go back, and the rows of the twelve
	 * other objects of A stay as the run wrote them, byte for byte. Read to
	 * go on, not asserted (R2): the plan, the preview and the confirmation,
	 * the states and texts of the steps, the end of the run.
	 * Sonst: Free would put an object of a kind or type back over a writer
	 * it no longer has (a template or a navigation through its writer of
	 * posts), or end the way back with an error.
	 */
	public function testTheWayBackOfAWholeMixedRunPutsBackThePostAndThePageAndNothingElse(): void {
		list( $push, $ids, $before, $written ) = self::mixed();
		self::only_post();
		self::way_back( $push, null );
		self::back_and_left( $ids, $before, $written );
	}

	/**
	 * Prüft: (I2 b) the same journals taken back over steps: first the
	 * steps of the kind post chosen together (the post, the page and the
	 * five types), then each other step alone, each way back planned with
	 * post alone registered: as in (a), no plan or confirmation an internal
	 * error, each next an array, the post and the page back, the twelve
	 * others as the run wrote them. Read to go on, not asserted (R2): the
	 * plans, previews and confirmations, the states and texts of the steps,
	 * the end of each run of the way back.
	 * Sonst: a step chosen over steps, alone or with others, would put back
	 * an object of a kind or type Free no longer has, or end with an error.
	 */
	public function testEachStepTakenBackOverStepsPutsBackThePostAndThePageAndNothingElse(): void {
		list( $push, $ids, $before, $written ) = self::mixed();
		$of_post = array();
		$others  = array();
		foreach ( AB_MCP_Sync_Run::steps( $push ) as $step ) {
			if ( 'write' === $step['op'] ) {
				if ( 'post' === $step['parts']['object']['kind'] ) {
					$of_post[] = (int) $step['seq'];
				} else {
					$others[] = (int) $step['seq'];
				}
			}
		}
		self::assertSame( array( 7, 7 ), array( count( $of_post ), count( $others ) ), 'Seven steps of the kind post, seven of the other kinds (the setup).' );
		self::only_post();
		self::way_back( $push, $of_post );
		foreach ( $others as $seq ) {
			self::way_back( $push, array( $seq ) );
		}
		self::back_and_left( $ids, $before, $written );
	}

	/**
	 * Prüft: (I3) a way back with open steps of a kind whose writer is gone
	 * (three categories; begun with every writer, interrupted at its first
	 * step, then led on with post alone registered, by its subtypes): no
	 * answer from then on is an internal error, each next answers without a
	 * WP_Error, and nothing of a term is put back, the steps still open as
	 * little as the one interrupted (the terms of A as the forward run left
	 * them). Read to go on, not asserted (R2): the plan and the confirmation
	 * of the continuation with its token, the preview, the counts, the
	 * states and texts of the steps, the end of the run.
	 * Sonst: Free would put a term back over a writer it no longer has, or
	 * end the continuation with an error.
	 */
	public function testAWayBackLedOnWithOpenStepsOfAKindWithoutAWriterPutsNothingBack(): void {
		self::pair();
		$names = array(
			'news'   => 'News',
			'tips'   => 'Tips',
			'events' => 'Events',
		);
		$on_a  = array();
		$on_b  = array();
		foreach ( $names as $slug => $name ) {
			$on_a[ $slug ] = (int) wp_insert_term( $name, 'category' )['term_id'];
			$on_b[ $slug ] = self::on_b( array( 'op' => 'insert_term', 'name' => $name, 'taxonomy' => 'category' ) );
		}
		self::paired( self::staged() );
		foreach ( $on_b as $slug => $id ) {
			self::b( array( array( 'op' => 'update_term', 'term' => $id, 'taxonomy' => 'category', 'args' => array( 'description' => 'Fresh ' . $slug . '.' ) ) ) );
		}
		$report  = self::report( self::staged() );
		$entries = array_map( static fn( string $slug ): array => self::entry( $report, 'term', 'category', $slug ), array_keys( $names ) );
		$push    = self::forward( self::args( $report, $entries ) );
		$written = self::terms_a( $on_a );
		self::assertSame( array( 'Fresh news.', 'Fresh tips.', 'Fresh events.' ), array_column( array_column( $written, 0 ), 'description' ), 'The forward run wrote the terms, so a way back would show.' );
		// The way back with every writer, interrupted at its first step: that
		// step begun, its request gone (as SyncRunTest leaves a step of the
		// way back), the interruption settled by a status call.
		$r    = (int) self::revert( $push )['run_id'];
		$seqs = array();
		foreach ( AB_MCP_Sync_Run::steps( $r ) as $step ) {
			if ( 'revert' === $step['op'] ) {
				$seqs[] = (int) $step['seq'];
			}
		}
		self::assertTrue( AB_MCP_Sync_Run::update_step( $r, min( $seqs ), array( 'state' => 'begun' ), 'planned' ) );
		$status = self::gated( 'wp_site_revert', array( 'action' => 'status', 'run_id' => $r ) );
		self::assertIsArray( $status, is_wp_error( $status ) ? $status->get_error_message() : '' );
		self::assertSame( 'interrupted', $status['state'], 'The way back is interrupted (the setup, still with every writer).' );
		self::only_post();
		$args = array(
			'action' => 'plan',
			'run_id' => $r,
		);
		$plan = self::gated( 'wp_site_revert', $args );
		self::not_internal( $plan, 'The plan of the continuation.' );
		$led = self::gated( 'wp_site_revert', $args + array( 'confirm_token' => $plan['confirm_token'] ) );
		self::not_internal( $led, 'The confirmation of the continuation.' );
		$ran = 0;
		for ( $i = 0; $i < $led['counts']['planned']; $i++ ) {
			$answer = self::gated( 'wp_site_revert', array( 'action' => 'next', 'run_id' => $r ) );
			self::assertIsArray( $answer, is_wp_error( $answer ) ? $answer->get_error_code() . ': ' . $answer->get_error_message() : '' );
			$ran += (int) isset( $answer['step'] );
		}
		self::assertGreaterThan( 0, $ran, 'A step of the continuation ran under next, so revert_boundary() was reached (the setup, not a promise about the plan).' );
		self::assertSame( $written, self::terms_a( $on_a ), 'Nothing of a term put back, the interrupted step as little as the open ones.' );
	}

	/**
	 * Prüft: (P3) a run of a post (its text, and a category set on B), a
	 * page (its text) and a new page, planned and led with post alone
	 * registered, by its subtypes: no answer is an error, the run closes as
	 * done, the category of the post is on A, phase 7 empties the caches of
	 * the taxonomy category, and the pair of the new page has its hash here
	 * as its base. Its way back: the post changed on A after the preview,
	 * the confirmation is refused (ab_mcp_confirm_mismatch); the change
	 * undone, the way back runs to its end without an error, and the post
	 * and the page are as before the run but for the time of the change,
	 * the category of the post as well. Read, not asserted: the end of the
	 * way back (the new page stays as a rest).
	 * Sonst: a writer asked without the subtype ends phase 7 with an
	 * internal error, empties no taxonomy, finds each written post changed,
	 * bases the new pair on 64 zeros, or binds no row in the way back.
	 */
	public function testARunOfAPostAndPagesWithPostAloneGoesOverAndBackAndItsWayBackBindsThePost(): void {
		self::pair();
		$made = self::both(
			array(
				array( 'op' => 'insert_term', 'name' => 'Themen', 'taxonomy' => 'category', 'as' => 'kat' ),
				array( 'op' => 'insert_post', 'args' => self::post( 'post', 'notiz', self::para( 'Alt' ) ), 'as' => 'post' ),
				array( 'op' => 'insert_post', 'args' => self::post( 'page', 'ueber', self::para( 'Alt' ) ), 'as' => 'page' ),
			)
		);
		$a = array_map( 'intval', $made['a'] );
		$b = array_map( 'intval', $made['b'] );
		self::paired( self::staged() );
		self::b(
			array(
				array( 'op' => 'set_terms', 'post' => $b['post'], 'terms' => array( $b['kat'] ), 'taxonomy' => 'category' ),
				array( 'op' => 'update_post', 'args' => array( 'ID' => $b['post'], 'post_content' => self::para( 'Neu' ) ) ),
				array( 'op' => 'update_post', 'args' => array( 'ID' => $b['page'], 'post_content' => self::para( 'Neu' ) ) ),
				array( 'op' => 'insert_post', 'args' => self::post( 'page', 'kontakt', self::para( 'Neu' ) ) ),
			)
		);
		$report  = self::report( self::staged() );
		$args    = self::args( $report, array( self::entry( $report, 'post', 'post', 'notiz' ), self::entry( $report, 'post', 'page', 'ueber' ), self::entry( $report, 'post', 'page', 'kontakt' ) ) );
		$ids     = array( 'post' => $a['post'], 'page' => $a['page'] );
		$terms   = static fn(): array => AB_MCP_IT_Sites::rows( 'a', 'term_relationships', array( 'object_id' => (string) $a['post'] ) );
		$before  = array( self::rows_a( $ids ), $terms() );
		$emptied = array();
		$spy     = static function ( $term_ids, $taxonomy ) use ( &$emptied ): void {
			if ( in_array( 'run_post', array_column( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ), 'function' ), true ) ) {
				$emptied[] = (string) $taxonomy;
			}
		};
		self::only_post();
		add_action( 'clean_term_cache', $spy, 10, 2 );
		try {
			$push = self::forward( $args );
		} finally {
			remove_action( 'clean_term_cache', $spy, 10 );
		}
		$run = AB_MCP_Sync_Run::get( $push );
		self::assertSame( 'closed_done', $run['state'], 'Every written object hashes to its target.' );
		self::assertSame( array( (string) get_term( $a['kat'], 'category' )->term_taxonomy_id ), array_column( $terms(), 'term_taxonomy_id' ), 'The category of the post is written on A.' );
		self::assertContains( 'category', $emptied, 'Phase 7 empties the caches of the taxonomy of the post.' );
		$new = (int) AB_MCP_IT_Sites::rows( 'a', 'posts', array( 'post_name' => 'kontakt' ) )[0]['ID'];
		self::assertSame( \AB_MCP_Sync_Post_Writer::live_hash( $new, AB_MCP_Sync_Engine::context( $run )['source'] ), \AB_MCP_Sync_Pairs::find_live( (string) $run['source_id'], 'post', (string) $new )['base_live'], 'The pair of the new page is based on its hash here, not on 64 zeros.' );
		// The way back: the post changed after its preview refuses the
		// confirmation; that change undone, a way back anew to its end.
		$back    = array(
			'action' => 'plan',
			'run_id' => $push,
		);
		$preview = self::gated( 'wp_site_revert', $back );
		self::assertIsArray( $preview, is_wp_error( $preview ) ? $preview->get_error_message() : '' );
		$wrote = (string) AB_MCP_IT_Sites::rows( 'a', 'posts', array( 'ID' => (string) $a['post'] ) )[0]['post_content'];
		AB_MCP_IT_Sync_Steps::run( 'a', array( array( 'op' => 'raw_post', 'post' => $a['post'], 'set' => array( 'post_content' => self::para( 'Fremd' ) ) ) ) );
		wp_cache_flush();
		$refused = self::gated( 'wp_site_revert', $back + array( 'confirm_token' => $preview['confirm_token'] ) );
		self::assertSame( 'ab_mcp_confirm_mismatch', is_wp_error( $refused ) ? $refused->get_error_code() : null, 'The token of the way back binds the row of the post.' );
		AB_MCP_IT_Sync_Steps::run( 'a', array( array( 'op' => 'raw_post', 'post' => $a['post'], 'set' => array( 'post_content' => $wrote ) ) ) );
		wp_cache_flush();
		$answer = self::revert( $push );
		for ( $i = 0; $i < 12 && ! in_array( $answer['state'], AB_MCP_Sync_Schema::CLOSED, true ); $i++ ) {
			$answer = self::gated( 'wp_site_revert', array( 'action' => 'next', 'run_id' => (int) $answer['run_id'] ) );
			self::assertIsArray( $answer, is_wp_error( $answer ) ? $answer->get_error_code() . ': ' . $answer->get_error_message() : '' );
		}
		self::assertContains( $answer['state'], AB_MCP_Sync_Schema::CLOSED, 'The way back runs to its end.' );
		self::back_and_left( $ids, $before[0], array() );
		self::assertSame( $before[1], $terms(), 'The category of the post is back as before the run.' );
	}
}

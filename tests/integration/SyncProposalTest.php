<?php
/**
 * wp_site_pairs on two WordPress sites (Z8, decisions 10, 13 and 15 of the
 * plan; branch 6, part 1): site B is the staging site, in processes of its
 * own; site A, this process, is the production site with B's key a person
 * confirmed, and takes B's packages over.
 *
 * - The inventory B packed is compared with A's own: the same page is equal,
 *   another content differs, a page on one side only is staging_only or
 *   live_only; the same objects with other ids are equal once mapped, also
 *   from packages that hold the posts but no term yet (a term of a pair by
 *   its pair, one without by the mapped form; Codex round 3 of part 2).
 *   Over MCP: confirm answers with a preview and a token, the
 *   same call with the token makes the pairs (active, unclear), list shows
 *   them, the token repeated after it ran is refused, a fresh preview says
 *   nothing is left, and discard ends a pair with its token.
 * - A proposal goes on over calls and belongs to its account and connection.
 * - A name holding a credential is shown by the SHA-256 of its ref only, on
 *   both sides.
 * - Packages of one series are one inventory; packages of two are refused;
 *   a package not taken over completely, or not by this account, is none.
 * - The canon change on rows; an unclear pair aligned anew keeps its
 *   override and base_extra; a confirmation that cannot write a pair keeps
 *   nothing; a proposal holds no more once the pair of sites changed or it
 *   expired.
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
use AB_MCP_Sync_Export;
use AB_MCP_Sync_Identity;
use AB_MCP_Sync_Import;
use AB_MCP_Sync_Inventory;
use AB_MCP_Sync_Pairs;
use AB_MCP_Sync_Pairs_Tool;
use AB_MCP_Sync_Proposal;
use AB_MCP_Sync_Schema;
use AB_MCP_Tool_Registry;
use AB_MCP_Tools_Staging;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use WP_Error;

require_once AB_MCP_DIR . 'includes/sync/class-sync-import.php';
require_once AB_MCP_DIR . 'includes/sync/class-sync-pairs-tool.php';
require_once AB_MCP_DIR . 'includes/tools/class-tools-staging.php';

final class SyncProposalTest extends TestCase {

	/** The date every page of these tests carries, on both sites. */
	private const DATE = '2026-10-01 10:00:00';

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
		wp_set_current_user( self::admin() );
		self::assertIsArray( AB_MCP_Sync_Schema::install() );
	}

	protected function tearDown(): void {
		if ( null === $this->docroot_before ) {
			unset( $_SERVER['DOCUMENT_ROOT'] );
		} else {
			$_SERVER['DOCUMENT_ROOT'] = $this->docroot_before;
		}
		AB_MCP_Protected_Store::forget();
		AB_MCP_Sync_Proposal::$limit = null;
		AB_MCP_Sync_Proposal::$clock = null;
		AB_MCP_Sync_Inventory::$clock = null;
		AB_MCP_Sync_Identity::$slice  = null;
		remove_all_filters( 'query' );
	}

	public static function tearDownAfterClass(): void {
		AB_MCP_IT_Sites::reset( 'a' );
		AB_MCP_IT_Sites::reset( 'b' );
		self::wipe();
		AB_MCP_Protected_Store::forget();
		AB_MCP_Sync_Schema::forget();
	}

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

	/** A tool over MCP: its structured answer, or the text of its error. */
	private static function tool( string $key, string $name, array $arguments ) {
		$answer = AB_MCP_IT_Sites::mcp(
			$key,
			'tools/call',
			array(
				'name'      => $name,
				'arguments' => $arguments,
			)
		);
		self::assertSame( 200, $answer['status'], $answer['raw'] );
		$result = $answer['body']['result'];
		return $result['isError'] ? $result['content'][0]['text'] : $result['structuredContent'];
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

	/** Pages on A, in this process. */
	private static function pages_on_a( array $pages ): array {
		$ids = array();
		foreach ( $pages as $name => $content ) {
			$id = wp_insert_post( wp_slash( self::page( $name, $content ) ), true );
			self::assertIsInt( $id, is_wp_error( $id ) ? $id->get_error_message() : '' );
			$ids[ $name ] = (string) $id;
		}
		return $ids;
	}

	/** Pages on B. */
	private static function pages_on_b( array $pages ): array {
		$steps = array();
		foreach ( $pages as $name => $content ) {
			$steps[] = array(
				'op'   => 'insert_post',
				'args' => self::page( $name, $content ),
				'as'   => $name,
			);
		}
		return array_map( 'strval', self::b( $steps ) );
	}

	/** B packs its inventory: the packages of one series, at most $objects objects each. */
	private static function export_b( ?int $objects = null ): array {
		$packages = array();
		$cursor   = null;
		do {
			$step = array(
				'op'      => 'export',
				'purpose' => 'inventory',
				'as'      => 'package',
			);
			if ( null !== $cursor ) {
				$step['cursor'] = $cursor;
			}
			if ( null !== $objects ) {
				$step['objects'] = $objects;
			}
			$package    = self::b( array( $step ) )['package'];
			$packages[] = $package;
			$cursor     = $package['cursor'];
		} while ( null !== $cursor && count( $packages ) < 50 );
		self::assertNull( $cursor );
		return $packages;
	}

	/**
	 * A takes a package of B over the relay, in this process (or over MCP:
	 * a package belongs to the connection that took it over): the last answer.
	 */
	private static function import( array $package, ?int $chunks = null, bool $mcp = false ) {
		$import = static fn( array $args ) => $mcp ? self::tool( 'a', 'wp_site_import_chunk', $args ) : AB_MCP_Sync_Import::call( $args );
		$state  = $import(
			array(
				'package_id' => $package['package_id'],
				'manifest'   => $package['manifest'],
				'purpose'    => 'inventory',
			)
		);
		self::assertIsArray( $state, is_wp_error( $state ) ? $state->get_error_message() : ( is_string( $state ) ? $state : '' ) );
		$steps = array();
		foreach ( range( 0, ( $chunks ?? $package['chunks'] ) - 1 ) as $i ) {
			$steps[] = array(
				'op'         => 'export_chunk',
				'package_id' => $package['package_id'],
				'chunk'      => $i,
				'as'         => 'c' . $i,
			);
		}
		$data = self::b( $steps );
		foreach ( range( 0, ( $chunks ?? $package['chunks'] ) - 1 ) as $i ) {
			$state = $import(
				array(
					'package_id' => $package['package_id'],
					'chunk'      => $i,
					'data'       => $data[ 'c' . $i ]['data'],
				)
			);
			self::assertIsArray( $state, is_wp_error( $state ) ? $state->get_error_message() : ( is_string( $state ) ? $state : '' ) );
		}
		return $state;
	}

	/** B packs and A takes over (in this process, or over MCP): the package ids. */
	private static function staged( ?int $objects = null, bool $mcp = false ): array {
		$ids = array();
		foreach ( self::export_b( $objects ) as $package ) {
			self::assertTrue( self::import( $package, null, $mcp )['complete'] );
			$ids[] = $package['package_id'];
		}
		return $ids;
	}

	/** wp_site_pairs in this process (propose and list run at once). */
	private static function here( array $a ) {
		return AB_MCP_Sync_Pairs_Tool::call( $a );
	}

	/** A proposal worked out to its end in this process: every entry. */
	private static function proposal( array $packages, int &$calls = 0 ): array {
		$answer = self::here(
			array(
				'action'   => 'propose',
				'packages' => $packages,
			)
		);
		$calls  = 1;
		while ( is_array( $answer ) && false === $answer['done'] ) {
			$answer = self::here(
				array(
					'action'      => 'propose',
					'proposal_id' => $answer['proposal_id'],
				)
			);
			++$calls;
			self::assertLessThan( 200, $calls );
		}
		self::assertIsArray( $answer, is_wp_error( $answer ) ? $answer->get_error_message() : '' );
		$all  = $answer['entries'];
		$page = $answer;
		while ( null !== $page['next_after'] ) {
			$page = self::here(
				array(
					'action'      => 'propose',
					'proposal_id' => $answer['proposal_id'],
					'after'       => $page['next_after'],
				)
			);
			self::assertIsArray( $page );
			$all = array_merge( $all, $page['entries'] );
		}
		$answer['all'] = $all;
		return $answer;
	}

	/** The entry of a page by its name, on either side. */
	private static function entry_of( array $entries, string $name ): array {
		foreach ( $entries as $entry ) {
			foreach ( array( 'staging', 'live' ) as $side ) {
				if ( isset( $entry[ $side ]['identity']['post_name'] ) && $name === $entry[ $side ]['identity']['post_name'] && 'post' === $entry['kind'] ) {
					return $entry;
				}
			}
		}
		self::fail( "No entry for $name." );
	}

	/** The pairs of A's table, by id. */
	private static function pair_rows(): array {
		$rows = array();
		foreach ( AB_MCP_IT_Sites::rows( 'a', 'ab_mcp_staging_pairs' ) as $row ) {
			$rows[ (int) $row['id'] ] = $row;
		}
		return $rows;
	}

	/** The definition of wp_site_pairs. */
	private static function def(): array {
		$r = new AB_MCP_Tool_Registry();
		AB_MCP_Tools_Staging::register( $r );
		return $r->get( 'wp_site_pairs' );
	}

	/** wp_site_pairs through the gate of the plugin, in this process. */
	private static function gated( array $a ) {
		return AB_MCP_REST_Controller::run_tool( 'wp_site_pairs', self::def(), $a );
	}

	/** A confirmation in this process: preview, then the same call with its token. */
	private static function confirmed( array $a ) {
		$preview = self::gated( $a );
		if ( is_wp_error( $preview ) ) {
			return $preview;
		}
		self::assertTrue( $preview['confirm_required'], (string) json_encode( $preview ) );
		return self::gated( $a + array( 'confirm_token' => $preview['confirm_token'] ) );
	}

	private static function refused( $result, string $code ): string {
		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( $code, $result->get_error_code(), $result->get_error_message() );
		return $result->get_error_message();
	}


	/** A package of these lines, as B would make it, with the manifest B signs at $now. */
	private static function crafted( array $lines, int $now ): array {
		$text = '';
		foreach ( $lines as $line ) {
			$text .= \AB_MCP_Sync_Canon::encode( $line ) . "\n";
		}
		$chunk   = (string) gzencode( $text, 6 );
		$id      = bin2hex( random_bytes( 16 ) );
		$package = array(
			'package_id'  => $id,
			'purpose'     => 'inventory',
			'report_hash' => null,
			'kinds'       => array(),
			'objects'     => count( $lines ),
			'unpacked'    => strlen( $text ),
			'chunks'      => array(
				array(
					'bytes'  => strlen( $chunk ),
					'sha256' => hash( 'sha256', $chunk ),
				),
			),
			'bytes'       => strlen( $chunk ),
			'sha256'      => hash( 'sha256', $chunk ),
		);
		$signed  = self::b(
			array(
				array(
					'op'      => 'sign_manifest',
					'as'      => 'signed',
					'package' => $package,
					'now'     => $now,
				),
			)
		)['signed'];
		$state   = AB_MCP_Sync_Import::call( array( 'package_id' => $id, 'manifest' => $signed, 'purpose' => 'inventory' ) );
		self::assertIsArray( $state, is_wp_error( $state ) ? $state->get_error_message() : '' );
		$state = AB_MCP_Sync_Import::call( array( 'package_id' => $id, 'chunk' => 0, 'data' => base64_encode( $chunk ) ) );
		self::assertIsArray( $state, is_wp_error( $state ) ? $state->get_error_message() : '' );
		self::assertTrue( $state['complete'] );
		return array(
			'id'      => $id,
			'expires' => $now + 86400,
		);
	}

	/** Put a file into the group of a proposal, named as the proposal names its files. */
	private static function put_file( string $gid, string $prefix, ?int $seq, array $data ): void {
		$text = \AB_MCP_Sync_Canon::encode( $data );
		$put  = AB_MCP_Protected_Store::put( 'reports', $gid, AB_MCP_Sync_Proposal::name( $prefix, $seq, hash( 'sha256', $text ) ), $text );
		self::assertIsArray( $put, is_wp_error( $put ) ? $put->get_error_message() : '' );
	}

	/** A group of the area reports with a head of its own, as a proposal of this connection would have it. */
	private static function head( array $over, string $label = 'proposal' ): string {
		$gid = AB_MCP_Protected_Store::open( 'reports', array( 'label' => $label ) );
		self::assertIsString( $gid );
		self::put_file(
			$gid,
			'h',
			null,
			array_merge(
				array(
					'canon'      => 'canon/1',
					'connection' => AB_MCP_Sync_Proposal::connection(),
					'created'    => time(),
					'expires'    => time() + 3600,
					'format'     => AB_MCP_Sync_Proposal::FORMAT,
					'packages'   => array( str_repeat( 'a', 32 ) ),
					'print'      => (string) AB_MCP_Sync_Anchor::pair_print(),
					'source_id'  => AB_MCP_Sync_Anchor::active()['source_id'],
				),
				$over
			)
		);
		return $gid;
	}

	/** A line of an option, as the export writes one. */
	private static function option_line( string $name ): array {
		return array(
			'b9'       => array(),
			'canon'    => 'canon/1',
			'complete' => true,
			'hash'     => hash( 'sha256', $name ),
			'identity' => array( 'name' => $name ),
			'kind'     => 'option',
			'locks'    => array(),
			'possible' => array(),
			'ref'      => $name,
			'refs'     => array(),
			'size'     => 10,
			'status'   => 'z8',
			'unread'   => array(),
		);
	}

	/* ---------------------------------------------------------------- MCP */

	public function testAProposalOfTheStagingInventoryIsConfirmedListedAndDiscardedOverMcp(): void {
		self::pair();
		$a = self::pages_on_a(
			array(
				'about'    => 'About us.',
				'contact'  => 'Write to us at A.',
				'live-new' => 'Only here.',
			)
		);
		$b = self::pages_on_b(
			array(
				'about'       => 'About us.',
				'contact'     => 'Write to us at B.',
				'staging-new' => 'Only on staging.',
			)
		);
		$ids = self::staged( null, true );
		self::assertCount( 1, $ids, 'A small site in one package.' );

		$answer = self::tool( 'a', 'wp_site_pairs', array( 'action' => 'propose', 'packages' => $ids ) );
		self::assertIsArray( $answer, is_string( $answer ) ? $answer : '' );
		self::assertTrue( $answer['done'] );
		self::assertNull( $answer['next_after'], 'A small site in one page.' );
		self::assertSame( array(), $answer['staging']['incomplete'], 'One package read every kind whole.' );
		$about   = self::entry_of( $answer['entries'], 'about' );
		$contact = self::entry_of( $answer['entries'], 'contact' );
		self::assertSame( array( 'equal', $b['about'], $a['about'] ), array( $about['category'], $about['staging']['ref'], $about['live']['ref'] ) );
		self::assertSame( array( 'differs', $b['contact'], $a['contact'] ), array( $contact['category'], $contact['staging']['ref'], $contact['live']['ref'] ) );
		self::assertSame( 'staging_only', self::entry_of( $answer['entries'], 'staging-new' )['category'] );
		self::assertSame( 'live_only', self::entry_of( $answer['entries'], 'live-new' )['category'] );
		self::assertStringNotContainsString( '"hash"', (string) json_encode( $answer ), 'No hash in an answer.' );
		self::assertSame( array(), self::pair_rows(), 'A proposal pairs nothing.' );

		$args    = array(
			'action'      => 'confirm',
			'proposal_id' => $answer['proposal_id'],
			'select'      => array( $about['n'], $contact['n'] ),
		);
		$preview = self::tool( 'a', 'wp_site_pairs', $args );
		self::assertIsArray( $preview, is_string( $preview ) ? $preview : '' );
		self::assertTrue( $preview['confirm_required'] );
		self::assertSame( array( 'made_active' => 1, 'made_unclear' => 1 ), $preview['preview']['counts'] );
		self::assertSame( array(), self::pair_rows(), 'A preview pairs nothing.' );

		$other = self::tool( 'a', 'wp_site_pairs', array( 'select' => array( $about['n'] ) ) + $args + array( 'confirm_token' => $preview['confirm_token'] ) );
		self::assertIsString( $other, 'The token of other arguments.' );
		self::assertStringContainsString( 'does not match this call', $other );
		self::assertSame( array(), self::pair_rows() );

		$done = self::tool( 'a', 'wp_site_pairs', $args + array( 'confirm_token' => $preview['confirm_token'] ) );
		self::assertIsArray( $done, is_string( $done ) ? $done : '' );
		self::assertTrue( $done['done'] );
		$rows = self::pair_rows();
		self::assertCount( 2, $rows );
		$by = array();
		foreach ( $rows as $row ) {
			$by[ $row['live_ref'] ] = $row;
			self::assertSame( AB_MCP_Sync_Anchor::active()['source_id'], $row['source_id'] );
			self::assertSame( 'post', $row['kind'] );
			self::assertSame( 'proposal', $row['base_origin'] );
			self::assertSame( 'canon/1', $row['canon'] );
		}
		self::assertSame( array( $b['about'], 'active' ), array( $by[ $a['about'] ]['staging_ref'], $by[ $a['about'] ]['state'] ) );
		self::assertSame( $by[ $a['about'] ]['base_live'], $by[ $a['about'] ]['base_staging'], 'Equal: one base on both sides.' );
		self::assertSame( array( $b['contact'], 'unclear' ), array( $by[ $a['contact'] ]['staging_ref'], $by[ $a['contact'] ]['state'] ) );
		self::assertNotSame( $by[ $a['contact'] ]['base_live'], $by[ $a['contact'] ]['base_staging'] );

		// Replay: the same token meets the pairs it made, and makes none twice.
		$again = self::tool( 'a', 'wp_site_pairs', $args + array( 'confirm_token' => $preview['confirm_token'] ) );
		self::assertIsString( $again );
		self::assertStringContainsString( 'Nothing is left to confirm', $again );
		self::assertStringContainsString( 'paired meanwhile', $again );
		$fresh = self::tool( 'a', 'wp_site_pairs', $args );
		self::assertIsString( $fresh );
		self::assertStringContainsString( 'Nothing is left to confirm', $fresh );
		self::assertCount( 2, self::pair_rows() );

		$list = self::tool( 'a', 'wp_site_pairs', array( 'action' => 'list' ) );
		self::assertIsArray( $list );
		self::assertSame( array_keys( $rows ), array_column( $list['pairs'], 'pair_id' ) );
		self::assertNull( $list['next_after'] );
		self::assertSame( array( (int) $by[ $a['contact'] ]['id'] ), array_column( self::tool( 'a', 'wp_site_pairs', array( 'action' => 'list', 'state' => 'unclear' ) )['pairs'], 'pair_id' ) );
		self::assertSame( array(), self::tool( 'a', 'wp_site_pairs', array( 'action' => 'list', 'kind' => 'term' ) )['pairs'] );

		// The pairs are not proposed again; the unclear one is for a new alignment.
		$next = self::tool( 'a', 'wp_site_pairs', array( 'action' => 'propose', 'packages' => $ids ) );
		self::assertIsArray( $next, is_string( $next ) ? $next : '' );
		self::assertSame( 1, $next['paired'] );
		$realign = array_values( array_filter( $next['entries'], static fn( $e ) => 'realign' === $e['category'] ) );
		self::assertCount( 1, $realign );
		self::assertSame( (int) $by[ $a['contact'] ]['id'], $realign[0]['pair']['id'] );
		self::assertStringContainsString( 'The two sides differ now', $realign[0]['note'] );

		// discard, with its token.
		$discard = array(
			'action'   => 'discard',
			'pair_ids' => array( (int) $by[ $a['contact'] ]['id'] ),
		);
		$preview = self::tool( 'a', 'wp_site_pairs', $discard );
		self::assertIsArray( $preview );
		self::assertTrue( $preview['confirm_required'] );
		self::assertCount( 2, self::pair_rows() );
		$ended = self::tool( 'a', 'wp_site_pairs', $discard + array( 'confirm_token' => $preview['confirm_token'] ) );
		self::assertIsArray( $ended, is_string( $ended ) ? $ended : '' );
		self::assertSame( array( 'discarded' => 1 ), $ended['counts'] );
		self::assertSame( array( (int) $by[ $a['about'] ]['id'] ), array_keys( self::pair_rows() ) );
		self::assertSame( 'Write to us at A.', get_post( (int) $a['contact'] )->post_content, 'Its objects stay.' );
	}

	public function testObjectsOfPluginsAreProposedWithTheirLocksNotLeftOut(): void {
		self::pair();
		// What WooCommerce, ACF, Elementor and Yoast store, on both sites alike
		// (a product of the other content on B).
		$posts = array(
			'product'  => array( 'post_type' => 'product', 'post_name' => 'blue-shirt', 'post_title' => 'Blue shirt', 'post_status' => 'publish' ),
			'group'    => array( 'post_type' => 'acf-field-group', 'post_name' => 'group_64ab12cd34ef5', 'post_title' => 'Shirt fields', 'post_status' => 'publish', 'post_content' => 'a:1:{s:8:"position";s:6:"normal";}' ),
			'template' => array( 'post_type' => 'elementor_library', 'post_name' => 'header-1', 'post_title' => 'Header', 'post_status' => 'publish' ),
			'landing'  => array( 'post_type' => 'page', 'post_name' => 'landing', 'post_title' => 'Landing', 'post_status' => 'publish' ),
		);
		$common = array(
			'post_author'   => 1,
			'post_date'     => self::DATE,
			'post_date_gmt' => self::DATE,
		);
		$a      = array();
		foreach ( $posts as $key => $args ) {
			$id = wp_insert_post( wp_slash( $args + $common + array( 'post_content' => 'product' === $key ? 'Cotton, size M.' : '' ) ), true );
			self::assertIsInt( $id );
			$a[ $key ] = (string) $id;
		}
		update_post_meta( (int) $a['landing'], '_elementor_data', wp_slash( '[{"id":"1a2b3c","elType":"section","elements":[]}]' ) );
		update_post_meta( (int) $a['landing'], '_elementor_edit_mode', 'builder' );
		update_option( 'woocommerce_currency', 'CHF' );
		update_option( 'wpseo_titles', array( 'title-home-wpseo' => '%%sitename%%', 'separator' => 'sc-dash' ) );
		$steps = array();
		foreach ( $posts as $key => $args ) {
			$steps[] = array(
				'op'   => 'insert_post',
				'args' => $args + $common + array( 'post_content' => 'product' === $key ? 'Cotton, size L.' : '' ),
				'as'   => $key,
			);
		}
		$steps[] = array( 'op' => 'add_meta', 'post' => '$landing', 'key' => '_elementor_data', 'value' => '[{"id":"1a2b3c","elType":"section","elements":[]}]' );
		$steps[] = array( 'op' => 'add_meta', 'post' => '$landing', 'key' => '_elementor_edit_mode', 'value' => 'builder' );
		$steps[] = array( 'op' => 'update_option', 'name' => 'woocommerce_currency', 'value' => 'CHF' );
		$steps[] = array( 'op' => 'update_option', 'name' => 'wpseo_titles', 'value' => array( 'title-home-wpseo' => '%%sitename%%', 'separator' => 'sc-dash' ) );
		self::b( $steps );

		$answer = self::proposal( self::staged() );
		$want   = array(
			'blue-shirt'          => array( 'differs', 'post_type:*' ),
			'group_64ab12cd34ef5' => array( 'equal', 'post_type:*' ),
			'header-1'            => array( 'equal', 'post_type:*' ),
			'landing'             => array( 'equal', 'post:builder' ),
		);
		foreach ( $want as $name => list( $category, $lock ) ) {
			$entry = self::entry_of( $answer['all'], $name );
			self::assertSame( $category, $entry['category'], $name );
			self::assertContains( $lock, array_column( $entry['live']['locks'], 'key' ), $name . ': proposed with its lock, shown.' );
			self::assertContains( $entry['confirm'], array( 'active', 'unclear' ), $name );
		}
		foreach ( array( 'woocommerce_currency', 'wpseo_titles' ) as $option ) {
			$found = array_values( array_filter( $answer['all'], static fn( $e ) => 'option' === $e['kind'] && $option === ( $e['live']['ref'] ?? null ) ) );
			self::assertCount( 1, $found, $option );
			self::assertSame( 'equal', $found[0]['category'], $option );
			self::assertSame( array( 'option:unclassified' ), array_column( $found[0]['live']['locks'], 'key' ), $option );
		}
		self::assertSame( 0, $answer['counts']['held'], 'No name of these plugins is held.' );
	}


	public function testANumberThatHitsAPostAndATermNamesNeither(): void {
		global $wpdb;
		self::pair();
		self::shift_ids_on_a();
		$image = array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_title' => 'feld', 'post_name' => 'feld', 'post_mime_type' => 'image/jpeg', 'post_author' => 1, 'post_date' => self::DATE, 'post_date_gmt' => self::DATE );
		$a     = (int) wp_insert_post( wp_slash( $image ) );
		update_post_meta( $a, '_wp_attached_file', '2026/10/feld.jpg' );
		// A term of the same id here: the number names a post or a term.
		self::assertSame( 1, $wpdb->insert( $wpdb->terms, array( 'term_id' => $a, 'name' => 'Doppelt', 'slug' => 'doppelt', 'term_group' => 0 ) ) );
		self::assertSame( 1, $wpdb->insert( $wpdb->term_taxonomy, array( 'term_id' => $a, 'taxonomy' => 'post_tag', 'description' => '', 'parent' => 0, 'count' => 0 ) ) );
		$page = (int) wp_insert_post( wp_slash( self::page( 'doppelt', 'A field.' ) ) );
		update_post_meta( $page, 'feld', (string) $a );
		$b = self::b(
			array(
				array( 'op' => 'insert_post', 'args' => $image, 'as' => 'image' ),
				array( 'op' => 'add_meta', 'post' => '$image', 'key' => '_wp_attached_file', 'value' => '2026/10/feld.jpg' ),
				array( 'op' => 'insert_post', 'args' => self::page( 'doppelt', 'A field.' ), 'as' => 'page' ),
				array( 'op' => 'add_meta', 'post' => '$page', 'key' => 'feld', 'value' => '{$image}' ),
			)
		);
		self::assertNotSame( (string) $a, (string) $b['image'] );
		$answer = self::proposal( self::staged() );
		self::assertSame( 'differs', self::entry_by_live( $answer['all'], 'post', (string) $page )['category'], 'Its number names no one object here: compared as it is.' );
	}

	/* ----------------------------------------------------------- selection */

	public function testAConfirmationTakesOnlyWhatTheProposalOffers(): void {
		self::pair();
		$a = self::pages_on_a( array( 'about' => 'About us.', 'live-new' => 'x' ) );
		self::pages_on_b( array( 'about' => 'About us.', 'staging-new' => 'y' ) );
		// Drafts without a slug: one group of more than one on each side.
		$drafts_a = array();
		foreach ( array( 'x1', 'x2', 'same' ) as $content ) {
			$id = wp_insert_post( wp_slash( self::page( '', $content, array( 'post_status' => 'draft', 'post_title' => 'Draft' ) ) ), true );
			self::assertIsInt( $id );
			$drafts_a[ $content ] = (string) $id;
		}
		$steps = array();
		foreach ( array( 'x3', 'x4', 'same', 'same2' ) as $content ) {
			$steps[] = array(
				'op'   => 'insert_post',
				'args' => self::page( '', 'same2' === $content ? 'same' : $content, array( 'post_status' => 'draft', 'post_title' => 'Draft' ) ),
				'as'   => $content,
			);
		}
		$steps[]  = array(
			'op'   => 'insert_post',
			'args' => self::page( '', 'locked', array( 'post_status' => 'draft', 'post_title' => 'Draft', 'post_password' => 'secret' ) ),
			'as'   => 'locked',
		);
		$drafts_b = array_map( 'strval', self::b( $steps ) );
		$source   = AB_MCP_Sync_Anchor::active()['source_id'];
		self::assertIsArray(
			AB_MCP_Sync_Pairs::create(
				array(
					'source_id'    => $source,
					'kind'         => 'post',
					'live_ref'     => $a['live-new'],
					'staging_ref'  => '999999',
					'canon'        => 'canon/1',
					'base_live'    => hash( 'sha256', 'l' ),
					'base_staging' => hash( 'sha256', 's' ),
					'base_origin'  => 'push',
					'state'        => 'unclear',
				)
			)
		);
		$answer = self::proposal( self::staged() );
		$id     = $answer['proposal_id'];
		$pick   = static fn( string $category ): array => array_values( array_filter( $answer['all'], static fn( $e ) => $category === $e['category'] ) );
		// Drafts without a slug are paired only by a choice (uncertain).
		$amb    = array_values( array_filter( $pick( 'uncertain' ), static fn( $e ) => 'page' === $e['subtype'] ) );
		self::assertCount( 1, $amb );
		$amb    = $amb[0];
		self::assertCount( 5, $amb['candidates']['staging'] );
		self::assertCount( 3, $amb['candidates']['live'] );
		$about   = self::entry_of( $answer['all'], 'about' );
		$staging = self::entry_of( $answer['all'], 'staging-new' );
		$realign = $pick( 'realign' );
		self::assertNull( $realign[0]['confirm'] );

		$c   = static fn( array $more ): array => array( 'action' => 'confirm', 'proposal_id' => $id ) + $more;
		$say = static function ( array $more, string $says ) use ( $c ): void {
			self::assertStringContainsString( $says, self::refused( self::gated( $c( $more ) ), 'ab_mcp_sync_pairs_selection' ), (string) json_encode( $more ) );
		};
		$say( array(), 'Nothing is chosen.' );
		$say( array( 'select' => array( 'x' ) ), 'select is a list of entry numbers' );
		$say( array( 'select' => array( 0 ) ), 'select is a list of entry numbers' );
		$say( array( 'categories' => array( 'staging_only' ) ), 'categories a list of equal, differs and realign' );
		// The schema of the tool refuses a choices that is no list before; the plan, called alone, as well.
		self::assertStringContainsString( 'choices a list of entry', self::refused( AB_MCP_Tools_Staging::pairs_state( $c( array( 'choices' => 'x' ) ) ), 'ab_mcp_sync_pairs_selection' ) );
		$say( array( 'choices' => array( array( 'entry' => $amb['n'] ) ) ), 'A choice is an object of entry' );
		$say( array( 'select' => array( 99999 ) ), 'The proposal has no entry 99999' );
		$say( array( 'select' => array( $amb['n'] ) ), 'is uncertain: it is paired only by the choice of a person' );
		$say( array( 'select' => array( $staging['n'] ) ), 'is staging_only and is not paired' );
		$say( array( 'select' => array( $realign[0]['n'] ) ), 'cannot be aligned anew' );
		$say( array( 'choices' => array( array( 'entry' => $about['n'], 'staging_ref' => $about['staging']['ref'], 'live_ref' => $about['live']['ref'] ) ) ), 'is not uncertain; choose it with select' );
		$say( array( 'choices' => array( array( 'entry' => $amb['n'], 'staging_ref' => $about['staging']['ref'], 'live_ref' => $drafts_a['x1'] ) ) ), 'not among its candidates' );
		$say( array( 'choices' => array( array( 'entry' => $amb['n'], 'staging_ref' => $drafts_b['x3'], 'live_ref' => $about['live']['ref'] ) ) ), 'not among its candidates' );
		$say( array( 'choices' => array( array( 'entry' => $amb['n'], 'staging_ref' => $drafts_b['locked'], 'live_ref' => $drafts_a['x1'] ) ) ), 'is not compared (it has no hash)' );
		$say(
			array(
				'choices' => array(
					array( 'entry' => $amb['n'], 'staging_ref' => $drafts_b['x3'], 'live_ref' => $drafts_a['x1'] ),
					array( 'entry' => $amb['n'], 'staging_ref' => $drafts_b['x3'], 'live_ref' => $drafts_a['x2'] ),
				),
			),
			'The same object is chosen twice'
		);
		$say(
			array(
				'choices' => array(
					array( 'entry' => $amb['n'], 'staging_ref' => $drafts_b['x3'], 'live_ref' => $drafts_a['x1'] ),
					array( 'entry' => $amb['n'], 'staging_ref' => $drafts_b['x4'], 'live_ref' => $drafts_a['x1'] ),
				),
			),
			'The same object is chosen twice'
		);
		$skip = self::refused( self::gated( $c( array( 'categories' => array( 'realign' ) ) ) ), 'ab_mcp_sync_pairs_nothing' );
		self::assertStringContainsString( 'Entry ' . $realign[0]['n'] . ': The object is not in the inventory of the staging site.', $skip );
		self::assertSame( array(), array_filter( self::pair_rows(), static fn( $r ) => 'proposal' === $r['base_origin'] ), 'Nothing was paired.' );

		$done = self::confirmed(
			$c(
				array(
					'choices' => array(
						array( 'entry' => $amb['n'], 'staging_ref' => $drafts_b['same'], 'live_ref' => $drafts_a['same'] ),
						array( 'entry' => $amb['n'], 'staging_ref' => $drafts_b['x3'], 'live_ref' => $drafts_a['x1'] ),
					),
				)
			)
		);
		self::assertIsArray( $done, is_wp_error( $done ) ? $done->get_error_message() : '' );
		self::assertSame( array( 'made_active' => 1, 'made_unclear' => 1 ), $done['counts'] );
		$by = array();
		foreach ( self::pair_rows() as $row ) {
			$by[ $row['live_ref'] ] = $row;
		}
		self::assertSame( array( $drafts_b['same'], 'active' ), array( $by[ $drafts_a['same'] ]['staging_ref'], $by[ $drafts_a['same'] ]['state'] ), 'The same content: active.' );
		self::assertSame( array( $drafts_b['x3'], 'unclear' ), array( $by[ $drafts_a['x1'] ]['staging_ref'], $by[ $drafts_a['x1'] ]['state'] ) );
	}

	public function testAConfirmationMakesAtMost500PairsAndTheRestInAPartAfter(): void {
		self::pair();
		$names = array();
		foreach ( range( 1, 501 ) as $i ) {
			$names[ 'p-' . $i ] = 'Same ' . $i;
		}
		self::pages_on_a( $names );
		self::pages_on_b( $names );
		$answer = self::proposal( self::staged() );
		$equal  = array_values( array_filter( $answer['all'], static fn( $e ) => 'equal' === $e['category'] ) );
		self::assertGreaterThanOrEqual( 501, count( $equal ) );
		self::assertGreaterThan( 200, $answer['total'], 'More than one page of entries.' );

		$args    = array(
			'action'      => 'confirm',
			'proposal_id' => $answer['proposal_id'],
			'categories'  => array( 'equal' ),
		);
		$preview = self::gated( $args );
		self::assertIsArray( $preview );
		self::assertSame( array( 'made_active' => 500 ), $preview['preview']['counts'] );
		self::assertSame( $equal[499]['n'], $preview['preview']['more_after'] );
		self::assertCount( 200, $preview['preview']['pairs'], 'The first 200 named.' );
		$done = self::gated( $args + array( 'confirm_token' => $preview['confirm_token'] ) );
		self::assertIsArray( $done );
		self::assertSame( $equal[499]['n'], $done['more_after'] );
		self::assertStringContainsString( 'after=' . $equal[499]['n'], $done['next'] );
		self::assertCount( 500, self::pair_rows() );
		$rest = self::confirmed( $args + array( 'after' => $done['more_after'] ) );
		self::assertIsArray( $rest, is_wp_error( $rest ) ? $rest->get_error_message() : '' );
		self::assertSame( array( 'made_active' => count( $equal ) - 500 ), $rest['counts'] );
		self::assertArrayNotHasKey( 'more_after', $rest );
		self::assertCount( count( $equal ), self::pair_rows() );

		$numbers = array_column( array_slice( $equal, 0, 501 ), 'n' );
		self::assertStringContainsString( 'at most 500 pairs', self::refused( self::gated( array( 'action' => 'confirm', 'proposal_id' => $answer['proposal_id'], 'select' => $numbers ) ), 'ab_mcp_sync_pairs_selection' ) );
		self::assertStringContainsString( 'fill them already', self::refused( self::gated( array( 'action' => 'confirm', 'proposal_id' => $answer['proposal_id'], 'select' => array_slice( $numbers, 0, 500 ), 'categories' => array( 'equal' ) ) ), 'ab_mcp_sync_pairs_selection' ) );
	}

	/* ------------------------------------------------------------- discard */

	public function testADiscardThatCannotEndAPairEndsNone(): void {
		self::pair();
		$a      = self::pages_on_a( array( 'about' => 'About us.', 'team' => 'T' ) );
		$b      = self::pages_on_b( array( 'about' => 'About us.', 'team' => 'T' ) );
		$source = AB_MCP_Sync_Anchor::active()['source_id'];
		$ids    = array();
		foreach ( array( 'about', 'team' ) as $name ) {
			$pair = AB_MCP_Sync_Pairs::create(
				array(
					'source_id'    => $source,
					'kind'         => 'post',
					'live_ref'     => $a[ $name ],
					'staging_ref'  => $b[ $name ],
					'canon'        => 'canon/1',
					'base_live'    => hash( 'sha256', 'l' ),
					'base_staging' => hash( 'sha256', 's' ),
					'base_origin'  => 'proposal',
					'state'        => 'residual',
					'override'     => array( 'post_status' => 'draft' ),
				)
			);
			self::assertIsArray( $pair );
			$ids[ $name ] = (int) $pair['id'];
		}
		$foreign = AB_MCP_Sync_Pairs::create(
			array(
				'source_id'    => 'another-source',
				'kind'         => 'post',
				'live_ref'     => $a['about'],
				'staging_ref'  => '1',
				'canon'        => 'canon/1',
				'base_live'    => hash( 'sha256', 'l' ),
				'base_staging' => hash( 'sha256', 's' ),
				'base_origin'  => 'proposal',
			)
		);
		self::assertIsArray( $foreign );
		self::assertStringContainsString( 'There is no pair ' . $foreign['id'], self::refused( self::gated( array( 'action' => 'discard', 'pair_ids' => array( (int) $foreign['id'] ) ) ), 'ab_mcp_sync_pairs_selection' ) );
		self::refused( self::gated( array( 'action' => 'discard', 'pair_ids' => array( 999999 ) ) ), 'ab_mcp_sync_pairs_selection' );

		$args    = array(
			'action'   => 'discard',
			'pair_ids' => array( $ids['about'], $ids['team'] ),
		);
		$preview = self::gated( $args );
		self::assertSame( array( 'discarded' => 2 ), $preview['preview']['counts'] );
		$table   = AB_MCP_Sync_Schema::table( 'pairs' );
		$deletes = 0;
		add_filter(
			'query',
			static function ( $sql ) use ( $table, &$deletes ) {
				if ( 0 === strpos( (string) $sql, 'DELETE FROM ' . $table ) && 2 === ++$deletes ) {
					return 'SELECT broken FROM nowhere_at_all';
				}
				return $sql;
			}
		);
		$result = self::gated( $args + array( 'confirm_token' => $preview['confirm_token'] ) );
		remove_all_filters( 'query' );
		self::assertSame( 2, $deletes );
		self::assertStringContainsString( 'the database took back all of it', self::refused( $result, 'ab_mcp_sync_pairs_discard_failed' ) );
		$rows = self::pair_rows();
		self::assertTrue( isset( $rows[ $ids['about'] ], $rows[ $ids['team'] ] ), 'Both pairs stay, with their ids.' );
		self::assertSame( array( $b['about'], 'residual', '{"post_status":"draft"}', 'proposal' ), array( $rows[ $ids['about'] ]['staging_ref'], $rows[ $ids['about'] ]['state'], $rows[ $ids['about'] ]['override'], $rows[ $ids['about'] ]['base_origin'] ), 'As it was.' );

		// Any account with manage_options ends pairs (pairs are data of the site); the log names it.
		$other = wp_insert_user(
			array(
				'user_login' => 'second',
				'user_pass'  => wp_generate_password( 24 ),
				'user_email' => 'second@example.invalid',
				'role'       => 'administrator',
			)
		);
		self::assertIsInt( $other );
		wp_set_current_user( $other );
		$done = self::confirmed( $args );
		self::assertIsArray( $done, is_wp_error( $done ) ? $done->get_error_message() : '' );
		self::assertSame( array( 'discarded' => 2 ), $done['counts'] );
		self::assertFalse( isset( self::pair_rows()[ $ids['about'] ] ) );
		$log  = (array) get_option( 'ab_mcp_audit', array() );
		$last = end( $log );
		self::assertSame( array( 'wp_site_pairs', $other ), array( $last['tool'] ?? null, (int) ( $last['user'] ?? 0 ) ), 'The log names the account.' );
	}

	/* --------------------------------------------------------------- store */

	public function testAProposalNotDoneIsNotConfirmedAndTwoCallsAtOnceVoidIt(): void {
		self::pair();
		$ids                         = self::staged();
		AB_MCP_Sync_Proposal::$limit = 3;
		$cases                       = array(
			'the same number twice' => static fn( array $first ): array => array( 'c', 1, array_merge( $first, array( 'b9' => 99 ) ) ),
			'a gap'                 => static fn( array $first ): array => array( 'c', 3, array_merge( $first, array( 'seq' => 3 ) ) ),
			'another number inside' => static fn( array $first ): array => array( 'c', 2, array_merge( $first, array( 'seq' => 5 ) ) ),
		);
		foreach ( $cases as $case => $make ) {
			$begun = self::here( array( 'action' => 'propose', 'packages' => $ids ) );
			self::assertFalse( $begun['done'], $case );
			self::assertStringContainsString( 'not worked out yet', self::refused( self::gated( array( 'action' => 'confirm', 'proposal_id' => $begun['proposal_id'], 'select' => array( 1 ) ) ), 'ab_mcp_sync_pairs_not_done' ), $case );
			$files = AB_MCP_Protected_Store::files( 'reports', $begun['proposal_id'] );
			$close = null;
			foreach ( array_keys( $files ) as $name ) {
				$parsed = AB_MCP_Sync_Proposal::parse_name( (string) $name );
				if ( null !== $parsed && 'c' === $parsed['prefix'] ) {
					$close = json_decode( (string) AB_MCP_Protected_Store::get( 'reports', $begun['proposal_id'], $parsed['name'], $parsed['sha'] ), true );
				}
			}
			self::assertIsArray( $close, $case );
			list( $prefix, $seq, $data ) = $make( $close );
			self::put_file( $begun['proposal_id'], $prefix, $seq, $data );
			self::assertStringContainsString( 'two calls went on with it at once', self::refused( self::here( array( 'action' => 'propose', 'proposal_id' => $begun['proposal_id'] ) ), 'ab_mcp_sync_pairs_parts' ), $case );
		}
	}

	public function testAProposalWhoseWorkingOutFailedIsWorkedOutByTheNextCall(): void {
		self::pair();
		self::pages_on_a( array( 'about' => 'About us.', 'live-new' => 'x' ) );
		self::pages_on_b( array( 'about' => 'About us.' ) );
		$ids   = self::staged();
		$whole = self::proposal( $ids );
		$table = AB_MCP_Sync_Schema::table( 'pairs' );
		$fail  = true;
		add_filter(
			'query',
			static function ( $sql ) use ( $table, &$fail ) {
				if ( $fail && false !== strpos( (string) $sql, 'FROM ' . $table ) ) {
					$fail = false;
					return 'SELECT broken FROM nowhere_at_all';
				}
				return $sql;
			}
		);
		$failed = self::here( array( 'action' => 'propose', 'packages' => $ids ) );
		remove_all_filters( 'query' );
		self::refused( $failed, 'ab_mcp_staging_db_error' );
		$groups = array_values( array_filter( AB_MCP_Protected_Store::groups( 'reports' ), static fn( $g ) => 'proposal' === $g['label'] && $whole['proposal_id'] !== $g['gid'] ) );
		self::assertCount( 1, $groups );
		$again = self::here( array( 'action' => 'propose', 'proposal_id' => $groups[0]['gid'] ) );
		self::assertIsArray( $again, is_wp_error( $again ) ? $again->get_error_message() : '' );
		self::assertTrue( $again['done'] );
		$strip = static fn( array $entries ): array => array_map( static fn( $e ) => array( $e['category'], $e['staging']['ref'] ?? null, $e['live']['ref'] ?? null ), $entries );
		self::assertSame( $strip( $whole['entries'] ), $strip( $again['entries'] ), 'Its inventory was not read a second time.' );
		self::assertSame( $whole['live']['objects'], $again['live']['objects'] );
	}

	public function testAGroupWithTwoHeadsIsNone(): void {
		self::pair();
		$gid = self::head( array() );
		self::put_file( $gid, 'h', null, array( 'format' => AB_MCP_Sync_Proposal::FORMAT, 'other' => true ) );
		self::refused( self::here( array( 'action' => 'propose', 'proposal_id' => $gid ) ), 'ab_mcp_sync_pairs_parts' );
	}

	public function testAProposalOfAnotherCanonSourceOrFormatIsNone(): void {
		self::pair();
		foreach ( array( 'canon' => 'canon/9', 'source_id' => 'another-source' ) as $field => $value ) {
			$gid = self::head( array( $field => $value ) );
			self::refused( self::here( array( 'action' => 'propose', 'proposal_id' => $gid ) ), 'ab_mcp_sync_pairs_stale' );
		}
		$gid = self::head( array( 'expires' => time() + 3600 ) );
		self::assertNotSame( 'ab_mcp_sync_pairs_stale', self::here( array( 'action' => 'propose', 'proposal_id' => $gid ) )->get_error_code(), 'The same head as it should be is no stale one.' );
		self::refused( self::here( array( 'action' => 'propose', 'proposal_id' => self::head( array( 'format' => 'other/1' ) ) ) ), 'ab_mcp_sync_pairs_unknown' );
		self::refused( self::here( array( 'action' => 'propose', 'proposal_id' => self::head( array(), 'other' ) ) ), 'ab_mcp_sync_pairs_unknown' );
		self::refused( self::here( array( 'action' => 'propose', 'proposal_id' => self::head( array( 'connection' => hash( 'sha256', 'x' ) ) ) ) ), 'ab_mcp_sync_pairs_foreign' );
	}

	public function testAProposalHoldsAsLongAsItsEarliestPackage(): void {
		self::pair();
		$early = self::crafted( array( self::option_line( 'blogname' ) ), time() - 3600 );
		$late  = self::crafted( array( self::option_line( 'blogdescription' ) ), time() );
		$answer = self::proposal( array( $late['id'], $early['id'] ) );
		self::assertSame( $early['expires'], $answer['expires'] );
	}

	public function testAPackageWhoseManifestExpiredIsNotRead(): void {
		self::pair();
		$package = self::crafted( array( self::option_line( 'blogname' ) ), time() - 86400 + 8 );
		while ( time() < $package['expires'] ) {
			sleep( 1 );
		}
		self::refused( self::here( array( 'action' => 'propose', 'packages' => array( $package['id'] ) ) ), 'ab_mcp_sync_manifest_expired' );
	}

	/* -------------------------------------------------- mapped forms (B1) */

	/** On A: $n posts and a category first, so that the ids of A run ahead of those of B. */
	private static function shift_ids_on_a( int $n = 7 ): void {
		foreach ( range( 1, $n ) as $i ) {
			self::assertIsInt( wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'draft', 'post_title' => 'Filler ' . $i, 'post_name' => 'filler-' . $i ) ) );
		}
		self::assertIsArray( wp_insert_term( 'Filler', 'category' ) );
	}

	/** The same structure on A (in this process) and on B: parent, category, image, menu. */
	private static function structure( string $category_b = 'news' ): array {
		$a                 = array();
		$a['eltern']       = (int) wp_insert_post( wp_slash( self::page( 'eltern', 'Parent.' ) ) );
		$a['kind']         = (int) wp_insert_post( wp_slash( self::page( 'kind', 'Child.', array( 'post_parent' => $a['eltern'] ) ) ) );
		$a['enkel']        = (int) wp_insert_post( wp_slash( self::page( 'enkel', 'Grandchild.', array( 'post_parent' => $a['kind'] ) ) ) );
		$a['news']       = (int) wp_insert_term( 'News', 'category', array( 'slug' => 'news' ) )['term_id'];
		$a['other']        = (int) wp_insert_term( 'Other', 'category', array( 'slug' => 'other' ) )['term_id'];
		$a['meldung']      = (int) wp_insert_post( wp_slash( self::page( 'meldung', 'News item.', array( 'post_type' => 'post' ) ) ) );
		wp_set_object_terms( $a['meldung'], array( $a['news'] ), 'category' );
		$a['bild']         = (int) wp_insert_post( wp_slash( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_title' => 'Bild', 'post_name' => 'bild', 'post_mime_type' => 'image/jpeg', 'post_author' => 1, 'post_date' => self::DATE, 'post_date_gmt' => self::DATE ) ) );
		update_post_meta( $a['bild'], '_wp_attached_file', '2026/10/bild.jpg' );
		$a['mit-bild']     = (int) wp_insert_post( wp_slash( self::page( 'mit-bild', 'With an image.' ) ) );
		update_post_meta( $a['mit-bild'], '_thumbnail_id', (string) $a['bild'] );
		$a['menu']         = (int) wp_create_nav_menu( 'Haupt' );
		$a['item']         = (int) wp_update_nav_menu_item( $a['menu'], 0, array( 'menu-item-object-id' => $a['eltern'], 'menu-item-object' => 'page', 'menu-item-type' => 'post_type', 'menu-item-status' => 'publish', 'menu-item-position' => 1 ) );
		$b = self::b(
			array(
				array( 'op' => 'insert_post', 'args' => self::page( 'eltern', 'Parent.' ), 'as' => 'eltern' ),
				array( 'op' => 'insert_post', 'args' => self::page( 'kind', 'Child.', array( 'post_parent' => '$eltern' ) ), 'as' => 'kind' ),
				array( 'op' => 'insert_post', 'args' => self::page( 'enkel', 'Grandchild.', array( 'post_parent' => '$kind' ) ), 'as' => 'enkel' ),
				array( 'op' => 'insert_term', 'name' => 'News', 'taxonomy' => 'category', 'args' => array( 'slug' => 'news' ), 'as' => 'news' ),
				array( 'op' => 'insert_term', 'name' => 'Other', 'taxonomy' => 'category', 'args' => array( 'slug' => 'other' ), 'as' => 'other' ),
				array( 'op' => 'insert_post', 'args' => self::page( 'meldung', 'News item.', array( 'post_type' => 'post' ) ), 'as' => 'meldung' ),
				array( 'op' => 'set_terms', 'post' => '$meldung', 'terms' => array( '$' . $category_b ), 'taxonomy' => 'category' ),
				array( 'op' => 'insert_post', 'args' => array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_title' => 'Bild', 'post_name' => 'bild', 'post_mime_type' => 'image/jpeg', 'post_author' => 1, 'post_date' => self::DATE, 'post_date_gmt' => self::DATE ), 'as' => 'bild' ),
				array( 'op' => 'add_meta', 'post' => '$bild', 'key' => '_wp_attached_file', 'value' => '2026/10/bild.jpg' ),
				array( 'op' => 'insert_post', 'args' => self::page( 'mit-bild', 'With an image.' ), 'as' => 'mit-bild' ),
				array( 'op' => 'add_meta', 'post' => '$mit-bild', 'key' => '_thumbnail_id', 'value' => '{$bild}' ),
				array( 'op' => 'nav_menu', 'name' => 'Haupt', 'as' => 'menu' ),
				array( 'op' => 'nav_menu_item', 'menu' => '$menu', 'args' => array( 'menu-item-object-id' => '$eltern', 'menu-item-object' => 'page', 'menu-item-type' => 'post_type', 'menu-item-status' => 'publish', 'menu-item-position' => 1 ), 'as' => 'item' ),
			)
		);
		return array(
			'a' => array_map( 'strval', $a ),
			'b' => array_map( static fn( $v ) => is_scalar( $v ) ? (string) $v : '', $b ),
		);
	}

	/** The entry of an object of A by kind and ref, also as a candidate of a choice. */
	private static function entry_with_live( array $entries, string $kind, string $ref ): array {
		foreach ( $entries as $entry ) {
			$refs = array_merge( array( (string) ( $entry['live']['ref'] ?? '' ) ), array_map( 'strval', array_column( $entry['candidates']['live'] ?? array(), 'ref' ) ) );
			if ( $kind === $entry['kind'] && in_array( $ref, $refs, true ) ) {
				return $entry;
			}
		}
		self::fail( "No entry for $kind $ref." );
	}

	/** The identity of an attachment, term or menu item of these parts (AB_MCP_Sync_Identity), in hex. */
	private static function identity( array $parts ): string {
		return bin2hex( \AB_MCP_Sync_Identity::part( $parts ) );
	}

	/** The identity of a post at the top, in hex. */
	private static function post_identity( string $type, string $theme, string $slug, string $lang ): string {
		return bin2hex( \AB_MCP_Sync_Identity::under( \AB_MCP_Sync_Identity::part( array( 'post', $type, $theme, $slug, $lang ) ), '' ) );
	}

	/** The entry of an object of B by kind and ref. */
	private static function entry_by_staging( array $entries, string $kind, string $ref ): array {
		foreach ( $entries as $entry ) {
			if ( $kind === $entry['kind'] && $ref === (string) ( $entry['staging']['ref'] ?? '' ) ) {
				return $entry;
			}
		}
		self::fail( "No entry for $kind $ref on staging." );
	}

	/** The entry of an object of A by kind and ref. */
	private static function entry_by_live( array $entries, string $kind, string $ref ): array {
		foreach ( $entries as $entry ) {
			if ( $kind === $entry['kind'] && $ref === (string) ( $entry['live']['ref'] ?? '' ) ) {
				return $entry;
			}
		}
		self::fail( "No entry for $kind $ref." );
	}

	public function testTheSameObjectsWithOtherIdsAreEqualOnceMapped(): void {
		self::pair();
		self::shift_ids_on_a();
		$ids    = self::structure();
		$answer = self::proposal( self::staged() );
		foreach ( array( 'eltern', 'kind', 'enkel', 'meldung', 'mit-bild' ) as $name ) {
			self::assertNotSame( $ids['a'][ $name ], $ids['b'][ $name ], $name . ': other ids on the two sites.' );
		}
		foreach ( array( 'kind' => 'post', 'enkel' => 'post', 'meldung' => 'post', 'mit-bild' => 'post', 'item' => 'menu_item', 'news' => 'term' ) as $name => $kind ) {
			$entry = self::entry_by_live( $answer['all'], $kind, $ids['a'][ $name ] );
			self::assertSame( 'equal', $entry['category'], $name . ': the same once its references name their targets.' );
			self::assertSame( $ids['b'][ $name ], $entry['staging']['ref'], $name );
		}
	}

	public function testAnotherTargetOfAReferenceIsADifference(): void {
		self::pair();
		self::shift_ids_on_a();
		$ids    = self::structure( 'other' );
		$answer = self::proposal( self::staged() );
		self::assertSame( 'differs', self::entry_by_live( $answer['all'], 'post', $ids['a']['meldung'] )['category'], 'In another category on staging.' );
		self::assertSame( 'equal', self::entry_by_live( $answer['all'], 'post', $ids['a']['kind'] )['category'] );
	}

	/**
	 * Codex round 3 of part 2, B: a proposal from the packages of the staging
	 * site that hold its posts but no term yet (the inventory in packages of
	 * four objects, every package with a term left out). «meldung» is in
	 * «news» of a confirmed pair and in «events» without a pair on both
	 * sites, with other ids: equal and active, as from the whole inventory,
	 * because a target without a pair is compared by the mapped form its
	 * site computed and a target of a pair by its pair, never by a line of
	 * the target. The terms themselves are not compared from such packages.
	 */
	public function testAPostInATermOfAPairAndOneWithoutIsEqualAlsoFromPackagesWithoutTheTerms(): void {
		self::pair();
		self::shift_ids_on_a();
		$ids    = self::structure();
		$events = (int) wp_insert_term( 'Events', 'category', array( 'slug' => 'events' ) )['term_id'];
		wp_set_object_terms( (int) $ids['a']['meldung'], array( (int) $ids['a']['news'], $events ), 'category' );
		self::b(
			array(
				array( 'op' => 'insert_term', 'name' => 'Events', 'taxonomy' => 'category', 'args' => array( 'slug' => 'events' ), 'as' => 'events' ),
				array( 'op' => 'set_terms', 'post' => $ids['b']['meldung'], 'terms' => array( $ids['b']['news'], '$events' ), 'taxonomy' => 'category' ),
			)
		);
		$pair = AB_MCP_Sync_Pairs::create(
			array(
				'source_id'    => AB_MCP_Sync_Anchor::active()['source_id'],
				'kind'         => 'term',
				'live_ref'     => $ids['a']['news'],
				'staging_ref'  => $ids['b']['news'],
				'canon'        => 'canon/1',
				'base_live'    => hash( 'sha256', 'l' ),
				'base_staging' => hash( 'sha256', 's' ),
				'base_origin'  => 'proposal',
				'state'        => 'active',
			)
		);
		self::assertIsArray( $pair, is_wp_error( $pair ) ? $pair->get_error_message() : '' );
		$without = array();
		$holds   = null;
		foreach ( self::export_b( 4 ) as $package ) {
			self::assertTrue( self::import( $package )['complete'] );
			$lines = AB_MCP_Sync_Proposal::staging( array( $package['package_id'] ) );
			self::assertIsArray( $lines, is_wp_error( $lines ) ? $lines->get_error_message() : '' );
			$kinds = array_column( $lines['lines'], 'kind' );
			if ( in_array( 'term', $kinds, true ) || in_array( 'menu', $kinds, true ) ) {
				continue;
			}
			$without[] = $package['package_id'];
			foreach ( $lines['lines'] as $line ) {
				if ( 'post' === $line['kind'] && $ids['b']['meldung'] === (string) $line['ref'] ) {
					$holds = $package['package_id'];
				}
			}
		}
		self::assertNotNull( $holds, 'The package of «meldung» holds a term as well: choose another count of objects per package (the posts of B are eight, four to a package).' );
		$answer = self::proposal( $without );
		$entry  = self::entry_by_live( $answer['all'], 'post', $ids['a']['meldung'] );
		self::assertSame( array( 'equal', 'active', $ids['b']['meldung'] ), array( $entry['category'], $entry['confirm'], (string) $entry['staging']['ref'] ), (string) json_encode( $entry ) );
		self::assertSame( 'not_compared', self::entry_by_live( $answer['all'], 'term', (string) $events )['category'], 'No term in these packages: the kind is not complete, so «events» here is not called only live.' );
		self::assertSame( array( 'equal', 'active' ), array_values( array_intersect_key( self::entry_by_live( self::proposal( self::staged() )['all'], 'post', $ids['a']['meldung'] ), array( 'category' => 1, 'confirm' => 1 ) ) ), 'From the whole inventory as well.' );
	}

	public function testATargetWithoutIdentityNamesNothingAlsoWhereBothSidesAreAlike(): void {
		self::pair();
		$draft = (int) wp_insert_post( wp_slash( self::page( '', 'A draft.', array( 'post_status' => 'draft' ) ) ) );
		$under = (int) wp_insert_post( wp_slash( self::page( 'unter-entwurf', 'Under a draft.', array( 'post_parent' => $draft ) ) ) );
		$gone  = (int) wp_insert_post( wp_slash( self::page( 'ohne-bild', 'An image that is gone.' ) ) );
		update_post_meta( $gone, '_thumbnail_id', '987654' );
		self::assertNull( get_post( 987654 ) );
		$menu = (int) wp_create_nav_menu( 'Haupt' );
		$item = (int) wp_update_nav_menu_item( $menu, 0, array( 'menu-item-object-id' => $draft, 'menu-item-object' => 'page', 'menu-item-type' => 'post_type', 'menu-item-status' => 'publish', 'menu-item-position' => 1 ) );
		self::b(
			array(
				array( 'op' => 'insert_post', 'args' => self::page( '', 'A draft.', array( 'post_status' => 'draft' ) ), 'as' => 'draft' ),
				array( 'op' => 'insert_post', 'args' => self::page( 'unter-entwurf', 'Under a draft.', array( 'post_parent' => '$draft' ) ), 'as' => 'under' ),
				array( 'op' => 'insert_post', 'args' => self::page( 'ohne-bild', 'An image that is gone.' ), 'as' => 'gone' ),
				array( 'op' => 'add_meta', 'post' => '$gone', 'key' => '_thumbnail_id', 'value' => '987654' ),
				array( 'op' => 'nav_menu', 'name' => 'Haupt', 'as' => 'menu' ),
				array( 'op' => 'nav_menu_item', 'menu' => '$menu', 'args' => array( 'menu-item-object-id' => '$draft', 'menu-item-object' => 'page', 'menu-item-type' => 'post_type', 'menu-item-status' => 'publish', 'menu-item-position' => 1 ), 'as' => 'item' ),
			)
		);
		$answer = self::proposal( self::staged() );
		$entry  = self::entry_with_live( $answer['all'], 'post', (string) $under );
		self::assertSame( 'uncertain', $entry['category'], 'A parent without a slug: the path is not known whole (Codex round 2, A-2).' );
		self::assertStringContainsString( 'path of its parents is not known whole', $entry['reason'] );
		self::assertSame( 'differs', self::entry_by_live( $answer['all'], 'post', (string) $gone )['category'], 'The same id of an object that is on neither site names nothing: no equal.' );
		self::assertSame( 'uncertain', self::entry_with_live( $answer['all'], 'menu_item', (string) $item )['category'], 'A menu item showing a page without a slug.' );
	}

	/* ------------------------------------- one target per identity (A-1) */

	public function testTwoHeadingsOfOneAddressAreTwoItemsAndAChildMovedUnderTheOtherIsNoEqual(): void {
		self::pair();
		$heading = static fn( string $title, int $position, int $parent = 0 ): array => array( 'menu-item-title' => $title, 'menu-item-url' => '#', 'menu-item-type' => 'custom', 'menu-item-status' => 'publish', 'menu-item-position' => $position, 'menu-item-parent-id' => $parent );
		$menu    = (int) wp_create_nav_menu( 'Haupt' );
		$privat  = (int) wp_update_nav_menu_item( $menu, 0, $heading( 'Privat', 1 ) );
		$firma   = (int) wp_update_nav_menu_item( $menu, 0, $heading( 'Firma', 2 ) );
		$konto   = (int) wp_update_nav_menu_item( $menu, 0, array( 'menu-item-title' => 'Konto', 'menu-item-url' => 'https://example.org/konto', 'menu-item-type' => 'custom', 'menu-item-status' => 'publish', 'menu-item-position' => 3, 'menu-item-parent-id' => $firma ) );
		// A second menu: one heading twice, the same address and label.
		$zweit   = (int) wp_create_nav_menu( 'Zweit' );
		$twin_1  = (int) wp_update_nav_menu_item( $zweit, 0, $heading( 'Privat', 1 ) );
		$twin_2  = (int) wp_update_nav_menu_item( $zweit, 0, $heading( 'Privat', 2 ) );
		$unter   = (int) wp_update_nav_menu_item( $zweit, 0, array( 'menu-item-title' => 'Unter', 'menu-item-url' => 'https://example.org/unter', 'menu-item-type' => 'custom', 'menu-item-status' => 'publish', 'menu-item-position' => 3, 'menu-item-parent-id' => $twin_2 ) );
		$custom  = static fn( string $title, int $position, string $url = '#', string $parent = '0' ): array => array( 'menu-item-title' => $title, 'menu-item-url' => $url, 'menu-item-type' => 'custom', 'menu-item-status' => 'publish', 'menu-item-position' => $position, 'menu-item-parent-id' => $parent );
		$b       = self::b(
			array(
				array( 'op' => 'nav_menu', 'name' => 'Haupt', 'as' => 'menu' ),
				array( 'op' => 'nav_menu_item', 'menu' => '$menu', 'args' => $custom( 'Privat', 1 ), 'as' => 'privat' ),
				array( 'op' => 'nav_menu_item', 'menu' => '$menu', 'args' => $custom( 'Firma', 2 ), 'as' => 'firma' ),
				// Under Privat on staging, under Firma here, at the same place.
				array( 'op' => 'nav_menu_item', 'menu' => '$menu', 'args' => $custom( 'Konto', 3, 'https://example.org/konto', '$privat' ), 'as' => 'konto' ),
				array( 'op' => 'nav_menu', 'name' => 'Zweit', 'as' => 'zweit' ),
				array( 'op' => 'nav_menu_item', 'menu' => '$zweit', 'args' => $custom( 'Privat', 1 ), 'as' => 'twin_1' ),
				array( 'op' => 'nav_menu_item', 'menu' => '$zweit', 'args' => $custom( 'Privat', 2 ), 'as' => 'twin_2' ),
				array( 'op' => 'nav_menu_item', 'menu' => '$zweit', 'args' => $custom( 'Unter', 3, 'https://example.org/unter', '$twin_2' ), 'as' => 'unter' ),
			)
		);
		$answer = self::proposal( self::staged() );
		foreach ( array( 'privat' => $privat, 'firma' => $firma ) as $name => $live ) {
			$entry = self::entry_by_live( $answer['all'], 'menu_item', (string) $live );
			self::assertSame( (string) $b[ $name ], $entry['staging']['ref'], $name . ': by its address and its label.' );
			self::assertSame( 'equal', $entry['category'], $name );
		}
		$moved = self::entry_by_live( $answer['all'], 'menu_item', (string) $konto );
		self::assertSame( 'live_only', $moved['category'], 'Under another heading: another item, never equal.' );
		self::assertSame( 'staging_only', self::entry_by_staging( $answer['all'], 'menu_item', (string) $b['konto'] )['category'] );
		// One heading twice: only its place tells them apart, which is no identity (Codex round 3, 1).
		$twins = self::entry_with_live( $answer['all'], 'menu_item', (string) $twin_1 );
		self::assertSame( 'uncertain', $twins['category'] );
		self::assertEqualsCanonicalizing( array( (string) $twin_1, (string) $twin_2 ), array_map( 'strval', array_column( $twins['candidates']['live'], 'ref' ) ) );
		$child = self::entry_with_live( $answer['all'], 'menu_item', (string) $unter );
		self::assertSame( 'uncertain', $child['category'], 'Under a heading that is there twice: its parent names nothing.' );
		self::assertStringContainsString( 'two links of one address and label', $child['reason'] );
	}

	public function testATermWhoseSlugIsThereTwiceNamesNothing(): void {
		global $wpdb;
		self::pair();
		$news = (int) wp_insert_term( 'News', 'category', array( 'slug' => 'news' ) )['term_id'];
		// The core gives a slug once per taxonomy; a table written otherwise has it twice.
		$wpdb->insert( $wpdb->terms, array( 'name' => 'News too', 'slug' => 'news', 'term_group' => 0 ) );
		$twin = (int) $wpdb->insert_id;
		$wpdb->insert( $wpdb->term_taxonomy, array( 'term_id' => $twin, 'taxonomy' => 'category', 'description' => '', 'parent' => 0, 'count' => 0 ) );
		clean_term_cache( array( $news, $twin ), 'category' );
		$post = (int) wp_insert_post( wp_slash( self::page( 'meldung', 'News item.', array( 'post_type' => 'post' ) ) ) );
		wp_set_object_terms( $post, array( $news ), 'category' );
		self::b(
			array(
				array( 'op' => 'insert_term', 'name' => 'News', 'taxonomy' => 'category', 'args' => array( 'slug' => 'news' ), 'as' => 'news' ),
				array( 'op' => 'insert_post', 'args' => self::page( 'meldung', 'News item.', array( 'post_type' => 'post' ) ), 'as' => 'meldung' ),
				array( 'op' => 'set_terms', 'post' => '$meldung', 'terms' => array( '$news' ), 'taxonomy' => 'category' ),
			)
		);
		$answer = self::proposal( self::staged() );
		self::assertSame( 'differs', self::entry_by_live( $answer['all'], 'post', (string) $post )['category'], 'Its category names nothing here: no equal.' );
		// The slug twice here is no identity (Codex round 4); both twins at hand, the
		// content of one there once on each side: that one is a pair (Codex round 5).
		self::assertSame( 'equal', self::entry_by_live( $answer['all'], 'term', (string) $news )['category'] );
		self::assertSame( array(), array_values( array_filter( $answer['all'], static fn( $e ) => 'term' === $e['kind'] && 'uncertain' === $e['category'] ) ) );
		self::assertNotContains( self::entry_by_live( $answer['all'], 'term', (string) $twin )['category'], array( 'equal', 'differs' ), 'The other one has no counterpart.' );
		\AB_MCP_Sync_Identity::reset();
		$ctx = \AB_MCP_Sync_Canon::context( array( home_url() ) );
		self::assertNull( \AB_MCP_Sync_Identity::term( $news, $ctx, 'category' ) );
		self::assertNull( \AB_MCP_Sync_Identity::term( $twin, $ctx ) );
	}

	public function testAnIdentityAnotherObjectOfTheSiteHasNamesNone(): void {
		global $wpdb;
		$ctx   = \AB_MCP_Sync_Canon::context( array( home_url(), site_url() ) );
		$named = static function ( array $args, string $name ) use ( $wpdb ): int {
			$id = (int) wp_insert_post( wp_slash( $args ) );
			// The slug as a table written otherwise holds it.
			$wpdb->update( $wpdb->posts, array( 'post_name' => $name ), array( 'ID' => $id ) );
			clean_post_cache( $id );
			return $id;
		};
		$one   = $named( self::page( 'kontakt', 'K' ), 'kontakt' );
		$two   = $named( self::page( 'kontakt-zwei', 'K2', array( 'post_status' => 'draft' ) ), 'kontakt' );
		$alone = $named( self::page( 'team', 'T' ), 'team' );
		// Under the twins: a page without an identity; under two other parents: two.
		$under = $named( self::page( 'unter-zwilling', 'U', array( 'post_parent' => $one ) ), 'unter-zwilling' );
		$infos = array();
		foreach ( array( 'a-eltern', 'b-eltern' ) as $parent ) {
			$up       = $named( self::page( $parent, 'P' ), $parent );
			$infos[]  = $named( self::page( 'info-' . $parent, 'I', array( 'post_parent' => $up ) ), 'info' );
		}
		$index = array();
		foreach ( array( 'theme-a', 'theme-b' ) as $theme ) {
			$index[ $theme ] = $named( array( 'post_type' => 'wp_template', 'post_status' => 'publish', 'post_title' => 'Index', 'post_name' => 'index' ), 'index' );
			wp_set_object_terms( $index[ $theme ], array( $theme ), 'wp_theme' );
		}
		$files = array();
		foreach ( array( 'f1' => '2026/10/same.jpg', 'f2' => '2026/10/same.jpg', 'f3' => '2026/10/other.jpg' ) as $key => $file ) {
			$files[ $key ] = (int) wp_insert_post( wp_slash( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_title' => $key, 'post_name' => $key, 'post_mime_type' => 'image/jpeg' ) ) );
			update_post_meta( $files[ $key ], '_wp_attached_file', $file );
		}
		\AB_MCP_Sync_Identity::reset();
		self::assertNull( \AB_MCP_Sync_Identity::post( $one, $ctx ), 'A slug twice under one parent.' );
		self::assertNull( \AB_MCP_Sync_Identity::post( $two, $ctx ) );
		self::assertSame( self::post_identity( 'page', '', 'team', '' ), \AB_MCP_Sync_Identity::post( $alone, $ctx ) );
		self::assertNull( \AB_MCP_Sync_Identity::post( $under, $ctx ), 'Under a parent without an identity.' );
		foreach ( $infos as $info ) {
			self::assertNotNull( \AB_MCP_Sync_Identity::post( $info, $ctx ), 'One slug under two parents: two identities.' );
		}
		self::assertSame( self::post_identity( 'wp_template', 'theme-a', 'index', '' ), \AB_MCP_Sync_Identity::post( $index['theme-a'], $ctx ), 'The template of another theme is another identity.' );
		self::assertNull( \AB_MCP_Sync_Identity::post( $files['f1'], $ctx ), 'One file twice.' );
		self::assertSame( self::identity( array( 'attachment', '2026/10/other.jpg', '' ) ), \AB_MCP_Sync_Identity::post( $files['f3'], $ctx ) );
		$again = $named( array( 'post_type' => 'wp_template', 'post_status' => 'publish', 'post_title' => 'Index', 'post_name' => 'index' ), 'index' );
		wp_set_object_terms( $again, array( 'theme-a' ), 'wp_theme' );
		\AB_MCP_Sync_Identity::reset();
		self::assertNull( \AB_MCP_Sync_Identity::post( $index['theme-a'], $ctx ), 'One template of one theme twice.' );
		self::assertNotNull( \AB_MCP_Sync_Identity::post( $index['theme-b'], $ctx ) );
	}

	public function testASubmenuIsEqualAndHeadingsTwiceAreUncertainInAnyOrder(): void {
		self::pair();
		$link = static fn( string $title, string $url, int $position, $parent = 0 ): array => array( 'menu-item-title' => $title, 'menu-item-url' => $url, 'menu-item-type' => 'custom', 'menu-item-status' => 'publish', 'menu-item-position' => $position, 'menu-item-parent-id' => $parent );
		$menu = (int) wp_create_nav_menu( 'Haupt' );
		$a    = array();
		$a['privat']  = (int) wp_update_nav_menu_item( $menu, 0, $link( 'Privat', '#', 1 ) );
		$a['kontakt'] = (int) wp_update_nav_menu_item( $menu, 0, $link( 'Kontakt', 'https://example.org/kontakt', 2, $a['privat'] ) );
		$a['hilfe']   = (int) wp_update_nav_menu_item( $menu, 0, $link( 'Hilfe', 'https://example.org/hilfe', 3, $a['privat'] ) );
		$a['firma']   = (int) wp_update_nav_menu_item( $menu, 0, $link( 'Firma', '#', 4 ) );
		$a['service'] = (int) wp_update_nav_menu_item( $menu, 0, $link( 'Service', '#', 5, $a['firma'] ) );
		$a['zwilling'] = (int) wp_update_nav_menu_item( $menu, 0, $link( 'Service', '#', 6, $a['firma'] ) );
		$a['anfrage'] = (int) wp_update_nav_menu_item( $menu, 0, $link( 'Anfrage', 'https://example.org/anfrage', 7, $a['zwilling'] ) );
		// On staging each level the other way round: other ids in another order.
		$b = self::b(
			array(
				array( 'op' => 'nav_menu', 'name' => 'Haupt', 'as' => 'menu' ),
				array( 'op' => 'nav_menu_item', 'menu' => '$menu', 'args' => $link( 'Firma', '#', 4 ), 'as' => 'firma' ),
				array( 'op' => 'nav_menu_item', 'menu' => '$menu', 'args' => $link( 'Privat', '#', 1 ), 'as' => 'privat' ),
				array( 'op' => 'nav_menu_item', 'menu' => '$menu', 'args' => $link( 'Service', '#', 6, '$firma' ), 'as' => 'zwilling' ),
				array( 'op' => 'nav_menu_item', 'menu' => '$menu', 'args' => $link( 'Service', '#', 5, '$firma' ), 'as' => 'service' ),
				array( 'op' => 'nav_menu_item', 'menu' => '$menu', 'args' => $link( 'Hilfe', 'https://example.org/hilfe', 3, '$privat' ), 'as' => 'hilfe' ),
				array( 'op' => 'nav_menu_item', 'menu' => '$menu', 'args' => $link( 'Kontakt', 'https://example.org/kontakt', 2, '$privat' ), 'as' => 'kontakt' ),
				array( 'op' => 'nav_menu_item', 'menu' => '$menu', 'args' => $link( 'Anfrage', 'https://example.org/anfrage', 7, '$zwilling' ), 'as' => 'anfrage' ),
			)
		);
		$answer = self::proposal( self::staged() );
		foreach ( array( 'privat', 'kontakt', 'hilfe', 'firma' ) as $name ) {
			$entry = self::entry_by_live( $answer['all'], 'menu_item', (string) $a[ $name ] );
			self::assertSame( (string) $b[ $name ], $entry['staging']['ref'], $name );
			self::assertSame( 'equal', $entry['category'], $name . ': a submenu of other items (Codex round 3, B1).' );
		}
		foreach ( array( 'service', 'zwilling', 'anfrage' ) as $name ) {
			self::assertSame( 'uncertain', self::entry_with_live( $answer['all'], 'menu_item', (string) $a[ $name ] )['category'], $name . ': no differs, no equal (Codex round 3, A2).' );
		}
		// The identities are the same whatever is asked first.
		$ctx   = \AB_MCP_Sync_Canon::context( array( home_url() ) );
		$ask   = static function ( array $ids ) use ( $ctx ): array {
			\AB_MCP_Sync_Identity::reset();
			$out = array();
			foreach ( $ids as $id ) {
				$out[ $id ] = array( \AB_MCP_Sync_Identity::post( $id, $ctx ), \AB_MCP_Sync_Identity::menu_item( $id, $ctx ) );
			}
			ksort( $out );
			return $out;
		};
		$first = $ask( array_values( $a ) );
		self::assertSame( $first, $ask( array_reverse( array_values( $a ) ) ) );
		self::assertNull( $first[ $a['service'] ][0] );
		self::assertNull( $first[ $a['anfrage'] ][1], 'Under a heading that is there twice: its line names no parent.' );
		self::assertNotNull( $first[ $a['hilfe'] ][0] );
	}

	public function testAValueIsMappedOnlyAsItIsWritten(): void {
		self::pair();
		self::shift_ids_on_a();
		$image = static fn( string $key ): array => array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_title' => $key, 'post_name' => $key, 'post_mime_type' => 'image/jpeg', 'post_author' => 1, 'post_date' => self::DATE, 'post_date_gmt' => self::DATE );
		$a     = array();
		foreach ( array( 'i1' => '2026/10/i1.jpg', 'i2' => '2026/10/i2.jpg' ) as $key => $file ) {
			$a[ $key ] = (int) wp_insert_post( wp_slash( $image( $key ) ) );
			update_post_meta( $a[ $key ], '_wp_attached_file', $file );
		}
		$pages = array(
			'null-davor'  => array( '0' . $a['i1'], '{$i1}' ),
			'mit-null'    => array( $a['i1'] . ',0,' . $a['i2'], '{$i1},{$i2}' ),
			'zahl-text'   => array( (string) wp_json_encode( array( 'image' => $a['i1'] ) ), '{"image":"{$i1}"}' ),
		);
		$steps = array();
		foreach ( array( 'i1' => '2026/10/i1.jpg', 'i2' => '2026/10/i2.jpg' ) as $key => $file ) {
			$steps[] = array( 'op' => 'insert_post', 'args' => $image( $key ), 'as' => $key );
			$steps[] = array( 'op' => 'add_meta', 'post' => '$' . $key, 'key' => '_wp_attached_file', 'value' => $file );
		}
		foreach ( $pages as $slug => list( $here, $there ) ) {
			$a[ $slug ] = (int) wp_insert_post( wp_slash( self::page( $slug, 'A field.' ) ) );
			update_post_meta( $a[ $slug ], 'feld', wp_slash( $here ) );
			$steps[] = array( 'op' => 'insert_post', 'args' => self::page( $slug, 'A field.' ), 'as' => str_replace( '-', '_', $slug ) );
			$steps[] = array( 'op' => 'add_meta', 'post' => '$' . str_replace( '-', '_', $slug ), 'key' => 'feld', 'value' => $there );
		}
		self::b( $steps );
		$answer = self::proposal( self::staged() );
		foreach ( array( 'null-davor' => '"007" against "7"', 'mit-null' => '"7,0,9" against "7,9"', 'zahl-text' => 'a number against a text' ) as $slug => $what ) {
			self::assertSame( 'differs', self::entry_by_live( $answer['all'], 'post', (string) $a[ $slug ] )['category'], $what . ' (Codex round 3, A1).' );
		}
	}

	public function testTranslationsOfPolylangSharingSlugsAndAFileAreEqualByLanguage(): void {
		self::pair();
		self::shift_ids_on_a();
		register_taxonomy( 'language', array( 'page', 'attachment' ), array( 'public' => false ) );
		try {
			$image = static fn( string $l ): array => array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_title' => 'Bild ' . $l, 'post_name' => 'bild-' . $l, 'post_mime_type' => 'image/jpeg', 'post_author' => 1, 'post_date' => self::DATE, 'post_date_gmt' => self::DATE );
			$a     = array();
			$steps = array( array( 'op' => 'register_taxonomy', 'name' => 'language', 'types' => array( 'page', 'attachment' ) ) );
			foreach ( array( 'de', 'en' ) as $l ) {
				$a[ 'lang_' . $l ] = (int) wp_insert_term( strtoupper( $l ), 'language', array( 'slug' => $l ) )['term_id'];
				$steps[]           = array( 'op' => 'insert_term', 'name' => strtoupper( $l ), 'taxonomy' => 'language', 'args' => array( 'slug' => $l ), 'as' => 'lang_' . $l );
			}
			foreach ( array( 'de', 'en' ) as $l ) {
				// One file, translated (Polylang: one attachment per language).
				$a[ 'bild_' . $l ] = (int) wp_insert_post( wp_slash( $image( $l ) ) );
				update_post_meta( $a[ 'bild_' . $l ], '_wp_attached_file', '2026/10/bild.jpg' );
				wp_set_object_terms( $a[ 'bild_' . $l ], array( $a[ 'lang_' . $l ] ), 'language' );
				$a[ 'kontakt_' . $l ] = (int) wp_insert_post( wp_slash( self::page( 'kontakt-' . $l, 'Kontakt ' . $l ) ) );
				$a[ 'team_' . $l ]    = (int) wp_insert_post( wp_slash( self::page( 'team-' . $l, 'Team ' . $l, array( 'post_parent' => $a[ 'kontakt_' . $l ] ) ) ) );
				foreach ( array( 'kontakt', 'team' ) as $page ) {
					// One slug for both translations (Polylang Pro, shared slugs).
					$GLOBALS['wpdb']->update( $GLOBALS['wpdb']->posts, array( 'post_name' => $page ), array( 'ID' => $a[ $page . '_' . $l ] ) );
					clean_post_cache( $a[ $page . '_' . $l ] );
					wp_set_object_terms( $a[ $page . '_' . $l ], array( $a[ 'lang_' . $l ] ), 'language' );
				}
				update_post_meta( $a[ 'kontakt_' . $l ], '_thumbnail_id', (string) $a[ 'bild_' . $l ] );
				$steps = array_merge(
					$steps,
					array(
						array( 'op' => 'insert_post', 'args' => $image( $l ), 'as' => 'bild_' . $l ),
						array( 'op' => 'add_meta', 'post' => '$bild_' . $l, 'key' => '_wp_attached_file', 'value' => '2026/10/bild.jpg' ),
						array( 'op' => 'set_terms', 'post' => '$bild_' . $l, 'terms' => array( '$lang_' . $l ), 'taxonomy' => 'language' ),
						array( 'op' => 'insert_post', 'args' => self::page( 'kontakt-' . $l, 'Kontakt ' . $l . ( 'en' === $l ? ', changed on staging' : '' ) ), 'as' => 'kontakt_' . $l ),
						array( 'op' => 'insert_post', 'args' => self::page( 'team-' . $l, 'Team ' . $l, array( 'post_parent' => '$kontakt_' . $l ) ), 'as' => 'team_' . $l ),
						array( 'op' => 'raw_slug', 'post' => '$kontakt_' . $l, 'slug' => 'kontakt' ),
						array( 'op' => 'raw_slug', 'post' => '$team_' . $l, 'slug' => 'team' ),
						array( 'op' => 'set_terms', 'post' => '$kontakt_' . $l, 'terms' => array( '$lang_' . $l ), 'taxonomy' => 'language' ),
						array( 'op' => 'set_terms', 'post' => '$team_' . $l, 'terms' => array( '$lang_' . $l ), 'taxonomy' => 'language' ),
						array( 'op' => 'add_meta', 'post' => '$kontakt_' . $l, 'key' => '_thumbnail_id', 'value' => '{$bild_' . $l . '}' ),
					)
				);
			}
			$b      = self::b( $steps );
			$answer = self::proposal( self::staged() );
			foreach ( array( 'kontakt_de' => 'equal', 'kontakt_en' => 'differs', 'team_de' => 'equal', 'team_en' => 'equal' ) as $name => $category ) {
				$entry = self::entry_by_live( $answer['all'], 'post', (string) $a[ $name ] );
				self::assertSame( (string) $b[ $name ], $entry['staging']['ref'], $name . ': by its language (Codex round 3, B2).' );
				self::assertSame( $category, $entry['category'], $name . ': its image, its parent by language.' );
			}
		} finally {
			unregister_taxonomy( 'language' );
		}
	}

	public function testTheLanguagesOfWpmlNameTranslationsApart(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'icl_translations';
		$wpdb->query( "CREATE TABLE `{$table}` ( translation_id bigint(20) NOT NULL AUTO_INCREMENT, element_type varchar(60) NOT NULL DEFAULT 'post_post', element_id bigint(20) DEFAULT NULL, trid bigint(20) NOT NULL, language_code varchar(7) NOT NULL, source_language_code varchar(7) DEFAULT NULL, PRIMARY KEY (translation_id) )" ); // phpcs:ignore WordPress.DB
		try {
			$files = array();
			foreach ( array( 'de', 'en' ) as $i => $l ) {
				$files[ $l ] = (int) wp_insert_post( wp_slash( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_title' => 'Bild ' . $l, 'post_name' => 'bild-' . $l, 'post_mime_type' => 'image/jpeg' ) ) );
				update_post_meta( $files[ $l ], '_wp_attached_file', '2026/10/bild.jpg' );
				$wpdb->insert( $table, array( 'element_type' => 'post_attachment', 'element_id' => $files[ $l ], 'trid' => 1, 'language_code' => $l ) );
			}
			$news = wp_insert_term( 'News', 'category', array( 'slug' => 'news' ) );
			$wpdb->insert( $table, array( 'element_type' => 'tax_category', 'element_id' => (int) $news['term_taxonomy_id'], 'trid' => 2, 'language_code' => 'de' ) );
			$ctx = \AB_MCP_Sync_Canon::context( array( home_url() ) );
			\AB_MCP_Sync_Identity::reset();
			self::assertSame( self::identity( array( 'attachment', '2026/10/bild.jpg', 'de' ) ), \AB_MCP_Sync_Identity::post( $files['de'], $ctx ), 'One file in two languages: two identities.' );
			self::assertSame( self::identity( array( 'attachment', '2026/10/bild.jpg', 'en' ) ), \AB_MCP_Sync_Identity::post( $files['en'], $ctx ) );
			self::assertSame( self::identity( array( 'term', 'category', 'news', 'de' ) ), \AB_MCP_Sync_Identity::term( (int) $news['term_id'], $ctx ) );
			self::assertSame( 'en', \AB_MCP_Sync_Identity::language( 'post', $files['en'], $ctx ) );
		} finally {
			$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB
			\AB_MCP_Sync_Identity::reset();
		}
	}

	/* --------------------------------------- possible references (B-1) */

	public function testImageIdsOfPluginsOnOtherIdsAreEqualAndAnotherImageDiffers(): void {
		self::pair();
		self::shift_ids_on_a();
		$files   = array( 'bild' => '2026/10/bild.jpg', 'g1' => '2026/10/g1.jpg', 'g2' => '2026/10/g2.jpg', 'anderes' => '2026/10/anderes.jpg' );
		$image   = static fn( string $key ): array => array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_title' => $key, 'post_name' => $key, 'post_mime_type' => 'image/jpeg', 'post_author' => 1, 'post_date' => self::DATE, 'post_date_gmt' => self::DATE );
		$product = array( 'post_type' => 'product', 'post_status' => 'publish', 'post_title' => 'Shirt', 'post_name' => 'shirt', 'post_content' => 'Cotton.', 'post_author' => 1, 'post_date' => self::DATE, 'post_date_gmt' => self::DATE );
		$builder = static fn( string $site, string $id ): string => (string) wp_json_encode( array( array( 'id' => '1a2b3c', 'elType' => 'widget', 'widgetType' => 'image', 'settings' => array( 'image' => array( 'url' => AB_MCP_IT_Sites::url( $site, '/wp-content/uploads/2026/10/bild.jpg' ), 'id' => (int) $id ) ) ) ) );
		$a       = array();
		foreach ( $files as $key => $file ) {
			$a[ $key ] = (int) wp_insert_post( wp_slash( $image( $key ) ) );
			update_post_meta( $a[ $key ], '_wp_attached_file', $file );
		}
		$a['acf'] = (int) wp_insert_post( wp_slash( self::page( 'acf-seite', 'With a field.' ) ) );
		update_post_meta( $a['acf'], 'hero_bild', (string) $a['bild'] );
		update_post_meta( $a['acf'], '_hero_bild', 'field_64ab12cd34ef6' );
		// A gallery field of ACF (serialized ids) and JSON of no producer of the core.
		update_post_meta( $a['acf'], 'galerie', array( (string) $a['g1'], (string) $a['g2'] ) );
		update_post_meta( $a['acf'], 'layout', '{"image": ' . $a['bild'] . '}' );
		$a['anders'] = (int) wp_insert_post( wp_slash( self::page( 'acf-anders', 'Another image.' ) ) );
		update_post_meta( $a['anders'], 'hero_bild', (string) $a['bild'] );
		update_post_meta( $a['anders'], '_hero_bild', 'field_64ab12cd34ef6' );
		$a['elementor'] = (int) wp_insert_post( wp_slash( self::page( 'elementor-seite', '' ) ) );
		update_post_meta( $a['elementor'], '_elementor_data', wp_slash( $builder( 'a', (string) $a['bild'] ) ) );
		update_post_meta( $a['elementor'], '_elementor_edit_mode', 'builder' );
		$a['shirt'] = (int) wp_insert_post( wp_slash( $product ) );
		update_post_meta( $a['shirt'], '_product_image_gallery', $a['g1'] . ',' . $a['g2'] );
		update_post_meta( $a['shirt'], '_price', '19.90' );
		$steps = array();
		foreach ( $files as $key => $file ) {
			$steps[] = array( 'op' => 'insert_post', 'args' => $image( $key ), 'as' => $key );
			$steps[] = array( 'op' => 'add_meta', 'post' => '$' . $key, 'key' => '_wp_attached_file', 'value' => $file );
		}
		$steps[] = array( 'op' => 'insert_post', 'args' => self::page( 'acf-seite', 'With a field.' ), 'as' => 'acf' );
		$steps[] = array( 'op' => 'add_meta', 'post' => '$acf', 'key' => 'hero_bild', 'value' => '{$bild}' );
		$steps[] = array( 'op' => 'add_meta', 'post' => '$acf', 'key' => '_hero_bild', 'value' => 'field_64ab12cd34ef6' );
		$steps[] = array( 'op' => 'add_meta', 'post' => '$acf', 'key' => 'galerie', 'value' => array( '{$g1}', '{$g2}' ) );
		$steps[] = array( 'op' => 'add_meta', 'post' => '$acf', 'key' => 'layout', 'value' => '{"image": {$bild}}' );
		$steps[] = array( 'op' => 'insert_post', 'args' => self::page( 'acf-anders', 'Another image.' ), 'as' => 'anders' );
		$steps[] = array( 'op' => 'add_meta', 'post' => '$anders', 'key' => 'hero_bild', 'value' => '{$anderes}' );
		$steps[] = array( 'op' => 'add_meta', 'post' => '$anders', 'key' => '_hero_bild', 'value' => 'field_64ab12cd34ef6' );
		$steps[] = array( 'op' => 'insert_post', 'args' => self::page( 'elementor-seite', '' ), 'as' => 'elementor' );
		$steps[] = array( 'op' => 'add_meta', 'post' => '$elementor', 'key' => '_elementor_edit_mode', 'value' => 'builder' );
		$steps[] = array( 'op' => 'insert_post', 'args' => $product, 'as' => 'shirt' );
		$steps[] = array( 'op' => 'add_meta', 'post' => '$shirt', 'key' => '_product_image_gallery', 'value' => '{$g1},{$g2}' );
		$steps[] = array( 'op' => 'add_meta', 'post' => '$shirt', 'key' => '_price', 'value' => '19.90' );
		$b       = self::b( $steps );
		// The JSON of the builder with the id of the image on B.
		self::b( array( array( 'op' => 'add_meta', 'post' => (int) $b['elementor'], 'key' => '_elementor_data', 'value' => $builder( 'b', (string) $b['bild'] ) ) ) );
		self::assertNotSame( (string) $a['bild'], (string) $b['bild'], 'Other ids of the images on the two sites.' );
		$answer = self::proposal( self::staged() );
		foreach ( array( 'acf' => 'An image and a gallery field of ACF, an image in JSON', 'elementor' => 'settings.image.id of Elementor', 'shirt' => 'The gallery of a product' ) as $name => $what ) {
			$entry = self::entry_by_live( $answer['all'], 'post', (string) $a[ $name ] );
			self::assertSame( (string) $b[ $name ], $entry['staging']['ref'], $what );
			self::assertSame( 'equal', $entry['category'], $what . ': the same image on other ids.' );
		}
		self::assertSame( 'differs', self::entry_by_live( $answer['all'], 'post', (string) $a['anders'] )['category'], 'Another image: other content.' );
	}

	public function testAMenuSortedAnewOnStagingPairsEachItemWithItsTarget(): void {
		self::pair();
		$a          = self::pages_on_a( array( 'ueber-uns' => 'U', 'kontakt' => 'K' ) );
		$menu       = (int) wp_create_nav_menu( 'Haupt' );
		$item_ueber = (int) wp_update_nav_menu_item( $menu, 0, array( 'menu-item-object-id' => (int) $a['ueber-uns'], 'menu-item-object' => 'page', 'menu-item-type' => 'post_type', 'menu-item-status' => 'publish', 'menu-item-position' => 1 ) );
		$item_kont  = (int) wp_update_nav_menu_item( $menu, 0, array( 'menu-item-object-id' => (int) $a['kontakt'], 'menu-item-object' => 'page', 'menu-item-type' => 'post_type', 'menu-item-status' => 'publish', 'menu-item-position' => 2 ) );
		$b          = self::b(
			array(
				array( 'op' => 'insert_post', 'args' => self::page( 'ueber-uns', 'U' ), 'as' => 'ueber' ),
				array( 'op' => 'insert_post', 'args' => self::page( 'kontakt', 'K' ), 'as' => 'kontakt' ),
				array( 'op' => 'nav_menu', 'name' => 'Haupt', 'as' => 'menu' ),
				array( 'op' => 'nav_menu_item', 'menu' => '$menu', 'args' => array( 'menu-item-object-id' => '$kontakt', 'menu-item-object' => 'page', 'menu-item-type' => 'post_type', 'menu-item-status' => 'publish', 'menu-item-position' => 1 ), 'as' => 'item_kont' ),
				array( 'op' => 'nav_menu_item', 'menu' => '$menu', 'args' => array( 'menu-item-object-id' => '$ueber', 'menu-item-object' => 'page', 'menu-item-type' => 'post_type', 'menu-item-status' => 'publish', 'menu-item-position' => 2 ), 'as' => 'item_ueber' ),
			)
		);
		$answer = self::proposal( self::staged() );
		foreach ( array( 'item_ueber' => $item_ueber, 'item_kont' => $item_kont ) as $name => $live ) {
			$entry = self::entry_by_live( $answer['all'], 'menu_item', (string) $live );
			self::assertSame( (string) $b[ $name ], $entry['staging']['ref'], $name . ': paired with the item of the same target, not the same position.' );
			self::assertSame( 'differs', $entry['category'], $name . ': its position differs.' );
		}
	}

	public function testACustomLinkIsNamedByItsAddress(): void {
		self::pair();
		$menu  = (int) wp_create_nav_menu( 'Haupt' );
		$items = array();
		foreach ( array( 'own' => AB_MCP_IT_Sites::url( 'a', '/kontakt/' ), 'external' => 'https://example.org/partner' ) as $name => $url ) {
			// One label for both: the address tells them apart.
			$items[ $name ] = (int) wp_update_nav_menu_item( $menu, 0, array( 'menu-item-title' => 'Link', 'menu-item-url' => $url, 'menu-item-type' => 'custom', 'menu-item-status' => 'publish', 'menu-item-position' => 'own' === $name ? 1 : 2 ) );
		}
		$b      = self::b(
			array(
				array( 'op' => 'nav_menu', 'name' => 'Haupt', 'as' => 'menu' ),
				array( 'op' => 'nav_menu_item', 'menu' => '$menu', 'args' => array( 'menu-item-title' => 'Link', 'menu-item-url' => 'https://example.org/partner', 'menu-item-type' => 'custom', 'menu-item-status' => 'publish', 'menu-item-position' => 1 ), 'as' => 'external' ),
				array( 'op' => 'nav_menu_item', 'menu' => '$menu', 'args' => array( 'menu-item-title' => 'Link', 'menu-item-url' => AB_MCP_IT_Sites::url( 'b', '/kontakt/' ), 'menu-item-type' => 'custom', 'menu-item-status' => 'publish', 'menu-item-position' => 2 ), 'as' => 'own' ),
			)
		);
		$answer = self::proposal( self::staged() );
		foreach ( $items as $name => $live ) {
			$entry = self::entry_by_live( $answer['all'], 'menu_item', (string) $live );
			self::assertSame( (string) $b[ $name ], $entry['staging']['ref'], $name . ': by its address, the address of the own site neutral on both.' );
		}
	}

	/* ------------------------------------------------ no pair by exclusion */

	public function testPagesOnOtherPathsAndDraftsWithoutASlugAreOnlyChosen(): void {
		self::pair();
		$a = self::pages_on_a( array( 'team' => 'T', 'shop' => 'S' ) );
		self::assertIsInt( wp_insert_post( wp_slash( self::page( 'kontakt', 'Kontakt', array( 'post_parent' => (int) $a['shop'] ) ) ) ) );
		self::assertIsInt( wp_insert_post( wp_slash( self::page( '', 'A draft.', array( 'post_status' => 'draft' ) ) ) ) );
		self::b(
			array(
				array( 'op' => 'insert_post', 'args' => self::page( 'team', 'T' ), 'as' => 'team' ),
				array( 'op' => 'insert_post', 'args' => self::page( 'shop', 'S' ), 'as' => 'shop' ),
				array( 'op' => 'insert_post', 'args' => self::page( 'kontakt', 'Kontakt', array( 'post_parent' => '$team' ) ), 'as' => 'kontakt' ),
				array( 'op' => 'insert_post', 'args' => self::page( '', 'Another draft.', array( 'post_status' => 'draft' ) ), 'as' => 'draft' ),
			)
		);
		$answer    = self::proposal( self::staged() );
		$uncertain = array_values( array_filter( $answer['all'], static fn( $e ) => 'uncertain' === $e['category'] && 'page' === $e['subtype'] ) );
		self::assertCount( 2, $uncertain, '/team/kontakt and /shop/kontakt; the two drafts.' );
		$kontakt = array_values( array_filter( $uncertain, static fn( $e ) => 'kontakt' === $e['candidates']['staging'][0]['identity']['post_name'] ) )[0];
		self::assertSame( array( 'team' ), $kontakt['candidates']['staging'][0]['path'] );
		self::assertSame( array( 'shop' ), $kontakt['candidates']['live'][0]['path'] );
		$confirm = self::gated( array( 'action' => 'confirm', 'proposal_id' => $answer['proposal_id'], 'categories' => array( 'equal', 'differs' ) ) );
		self::assertIsArray( $confirm );
		foreach ( $confirm['preview']['pairs'] as $op ) {
			self::assertNotSame( 'kontakt', $op['identity']['live']['post_name'] ?? null, 'Never confirmed by category.' );
			self::assertNotSame( '', $op['identity']['live']['post_name'] ?? null );
		}
	}

	/**
	 * Codex round 2 of part 2, b: the page «team» of a pair is renamed
	 * «team-alt» here, and another page takes «team». «kontakt», new on both
	 * sites, on staging under the page of the pair, here under the other one,
	 * has the same mapped form on both: uncertain, never equal, and chosen by
	 * a person unclear. «faq» under «shop» of a pair on both sites is equal.
	 */
	public function testAPageUnderThePageOfAPairThereAndAnotherHereIsOnlyChosenAndThenUnclear(): void {
		self::pair();
		$a      = self::pages_on_a( array( 'team-alt' => 'T', 'team' => 'T2', 'shop' => 'S' ) );
		$b      = self::pages_on_b( array( 'team' => 'T', 'shop' => 'S' ) );
		$source = AB_MCP_Sync_Anchor::active()['source_id'];
		foreach ( array( array( $a['team-alt'], $b['team'] ), array( $a['shop'], $b['shop'] ) ) as list( $live, $staging ) ) {
			$pair = AB_MCP_Sync_Pairs::create(
				array(
					'source_id'    => $source,
					'kind'         => 'post',
					'live_ref'     => $live,
					'staging_ref'  => $staging,
					'canon'        => 'canon/1',
					'base_live'    => hash( 'sha256', 'l' . $live ),
					'base_staging' => hash( 'sha256', 's' . $staging ),
					'base_origin'  => 'proposal',
					'state'        => 'active',
				)
			);
			self::assertIsArray( $pair, is_wp_error( $pair ) ? $pair->get_error_message() : '' );
		}
		$a_kontakt = wp_insert_post( wp_slash( self::page( 'kontakt', 'Kontakt', array( 'post_parent' => (int) $a['team'] ) ) ), true );
		$a_faq     = wp_insert_post( wp_slash( self::page( 'faq', 'FAQ', array( 'post_parent' => (int) $a['shop'] ) ) ), true );
		self::assertIsInt( $a_kontakt );
		self::assertIsInt( $a_faq );
		$kids    = self::b(
			array(
				array( 'op' => 'insert_post', 'args' => self::page( 'kontakt', 'Kontakt', array( 'post_parent' => (int) $b['team'] ) ), 'as' => 'kontakt' ),
				array( 'op' => 'insert_post', 'args' => self::page( 'faq', 'FAQ', array( 'post_parent' => (int) $b['shop'] ) ), 'as' => 'faq' ),
			)
		);
		$answer  = self::proposal( self::staged() );
		$kontakt = self::entry_with_live( $answer['all'], 'post', (string) $a_kontakt );
		self::assertSame( 'uncertain', $kontakt['category'], (string) json_encode( $kontakt ) );
		self::assertSame( array( (string) $kids['kontakt'] ), array_map( 'strval', array_column( $kontakt['candidates']['staging'], 'ref' ) ) );
		self::assertSame( array( array( 'team' ), array( 'team' ) ), array( $kontakt['candidates']['staging'][0]['path'], $kontakt['candidates']['live'][0]['path'] ), 'The same path by the slugs.' );
		self::assertStringContainsString( 'the object of a pair on one side and another object on the other', $kontakt['reason'] );
		$faq = self::entry_with_live( $answer['all'], 'post', (string) $a_faq );
		self::assertSame( array( 'equal', 'active' ), array( $faq['category'], $faq['confirm'] ), 'Under the pages of a pair on both sites.' );
		self::assertStringNotContainsString( '"places"', (string) json_encode( $answer ), 'What the comparison keeps of the references is not shown.' );
		// Chosen by a person: unclear (the report asks which side wins), not active.
		$done = self::confirmed(
			array(
				'action'      => 'confirm',
				'proposal_id' => $answer['proposal_id'],
				'choices'     => array( array( 'entry' => $kontakt['n'], 'staging_ref' => (string) $kids['kontakt'], 'live_ref' => (string) $a_kontakt ) ),
				'select'      => array( $faq['n'] ),
			)
		);
		self::assertIsArray( $done, is_wp_error( $done ) ? $done->get_error_message() : '' );
		$states = array();
		foreach ( self::pair_rows() as $row ) {
			$states[ (string) $row['live_ref'] ] = $row['state'];
		}
		self::assertSame( array( 'unclear', 'active' ), array( $states[ (string) $a_kontakt ] ?? null, $states[ (string) $a_faq ] ?? null ) );
	}

	/* ------------------------------------------- the whole parent (round 4) */

	public function testAPageUnderAParentOfAnotherLanguageIsAnotherPathNeverDiffers(): void {
		self::pair();
		register_taxonomy( 'language', array( 'page' ), array( 'public' => false ) );
		try {
			// kontakt under team/en here, under team/de on staging (Codex round 4).
			$en   = (int) wp_insert_term( 'EN', 'language', array( 'slug' => 'en' ) )['term_id'];
			$team = (int) wp_insert_post( wp_slash( self::page( 'team', 'Team' ) ) );
			wp_set_object_terms( $team, array( $en ), 'language' );
			$kontakt = (int) wp_insert_post( wp_slash( self::page( 'kontakt', 'Kontakt', array( 'post_parent' => $team ) ) ) );
			$b       = self::b(
				array(
					array( 'op' => 'register_taxonomy', 'name' => 'language', 'types' => array( 'page' ) ),
					array( 'op' => 'insert_term', 'name' => 'DE', 'taxonomy' => 'language', 'args' => array( 'slug' => 'de' ), 'as' => 'de' ),
					array( 'op' => 'insert_post', 'args' => self::page( 'team', 'Team' ), 'as' => 'team' ),
					array( 'op' => 'set_terms', 'post' => '$team', 'terms' => array( '$de' ), 'taxonomy' => 'language' ),
					array( 'op' => 'insert_post', 'args' => self::page( 'kontakt', 'Kontakt', array( 'post_parent' => '$team' ) ), 'as' => 'kontakt' ),
				)
			);
			$answer = self::proposal( self::staged() );
			$entry  = self::entry_with_live( $answer['all'], 'post', (string) $kontakt );
			self::assertSame( 'uncertain', $entry['category'], 'Another path: never differs.' );
			self::assertSame( array( (string) $b['kontakt'] ), array_map( 'strval', array_column( $entry['candidates']['staging'], 'ref' ) ) );
			self::assertStringContainsString( 'a parent of another type, theme or language', $entry['reason'] );
			self::assertSame( 'live_only', self::entry_by_live( $answer['all'], 'post', (string) $team )['category'], 'team/en and team/de are two pages.' );
		} finally {
			unregister_taxonomy( 'language' );
		}
	}

	/* ------------------------------------------- twins of a site (round 4) */

	public function testATwinIsUncertainInAPackageOfItsOwnAndWhatIsLeftAfterAChoiceToo(): void {
		global $wpdb;
		self::pair();
		$heading = array( 'menu-item-title' => 'Privat', 'menu-item-url' => '#', 'menu-item-type' => 'custom', 'menu-item-status' => 'publish' );
		$menu    = (int) wp_create_nav_menu( 'Haupt' );
		$a_item  = (int) wp_update_nav_menu_item( $menu, 0, $heading + array( 'menu-item-position' => 1 ) );
		$a_dup   = (int) wp_insert_post( wp_slash( self::page( 'dup', 'Dup' ) ) );
		$b       = self::b(
			array(
				array( 'op' => 'nav_menu', 'name' => 'Haupt', 'as' => 'menu' ),
				array( 'op' => 'nav_menu_item', 'menu' => '$menu', 'args' => $heading + array( 'menu-item-position' => 1 ), 'as' => 'item_1' ),
				array( 'op' => 'nav_menu_item', 'menu' => '$menu', 'args' => $heading + array( 'menu-item-position' => 2 ), 'as' => 'item_2' ),
				array( 'op' => 'insert_post', 'args' => self::page( 'dup', 'Dup' ), 'as' => 'dup_1' ),
				array( 'op' => 'insert_post', 'args' => self::page( 'dup-2', 'Dup' ), 'as' => 'dup_2' ),
				array( 'op' => 'raw_slug', 'post' => '$dup_2', 'slug' => 'dup' ),
			)
		);
		// A package that holds one twin of each: its line says twin (Codex round 4).
		$staged = self::staged();
		self::assertCount( 1, $staged );
		$lines = array();
		foreach ( AB_MCP_Sync_Import::taken( $staged[0] )['lines'] as $line ) {
			if ( in_array( $line['kind'] . ':' . $line['ref'], array( 'menu_item:' . $b['item_2'], 'post:' . $b['dup_2'] ), true ) ) {
				continue;
			}
			if ( in_array( $line['kind'] . ':' . $line['ref'], array( 'menu_item:' . $b['item_1'], 'post:' . $b['dup_1'] ), true ) ) {
				self::assertSame( 2, $line['twin'] ?? null, $line['kind'] . ': the line of a twin says so, with how many its site has (Codex round 5).' );
			}
			$lines[] = $line;
		}
		$answer = self::proposal( array( self::crafted( $lines, time() )['id'] ) );
		foreach ( array( 'menu_item' => array( $a_item, $b['item_1'] ), 'post' => array( $a_dup, $b['dup_1'] ) ) as $kind => list( $live, $staging ) ) {
			$entry = self::entry_with_live( $answer['all'], $kind, (string) $live );
			self::assertSame( 'uncertain', $entry['category'], $kind . ': one of two on staging, never equal.' );
			self::assertSame( array( (string) $staging ), array_map( 'strval', array_column( $entry['candidates']['staging'], 'ref' ) ) );
		}
		// Twins on both sites, both pages changed here (so no content pairs them,
		// Codex round 5): a person pairs one of them; what is left stays
		// uncertain, also once its content is the same again.
		$a_item_2 = (int) wp_update_nav_menu_item( $menu, 0, $heading + array( 'menu-item-position' => 2 ) );
		$a_dup_2  = (int) wp_insert_post( wp_slash( self::page( 'dup-2', 'Dup here' ) ) );
		$wpdb->update( $wpdb->posts, array( 'post_name' => 'dup' ), array( 'ID' => $a_dup_2 ) );
		$wpdb->update( $wpdb->posts, array( 'post_content' => 'Dup here' ), array( 'ID' => $a_dup ) );
		clean_post_cache( $a_dup );
		clean_post_cache( $a_dup_2 );
		$staged  = self::staged();
		$answer  = self::proposal( $staged );
		$choices = array();
		foreach ( array( 'menu_item' => array( $a_item, $b['item_1'] ), 'post' => array( $a_dup, $b['dup_1'] ) ) as $kind => list( $live, $staging ) ) {
			$entry = self::entry_with_live( $answer['all'], $kind, (string) $live );
			self::assertSame( 'uncertain', $entry['category'], $kind );
			self::assertCount( 2, $entry['candidates']['staging'], $kind );
			$choices[] = array( 'entry' => $entry['n'], 'staging_ref' => (string) $staging, 'live_ref' => (string) $live );
		}
		$done = self::confirmed( array( 'action' => 'confirm', 'proposal_id' => $answer['proposal_id'], 'choices' => $choices ) );
		self::assertIsArray( $done, is_wp_error( $done ) ? $done->get_error_message() : '' );
		$wpdb->update( $wpdb->posts, array( 'post_content' => 'Dup' ), array( 'ID' => $a_dup_2 ) );
		clean_post_cache( $a_dup_2 );
		$again = self::proposal( $staged );
		foreach ( array( 'menu_item' => array( $a_item_2, $b['item_2'] ), 'post' => array( $a_dup_2, $b['dup_2'] ) ) as $kind => list( $live, $staging ) ) {
			$entry = self::entry_with_live( $again['all'], $kind, (string) $live );
			self::assertSame( 'uncertain', $entry['category'], $kind . ': the rest after a choice, never equal.' );
			self::assertSame( array( (string) $staging ), array_map( 'strval', array_column( $entry['candidates']['staging'], 'ref' ) ) );
		}
	}

	public function testAPublishedPageAndADraftOfItsSlugArePairedByTheirContent(): void {
		self::pair();
		// WordPress keeps the slug of a draft as it is given (wp_unique_post_slug()): a copy of a page as a draft.
		$a_page  = (int) wp_insert_post( wp_slash( self::page( 'kontakt', 'Kontakt' ) ) );
		$a_draft = (int) wp_insert_post( wp_slash( self::page( 'kontakt', 'Kontakt, neu', array( 'post_status' => 'draft' ) ) ) );
		self::assertSame( 'kontakt', get_post( $a_draft )->post_name, 'The draft keeps the slug of the page.' );
		$draft = self::page( 'kontakt', 'Kontakt, neu', array( 'post_status' => 'draft' ) );
		$b     = self::b(
			array(
				array( 'op' => 'insert_post', 'args' => self::page( 'kontakt', 'Kontakt' ), 'as' => 'page' ),
				array( 'op' => 'insert_post', 'args' => $draft, 'as' => 'draft' ),
			)
		);
		$staged = self::staged();
		foreach ( AB_MCP_Sync_Import::taken( $staged[0] )['lines'] as $line ) {
			if ( 'post' === $line['kind'] && in_array( (string) $line['ref'], array( (string) $b['page'], (string) $b['draft'] ), true ) ) {
				self::assertSame( 2, $line['twin'] ?? null, 'Each line says how many objects of its site have its identity.' );
			}
		}
		// Both unchanged: two pairs, each by its content (Codex round 5).
		$answer = self::proposal( $staged );
		foreach ( array( 'page' => $a_page, 'draft' => $a_draft ) as $name => $live ) {
			$entry = self::entry_by_live( $answer['all'], 'post', (string) $live );
			self::assertSame( 'equal', $entry['category'], $name );
			self::assertSame( (string) $b[ $name ], (string) $entry['staging']['ref'], $name );
		}
		// The draft changed on staging: the page is equal, the draft only a choice, never differs.
		self::b( array( array( 'op' => 'insert_post', 'args' => array( 'ID' => (int) $b['draft'], 'post_content' => 'Kontakt, neuer' ) + $draft ) ) );
		$answer = self::proposal( self::staged() );
		$page   = self::entry_by_live( $answer['all'], 'post', (string) $a_page );
		self::assertSame( array( 'equal', (string) $b['page'] ), array( $page['category'], (string) $page['staging']['ref'] ) );
		$entry = self::entry_with_live( $answer['all'], 'post', (string) $a_draft );
		self::assertSame( 'uncertain', $entry['category'] );
		self::assertSame( array( (string) $b['draft'] ), array_map( 'strval', array_column( $entry['candidates']['staging'], 'ref' ) ) );
		self::assertSame( array( (string) $a_draft ), array_map( 'strval', array_column( $entry['candidates']['live'], 'ref' ) ) );
		// Confirmed by category: the page only.
		$done = self::confirmed( array( 'action' => 'confirm', 'proposal_id' => $answer['proposal_id'], 'categories' => array( 'equal', 'differs' ) ) );
		self::assertIsArray( $done, is_wp_error( $done ) ? $done->get_error_message() : '' );
		$rows = array_values( array_filter( self::pair_rows(), static fn( $row ) => 'post' === $row['kind'] && in_array( (string) $row['live_ref'], array( (string) $a_page, (string) $a_draft ), true ) ) );
		self::assertSame( array( array( (string) $a_page, (string) $b['page'], 'active' ) ), array_map( static fn( $row ) => array( (string) $row['live_ref'], (string) $row['staging_ref'], $row['state'] ), $rows ) );
	}

	/* --------------------------------- the index of identities in parts (round 4, B) */

	/** Out of time after each page of the index of identities, and only there. */
	private static function slow_index(): void {
		AB_MCP_Sync_Inventory::$clock = static function (): float {
			foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ) as $frame ) {
				if ( 'AB_MCP_Sync_Identity' === ( $frame['class'] ?? '' ) && 'build' === $frame['function'] ) {
					return microtime( true ) + 3600;
				}
			}
			return microtime( true );
		};
	}

	/** The categories of a proposal by the refs of their sides. */
	private static function by_refs( array $entries ): array {
		$out = array();
		foreach ( $entries as $entry ) {
			$refs = array();
			foreach ( array( 'staging', 'live' ) as $side ) {
				$refs[] = (string) ( $entry[ $side ]['ref'] ?? '' );
				foreach ( $entry['candidates'][ $side ] ?? array() as $candidate ) {
					$refs[] = (string) ( $candidate['ref'] ?? '' );
				}
			}
			$out[ $entry['kind'] . ' ' . implode( ',', $refs ) ] = $entry['category'];
		}
		ksort( $out );
		return $out;
	}

	public function testTheIndexOfIdentitiesOfAProposalIsBuiltInPartsAndOnce(): void {
		self::pair();
		self::structure();
		$staged = self::staged();
		$once   = self::by_refs( self::proposal( $staged )['all'] );
		// A page of the index a call, at most 20 objects a call after it, the
		// index kept in slices of 256 bytes.
		self::slow_index();
		AB_MCP_Sync_Proposal::$limit = 20;
		AB_MCP_Sync_Identity::$slice = 256;
		$asked = 0;
		add_filter(
			'query',
			static function ( $q ) use ( &$asked ) {
				$asked += false !== strpos( (string) $q, "post_type <> 'revision'" ) ? 1 : 0;
				return $q;
			}
		);
		$answer = self::here( array( 'action' => 'propose', 'packages' => $staged ) );
		$calls  = 1;
		$built  = 0;
		while ( is_array( $answer ) && false === $answer['done'] ) {
			if ( $answer['live']['objects'] > 0 && 0 === $built ) {
				$built = $calls;
				$asked = 0;
			}
			$answer = self::here( array( 'action' => 'propose', 'proposal_id' => $answer['proposal_id'] ) );
			++$calls;
			self::assertLessThan( 200, $calls );
		}
		self::assertIsArray( $answer, is_wp_error( $answer ) ? $answer->get_error_message() : '' );
		self::assertGreaterThan( 5, $built, 'The index over several calls before the first object.' );
		self::assertGreaterThan( $built + 1, $calls, 'The objects over calls after it.' );
		self::assertSame( 0, $asked, 'Once done, the index is taken up, never built again.' );
		$seqs = array();
		foreach ( array_keys( AB_MCP_Protected_Store::files( 'reports', $answer['proposal_id'] ) ) as $name ) {
			$parsed = AB_MCP_Sync_Proposal::parse_name( (string) $name );
			if ( 'i' === ( $parsed['prefix'] ?? '' ) ) {
				$seqs[] = $parsed['seq'];
			}
		}
		self::assertCount( $built, array_unique( $seqs ), 'An index a call that built on it, none after.' );
		self::assertGreaterThan( $built, count( $seqs ), 'The larger ones in slices.' );
		$all = self::proposal( $staged );
		AB_MCP_Sync_Inventory::$clock = null;
		self::assertSame( $once, self::by_refs( $all['all'] ), 'The same proposal as with the index of one call.' );
	}

	public function testAnExportBuildsTheIndexInPartsAndItsSeriesTakesItUp(): void {
		self::pair();
		self::structure();
		// Packages of 40 objects (a kind read across packages is complete in none).
		$once = self::by_refs( self::proposal( self::staged( 40 ) )['all'] );
		$run  = static function ( ?array $cursor ): array {
			$step = array( 'op' => 'export', 'purpose' => 'inventory', 'as' => 'package', 'slow_index' => true, 'objects' => 40, 'index_slice' => 256, 'expect' => 'error' );
			if ( null !== $cursor ) {
				$step['cursor'] = $cursor;
			}
			return (array) self::b( array( $step ) )['package'];
		};
		$packages = array();
		$cursor   = null;
		do {
			$package    = $run( $cursor );
			self::assertArrayHasKey( 'package_id', $package, (string) json_encode( $package ) );
			$packages[] = $package;
			$cursor     = $package['cursor'];
		} while ( null !== $cursor && count( $packages ) < 60 );
		self::assertNull( $cursor );
		$first = 0;
		while ( 0 === $packages[ $first ]['objects'] ) {
			self::assertSame( array(), $packages[ $first ]['kinds'], 'A package of the index only names no kind.' );
			++$first;
		}
		self::assertGreaterThan( 5, $first, 'The first calls build the index only.' );
		self::assertGreaterThan( $first + 1, count( $packages ), 'Packages of objects after it.' );
		$ids = array();
		foreach ( $packages as $package ) {
			self::assertTrue( self::import( $package )['complete'] );
			$ids[] = $package['package_id'];
		}
		self::assertSame( $once, self::by_refs( self::proposal( $ids )['all'] ), 'The same proposal as from a series with the index in one call.' );
		// The index is kept with the package in which it was done; a series
		// whose package of the index is gone does not go on.
		self::b( array( array( 'op' => 'store_remove', 'area' => 'out', 'gid' => $packages[ $first ]['package_id'], 'as' => 'gone' ) ) );
		$refused = $run( $packages[ $first + 1 ]['cursor'] );
		self::assertSame( 'ab_mcp_sync_export_index', $refused['code'] ?? null, (string) json_encode( $refused ) );
	}

	public function testAPackageDeclaresTheBytesOfAnIndexOfSomeMegabytes(): void {
		self::pair();
		// 55 000 attachments: an index of some 2.4 MiB, beyond the room a
		// package keeps for its record.
		self::b( array( array( 'op' => 'bulk_attachments', 'count' => 55000, 'as' => 'rows' ) ) );
		$first = (array) self::b( array( array( 'op' => 'export', 'purpose' => 'inventory', 'objects' => 40, 'as' => 'package', 'expect' => 'error' ) ) )['package'];
		self::assertArrayHasKey( 'package_id', $first, (string) json_encode( $first ) );
		self::assertNotNull( $first['cursor'] );
		$next = (array) self::b( array( array( 'op' => 'export', 'purpose' => 'inventory', 'objects' => 40, 'cursor' => $first['cursor'], 'as' => 'package', 'expect' => 'error' ) ) )['package'];
		self::assertArrayHasKey( 'package_id', $next, 'The next package takes the index up: ' . json_encode( $next ) );
	}

	/* ---------------------------------------------------------- held (A3) */

	public function testAHeldNameReachesNeitherTheStoreNorARealignAnswer(): void {
		global $wpdb;
		self::pair();
		$a    = self::pages_on_a( array( 'plain' => 'x' ) );
		$slug = '{"password":"CANARY-ONLY"}';
		$wpdb->update( $wpdb->posts, array( 'post_name' => $slug ), array( 'ID' => (int) $a['plain'] ) );
		clean_post_cache( (int) $a['plain'] );
		$b = self::pages_on_b( array( 'plain' => 'x' ) );
		self::assertIsArray(
			AB_MCP_Sync_Pairs::create(
				array(
					'source_id'    => AB_MCP_Sync_Anchor::active()['source_id'],
					'kind'         => 'post',
					'live_ref'     => $a['plain'],
					'staging_ref'  => $b['plain'],
					'canon'        => 'canon/1',
					'base_live'    => hash( 'sha256', 'l' ),
					'base_staging' => hash( 'sha256', 's' ),
					'base_origin'  => 'push',
					'state'        => 'unclear',
				)
			)
		);
		AB_MCP_Sync_Proposal::$limit = 5;
		$answer                      = self::proposal( self::staged() );
		$realign                     = array_values( array_filter( $answer['all'], static fn( $e ) => 'realign' === $e['category'] ) );
		self::assertCount( 1, $realign );
		self::assertSame( array( 'held' => 'credential_field', 'ref_sha256' => hash( 'sha256', '"' . $a['plain'] . '"' ) ), $realign[0]['live'] );
		self::assertNull( $realign[0]['confirm'] );
		self::assertStringNotContainsString( 'CANARY', (string) json_encode( $answer ) );
		// Every file of the proposal, read against its hash.
		$text = '';
		foreach ( array_keys( AB_MCP_Protected_Store::files( 'reports', $answer['proposal_id'] ) ) as $name ) {
			$parsed = AB_MCP_Sync_Proposal::parse_name( (string) $name );
			self::assertNotNull( $parsed, (string) $name );
			$text .= (string) AB_MCP_Protected_Store::get( 'reports', $answer['proposal_id'], $parsed['name'], $parsed['sha'] );
		}
		self::assertStringContainsString( 'ref_sha256', $text );
		self::assertStringNotContainsString( 'CANARY', $text, 'Reduced before it was stored.' );
	}

	/* ---------------------------------------------------- transaction (B2) */

	public function testAConfirmationWhoseReadBackFailsAfterItsInsertKeepsNothing(): void {
		self::pair();
		self::pages_on_a( array( 'about' => 'About us.' ) );
		self::pages_on_b( array( 'about' => 'About us.' ) );
		$answer  = self::proposal( self::staged() );
		$args    = array(
			'action'      => 'confirm',
			'proposal_id' => $answer['proposal_id'],
			'select'      => array( self::entry_of( $answer['all'], 'about' )['n'] ),
		);
		$preview = self::gated( $args );
		$table   = AB_MCP_Sync_Schema::table( 'pairs' );
		$after   = false;
		add_filter(
			'query',
			static function ( $sql ) use ( $table, &$after ) {
				if ( 0 === strpos( (string) $sql, 'INSERT INTO `' . $table . '`' ) ) {
					$after = true;
				} elseif ( $after && 0 === strpos( (string) $sql, 'SELECT * FROM ' . $table . ' WHERE id = ' ) ) {
					$after = false;
					return 'SELECT broken FROM nowhere_at_all';
				}
				return $sql;
			}
		);
		$result = self::gated( $args + array( 'confirm_token' => $preview['confirm_token'] ) );
		remove_all_filters( 'query' );
		self::assertStringContainsString( 'kept nothing', self::refused( $result, 'ab_mcp_sync_pairs_confirm_failed' ) );
		self::assertSame( array(), self::pair_rows(), 'The row written before the failed read is gone.' );
	}

	public function testATableWithoutTransactionsTakesNoPairs(): void {
		global $wpdb;
		self::pair();
		self::pages_on_a( array( 'about' => 'About us.' ) );
		self::pages_on_b( array( 'about' => 'About us.' ) );
		$answer = self::proposal( self::staged() );
		$table  = AB_MCP_Sync_Schema::table( 'pairs' );
		self::assertSame( 'InnoDB', $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $table ) ) );
		$args    = array(
			'action'      => 'confirm',
			'proposal_id' => $answer['proposal_id'],
			'select'      => array( self::entry_of( $answer['all'], 'about' )['n'] ),
		);
		$preview = self::gated( $args );
		$wpdb->query( "ALTER TABLE `$table` ENGINE=MyISAM" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the table name is ours.
		$msg = self::refused( self::gated( $args + array( 'confirm_token' => $preview['confirm_token'] ) ), 'ab_mcp_staging_pairs_engine' );
		self::assertStringContainsString( 'ENGINE=InnoDB', $msg );
		self::assertSame( array(), self::pair_rows() );
	}

	/* ------------------------------------------------------ the token (B3) */

	public function testTheTokenCoversTheObjectsOfThisSiteAsTheyAreWhenConfirmed(): void {
		self::pair();
		$a = self::pages_on_a( array( 'about' => 'About us.' ) );
		self::pages_on_b( array( 'about' => 'About us.' ) );
		$answer  = self::proposal( self::staged() );
		$args    = array(
			'action'      => 'confirm',
			'proposal_id' => $answer['proposal_id'],
			'select'      => array( self::entry_of( $answer['all'], 'about' )['n'] ),
		);
		$preview = self::gated( $args );
		self::assertFalse( $preview['preview']['pairs'][0]['changed_since_proposal'] );
		$before = AB_MCP_Tools_Staging::pairs_state( $args );
		// The editor changes the page in between.
		self::assertIsInt( wp_update_post( array( 'ID' => (int) $a['about'], 'post_content' => 'About us, changed.' ) ) );
		self::assertNotSame( $before, AB_MCP_Tools_Staging::pairs_state( $args ) );
		self::assertStringContainsString( 'does not match this call', self::refused( self::gated( $args + array( 'confirm_token' => $preview['confirm_token'] ) ), 'ab_mcp_confirm_mismatch' ) );
		self::assertSame( array(), self::pair_rows() );
		$fresh = self::gated( $args );
		self::assertTrue( $fresh['preview']['pairs'][0]['changed_since_proposal'], 'The new preview says so.' );
		self::assertIsArray( self::gated( $args + array( 'confirm_token' => $fresh['confirm_token'] ) ) );
		self::assertCount( 1, self::pair_rows() );
		// Gone since the proposal: skipped.
		self::assertInstanceOf( \WP_Post::class, wp_delete_post( (int) $a['about'], true ) );
		$rows = self::pair_rows();
		AB_MCP_Sync_Pairs::discard( (int) array_keys( $rows )[0], AB_MCP_Sync_Pairs::snapshot_hash( AB_MCP_Sync_Pairs::snapshot( (int) array_keys( $rows )[0] ) ) );
		self::assertStringContainsString( 'gone since the proposal', self::refused( self::gated( $args ), 'ab_mcp_sync_pairs_nothing' ) );
	}

	public function testEachPartOfTheStateOfADiscardBindsItsToken(): void {
		self::pair();
		$a    = self::pages_on_a( array( 'about' => 'About us.' ) );
		$b    = self::pages_on_b( array( 'about' => 'About us.' ) );
		$pair = AB_MCP_Sync_Pairs::create(
			array(
				'source_id'    => AB_MCP_Sync_Anchor::active()['source_id'],
				'kind'         => 'post',
				'live_ref'     => $a['about'],
				'staging_ref'  => $b['about'],
				'canon'        => 'canon/1',
				'base_live'    => hash( 'sha256', 'l' ),
				'base_staging' => hash( 'sha256', 's' ),
				'base_origin'  => 'proposal',
			)
		);
		$args = array(
			'action'   => 'discard',
			'pair_ids' => array( (int) $pair['id'] ),
		);

		// The connection: a token of MCP does not run in this process.
		$over = self::tool( 'a', 'wp_site_pairs', $args );
		self::assertIsArray( $over, is_string( $over ) ? $over : '' );
		self::refused( self::gated( $args + array( 'confirm_token' => $over['confirm_token'] ) ), 'ab_mcp_confirm_mismatch' );

		// The pair: changed in between.
		$preview = self::gated( $args );
		self::assertIsArray( AB_MCP_Sync_Pairs::set_state( $pair['id'], 'unclear', AB_MCP_Sync_Pairs::snapshot_hash( AB_MCP_Sync_Pairs::snapshot( $pair['id'] ) ) ) );
		self::refused( self::gated( $args + array( 'confirm_token' => $preview['confirm_token'] ) ), 'ab_mcp_confirm_mismatch' );

		// The print of the pair of sites: the role confirmed again.
		$preview = self::gated( $args );
		$raw     = \AB_MCP_Settings::get( AB_MCP_Staging::KEY, array() );
		$raw['role']['at'] = (int) $raw['role']['at'] + 1;
		\AB_MCP_Settings::set( AB_MCP_Staging::KEY, $raw );
		self::refused( self::gated( $args + array( 'confirm_token' => $preview['confirm_token'] ) ), 'ab_mcp_confirm_mismatch' );
		self::assertCount( 1, self::pair_rows(), 'Nothing ended.' );
		$done = self::confirmed( $args );
		self::assertIsArray( $done );
		self::assertSame( array(), self::pair_rows() );
	}

	/* -------------------------------------------------- taken() (hash, purpose) */

	public function testTakenReadsOnlyAPackageOfTheInventoryWithItsChunksAsSigned(): void {
		self::pair();
		$cases = array(
			'purpose' => array( 'objects', 'ab_mcp_sync_import_purpose_held' ),
			'chunk'   => array( 'inventory', 'ab_mcp_store_corrupt' ),
		);
		foreach ( $cases as $case => list( $purpose, $code ) ) {
			$id      = bin2hex( random_bytes( 16 ) );
			$text    = \AB_MCP_Sync_Canon::encode( self::option_line( 'blogname' ) ) . "\n";
			$chunk   = (string) gzencode( $text, 6 );
			$package = array(
				'package_id'  => $id,
				'purpose'     => $purpose,
				'report_hash' => 'objects' === $purpose ? str_repeat( 'c', 64 ) : null,
				'kinds'       => array(),
				'objects'     => 1,
				'unpacked'    => strlen( $text ),
				'chunks'      => array( array( 'bytes' => strlen( $chunk ), 'sha256' => hash( 'sha256', $chunk ) ) ),
				'bytes'       => strlen( $chunk ),
				'sha256'      => hash( 'sha256', $chunk ),
			);
			$signed  = self::b( array( array( 'op' => 'sign_manifest', 'as' => 'signed', 'package' => $package ) ) )['signed'];
			$gid     = AB_MCP_Protected_Store::open( 'in', array( 'declared' => 1048576 + AB_MCP_Sync_Import::RECORD_MAX + AB_MCP_Sync_Import::HELD_MAX, 'label' => AB_MCP_Sync_Import::LABEL . $id, 'binding' => (string) AB_MCP_Sync_Anchor::pair_print() ) );
			self::assertIsString( $gid );
			$record  = \AB_MCP_Sync_Canon::encode(
				array(
					'connection' => AB_MCP_Sync_Import::connection(),
					'expect'     => array( 'package_id' => $id, 'purpose' => $purpose ),
					'format'     => AB_MCP_Sync_Import::RECORD,
					'manifest'   => $signed['manifest'],
					'print'      => AB_MCP_Sync_Anchor::pair_print(),
					'signature'  => $signed['signature'],
				)
			);
			self::assertIsArray( AB_MCP_Protected_Store::put( 'in', $gid, hash( 'sha256', $record ), $record ) );
			// The chunk kept is not the one the manifest names.
			self::assertIsArray( AB_MCP_Protected_Store::put( 'in', $gid, AB_MCP_Sync_Export::chunk_name( 0 ), (string) gzencode( "x\n", 6 ) ) );
			self::assertIsArray( AB_MCP_Protected_Store::put( 'in', $gid, AB_MCP_Sync_Import::COMPLETE, hash( 'sha256', $chunk ) ) );
			$held = \AB_MCP_Sync_Canon::encode( array( 'format' => AB_MCP_Sync_Import::HELD_FORMAT, 'objects' => array() ) );
			self::assertIsArray( AB_MCP_Protected_Store::put( 'in', $gid, AB_MCP_Sync_Import::held_name( hash( 'sha256', $held ) ), $held ) );
			self::refused( AB_MCP_Sync_Import::taken( $id ), $code );
		}
	}

	/* ------------------------------------------------------- calls, owner */

	public function testAProposalGoesOnOverCallsAndBelongsToItsAccountAndConnection(): void {
		self::pair();
		self::pages_on_a( array( 'about' => 'About us.', 'live-new' => 'x' ) );
		self::pages_on_b( array( 'about' => 'About us.' ) );
		$ids = self::staged();

		$calls = 0;
		$whole = self::proposal( $ids, $calls );
		self::assertSame( 1, $calls );
		AB_MCP_Sync_Proposal::$limit = 3;
		$parts = self::proposal( $ids, $calls );
		self::assertGreaterThan( 3, $calls, 'Three objects a call.' );
		$strip = static fn( array $entries ): array => array_map( static fn( $e ) => array( $e['category'], $e['staging']['ref'] ?? null, $e['live']['ref'] ?? null ), $entries );
		self::assertSame( $strip( $whole['all'] ), $strip( $parts['all'] ), 'The same proposal over calls.' );
		self::assertSame( $whole['live']['objects'], $parts['live']['objects'] );
		self::assertNotSame( $whole['proposal_id'], $parts['proposal_id'] );

		// One proposal a connection: the later one removed the earlier one.
		self::refused( self::here( array( 'action' => 'propose', 'proposal_id' => $whole['proposal_id'] ) ), 'ab_mcp_sync_pairs_unknown' );

		// Another connection: over MCP.
		$over = self::tool( 'a', 'wp_site_pairs', array( 'action' => 'propose', 'proposal_id' => $parts['proposal_id'] ) );
		self::assertIsString( $over );
		self::assertStringContainsString( 'belongs to another account or connection', $over );
		$confirm = self::tool( 'a', 'wp_site_pairs', array( 'action' => 'confirm', 'proposal_id' => $parts['proposal_id'], 'select' => array( 1 ) ) );
		self::assertIsString( $confirm );
		self::assertStringContainsString( 'belongs to another account or connection', $confirm );

		// A proposal over MCP removes none of this process, nor the other way;
		// from packages taken over over MCP (a package belongs to its connection).
		self::assertStringContainsString( 'this connection', (string) self::tool( 'a', 'wp_site_pairs', array( 'action' => 'propose', 'packages' => $ids ) ) );
		$mcp = self::tool( 'a', 'wp_site_pairs', array( 'action' => 'propose', 'packages' => self::staged( null, true ) ) );
		self::assertIsArray( $mcp );
		self::assertTrue( self::here( array( 'action' => 'propose', 'proposal_id' => $parts['proposal_id'] ) )['done'] );
		AB_MCP_Sync_Proposal::$limit = null;
		self::proposal( $ids );
		self::assertIsArray( self::tool( 'a', 'wp_site_pairs', array( 'action' => 'propose', 'proposal_id' => $mcp['proposal_id'] ) ) );
		$left = array_column( array_filter( AB_MCP_Protected_Store::groups( 'reports' ), static fn( $g ) => 'proposal' === $g['label'] ), 'gid' );
		self::assertCount( 2, $left, 'One a connection.' );
		self::assertContains( $mcp['proposal_id'], $left );
	}

	public function testAProposalOfAnotherAccountIsNotItsOwn(): void {
		self::pair();
		$ids   = self::staged();
		$whole = self::proposal( $ids );
		$other = wp_insert_user(
			array(
				'user_login' => 'second',
				'user_pass'  => wp_generate_password( 24 ),
				'user_email' => 'second@example.invalid',
				'role'       => 'administrator',
			)
		);
		self::assertIsInt( $other );
		wp_set_current_user( $other );
		self::refused( self::here( array( 'action' => 'propose', 'proposal_id' => $whole['proposal_id'] ) ), 'ab_mcp_sync_pairs_foreign' );
		self::refused( self::here( array( 'action' => 'propose', 'packages' => $ids ) ), 'ab_mcp_sync_import_unknown' );
	}

	/* --------------------------------------------------------------- held */

	public function testANameHoldingACredentialIsShownByTheHashOfItsRefOnly(): void {
		global $wpdb;
		self::pair();
		self::b(
			array(
				array(
					'op'   => 'insert_post',
					'args' => self::page( 'plain', 'x' ),
					'as'   => 'p',
				),
			)
		);
		// A slug no form of WordPress writes, with a credential in it, on both sites.
		$slug = '{"password":"CANARY-ONLY"}';
		$a    = self::pages_on_a( array( 'plain' => 'x' ) );
		$wpdb->update( $wpdb->posts, array( 'post_name' => $slug ), array( 'ID' => (int) $a['plain'] ) );
		clean_post_cache( (int) $a['plain'] );
		update_option( 'my_api_key', 'CANARY-B9' );
		$ids    = self::staged();
		$answer = self::proposal( $ids );
		$held   = array_values( array_filter( $answer['all'], static fn( $e ) => 'held' === $e['category'] ) );
		self::assertGreaterThanOrEqual( 1, $answer['live']['credentials'], 'The option of a credential is counted.' );
		self::assertSame( array(), array_filter( $answer['all'], static fn( $e ) => 'my_api_key' === ( $e['live']['ref'] ?? null ) ), 'And never listed.' );
		self::assertCount( 1, $held );
		self::assertSame( array( array( 'held' => 'credential_field', 'ref_sha256' => hash( 'sha256', '"' . $a['plain'] . '"' ) ) ), $held[0]['live'] );
		self::assertSame( 1, $answer['live']['held'] );
		self::assertStringNotContainsString( 'CANARY', (string) json_encode( $answer ) );
		self::assertSame( 'staging_only', self::entry_of( $answer['all'], 'plain' )['category'], 'The page of B has no counterpart now.' );
	}

	/* ------------------------------------------------------------- series */

	public function testPackagesOfOneSeriesAreOneInventoryAndOfTwoAreRefused(): void {
		self::pair();
		self::pages_on_a( array( 'about' => 'About us.', 'live-new' => 'x' ) );
		self::pages_on_b( array( 'about' => 'About us.' ) );
		$series = self::export_b( 4 );
		self::assertGreaterThan( 2, count( $series ) );
		$ids = array();
		foreach ( $series as $package ) {
			self::assertTrue( self::import( $package )['complete'] );
			$ids[] = $package['package_id'];
		}
		$answer = self::proposal( $ids );
		self::assertSame( 'equal', self::entry_of( $answer['all'], 'about' )['category'] );
		$incomplete = array_map( static fn( $k ) => $k['kind'] . '/' . $k['subtype'], $answer['staging']['incomplete'] );
		$live       = self::entry_of( $answer['all'], 'live-new' );
		if ( in_array( 'post/page', $incomplete, true ) ) {
			self::assertSame( 'not_compared', $live['category'], 'The post kind across packages: never only live.' );
			self::assertStringContainsString( 'does not hold every object of this kind', $live['reason'] );
		} else {
			self::assertSame( 'live_only', $live['category'] );
		}
		self::assertContains( 'option/', $incomplete, 'The options of B are more than four.' );

		// The first package of another series with this one: an object twice.
		$second = self::export_b( 4 );
		self::assertTrue( self::import( $second[0] )['complete'] );
		self::refused( self::here( array( 'action' => 'propose', 'packages' => array( $ids[0], $second[0]['package_id'] ) ) ), 'ab_mcp_sync_pairs_series' );
	}

	public function testOnlyAPackageTakenOverCompletelyIsRead(): void {
		self::pair();
		self::pages_on_b( array( 'about' => str_repeat( 'Long text. ', 10 ) ) );
		$package = self::export_b()[0];
		$msg     = self::refused( self::here( array( 'action' => 'propose', 'packages' => array( $package['package_id'] ) ) ), 'ab_mcp_sync_import_unknown' );
		self::assertStringContainsString( 'wp_site_import_chunk', $msg );
		$state = AB_MCP_Sync_Import::call(
			array(
				'package_id' => $package['package_id'],
				'manifest'   => $package['manifest'],
				'purpose'    => 'inventory',
			)
		);
		self::assertFalse( $state['complete'] );
		self::refused( self::here( array( 'action' => 'propose', 'packages' => array( $package['package_id'] ) ) ), 'ab_mcp_sync_import_incomplete' );
		self::assertSame( array(), array_filter( AB_MCP_Protected_Store::groups( 'reports' ), static fn( $g ) => 'proposal' === $g['label'] ), 'No proposal begun.' );
	}

	/* ------------------------------------------------------------- canon */

	public function testACanonChangeGivesEqualFormsNewBasesAndMakesTheOthersUnclear(): void {
		self::pair();
		$a      = self::pages_on_a( array( 'about' => 'About us.', 'contact' => 'A' ) );
		$b      = self::pages_on_b( array( 'about' => 'About us.', 'contact' => 'B' ) );
		$source = AB_MCP_Sync_Anchor::active()['source_id'];
		$old    = hash( 'sha256', 'old' );
		$made   = array();
		foreach ( array( 'about', 'contact' ) as $name ) {
			$pair = AB_MCP_Sync_Pairs::create(
				array(
					'source_id'    => $source,
					'kind'         => 'post',
					'live_ref'     => $a[ $name ],
					'staging_ref'  => $b[ $name ],
					'canon'        => 'canon/9',
					'base_live'    => $old,
					'base_staging' => $old,
					'base_origin'  => 'proposal',
				)
			);
			self::assertIsArray( $pair );
			$made[ $name ] = $pair;
		}
		$waits = AB_MCP_Sync_Pairs::create(
			array(
				'source_id'    => $source,
				'kind'         => 'post',
				'live_ref'     => (string) self::pages_on_a( array( 'gone-on-staging' => 'x' ) )['gone-on-staging'],
				'staging_ref'  => '999999',
				'canon'        => 'canon/9',
				'base_live'    => $old,
				'base_staging' => $old,
				'base_origin'  => 'proposal',
			)
		);
		self::assertIsArray( $waits );
		$answer = self::proposal( self::staged() );
		self::assertSame( array( 'rebased' => 1, 'unclear' => 1, 'waiting' => 1 ), $answer['canon'] );
		self::assertSame( array( 'active', 'canon/9', $old ), array( AB_MCP_Sync_Pairs::get( $waits['id'] )['state'], AB_MCP_Sync_Pairs::get( $waits['id'] )['canon'], AB_MCP_Sync_Pairs::get( $waits['id'] )['base_live'] ), 'Its staging object is not in the inventory: it waits.' );
		$about   = AB_MCP_Sync_Pairs::get( $made['about']['id'] );
		$contact = AB_MCP_Sync_Pairs::get( $made['contact']['id'] );
		self::assertSame( array( 'active', 'canon/1' ), array( $about['state'], $about['canon'] ) );
		self::assertSame( $about['base_live'], $about['base_staging'] );
		self::assertNotSame( $old, $about['base_live'] );
		self::assertSame( array( 'unclear', 'canon/9', $old ), array( $contact['state'], $contact['canon'], $contact['base_live'] ), 'The old bases stay for the person who confirms.' );
		self::assertSame( 2, $answer['paired'] );
		self::assertSame( 1, $answer['counts']['realign'], 'The unclear one, read again after the change.' );
	}

	/* ------------------------------------------------------------ realign */

	public function testAnUnclearPairAlignedAnewKeepsItsOverrideAndExtra(): void {
		self::pair();
		$a      = self::pages_on_a( array( 'contact' => 'A' ) );
		$b      = self::pages_on_b( array( 'contact' => 'B' ) );
		$source = AB_MCP_Sync_Anchor::active()['source_id'];
		$pair   = AB_MCP_Sync_Pairs::create(
			array(
				'source_id'    => $source,
				'kind'         => 'post',
				'live_ref'     => $a['contact'],
				'staging_ref'  => $b['contact'],
				'canon'        => 'canon/1',
				'base_live'    => hash( 'sha256', 'l' ),
				'base_staging' => hash( 'sha256', 's' ),
				'base_origin'  => 'push',
				'state'        => 'unclear',
				'override'     => array( 'post_status' => 'draft' ),
				'base_extra'   => array( 'items' => array( 1, 2 ) ),
			)
		);
		self::assertIsArray( $pair );
		$answer  = self::proposal( self::staged() );
		$realign = array_values( array_filter( $answer['all'], static fn( $e ) => 'realign' === $e['category'] ) );
		self::assertCount( 1, $realign );
		$done = self::confirmed(
			array(
				'action'      => 'confirm',
				'proposal_id' => $answer['proposal_id'],
				'select'      => array( $realign[0]['n'] ),
			)
		);
		self::assertIsArray( $done, is_wp_error( $done ) ? $done->get_error_message() : '' );
		self::assertSame( array( 'aligned' => 1 ), $done['counts'] );
		$now = AB_MCP_Sync_Pairs::get( $pair['id'] );
		self::assertSame( array( 'active', 'proposal', array( 'post_status' => 'draft' ), array( 'items' => array( 1, 2 ) ) ), array( $now['state'], $now['base_origin'], $now['override'], $now['base_extra'] ) );
		self::assertNotSame( hash( 'sha256', 'l' ), $now['base_live'] );
		self::assertNotSame( $now['base_live'], $now['base_staging'], 'Each side its own base.' );
	}

	public function testAPairChangedSinceTheProposalIsSkippedNotOverwritten(): void {
		self::pair();
		$a      = self::pages_on_a( array( 'contact' => 'A', 'about' => 'About us.' ) );
		$b      = self::pages_on_b( array( 'contact' => 'B', 'about' => 'About us.' ) );
		$source = AB_MCP_Sync_Anchor::active()['source_id'];
		$pair   = AB_MCP_Sync_Pairs::create(
			array(
				'source_id'    => $source,
				'kind'         => 'post',
				'live_ref'     => $a['contact'],
				'staging_ref'  => $b['contact'],
				'canon'        => 'canon/1',
				'base_live'    => hash( 'sha256', 'l' ),
				'base_staging' => hash( 'sha256', 's' ),
				'base_origin'  => 'push',
				'state'        => 'unclear',
			)
		);
		$answer  = self::proposal( self::staged() );
		$realign = array_values( array_filter( $answer['all'], static fn( $e ) => 'realign' === $e['category'] ) )[0];
		$about   = self::entry_of( $answer['all'], 'about' );
		// Another request moves the pair on in between.
		self::assertIsArray( AB_MCP_Sync_Pairs::set_state( $pair['id'], 'residual', AB_MCP_Sync_Pairs::snapshot_hash( AB_MCP_Sync_Pairs::snapshot( $pair['id'] ) ) ) );
		$args    = array(
			'action'      => 'confirm',
			'proposal_id' => $answer['proposal_id'],
			'select'      => array( $realign['n'], $about['n'] ),
		);
		$preview = self::gated( $args );
		self::assertSame( array( 'made_active' => 1 ), $preview['preview']['counts'] );
		self::assertSame( array( array( 'entry' => $realign['n'], 'reason' => 'pair ' . $pair['id'] . ' changed since the proposal' ) ), $preview['preview']['skipped'] );
		$done = self::gated( $args + array( 'confirm_token' => $preview['confirm_token'] ) );
		self::assertIsArray( $done );
		self::assertSame( 'residual', AB_MCP_Sync_Pairs::get( $pair['id'] )['state'], 'Left as the other request wrote it.' );
		self::assertCount( 2, self::pair_rows() );
	}

	/* ------------------------------------------------------- all or nothing */

	public function testAConfirmationThatCannotWriteAPairKeepsNothing(): void {
		self::pair();
		$a      = self::pages_on_a( array( 'contact' => 'A', 'about' => 'About us.', 'team' => 'T' ) );
		$b      = self::pages_on_b( array( 'contact' => 'B', 'about' => 'About us.', 'team' => 'T' ) );
		$source = AB_MCP_Sync_Anchor::active()['source_id'];
		$pair   = AB_MCP_Sync_Pairs::create(
			array(
				'source_id'    => $source,
				'kind'         => 'post',
				'live_ref'     => $a['contact'],
				'staging_ref'  => $b['contact'],
				'canon'        => 'canon/1',
				'base_live'    => hash( 'sha256', 'l' ),
				'base_staging' => hash( 'sha256', 's' ),
				'base_origin'  => 'push',
				'state'        => 'unclear',
			)
		);
		// Drafts without a slug: an uncertain group, whose choice comes last.
		$drafts_a = array();
		foreach ( array( 'd1', 'd2' ) as $content ) {
			$drafts_a[ $content ] = (string) wp_insert_post( wp_slash( self::page( '', $content, array( 'post_status' => 'draft', 'post_title' => 'Draft' ) ) ) );
		}
		$drafts_b = array_map(
			'strval',
			self::b(
				array(
					array( 'op' => 'insert_post', 'args' => self::page( '', 'd3', array( 'post_status' => 'draft', 'post_title' => 'Draft' ) ), 'as' => 'd3' ),
					array( 'op' => 'insert_post', 'args' => self::page( '', 'd4', array( 'post_status' => 'draft', 'post_title' => 'Draft' ) ), 'as' => 'd4' ),
				)
			)
		);
		$before  = AB_MCP_Sync_Pairs::snapshot( $pair['id'] );
		$answer  = self::proposal( self::staged() );
		$realign = array_values( array_filter( $answer['all'], static fn( $e ) => 'realign' === $e['category'] ) )[0];
		$amb     = array_values( array_filter( $answer['all'], static fn( $e ) => 'uncertain' === $e['category'] && 'page' === $e['subtype'] ) )[0];
		$args    = array(
			'action'      => 'confirm',
			'proposal_id' => $answer['proposal_id'],
			'select'      => array( $realign['n'], self::entry_of( $answer['all'], 'about' )['n'], self::entry_of( $answer['all'], 'team' )['n'] ),
		);
		$preview = self::gated( $args );
		self::assertIsArray( $preview );
		$table   = AB_MCP_Sync_Schema::table( 'pairs' );
		$inserts = 0;
		add_filter(
			'query',
			static function ( $sql ) use ( $table, &$inserts ) {
				if ( 0 === strpos( (string) $sql, 'INSERT INTO `' . $table . '`' ) && 2 === ++$inserts ) {
					return 'SELECT broken FROM nowhere_at_all';
				}
				return $sql;
			}
		);
		$result = self::gated( $args + array( 'confirm_token' => $preview['confirm_token'] ) );
		remove_all_filters( 'query' );
		self::assertSame( 2, $inserts );
		$msg = self::refused( $result, 'ab_mcp_sync_pairs_confirm_failed' );
		self::assertStringContainsString( 'the database took back all of it', $msg );
		self::assertSame( array( (int) $pair['id'] ), array_keys( self::pair_rows() ), 'The pair made before is gone.' );
		self::assertSame( $before, AB_MCP_Sync_Pairs::snapshot( $pair['id'] ), 'The pair to align was not reached.' );

		// The pair aligned anew before a later failure is put back.
		$args    = array(
			'action'      => 'confirm',
			'proposal_id' => $answer['proposal_id'],
			'select'      => array( $realign['n'] ),
			'choices'     => array( array( 'entry' => $amb['n'], 'staging_ref' => $drafts_b['d3'], 'live_ref' => $drafts_a['d1'] ) ),
		);
		$preview = self::gated( $args );
		self::assertIsArray( $preview );
		self::assertSame( array( 'realign', 'create' ), array_column( $preview['preview']['pairs'], 'op' ), 'Aligned first, the choice after it.' );
		$inserts = 0;
		$updates = 0;
		add_filter(
			'query',
			static function ( $sql ) use ( $table, &$inserts, &$updates ) {
				if ( 0 === strpos( (string) $sql, 'UPDATE ' . $table ) ) {
					++$updates;
				}
				if ( 0 === strpos( (string) $sql, 'INSERT INTO `' . $table . '`' ) && 1 === ++$inserts ) {
					return 'SELECT broken FROM nowhere_at_all';
				}
				return $sql;
			}
		);
		$result = self::gated( $args + array( 'confirm_token' => $preview['confirm_token'] ) );
		remove_all_filters( 'query' );
		self::assertSame( 1, $updates, 'Aligned once; the database takes it back.' );
		self::assertStringContainsString( 'the database took back all of it', self::refused( $result, 'ab_mcp_sync_pairs_confirm_failed' ) );
		$now = AB_MCP_Sync_Pairs::get( $pair['id'] );
		self::assertSame( array( 'unclear', 'push', hash( 'sha256', 'l' ) ), array( $now['state'], $now['base_origin'], $now['base_live'] ), 'Back as it was.' );
		self::assertSame( array( (int) $pair['id'] ), array_keys( self::pair_rows() ) );
	}

	/* ------------------------------------------------------------ validity */

	public function testAProposalHoldsNoMoreOnceThePairOfSitesChangedOrItExpired(): void {
		self::pair();
		self::pages_on_a( array( 'about' => 'About us.' ) );
		self::pages_on_b( array( 'about' => 'About us.' ) );
		$ids    = self::staged();
		$answer = self::proposal( $ids );
		$args   = array(
			'action'      => 'confirm',
			'proposal_id' => $answer['proposal_id'],
			'select'      => array( self::entry_of( $answer['all'], 'about' )['n'] ),
		);
		self::assertIsArray( self::gated( $args ) );

		AB_MCP_Sync_Proposal::$clock = static fn(): int => (int) $answer['expires'];
		self::assertStringContainsString( 'expired', self::refused( self::gated( $args ), 'ab_mcp_sync_pairs_expired' ) );
		AB_MCP_Sync_Proposal::$clock = static fn(): int => (int) $answer['expires'] - 1;
		self::assertIsArray( self::gated( $args ), 'Until it expires.' );
		AB_MCP_Sync_Proposal::$clock = null;

		// The role confirmed again later: another print of the pair, the same key.
		$print = AB_MCP_Sync_Anchor::pair_print();
		$raw   = \AB_MCP_Settings::get( AB_MCP_Staging::KEY, array() );
		$raw['role']['at'] = (int) $raw['role']['at'] + 1;
		\AB_MCP_Settings::set( AB_MCP_Staging::KEY, $raw );
		self::assertNotNull( AB_MCP_Sync_Anchor::active() );
		self::assertNotSame( $print, AB_MCP_Sync_Anchor::pair_print() );
		self::refused( self::gated( $args ), 'ab_mcp_sync_pairs_stale' );
		self::refused( self::here( array( 'action' => 'propose', 'proposal_id' => $answer['proposal_id'] ) ), 'ab_mcp_sync_pairs_stale' );
		self::refused( self::here( array( 'action' => 'propose', 'packages' => $ids ) ), 'ab_mcp_sync_import_pair_changed' );
		self::assertSame( array(), self::pair_rows() );
	}

	public function testTheSnapshotAProposalKeepsIsTheOneOfTheStore(): void {
		$pair = AB_MCP_Sync_Pairs::create(
			array(
				'source_id'    => 'src-1',
				'kind'         => 'menu',
				'live_ref'     => '12',
				'staging_ref'  => 'ä/34',
				'canon'        => 'canon/1',
				'base_live'    => hash( 'sha256', 'l' ),
				'base_staging' => hash( 'sha256', 's' ),
				'base_origin'  => 'push',
				'state'        => 'residual',
				'override'     => array( 'post_status' => 'draft', 'comment_status' => 'closed' ),
				'base_extra'   => array( 'z' => array( 'b' => 1, 'a' => 'ü/x' ), 'a' => true ),
				'last_run'     => 7,
			)
		);
		self::assertIsArray( $pair );
		self::assertSame( AB_MCP_Sync_Pairs::snapshot( $pair['id'] ), AB_MCP_Sync_Proposal::pair_snapshot( $pair ) );
	}
}

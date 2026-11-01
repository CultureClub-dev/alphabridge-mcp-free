<?php
/**
 * The reasons of the takeover's tables against WordPress 7.1.3 itself
 * (AB_MCP_Sync_Kinds::OWNERSHIP, ::LOCKS, AB_MCP_Sync_Exclusions; the
 * reasons in tests/fixtures/sync-reasons.php):
 *
 * - every place of the core a reason names says on that line what the
 *   reason says it does;
 * - every post type and taxonomy the core registers has an entry of its
 *   own (or is excluded), and no entry names one the core does not have;
 * - what the exclusions say WordPress writes by itself, it writes: the
 *   former slug and date, the editing lock and the last editor, the state
 *   of the trash, the enclosure and pingback jobs, oEmbed caches as meta
 *   and as posts, revisions, a request for personal data, the counters of
 *   comments and terms, the time of the change, the guid, transients and
 *   the locks of WP_Upgrader.
 *
 * Which entry each name resolves to is tests/SyncKindsTest.php.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests\Integration;

use AB_MCP_IT_Sites;
use AB_MCP_Sync_Exclusions;
use AB_MCP_Sync_Kinds;
use PHPUnit\Framework\TestCase;

final class SyncCoreReasonsTest extends TestCase {

	protected function setUp(): void {
		AB_MCP_IT_Sites::reset( 'a' );
		wp_set_current_user( (int) AB_MCP_IT_Sites::site( 'a' )['admin']['user_id'] );
	}

	public static function tearDownAfterClass(): void {
		wp_set_current_user( 0 );
		AB_MCP_IT_Sites::reset( 'a' );
	}

	private static function reasons(): array {
		return require dirname( __DIR__ ) . '/fixtures/sync-reasons.php';
	}

	/** @return string[] The meta keys a post has, as rows. */
	private static function meta_keys( int $post_id ): array {
		$keys = array_column( AB_MCP_IT_Sites::rows( 'a', 'postmeta', array( 'post_id' => $post_id ) ), 'meta_key' );
		sort( $keys );
		return $keys;
	}

	private static function post( array $over = array() ): int {
		$id = wp_insert_post(
			array_merge(
				array(
					'post_title'   => 'Reason',
					'post_content' => 'Text',
					'post_status'  => 'publish',
					'post_type'    => 'post',
					'post_date'    => '2026-10-01 10:00:00',
				),
				$over
			),
			true
		);
		self::assertIsInt( $id, is_wp_error( $id ) ? $id->get_error_message() : '' );
		return $id;
	}

	/* ------------------------------------------------------- citations */

	public function testEveryPlaceOfTheCoreAReasonNamesSaysWhatTheReasonSays(): void {
		self::assertSame( '7.1.3', get_bloginfo( 'version' ) );
		$count = 0;
		foreach ( self::reasons() as $group => $entries ) {
			foreach ( $entries as $key => $entry ) {
				foreach ( $entry['core'] ?? array() as list( $file, $line, $text ) ) {
					$lines = file( ABSPATH . $file );
					self::assertIsArray( $lines, $file );
					self::assertStringContainsString( $text, (string) ( $lines[ $line - 1 ] ?? '' ), "$group $key: $file:$line" );
					++$count;
				}
			}
		}
		self::assertGreaterThan( 70, $count, 'The scan finds them.' );
	}

	public function testEveryPostTypeAndTaxonomyOfTheCoreHasItsOwnEntry(): void {
		$builtin = array(
			'post_type' => get_post_types( array( '_builtin' => true ) ),
			'taxonomy'  => get_taxonomies( array( '_builtin' => true ) ),
		);
		// On this site nothing registers more than the core does.
		self::assertSame( array_values( $builtin['post_type'] ), array_values( get_post_types() ) );
		self::assertSame( array_values( $builtin['taxonomy'] ), array_values( get_taxonomies() ) );
		self::assertCount( 16, $builtin['post_type'] );
		self::assertCount( 8, $builtin['taxonomy'] );

		foreach ( $builtin as $prefix => $names ) {
			$explicit = array();
			foreach ( array_keys( AB_MCP_Sync_Kinds::OWNERSHIP ) as $key ) {
				if ( 0 === strpos( $key, $prefix . ':' ) && $prefix . ':*' !== $key ) {
					$explicit[] = substr( $key, strlen( $prefix ) + 1 );
				}
			}
			if ( 'post_type' === $prefix ) {
				$explicit = array_merge( $explicit, AB_MCP_Sync_Exclusions::POST_TYPES );
			}
			sort( $explicit );
			$core = array_values( $names );
			sort( $core );
			self::assertSame( $core, $explicit, "Every $prefix of the core has an entry of its own, and every entry is one of the core." );
		}
	}

	/* --------------------------------------------- what the core writes */

	public function testTheCoreKeepsFormerSlugsDatesAndRevisionsAndTheTimeOfTheChange(): void {
		$id     = self::post();
		$before = AB_MCP_IT_Sites::rows( 'a', 'posts', array( 'ID' => $id ) )[0];
		self::assertStringContainsString( AB_MCP_IT_Sites::url( 'a' ), $before['guid'], 'The guid holds the address of this site.' );

		$updated = wp_update_post(
			array(
				'ID'           => $id,
				'post_name'    => 'new-slug',
				'post_date'    => '2026-10-02 10:00:00',
				'post_content' => 'Text, changed',
			),
			true
		);
		self::assertSame( $id, $updated );

		$keys = self::meta_keys( $id );
		foreach ( array( '_wp_old_slug', '_wp_old_date' ) as $key ) {
			self::assertContains( $key, $keys );
			self::assertTrue( AB_MCP_Sync_Exclusions::post_meta( $key ) );
		}
		$after = AB_MCP_IT_Sites::rows( 'a', 'posts', array( 'ID' => $id ) )[0];
		self::assertNotSame( $before['post_modified_gmt'], $after['post_modified_gmt'], 'Set by the core on the update.' );
		self::assertSame( $before['guid'], $after['guid'], 'Never written again.' );
		foreach ( array( 'post_modified', 'post_modified_gmt', 'guid' ) as $column ) {
			self::assertTrue( AB_MCP_Sync_Exclusions::column( 'posts', $column ) );
		}

		$revisions = AB_MCP_IT_Sites::rows( 'a', 'posts', array( 'post_parent' => $id, 'post_type' => 'revision' ) );
		self::assertNotSame( array(), $revisions, 'A revision of the update.' );
		self::assertSame( 'excluded', AB_MCP_Sync_Kinds::post_type( 'revision' )['status'] );

		// The way back of a revision notes the last editor.
		self::assertNotFalse( wp_restore_post_revision( (int) $revisions[0]['ID'] ) );
		self::assertContains( '_edit_last', self::meta_keys( $id ) );
	}

	public function testTheEditorsLockAndTheTrashLeaveTheirStateOnThePost(): void {
		require_once ABSPATH . 'wp-admin/includes/post.php';
		$id = self::post();
		wp_insert_comment(
			array(
				'comment_post_ID'  => $id,
				'comment_content'  => 'A comment',
				'comment_approved' => 1,
			)
		);
		self::assertSame( '1', AB_MCP_IT_Sites::rows( 'a', 'posts', array( 'ID' => $id ) )[0]['comment_count'], 'Counted by the core.' );
		self::assertTrue( AB_MCP_Sync_Exclusions::column( 'posts', 'comment_count' ) );

		self::assertIsArray( wp_set_post_lock( $id ) );
		self::assertContains( '_edit_lock', self::meta_keys( $id ) );

		self::assertNotFalse( wp_trash_post( $id ) );
		$keys = self::meta_keys( $id );
		foreach ( array( '_wp_trash_meta_status', '_wp_trash_meta_time', '_wp_trash_meta_comments_status', '_wp_desired_post_slug' ) as $key ) {
			self::assertContains( $key, $keys );
			self::assertTrue( AB_MCP_Sync_Exclusions::post_meta( $key ), $key );
		}
		self::assertTrue( AB_MCP_Sync_Exclusions::post_meta( '_edit_lock' ) );
	}

	public function testPublishingAPostNotesTheEnclosureAndPingbackJobs(): void {
		update_option( 'default_pingback_flag', '1' );
		$id   = self::post( array( 'post_status' => 'draft' ) );
		$none = self::meta_keys( $id );
		self::assertNotContains( '_encloseme', $none );
		wp_publish_post( $id );
		$keys = self::meta_keys( $id );
		foreach ( array( '_encloseme', '_pingme' ) as $key ) {
			self::assertContains( $key, $keys );
			self::assertTrue( AB_MCP_Sync_Exclusions::post_meta( $key ) );
		}
	}

	public function testTheCoreCachesOEmbedAnswersOnThePostAndAsPosts(): void {
		$html   = '<iframe src="https://video.example.test/embed/1"></iframe>';
		$answer = static fn() => $html;
		add_filter( 'pre_oembed_result', $answer );
		try {
			global $wp_embed;
			$id               = self::post();
			$wp_embed->post_ID = $id;
			$wp_embed->shortcode( array(), 'https://video.example.test/watch/1' );
			$wp_embed->post_ID = null;
			$keys              = self::meta_keys( $id );
			self::assertCount( 2, preg_grep( '/^_oembed_(time_)?[0-9a-f]{32}\z/', $keys ), implode( ', ', $keys ) );
			foreach ( preg_grep( '/^_oembed_/', $keys ) as $key ) {
				self::assertTrue( AB_MCP_Sync_Exclusions::post_meta( $key ), $key );
			}

			// Without a post, the answer is kept as a post of its own.
			$GLOBALS['post'] = null;
			$wp_embed->shortcode( array(), 'https://video.example.test/watch/2' );
			self::assertNotSame( array(), AB_MCP_IT_Sites::rows( 'a', 'posts', array( 'post_type' => 'oembed_cache' ) ) );
			self::assertTrue( AB_MCP_Sync_Exclusions::post_type( 'oembed_cache' ) );
		} finally {
			remove_filter( 'pre_oembed_result', $answer );
		}
	}

	public function testARequestForPersonalDataIsAPostOfItsOwnType(): void {
		$id = wp_create_user_request( 'person@example.test', 'export_personal_data' );
		self::assertIsInt( $id );
		self::assertSame( 'user_request', AB_MCP_IT_Sites::rows( 'a', 'posts', array( 'ID' => $id ) )[0]['post_type'] );
		self::assertSame( 'excluded', AB_MCP_Sync_Kinds::post_type( 'user_request' )['status'] );
	}

	public function testTheCoreCountsTheObjectsOfATermWhenAPostIsPublished(): void {
		$term = wp_insert_term( 'Reasons', 'category' );
		self::assertIsArray( $term );
		$count = static fn() => AB_MCP_IT_Sites::rows( 'a', 'term_taxonomy', array( 'term_taxonomy_id' => $term['term_taxonomy_id'] ) )[0]['count'];
		$id    = self::post( array( 'post_status' => 'draft', 'post_category' => array( $term['term_id'] ) ) );
		self::assertSame( '0', $count() );
		wp_publish_post( $id );
		self::assertSame( '1', $count() );
		self::assertTrue( AB_MCP_Sync_Exclusions::column( 'term_taxonomy', 'count' ) );
	}

	public function testTransientsAndTheLocksOfTheUpgraderAreOptionsOfTheirOwnNames(): void {
		set_transient( 'ab_it_reason', 'x', 60 );
		self::assertNotNull( AB_MCP_IT_Sites::option_row( 'a', '_transient_ab_it_reason' ) );
		self::assertNotNull( AB_MCP_IT_Sites::option_row( 'a', '_transient_timeout_ab_it_reason' ) );
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		self::assertTrue( \WP_Upgrader::create_lock( 'ab_it_reason' ) );
		self::assertNotNull( AB_MCP_IT_Sites::option_row( 'a', 'ab_it_reason.lock' ) );
		foreach ( array( '_transient_ab_it_reason', '_transient_timeout_ab_it_reason', 'ab_it_reason.lock' ) as $name ) {
			self::assertTrue( AB_MCP_Sync_Exclusions::option( $name ), $name );
		}
		\WP_Upgrader::release_lock( 'ab_it_reason' );
	}
}

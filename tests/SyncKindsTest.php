<?php
/**
 * The kinds of the takeover (contract A, Z8 decision 25): who is
 * responsible for which object type, what Z8 locks with which stage, what
 * is never taken over, and the registry of the engines.
 *
 * One test per entry of AB_MCP_Sync_Kinds::OWNERSHIP, ::LOCKS and of each
 * list of AB_MCP_Sync_Exclusions: the answer the resolver gives for it is
 * written out here, not read from the table, so an entry taken out or
 * moved fails its own test. Why the entry stands there is
 * tests/fixtures/sync-reasons.php, one reason per entry with where
 * WordPress or this plugin shows it; the citations of this plugin are
 * checked here, those of the core against WordPress 7.1.3 in
 * tests/integration/SyncCoreReasonsTest.php, which also holds that every
 * post type and taxonomy of the core has its own entry.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use AB_MCP_Sync_Exclusions;
use AB_MCP_Sync_Kind;
use AB_MCP_Sync_Kinds;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WP_Error;

require_once __DIR__ . '/../includes/sync/class-sync-kinds.php';

/**
 * A kind for the registry: a name, a phase and what it claims.
 */
final class FakeSyncKind implements AB_MCP_Sync_Kind {
	private $name;
	private $phase;
	private $claims;

	public function __construct( $name, $phase, array $claims ) {
		$this->name   = $name;
		$this->phase  = $phase;
		$this->claims = $claims;
	}

	public function kind() {
		return $this->name;
	}

	public function phase() {
		return $this->phase;
	}

	public function responsibility() {
		return $this->claims;
	}

	public function inventory( $source, $cursor ) {
		return array();
	}

	public function canonical( $object ) {
		return array();
	}

	public function export( array $refs ) {
		return array();
	}

	public function rights( $op, $ref ) {
		return true;
	}

	public function core_effects( $op ) {
		return array();
	}

	public function fields() {
		return array();
	}

	public function parts( array $payload ) {
		return array();
	}

	public function backup( array $part ) {
		return array();
	}

	public function write( array $part ) {
		return true;
	}

	public function read_back( array $part ) {
		return '';
	}

	public function restore_part( array $part ) {
		return true;
	}

	public function post_write( array $parts ) {
		return true;
	}

	public function verify( array $pair ) {
		return true;
	}
}

final class SyncKindsTest extends TestCase {

	/** What each kind of Z8 owns, as the table must give it. */
	private const OWNS = array(
		'post'        => array( 'post_type:page', 'post_type:post', 'post_type:wp_block', 'post_type:wp_global_styles', 'post_type:wp_navigation', 'post_type:wp_template', 'post_type:wp_template_part', 'taxonomy:post_format', 'taxonomy:wp_template_part_area', 'taxonomy:wp_theme' ),
		'attachment'  => array( 'files:attachment', 'post_type:attachment' ),
		'term'        => array( 'taxonomy:category', 'taxonomy:post_tag', 'taxonomy:wp_pattern_category' ),
		'menu'        => array( 'post_type:nav_menu_item', 'taxonomy:nav_menu', 'theme_mod:nav_menu_locations' ),
		'widget_area' => array( 'option:sidebars_widgets', 'option:widget_*' ),
		'custom_css'  => array( 'post_type:custom_css', 'theme_mod:custom_css_post_id' ),
		'option'      => array( 'option:*' ),
	);

	protected function setUp(): void {
		ab_test_reset();
		AB_MCP_Sync_Kinds::reset();
	}

	protected function tearDown(): void {
		AB_MCP_Sync_Kinds::reset();
	}

	private static function reasons(): array {
		return require __DIR__ . '/fixtures/sync-reasons.php';
	}

	private function assertReason( string $group, string $key ): void {
		$reasons = self::reasons()[ $group ];
		self::assertArrayHasKey( $key, $reasons, "No reason for $group $key in tests/fixtures/sync-reasons.php." );
		self::assertNotSame( '', trim( (string) ( $reasons[ $key ]['why'] ?? '' ) ), "$group $key: why" );
		self::assertNotSame( '', trim( (string) ( $reasons[ $key ]['plan'] ?? '' ) ), "$group $key: the decision of the plan" );
	}

	/* ------------------------------------------------------- ownership */

	/**
	 * One case per entry of OWNERSHIP: the resolver, what is asked, and the
	 * answer (status, kind, stage).
	 */
	public static function ownership(): array {
		return array(
			// The post types of WordPress 7.1.3.
			'post_type:post'                 => array( 'post_type', 'post', 'z8', 'post', null ),
			'post_type:page'                 => array( 'post_type', 'page', 'z8', 'post', null ),
			'post_type:attachment'           => array( 'post_type', 'attachment', 'z8', 'attachment', null ),
			'post_type:nav_menu_item'        => array( 'post_type', 'nav_menu_item', 'z8', 'menu', null ),
			'post_type:custom_css'           => array( 'post_type', 'custom_css', 'z8', 'custom_css', null ),
			'post_type:customize_changeset'  => array( 'post_type', 'customize_changeset', 'locked', null, 'Z8d' ),
			'post_type:wp_block'             => array( 'post_type', 'wp_block', 'z8', 'post', null ),
			'post_type:wp_template'          => array( 'post_type', 'wp_template', 'z8', 'post', null ),
			'post_type:wp_template_part'     => array( 'post_type', 'wp_template_part', 'z8', 'post', null ),
			'post_type:wp_global_styles'     => array( 'post_type', 'wp_global_styles', 'z8', 'post', null ),
			'post_type:wp_navigation'        => array( 'post_type', 'wp_navigation', 'z8', 'post', null ),
			'post_type:wp_font_family'       => array( 'post_type', 'wp_font_family', 'locked', null, 'Z8b' ),
			'post_type:wp_font_face'         => array( 'post_type', 'wp_font_face', 'locked', null, 'Z8b' ),
			'post_type:*'                    => array( 'post_type', 'product', 'locked', null, 'Z8d' ),
			// The taxonomies of WordPress 7.1.3.
			'taxonomy:category'              => array( 'taxonomy', 'category', 'z8', 'term', null ),
			'taxonomy:post_tag'              => array( 'taxonomy', 'post_tag', 'z8', 'term', null ),
			'taxonomy:wp_pattern_category'   => array( 'taxonomy', 'wp_pattern_category', 'z8', 'term', null ),
			'taxonomy:nav_menu'              => array( 'taxonomy', 'nav_menu', 'z8', 'menu', null ),
			'taxonomy:link_category'         => array( 'taxonomy', 'link_category', 'locked', null, 'Z8d' ),
			'taxonomy:post_format'           => array( 'taxonomy', 'post_format', 'z8', 'post', null ),
			'taxonomy:wp_theme'              => array( 'taxonomy', 'wp_theme', 'z8', 'post', null ),
			'taxonomy:wp_template_part_area' => array( 'taxonomy', 'wp_template_part_area', 'z8', 'post', null ),
			'taxonomy:*'                     => array( 'taxonomy', 'product_cat', 'locked', null, 'Z8d' ),
			// Options.
			'option:sidebars_widgets'        => array( 'option', 'sidebars_widgets', 'z8', 'widget_area', null ),
			'option:widget_*'                => array( 'option', 'widget_text', 'z8', 'widget_area', null ),
			'option:theme_mods_*'            => array( 'option', 'theme_mods_twentytwentyfive', 'fields', null, null ),
			'option:{prefix}user_roles'      => array( 'option', 'wp_user_roles', 'locked', 'roles', 'Z8d' ),
			'option:users_can_register'      => array( 'option', 'users_can_register', 'locked', 'registration', 'Z8d' ),
			'option:default_role'            => array( 'option', 'default_role', 'locked', 'registration', 'Z8d' ),
			'option:active_plugins'          => array( 'option', 'active_plugins', 'locked', null, 'Z8b' ),
			'option:template'                => array( 'option', 'template', 'locked', null, 'Z8b' ),
			'option:stylesheet'              => array( 'option', 'stylesheet', 'locked', null, 'Z8b' ),
			'option:nav_menu_options'        => array( 'option', 'nav_menu_options', 'locked', 'menu', 'Z8d' ),
			'option:*'                       => array( 'option', 'blogname', 'z8', 'option', null ),
			// Theme mods.
			'theme_mod:nav_menu_locations'   => array( 'theme_mod', 'nav_menu_locations', 'z8', 'menu', null ),
			'theme_mod:custom_css_post_id'   => array( 'theme_mod', 'custom_css_post_id', 'z8', 'custom_css', null ),
			'theme_mod:*'                    => array( 'theme_mod', 'header_image', 'locked', 'theme_mods', 'Z8d' ),
			// Files.
			'files:attachment'               => array( 'files', 'attachment', 'z8', 'attachment', null ),
			'files:*'                        => array( 'files', 'languages', 'locked', null, 'Z8b' ),
		);
	}

	#[DataProvider( 'ownership' )]
	public function testEachObjectTypeHasTheOwnerTheTableGivesIt( string $resolver, string $asked, string $status, ?string $kind, ?string $stage ): void {
		$key    = (string) $this->dataName();
		$answer = 'option' === $resolver ? AB_MCP_Sync_Kinds::option( $asked, 'wp_' ) : call_user_func( array( AB_MCP_Sync_Kinds::class, $resolver ), $asked );
		self::assertSame( $key, $answer['key'] );
		self::assertSame( $status, $answer['status'] );
		self::assertSame( $kind, $answer['kind'] );
		self::assertSame( $stage, $answer['stage'] );
		if ( null !== $stage ) {
			self::assertNotSame( '', (string) $answer['reason'], 'A locked type says why.' );
		}
		self::assertMatchesRegularExpression( '/^A\d+\z/', (string) $answer['art'] );
		$this->assertReason( 'ownership', $key );
	}

	/* ------------------------------------------------------------ locks */

	/**
	 * One case per entry of LOCKS: the kinds and the operation it locks,
	 * and the stage that brings it.
	 */
	public static function locks(): array {
		$all = array_keys( self::OWNS );
		return array(
			'*:delete'                     => array( $all, 'delete', 'Z8d' ),
			'menu:remove_item'             => array( array( 'menu' ), 'remove_item', 'Z8d' ),
			'widget_area:remove_instance'  => array( array( 'widget_area' ), 'remove_instance', 'Z8d' ),
			'custom_css:create'            => array( array( 'custom_css' ), 'create', 'Z8d' ),
			'term:create_with_meta'        => array( array( 'term' ), 'create_with_meta', 'Z8d' ),
			'post:create_with_object_meta' => array( array( 'post' ), 'create_with_object_meta', 'Z8d' ),
			'*:meta_without_rule'          => array( array( 'post', 'term' ), 'meta_without_rule', 'Z8d' ),
			'post:builder'                 => array( array( 'post' ), 'builder', 'Z8c' ),
			'attachment:replace_file'      => array( array( 'attachment' ), 'replace_file', 'Z8b' ),
			'attachment:shared_path'       => array( array( 'attachment' ), 'shared_path', 'Z8b' ),
			'option:unclassified'          => array( array( 'option' ), 'unclassified', 'Z8d' ),
			'*:mutual_references'          => array( $all, 'mutual_references', 'Z11c' ),
		);
	}

	#[DataProvider( 'locks' )]
	public function testEachLockedOperationNamesItsStage( array $kinds, string $op, string $stage ): void {
		$key = (string) $this->dataName();
		foreach ( $kinds as $kind ) {
			$lock = AB_MCP_Sync_Kinds::lock( $kind, $op );
			self::assertIsArray( $lock, "$kind:$op" );
			self::assertSame( $key, $lock['key'] );
			self::assertSame( $stage, $lock['stage'] );
			self::assertNotSame( '', $lock['reason'] );
		}
		$this->assertReason( 'locks', $key );
	}

	public function testWhatIsNotLockedHasNoLock(): void {
		foreach ( array( array( 'post', 'create' ), array( 'post', 'update' ), array( 'term', 'create' ), array( 'menu', 'create' ), array( 'menu', 'add_item' ), array( 'custom_css', 'update' ), array( 'option', 'update' ), array( 'term', 'remove_item' ) ) as list( $kind, $op ) ) {
			self::assertNull( AB_MCP_Sync_Kinds::lock( $kind, $op ), "$kind:$op" );
		}
	}

	/* ------------------------------------------------------- exclusions */

	/**
	 * One case per entry of the lists of AB_MCP_Sync_Exclusions: the list
	 * and a name it must answer.
	 */
	public static function exclusions(): array {
		return array(
			'post_type:revision'               => array( 'post_type', 'revision' ),
			'post_type:oembed_cache'           => array( 'post_type', 'oembed_cache' ),
			'post_type:user_request'           => array( 'post_type', 'user_request' ),
			'option:siteurl'                   => array( 'option', 'siteurl' ),
			'option:home'                      => array( 'option', 'home' ),
			'option:blog_public'               => array( 'option', 'blog_public' ),
			'option:admin_email'               => array( 'option', 'admin_email' ),
			'option:cron'                      => array( 'option', 'cron' ),
			'option:rewrite_rules'             => array( 'option', 'rewrite_rules' ),
			'option:recently_activated'        => array( 'option', 'recently_activated' ),
			'option:recently_edited'           => array( 'option', 'recently_edited' ),
			'option:uninstall_plugins'         => array( 'option', 'uninstall_plugins' ),
			'option:db_version'                => array( 'option', 'db_version' ),
			'option:initial_db_version'        => array( 'option', 'initial_db_version' ),
			'option:fresh_site'                => array( 'option', 'fresh_site' ),
			'option_prefix:_transient_'        => array( 'option', '_transient_timeout_feed_x' ),
			'option_prefix:_site_transient_'   => array( 'option', '_site_transient_update_plugins' ),
			'option_prefix:ab_mcp_'            => array( 'option', 'ab_mcp_staging_db_version' ),
			'option_suffix:.lock'              => array( 'option', 'core_updater.lock' ),
			'post_meta:_edit_lock'             => array( 'post_meta', '_edit_lock' ),
			'post_meta:_edit_last'             => array( 'post_meta', '_edit_last' ),
			'post_meta:_wp_old_slug'           => array( 'post_meta', '_wp_old_slug' ),
			'post_meta:_wp_old_date'           => array( 'post_meta', '_wp_old_date' ),
			'post_meta:_wp_desired_post_slug'  => array( 'post_meta', '_wp_desired_post_slug' ),
			'post_meta:_encloseme'             => array( 'post_meta', '_encloseme' ),
			'post_meta:_pingme'                => array( 'post_meta', '_pingme' ),
			'post_meta_prefix:_wp_trash_meta_' => array( 'post_meta', '_wp_trash_meta_comments_status' ),
			'post_meta_prefix:_oembed_'        => array( 'post_meta', '_oembed_time_0123456789abcdef0123456789abcdef' ),
			'post_meta_prefix:_ab_mcp_'        => array( 'post_meta', '_ab_mcp_sha256' ),
			'column:posts.ID'                  => array( 'column', 'posts.ID' ),
			'column:posts.guid'                => array( 'column', 'posts.guid' ),
			'column:posts.post_modified'       => array( 'column', 'posts.post_modified' ),
			'column:posts.post_modified_gmt'   => array( 'column', 'posts.post_modified_gmt' ),
			'column:posts.comment_count'       => array( 'column', 'posts.comment_count' ),
			'column:posts.to_ping'             => array( 'column', 'posts.to_ping' ),
			'column:posts.pinged'              => array( 'column', 'posts.pinged' ),
			'column:term_taxonomy.count'       => array( 'column', 'term_taxonomy.count' ),
		);
	}

	#[DataProvider( 'exclusions' )]
	public function testEachExcludedNameIsNeverTakenOver( string $list, string $name ): void {
		$key = (string) $this->dataName();
		switch ( $list ) {
			case 'post_type':
				self::assertTrue( AB_MCP_Sync_Exclusions::post_type( $name ) );
				self::assertSame( 'excluded', AB_MCP_Sync_Kinds::post_type( $name )['status'], 'The owner table answers the exclusion first.' );
				break;
			case 'option':
				self::assertTrue( AB_MCP_Sync_Exclusions::option( $name ) );
				self::assertSame( 'excluded', AB_MCP_Sync_Kinds::option( $name, 'wp_' )['status'] );
				break;
			case 'post_meta':
				self::assertTrue( AB_MCP_Sync_Exclusions::post_meta( $name ) );
				break;
			case 'column':
				self::assertTrue( AB_MCP_Sync_Exclusions::column( strtok( $name, '.' ), substr( $name, strpos( $name, '.' ) + 1 ) ) );
				break;
		}
		$this->assertReason( 'exclusions', $key );
	}

	public function testWhatDescribesTheContentIsNotExcluded(): void {
		// The keys wp_duplicate_post copies because they describe the content
		// (tools/class-duplicate-meta.php:25): the template and the featured
		// image have their own way (decision 28), the hooked blocks the editor
		// removed are content; none of them is excluded.
		$line = file( dirname( __DIR__ ) . '/includes/tools/class-duplicate-meta.php' )[24];
		self::assertStringContainsString( "const WORDPRESS_KEYS = array( '_wp_page_template', '_thumbnail_id', '_wp_ignored_hooked_blocks' );", $line );
		foreach ( array( '_wp_page_template', '_thumbnail_id', '_wp_ignored_hooked_blocks', '_wp_attached_file', '_wp_attachment_metadata', '_menu_item_object_id', 'footnotes', '_EDIT_LOCK', '_edit_lock ', 'x_wp_old_slug' ) as $key ) {
			self::assertFalse( AB_MCP_Sync_Exclusions::post_meta( $key ), $key );
		}
		// A navigation and a changeset are no group B (the one is A6 in Z8,
		// the other locked until Z8d); the options of the core with a
		// schema go to the option kind; the columns of content are compared.
		foreach ( array( 'wp_navigation', 'customize_changeset', 'nav_menu_item', 'post' ) as $type ) {
			self::assertFalse( AB_MCP_Sync_Exclusions::post_type( $type ), $type );
		}
		foreach ( array( 'blogname', 'page_on_front', 'permalink_structure', 'sticky_posts', 'show_on_front', '.lock', 'ab_mcp', 'transient_x', 'SITEURL' ) as $option ) {
			self::assertFalse( AB_MCP_Sync_Exclusions::option( $option ), $option );
		}
		foreach ( array( array( 'posts', 'post_content' ), array( 'posts', 'post_status' ), array( 'posts', 'post_date' ), array( 'posts', 'post_name' ), array( 'posts', 'id' ), array( 'term_taxonomy', 'parent' ), array( 'term_taxonomy', 'description' ), array( 'terms', 'count' ) ) as list( $table, $column ) ) {
			self::assertFalse( AB_MCP_Sync_Exclusions::column( $table, $column ), "$table.$column" );
		}
	}

	/* ------------------------------------------------- one reason each */

	public function testEveryEntryHasItsTestAndItsReasonAndNoOtherIsThere(): void {
		$reasons = self::reasons();
		$sorted  = static function ( array $keys ): array {
			sort( $keys );
			return $keys;
		};
		self::assertSame( $sorted( array_keys( AB_MCP_Sync_Kinds::OWNERSHIP ) ), $sorted( array_keys( self::ownership() ) ), 'A test per entry of OWNERSHIP.' );
		self::assertSame( $sorted( array_keys( AB_MCP_Sync_Kinds::OWNERSHIP ) ), $sorted( array_keys( $reasons['ownership'] ) ), 'A reason per entry of OWNERSHIP.' );
		self::assertSame( $sorted( array_keys( AB_MCP_Sync_Kinds::LOCKS ) ), $sorted( array_keys( self::locks() ) ), 'A test per lock.' );
		self::assertSame( $sorted( array_keys( AB_MCP_Sync_Kinds::LOCKS ) ), $sorted( array_keys( $reasons['locks'] ) ), 'A reason per lock.' );

		$listed = array();
		foreach ( array(
			'post_type'        => AB_MCP_Sync_Exclusions::POST_TYPES,
			'option'           => AB_MCP_Sync_Exclusions::OPTIONS,
			'option_prefix'    => AB_MCP_Sync_Exclusions::OPTION_PREFIXES,
			'option_suffix'    => AB_MCP_Sync_Exclusions::OPTION_SUFFIXES,
			'post_meta'        => AB_MCP_Sync_Exclusions::POST_META_KEYS,
			'post_meta_prefix' => AB_MCP_Sync_Exclusions::POST_META_PREFIXES,
		) as $list => $names ) {
			foreach ( $names as $name ) {
				$listed[] = $list . ':' . $name;
			}
		}
		foreach ( AB_MCP_Sync_Exclusions::COLUMNS as $table => $columns ) {
			foreach ( $columns as $column ) {
				$listed[] = 'column:' . $table . '.' . $column;
			}
		}
		self::assertSame( $sorted( $listed ), $sorted( array_keys( self::exclusions() ) ), 'A test per excluded name.' );
		self::assertSame( $sorted( $listed ), $sorted( array_keys( $reasons['exclusions'] ) ), 'A reason per excluded name.' );

		// Every reason that is not the rule for the others points somewhere.
		foreach ( $reasons as $group => $entries ) {
			foreach ( $entries as $key => $entry ) {
				if ( '*' !== substr( $key, -1 ) && 'locks' !== $group ) {
					self::assertNotEmpty( array_merge( $entry['core'] ?? array(), $entry['plugin'] ?? array() ), "$group $key names where WordPress or this plugin shows it." );
				}
			}
		}
	}

	public function testTheCitationsOfThisPluginHold(): void {
		$count = 0;
		foreach ( self::reasons() as $group => $entries ) {
			foreach ( $entries as $key => $entry ) {
				foreach ( $entry['plugin'] ?? array() as list( $file, $line, $text ) ) {
					$lines = file( dirname( __DIR__ ) . '/' . $file );
					self::assertIsArray( $lines, $file );
					self::assertStringContainsString( $text, (string) ( $lines[ $line - 1 ] ?? '' ), "$group $key: $file:$line" );
					++$count;
				}
			}
		}
		self::assertGreaterThan( 15, $count, 'The scan finds them.' );
	}

	/* --------------------------------------------------------- registry */

	public function testAKindRegistersForExactlyWhatTheTableGivesIt(): void {
		foreach ( self::OWNS as $name => $owns ) {
			self::assertTrue( AB_MCP_Sync_Kinds::register( new FakeSyncKind( $name, 5, array_reverse( $owns ) ) ), $name );
		}
		foreach ( array_keys( self::OWNS ) as $name ) {
			self::assertSame( $name, AB_MCP_Sync_Kinds::get( $name )->kind() );
		}
		self::assertNull( AB_MCP_Sync_Kinds::get( 'theme_mods' ) );
		self::assertSame( array_keys( self::OWNS ), array_map( static fn( $k ) => $k->kind(), AB_MCP_Sync_Kinds::all() ) );

		// Every entry without a stage that names a kind is one of these.
		$table = array();
		foreach ( AB_MCP_Sync_Kinds::OWNERSHIP as $key => $entry ) {
			if ( null !== $entry['kind'] && ! isset( $entry['stage'] ) ) {
				$table[ $entry['kind'] ][] = $key;
			}
		}
		foreach ( $table as $kind => $keys ) {
			sort( $keys );
			$owns = self::OWNS[ $kind ];
			sort( $owns );
			self::assertSame( $owns, $keys, $kind );
		}
		self::assertSame( array_keys( self::OWNS ), array_keys( array_intersect_key( self::OWNS, $table ) ) );
	}

	public function testASecondKindOfTheSameNameIsRefused(): void {
		self::assertTrue( AB_MCP_Sync_Kinds::register( new FakeSyncKind( 'term', 5, self::OWNS['term'] ) ) );
		$first  = AB_MCP_Sync_Kinds::get( 'term' );
		$second = AB_MCP_Sync_Kinds::register( new FakeSyncKind( 'term', 5, self::OWNS['term'] ) );
		self::assertInstanceOf( WP_Error::class, $second );
		self::assertSame( 'ab_mcp_sync_kind_duplicate', $second->get_error_code() );
		self::assertSame( $first, AB_MCP_Sync_Kinds::get( 'term' ), 'The first stays.' );
		self::assertCount( 1, AB_MCP_Sync_Kinds::all() );
	}

	public function testAKindThatClaimsMoreOrLessThanItsOwnIsRefused(): void {
		$cases = array(
			'less'      => array( 'menu', array( 'taxonomy:nav_menu', 'post_type:nav_menu_item' ) ),
			'more'      => array( 'term', array_merge( self::OWNS['term'], array( 'taxonomy:nav_menu' ) ) ),
			'another'   => array( 'post', array_merge( self::OWNS['post'], array( 'post_type:attachment' ) ) ),
			'unknown'   => array( 'option', array( 'option:*', 'option:blogname' ) ),
			'no owner'  => array( 'comments', array( 'post_type:post' ) ),
			'nothing'   => array( 'custom_css', array() ),
		);
		foreach ( $cases as $case => list( $name, $claims ) ) {
			$answer = AB_MCP_Sync_Kinds::register( new FakeSyncKind( $name, 5, $claims ) );
			self::assertInstanceOf( WP_Error::class, $answer, $case );
			self::assertSame( 'ab_mcp_sync_kind_responsibility', $answer->get_error_code(), $case );
			self::assertNull( AB_MCP_Sync_Kinds::get( $name ), $case );
		}
	}

	public function testAKindForWhatZ8LocksIsRefusedWithItsStage(): void {
		foreach ( array(
			'theme_mods'   => array( array( 'theme_mod:*' ), 'theme_mod:*', 'Z8d' ),
			'roles'        => array( array( 'option:{prefix}user_roles' ), 'option:{prefix}user_roles', 'Z8d' ),
			'registration' => array( array( 'option:users_can_register', 'option:default_role' ), 'option:users_can_register', 'Z8d' ),
			'menu'         => array( array_merge( self::OWNS['menu'], array( 'option:nav_menu_options' ) ), 'option:nav_menu_options', 'Z8d' ),
		) as $name => list( $claims, $key, $stage ) ) {
			$answer = AB_MCP_Sync_Kinds::register( new FakeSyncKind( $name, 5, $claims ) );
			self::assertInstanceOf( WP_Error::class, $answer, $name );
			self::assertSame( 'ab_mcp_sync_kind_locked', $answer->get_error_code(), $name );
			self::assertSame( $key, $answer->get_error_data()['key'], $name );
			self::assertSame( $stage, $answer->get_error_data()['stage'], $name );
		}
		// Claiming none of it: still nothing it may take over in Z8.
		$answer = AB_MCP_Sync_Kinds::register( new FakeSyncKind( 'theme_mods', 5, array() ) );
		self::assertSame( 'ab_mcp_sync_kind_responsibility', $answer->get_error_code() );
		self::assertSame( array(), AB_MCP_Sync_Kinds::all() );
	}

	public function testAKindNeedsANameAndAPhaseItWritesIn(): void {
		foreach ( array( '', 'Term', 'term-x', '1term', str_repeat( 'a', 33 ) ) as $name ) {
			$answer = AB_MCP_Sync_Kinds::register( new FakeSyncKind( $name, 5, array() ) );
			self::assertSame( 'ab_mcp_sync_kind_invalid', $answer->get_error_code(), $name );
		}
		foreach ( array( 1, 4, 6, 7, 8, 0, '5' ) as $phase ) {
			$answer = AB_MCP_Sync_Kinds::register( new FakeSyncKind( 'term', $phase, self::OWNS['term'] ) );
			self::assertInstanceOf( WP_Error::class, $answer, var_export( $phase, true ) );
			self::assertSame( 'ab_mcp_sync_kind_phase', $answer->get_error_code(), var_export( $phase, true ) );
		}
		foreach ( array( 2, 3, 5 ) as $phase ) {
			AB_MCP_Sync_Kinds::reset();
			self::assertTrue( AB_MCP_Sync_Kinds::register( new FakeSyncKind( 'term', $phase, self::OWNS['term'] ) ), (string) $phase );
		}
	}

	public function testAllListsTheKindsByPhaseThenInTheOrderTheyCame(): void {
		AB_MCP_Sync_Kinds::register( new FakeSyncKind( 'post', 5, self::OWNS['post'] ) );
		AB_MCP_Sync_Kinds::register( new FakeSyncKind( 'option', 3, self::OWNS['option'] ) );
		AB_MCP_Sync_Kinds::register( new FakeSyncKind( 'menu', 5, self::OWNS['menu'] ) );
		AB_MCP_Sync_Kinds::register( new FakeSyncKind( 'term', 2, self::OWNS['term'] ) );
		AB_MCP_Sync_Kinds::register( new FakeSyncKind( 'attachment', 5, self::OWNS['attachment'] ) );
		self::assertSame( array( 'term', 'option', 'post', 'menu', 'attachment' ), array_map( static fn( $k ) => $k->kind(), AB_MCP_Sync_Kinds::all() ) );
		AB_MCP_Sync_Kinds::reset();
		self::assertSame( array(), AB_MCP_Sync_Kinds::all() );
		self::assertNull( AB_MCP_Sync_Kinds::get( 'post' ) );
	}

	public function testTheRolesFollowThePrefixOfTheSite(): void {
		self::assertSame( 'locked', AB_MCP_Sync_Kinds::option( 'wp_2_user_roles', 'wp_2_' )['status'] );
		self::assertSame( 'option:*', AB_MCP_Sync_Kinds::option( 'wp_user_roles', 'wp_2_' )['key'], 'Another prefix: not the roles of this site.' );
		$GLOBALS['wpdb'] = (object) array( 'prefix' => 'wpx_' );
		try {
			self::assertSame( 'option:{prefix}user_roles', AB_MCP_Sync_Kinds::option( 'wpx_user_roles' )['key'], 'Without a prefix, the one of $wpdb.' );
		} finally {
			unset( $GLOBALS['wpdb'] );
		}
		self::assertSame( 'option:*', AB_MCP_Sync_Kinds::option( 'widget_' )['key'], 'widget_ alone is no widget row.' );
		self::assertSame( 'option:*', AB_MCP_Sync_Kinds::option( 'theme_mods_' )['key'] );
	}
}

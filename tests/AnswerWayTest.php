<?php
/**
 * A refusal names the way: what does not work, why, and what makes it work —
 * the switch and where it is, the right and who has it, the tool or
 * parameter to use instead. One case per kind of refusal, and for the
 * notes that are no WP_Error; on top, every literal WP_Error message under
 * includes/ is read from the source and must name a way, so a text that
 * loses its way fails even without a case of its own. Errors WordPress
 * itself returns are passed on as they come and are not checked here.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use AB_MCP_Auth;
use AB_MCP_Builders;
use AB_MCP_REST_Controller;
use AB_MCP_Security;
use AB_MCP_Tool_Registry;
use AB_MCP_Tools_Content;
use AB_MCP_Tools_Media;
use AB_MCP_Tools_Meta_Auth;
use AB_MCP_Tools_Seo;
use AB_MCP_Tools_Taxonomy_Comments;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use WP_REST_Request;

require_once __DIR__ . '/../includes/tools/class-tools-meta-auth.php';
require_once __DIR__ . '/../includes/tools/class-tools-seo.php';
require_once __DIR__ . '/answer-way-stubs.php';

final class AnswerWayTest extends TestCase {

	protected function setUp(): void {
		ab_test_reset();
		$GLOBALS['ab_test_way'] = array();
		self::scope( 'full' );
	}

	protected function tearDown(): void {
		self::scope( 'full' );
	}

	private static function scope( string $scope ): void {
		( new ReflectionProperty( AB_MCP_Auth::class, 'current_scope' ) )->setValue( null, $scope );
	}

	private static function message( $res ): string {
		self::assertInstanceOf( \WP_Error::class, $res );
		return (string) $res->get_error_message();
	}

	private static function allow( string ...$caps ): void {
		$GLOBALS['ab_test_can'] = static fn( string $cap ): bool => array() === $caps || in_array( $cap, $caps, true );
	}

	/* ------------------------------------------------- the policy gate */

	/**
	 * Each refusal of the policy gate ends with the direct link and the
	 * request to pass it on and try again (AB_MCP_Guidance::compose()).
	 */
	private static function assertLeadsTo( string $url, string $msg ): void {
		self::assertStringContainsString( ' Direct link: ' . $url . ' ', $msg );
		self::assertStringEndsWith( \AB_MCP_Guidance::handover(), $msg );
		self::assertStringContainsString( 'Pass this on to the person you are working for in a friendly way', $msg );
		self::assertStringContainsString( 'try again once it is done', $msg );
	}

	public function testAMissingCapabilityNamesTheAccountAndTheRole(): void {
		ab_test_add_user( 3 );
		$GLOBALS['ab_test_current_user'] = 3;
		$GLOBALS['ab_test_can']          = static fn(): bool => false;
		$msg = self::message( AB_MCP_Security::authorize( 'wp_list_posts', array( 'capability' => 'edit_posts' ) ) );
		self::assertStringStartsWith( 'The account of this connection lacks the capability "edit_posts" that the tool "wp_list_posts" needs.', $msg );
		self::assertStringContainsString( 'ask an administrator to give the account "user3" a role with the capability "edit_posts" under Users, or connect the app again with an account that has it', $msg );
		self::assertLeadsTo( 'https://example.test/wp-admin/users.php', $msg );
	}

	public function testADisabledToolNamesTheSwitch(): void {
		self::allow();
		update_option( 'ab_mcp_tool_state', array( 'wp_list_posts' => false ) );
		$res = AB_MCP_Security::authorize( 'wp_list_posts', array( 'capability' => 'edit_posts' ) );
		self::assertSame( 'ab_mcp_tool_disabled', $res->get_error_code() );
		self::assertStringContainsString( 'The tool "wp_list_posts" is switched off in the fine-tuning of AlphaBridge MCP on this site.', self::message( $res ) );
		self::assertStringContainsString( 'an administrator switches "wp_list_posts" on under Settings → AlphaBridge MCP → Fine-tuning and saves the changes', self::message( $res ) );
		self::assertLeadsTo( 'https://example.test/wp-admin/options-general.php?page=alphabridge-mcp#ab-tool-wp_list_posts', self::message( $res ) );
	}

	public function testReadModeNamesTheSwitchAndTheRisk(): void {
		self::allow();
		$res = AB_MCP_Security::authorize( 'wp_update_post', array( 'capability' => 'edit_posts' ) );
		self::assertSame( 'ab_mcp_read_mode', $res->get_error_code() );
		self::assertStringStartsWith( 'Write access is off on this site, so the tool "wp_update_post" did not run: it changes the site', self::message( $res ) );
		self::assertStringContainsString( 'an administrator can switch on write access at the top of Settings → AlphaBridge MCP', self::message( $res ) );
		self::assertStringContainsString( 'at the site owner\'s own risk', self::message( $res ) );
		self::assertLeadsTo( 'https://example.test/wp-admin/options-general.php?page=alphabridge-mcp#ab-mode', self::message( $res ) );
	}

	public function testANarrowAccessLevelNamesHowToWidenIt(): void {
		self::allow();
		self::scope( 'read' );
		// The scope is checked after the site mode; in Read the mode answers first.
		update_option( 'ab_mcp_options', array( 'site_mode' => 'full' ) );
		$res = AB_MCP_Security::authorize( 'wp_update_post', array( 'capability' => 'edit_posts' ) );
		self::assertSame( 'ab_mcp_scope', $res->get_error_code() );
		self::assertStringStartsWith( 'This connection has the access level "Read only", which does not include the tool "wp_update_post".', self::message( $res ) );
		self::assertStringContainsString( 'connect the app again and choose the access level "Full" on the consent screen', self::message( $res ) );
		self::assertStringContainsString( 'Settings → AlphaBridge MCP', self::message( $res ) );
		self::assertLeadsTo( 'https://example.test/wp-admin/options-general.php?page=alphabridge-mcp#ab-connections', self::message( $res ) );
	}

	public function testTheRateLimitSaysHowManyAndHowLongToWait(): void {
		self::allow();
		update_option( 'ab_mcp_options', array( 'rate_limit_per_min' => 1 ) );
		self::assertTrue( AB_MCP_Security::authorize( 'wp_list_posts', array( 'capability' => 'edit_posts' ) ) );
		$res = AB_MCP_Security::authorize( 'wp_list_posts', array( 'capability' => 'edit_posts' ) );
		self::assertSame( 'ab_mcp_rate_limited', $res->get_error_code() );
		self::assertStringContainsString( 'at most 1 tool calls per minute', self::message( $res ) );
		self::assertStringContainsString( 'Wait until the next minute', self::message( $res ) );
	}

	/* ------------------------------------------------------ the tools */

	public function testAMissingPostNamesTheToolsThatFindIt(): void {
		self::allow();
		$msg = self::message( AB_MCP_Tools_Content::get_post( array( 'id' => 404 ) ) );
		self::assertStringContainsString( 'wp_list_posts', $msg );
	}

	public function testAnUnknownPostTypeNamesTheList(): void {
		self::allow();
		$msg = self::message( AB_MCP_Tools_Content::create_post( array( 'type' => 'nope', 'title' => 'X' ) ) );
		self::assertStringContainsString( 'wp_get_post_types', $msg );
	}

	public function testAnUnsupportedStatusNamesTheStatusesAndTheFilter(): void {
		self::allow();
		$msg = self::message( AB_MCP_Tools_Content::create_post( array( 'title' => 'X', 'status' => 'archived' ) ) );
		self::assertStringContainsString( 'draft, pending, publish, private and future', $msg );
		self::assertStringContainsString( 'ab_mcp_allowed_post_statuses', $msg );
	}

	public function testARefusedTrashNamesTheCheckAndWhatRemains(): void {
		self::allow();
		ab_test_add_post( 9, array( 'post_status' => 'publish' ) );
		$GLOBALS['ab_test_trash_refused'] = true;
		$msg = self::message( AB_MCP_Tools_Content::delete_post( array( 'id' => 9 ) ) );
		self::assertStringContainsString( 'could not be moved to the trash', $msg );
		self::assertStringContainsString( 'Check it with wp_get_post', $msg );
		self::assertStringContainsString( 'force=true, which cannot be undone', $msg );
		// The tool clears the old trash notes first, so the post is not
		// promised to be as it was.
		self::assertStringNotContainsString( 'unchanged', $msg );
	}

	public function testARefusedDeletionPromisesNothingAboutWhatIsLeft(): void {
		// wp_delete_post() removes terms, comments and custom fields before
		// the post's row and says false when only that last step fails.
		self::allow();
		ab_test_add_post( 10, array( 'post_status' => 'draft' ) );
		$GLOBALS['ab_test_delete_refused'] = true;
		$res = AB_MCP_Tools_Content::delete_post( array( 'id' => 10, 'force' => true ) );
		self::assertSame( 'ab_mcp_delete_failed', $res->get_error_code() );
		self::assertStringContainsString( 'Check it with wp_get_post', self::message( $res ) );
		self::assertStringNotContainsString( 'unchanged', self::message( $res ) );
	}

	public function testARefusedCommentDeletionPromisesNothingAboutWhatIsLeft(): void {
		self::allow();
		$GLOBALS['ab_test_comments'][4] = (object) array( 'comment_ID' => 4 );
		$res = AB_MCP_Tools_Taxonomy_Comments::delete_comment( array( 'comment_id' => 4, 'force' => true ) );
		self::assertSame( 'ab_mcp_delete_failed', $res->get_error_code() );
		self::assertStringContainsString( 'wp_get_comment', self::message( $res ) );
		self::assertStringNotContainsString( 'unchanged', self::message( $res ) );
	}

	/**
	 * Each media tool, asked for an id that is no file, names the list.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function mediaTools(): array {
		return array(
			'wp_get_media'    => array( 'get_media' ),
			'wp_update_media' => array( 'update_media' ),
			'wp_delete_media' => array( 'delete_media' ),
		);
	}

	#[DataProvider( 'mediaTools' )]
	public function testAMissingFileNamesTheMediaList( string $method ): void {
		self::allow();
		$res = AB_MCP_Tools_Media::$method( array( 'id' => 404, 'title' => 'X' ) );
		self::assertSame( 'ab_mcp_not_found', $res->get_error_code() );
		self::assertStringContainsString( 'Look it up with wp_list_media', self::message( $res ) );
	}

	public function testDeletingAPostAsAFileNamesTheMediaListNotOnlyAPlugin(): void {
		// delete_media lets any existing post through to wp_delete_attachment(),
		// which says false for everything that is not a file.
		self::allow();
		ab_test_add_post( 11, array( 'post_type' => 'page' ) );
		$res = AB_MCP_Tools_Media::delete_media( array( 'id' => 11 ) );
		self::assertSame( 'ab_mcp_delete_failed', $res->get_error_code() );
		self::assertStringContainsString( 'the id is not a file in the media library', self::message( $res ) );
		self::assertStringContainsString( 'wp_list_media', self::message( $res ) );
	}

	public function testAnAddressThatIsNotHttpNamesTheOtherUpload(): void {
		self::allow();
		$res = AB_MCP_Tools_Media::upload_from_url( array( 'url' => 'ftp://example.com/photo.jpg' ) );
		self::assertSame( 'ab_mcp_bad_url', $res->get_error_code() );
		self::assertStringContainsString( 'wp_upload_media', self::message( $res ) );
	}

	public function testAUserMetaKeyOutsideTheListNamesTheList(): void {
		self::allow();
		ab_test_add_user( 5 );
		$res = AB_MCP_Tools_Meta_Auth::get_user_meta_tool( array( 'user_id' => 5, 'key' => 'billing_token' ) );
		self::assertSame( 'ab_mcp_protected_meta', $res->get_error_code() );
		self::assertStringContainsString( 'first_name, last_name, nickname', self::message( $res ) );
		self::assertStringContainsString( 'leave key out', self::message( $res ) );
	}

	/* ------------------------------------------- meta keys, application passwords */

	public function testAPostMetaKeyOfAPostOneMayOnlyReadNamesTheRightToEditThePost(): void {
		// WordPress maps edit_post_meta to edit_post on the post first; the
		// tool lets in accounts that may only read it.
		self::allow( 'read_post' );
		ab_test_add_post( 12, array( 'post_status' => 'publish' ) );
		$res = AB_MCP_Tools_Content::get_post_meta_tool( array( 'id' => 12, 'key' => 'subtitle' ) );
		self::assertSame( 'ab_mcp_forbidden', $res->get_error_code() );
		self::assertStringContainsString( 'the right to edit the post itself', self::message( $res ) );
		self::assertStringContainsString( 'can restrict it further', self::message( $res ) );
		self::assertStringNotContainsString( 'usually because the plugin', self::message( $res ) );
	}

	public function testATermMetaKeyOneMayOnlyAssignNamesTheRightToEditTheTerm(): void {
		self::allow( 'assign_categories' );
		$GLOBALS['ab_test_way']['terms'][3]             = (object) array( 'term_id' => 3, 'taxonomy' => 'category' );
		$GLOBALS['ab_test_way']['taxonomies']['category'] = (object) array(
			'name' => 'category',
			'cap'  => (object) array( 'assign_terms' => 'assign_categories' ),
		);
		$res = AB_MCP_Tools_Meta_Auth::get_term_meta_tool( array( 'term_id' => 3, 'key' => 'color' ) );
		self::assertSame( 'ab_mcp_forbidden', $res->get_error_code() );
		self::assertStringContainsString( 'the right to edit the term itself', self::message( $res ) );
		self::assertStringContainsString( 'manage_categories', self::message( $res ) );
		self::assertStringNotContainsString( 'usually because the plugin', self::message( $res ) );
	}

	public function testTheApplicationPasswordGuardNamesNoSingleAction(): void {
		// Pro runs the same guard before creating and revoking.
		self::allow();
		ab_test_add_user( 5 );
		$res = AB_MCP_Tools_Meta_Auth::list_app_passwords( array( 'user_id' => 5 ) );
		self::assertSame( 'ab_mcp_no_app_pw', $res->get_error_code() );
		self::assertStringContainsString( 'update WordPress to use them', self::message( $res ) );
		self::assertStringNotContainsString( 'list', self::message( $res ) );

		require_once __DIR__ . '/app-passwords-stub.php';
		$GLOBALS['ab_test_way']['app_pw_available'] = false;
		$res = AB_MCP_Tools_Meta_Auth::list_app_passwords( array( 'user_id' => 5 ) );
		self::assertSame( 'ab_mcp_app_pw_off', $res->get_error_code() );
		self::assertStringContainsString( 'only over HTTPS or in a local environment', self::message( $res ) );
		self::assertStringContainsString( 'wp_is_application_passwords_available', self::message( $res ) );
		self::assertStringNotContainsString( 'list', self::message( $res ) );
	}

	/* ------------------------------------------------- terms, SEO, builders, errors */

	public function testARefusedTermDeletionNamesTheListAndTheDefaultTerm(): void {
		self::allow();
		$GLOBALS['ab_test_way']['taxonomies']['category'] = (object) array(
			'name' => 'category',
			'cap'  => (object) array( 'delete_terms' => 'delete_categories' ),
		);
		$res = AB_MCP_Tools_Taxonomy_Comments::delete_term( array( 'taxonomy' => 'category', 'term_id' => 1 ) );
		self::assertSame( 'ab_mcp_delete_failed', $res->get_error_code() );
		self::assertStringContainsString( 'Check the id with wp_list_terms', self::message( $res ) );
		self::assertStringContainsString( 'Settings → Writing', self::message( $res ) );
	}

	public function testWithoutASupportedSeoPluginTheAnswerNamesWhatItReadsAndWhereTheTitleIs(): void {
		self::allow();
		ab_test_add_post( 13 );
		$out = AB_MCP_Tools_Seo::get_seo( array( 'post_id' => 13 ) );
		self::assertIsArray( $out );
		self::assertStringContainsString( 'Yoast SEO and Rank Math', $out['note'] );
		self::assertStringContainsString( 'wp_get_post', $out['note'] );
	}

	public function testAPageInABuildersOwnTablesNamesItsEditorAndPromisesNoMore(): void {
		$note = new ReflectionMethod( AB_MCP_Builders::class, 'note_for' );
		$text = (string) $note->invoke(
			null,
			array(
				'id'      => 'mosaic',
				'name'    => 'Mosaic',
				'active'  => true,
				'storage' => 'C',
			)
		);
		self::assertStringContainsString( 'change it in the Mosaic editor', $text );
		// With the Pro add-on a database tool can read such tables; where the
		// editor runs is not checked.
		self::assertStringContainsString( 'this plugin\'s page builder tools do not read', $text );
		self::assertStringNotContainsString( 'no tool', $text );
		self::assertStringNotContainsString( 'wp-admin', $text );
	}

	public function testAToolThatThrowsSaysWhereTheErrorWentAndHowToCheck(): void {
		self::allow();
		$registry = new AB_MCP_Tool_Registry();
		$registry->set_current_group( 'content', 'Posts & Pages' );
		$registry->register(
			'wp_get_broken',
			array(
				'description' => 'Fails.',
				'callback'    => static function (): array {
					throw new \RuntimeException( '/srv/www/secret/path.php' );
				},
			)
		);
		$request = new WP_REST_Request();
		$request->set_json_params(
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => 'tools/call',
				'params'  => array(
					'name'      => 'wp_get_broken',
					'arguments' => array(),
				),
			)
		);
		$data = ( new AB_MCP_REST_Controller( $registry ) )->handle( $request )->get_data();
		self::assertTrue( $data['result']['isError'] );
		$text = $data['result']['content'][0]['text'];
		self::assertStringContainsString( 'the AlphaBridge MCP log (the ab_mcp_audit option)', $text );
		self::assertStringContainsString( 'Check the result with a read tool', $text );
		self::assertStringNotContainsString( '/srv/www', $text );
	}

	/* ------------------------------------------------------ every message */

	/**
	 * Every refusal or failure AlphaBridge words itself, as a literal
	 * WP_Error message in includes/: file:line => [code, message]. Read from
	 * the source, so a new message is checked without a case of its own.
	 * Messages built from a variable are checked where they are built.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function ownMessages(): array {
		$root  = dirname( __DIR__ ) . '/includes';
		$cases = array();
		$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $files as $file ) {
			if ( '.php' !== substr( (string) $file, -4 ) ) {
				continue;
			}
			$tokens = token_get_all( (string) file_get_contents( (string) $file ) );
			$count  = count( $tokens );
			for ( $i = 0; $i < $count; $i++ ) {
				if ( ! is_array( $tokens[ $i ] ) || T_NEW !== $tokens[ $i ][0] ) {
					continue;
				}
				$j = $i + 1;
				while ( is_array( $tokens[ $j ] ) && T_WHITESPACE === $tokens[ $j ][0] ) {
					++$j;
				}
				if ( ! is_array( $tokens[ $j ] ) || 'WP_Error' !== $tokens[ $j ][1] ) {
					continue;
				}
				$strings = array();
				$depth   = 0;
				$compose = false;
				for ( $k = $j + 1; $k < $count; $k++ ) {
					if ( '(' === $tokens[ $k ] ) {
						++$depth;
					} elseif ( ')' === $tokens[ $k ] && 0 === --$depth ) {
						break;
					}
					if ( is_array( $tokens[ $k ] ) && T_CONSTANT_ENCAPSED_STRING === $tokens[ $k ][0] ) {
						$strings[] = stripcslashes( substr( $tokens[ $k ][1], 1, -1 ) );
					} elseif ( is_array( $tokens[ $k ] ) && T_STRING === $tokens[ $k ][0] && 'compose' === $tokens[ $k ][1] ) {
						$compose = true;
					}
				}
				// A message built from a variable has no second literal.
				if ( count( $strings ) < 2 || ! preg_match( '/\s/', $strings[1] ) ) {
					continue;
				}
				// A message put together by AB_MCP_Guidance::compose() is its
				// reason and its steps: the steps name the way.
				$message = $strings[1];
				if ( $compose ) {
					$message = implode( ' ', array_filter( array_slice( $strings, 1 ), static fn( string $s ): bool => (bool) preg_match( '/\s/', $s ) ) );
				}
				$where           = substr( (string) $file, strlen( $root ) + 1 ) . ':' . $tokens[ $j ][2];
				$cases[ $where ] = array( $strings[0], $message );
			}
		}
		ksort( $cases );
		return $cases;
	}

	/**
	 * The ways a message can name: a tool or a filter by name, an account to
	 * connect with, a settings path, a parameter value, or a step to take.
	 */
	private const WAY = '/\b(wp_[a-z_]+|ab_mcp_[a-z_]+|[Cc]onnect with|Settings →|force=true|[Pp]ass |[Uu]se |[Aa]sk |[Cc]heck |[Tt]ry again|[Cc]hoose |[Gg]ive |[Cc]onvert |[Aa]ttach it|[Ww]ait |[Ss]witch |[Uu]pdate WordPress|register with|the site administrator|host can fix|plugin that owns)/';

	/**
	 * Messages whose way is what they list, or that report rather than refuse.
	 */
	private const WAY_IS_THE_LIST = array(
		'ab_mcp_missing_arg'        => 'names the arguments to pass',
		'ab_mcp_invalid_args'       => 'gives the expected schema',
		'ab_mcp_bad_status'         => 'names the statuses it accepts',
		'ab_mcp_elementor_not_json' => 'a note on a copy that was made, not a refusal',
		'ab_mcp_elementor_ids'      => 'a note on a copy that was made, not a refusal',
	);

	public function testTheReadmesClaimTheWayOnlyForAlphaBridgesOwnChecks(): void {
		// WordPress' own errors (a duplicate term name, a failed insert) are
		// passed on as they come, so neither readme promises more.
		foreach ( array( 'readme.txt', 'README.md' ) as $file ) {
			$text = (string) preg_replace( '/\s+/', ' ', (string) file_get_contents( AB_MCP_PATH . $file ) );
			self::assertStringContainsString( 'Errors WordPress itself reports are passed on as WordPress words them.', $text, $file );
			self::assertStringNotContainsString( 'Every refusal names the way', $text, $file );
			self::assertStringNotContainsString( 'a refused or failed call says', $text, $file );
		}
	}

	public function testTheMessagesAreFoundAtAll(): void {
		// Guards the reader below: without it an empty provider would pass.
		self::assertGreaterThan( 80, count( self::ownMessages() ) );
	}

	#[DataProvider( 'ownMessages' )]
	public function testEveryOwnRefusalNamesAWay( string $code, string $message ): void {
		if ( isset( self::WAY_IS_THE_LIST[ $code ] ) ) {
			self::assertNotSame( '', $message );
			return;
		}
		self::assertMatchesRegularExpression( self::WAY, $message, $code . ': ' . $message );
	}
}

<?php
/**
 * The option guard of stage 2 (4.17.0): one list, two questions.
 *
 * option_write_refused() stays as strict as it was; confirmed_write_refused()
 * lets exactly the registration pair through (users_can_register,
 * default_role) and refuses everything else the first one refuses;
 * option_class() names the class from the two answers; resolve_option()
 * settles which row a given name addresses under either question, so that
 * a condition, a preview and the write act on the same row. The list gained
 * the six salts of wp_salt(), the needle «_salt» and fs_accounts. A refusal
 * names the way of the name's group, and a tool of Pro only where the
 * edition of the site has it.
 *
 * The database is a stand-in that compares option_name the way a folding
 * collation does (case, and i/j, u/v as in Classical Latin), so a spelling
 * lands on the row it would land on in MySQL.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use AB_MCP_Option_Guard;
use PHPUnit\Framework\TestCase;
use WP_Error;

require_once __DIR__ . '/undo-stubs.php';
require_once __DIR__ . '/../includes/class-option-guard.php';
require_once __DIR__ . '/../includes/undo/class-undo.php';

/**
 * The options table as MySQL answers AB_MCP_Undo::option_row(): the stored
 * spelling of the row the given name lands on.
 */
final class FoldingOptionsWpdb {
	public $options     = 'wp_options';
	public $last_error  = '';
	public $last_result = array();
	/** @var bool Whether the column folds (false: a binary collation). */
	public $folds = true;
	/** @var string[] Names looked up, in order. */
	public $asked = array();

	public function prepare( $query, ...$args ) {
		foreach ( $args as $a ) {
			$query = preg_replace_callback( '/%s/', static fn(): string => "'" . addslashes( (string) $a ) . "'", $query, 1 );
		}
		return $query;
	}

	private function fold( string $s ): string {
		return $this->folds ? strtr( strtolower( $s ), array( 'j' => 'i', 'v' => 'u' ) ) : $s;
	}

	public function query( $query ) {
		$this->last_result = array();
		$this->last_error  = '';
		if ( preg_match( "/FROM wp_options WHERE option_name = '(.*)' LIMIT 1$/", (string) $query, $m ) ) {
			$name          = stripslashes( $m[1] );
			$this->asked[] = $name;
			foreach ( array_keys( $GLOBALS['ab_test_options'] ) as $stored ) {
				if ( $this->fold( (string) $stored ) === $this->fold( $name ) ) {
					$this->last_result = array( (object) array( 'option_name' => (string) $stored, 'autoload' => 'yes' ) );
					break;
				}
			}
		}
		return count( $this->last_result );
	}
}

final class OptionGuardStageTwoTest extends TestCase {

	/** The seven names Z7 added to the list by name (Entscheid 14). */
	private const HARDENED = array( 'secure_auth_key', 'secure_auth_salt', 'logged_in_key', 'logged_in_salt', 'nonce_key', 'nonce_salt', 'fs_accounts' );

	/** A name of every group that stays closed, for both questions. */
	private const CLOSED = array(
		'active_plugins', 'template', 'stylesheet', 'active_sitewide_plugins', 'cron', 'siteurl', 'home',
		'ab_mcp_tokens', 'ab_mcp_pro_undo_index', 'auth_key', 'auth_salt', 'secret', 'secret_key', 'alloptions', 'notoptions',
		'wp_user_roles', 'mailserver_url', 'mailserver_login', 'mailserver_pass', 'mailserver_port', 'stripe_secret_key',
		'secure_auth_key', 'secure_auth_salt', 'logged_in_key', 'logged_in_salt', 'nonce_key', 'nonce_salt', 'fs_accounts', 'my_plugin_salt',
	);

	private FoldingOptionsWpdb $db;

	protected function setUp(): void {
		ab_test_reset();
		$this->db        = new FoldingOptionsWpdb();
		$GLOBALS['wpdb'] = $this->db;
		$GLOBALS['ab_test_options'] = array(
			'users_can_register' => '0',
			'default_role'       => 'subscriber',
			'active_plugins'     => array( 'akismet/akismet.php' ),
			'blogname'           => 'Site',
		);
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
	}

	private function edition( string $edition ): void {
		add_filter( 'ab_mcp_site_edition', static fn(): string => $edition );
	}

	private static function message( $answer ): string {
		self::assertInstanceOf( WP_Error::class, $answer );
		self::assertSame( 'ab_mcp_blocked', $answer->get_error_code() );
		return $answer->get_error_message();
	}

	/* ------------------------------------------------------------ the list */

	public function testTheSaltsAndFreemiusAreOnTheListByNameAndTheNeedleCatchesEveryOtherSalt(): void {
		$lists = AB_MCP_Option_Guard::write_guard_lists();
		foreach ( self::HARDENED as $name ) {
			self::assertContains( $name, $lists['exact'], $name . ' is on the list by name, so the scan of wp_search_replace leaves it out by name too.' );
			self::assertInstanceOf( WP_Error::class, AB_MCP_Option_Guard::option_write_refused( $name ), $name );
			self::assertInstanceOf( WP_Error::class, AB_MCP_Option_Guard::confirmed_write_refused( $name ), $name );
		}
		self::assertContains( '_salt', $lists['needles'] );
		foreach ( array( 'my_plugin_salt', 'WOO_SESSION_SALT', 'cache_salt_v2' ) as $name ) {
			self::assertInstanceOf( WP_Error::class, AB_MCP_Option_Guard::option_write_refused( $name ), $name . ' holds the needle _salt' );
		}
		foreach ( array( 'salted_caramel', 'blog_salutation', 'saltire' ) as $name ) {
			self::assertFalse( AB_MCP_Option_Guard::option_write_refused( $name ), $name . ' has no "_salt" in it' );
		}
	}

	/* ------------------------------------------------------- two questions */

	public function testTheFirstQuestionStillRefusesThePairAndTheSecondLetsExactlyThePairThrough(): void {
		foreach ( AB_MCP_Option_Guard::CONFIRMED as $name ) {
			self::assertInstanceOf( WP_Error::class, AB_MCP_Option_Guard::option_write_refused( $name ), $name . ': unchanged for every writer that does not confirm' );
			self::assertFalse( AB_MCP_Option_Guard::confirmed_write_refused( $name ), $name );
		}
		self::assertSame( array( 'users_can_register', 'default_role' ), AB_MCP_Option_Guard::CONFIRMED );
	}

	public function testTheSecondQuestionRefusesEverythingElseTheFirstRefuses(): void {
		foreach ( self::CLOSED as $name ) {
			self::assertInstanceOf( WP_Error::class, AB_MCP_Option_Guard::option_write_refused( $name ), $name );
			self::assertInstanceOf( WP_Error::class, AB_MCP_Option_Guard::confirmed_write_refused( $name ), $name );
		}
		foreach ( array( 'blogname', 'posts_per_page', 'my_plugin_label' ) as $name ) {
			self::assertFalse( AB_MCP_Option_Guard::option_write_refused( $name ), $name );
			self::assertFalse( AB_MCP_Option_Guard::confirmed_write_refused( $name ), $name );
		}
	}

	public function testTheSecondQuestionOpensThePairOnlyInItsOwnSpelling(): void {
		// Another case, the spaces, a fold: refused by the question as handed
		// in. Only resolve_option() may find such a spelling to be the row.
		foreach ( array( 'DEFAULT_ROLE', 'Default_Role', ' default_role ', 'defavlt_role', 'vsers_can_register', 'Users_Can_Register' ) as $name ) {
			self::assertInstanceOf( WP_Error::class, AB_MCP_Option_Guard::confirmed_write_refused( $name ), var_export( $name, true ) );
		}
	}

	public function testTheSecondQuestionAsksTheLengthTheInvisibleCharactersAndTheFoldAsTheFirst(): void {
		$long = AB_MCP_Option_Guard::confirmed_write_refused( 'default_role' . str_repeat( ' ', 180 ) . 'x' );
		self::assertSame( 'ab_mcp_option_name_too_long', $long->get_error_code() );
		$hidden = AB_MCP_Option_Guard::confirmed_write_refused( "default\u{200B}_role" );
		self::assertSame( 'ab_mcp_option_name_invalid', $hidden->get_error_code() );
		foreach ( array( 'actiue_plugins', 'sjteurl', 'ab_mcp_pro_déploy', 'my_tökén' ) as $folded ) {
			self::assertSame( 'ab_mcp_blocked', AB_MCP_Option_Guard::confirmed_write_refused( $folded )->get_error_code(), $folded );
			self::assertSame( 'ab_mcp_blocked', AB_MCP_Option_Guard::option_write_refused( $folded )->get_error_code(), $folded );
		}
	}

	public function testTheClassOfANameComesFromTheTwoAnswers(): void {
		self::assertSame( AB_MCP_Option_Guard::CLASS_OPEN, AB_MCP_Option_Guard::option_class( 'blogname' ) );
		self::assertSame( AB_MCP_Option_Guard::CLASS_CONFIRM, AB_MCP_Option_Guard::option_class( 'users_can_register' ) );
		self::assertSame( AB_MCP_Option_Guard::CLASS_CONFIRM, AB_MCP_Option_Guard::option_class( 'default_role' ) );
		foreach ( array_merge( self::CLOSED, array( 'DEFAULT_ROLE', ' default_role ', 'defavlt_role', str_repeat( 'a', 192 ), "blog\u{200B}name" ) ) as $name ) {
			self::assertSame( AB_MCP_Option_Guard::CLASS_BLOCKED, AB_MCP_Option_Guard::option_class( $name ), var_export( $name, true ) );
		}
	}

	/* ---------------------------------------------------------- resolving */

	public function testSpacesCaseAndAFoldResolveOntoThePairUnderTheSecondQuestion(): void {
		foreach ( array( ' default_role ', 'DEFAULT_ROLE', 'defavlt_role', 'Defavlt_Role' ) as $given ) {
			$r = AB_MCP_Option_Guard::resolve_option( $given, AB_MCP_Option_Guard::QUESTION_CONFIRMED );
			self::assertIsArray( $r, var_export( $given, true ) );
			self::assertSame( 'default_role', $r['name'], $given );
			self::assertSame( AB_MCP_Option_Guard::CLASS_CONFIRM, $r['class'], $given );
			self::assertSame( AB_MCP_Option_Guard::CLASS_CONFIRM, AB_MCP_Option_Guard::option_class( $r['name'] ), $given );
			self::assertSame( 'subscriber', $r['snapshot']['value'], 'The snapshot is of the row the name lands on.' );
			self::assertTrue( $r['snapshot']['existed'] );
		}
		$r = AB_MCP_Option_Guard::resolve_option( 'Users_Can_Register', AB_MCP_Option_Guard::QUESTION_CONFIRMED );
		self::assertSame( 'users_can_register', $r['name'] );
		self::assertSame( AB_MCP_Option_Guard::CLASS_CONFIRM, $r['class'] );
	}

	public function testTheFirstQuestionRefusesThePairInEverySpellingBeforeLookingItUp(): void {
		foreach ( array( 'default_role', ' default_role ', 'DEFAULT_ROLE', 'users_can_register' ) as $given ) {
			$this->db->asked = array();
			self::message( AB_MCP_Option_Guard::resolve_option( $given ) );
			self::message( AB_MCP_Option_Guard::resolve_option( $given, AB_MCP_Option_Guard::QUESTION_WRITE ) );
			self::assertSame( array(), $this->db->asked, $given . ': refused before any lookup, as before 4.17.0' );
		}
		// The fold is caught by the lookup: the row it lands on is the pair.
		self::message( AB_MCP_Option_Guard::resolve_option( 'defavlt_role' ) );
		$this->db->asked = array();
		self::message( AB_MCP_Option_Guard::resolve_option( 'default_role', 'some other question' ) );
		self::assertSame( array(), $this->db->asked, 'Another question counts as the first one.' );
	}

	public function testWithoutARowASpellingThatOnlyFoldsOntoThePairIsRefusedNotOpened(): void {
		unset( $GLOBALS['ab_test_options']['default_role'], $GLOBALS['ab_test_options']['users_can_register'] );
		foreach ( array( 'DEFAULT_ROLE', 'defavlt_role', 'Users_Can_Register', 'vsers_can_register' ) as $given ) {
			self::message( AB_MCP_Option_Guard::resolve_option( $given, AB_MCP_Option_Guard::QUESTION_CONFIRMED ) );
		}
		// The exact spelling is the pair even without a row: writing it
		// creates the row of that name.
		$r = AB_MCP_Option_Guard::resolve_option( 'default_role', AB_MCP_Option_Guard::QUESTION_CONFIRMED );
		self::assertSame( 'default_role', $r['name'] );
		self::assertFalse( $r['snapshot']['existed'] );
	}

	public function testARowSpelledOtherwiseIsNotThePair(): void {
		// A column that does not fold, holding a row of its own spelled in
		// capitals: that row is not default_role, and the second question
		// does not open it.
		$this->db->folds = false;
		$GLOBALS['ab_test_options']['DEFAULT_ROLE'] = 'administrator';
		self::message( AB_MCP_Option_Guard::resolve_option( 'DEFAULT_ROLE', AB_MCP_Option_Guard::QUESTION_CONFIRMED ) );
	}

	public function testARowStoredUnderAFoldedSpellingOfAProtectedNameIsRefusedUnderBothQuestions(): void {
		// The folding column holds the protected option under another spelling
		// (an earlier write landed it there). Every way onto that row — the
		// listed name, the stored spelling — is refused, and so is the pair
		// when its row is stored folded: only the exact spelling is opened.
		unset( $GLOBALS['ab_test_options']['active_plugins'], $GLOBALS['ab_test_options']['default_role'] );
		$GLOBALS['ab_test_options']['actiue_plugins'] = array( 'akismet/akismet.php' );
		$GLOBALS['ab_test_options']['defavlt_role']   = 'subscriber';
		foreach ( array( 'active_plugins', 'actiue_plugins', 'ACTIUE_PLUGINS', 'default_role', 'defavlt_role' ) as $given ) {
			self::message( AB_MCP_Option_Guard::resolve_option( $given, AB_MCP_Option_Guard::QUESTION_CONFIRMED ) );
			self::message( AB_MCP_Option_Guard::resolve_option( $given, AB_MCP_Option_Guard::QUESTION_WRITE ) );
		}
	}

	public function testTheSecondQuestionStillRefusesEveryOtherProtectedRowInEverySpelling(): void {
		foreach ( array( 'active_plugins', 'ACTIVE_PLUGINS', 'actiue_plugins', 'siteurl', 'my_api_key', 'ab_mcp_options' ) as $given ) {
			self::message( AB_MCP_Option_Guard::resolve_option( $given, AB_MCP_Option_Guard::QUESTION_CONFIRMED ) );
		}
	}

	public function testAnOrdinaryOptionResolvesAlikeUnderBothQuestions(): void {
		foreach ( array( AB_MCP_Option_Guard::QUESTION_WRITE, AB_MCP_Option_Guard::QUESTION_CONFIRMED ) as $q ) {
			$r = AB_MCP_Option_Guard::resolve_option( ' BlogName ', $q );
			self::assertSame( 'blogname', $r['name'], $q );
			self::assertSame( AB_MCP_Option_Guard::CLASS_OPEN, $r['class'], $q );
			self::assertSame( 'Site', $r['snapshot']['value'], $q );
			$n = AB_MCP_Option_Guard::resolve_option( 'my_new_option', $q );
			self::assertSame( 'my_new_option', $n['name'], $q );
			self::assertFalse( $n['snapshot']['existed'], $q );
			// The legacy names are redirected before the lookup, as before.
			$GLOBALS['ab_test_options']['disallowed_keys'] = 'spam';
			self::assertSame( 'disallowed_keys', AB_MCP_Option_Guard::resolve_option( 'blacklist_keys', $q )['name'], $q );
		}
	}

	public function testAFoldOntoAProtectedNameWithoutARowIsRefusedUnderBothQuestions(): void {
		$this->db->folds = false;
		foreach ( array( AB_MCP_Option_Guard::QUESTION_WRITE, AB_MCP_Option_Guard::QUESTION_CONFIRMED ) as $q ) {
			self::message( AB_MCP_Option_Guard::resolve_option( 'actiue_plugins', $q ) );
		}
		// With a row of that very spelling it stays refused (since 4.17.0):
		// under a folding column that row IS active_plugins, and nothing here
		// can tell the two columns apart from the row alone. In a binary column
		// it is a row of its own that nobody names so; refusing it costs
		// nothing.
		$GLOBALS['ab_test_options']['actiue_plugins'] = 'x';
		foreach ( array( AB_MCP_Option_Guard::QUESTION_WRITE, AB_MCP_Option_Guard::QUESTION_CONFIRMED ) as $q ) {
			self::message( AB_MCP_Option_Guard::resolve_option( 'actiue_plugins', $q ) );
		}
	}

	public function testTheLengthAndTheInvisibleCharactersAreRefusedBeforeTheLoop(): void {
		foreach ( array( AB_MCP_Option_Guard::QUESTION_WRITE, AB_MCP_Option_Guard::QUESTION_CONFIRMED ) as $q ) {
			self::assertSame( 'ab_mcp_option_name_too_long', AB_MCP_Option_Guard::resolve_option( 'default_role' . str_repeat( ' ', 180 ) . 'x', $q )->get_error_code() );
			self::assertSame( 'ab_mcp_option_name_invalid', AB_MCP_Option_Guard::resolve_option( "default\u{00AD}_role", $q )->get_error_code() );
		}
	}

	/* ------------------------------------------------------- the refusals */

	public function testTheActivationListsNameTheirToolsOnEverySite(): void {
		$m = self::message( AB_MCP_Option_Guard::option_write_refused( 'active_plugins' ) );
		self::assertStringContainsString( 'wp_activate_plugin', $m );
		self::assertStringContainsString( 'wp_deactivate_plugin', $m );
		self::assertStringContainsString( 'wp-admin/plugins.php', $m );
		foreach ( array( 'template', 'stylesheet', 'Stylesheet' ) as $name ) {
			$t = self::message( AB_MCP_Option_Guard::option_write_refused( $name ) );
			self::assertStringContainsString( 'wp_activate_theme', $t, $name );
			self::assertStringContainsString( 'wp-admin/themes.php', $t, $name );
		}
		// Through the loop, and for a folded spelling, the same way.
		self::assertStringContainsString( 'wp_activate_plugin', self::message( AB_MCP_Option_Guard::resolve_option( 'active_plugins', AB_MCP_Option_Guard::QUESTION_CONFIRMED ) ) );
		self::assertStringContainsString( 'wp_activate_plugin', self::message( AB_MCP_Option_Guard::option_write_refused( 'actiue_plugins' ) ) );
	}

	public function testOnAFreeSiteTheWayIsInWpAdminAndNoToolOfProIsNamed(): void {
		foreach ( array( 'free', 'enterprise', '' ) as $edition ) {
			ab_test_reset();
			$this->edition( $edition );
			$network = self::message( AB_MCP_Option_Guard::option_write_refused( 'active_sitewide_plugins' ) );
			self::assertStringContainsString( 'Network Admin › Plugins', $network, $edition );
			self::assertStringNotContainsString( 'wp_network_activate_plugin', $network, $edition );
			$cron = self::message( AB_MCP_Option_Guard::option_write_refused( 'cron' ) );
			self::assertStringNotContainsString( 'wp_schedule_cron', $cron, $edition );
			self::assertStringNotContainsString( 'wp_unschedule_cron', $cron, $edition );
			self::assertStringContainsString( 'plugin', $cron, $edition );
			foreach ( array( 'siteurl', 'home' ) as $name ) {
				$address = self::message( AB_MCP_Option_Guard::option_write_refused( $name ) );
				self::assertStringContainsString( 'Settings › General', $address, $name );
				self::assertStringContainsString( 'wp-admin/options-general.php', $address, $name );
				self::assertStringNotContainsString( 'wp_change_site_address', $address, $name );
			}
			foreach ( AB_MCP_Option_Guard::CONFIRMED as $name ) {
				$pair = self::message( AB_MCP_Option_Guard::option_write_refused( $name ) );
				self::assertStringContainsString( 'Settings › General', $pair, $name );
				self::assertStringNotContainsString( 'wp_update_option', $pair, $name );
			}
		}
	}

	public function testOnAProSiteTheWayIsTheToolOfPro(): void {
		foreach ( array( 'pro', 'agency' ) as $edition ) {
			ab_test_reset();
			$this->edition( $edition );
			self::assertStringContainsString( 'wp_network_activate_plugin', self::message( AB_MCP_Option_Guard::option_write_refused( 'active_sitewide_plugins' ) ), $edition );
			$cron = self::message( AB_MCP_Option_Guard::option_write_refused( 'cron' ) );
			self::assertStringContainsString( 'wp_schedule_cron', $cron, $edition );
			self::assertStringContainsString( 'wp_unschedule_cron', $cron, $edition );
			foreach ( array( 'siteurl', 'home' ) as $name ) {
				self::assertStringContainsString( 'wp_change_site_address', self::message( AB_MCP_Option_Guard::option_write_refused( $name ) ), $name );
			}
			foreach ( AB_MCP_Option_Guard::CONFIRMED as $name ) {
				$pair = self::message( AB_MCP_Option_Guard::option_write_refused( $name ) );
				self::assertStringContainsString( 'wp_update_option', $pair, $name );
				self::assertStringContainsString( 'confirm_token', $pair, $name );
			}
		}
	}

	public function testTheClosedGroupsSayWhereAnAdministratorChangesThemOrThatNobodyDoes(): void {
		foreach ( array( 'ab_mcp_pro_undo_index', 'ab_mcp_tokens', 'ab_mcp_oauth_codes', 'AB_MCP_SECRET' ) as $name ) {
			$own = self::message( AB_MCP_Option_Guard::option_write_refused( $name ) );
			self::assertStringContainsString( 'AlphaBridge itself', $own, $name . ': its own, before any other needle it holds' );
			self::assertStringContainsString( 'admin.php?page=alphabridge-connection', $own, $name );
		}
		self::assertStringContainsString( 'wp-admin/users.php', self::message( AB_MCP_Option_Guard::option_write_refused( 'wp_user_roles' ) ) );
		foreach ( array( 'mailserver_url', 'mailserver_login', 'mailserver_pass', 'mailserver_port' ) as $name ) {
			self::assertStringContainsString( 'wp-admin/options-writing.php', self::message( AB_MCP_Option_Guard::option_write_refused( $name ) ), $name );
		}
		foreach ( array( 'alloptions', 'notoptions' ) as $name ) {
			$cache = self::message( AB_MCP_Option_Guard::option_write_refused( $name ) );
			self::assertStringContainsString( 'Nothing needs changing', $cache, $name );
			self::assertStringNotContainsString( 'Direct link', $cache, $name );
		}
		$old = 'This option is protected and cannot be changed via MCP. An administrator changes it in wp-admin, where WordPress or its plugin offers it.';
		foreach ( array( 'auth_key', 'nonce_salt', 'fs_accounts', 'my_api_key', 'smtp_password', 'my_plugin_salt' ) as $name ) {
			self::assertSame( $old, self::message( AB_MCP_Option_Guard::option_write_refused( $name ) ), $name );
		}
	}

	public function testAToolOfTheAgencyPlanTakesTheAgencyEdition(): void {
		$has = new \ReflectionMethod( AB_MCP_Option_Guard::class, 'edition_has' );
		$this->edition( 'pro' );
		self::assertTrue( $has->invoke( null, 'wp_update_option' ) );
		self::assertFalse( $has->invoke( null, 'wp_deploy_push_zip' ), 'Pro without its Agency plan has no Site Deploy' );
		ab_test_reset();
		$this->edition( 'agency' );
		self::assertTrue( $has->invoke( null, 'wp_deploy_push_zip' ) );
		self::assertTrue( $has->invoke( null, 'wp_update_option' ) );
	}

	public function testEveryRefusalKeepsItsCodeNamesNoEditionAndLeadsOnWithALink(): void {
		$this->edition( 'pro' );
		foreach ( array_merge( self::CLOSED, AB_MCP_Option_Guard::CONFIRMED ) as $name ) {
			$m = self::message( AB_MCP_Option_Guard::option_write_refused( $name ) );
			self::assertStringNotContainsString( 'AlphaBridge MCP Pro', $m, $name );
			self::assertStringNotContainsString( 'Agency', $m, $name );
		}
		foreach ( array( 'active_plugins', 'template', 'active_sitewide_plugins', 'cron', 'siteurl', 'default_role', 'ab_mcp_tokens', 'wp_user_roles', 'mailserver_pass' ) as $name ) {
			$m = self::message( AB_MCP_Option_Guard::option_write_refused( $name ) );
			self::assertStringContainsString( 'Direct link: https://example.test/wp-admin/', $m, $name );
			self::assertStringContainsString( 'Pass this on', $m, $name );
		}
	}
}

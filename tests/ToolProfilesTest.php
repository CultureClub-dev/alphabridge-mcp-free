<?php
/**
 * Profiles for the tool switches (Simple, Advanced, Expert) and the level of
 * a tool group they are built on.
 *
 * What must hold, whatever an add-on or a filter declares:
 *
 * - Simple is the state a site ships in: every tool on that is not Mighty,
 *   every Mighty tool off. A site that never touched a switch reads «Simple».
 * - A profile is a preset for the switches, not a rule: applying one writes
 *   the switches the settings form writes, and nothing else — no connection,
 *   no token, not read-only mode.
 * - Opening the settings page writes nothing. A site with switches of its own
 *   reads «Custom» and keeps every one of them.
 * - The page offers a profile only where it makes a difference: Advanced
 *   only where some Mighty group is marked Advanced and another is not.
 * - Only an administrator, with the form's own nonce, applies a profile.
 *
 * The consent screen describes each token scope in one sentence, the content
 * sentence names what a content token changes and promises nothing about
 * code, and the page opens with «content» checked.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use PHPUnit\Framework\TestCase;
use AbTestExit;
use AB_MCP_Admin;
use AB_MCP_OAuth;
use AB_MCP_Settings;
use AB_MCP_Tool_Profiles;
use AB_MCP_Tool_Registry;
use DOMDocument;
use DOMXPath;
use ReflectionMethod;

final class ToolProfilesTest extends TestCase {

	protected function setUp(): void {
		ab_test_reset();
	}

	protected function tearDown(): void {
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		unset( $_SERVER['REQUEST_METHOD'] );
	}

	/**
	 * Shaped like a site with Free and an add-on: Free's groups, two of them
	 * Mighty because of a delete tool, and add-on groups that register into
	 * Free's «search» group and into groups of their own.
	 */
	private function registry(): AB_MCP_Tool_Registry {
		$r = new AB_MCP_Tool_Registry();
		$r->set_current_group( 'content', 'Posts & Pages' );
		$r->register( 'wp_list_posts', array() );
		$r->register( 'wp_update_post', array() );
		$r->register( 'wp_delete_post', array( 'dangerous' => true ) );
		$r->set_current_group( 'search', 'Search & Bulk Actions' );
		$r->register( 'wp_search', array() );
		$r->set_current_group( 'seo', 'SEO' );
		$r->register( 'wp_seo_get', array() );
		// The add-on, in Free's group and in groups of its own.
		$r->set_current_group( 'search', 'Search & Bulk Actions', 'advanced' );
		$r->register( 'wp_search_replace', array( 'dangerous' => true ) );
		$r->set_current_group( 'builders', 'Page builders', 'advanced' );
		$r->register( 'wp_update_builder_element', array( 'dangerous' => true ) );
		$r->set_current_group( 'undo', 'Undo', 'advanced' );
		$r->register( 'wp_undo', array() );
		$r->set_current_group( 'database', 'Database' );
		$r->register( 'wp_db_tables', array() );
		$r->register( 'wp_db_execute', array( 'dangerous' => true ) );
		return $r;
	}

	/** Free on its own: no group declares a level. */
	private function free_registry(): AB_MCP_Tool_Registry {
		$r = new AB_MCP_Tool_Registry();
		$r->set_current_group( 'content', 'Posts & Pages' );
		$r->register( 'wp_list_posts', array( 'description' => 'List posts.' ) );
		$r->register( 'wp_delete_post', array( 'description' => 'Delete a post.', 'dangerous' => true ) );
		$r->set_current_group( 'seo', 'SEO' );
		$r->register( 'wp_seo_get', array( 'description' => 'Read the SEO fields of a post.' ) );
		return $r;
	}

	/** @return array<string,string> slug => level */
	private static function levels( AB_MCP_Tool_Registry $r ): array {
		return array_map( static fn( array $g ): string => $g['level'], $r->groups() );
	}

	/** @return array<string,array<string,bool>> */
	private static function states( AB_MCP_Tool_Registry $r ): array {
		return AB_MCP_Tool_Profiles::states( $r->all(), $r->groups() );
	}

	private static function current( AB_MCP_Tool_Registry $r ): string {
		return AB_MCP_Tool_Profiles::current( $r->all(), $r->groups() );
	}

	/** @return string[] Tool names switched on. */
	private static function on( array $state ): array {
		return array_keys( array_filter( $state ) );
	}

	/* ------------------------------------------------------------- levels */

	public function testAGroupWithoutADeclaredLevelFollowsTheDefaultRule(): void {
		$levels = self::levels( $this->free_registry() );

		self::assertSame( 'expert', $levels['content'], 'Mighty: from Expert on.' );
		self::assertSame( 'simple', $levels['seo'], 'Not Mighty: in every profile.' );
	}

	public function testTheRegistrationDeclaresTheLevel(): void {
		$levels = self::levels( $this->registry() );

		self::assertSame( 'advanced', $levels['builders'] );
		self::assertSame( 'expert', $levels['database'], 'Declared nothing: Mighty means Expert.' );
	}

	public function testTheWidestDeclaredLevelWins(): void {
		$r = new AB_MCP_Tool_Registry();
		$r->set_current_group( 'search', 'Search', 'advanced' );
		$r->register( 'wp_search_replace', array( 'dangerous' => true ) );
		$r->set_current_group( 'search', 'Search', 'expert' );
		$r->register( 'wp_bulk_delete', array( 'dangerous' => true ) );
		$r->set_current_group( 'search', 'Search', 'advanced' );
		$r->register( 'wp_search_more', array( 'dangerous' => true ) );

		self::assertSame( 'expert', self::levels( $r )['search'], 'One registration cannot pull another\'s tools into a narrower profile.' );
	}

	public function testAnAddOnCannotPullFreesMightyToolsIntoAdvanced(): void {
		// Free registers its groups without a level; an add-on registers into
		// the same group and declares Advanced for its own tool.
		$r = new AB_MCP_Tool_Registry();
		$r->set_current_group( 'content', 'Posts & Pages' );
		$r->register( 'wp_update_post', array() );
		$r->register( 'wp_delete_post', array( 'dangerous' => true ) );
		$r->set_current_group( 'content', 'Posts & Pages', 'advanced' );
		$r->register( 'wp_restore_revision', array( 'dangerous' => true ) );

		self::assertSame( 'expert', self::levels( $r )['content'], 'A Mighty tool registered without a level counts as Expert.' );
		$advanced = self::states( $r )['advanced'];
		self::assertFalse( $advanced['wp_delete_post'], 'Deleting posts does not come on with Advanced.' );
		self::assertFalse( $advanced['wp_restore_revision'], 'The add-on\'s tool shares the wider level.' );

		// The filter still moves the group, on purpose and for all of it.
		add_filter(
			'ab_mcp_group_levels',
			static function ( $levels ) {
				$levels['content'] = 'advanced';
				return $levels;
			}
		);
		self::assertSame( 'advanced', self::levels( $r )['content'] );
	}

	public function testADefinitionCanDeclareItsGroupAndLevel(): void {
		$r = new AB_MCP_Tool_Registry();
		$r->register(
			'wp_custom',
			array(
				'dangerous'   => true,
				'group'       => 'custom',
				'group_label' => 'Custom tools',
				'group_level' => 'advanced',
			)
		);

		self::assertSame( 'advanced', self::levels( $r )['custom'] );
	}

	public function testTheFilterHasTheLastWord(): void {
		$seen = null;
		add_filter(
			'ab_mcp_group_levels',
			static function ( $levels, $groups ) use ( &$seen ) {
				$seen               = array( $levels, array_keys( $groups ) );
				$levels['builders'] = 'expert';
				$levels['database'] = 'advanced';
				return $levels;
			},
			10,
			2
		);
		$levels = self::levels( $this->registry() );

		self::assertSame( 'expert', $levels['builders'], 'Over the registration.' );
		self::assertSame( 'advanced', $levels['database'], 'Over the default rule.' );
		self::assertSame( 'advanced', $seen[0]['builders'], 'The filter sees the levels before it.' );
		self::assertContains( 'database', $seen[1], 'And the groups.' );
	}

	public function testUnknownLevelsAndGroupsFromTheFilterAreIgnored(): void {
		add_filter(
			'ab_mcp_group_levels',
			static function ( $levels ) {
				$levels['builders'] = 'root';
				$levels['nonesuch'] = 'advanced';
				return $levels;
			}
		);
		$groups = $this->registry()->groups();

		self::assertSame( 'advanced', $groups['builders']['level'], 'An unknown level changes nothing.' );
		self::assertSame( array( 'content', 'search', 'seo', 'builders', 'undo', 'database' ), array_keys( $groups ), 'The groups are the registered ones, whatever the filter names.' );

		add_filter( 'ab_mcp_group_levels', '__return_false', 20 );
		self::assertSame( 'advanced', $this->registry()->groups()['builders']['level'], 'A filter that answers no list changes nothing.' );
	}

	public function testAGroupWithoutMightyToolsIsAlwaysSimple(): void {
		add_filter(
			'ab_mcp_group_levels',
			static function ( $levels ) {
				$levels['seo'] = 'expert';
				return $levels;
			}
		);
		$levels = self::levels( $this->registry() );

		self::assertSame( 'simple', $levels['undo'], 'Declared Advanced, but its tools are on as the site ships.' );
		self::assertSame( 'simple', $levels['seo'], 'No profile switches off what a site ships with.' );
	}

	public function testAMightyGroupIsNeverSimple(): void {
		add_filter(
			'ab_mcp_group_levels',
			static function ( $levels ) {
				$levels['database'] = 'simple';
				return $levels;
			}
		);
		$r = $this->registry();
		$r->set_current_group( 'files', 'Files', 'simple' );
		$r->register( 'wp_write_file', array( 'dangerous' => true ) );
		$levels = self::levels( $r );

		self::assertSame( 'advanced', $levels['database'], 'Filtered to Simple: Advanced at the earliest.' );
		self::assertSame( 'advanced', $levels['files'], 'Declared Simple: the same.' );
	}

	public function testGroupsKeepWhatTheyCarriedBefore(): void {
		$g = $this->free_registry()->groups()['content'];

		self::assertSame( array( 'label', 'mighty', 'tools', 'count', 'level' ), array_keys( $g ) );
		self::assertSame( array( 'wp_list_posts', 'wp_delete_post' ), $g['tools'] );
		self::assertTrue( $g['mighty'] );
		self::assertSame( 2, $g['count'] );
	}

	/* ----------------------------------------------------------- profiles */

	public function testSimpleIsTheStateTheSiteShipsIn(): void {
		// Even where the levels try to say otherwise.
		add_filter(
			'ab_mcp_group_levels',
			static function ( $levels ) {
				$levels['database'] = 'simple';
				return $levels;
			}
		);
		$r = $this->registry();

		foreach ( self::states( $r )['simple'] as $name => $on ) {
			self::assertSame( AB_MCP_Settings::is_tool_enabled( $name, $r->get( $name ) ), $on, $name );
		}
		self::assertSame( 'simple', self::current( $r ) );
	}

	public function testAdvancedAddsTheMightyToolsOfAdvancedGroups(): void {
		$r      = $this->registry();
		$states = self::states( $r );

		self::assertSame(
			array( 'wp_search_replace', 'wp_update_builder_element' ),
			array_values( array_diff( self::on( $states['advanced'] ), self::on( $states['simple'] ) ) )
		);
		self::assertFalse( $states['advanced']['wp_db_execute'], 'Expert groups stay off.' );
		self::assertFalse( $states['advanced']['wp_delete_post'] );
	}

	public function testExpertSwitchesEveryToolOn(): void {
		$r = $this->registry();

		self::assertSame( array_keys( $r->all() ), self::on( self::states( $r )['expert'] ) );
	}

	public function testAToolThatIsNotMightyIsOnInEveryProfile(): void {
		foreach ( self::states( $this->registry() ) as $profile => $state ) {
			foreach ( array( 'wp_list_posts', 'wp_update_post', 'wp_search', 'wp_undo', 'wp_db_tables' ) as $name ) {
				self::assertTrue( $state[ $name ], $profile . ': ' . $name );
			}
		}
	}

	public function testApplyingAProfileWritesOnlyTheToolSwitches(): void {
		AB_MCP_Settings::add_token( 1, 'Claude', 'content' );
		AB_MCP_Settings::set( 'read_only', true );
		$tokens                    = get_option( AB_MCP_Settings::OPT_TOKENS );
		$options                   = get_option( AB_MCP_Settings::OPT_OPTIONS );
		$GLOBALS['ab_test_writes'] = array();
		$r                         = $this->registry();

		self::assertTrue( AB_MCP_Tool_Profiles::apply( 'advanced', $r ) );

		self::assertSame( self::states( $r )['advanced'], AB_MCP_Settings::get_tool_state() );
		self::assertSame( $tokens, get_option( AB_MCP_Settings::OPT_TOKENS ), 'No connection, no token changes.' );
		self::assertSame( $options, get_option( AB_MCP_Settings::OPT_OPTIONS ), 'Read-only mode and every other setting stay as they are.' );
		self::assertSame( 0, ab_test_writes( AB_MCP_Settings::OPT_OPTIONS ) );
		self::assertSame( 'advanced', self::current( $r ) );
	}

	public function testAnUnknownProfileChangesNothing(): void {
		$r = $this->registry();

		foreach ( array( '', 'custom', 'root', 'Expert ' ) as $profile ) {
			self::assertFalse( AB_MCP_Tool_Profiles::apply( $profile, $r ), var_export( $profile, true ) );
		}
		self::assertSame( 0, ab_test_writes( AB_MCP_Settings::OPT_TOOLSTATE ) );
		self::assertSame( 0, ab_test_writes( AB_MCP_Settings::OPT_OPTIONS ) );
	}

	public function testOneSwitchAwayFromEveryProfileIsCustom(): void {
		$r = $this->registry();

		AB_MCP_Tool_Profiles::apply( 'expert', $r );
		$state                   = AB_MCP_Settings::get_tool_state();
		$state['wp_db_execute'] = false;
		AB_MCP_Settings::set_tool_state( $state );
		self::assertSame( 'custom', self::current( $r ) );

		AB_MCP_Tool_Profiles::apply( 'simple', $r );
		$state                   = AB_MCP_Settings::get_tool_state();
		$state['wp_seo_get']    = false;
		AB_MCP_Settings::set_tool_state( $state );
		self::assertSame( 'custom', self::current( $r ), 'Off what Simple has on: Custom too.' );
	}

	public function testFreeOnItsOwnOffersSimpleAndExpert(): void {
		$r = $this->free_registry(); // No group is marked Advanced.

		self::assertSame( array( 'simple', 'expert' ), array_keys( AB_MCP_Tool_Profiles::offered( self::states( $r ) ) ), 'Advanced would switch what Simple does.' );
		self::assertSame( array( 'simple', 'advanced', 'expert' ), array_keys( AB_MCP_Tool_Profiles::offered( self::states( $this->registry() ) ) ), 'With a group marked Advanced, all three.' );
	}

	public function testAdvancedIsNotOfferedWhereItEqualsExpert(): void {
		add_filter(
			'ab_mcp_group_levels',
			static function ( $levels ) {
				return array_fill_keys( array_keys( $levels ), 'advanced' );
			}
		);

		self::assertSame( array( 'simple', 'expert' ), array_keys( AB_MCP_Tool_Profiles::offered( self::states( $this->registry() ) ) ) );
	}

	public function testAProfileThatIsNotOfferedNeverShowsAsInUse(): void {
		$r = $this->free_registry();

		self::assertTrue( AB_MCP_Tool_Profiles::apply( 'advanced', $r ), 'A request from an older page still applies.' );
		self::assertSame( self::states( $r )['simple'], AB_MCP_Settings::get_tool_state(), 'Its switches are those of Simple.' );
		self::assertSame( 'simple', self::current( $r ) );
	}

	public function testANewMightyToolNeverComesOnUnasked(): void {
		$r = $this->registry();
		AB_MCP_Tool_Profiles::apply( 'expert', $r );

		// An update or an add-on registers one more Mighty tool.
		$r->set_current_group( 'files', 'Files' );
		$r->register( 'wp_write_plugin_file', array( 'dangerous' => true ) );

		self::assertFalse( AB_MCP_Settings::is_tool_enabled( 'wp_write_plugin_file', $r->get( 'wp_write_plugin_file' ) ) );
		self::assertSame( 'custom', self::current( $r ), 'The page says the site no longer follows Expert.' );
		AB_MCP_Tool_Profiles::apply( 'expert', $r );
		self::assertTrue( AB_MCP_Settings::is_tool_enabled( 'wp_write_plugin_file', $r->get( 'wp_write_plugin_file' ) ), 'Until the admin picks Expert again.' );
	}

	/* ------------------------------------------------- existing installations */

	public function testASiteThatNeverTouchedASwitchReadsSimpleAndNothingIsWritten(): void {
		$r = $this->registry();

		self::assertSame( 'simple', self::current( $r ) );
		$this->call( 'capabilities_card_html', $r->groups(), $r->all(), false );

		self::assertSame( 0, ab_test_writes( AB_MCP_Settings::OPT_TOOLSTATE ), 'Opening the page writes no switch.' );
		self::assertSame( 0, ab_test_writes( AB_MCP_Settings::OPT_OPTIONS ) );
		self::assertFalse( get_option( AB_MCP_Settings::OPT_TOOLSTATE ) );
	}

	public function testASiteWithSwitchesOfItsOwnReadsCustomAndKeepsThem(): void {
		$own = array(
			'wp_delete_post' => true,
			'wp_seo_get'     => false,
		);
		update_option( AB_MCP_Settings::OPT_TOOLSTATE, $own );
		$GLOBALS['ab_test_writes'] = array();
		$r                         = $this->registry();
		$before                    = AB_MCP_Tool_Profiles::effective( $r->all() );

		self::assertSame( 'custom', self::current( $r ) );
		$x = $this->xpath( $this->call( 'capabilities_card_html', $r->groups(), $r->all(), false ) );

		self::assertSame( $own, get_option( AB_MCP_Settings::OPT_TOOLSTATE ), 'Every switch kept.' );
		self::assertSame( $before, AB_MCP_Tool_Profiles::effective( $r->all() ), 'Every tool on or off as before.' );
		self::assertSame( 0, ab_test_writes( AB_MCP_Settings::OPT_TOOLSTATE ) );
		self::assertSame( 0, ab_test_writes( AB_MCP_Settings::OPT_OPTIONS ) );
		self::assertSame( 'true', $x->query( '//*[@data-profile="custom"]' )->item( 0 )->getAttribute( 'aria-current' ) );
	}

	public function testASiteThatSavedTheShippingStateReadsSimple(): void {
		$r     = $this->registry();
		$saved = array();
		foreach ( $r->all() as $name => $def ) {
			$saved[ $name ] = empty( $def['dangerous'] ); // What saving the untouched form writes.
		}
		update_option( AB_MCP_Settings::OPT_TOOLSTATE, $saved );

		self::assertSame( 'simple', self::current( $r ) );
	}

	public function testTheReadmesSayAProfileReplacesEverySwitch(): void {
		foreach ( array( 'readme.txt', 'README.md' ) as $file ) {
			$text = (string) preg_replace( '/\s+/', ' ', (string) file_get_contents( dirname( __DIR__ ) . '/' . $file ) );
			self::assertStringContainsString( 'Choosing a profile replaces every tool switch; until you choose one, switches', $text, $file );
			self::assertStringNotContainsString( 'keep their place', $text, $file );
		}
	}

	/* ------------------------------------------------------------ the page */

	/** @param mixed ...$args */
	private function call( string $method, ...$args ): string {
		$m = new ReflectionMethod( AB_MCP_Admin::class, $method );
		ob_start();
		$out    = $m->invoke( new AB_MCP_Admin(), ...$args );
		$echoed = (string) ob_get_clean();
		return $echoed . ( is_string( $out ) ? $out : '' );
	}

	private function xpath( string $html ): DOMXPath {
		$doc = new DOMDocument();
		$doc->loadHTML( '<!doctype html><meta charset="utf-8"><body>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING );
		return new DOMXPath( $doc );
	}

	private function card( ?AB_MCP_Tool_Registry $r = null ): DOMXPath {
		$r = $r ?? $this->free_registry();
		return $this->xpath( $this->call( 'capabilities_card_html', $r->groups(), $r->all(), false ) );
	}

	public function testTheProfileButtonsSubmitASignedFormOfTheirOwn(): void {
		$x = $this->card( $this->registry() );

		$buttons = array();
		foreach ( $x->query( '//button[contains(concat(" ", @class, " "), " ab-profile ")]' ) as $b ) {
			self::assertSame( 'submit', $b->getAttribute( 'type' ) );
			self::assertSame( 'ab-profile-form', $b->getAttribute( 'form' ), 'Posts the profile form, not the capabilities form.' );
			self::assertSame( 'profile', $b->getAttribute( 'name' ) );
			$buttons[] = $b->getAttribute( 'value' );
		}
		self::assertSame( array( 'simple', 'advanced', 'expert' ), $buttons );

		$form = $x->query( '//form[@id="ab-profile-form"]' )->item( 0 );
		self::assertNotNull( $form );
		self::assertSame( 'ab_mcp_profile', $x->query( './/input[@name="action"]', $form )->item( 0 )->getAttribute( 'value' ) );
		self::assertSame( 'nonce-ab_mcp_profile', $x->query( './/input[@name="_wpnonce"]', $form )->item( 0 )->getAttribute( 'value' ) );
		self::assertSame( 0, $x->query( '//form[@id="ab-profile-form"]//form | //form[@id="ab-caps"]//form' )->length, 'No form inside a form.' );

		// The capabilities form posts what it always posted.
		self::assertSame( 'ab_mcp_save', $x->query( '//form[@id="ab-caps"]//input[@name="action"]' )->item( 0 )->getAttribute( 'value' ) );
		self::assertSame( 'nonce-ab_mcp_save', $x->query( '//form[@id="ab-caps"]//input[@name="_wpnonce"]' )->item( 0 )->getAttribute( 'value' ) );
		self::assertSame( 0, $x->query( '//form[@id="ab-caps"]//button[@name="profile"][not(@form="ab-profile-form")]' )->length );
	}

	public function testThePageMarksTheProfileInUse(): void {
		$x = $this->card();

		$current = array();
		foreach ( $x->query( '//*[@aria-current="true"]' ) as $el ) {
			$current[] = $el->getAttribute( 'data-profile' );
		}
		self::assertSame( array( 'simple' ), $current );
		self::assertTrue( $x->query( '//*[@data-profile="custom"]' )->item( 0 )->hasAttribute( 'hidden' ), 'Custom shows only when no profile fits.' );
		self::assertSame( 'simple', $x->query( '//*[contains(@class, "ab-profiles")][@data-current]' )->item( 0 )->getAttribute( 'data-current' ) );
	}

	public function testEverySwitchNamesTheProfilesItIsOnIn(): void {
		$profiles = function ( DOMXPath $x, string $tool ): string {
			return $x->query( '//input[@name="enabled_tools[]"][@value="' . $tool . '"]' )->item( 0 )->getAttribute( 'data-profiles' );
		};

		$x = $this->card( $this->registry() );
		self::assertSame( 'simple advanced expert', $profiles( $x, 'wp_list_posts' ) );
		self::assertSame( 'expert', $profiles( $x, 'wp_delete_post' ) );
		self::assertSame( 'advanced expert', $profiles( $x, 'wp_update_builder_element' ) );
	}

	public function testEachProfileSaysWhatItSwitchesOn(): void {
		$text = function ( DOMXPath $x, string $profile, string $part ): string {
			return trim( $x->query( '//button[@value="' . $profile . '"]//*[contains(@class, "ab-profile__' . $part . '")]' )->item( 0 )->textContent );
		};

		$x = $this->card();
		self::assertSame( '2 of 3 tools on', $text( $x, 'simple', 'count' ) );
		self::assertSame( '3 of 3 tools on', $text( $x, 'expert', 'count' ) );
		self::assertSame( 0, $x->query( '//button[@value="advanced"]' )->length, 'No button that changes nothing.' );
		self::assertStringContainsString( 'tools marked Mighty, which delete or change a lot at once, stay off', $text( $x, 'simple', 'text' ) );
		self::assertStringContainsString( 'only what the connected account and its access level allow', $text( $x, 'expert', 'text' ) );

		$x = $this->card( $this->registry() );
		self::assertStringContainsString( 'marked Advanced: Search & Bulk Actions, Page builders; groups marked Expert stay off', $text( $x, 'advanced', 'text' ), 'Names what Advanced adds on this site.' );
		self::assertSame( 'A profile sets only the tool switches below. Read-only mode and your connections stay as they are.', trim( $x->query( '//*[contains(@class, "ab-profiles")]//p[contains(@class, "ab-note")]' )->item( 0 )->textContent ) );
	}

	public function testAMightyGroupShowsTheProfileThatSwitchesItOn(): void {
		$pill = function ( DOMXPath $x, string $group ): string {
			$p = $x->query( '//details[@data-group="' . $group . '"]/summary//*[contains(@class, "ab-pill--level")]' )->item( 0 );
			self::assertSame( 0, null === $p ? 0 : $x->query( './/*[contains(@class, "screen-reader-text")]', $p )->length, 'Every word of it is visible.' );
			return null === $p ? '' : trim( $p->textContent );
		};

		$x = $this->card( $this->registry() );
		self::assertSame( 'Mighty tools: from Expert', $pill( $x, 'content' ) );
		self::assertSame( 'Mighty tools: from Advanced', $pill( $x, 'builders' ) );
		self::assertSame( '', $pill( $x, 'seo' ), 'Not Mighty: on in every profile, no pill.' );

		// Where Advanced is not offered, the pill names the profile that is.
		add_filter(
			'ab_mcp_group_levels',
			static function ( $levels ) {
				return array_fill_keys( array_keys( $levels ), 'advanced' );
			}
		);
		self::assertSame( 'Mighty tools: from Expert', $pill( $this->card( $this->registry() ), 'builders' ) );
	}

	public function testThePageCarriesTheQuestionsAskedBeforeAProfileDiscardsSomething(): void {
		$box = $this->card()->query( '//*[contains(@class, "ab-profiles")][@data-current]' )->item( 0 );

		foreach ( array( 'data-now', 'data-now-unsaved', 'data-confirm-custom', 'data-confirm-read-only', 'data-confirm-ask' ) as $attribute ) {
			self::assertNotSame( '', $box->getAttribute( $attribute ), $attribute );
		}
		self::assertStringContainsString( 'tools you switched off come back on', $box->getAttribute( 'data-confirm-custom' ) );
		self::assertStringContainsString( 'Read-only mode was changed but not saved', $box->getAttribute( 'data-confirm-read-only' ) );
	}

	/* --------------------------------------------------------- the handler */

	/**
	 * Post the profile form as an administrator, with its own nonce unless
	 * the post brings one.
	 *
	 * @param array<string,string> $post
	 * @return AbTestExit|null How the request ended.
	 */
	private function submit( AB_MCP_Tool_Registry $r, array $post, ?\Closure $can = null ): ?AbTestExit {
		$GLOBALS['ab_test_can'] = $can ?? static fn( string $cap ): bool => 'manage_options' === $cap;
		$_POST                  = $post + array( '_wpnonce' => 'nonce-ab_mcp_profile' );
		$_REQUEST               = $_POST;
		try {
			( new AB_MCP_Admin( $r ) )->handle_profile();
		} catch ( AbTestExit $exit ) {
			return $exit;
		} finally {
			$_POST    = array();
			$_REQUEST = array();
		}
		return null;
	}

	public function testAnAdministratorAppliesAProfile(): void {
		AB_MCP_Settings::add_token( 1, 'Claude', 'full' );
		$tokens = get_option( AB_MCP_Settings::OPT_TOKENS );
		$r      = $this->registry();

		$exit = $this->submit( $r, array( 'profile' => 'expert' ) );

		self::assertNotNull( $exit );
		self::assertSame( 'redirect', $exit->kind );
		self::assertStringContainsString( 'ab_notice=profile_saved', $exit->detail );
		self::assertSame( self::states( $r )['expert'], AB_MCP_Settings::get_tool_state() );
		self::assertSame( $tokens, get_option( AB_MCP_Settings::OPT_TOKENS ) );
		self::assertFalse( AB_MCP_Settings::get( 'read_only' ) );
	}

	public function testAnUnknownProfileIsRefusedWithAWayBack(): void {
		$exit = $this->submit( $this->registry(), array( 'profile' => 'root' ) );

		self::assertSame( 'redirect', $exit->kind );
		self::assertStringContainsString( 'ab_notice=profile_unknown', $exit->detail );
		self::assertSame( 0, ab_test_writes( AB_MCP_Settings::OPT_TOOLSTATE ) );
		self::assertSame( 0, ab_test_writes( AB_MCP_Settings::OPT_OPTIONS ) );
	}

	/**
	 * @return array<string,array{0:array<string,string>}>
	 */
	public static function foreignNonces(): array {
		return array(
			'no nonce'                       => array( array( '_wpnonce' => '' ) ),
			'the nonce of the switches form' => array( array( '_wpnonce' => 'nonce-ab_mcp_save' ) ),
		);
	}

	/**
	 * @param array<string,string> $post
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'foreignNonces' )]
	public function testAProfileIsNotAppliedWithoutItsNonce( array $post ): void {
		$exit = $this->submit( $this->registry(), $post + array( 'profile' => 'expert' ) );

		self::assertSame( 'die', $exit->kind, 'A page elsewhere cannot switch every tool on for the admin.' );
		self::assertSame( 0, ab_test_writes( AB_MCP_Settings::OPT_TOOLSTATE ) );
	}

	public function testOnlyAnAdministratorAppliesAProfile(): void {
		$exit = $this->submit( $this->registry(), array( 'profile' => 'expert' ), static fn( string $cap ): bool => 'manage_options' !== $cap );

		self::assertSame( 'die', $exit->kind );
		self::assertSame( 0, ab_test_writes( AB_MCP_Settings::OPT_TOOLSTATE ) );
	}

	public function testTheNoticesAfterApplying(): void {
		foreach ( array( 'profile_saved' => 'notice-success', 'profile_unknown' => 'notice-error' ) as $key => $class ) {
			$_GET = array( 'ab_notice' => $key );
			$html = $this->call( 'notice' );
			self::assertStringContainsString( $class, $html, $key );
		}
	}

	/* ------------------------------------------------- the consent screen */

	private function scope_choices( string $default ): DOMXPath {
		$m = new ReflectionMethod( AB_MCP_OAuth::class, 'scope_choices_html' );
		return $this->xpath( (string) $m->invoke( null, AB_MCP_Tool_Registry::scopes(), $default ) );
	}

	public function testTheConsentScreenDescribesEveryScopeInOneSentence(): void {
		$default = ( new ReflectionMethod( AB_MCP_OAuth::class, 'default_scope' ) )->invoke( null, AB_MCP_Tool_Registry::scopes() );
		$x       = $this->scope_choices( $default );

		$choices = array();
		foreach ( $x->query( '//fieldset//label' ) as $label ) {
			$radio = $x->query( './/input[@type="radio"][@name="ab_scope"]', $label )->item( 0 );
			$text  = trim( $x->query( './/*[@class="scope-text"]', $label )->item( 0 )->textContent );
			self::assertSame( 1, preg_match_all( '/[.!?](\s|$)/', $text ), 'One sentence: ' . $text );
			$choices[ $radio->getAttribute( 'value' ) ] = array( trim( $x->query( './/b', $label )->item( 0 )->textContent ), $radio->hasAttribute( 'checked' ) );
		}

		self::assertSame(
			array(
				'read'    => array( 'Read only', false ),
				'content' => array( 'Content', true ),
				'full'    => array( 'Full', false ),
			),
			$choices,
			'Narrowest first; content stays the default.'
		);
		$content = AB_MCP_Tool_Registry::scopes()['content'];
		self::assertStringContainsString( 'change or delete posts, pages, media, terms and comments', $content );
		self::assertStringContainsString( 'no theme or plugin files and no site settings', $content );
		// For an account with unfiltered_html WordPress stores scripts in
		// content whatever the scope, and only an add-on writes the texts of
		// builder pages that keep them outside the content field.
		self::assertStringNotContainsString( 'no code', $content );
		self::assertStringNotContainsString( 'page-builder', $content );
		self::assertStringContainsString( 'Access level', $x->query( '//legend' )->item( 0 )->textContent );
	}

	public function testTheConsentPageOpensWithContentChecked(): void {
		ab_test_add_user( 7 );
		$GLOBALS['ab_test_current_user'] = 7;
		$m                               = new ReflectionMethod( AB_MCP_OAuth::class, 'render_consent' );
		ob_start();
		$m->invoke(
			null,
			array( 'name' => 'Claude' ),
			array(
				'client_id'      => 'abc',
				'redirect_uri'   => 'https://claude.ai/api/mcp/auth_callback',
				'code_challenge' => str_repeat( 'a', 43 ),
			),
			'st4te',
			AB_MCP_Tool_Registry::scopes()
		);
		$x = $this->xpath( (string) ob_get_clean() );

		$radios = $x->query( '//form[@method="post"]//input[@type="radio"][@name="ab_scope"]' );
		$order  = array();
		$on     = array();
		foreach ( $radios as $radio ) {
			$order[] = $radio->getAttribute( 'value' );
			if ( $radio->hasAttribute( 'checked' ) ) {
				$on[] = $radio->getAttribute( 'value' );
			}
		}
		self::assertSame( array( 'read', 'content', 'full' ), $order, 'Every scope, narrowest first, inside the form that posts.' );
		self::assertSame( array( 'content' ), $on, 'Exactly one checked, and never full.' );
		self::assertStringContainsString( 'user7', $x->query( '//*[@class="who"]' )->item( 0 )->textContent, 'The page it is part of rendered.' );
	}

	public function testAContentTokenChangesWhatItsSentenceNames(): void {
		foreach ( glob( dirname( __DIR__ ) . '/includes/tools/class-tools-*.php' ) as $file ) {
			require_once $file;
		}
		$r = new AB_MCP_Tool_Registry();
		foreach ( get_declared_classes() as $class ) {
			if ( 0 === strpos( $class, 'AB_MCP_Tools_' ) && ! ( new \ReflectionClass( $class ) )->isAbstract() ) {
				$class::register( $r );
			}
		}

		$writers = array();
		foreach ( $r->all() as $name => $def ) {
			if ( AB_MCP_Tool_Registry::scope_allows( 'content', $name, $def ) && ! AB_MCP_Tool_Registry::is_read_only( $name, $def ) ) {
				$writers[] = $name;
				self::assertMatchesRegularExpression( '/_(post|media|term|comment)(_|$)/', $name, 'A content token changes only posts, pages, media, terms and comments.' );
			}
		}
		self::assertGreaterThan( 10, count( $writers ), 'Free\'s writers are all there.' );
		self::assertSame(
			array( 'wp_delete_post', 'wp_delete_media', 'wp_delete_term', 'wp_delete_comment' ),
			array_values( array_filter( $writers, static fn( string $n ): bool => 0 === strpos( $n, 'wp_delete_' ) ) ),
			'Deleting is among them, so the sentence says so.'
		);
	}

	public function testWithoutContentTheConsentScreenFallsBackToReadNeverFull(): void {
		$default = ( new ReflectionMethod( AB_MCP_OAuth::class, 'default_scope' ) )->invoke( null, array( 'read' => 'r', 'full' => 'f' ) );

		self::assertSame( 'read', $default );
	}

	public function testAnApprovalWithoutAChoiceIsContent(): void {
		$redirect  = 'https://app.example/callback';
		$verifier  = 'verifier-verifier-verifier-verifier-verifier-1234';
		$challenge = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
		update_option(
			AB_MCP_OAuth::OPT_CLIENTS,
			array(
				'abc_registered' => array(
					'name'          => 'Registered',
					'redirect_uris' => array( $redirect ),
				),
			)
		);
		ab_test_add_user( 7 );
		$GLOBALS['ab_test_current_user'] = 7;
		$GLOBALS['ab_test_logged_in']    = true;
		$GLOBALS['ab_test_can']          = static fn(): bool => true;
		$_GET                            = array(
			'ab_mcp_oauth'          => 'authorize',
			'client_id'             => 'abc_registered',
			'redirect_uri'          => $redirect,
			'response_type'         => 'code',
			'code_challenge'        => $challenge,
			'code_challenge_method' => 'S256',
			'state'                 => 'st4te',
		);
		$_REQUEST                        = $_GET;
		$_POST                           = array( '_wpnonce' => 'nonce-ab_mcp_oauth_approve' ); // No ab_scope.
		$_SERVER['REQUEST_METHOD']       = 'POST';

		try {
			AB_MCP_OAuth::handle_authorize();
			self::fail( 'The approval ended without a redirect.' );
		} catch ( AbTestExit $exit ) {
			self::assertSame( 'redirect', $exit->kind );
		}

		$scopes = array();
		foreach ( $GLOBALS['ab_test_transients'] as $record ) {
			if ( is_array( $record ) && isset( $record['scope'] ) ) {
				$scopes[] = $record['scope'];
			}
		}
		self::assertSame( array( 'content' ), $scopes );
	}
}

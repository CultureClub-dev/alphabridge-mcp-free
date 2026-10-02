<?php
/**
 * The settings screen around the connect card: the band at the top, the
 * fine-tuning of the tool switches, the order of the main column and the side
 * column. The main switch for write access at the very top has a test of its
 * own (SiteModeTest).
 *
 * The fine-tuning form must post what it always posted — the handler reads
 * enabled_tools[] and all_tools — while the switches for all tools, for a
 * main group and for a tool group only move the switches below them and post
 * nothing themselves. It is open from the start and shows a few main groups
 * (only the display is grouped); each opens its tool groups, and each of
 * those its tools. The only badges are Reads and Writes, each with a symbol;
 * no «Mighty». An add-on puts its items into the same form (form="ab-caps")
 * and saves them on ab_mcp_fine_save, so one button saves everything; a form
 * of its own (an add-on before 4.5.0) still works without nesting forms.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use PHPUnit\Framework\TestCase;
use AB_MCP_Admin;
use AB_MCP_REST_Controller;
use AB_MCP_Tool_Registry;
use DOMDocument;
use DOMXPath;
use ReflectionMethod;

final class AdminPageTest extends TestCase {

	protected function setUp(): void {
		ab_test_reset();
	}

	/** Two groups, three tools; the dangerous one is marked Mighty. */
	private function registry(): AB_MCP_Tool_Registry {
		$r = new AB_MCP_Tool_Registry();
		$r->set_current_group( 'content', 'Posts & Pages' );
		$r->register( 'wp_list_posts', array( 'description' => 'List posts. With filters and paging.' ) );
		$r->register(
			'wp_delete_post',
			array(
				'description' => 'Delete a post.',
				'dangerous'   => true,
			)
		);
		$r->set_current_group( 'seo', 'SEO' );
		$r->register( 'wp_seo_get', array( 'description' => 'Read the SEO fields of a post.' ) );
		return $r;
	}

	/** @param mixed ...$args */
	private function call( string $method, ...$args ): string {
		$m = new ReflectionMethod( AB_MCP_Admin::class, $method );
		ob_start();
		$out = $m->invoke( new AB_MCP_Admin(), ...$args );
		$echoed = (string) ob_get_clean();
		return $echoed . ( is_string( $out ) ? $out : '' );
	}

	private function xpath( string $html ): DOMXPath {
		$doc = new DOMDocument();
		$doc->loadHTML( '<!doctype html><meta charset="utf-8"><body>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING );
		return new DOMXPath( $doc );
	}

	/** The fine-tuning card, with one tool switched off by an administrator. */
	private function caps(): DOMXPath {
		update_option( 'ab_mcp_tool_state', array( 'wp_delete_post' => false ) );
		$r = $this->registry();
		return $this->xpath( $this->call( 'capabilities_card_html', $r->groups(), $r->all() ) );
	}

	public function testTheBandCountsConnectionsToolsAndTheMode(): void {
		$all = $this->registry()->all();
		update_option( 'ab_mcp_tool_state', array( 'wp_delete_post' => false ) );
		$html = $this->call( 'hero_html', array( array( 'hash' => 'h1' ) ), $all );

		self::assertStringContainsString( '<b>1</b> connection<', $html, 'One connection, singular.' );
		self::assertStringContainsString( '<b>2</b> of <b>3</b> tools can run with write access off', $html, 'Write access off: switched on and reading.' );
		self::assertStringContainsString( '>Write access off (read only)</a>', $html );
		self::assertStringContainsString( 'href="#ab-mode"', $html, 'The chip leads to the switch.' );
		self::assertStringNotContainsString( 'ab-dot--warn', $html );
		self::assertStringNotContainsString( 'Mode:', $html );

		update_option( 'ab_mcp_tool_state', array() );
		self::assertStringContainsString( '<b>2</b> of <b>3</b> tools can run with write access off', $this->call( 'hero_html', array(), $all ), 'A writing tool switched on does not run with write access off.' );

		update_option( 'ab_mcp_tool_state', array( 'wp_delete_post' => false ) );
		update_option( 'ab_mcp_options', array( 'site_mode' => 'full' ) );
		$full = $this->call( 'hero_html', array(), $all );
		self::assertStringContainsString( '<b>2</b> of <b>3</b> tools on', $full );
		self::assertStringContainsString( '<b>0</b> connections', $full );
		self::assertStringContainsString( '>Write access on (full power)</a>', $full );
		self::assertStringContainsString( 'ab-dot--warn', $full );
	}

	public function testTheBandOffersFourAssistantsWithClaudeFirst(): void {
		$x = $this->xpath( $this->call( 'hero_html', array(), $this->registry()->all() ) );

		$buttons = $x->query( '//button[contains(@class, "ab-client")]' );
		$clients = array();
		foreach ( $buttons as $b ) {
			$clients[ $b->getAttribute( 'data-client' ) ] = $b->getAttribute( 'aria-pressed' );
		}
		self::assertSame(
			array(
				'claude'  => 'true',
				'chatgpt' => 'false',
				'cursor'  => 'false',
				'other'   => 'false',
			),
			$clients
		);
		self::assertSame( AB_MCP_URL . 'assets/logo-128.png', $x->query( '//img[@class="ab-logo"]' )->item( 0 )->getAttribute( 'src' ), 'The logo ships with the plugin.' );
	}

	public function testCapabilitiesPostEveryToolAsBefore(): void {
		$x = $this->caps();

		// The form holds its hidden fields; every switch joins it through the
		// form attribute, so a form of an add-on in a group is not nested.
		$tools = array();
		foreach ( $x->query( '//input[@name="enabled_tools[]"]' ) as $in ) {
			self::assertSame( 'ab-caps', $in->getAttribute( 'form' ), $in->getAttribute( 'value' ) . ' posts with the fine-tuning form.' );
			$tools[ $in->getAttribute( 'value' ) ] = $in->hasAttribute( 'checked' );
		}
		self::assertSame( 1, $x->query( '//button[@type="submit"][@form="ab-caps"]' )->length, 'The save button submits that form.' );
		self::assertSame(
			array(
				'wp_list_posts'  => true,
				'wp_delete_post' => false,
				'wp_seo_get'     => true,
			),
			$tools
		);
		self::assertSame( 'wp_list_posts,wp_delete_post,wp_seo_get', $x->query( '//form[@id="ab-caps"]//input[@name="all_tools"]' )->item( 0 )->getAttribute( 'value' ) );
		self::assertSame( 0, $x->query( '//input[@name="read_only"]' )->length, 'The read-only switch gave way to the site mode.' );
		self::assertSame( 0, $x->query( '//*[contains(@class, "ab-profile")]' )->length, 'No profiles any more.' );
		foreach ( array( 'ab-all-toggle', 'ab-group-toggle' ) as $class ) {
			foreach ( $x->query( '//input[contains(@class, "' . $class . '")]' ) as $in ) {
				self::assertFalse( $in->hasAttribute( 'name' ), $class . ' posts nothing itself.' );
			}
		}
		self::assertSame( 'nonce-ab_mcp_save', $x->query( '//form[@id="ab-caps"]//input[@name="_wpnonce"]' )->item( 0 )->getAttribute( 'value' ), 'Signed for the save handler.' );
		self::assertSame( 'ab_mcp_save', $x->query( '//form[@id="ab-caps"]//input[@name="action"]' )->item( 0 )->getAttribute( 'value' ) );
		self::assertSame( 1, $x->query( '//input[@type="search"][contains(@class, "ab-search")]' )->length );
		self::assertSame( '2', trim( $x->query( '//*[contains(@class, "ab-on-count")]' )->item( 0 )->textContent ) );
	}

	/**
	 * Three main groups' worth of tools: posts and SEO (content), the
	 * database with a reader that runs in Full only (system), options (site).
	 */
	private function wide_registry(): AB_MCP_Tool_Registry {
		$r = $this->registry();
		$r->set_current_group( 'database', 'Database' );
		$r->register(
			'wp_db_query',
			array(
				'description' => 'Run a read-only SQL query.',
				'dangerous'   => true,
			)
		);
		$r->set_current_group( 'options', 'Options & Settings' );
		$r->register( 'wp_get_site_settings', array( 'description' => 'Read the site settings.' ) );
		$r->register( 'wp_update_option', array( 'description' => 'Change an option.' ) );
		return $r;
	}

	private function wide_caps(): DOMXPath {
		update_option( 'ab_mcp_tool_state', array( 'wp_delete_post' => false, 'wp_get_site_settings' => false, 'wp_update_option' => false ) );
		$r = $this->wide_registry();
		return $this->xpath( $this->call( 'capabilities_card_html', $r->groups(), $r->all() ) );
	}

	private function group_state( DOMXPath $x, string $main ): \DOMElement {
		$el = $x->query( '//div[@data-main="' . $main . '"]//*[contains(@class, "ab-group__head")]//*[contains(@class, "ab-status")]' )->item( 0 );
		self::assertInstanceOf( \DOMElement::class, $el, $main );
		return $el;
	}

	public function testAMainGroupShowsHowManyOfItsToolsAreOn(): void {
		$x = $this->wide_caps();

		self::assertSame( '2 of 3 on', trim( $this->group_state( $x, 'content' )->textContent ), 'Posts and SEO together; the delete tool is off.' );
		self::assertSame( 'partial', $this->group_state( $x, 'content' )->getAttribute( 'data-state' ) );
		self::assertSame( '1 of 1 on', trim( $this->group_state( $x, 'system' )->textContent ) );
		self::assertSame( 'on', $this->group_state( $x, 'system' )->getAttribute( 'data-state' ) );
		self::assertSame( '0 of 2 on', trim( $this->group_state( $x, 'site' )->textContent ) );
		self::assertSame( 'off', $this->group_state( $x, 'site' )->getAttribute( 'data-state' ) );
		self::assertStringNotContainsString( 'ab-pill', $this->group_state( $x, 'content' )->getAttribute( 'class' ), 'The state is plain text, no badge.' );
		$toggle = fn( string $main ) => $x->query( '//div[@data-main="' . $main . '"]//input[contains(@class, "ab-group-toggle")]' )->item( 0 );
		self::assertFalse( $toggle( 'content' )->hasAttribute( 'checked' ), 'Partly on: not checked, so a click switches all of it on.' );
		self::assertTrue( $toggle( 'system' )->hasAttribute( 'checked' ), 'All on: checked.' );
		self::assertFalse( $toggle( 'site' )->hasAttribute( 'checked' ) );
		self::assertSame( 'All tools in Content', trim( $toggle( 'content' )->parentNode->textContent ), 'The group switch has a name.' );
	}

	public function testEachMainGroupCountsItsToolsThatReadAndThatWrite(): void {
		$x = $this->wide_caps();

		$badges = function ( string $main ) use ( $x ): array {
			$out = array();
			foreach ( $x->query( '//div[@data-main="' . $main . '"]//*[contains(@class, "ab-group__badges")]/span' ) as $b ) {
				$out[] = $b->getAttribute( 'class' ) . ': ' . trim( $b->textContent );
			}
			return $out;
		};
		self::assertSame( array( 'ab-pill ab-pill--reads: 2 read', 'ab-pill ab-pill--writes: 1 write' ), $badges( 'content' ) );
		self::assertSame( array( 'ab-pill ab-pill--reads: 1 read' ), $badges( 'system' ), 'No badge for a kind with no tool.' );
		self::assertSame( array( 'ab-pill ab-pill--reads: 1 read', 'ab-pill ab-pill--writes: 1 write' ), $badges( 'site' ) );
	}

	public function testTheOnlyBadgesAreReadAndWrite(): void {
		$x = $this->wide_caps();

		$pills = $x->query( '//*[contains(concat(" ", @class, " "), " ab-pill ")]' );
		self::assertGreaterThan( 0, $pills->length );
		foreach ( $pills as $p ) {
			self::assertMatchesRegularExpression( '/^ab-pill ab-pill--(reads|writes)$/', $p->getAttribute( 'class' ), trim( $p->textContent ) );
		}
		self::assertStringNotContainsString( 'Mighty', $x->query( '//section[@id="ab-fine"]' )->item( 0 )->textContent, 'No «Mighty» on the fine-tuning.' );
		self::assertSame( 0, $x->query( '//*[contains(@class, "mighty")]' )->length, 'Nor its badge.' );

		$kind = function ( string $tool ) use ( $x ): string {
			$row  = $x->query( '//li[.//input[@value="' . $tool . '"]]' )->item( 0 );
			$pill = $x->query( './/*[contains(@class, "ab-pill")]', $row );
			self::assertSame( 1, $pill->length, $tool . ' has exactly one badge.' );
			return trim( $pill->item( 0 )->textContent );
		};
		self::assertSame( 'Reads', $kind( 'wp_list_posts' ) );
		self::assertSame( 'Writes', $kind( 'wp_delete_post' ), 'Mighty and writing: Writes, nothing more.' );
		self::assertSame( 'Reads', $kind( 'wp_db_query' ) );
		self::assertSame( 'Writes', $kind( 'wp_update_option' ) );
		foreach ( $pills as $p ) {
			$svg = $x->query( './/*[local-name()="svg"]', $p );
			self::assertSame( 1, $svg->length, 'A symbol beside the word: ' . trim( $p->textContent ) );
			self::assertSame( 'true', $svg->item( 0 )->getAttribute( 'aria-hidden' ), 'The word says it; the symbol is hidden from screen readers.' );
		}
	}

	public function testAReaderThatWaitsForFullSaysSoInPlainText(): void {
		$x = $this->wide_caps();

		$note = $x->query( '//li[.//input[@value="wp_db_query"]]//*[contains(@class, "ab-tool-row__note")]' );
		self::assertSame( 1, $note->length );
		self::assertSame( 'only with write access', trim( $note->item( 0 )->textContent ) );
		self::assertSame( 'span', $note->item( 0 )->nodeName );
		self::assertStringNotContainsString( 'ab-pill', $note->item( 0 )->getAttribute( 'class' ), 'Text, not a badge.' );
		foreach ( array( 'wp_list_posts', 'wp_delete_post', 'wp_update_option', 'wp_seo_get' ) as $tool ) {
			self::assertSame( 0, $x->query( '//li[.//input[@value="' . $tool . '"]]//*[contains(@class, "ab-tool-row__note")]' )->length, $tool . ': runs in Read, or writes.' );
		}
		self::assertStringContainsString( 'only with write access', $x->query( '//li[.//input[@value="wp_db_query"]]' )->item( 0 )->getAttribute( 'data-search' ), 'The search finds it by the note.' );
	}

	public function testEveryMainGroupIsAButtonThatOpensItsTools(): void {
		$x = $this->wide_caps();

		$mains = array();
		foreach ( $x->query( '//div[contains(@class, "ab-groups")]/div[contains(@class, "ab-group")]' ) as $g ) {
			$main    = $g->getAttribute( 'data-main' );
			$mains[] = $main;
			$button  = $x->query( './/button[contains(@class, "ab-group__toggle")]', $g )->item( 0 );
			self::assertNotNull( $button, $main );
			self::assertSame( 'button', $button->getAttribute( 'type' ), 'Never submits.' );
			self::assertSame( 'false', $button->getAttribute( 'aria-expanded' ), $main . ': its tools are folded until the click.' );
			$body = $x->query( '//*[@id="' . $button->getAttribute( 'aria-controls' ) . '"]' )->item( 0 );
			self::assertNotNull( $body, $main . ': the button names what it opens.' );
			self::assertTrue( $body->hasAttribute( 'hidden' ), $main );
			self::assertTrue( $g->isSameNode( $body->parentNode ), $main . ': the tools belong to the row.' );
			self::assertSame( 0, $x->query( './/input', $button )->length, 'The switch is not inside the button.' );
		}
		self::assertSame( array( 'content', 'site', 'system' ), $mains, 'Only main groups with tools, in their order.' );
		self::assertSame( 0, $x->query( '//details[contains(@class, "ab-group")]' )->length, 'No group is a details element any more.' );
	}

	public function testAMainGroupNamesItsToolGroupsWhenItHasMoreThanOne(): void {
		$x = $this->wide_caps();

		$titles = function ( string $main ) use ( $x ): array {
			$out = array();
			foreach ( $x->query( '//div[@data-main="' . $main . '"]//*[contains(@class, "ab-sub__name")]' ) as $t ) {
				$out[] = trim( $t->textContent );
			}
			return $out;
		};
		self::assertSame( array( 'Posts and pages', 'SEO' ), $titles( 'content' ) );
		self::assertSame( array(), $titles( 'system' ), 'One tool group: its tools stand in the main group directly.' );
		self::assertSame( 1, $x->query( '//div[@data-main="system"]//ul[contains(@class, "ab-tool-list--direct")]//input[@value="wp_db_query"]' )->length );
	}

	public function testEachToolShowsItsShortNameAndTheDescriptionBehindTheInfo(): void {
		$x = $this->caps();

		$row = $x->query( '//li[.//input[@value="wp_list_posts"]]' )->item( 0 );
		self::assertSame( 'List posts and pages', trim( $x->query( './/*[contains(@class, "ab-tool-row__label")]', $row )->item( 0 )->textContent ), 'The short name, not the description for the assistant.' );
		self::assertSame( 'wp_list_posts', trim( $x->query( './/code', $row )->item( 0 )->textContent ) );
		self::assertSame( 'List posts. With filters and paging.', trim( $x->query( './/*[contains(@class, "ab-tip")]', $row )->item( 0 )->textContent ) );

		$info = $x->query( './/button[contains(@class, "ab-info")]', $row )->item( 0 );
		self::assertSame( 'ab-tip-wp_list_posts', $info->getAttribute( 'aria-describedby' ), 'Screen readers get the whole description.' );
		self::assertSame( 1, $x->query( '//*[@id="ab-tip-wp_list_posts"]' )->length );

		$one = $x->query( '//li[.//input[@value="wp_seo_get"]]' )->item( 0 );
		self::assertSame( 'Read SEO title and description', trim( $x->query( './/*[contains(@class, "ab-tool-row__label")]', $one )->item( 0 )->textContent ) );
		self::assertSame( 'Read the SEO fields of a post.', trim( $x->query( './/*[contains(@class, "ab-tip")]', $one )->item( 0 )->textContent ), 'A one-sentence description behind the «i» too.' );
	}

	public function testAToolWithoutAShortNameShowsItsFirstSentence(): void {
		$r = $this->registry();
		$r->set_current_group( 'acme', 'Acme' );
		$r->register( 'acme_sync', array( 'description' => 'Sync the catalogue. Runs for minutes.' ) );
		$r->register( 'acme_ping', array( 'description' => 'Ping the shop.' ) );
		$r->register( 'acme_named', array( 'description' => 'Something long for the assistant. More.' ) );
		add_filter( 'ab_mcp_tool_label', static fn( string $label, string $name ): string => 'acme_named' === $name ? 'Named by its add-on' : $label, 10, 2 );
		$x = $this->xpath( $this->call( 'capabilities_card_html', $r->groups(), $r->all() ) );

		$label = static fn( string $tool ): string => trim( $x->query( '//li[.//input[@value="' . $tool . '"]]//*[contains(@class, "ab-tool-row__label")]' )->item( 0 )->textContent );
		self::assertSame( 'Sync the catalogue.', $label( 'acme_sync' ) );
		self::assertSame( 1, $x->query( '//li[.//input[@value="acme_sync"]]//*[contains(@class, "ab-info")]' )->length );
		self::assertSame( 'Ping the shop.', $label( 'acme_ping' ) );
		self::assertSame( 0, $x->query( '//li[.//input[@value="acme_ping"]]//*[contains(@class, "ab-info")]' )->length, 'Nothing more to say: no «i».' );
		self::assertSame( 'Named by its add-on', $label( 'acme_named' ) );
	}

	public function testTheSearchFindsAToolByItsShortNameAndItsGroups(): void {
		$x      = $this->caps();
		$search = $x->query( '//li[.//input[@value="wp_seo_get"]]' )->item( 0 )->getAttribute( 'data-search' );

		foreach ( array( 'wp_seo_get', 'read seo title and description', 'read the seo fields of a post.', 'seo', 'content' ) as $word ) {
			self::assertStringContainsString( $word, $search, 'Found by: ' . $word );
		}
		self::assertStringContainsString( 'posts and pages', $x->query( '//li[.//input[@value="wp_delete_post"]]' )->item( 0 )->getAttribute( 'data-search' ), 'By the name of its tool group.' );
	}

	public function testEveryToolOfThisPluginHasAShortName(): void {
		foreach ( glob( dirname( __DIR__ ) . '/includes/tools/class-tools-*.php' ) as $file ) {
			require_once $file;
		}
		$r = new AB_MCP_Tool_Registry();
		foreach ( get_declared_classes() as $class ) {
			if ( 0 === strpos( $class, 'AB_MCP_Tools_' ) && ! ( new \ReflectionClass( $class ) )->isAbstract() && method_exists( $class, 'register' ) ) {
				$class::register( $r );
			}
		}
		$missing = array();
		foreach ( array_keys( $r->all() ) as $name ) {
			if ( '' === \AB_MCP_Tool_Labels::get( $name ) ) {
				$missing[] = $name;
			}
		}
		self::assertGreaterThan( 35, count( $r->all() ) );
		self::assertSame( array(), $missing );
		$all = \AB_MCP_Tool_Labels::all();
		self::assertSame( count( $all ), count( array_unique( $all ) ), 'No two tools share a name.' );
		foreach ( $all as $name => $label ) {
			self::assertLessThanOrEqual( 40, strlen( $label ), $name . ': short enough for one line.' );
		}
	}

	public function testTheMainColumnKeepsItsOrder(): void {
		add_action(
			'ab_mcp_admin_main_boxes',
			static function () {
				echo '<div id="addon-main"></div>';
			}
		);
		$r    = $this->registry();
		$html = $this->call( 'render_main_column', array(), $r->groups(), $r->all() );

		$at = array();
		foreach ( array( 'id="ab-connect"', 'id="ab-caps"', 'id="ab-connections"', 'id="addon-main"', 'id="ab-log"' ) as $marker ) {
			$pos = strpos( $html, $marker );
			self::assertIsInt( $pos, $marker );
			$at[] = $pos;
		}
		$sorted = $at;
		sort( $sorted );
		self::assertSame( $sorted, $at, 'Connect, fine-tuning, connections, add-on boxes, log.' );
	}

	public function testTheFineTuningIsOpenAndSaysSo(): void {
		$x    = $this->caps();
		$card = $x->query( '//section[@id="ab-fine"]' )->item( 0 );

		self::assertNotNull( $card, 'The switches sit in a card of their own.' );
		self::assertSame( 0, $x->query( '//details[@id="ab-fine"] | //section[@id="ab-fine"]/ancestor::details' )->length, 'Open from the start, nothing folds it: the main groups show.' );
		self::assertSame( 'Fine-tuning', trim( $x->query( './/*[contains(@class, "ab-eyebrow")]', $card )->item( 0 )->textContent ) );
		self::assertSame( 'Switch single tools on and off', trim( $x->query( './/h2', $card )->item( 0 )->textContent ) );
		self::assertSame( $card->getAttribute( 'aria-labelledby' ), $x->query( './/h2', $card )->item( 0 )->getAttribute( 'id' ) );
		self::assertSame( 1, $x->query( './/form[@id="ab-caps"]', $card )->length, 'The form is inside the card.' );
		self::assertStringContainsString( 'Every tool is on until you switch it off here. No AI assistant sees a switched-off tool. Writing tools run only with write access on, and so do the reading ones noted “only with write access”: they read code, files, the database or logs.', $card->textContent );
	}

	public function testTheFineTuningSaysThatSwitchingOnWriteAccessSwitchesEverythingOn(): void {
		$x     = $this->caps();
		$reset = $x->query( '//section[@id="ab-fine"]//*[contains(@class, "ab-fine__reset")]' );

		self::assertSame( 1, $reset->length );
		self::assertSame( 'Switching on write access switches everything here on; what you switch off afterwards stays off until write access is switched on the next time.', trim( $reset->item( 0 )->textContent ) );
		self::assertSame( 1, preg_match_all( '/[.!?](\s|$)/', trim( $reset->item( 0 )->textContent ) ), 'One sentence.' );
	}

	public function testTheBarCountsAllToolsWithTheTwoBadges(): void {
		$x   = $this->wide_caps();
		$bar = $x->query( '//*[contains(@class, "ab-capbar")]' )->item( 0 );

		$badges = array();
		foreach ( $x->query( './/*[contains(@class, "ab-capbar__badges")]/span', $bar ) as $b ) {
			$badges[] = trim( $b->textContent );
		}
		self::assertSame( array( '4 read', '2 write' ), $badges, 'Every tool of the registry, by kind.' );
		self::assertSame( '3 of 6 on', trim( preg_replace( '/\s+/', ' ', $x->query( './/*[contains(@class, "ab-capbar__count")]', $bar )->item( 0 )->textContent ) ) );
		self::assertSame( 'All tools', trim( $x->query( './/label[.//input[contains(@class, "ab-all-toggle")]]', $bar )->item( 0 )->textContent ) );
	}

	public function testThreeLevelsMainGroupsToolGroupsTools(): void {
		$x = $this->wide_caps();

		$content = $x->query( '//div[@data-main="content"]' )->item( 0 );
		self::assertSame( 'Posts and pages, SEO', trim( $x->query( './/*[contains(@class, "ab-group__desc")]', $content )->item( 0 )->textContent ), 'The line under the name says what is in it.' );
		$subs = $x->query( './/div[contains(concat(" ", @class, " "), " ab-sub ")]', $content );
		self::assertSame( 2, $subs->length );
		foreach ( $subs as $sub ) {
			$button = $x->query( './/button[contains(@class, "ab-sub__toggle")]', $sub )->item( 0 );
			self::assertSame( 'button', $button->getAttribute( 'type' ) );
			self::assertSame( 'false', $button->getAttribute( 'aria-expanded' ), 'A tool group opens on a click.' );
			$list = $x->query( '//*[@id="' . $button->getAttribute( 'aria-controls' ) . '"]' )->item( 0 );
			self::assertNotNull( $list, 'The button names what it opens.' );
			self::assertTrue( $list->hasAttribute( 'hidden' ) );
			self::assertTrue( $sub->isSameNode( $list->parentNode ) );
			$toggle = $x->query( './/input[contains(@class, "ab-sub-toggle")]', $sub )->item( 0 );
			self::assertFalse( $toggle->hasAttribute( 'name' ), 'The switch of a tool group posts nothing.' );
			self::assertSame( 0, $x->query( './/input', $button )->length, 'The switch is not inside the button.' );
			self::assertGreaterThan( 0, $x->query( './/li[contains(@class, "ab-tool-row")]//input[@name="enabled_tools[]"]', $list )->length );
		}
		self::assertSame( 'ab-tool-wp_list_posts', $x->query( '//li[.//input[@value="wp_list_posts"]]' )->item( 0 )->getAttribute( 'id' ), 'A link can land on a single tool.' );
	}

	public function testEverySwitchIsASwitchWithAName(): void {
		$x = $this->wide_caps();

		$boxes = $x->query( '//section[@id="ab-fine"]//input[@type="checkbox"]' );
		self::assertGreaterThan( 8, $boxes->length );
		foreach ( $boxes as $in ) {
			self::assertSame( 'switch', $in->getAttribute( 'role' ), $in->getAttribute( 'class' ) . ' ' . $in->getAttribute( 'value' ) );
			$label = $in->parentNode;
			while ( null !== $label && 'label' !== $label->nodeName ) {
				$label = $label->parentNode;
			}
			self::assertNotNull( $label, 'Inside its label.' );
			self::assertNotSame( '', trim( $label->textContent ), 'With a name.' );
		}
		foreach ( $x->query( '//section[@id="ab-fine"]//button[contains(@class, "ab-group__toggle") or contains(@class, "ab-sub__toggle")]' ) as $b ) {
			self::assertTrue( $b->hasAttribute( 'aria-expanded' ) );
			self::assertTrue( $b->hasAttribute( 'aria-controls' ) );
		}
	}

	/**
	 * A registry with one tool in every tool group the core and the Pro
	 * add-on register (Pro 4.6.0, alphabridge-mcp-pro.php).
	 */
	private function pro_like_registry(): AB_MCP_Tool_Registry {
		$r = new AB_MCP_Tool_Registry();
		foreach ( array( 'content', 'builders', 'media', 'taxonomy', 'widgets', 'options', 'system', 'search', 'lifecycle', 'seo', 'meta-auth', 'ops', 'users', 'plugins-themes', 'database', 'woocommerce', 'files', 'php-snippets', 'migration', 'multisite', 'transfer', 'undo', 'blueprint', 'abilities', 'deploy' ) as $group ) {
			$r->set_current_group( $group, 'Label of ' . $group );
			$r->register( 'wp_get_' . str_replace( '-', '_', $group ), array( 'description' => 'Read ' . $group . '.' ) );
		}
		return $r;
	}

	public function testWithProThereAreSevenMainGroupsAndEveryToolGroupHasOne(): void {
		$r     = $this->pro_like_registry();
		$fine  = AB_MCP_Admin::fine_groups( $r->groups(), $r->all() );
		$where = array();
		foreach ( $fine as $main => $m ) {
			$where[ $main ] = array_keys( $m['groups'] );
		}

		self::assertSame(
			array(
				'content'   => array( 'content', 'builders', 'media', 'taxonomy', 'widgets', 'seo', 'search' ),
				'site'      => array( 'options', 'users', 'meta-auth', 'multisite' ),
				'code'      => array( 'lifecycle', 'plugins-themes', 'files', 'php-snippets', 'deploy', 'transfer', 'blueprint', 'migration' ),
				'system'    => array( 'database', 'system', 'ops' ),
				'shop'      => array( 'woocommerce' ),
				'undo'      => array( 'undo' ),
				'abilities' => array( 'abilities' ),
			),
			$where
		);
		self::assertCount( 25, array_merge( ...array_values( $where ) ), 'Every tool group shows, in exactly one main group.' );
		self::assertSame(
			array(
				'content'   => 'Content',
				'site'      => 'Site & users',
				'code'      => 'Plugins, themes & code',
				'system'    => 'Database & operations',
				'shop'      => 'Shop (WooCommerce)',
				'undo'      => 'Undo',
				'abilities' => 'Abilities of other plugins',
			),
			array_map( static fn( array $m ): string => $m['label'], $fine )
		);
	}

	public function testTheFreePluginAloneShowsFourMainGroups(): void {
		$r = new AB_MCP_Tool_Registry();
		foreach ( array( 'content', 'media', 'taxonomy', 'widgets', 'options', 'system', 'search', 'lifecycle', 'seo', 'meta-auth', 'ops' ) as $group ) {
			$r->set_current_group( $group, $group );
			$r->register( 'wp_get_' . str_replace( '-', '_', $group ), array() );
		}
		self::assertSame( array( 'content', 'site', 'code', 'system' ), array_keys( AB_MCP_Admin::fine_groups( $r->groups(), $r->all() ) ), 'No Shop, Undo or abilities of other plugins without their tools.' );
	}

	public function testAnUnknownToolGroupGoesToMoreToolsUnlessItsAddOnPlacesIt(): void {
		$r = $this->registry();
		$r->set_current_group( 'acme', 'Acme' );
		$r->register( 'wp_acme_ping', array( 'description' => 'Ping Acme.' ) );

		$fine = AB_MCP_Admin::fine_groups( $r->groups(), $r->all() );
		self::assertSame( array( 'content', 'more' ), array_keys( $fine ), 'More tools comes last.' );
		self::assertSame( array( 'acme' ), array_keys( $fine['more']['groups'] ) );
		self::assertSame( 'More tools', $fine['more']['label'] );
		self::assertSame( 'Acme', $fine['more']['groups']['acme']['label'], 'An unknown group keeps its own name.' );

		add_filter(
			'ab_mcp_tool_main_group',
			static function ( $main, $group ) {
				return 'acme' === $group ? 'site' : $main;
			},
			10,
			2
		);
		$fine = AB_MCP_Admin::fine_groups( $r->groups(), $r->all() );
		self::assertSame( array( 'content', 'site' ), array_keys( $fine ), 'Placed by its add-on.' );

		add_filter(
			'ab_mcp_tool_main_group',
			static function ( $main, $group ) {
				return 'acme' === $group ? 'no-such-group' : $main;
			},
			20,
			2
		);
		self::assertSame( array( 'content', 'more' ), array_keys( AB_MCP_Admin::fine_groups( $r->groups(), $r->all() ) ), 'A main group that does not exist: More tools.' );

		add_filter(
			'ab_mcp_tool_main_groups',
			static function ( $mains ) {
				$mains['no-such-group'] = 'Acme tools';
				$mains['more']          = 'Not this way';
				return $mains;
			}
		);
		$fine = AB_MCP_Admin::fine_groups( $r->groups(), $r->all() );
		self::assertSame( array( 'content', 'no-such-group' ), array_keys( $fine ), 'An add-on may add a main group.' );
		self::assertSame( 'Acme tools', $fine['no-such-group']['label'] );
		self::assertSame( 'More tools', AB_MCP_Admin::main_groups()['more'], 'More tools cannot be renamed or moved.' );
		self::assertSame( 'more', array_key_last( AB_MCP_Admin::main_groups() ) );
	}

	public function testOnlyTheDisplayIsGrouped(): void {
		$r      = $this->pro_like_registry();
		$before = $r->all();
		AB_MCP_Admin::fine_groups( $r->groups(), $r->all() );
		$x = $this->xpath( $this->call( 'capabilities_card_html', $r->groups(), $r->all() ) );

		self::assertSame( $before, $r->all(), 'The registry keeps every tool with its group.' );
		self::assertSame( 'database', $r->get( 'wp_get_database' )['group'] );
		$posted = array();
		foreach ( $x->query( '//input[@name="enabled_tools[]"]' ) as $in ) {
			$posted[] = $in->getAttribute( 'value' );
		}
		$names = array_keys( $r->all() );
		sort( $names );
		sort( $posted );
		self::assertSame( $names, $posted, 'Every tool has its switch, once (in the order of the main groups).' );
		self::assertSame( implode( ',', array_keys( $r->all() ) ), $x->query( '//form[@id="ab-caps"]//input[@name="all_tools"]' )->item( 0 )->getAttribute( 'value' ) );
	}

	public function testTheCoreNamesTheToolGroupsOfProInItsOwnLanguage(): void {
		self::assertSame( 'Users and menus', AB_MCP_Admin::group_label( 'users', 'Users & Menus' ) );
		self::assertSame( 'Database', AB_MCP_Admin::group_label( 'database', 'anything Pro says' ), 'A text of the core, so the core translates it.' );
		self::assertSame( 'Abilities of other plugins', AB_MCP_Admin::group_label( 'abilities', 'Vendor abilities' ) );
		self::assertSame(
			array( 'Install and update', 'Plugins and themes', 'Plugin files and uploads', 'PHP snippets (Code Snippets, WPCode)', 'Site Deploy (FTP/SFTP)', 'Large uploads in parts', 'Blueprint (site structure)', 'Export' ),
			array_map( static fn( string $g ): string => AB_MCP_Admin::group_label( $g ), array( 'lifecycle', 'plugins-themes', 'files', 'php-snippets', 'deploy', 'transfer', 'blueprint', 'migration' ) ),
			'The tool groups of «Plugins, themes & code», as the design names them.'
		);
		self::assertSame( 'Acme', AB_MCP_Admin::group_label( 'acme', 'Acme' ), 'Unknown: the registered label.' );
		self::assertSame( 'acme', AB_MCP_Admin::group_label( 'acme', '' ) );

		$src = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-admin.php' );
		foreach ( array_keys( AB_MCP_Admin::MAIN_OF ) as $group ) {
			self::assertNotSame( $group, AB_MCP_Admin::group_label( $group, '' ), $group . ' has a name of the core.' );
		}
		self::assertStringContainsString( "'woocommerce'    => 'WooCommerce',", $src, 'A product name is not translated.' );
	}

	public function testAddOnsGetTheBadgesAndNotesFromTheCore(): void {
		$text = static fn( string $html ): string => trim( (string) preg_replace( '/<[^>]+>/', '', $html ) );
		self::assertStringStartsWith( '<span class="ab-pill ab-pill--reads"><svg ', AB_MCP_Admin::kind_badge( true ) );
		self::assertSame( 'Reads', $text( AB_MCP_Admin::kind_badge( true ) ) );
		self::assertStringStartsWith( '<span class="ab-pill ab-pill--writes"><svg ', AB_MCP_Admin::kind_badge( false ) );
		self::assertSame( 'Writes', $text( AB_MCP_Admin::kind_badge( false ) ) );
		self::assertSame( '<span class="ab-tool-row__note ab-tool-row__note--deletes">deletes</span>', AB_MCP_Admin::tool_note( 'deletes' ) );
		self::assertSame( AB_MCP_Admin::tool_note( 'deletes' ), AB_MCP_Admin::tool_note( 'destructive' ), 'The name of before still works.' );
		self::assertSame( '<span class="ab-tool-row__note ab-tool-row__note--full_only">only with write access</span>', AB_MCP_Admin::tool_note( 'full_only' ) );
		self::assertSame( '', AB_MCP_Admin::tool_note( 'mighty' ), 'No Mighty note or badge any more.' );
		self::assertSame( '3 read 1 write', $text( AB_MCP_Admin::count_badges( 3, 1 ) ) );
		self::assertSame( '', AB_MCP_Admin::count_badges( 0, 0 ) );
	}

	public function testTheCoreHasTheTextsAnAddOnShowsHere(): void {
		self::assertSame( 'With Pro they also read users.', AB_MCP_Admin::addon_text( 'reads_also_users' ) );
		self::assertStringContainsString( 'With write access on, every ability runs, reading or writing, unless you switch it off here', AB_MCP_Admin::addon_text( 'abilities_lead' ) );
		self::assertStringContainsString( 'WordPress 6.9', AB_MCP_Admin::addon_text( 'abilities_need_wp' ) );
		self::assertStringContainsString( '%s', AB_MCP_Admin::addon_text( 'abilities_need_wp' ), 'The version goes in.' );
		foreach ( array( 'reads_also_shop', 'reads_also_users_shop', 'abilities_write_off', 'abilities_none', 'abilities_kept_off' ) as $key ) {
			self::assertNotSame( '', AB_MCP_Admin::addon_text( $key ), $key );
		}
		self::assertSame( '', AB_MCP_Admin::addon_text( 'no such text' ) );
	}

	public function testAnAddOnDrawsItsItemsLikeToolsWithTheCoresHelpers(): void {
		$row = AB_MCP_Admin::fine_row_html(
			array(
				'name'    => 'ab_x_on[]',
				'value'   => 'jetpack-forms/delete-form',
				'checked' => false,
				'label'   => 'Delete a form',
				'reads'   => false,
				'note'    => 'deletes',
			)
		);
		$sub = AB_MCP_Admin::fine_sub_html(
			array(
				'id'     => 'ability-jetpack-forms',
				'title'  => 'jetpack-forms',
				'reads'  => 0,
				'writes' => 1,
				'rows'   => $row,
				'open'   => true,
			)
		);
		$x  = $this->xpath( $sub );
		$in = $x->query( '//input[@name="ab_x_on[]"]' )->item( 0 );

		self::assertSame( 'ab-caps', $in->getAttribute( 'form' ), 'Posts with the fine-tuning, wherever it stands.' );
		self::assertSame( 'ab-item', $in->getAttribute( 'class' ), 'Moved by the switches for its group and for all, not counted as a tool.' );
		self::assertSame( 'switch', $in->getAttribute( 'role' ) );
		self::assertFalse( $in->hasAttribute( 'checked' ) );
		self::assertSame( 'jetpack-forms/delete-form', trim( $x->query( '//code' )->item( 0 )->textContent ) );
		self::assertSame( 'deletes', trim( $x->query( '//*[contains(@class, "ab-tool-row__note")]' )->item( 0 )->textContent ), '«deletes» as text, not a badge.' );
		self::assertSame( 'Writes', trim( $x->query( '//li//*[contains(@class, "ab-pill--writes")]' )->item( 0 )->textContent ) );
		self::assertSame( '1 write', trim( $x->query( '//*[contains(@class, "ab-sub__badges")]' )->item( 0 )->textContent ), 'The tool group counts it.' );
		$button = $x->query( '//button[contains(@class, "ab-sub__toggle")]' )->item( 0 );
		self::assertSame( 'true', $button->getAttribute( 'aria-expanded' ), 'Open, as asked.' );
		self::assertSame( 'ab-sub-ability-jetpack-forms', $button->getAttribute( 'aria-controls' ) );
		self::assertFalse( $x->query( '//*[@id="ab-sub-ability-jetpack-forms"]' )->item( 0 )->hasAttribute( 'hidden' ) );
		self::assertSame( 'All in jetpack-forms', trim( $x->query( '//label[.//input[contains(@class, "ab-sub-toggle")]]' )->item( 0 )->textContent ) );
		self::assertStringNotContainsString( '<form', $sub, 'No form of its own: the card\'s one form takes it.' );
	}

	public function testOneButtonSavesTheToolsAndWhatAnAddOnAdded(): void {
		$seen = null;
		add_action(
			'ab_mcp_fine_save',
			static function ( $posted ) use ( &$seen ) {
				$seen = $posted;
			}
		);
		$r = $this->registry();
		$GLOBALS['ab_test_can'] = static fn( string $cap ): bool => 'manage_options' === $cap;
		$_POST                  = array(
			'_wpnonce'      => 'nonce-ab_mcp_save',
			'action'        => 'ab_mcp_save',
			'enabled_tools' => array( 'wp_list_posts' ),
			'ab_x_on'       => array( 'jetpack-forms/create-form' ),
		);
		$_REQUEST = $_POST;
		try {
			( new AB_MCP_Admin( $r ) )->handle_save();
			self::fail( 'No redirect.' );
		} catch ( \AbTestExit $exit ) {
			self::assertSame( 'redirect', $exit->kind );
			self::assertStringEndsWith( '#ab-fine', $exit->detail, 'Back to the fine-tuning.' );
		} finally {
			$_POST    = array();
			$_REQUEST = array();
		}

		self::assertSame( array( 'wp_list_posts' => true, 'wp_delete_post' => false, 'wp_seo_get' => false ), get_option( 'ab_mcp_tool_state' ) );
		self::assertIsArray( $seen, 'The add-on saves on the core\'s action.' );
		self::assertSame( array( 'jetpack-forms/create-form' ), $seen['ab_x_on'] );
		self::assertSame( array( 'wp_list_posts' ), $seen['enabled_tools'] );

		$seen = null;
		$GLOBALS['ab_test_can'] = static fn( string $cap ): bool => 'manage_options' === $cap;
		$_POST                  = array( '_wpnonce' => 'nonce-ab_mcp_oauth_settings', 'ab_x_on' => array( 'x' ) );
		$_REQUEST               = $_POST;
		try {
			( new AB_MCP_Admin( $r ) )->handle_save();
		} catch ( \AbTestExit $exit ) {
			self::assertSame( 'die', $exit->kind, 'Without the form\'s nonce nothing is saved.' );
		} finally {
			$_POST    = array();
			$_REQUEST = array();
		}
		self::assertNull( $seen, 'Nor does the add-on hear of it.' );
	}

	public function testAnAddOnOfBeforeStillPutsItsOwnFormIntoAMainGroupWithoutNestingForms(): void {
		$seen = array();
		add_filter(
			'ab_mcp_fine_group_html',
			static function ( $html, $main, $groups ) use ( &$seen ) {
				$seen[ $main ] = $groups;
				if ( ! in_array( 'abilities', $groups, true ) ) {
					return $html;
				}
				return $html . '<div id="ab-abilities"><form id="addon-form" method="post"><input type="hidden" name="action" value="addon_save"><input type="checkbox" name="approved[]" value="x/y"><button name="save">Save</button></form></div>';
			},
			10,
			3
		);
		$r = $this->registry();
		$r->set_current_group( 'abilities', 'Vendor abilities' );
		$r->register( 'wp_list_abilities', array( 'description' => 'List abilities.' ) );
		$r->register( 'wp_run_ability', array( 'description' => 'Run an ability.' ) );
		$x = $this->xpath( $this->call( 'capabilities_card_html', $r->groups(), $r->all() ) );

		self::assertSame(
			array(
				'content'   => array( 'content', 'seo' ),
				'abilities' => array( 'abilities' ),
			),
			$seen,
			'The filter learns each main group and its tool groups.'
		);
		$form = $x->query( '//div[@data-main="abilities"]/div[contains(@class, "ab-group__body")]//form[@id="addon-form"]' );
		self::assertSame( 1, $form->length, 'Inside the group, below its tools.' );
		self::assertSame( 0, $x->query( '//form//form' )->length, 'No form inside another.' );
		self::assertSame( 0, $x->query( '//form[@id="ab-caps"]//input[@type="checkbox"]' )->length, 'The fine-tuning form holds hidden fields only; its switches join with form="ab-caps".' );
		self::assertSame( 0, $x->query( '//form[@id="addon-form"]//input[@name="enabled_tools[]"]' )->length );
		self::assertSame( 0, $x->query( '//div[@data-main="content"]//form[@id="addon-form"]' )->length, 'Not in the other groups.' );
	}

	public function testANewSiteHasEveryToolSwitchedOn(): void {
		$r = $this->registry();
		$x = $this->xpath( $this->call( 'capabilities_card_html', $r->groups(), $r->all() ) );

		foreach ( $x->query( '//input[@name="enabled_tools[]"]' ) as $in ) {
			self::assertTrue( $in->hasAttribute( 'checked' ), $in->getAttribute( 'value' ) . ' is on, the Mighty one included.' );
		}
		self::assertSame( '3', trim( $x->query( '//*[contains(@class, "ab-on-count")]' )->item( 0 )->textContent ) );
	}

	public function testEveryFormOnThePageIsSignedForItsHandler(): void {
		$x = $this->xpath( $this->call( 'render_connections_card', array() ) . $this->call( 'render_audit' ) );

		$forms = array();
		foreach ( $x->query( '//form' ) as $form ) {
			$action = $x->query( './/input[@name="action"]', $form )->item( 0 );
			$nonce  = $x->query( './/input[@name="_wpnonce"]', $form )->item( 0 );
			self::assertNotNull( $action );
			self::assertNotNull( $nonce );
			$forms[ $action->getAttribute( 'value' ) ] = $nonce->getAttribute( 'value' );
		}
		self::assertSame(
			array(
				'ab_mcp_connector_auth'    => 'nonce-ab_mcp_connector_auth',
				'ab_mcp_oauth_settings'    => 'nonce-ab_mcp_oauth_settings',
				'ab_mcp_protocol_settings' => 'nonce-ab_mcp_protocol_settings',
				'ab_mcp_audit_clear'       => 'nonce-ab_mcp_audit_clear',
			),
			$forms
		);
		self::assertSame( 1, $x->query( '//form//input[@type="checkbox"][@name="connector_url_auth_enabled"]' )->length );
		self::assertSame( 1, $x->query( '//form//input[@type="checkbox"][@name="oauth_enabled"]' )->length );
		self::assertSame( 1, $x->query( '//form//input[@type="checkbox"][@name="modern_protocol"]' )->length );
	}

	public function testTheProtocolSwitchShowsTheStoredSetting(): void {
		$checked = function (): bool {
			$x   = $this->xpath( $this->call( 'render_connections_card', array() ) );
			$box = $x->query( '//input[@name="modern_protocol"]' )->item( 0 );
			self::assertNotNull( $box );
			return $box->hasAttribute( 'checked' );
		};

		self::assertTrue( $checked(), 'On by default.' );
		\AB_MCP_Settings::set( 'modern_protocol', false );
		self::assertFalse( $checked() );
	}

	public function testTheProtocolSwitchPromisesOnlyWhatTheSiteKeeps(): void {
		\AB_MCP_Settings::set( 'modern_protocol', false );
		$x    = $this->xpath( $this->call( 'render_connections_card', array() ) );
		$desc = $x->query( '//input[@name="modern_protocol"]/following-sibling::span//span[@class="description"]' )->item( 0 );
		self::assertNotNull( $desc );

		// Switched off, initialize still carries protocol_versions in the site
		// _meta (the hub reads it), a field releases before 4.4 did not send.
		// So the text may promise the older revisions, not the old answer.
		$m    = new ReflectionMethod( AB_MCP_REST_Controller::class, 'initialize' );
		$init = $m->invoke( new AB_MCP_REST_Controller( new AB_MCP_Tool_Registry() ), array( 'protocolVersion' => '2025-06-18' ) );
		self::assertSame( array( '2024-11-05', '2025-03-26', '2025-06-18' ), $init['_meta'][ AB_MCP_REST_Controller::SITE_META_KEY ]['protocol_versions'] );
		self::assertStringNotContainsString( 'exactly as before', $desc->textContent );
		self::assertStringContainsString( 'older revisions only', $desc->textContent );
	}

	/**
	 * Post a settings form to its handler as an administrator. The form's
	 * own nonce is added unless the post brings one.
	 *
	 * @param array<string,string> $post
	 * @return string How the request ended: 'redirect', 'die' or 'no exit'.
	 */
	private function submit( string $handler, string $action, array $post ): string {
		$GLOBALS['ab_test_can'] = static fn( string $cap ): bool => 'manage_options' === $cap;
		$_POST                  = $post + array( '_wpnonce' => 'nonce-' . $action );
		$_REQUEST               = $_POST;
		try {
			( new AB_MCP_Admin() )->$handler();
		} catch ( \AbTestExit $exit ) {
			return $exit->kind;
		} finally {
			$_POST    = array();
			$_REQUEST = array();
		}
		return 'no exit';
	}

	public function testSavingTheProtocolSwitchStoresIt(): void {
		$save = fn( array $post ): string => $this->submit( 'handle_protocol_settings', 'ab_mcp_protocol_settings', $post );

		self::assertSame( 'redirect', $save( array() ) );
		self::assertFalse( \AB_MCP_Settings::get( 'modern_protocol' ), 'An unticked box is not posted: off.' );

		self::assertSame( 'redirect', $save( array( 'modern_protocol' => '1' ) ) );
		self::assertTrue( \AB_MCP_Settings::get( 'modern_protocol' ) );
	}

	/**
	 * @return array<string,array{0:array<string,string>}>
	 */
	public static function foreignNonces(): array {
		return array(
			'no nonce'                => array( array( '_wpnonce' => '' ) ),
			'the nonce of another form' => array( array( '_wpnonce' => 'nonce-ab_mcp_oauth_settings' ) ),
		);
	}

	/**
	 * @param array<string,string> $post
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'foreignNonces' )]
	public function testTheProtocolSwitchIsNotSavedWithoutItsNonce( array $post ): void {
		self::assertSame( 'die', $this->submit( 'handle_protocol_settings', 'ab_mcp_protocol_settings', $post ) );
		self::assertTrue( \AB_MCP_Settings::get( 'modern_protocol' ), 'Unchanged: a page elsewhere cannot switch the revision off for the admin.' );
	}

	public function testOnlyAnAdministratorCanSaveTheProtocolSwitch(): void {
		$GLOBALS['ab_test_can'] = static fn(): bool => false;
		$_POST                  = array();

		try {
			( new AB_MCP_Admin() )->handle_protocol_settings();
			self::fail( 'The guard did not stop the request.' );
		} catch ( \AbTestExit $exit ) {
			self::assertSame( 'die', $exit->kind );
		}
		self::assertTrue( \AB_MCP_Settings::get( 'modern_protocol' ), 'Unchanged.' );
	}

	public function testTheMetadataDocumentSwitchShowsTheStoredSetting(): void {
		$checked = function (): bool {
			$x   = $this->xpath( $this->call( 'render_connections_card', array() ) );
			$box = $x->query( '//form[.//input[@name="action"][@value="ab_mcp_oauth_settings"]]//input[@name="oauth_cimd"]' )->item( 0 );
			self::assertNotNull( $box, 'In the OAuth form, next to the OAuth switch.' );
			return $box->hasAttribute( 'checked' );
		};

		self::assertTrue( $checked(), 'On by default.' );
		\AB_MCP_Settings::set( 'oauth_cimd', false );
		self::assertFalse( $checked() );
	}

	public function testSavingTheOAuthFormStoresBothSwitches(): void {
		$save = fn( array $post ): string => $this->submit( 'handle_oauth_settings', 'ab_mcp_oauth_settings', $post );

		self::assertSame( 'redirect', $save( array( 'oauth_enabled' => '1' ) ) );
		self::assertTrue( \AB_MCP_Settings::get( 'oauth_enabled' ) );
		self::assertFalse( \AB_MCP_Settings::get( 'oauth_cimd' ), 'An unticked box is not posted: off.' );
		self::assertFalse( \AB_MCP_OAuth::cimd_enabled() );

		self::assertSame( 'redirect', $save( array( 'oauth_enabled' => '1', 'oauth_cimd' => '1' ) ) );
		self::assertTrue( \AB_MCP_Settings::get( 'oauth_cimd' ) );
		self::assertTrue( \AB_MCP_OAuth::cimd_enabled() );
	}

	public function testTheOAuthFormIsNotSavedWithoutItsNonce(): void {
		self::assertSame( 'die', $this->submit( 'handle_oauth_settings', 'ab_mcp_oauth_settings', array( '_wpnonce' => 'nonce-ab_mcp_protocol_settings' ) ) );
		self::assertTrue( \AB_MCP_Settings::get( 'oauth_cimd' ), 'Unchanged.' );
	}

	public function testTheMetadataDocumentSwitchSaysWhenAFilterDecides(): void {
		$card = fn(): string => $this->call( 'render_connections_card', array() );

		self::assertStringNotContainsString( 'ab_mcp_oauth_cimd filter', $card() );

		add_filter( 'ab_mcp_oauth_cimd', '__return_false' );
		self::assertStringContainsString( 'right now apps with a metadata document are not accepted', $card() );
	}

	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	#[\PHPUnit\Framework\Attributes\PreserveGlobalState( false )]
	public function testTheMetadataDocumentSwitchSaysWhenTheSiteBlocksRequests(): void {
		self::assertStringNotContainsString( 'WP_HTTP_BLOCK_EXTERNAL', $this->call( 'render_connections_card', array() ) );

		define( 'WP_HTTP_BLOCK_EXTERNAL', true );
		self::assertStringContainsString( 'WP_ACCESSIBLE_HOSTS', $this->call( 'render_connections_card', array() ), 'The note names the way to use it anyway.' );
	}

	public function testTheProtocolSwitchSaysWhenAFilterDecides(): void {
		$note = fn(): string => $this->call( 'render_connections_card', array() );

		self::assertStringNotContainsString( 'ab_mcp_modern_protocol filter', $note(), 'Without a filter the switch alone decides.' );

		add_filter( 'ab_mcp_modern_protocol', '__return_false' );
		self::assertStringContainsString( 'right now the revision is not answered', $note(), 'The admin learns why saving the switch changes nothing.' );
	}

	public function testTheSideColumnOrdersProBoxAddOnsGuides(): void {
		add_action(
			'ab_mcp_admin_side_boxes',
			static function () {
				echo '<div id="addon-side"></div>';
			}
		);
		$html = $this->call( 'sidebar_html' );

		$pro    = strpos( $html, 'class="postbox ab-pro"' );
		$addon  = strpos( $html, 'id="addon-side"' );
		$guides = strpos( $html, 'ab-guides' );
		self::assertIsInt( $pro );
		self::assertIsInt( $addon );
		self::assertIsInt( $guides );
		self::assertTrue( $pro < $addon && $addon < $guides, 'Pro box, add-on boxes, guides.' );
	}

	public function testASiteWithProSeesTheAddOnBoxesButNoProBox(): void {
		add_filter(
			'ab_mcp_site_edition',
			static function () {
				return 'pro';
			}
		);
		add_action(
			'ab_mcp_admin_side_boxes',
			static function () {
				echo '<div id="addon-side"></div>';
			}
		);
		$html = $this->call( 'sidebar_html' );

		self::assertStringNotContainsString( 'ab-pro', $html );
		self::assertStringContainsString( 'id="addon-side"', $html );
		self::assertStringContainsString( 'ab-guides', $html );
	}

	/** The rule of one selector in admin.css, as written there. */
	private static function css_rule( string $selector ): string {
		$css = (string) file_get_contents( dirname( __DIR__ ) . '/assets/admin.css' );
		self::assertSame( 1, preg_match( '/^' . preg_quote( $selector, '/' ) . ' \{([^}]*)\}/m', $css, $m ), $selector );
		return $m[1];
	}

	public function testNothingHiddenMakesThePageWiderThanAPhone(): void {
		// A hidden description that kept its place (visibility: hidden) made
		// the page 521 px wide at 390 px; one not drawn takes no place.
		$tip = self::css_rule( '.ab-mcp .ab-tip' );
		self::assertStringContainsString( 'display: none;', $tip );
		self::assertStringNotContainsString( 'visibility', $tip );
		self::assertStringContainsString( 'max-width: calc(100vw - 32px);', $tip );
		self::assertStringContainsString( 'display: block;', self::css_rule( '.ab-mcp .ab-info:hover .ab-tip,' . "\n" . '.ab-mcp .ab-info:focus .ab-tip' ) );
		// The hidden heading of the connections' last column stays inside its scroller.
		self::assertStringContainsString( 'position: relative;', self::css_rule( '.ab-mcp .ab-table-wrap' ) );
	}

	public function testTheInfoStaysACircleWithATargetOf24Pixels(): void {
		self::assertStringContainsString( 'flex: none;', self::css_rule( '.ab-mcp .ab-info' ) );
		self::assertStringContainsString( 'inset: -4px;', self::css_rule( '.ab-mcp .ab-info::before' ), '18 px drawn, 24 px to hit.' );
	}

	public function testAToolStaysOnOneLineOnADesktop(): void {
		// The fine-tuning of a 1440 px window is about 800 px wide.
		$css = (string) file_get_contents( dirname( __DIR__ ) . '/assets/admin.css' );
		self::assertStringContainsString( '@container abfine (max-width: 600px) {', $css );
		self::assertSame( 0, preg_match( '/@container abfine \(max-width: (\d+)px\)/', str_replace( array( '(max-width: 600px)', '(max-width: 480px)' ), '', $css ) ), 'No other width breaks the line.' );
		self::assertStringContainsString( 'grid-template-areas: "name badges chev";', self::css_rule( '.ab-mcp .ab-sub__toggle' ), 'A tool group: name, badges and chevron on one line.' );
		self::assertStringContainsString( 'grid-template-areas: "name chev" "badges badges";', $css, 'Narrow: the badges below the name, which is never squeezed.' );
		self::assertStringContainsString( 'padding: 6px 12px;', self::css_rule( '.ab-mcp .ab-sub__head' ) );
	}
}

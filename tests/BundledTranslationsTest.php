<?php
/**
 * The German translations bundled in languages/.
 *
 * WordPress has no language pack of this plugin yet, and a German site must
 * read every text of the plugin a person sees in German from the release
 * that brings it: the settings page, the consent screen, the notices, the
 * refusals and errors an assistant passes on, and the description in the
 * plugin list. So the plugin ships its texts for de_DE, de_DE_formal,
 * de_AT, de_CH and de_CH_informal, and loads them behind a language pack
 * from translate.wordpress.org, which wins for every string it has. Tool
 * and parameter descriptions and the server instructions are written for
 * the assistant; they are not in gettext calls and stay English.
 *
 * What must hold:
 *
 * - Every locale has a .po and a compiled .mo with the same entries, and
 *   every msgid is a string the plugin's code really translates — a text
 *   changed in the code without its translation fails here.
 * - Every text the shipped code passes to a gettext function, and the
 *   description in the plugin header, is translated in every locale, with
 *   the same placeholders and the same HTML; a text added to the code
 *   without its translation fails here, and a counter-check shows that the
 *   check finds a missing or broken entry.
 * - The notice, the box, both refusals with write access off (a writing
 *   tool, a Mighty reader) and the way they name, the other refusals of the
 *   policy gate, the answer for a tool of Pro, the notice after the update,
 *   the consent sentences, the main switch with its states and boxes and the
 *   notices after switching are translated everywhere, with their
 *   placeholders.
 * - Every text of the fine-tuning (its main groups and what is in them, the
 *   names of the tool groups of the core and of Pro, the badges Reads and
 *   Writes, the notes, the lead, the controls, and the texts the core keeps
 *   for add-ons) is translated too: a text added there without its
 *   translation fails here.
 * - A translation is not the English text itself, except for the few
 *   names and words German uses as they are (SAME_AS_ENGLISH).
 * - de_AT reads like de_DE. Swiss German writes ss, never ß, and «» for
 *   quotes; de_DE_formal and de_CH address the reader as Sie, the others
 *   as du, and the check for that also finds a capitalised Du or Dein.
 * - The language pack is asked for before the bundled file is added.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use AB_MCP_Site_Mode;

final class BundledTranslationsTest extends TestCase {

	const LOCALES = array( 'de_DE', 'de_DE_formal', 'de_AT', 'de_CH', 'de_CH_informal' );

	/** The texts that must read in German wherever a German locale is set. */
	const REQUIRED = array(
		'With write access, your AI assistants work directly on your live website. Changes take effect immediately: they can create, change and also delete content, files, settings and code, and not everything can be undone. You switch this on at your own risk. A current backup keeps you on the safe side.',
		'Understood: changes take effect immediately, I switch on write access at my own risk and I have a current backup.',
		'Write access is off on this site, so the tool "%s" did not run: it changes the site, and with write access off AI assistants only read.',
		'Write access is off on this site, so the tool "%s" did not run: it is one of the reading tools that run only with write access on.',
		"To allow it, an administrator can switch on write access at the top of Settings → AlphaBridge MCP and confirm the notice there; that is at the site owner's own risk, and a current backup is advised.",
		'Pass this on to the person you are working for in a friendly way, with the steps and the link, and try again once it is done; do not look for a way around it.',
		'Direct link: %s',
		'The account of this connection lacks the capability "%2$s" that the tool "%1$s" needs.',
		'This connection has the access level "%2$s", which does not include the tool "%1$s".',
		'The tool "%s" is switched off in the fine-tuning of AlphaBridge MCP on this site.',
		'The tool "%s" is not on this site: it belongs to AlphaBridge MCP Pro, a separate plugin that is not active here.',
		'The tool "%s" is not on this site: it belongs to the Agency plan of AlphaBridge MCP Pro, a separate plugin that is not active here.',
		'AlphaBridge MCP now only reads on this site.',
		'Whatever access level you choose, the switch for write access on this site decides whether AI assistants may write; it is on right now.',
		'Whatever access level you choose, the switch for write access on this site decides whether AI assistants may write; it is off right now, so the connection only reads until an administrator switches it on at the top of Settings → AlphaBridge MCP.',
		"site mode\x04Write access off (read only)",
		"site mode\x04Write access on (full power)",
		'Write access for AI assistants',
		'Off: AI assistants only read. For anything they should change, you flip the switch.',
		'On: full power. Connected AI assistants read and write on this site.',
		'Applies to every connection: Claude, ChatGPT, Cursor and all others.',
		'Switch on write access?',
		'Switch on',
		'Cancel',
		'Off: read only',
		'On: full power',
		'Write access on since %1$s · confirmed by %2$s · every connection keeps its access level',
		'Write access on since %s · set by code, not confirmed on this page · every connection keeps its access level',
		'%1$s of %2$s tools can run with write access off',
		'Fine-tuning',
		'Write access was already on; nothing was changed. What you switched off under Fine-tuning stays off.',
		'If the account "%s" should be allowed to do this, an administrator can give it a role that allows it under Users.',
		"date and time format\x04Y-m-d H:i",
		"%s connection\0%s connections",
		"%s ability off\0%s abilities off",
	);

	protected function setUp(): void {
		ab_test_reset();
	}

	private static function file( string $locale, string $ext ): string {
		return dirname( __DIR__ ) . '/languages/alphabridge-mcp-' . $locale . '.' . $ext;
	}

	/**
	 * Entries of a compiled .mo file, the way WordPress reads it: key is the
	 * msgid, with "context\x04" in front where there is a context.
	 *
	 * @return array<string,string>
	 */
	private static function mo( string $path ): array {
		$data = (string) file_get_contents( $path );
		$magic = unpack( 'V', substr( $data, 0, 4 ) )[1];
		self::assertSame( 0x950412de, $magic, basename( $path ) . ' is a little-endian .mo file.' );
		$h       = unpack( 'Vrev/Vn/Vorig/Vtrans', substr( $data, 4, 16 ) );
		$entries = array();
		for ( $i = 0; $i < $h['n']; $i++ ) {
			$o = unpack( 'Vlen/Voff', substr( $data, $h['orig'] + 8 * $i, 8 ) );
			$t = unpack( 'Vlen/Voff', substr( $data, $h['trans'] + 8 * $i, 8 ) );
			$entries[ substr( $data, $o['off'], $o['len'] ) ] = substr( $data, $t['off'], $t['len'] );
		}
		unset( $entries[''] ); // The header.
		return $entries;
	}

	/**
	 * Entries of a .po file, keyed like mo(): a text with a plural form as
	 * «singular\0plural» => «form 0\0form 1».
	 *
	 * @return array<string,string>
	 */
	private static function po( string $path ): array {
		$entries = array();
		$unq     = static fn( string $s ): string => stripcslashes( substr( $s, 1, -1 ) );
		foreach ( preg_split( '/\n\s*\n/', (string) file_get_contents( $path ) ) as $block ) {
			$ctx = preg_match( '/^msgctxt (".*")$/m', $block, $m ) ? $unq( $m[1] ) . "\x04" : '';
			if ( ! preg_match( '/^msgid (".*")$/m', $block, $id ) ) {
				continue;
			}
			if ( preg_match( '/^msgid_plural (".*")$/m', $block, $pl ) && preg_match( '/^msgstr\[0\] (".*")$/m', $block, $s0 ) && preg_match( '/^msgstr\[1\] (".*")$/m', $block, $s1 ) ) {
				$entries[ $ctx . $unq( $id[1] ) . "\0" . $unq( $pl[1] ) ] = $unq( $s0[1] ) . "\0" . $unq( $s1[1] );
				continue;
			}
			if ( ! preg_match( '/^msgstr (".*")$/m', $block, $str ) ) {
				continue;
			}
			$key = $ctx . $unq( $id[1] );
			if ( '' !== $key ) {
				$entries[ $key ] = $unq( $str[1] );
			}
		}
		return $entries;
	}

	/**
	 * Every string literal in the shipped PHP, as PHP reads it.
	 *
	 * @return array<string,true>
	 */
	private static function literals(): array {
		$all = array();
		foreach ( self::shipped_files() as $file ) {
			foreach ( token_get_all( (string) file_get_contents( $file ) ) as $t ) {
				if ( is_array( $t ) && T_CONSTANT_ENCAPSED_STRING === $t[0] ) {
					$all[ eval( 'return ' . $t[1] . ';' ) ] = true; // phpcs:ignore Squiz.PHP.Eval -- a literal from our own source.
				}
			}
		}
		// WordPress translates the plugin header's description with the
		// plugin's text domain for the plugin list.
		$all[ self::description() ] = true;
		return $all;
	}

	/** @return array<int,string> The PHP files that ship. */
	private static function shipped_files(): array {
		$root = dirname( __DIR__ );
		return array_merge( glob( $root . '/*.php' ), glob( $root . '/includes/*.php' ), glob( $root . '/includes/*/*.php' ), glob( $root . '/includes/*/*/*.php' ) );
	}

	/** The plugin header's Description, as WordPress reads it. */
	private static function description(): string {
		$head = (string) file_get_contents( dirname( __DIR__ ) . '/alphabridge-mcp.php', false, null, 0, 8192 );
		self::assertSame( 1, preg_match( '/^[ \t\/*#@]*Description:(.*)$/mi', $head, $m ) );
		return trim( $m[1] );
	}

	/**
	 * The gettext functions WordPress has, with the positions of their
	 * arguments: the texts, the context, the domain.
	 */
	const GETTEXT = array(
		'__'           => array( 'text' => array( 0 ), 'ctx' => null, 'domain' => 1 ),
		'_e'           => array( 'text' => array( 0 ), 'ctx' => null, 'domain' => 1 ),
		'esc_html__'   => array( 'text' => array( 0 ), 'ctx' => null, 'domain' => 1 ),
		'esc_html_e'   => array( 'text' => array( 0 ), 'ctx' => null, 'domain' => 1 ),
		'esc_attr__'   => array( 'text' => array( 0 ), 'ctx' => null, 'domain' => 1 ),
		'esc_attr_e'   => array( 'text' => array( 0 ), 'ctx' => null, 'domain' => 1 ),
		'_x'           => array( 'text' => array( 0 ), 'ctx' => 1, 'domain' => 2 ),
		'_ex'          => array( 'text' => array( 0 ), 'ctx' => 1, 'domain' => 2 ),
		'esc_html_x'   => array( 'text' => array( 0 ), 'ctx' => 1, 'domain' => 2 ),
		'esc_attr_x'   => array( 'text' => array( 0 ), 'ctx' => 1, 'domain' => 2 ),
		'_n'           => array( 'text' => array( 0, 1 ), 'ctx' => null, 'domain' => 3 ),
		'_nx'          => array( 'text' => array( 0, 1 ), 'ctx' => 3, 'domain' => 4 ),
		'_n_noop'      => array( 'text' => array( 0, 1 ), 'ctx' => null, 'domain' => 2 ),
		'_nx_noop'     => array( 'text' => array( 0, 1 ), 'ctx' => 2, 'domain' => 3 ),
	);

	/**
	 * Every text the shipped code passes to a gettext function, keyed like
	 * mo(), and the plugin header's description. A call whose text, context
	 * or domain is not a plain literal, or whose domain is not
	 * alphabridge-mcp, fails: no tool could find its text to translate it.
	 *
	 * @return array<string,string> Key => where it is (file:line).
	 */
	private static function gettext_texts(): array {
		$keys = array( self::description() => 'alphabridge-mcp.php (Description)' );
		$root = dirname( __DIR__ ) . '/';
		foreach ( self::shipped_files() as $file ) {
			$tokens = array_values(
				array_filter(
					token_get_all( (string) file_get_contents( $file ) ),
					static fn( $t ) => ! ( is_array( $t ) && in_array( $t[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) )
				)
			);
			$n = count( $tokens );
			for ( $i = 0; $i < $n - 1; $i++ ) {
				$t = $tokens[ $i ];
				if ( ! is_array( $t ) || T_STRING !== $t[0] || ! isset( self::GETTEXT[ $t[1] ] ) || '(' !== $tokens[ $i + 1 ] ) {
					continue;
				}
				$before = $i > 0 ? $tokens[ $i - 1 ] : null;
				if ( is_array( $before ) && in_array( $before[0], array( T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NULLSAFE_OBJECT_OPERATOR ), true ) ) {
					continue;
				}
				// The arguments at the call's own depth, each as its tokens.
				$args  = array( array() );
				$depth = 0;
				for ( $j = $i + 1; $j < $n; $j++ ) {
					$tok = $tokens[ $j ];
					if ( in_array( $tok, array( '(', '[', '{' ), true ) || ( is_array( $tok ) && in_array( $tok[0], array( T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ), true ) ) ) {
						++$depth;
						if ( 1 === $depth ) {
							continue;
						}
					} elseif ( in_array( $tok, array( ')', ']', '}' ), true ) ) {
						--$depth;
						if ( 0 === $depth ) {
							break;
						}
					} elseif ( ',' === $tok && 1 === $depth ) {
						$args[] = array();
						continue;
					}
					$args[ count( $args ) - 1 ][] = $tok;
				}
				$where   = substr( $file, strlen( $root ) ) . ':' . $t[2];
				$spec    = self::GETTEXT[ $t[1] ];
				$literal = static function ( int $pos ) use ( $args, $where ): string {
					$arg = $args[ $pos ] ?? array();
					self::assertTrue( 1 === count( $arg ) && is_array( $arg[0] ) && T_CONSTANT_ENCAPSED_STRING === $arg[0][0], 'A plain literal as argument ' . ( $pos + 1 ) . ' at ' . $where );
					return eval( 'return ' . $arg[0][1] . ';' ); // phpcs:ignore Squiz.PHP.Eval -- a literal from our own source.
				};
				self::assertSame( 'alphabridge-mcp', $literal( $spec['domain'] ), 'The text domain at ' . $where );
				$texts = array_map( $literal, $spec['text'] );
				$key   = ( null !== $spec['ctx'] ? $literal( $spec['ctx'] ) . "\x04" : '' ) . implode( "\0", $texts );
				$keys[ $key ] = $where;
			}
		}
		return $keys;
	}

	/**
	 * The texts German writes as English does: names, and words German
	 * uses unchanged. Any other translation that is the English text itself
	 * was left untranslated.
	 */
	const SAME_AS_ENGLISH = array(
		"list separator\x04, ",
		'AlphaBridge Connect',
		"tool group\x04Export",
		'Plus, Pro, Business',
		'SEO',
		"tool group\x04SEO",
		"main group of tools\x04Shop (WooCommerce)",
		"tool group\x04Site Deploy (FTP/SFTP)",
		'Status',
		'Token',
		"main group of tools\x04Undo",
		"tool group\x04Undo",
		'Widgets',
		"tool group\x04Widgets",
		'claude.ai, Desktop, iPhone',
	);

	/**
	 * What is wrong with the translations of $texts in $mo: a text without
	 * a translation, an empty one, one that is the English text itself
	 * (outside SAME_AS_ENGLISH), or one whose placeholders or HTML tags
	 * differ from the text's.
	 *
	 * @param array<string,string> $texts Key => where (gettext_texts()).
	 * @param array<string,string> $mo    Entries (mo()).
	 * @return array<int,string> One line per problem; empty when all is well.
	 */
	private static function gaps( array $texts, array $mo ): array {
		$out = array();
		foreach ( $texts as $key => $where ) {
			if ( ! isset( $mo[ $key ] ) || '' === str_replace( "\0", '', $mo[ $key ] ) ) {
				$out[] = 'untranslated (' . $where . '): ' . $key;
				continue;
			}
			$text  = false !== strpos( $key, "\x04" ) ? substr( $key, strpos( $key, "\x04" ) + 1 ) : $key;
			$forms = explode( "\0", $text );
			foreach ( explode( "\0", $mo[ $key ] ) as $n => $translated ) {
				$source = $forms[ min( $n, count( $forms ) - 1 ) ];
				if ( $translated === $source && ! in_array( $key, self::SAME_AS_ENGLISH, true ) ) {
					$out[] = 'the English text (' . $where . '): ' . $key;
				}
				preg_match_all( '/%(?:\d+\$)?[sd]/', $source, $want );
				preg_match_all( '/%(?:\d+\$)?[sd]/', $translated, $have );
				$want = $want[0];
				$have = $have[0];
				if ( false !== strpos( implode( '', $want ), '$' ) ) {
					// Numbered placeholders may change places.
					sort( $want );
					sort( $have );
				}
				if ( $want !== $have ) {
					$out[] = 'placeholders (' . $where . '): ' . $key;
				}
				preg_match_all( '/<\/?[a-z][^>]*>/', $source, $tags );
				preg_match_all( '/<\/?[a-z][^>]*>/', $translated, $have_tags );
				if ( $tags[0] !== $have_tags[0] ) {
					$out[] = 'HTML (' . $where . '): ' . $key;
				}
			}
		}
		return $out;
	}

	#[DataProvider( 'locales' )]
	public function testEveryTextTheCodeTranslatesIsTranslated( string $locale ): void {
		$texts = self::gettext_texts();
		self::assertSame( array(), self::gaps( $texts, self::mo( self::file( $locale, 'mo' ) ) ), $locale );
	}

	public function testTheCheckFindsEveryGettextCallAndWhatIsMissing(): void {
		$texts = self::gettext_texts();
		self::assertGreaterThan( 600, count( $texts ), 'The scan finds the texts.' );
		// Some of every kind of place a person reads: the settings page, the
		// consent screen, the log, a refusal, a tool error, the plugin list.
		foreach ( array(
			'Connect your AI assistant',
			'%1$s wants to connect to %2$s',
			'Allow',
			"status of a tool call in the log\x04denied",
			"date and time format with seconds\x04Y-m-d H:i:s",
			'Unknown tool: %s. No tool of that name is registered on this site; tools/list names the tools this connection can call.',
			'it is not a JSON object',
			'(unknown)',
			"%d element was read without a measured profile (see its note); its fields may be incomplete.\0%d elements were read without a measured profile (see their note); their fields may be incomplete.",
			self::description(),
		) as $key ) {
			self::assertArrayHasKey( $key, $texts );
		}

		// The counter-check: the same check on entries with one missing, one
		// empty, one with a placeholder lost and one with its HTML changed.
		$mo = self::mo( self::file( 'de_DE', 'mo' ) );
		self::assertSame( array(), self::gaps( $texts, $mo ) );
		$broken = $mo;
		unset( $broken['Allow'] );
		$broken['Copied'] = '';
		$broken['%1$s wants to connect to %2$s'] = '%1$s möchte sich verbinden';
		$broken['On approval you are sent to <b>%s</b>. Only continue if you recognise it as the app you are connecting.'] = 'Nach der Zustimmung wirst du zu %s weitergeleitet.';
		$unknown            = 'Unknown tool: %s. No tool of that name is registered on this site; tools/list names the tools this connection can call.';
		$broken[ $unknown ] = $unknown;
		$gaps = implode( "\n", self::gaps( $texts, $broken ) );
		self::assertStringContainsString( 'untranslated (includes/class-oauth.php:', $gaps );
		self::assertStringContainsString( '): Allow', $gaps );
		self::assertStringContainsString( '): Copied', $gaps );
		self::assertStringContainsString( 'placeholders (includes/class-oauth.php:', $gaps );
		self::assertStringContainsString( 'HTML (includes/class-oauth.php:', $gaps );
		self::assertStringContainsString( 'the English text (includes/class-rest-controller.php:', $gaps );
		self::assertCount( 5, self::gaps( $texts, $broken ) );
		// A name German keeps as it is passes.
		self::assertSame( 'Token', $mo['Token'] );
	}

	#[DataProvider( 'locales' )]
	public function testThePluginListShowsTheDescriptionTranslated( string $locale ): void {
		$mo = self::mo( self::file( $locale, 'mo' ) );
		self::assertArrayHasKey( self::description(), $mo );
		self::assertStringStartsWith( 'Verbindet Claude und andere MCP-Clients', $mo[ self::description() ] );
		// An inactive plugin's description is translated from the folder the
		// header names: WordPress loads the domain from there for the list.
		$head = (string) file_get_contents( dirname( __DIR__ ) . '/alphabridge-mcp.php', false, null, 0, 8192 );
		self::assertMatchesRegularExpression( '/^ \* Domain Path: +\/languages$/m', $head );
	}

	/** @return array<string,array{0:string}> */
	public static function locales(): array {
		$out = array();
		foreach ( self::LOCALES as $l ) {
			$out[ $l ] = array( $l );
		}
		return $out;
	}

	#[DataProvider( 'locales' )]
	public function testTheCompiledFileHoldsWhatTheSourceSays( string $locale ): void {
		self::assertFileExists( self::file( $locale, 'po' ) );
		self::assertFileExists( self::file( $locale, 'mo' ) );

		$po = self::po( self::file( $locale, 'po' ) );
		$mo = self::mo( self::file( $locale, 'mo' ) );
		ksort( $po, SORT_STRING );
		ksort( $mo, SORT_STRING );
		self::assertGreaterThan( 20, count( $po ) );
		self::assertSame( $po, $mo, 'Compile the .po again: msgfmt -o alphabridge-mcp-' . $locale . '.mo alphabridge-mcp-' . $locale . '.po' );
	}

	#[DataProvider( 'locales' )]
	public function testEveryEntryIsATextTheCodeTranslates( string $locale ): void {
		$literals = self::literals();
		foreach ( array_keys( self::mo( self::file( $locale, 'mo' ) ) ) as $key ) {
			$text = false !== strpos( $key, "\x04" ) ? substr( $key, strpos( $key, "\x04" ) + 1 ) : $key;
			foreach ( explode( "\0", $text ) as $form ) {
				self::assertArrayHasKey( $form, $literals, 'Not in the code (changed or removed?): ' . $form );
			}
		}
	}

	#[DataProvider( 'locales' )]
	public function testTheTextsOfTheModeAreTranslatedWithTheirPlaceholders( string $locale ): void {
		$mo = self::mo( self::file( $locale, 'mo' ) );
		foreach ( self::REQUIRED as $key ) {
			self::assertArrayHasKey( $key, $mo, $locale . ': ' . $key );
			self::assertNotSame( '', $mo[ $key ] );
			preg_match_all( '/%(\d\$)?s/', $key, $want );
			preg_match_all( '/%(\d\$)?s/', $mo[ $key ], $have );
			self::assertSame( $want[0], $have[0], $locale . ' keeps the placeholders of: ' . $key );
		}
		self::assertSame( 'Schreibrechte aus (nur lesen)', $mo["site mode\x04Write access off (read only)"] );
		self::assertSame( 'Schreibrechte an (volle Leistung)', $mo["site mode\x04Write access on (full power)"] );
		self::assertSame( 'Schreibrechte für KI-Assistenten', $mo['Write access for AI assistants'] );
		self::assertSame( array_keys( self::mo( self::file( 'de_DE', 'mo' ) ) ), array_keys( $mo ), 'Every locale translates the same texts.' );
	}

	/** The methods of AB_MCP_Admin that draw the fine-tuning. */
	const FINE_METHODS = array( 'main_groups', 'main_description', 'group_label', 'kind_badge', 'tool_note', 'count_badges', 'addon_text', 'fine_row_html', 'fine_sub_html', 'tool_row_html', 'capabilities_card_html', 'group_status', 'mode_card_html', 'mode_notice_html', 'notice' );

	/**
	 * Every text those methods translate, keyed like mo(): «context\x04text»
	 * for _x(), the text for the others.
	 *
	 * @return array<int,string>
	 */
	private static function fine_texts(): array {
		$lines = file( dirname( __DIR__ ) . '/includes/class-admin.php' );
		$keys  = array();
		$lit   = "'((?:[^'\\\\]|\\\\.)*)'";
		$unq   = static fn( string $s ): string => stripcslashes( $s );
		foreach ( self::FINE_METHODS as $method ) {
			$m   = new \ReflectionMethod( \AB_MCP_Admin::class, $method );
			$src = implode( '', array_slice( $lines, $m->getStartLine() - 1, $m->getEndLine() - $m->getStartLine() + 1 ) );
			preg_match_all( '/\b_x\(\s*' . $lit . '\s*,\s*' . $lit . '/', $src, $x, PREG_SET_ORDER );
			foreach ( $x as $hit ) {
				$keys[] = $unq( $hit[2] ) . "\x04" . $unq( $hit[1] );
			}
			preg_match_all( '/\b(?:esc_html__|esc_attr__|__)\(\s*' . $lit . '/', $src, $plain, PREG_SET_ORDER );
			foreach ( $plain as $hit ) {
				$keys[] = $unq( $hit[1] );
			}
		}
		return array_values( array_unique( $keys ) );
	}

	#[DataProvider( 'locales' )]
	public function testEveryTextOfTheFineTuningIsTranslated( string $locale ): void {
		$texts = self::fine_texts();
		self::assertGreaterThan( 50, count( $texts ), 'The scan finds the texts.' );
		self::assertContains( "tool badge\x04Reads", $texts );
		self::assertContains( 'Save changes', $texts );
		self::assertContains( 'With Pro they also read users.', $texts, 'The texts the core keeps for add-ons.' );

		$mo = self::mo( self::file( $locale, 'mo' ) );
		foreach ( $texts as $key ) {
			self::assertArrayHasKey( $key, $mo, $locale . ' translates: ' . str_replace( "\x04", ' | ', $key ) );
			self::assertNotSame( '', $mo[ $key ] );
			preg_match_all( '/%(\d\$)?[sd]/', $key, $want );
			preg_match_all( '/%(\d\$)?[sd]/', $mo[ $key ], $have );
			self::assertSame( $want[0], $have[0], $locale . ' keeps the placeholders of: ' . $key );
		}
		self::assertSame( 'Lesend', $mo["tool badge\x04Reads"] );
		self::assertSame( 'Schreibend', $mo["tool badge\x04Writes"] );
		self::assertSame( '%d lesend', $mo["tool badge with a number\x04%d read"] );
		self::assertSame( '%d schreibend', $mo["tool badge with a number\x04%d write"] );
		self::assertSame( 'erst mit Schreibrechten', $mo["tool note\x04only with write access"] );
		self::assertSame( 'löscht', $mo["tool note\x04deletes"] );
		self::assertSame( 'Fähigkeiten anderer Plugins', $mo["main group of tools\x04Abilities of other plugins"] );
		self::assertSame( 'Inhalte', $mo["main group of tools\x04Content"] );
		self::assertSame( 'Datenbank & Betrieb', $mo["main group of tools\x04Database & operations"] );
		self::assertSame( 'Änderungen speichern', $mo['Save changes'] );
		self::assertSame( 'Feineinstellung', $mo['Fine-tuning'] );
		// The lead names the tools, not «Sie», which in the Sie files reads as
		// the reader; and «bei eingeschalteten Schreibrechten», as «laufen … an»
		// reads as «anlaufen».
		$lead = $mo['Every tool is on until you switch it off here. No AI assistant sees a switched-off tool. Writing tools run only with write access on, and so do the reading ones noted “only with write access”: they read code, files, the database or logs.'];
		self::assertStringContainsString( 'Schreibende Werkzeuge laufen nur bei eingeschalteten Schreibrechten', $lead );
		self::assertStringContainsString( ': Diese Werkzeuge lesen Code, Dateien, die Datenbank oder Protokolle.', $lead );
	}

	#[DataProvider( 'locales' )]
	public function testEveryShortNameOfAToolIsTranslated( string $locale ): void {
		$mo    = self::mo( self::file( $locale, 'mo' ) );
		$names = \AB_MCP_Tool_Labels::all();
		self::assertCount( 143, $names, 'Every tool of the core, of Pro and of its Agency plan.' );
		foreach ( $names as $tool => $label ) {
			self::assertArrayHasKey( "tool label\x04" . $label, $mo, $locale . ': ' . $tool );
			self::assertNotSame( '', $mo[ "tool label\x04" . $label ] );
		}
		$ss = 0 === strpos( $locale, 'de_CH' );
		// The names of the approved draft of the fine-tuning.
		self::assertSame( 'Snippets auflisten', $mo["tool label\x04List snippets"] );
		self::assertSame( 'Ein Snippet lesen', $mo["tool label\x04Read a snippet"] );
		self::assertSame( 'Snippet anlegen oder ändern', $mo["tool label\x04Create or change a snippet"] );
		self::assertSame( 'Snippet ein- oder ausschalten', $mo["tool label\x04Switch a snippet on or off"] );
		self::assertSame( 'Snippet löschen', $mo["tool label\x04Delete a snippet"] );
		self::assertSame( 'Fähigkeiten auflisten', $mo["tool label\x04List abilities"] );
		self::assertSame( 'Eine Fähigkeit ausführen', $mo["tool label\x04Run an ability"] );
		self::assertSame( $ss ? 'Grossen Upload beginnen' : 'Großen Upload beginnen', $mo["tool label\x04Start a large upload"] );
	}

	#[DataProvider( 'locales' )]
	public function testTheRefusalsForTheRoleReadInOneLanguage( string $locale ): void {
		// A refusal of a tool for the account's role is the tool's sentences,
		// the steps, the link and the handover: on a German site all German.
		$mo      = self::mo( self::file( $locale, 'mo' ) );
		$sources = '';
		foreach ( array_merge( glob( dirname( __DIR__ ) . '/includes/*.php' ), glob( dirname( __DIR__ ) . '/includes/*/*.php' ) ) as $file ) {
			$sources .= (string) file_get_contents( $file );
		}
		preg_match_all( "/role_refusal\\(\\s*__\\(\\s*'((?:[^'\\\\]|\\\\.)*)'/", $sources, $m );
		preg_match_all( "/(?:\\?|:) __\\( '(You cannot publish[^']*)'/", $sources, $publish );
		$texts = array_unique( array_merge( array_map( 'stripcslashes', $m[1] ), $publish[1] ) );
		self::assertGreaterThan( 20, count( $texts ), 'The scan finds them.' );
		foreach ( $texts as $text ) {
			self::assertArrayHasKey( $text, $mo, $locale . ': ' . $text );
		}
		self::assertArrayHasKey( \AB_MCP_Guidance::handover(), $mo );
		self::assertArrayHasKey( 'Direct link: %s', $mo );
	}

	public function testTheTextsInTheCodeAreTheOnesTranslated(): void {
		$mo = self::mo( self::file( 'de_DE', 'mo' ) );
		foreach ( array( AB_MCP_Site_Mode::notice_text(), AB_MCP_Site_Mode::checkbox_text(), AB_MCP_Site_Mode::consent_text(), AB_MCP_Site_Mode::way_text(), \AB_MCP_Guidance::handover() ) as $text ) {
			self::assertArrayHasKey( $text, $mo );
		}
		self::assertSame( 'Mit Schreibrechten arbeiten deine KI-Assistenten direkt auf deiner Live-Website. Änderungen wirken sofort: Sie können Inhalte, Dateien, Einstellungen und Code erstellen, ändern und auch löschen, und nicht alles lässt sich rückgängig machen. Du schaltest das auf eigenes Risiko ein. Ein aktuelles Backup gibt dir Sicherheit.', $mo[ AB_MCP_Site_Mode::notice_text() ], 'The wording Thomas approved.' );
		self::assertSame( 'Verstanden: Änderungen wirken sofort, ich schalte Schreiben auf eigenes Risiko ein und habe ein aktuelles Backup.', $mo[ AB_MCP_Site_Mode::checkbox_text() ] );
		self::assertSame( 'Mit Schreibrechten arbeiten Ihre KI-Assistenten direkt auf Ihrer Live-Website. Änderungen wirken sofort: Sie können Inhalte, Dateien, Einstellungen und Code erstellen, ändern und auch löschen, und nicht alles lässt sich rückgängig machen. Sie schalten das auf eigenes Risiko ein. Ein aktuelles Backup gibt Ihnen Sicherheit.', self::mo( self::file( 'de_DE_formal', 'mo' ) )[ AB_MCP_Site_Mode::notice_text() ] );
		self::assertSame( 'Mit Schreibrechten arbeiten Ihre KI-Assistenten direkt auf Ihrer Live-Website. Änderungen wirken sofort: Sie können Inhalte, Dateien, Einstellungen und Code erstellen, ändern und auch löschen, und nicht alles lässt sich rückgängig machen. Sie schalten das auf eigenes Risiko ein. Ein aktuelles Backup gibt Ihnen Sicherheit.', self::mo( self::file( 'de_CH', 'mo' ) )[ AB_MCP_Site_Mode::notice_text() ], 'de_CH addresses the reader as Sie.' );
	}

	/** @return array<string,array{0:string,1:string}> */
	public static function swiss(): array {
		return array(
			'de_CH follows de_DE_formal'   => array( 'de_CH', 'de_DE_formal' ),
			'de_CH_informal follows de_DE' => array( 'de_CH_informal', 'de_DE' ),
		);
	}

	#[DataProvider( 'swiss' )]
	public function testSwissGermanWritesSsAndGuillemets( string $swiss, string $german ): void {
		$ch = self::mo( self::file( $swiss, 'mo' ) );
		$de = self::mo( self::file( $german, 'mo' ) );
		foreach ( $ch as $key => $text ) {
			self::assertStringNotContainsString( 'ß', $text, $swiss );
			self::assertStringNotContainsString( '„', $text, $swiss );
			self::assertSame( strtr( $de[ $key ], array( 'ß' => 'ss', '„' => '«', '“' => '»' ) ), $text, $swiss . ' says what ' . $german . ' says: ' . $key );
		}
		self::assertStringContainsString( '«%s»', $ch['Write access is off on this site, so the tool "%s" did not run: it changes the site, and with write access off AI assistants only read.'] );
		self::assertStringContainsString( 'Grosse Uploads in Teilen', $ch["tool group\x04Large uploads in parts"] );
	}

	/** @return array<string,array{0:string,1:bool}> */
	public static function address(): array {
		return array(
			'de_DE'          => array( 'de_DE', false ),
			'de_AT'          => array( 'de_AT', false ),
			'de_CH_informal' => array( 'de_CH_informal', false ),
			'de_DE_formal'   => array( 'de_DE_formal', true ),
			'de_CH'          => array( 'de_CH', true ),
		);
	}

	/**
	 * Where «Sie» after a colon starts a sentence about the assistants
	 * («they»), not the reader: in the approved wording of the notice.
	 */
	const THEY = array( ': Sie können Inhalte' );

	/** A du form, also at the start of a sentence («Du kannst», «Deine»). */
	const DU = '/\b(du|dein\w*|dich|dir)\b/iu';

	/** A Sie form; lowercase «sie» and «ihr» are «they» and «her». */
	const SIE = '/\b(Sie|Ihr\w*|Ihnen)\b/u';

	#[DataProvider( 'address' )]
	public function testFormalLocalesSaySieTheOthersDu( string $locale, bool $formal ): void {
		$notice = self::mo( self::file( $locale, 'mo' ) )[ AB_MCP_Site_Mode::notice_text() ];

		self::assertStringContainsString( $formal ? 'Sie schalten das auf eigenes Risiko ein. Ein aktuelles Backup gibt Ihnen Sicherheit.' : 'Du schaltest das auf eigenes Risiko ein. Ein aktuelles Backup gibt dir Sicherheit.', $notice );
		foreach ( self::mo( self::file( $locale, 'mo' ) ) as $text ) {
			$text = str_replace( self::THEY, '', $text );
			self::assertDoesNotMatchRegularExpression( $formal ? self::DU : self::SIE, $text, $locale );
		}
	}

	public function testTheAddressCheckFindsEveryForm(): void {
		foreach ( array( 'Kopiert. Du kannst es einfügen.', 'Deine Verbindungen', 'Dein Undo-Zeitraum', 'Frag dich, ob', 'gibt dir Sicherheit' ) as $du ) {
			self::assertMatchesRegularExpression( self::DU, $du );
		}
		foreach ( array( 'Ihre Verbindungen', 'Geben Sie das weiter', 'gibt Ihnen Sicherheit' ) as $sie ) {
			self::assertMatchesRegularExpression( self::SIE, $sie );
		}
		// What is no address: «they» and the names in code.
		foreach ( array( 'Diese Werkzeuge lesen Code', 'WP_LANG_DIR', 'Dienst', 'Dirigent' ) as $none ) {
			self::assertDoesNotMatchRegularExpression( self::DU, $none );
		}
		self::assertDoesNotMatchRegularExpression( self::SIE, 'die Werkzeuge, die sie lesen, und ihre Felder' );
	}

	public function testAustrianGermanReadsLikeGermanGerman(): void {
		$at = self::mo( self::file( 'de_AT', 'mo' ) );
		$de = self::mo( self::file( 'de_DE', 'mo' ) );
		ksort( $at, SORT_STRING );
		ksort( $de, SORT_STRING );
		self::assertSame( $de, $at );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testTheLanguagePackIsAskedForBeforeTheBundledFileIsAdded(): void {
		require_once dirname( __DIR__ ) . '/includes/class-plugin.php';
		$GLOBALS['ab_test_locale'] = 'de_CH';

		self::assertTrue( \AB_MCP_Plugin::load_bundled_translations() );
		self::assertSame(
			array(
				array( 'pack', 'alphabridge-mcp' ),
				array( 'file', 'alphabridge-mcp', AB_MCP_DIR . 'languages/alphabridge-mcp-de_CH.mo', 'de_CH' ),
			),
			$GLOBALS['ab_test_i18n']
		);

		// switch_to_locale(): change_locale passes the new locale.
		$GLOBALS['ab_test_i18n'] = array();
		self::assertTrue( \AB_MCP_Plugin::load_bundled_translations( 'de_AT' ) );
		self::assertSame( AB_MCP_DIR . 'languages/alphabridge-mcp-de_AT.mo', $GLOBALS['ab_test_i18n'][1][2] );

		// Nothing bundled for this locale: nothing is loaded, the pack decides.
		$GLOBALS['ab_test_i18n']   = array();
		$GLOBALS['ab_test_locale'] = 'fr_FR';
		self::assertFalse( \AB_MCP_Plugin::load_bundled_translations() );
		self::assertSame( array(), $GLOBALS['ab_test_i18n'] );
	}

	public function testThePluginLoadsThemAndAgainAfterASwitchOfLocale(): void {
		$source = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-plugin.php' );

		self::assertStringContainsString( 'self::load_bundled_translations();', $source );
		self::assertStringContainsString( "add_action( 'change_locale', array( __CLASS__, 'load_bundled_translations' ) );", $source );
	}
}

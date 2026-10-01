<?php
/**
 * The shortcode reader: a tree with byte offsets for any tag, read the way
 * WordPress reads shortcodes, and never stopped by broken text.
 *
 * The inputs are written in the formats the vendor sources show
 * (docs/recherche/2026-09-30-page-builder-messung/quellen/: wpbakery.md,
 * divi-4.md, flatsome.md, enfold.md); they are constructed, not measured.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use AB_MCP_Shortcode_Reader;
use PHPUnit\Framework\TestCase;

final class ShortcodeReaderTest extends TestCase {

	/** Nodes of a text. */
	private function nodes( string $text ): array {
		return AB_MCP_Shortcode_Reader::parse( $text )['nodes'];
	}

	/** Bytes of a range. */
	private function at( string $text, array $range ): string {
		return AB_MCP_Shortcode_Reader::slice( $text, $range[0], $range[1] );
	}

	public function testNestedTreeWithOffsets(): void {
		$text = '[vc_row][vc_column width="1/2"][vc_column_text]<p>Hallo &amp; Grüsse</p>[/vc_column_text][vc_btn title="Jetzt" link="url:https%3A%2F%2Fexample.com|title:x" /][/vc_column][/vc_row]';
		$n    = $this->nodes( $text );
		self::assertCount( 1, $n );
		$row = $n[0];
		self::assertSame( 'vc_row', $row['tag'] );
		self::assertSame( 's0', $row['id'] );
		self::assertSame( 0, $row['start'] );
		self::assertSame( strlen( $text ), $row['end'] );
		self::assertTrue( $row['closed'] );

		$col = $row['children'][0];
		self::assertSame( 's0.0', $col['id'] );
		self::assertSame( '[vc_column width="1/2"]', AB_MCP_Shortcode_Reader::slice( $text, $col['start'], $col['open_end'] ) );
		self::assertSame( array( 'width' => '1/2' ), $col['attrs'] );
		self::assertSame( '1/2', $this->at( $text, $col['attr_spans']['width'] ) );
		self::assertSame( '"', $col['attr_quotes']['width'] );

		$txt = $col['children'][0];
		self::assertSame( 'vc_column_text', $txt['tag'] );
		self::assertSame( '<p>Hallo &amp; Grüsse</p>', $this->at( $text, $txt['content'] ), 'Byte offsets: the umlaut counts two.' );
		self::assertSame( '#text', $txt['children'][0]['tag'] );
		self::assertSame( 's0.0.0.0', $txt['children'][0]['id'] );

		$btn = $col['children'][1];
		self::assertSame( 'vc_btn', $btn['tag'] );
		self::assertTrue( $btn['self_closing'] );
		self::assertFalse( $btn['closed'] );
		self::assertNull( $btn['content'] );
		self::assertSame( 'url:https%3A%2F%2Fexample.com|title:x', $btn['attrs']['link'], 'Values stay as the builder encoded them.' );
		self::assertSame( 'url:https%3A%2F%2Fexample.com|title:x', $this->at( $text, $btn['attr_spans']['link'] ) );
		self::assertSame( '[/vc_column_text]', substr( $text, $txt['content'][1], strlen( '[/vc_column_text]' ) ) );
	}

	public function testDiviAndFlatsomeStructures(): void {
		$divi = '[et_pb_section fb_built="1"][et_pb_row][et_pb_column type="4_4"][et_pb_text _builder_version="4.27.0"]<h2>Über uns</h2>[/et_pb_text][et_pb_button button_text="Preise ansehen" button_url="https://example.ch/preise/" url_new_window="on"][/et_pb_button][/et_pb_column][/et_pb_row][/et_pb_section]';
		$col  = $this->nodes( $divi )[0]['children'][0]['children'][0];
		self::assertSame( 'et_pb_column', $col['tag'] );
		self::assertSame( array( 'et_pb_text', 'et_pb_button' ), array_column( $col['children'], 'tag' ) );
		self::assertSame( 'Preise ansehen', $col['children'][1]['attrs']['button_text'] );
		self::assertSame( '<h2>Über uns</h2>', $this->at( $divi, $col['children'][0]['content'] ) );
		self::assertSame( 's0.0.0.0', $col['children'][0]['id'] );

		$flat = '[section bg="12"][row][col span="6"]<p>Text</p>[ux_image id="12"][button text="Mehr" link="/kontakt"][/col][/row][/section]';
		$c    = $this->nodes( $flat )[0]['children'][0]['children'][0];
		self::assertSame( array( '#text', 'ux_image', 'button' ), array_column( $c['children'], 'tag' ) );
		self::assertFalse( $c['children'][1]['closed'], 'Standing alone: no [/ux_image] follows.' );
		self::assertSame( '/kontakt', $c['children'][2]['attrs']['link'] );
	}

	public function testSameNameTagsDoNotNestAsInWordPress(): void {
		// WordPress takes the FIRST closing tag: the outer [a] holds "[a]x".
		$text = '[a][a]x[/a][/a]';
		$n    = $this->nodes( $text );
		self::assertSame( array( 'a', '#text' ), array_column( $n, 'tag' ) );
		self::assertSame( '[a]x', $this->at( $text, $n[0]['content'] ) );
		self::assertSame( array( 'a', '#text' ), array_column( $n[0]['children'], 'tag' ) );
		self::assertFalse( $n[0]['children'][0]['closed'] );
		self::assertSame( '[/a]', $this->at( $text, array( $n[1]['start'], $n[1]['end'] ) ), 'The second closer is text.' );
	}

	public function testEveryQuoteForm(): void {
		$text = "[ok k=v 3 \"pos\" 'single' bare Name=\"Double\" s='q' esc=\"a\\\\nb\" html=\"<b>x</b>\" broken=\"<b\" empty=\"\" \"\"]";
		$n    = $this->nodes( $text )[0];
		self::assertSame(
			array(
				'k'      => 'v',
				0        => '3',
				1        => 'pos',
				2        => 'single',
				3        => 'bare',
				'name'   => 'Double',
				's'      => 'q',
				'esc'    => 'a\\nb',
				'html'   => '<b>x</b>',
				'broken' => '',
				'empty'  => '',
			),
			$n['attrs'],
			'Names lower-cased, stripcslashes, unclosed HTML refused, an empty positional value skipped — as shortcode_parse_atts().'
		);
		self::assertSame( "'", $n['attr_quotes'][2] );
		self::assertSame( '', $n['attr_quotes']['k'] );
		self::assertSame( 'a\\\\nb', $this->at( $text, $n['attr_spans']['esc'] ), 'The span is the raw value.' );
		self::assertSame( '<b', $this->at( $text, $n['attr_spans']['broken'] ) );
		foreach ( $n['attr_spans'] as $key => $span ) {
			self::assertLessThan( $n['open_end'], $span[1], (string) $key );
		}
	}

	public function testNoBreakSpacesWithoutShiftingOffsets(): void {
		$text = "[t a=\"x\u{00A0}y\"\u{00A0}b='z']after";
		$n    = $this->nodes( $text );
		self::assertSame( array( 'a' => 'x y', 'b' => 'z' ), $n[0]['attrs'], 'A no-break space reads as a space, as in WordPress.' );
		self::assertSame( "x\u{00A0}y", $this->at( $text, $n[0]['attr_spans']['a'] ) );
		self::assertSame( 'z', $this->at( $text, $n[0]['attr_spans']['b'] ) );
		self::assertSame( 'after', $this->at( $text, array( $n[1]['start'], $n[1]['end'] ) ) );
	}

	public function testEscapedStrayAndBrokenTagsAreText(): void {
		$text = 'a [[vc_row]] b [[x]y[/x]] c [/stray] d [ x] [no end e [ y] f [x title="a]b"] g [open';
		$n    = $this->nodes( $text );
		self::assertSame( array( '#text', 'x', '#text' ), array_column( $n, 'tag' ) );
		self::assertSame( 'a [[vc_row]] b [[x]y[/x]] c [/stray] d [ x] [no end e [ y] f ', $this->at( $text, array( $n[0]['start'], $n[0]['end'] ) ), 'Escaped tags, a stray closer, "[ " and a "[" inside a tag are text.' );
		self::assertSame( array( 'title="a' ), $n[1]['attrs'], 'The tag ends at the first "]", as in WordPress.' );
		self::assertSame( 'b"] g [open', $this->at( $text, array( $n[2]['start'], $n[2]['end'] ) ), 'An unterminated tag at the end is text.' );

		// With the builder's tag list the reader reads as WordPress does with
		// those shortcodes registered: "[" may stand in attribute text.
		$n = AB_MCP_Shortcode_Reader::parse( $text, array( 'tags' => array( 'no', 'x' ) ) )['nodes'];
		self::assertSame( array( '#text', 'no', '#text', 'x', '#text' ), array_column( $n, 'tag' ) );
		self::assertSame( array( 'end', 'e', '[', 'y' ), $n[1]['attrs'] );
	}

	public function testProseInBracketsDoesNotSwallowARealTag(): void {
		$text = 'See [note: details [vc_row][vc_column]x[/vc_column][/vc_row]';
		self::assertSame( array( '#text', 'vc_row' ), array_column( $this->nodes( $text ), 'tag' ) );
	}

	public function testATagListLimitsTheTags(): void {
		$text = '[vc_row][vc_row_inner][b]x[/b][/vc_row_inner][/vc_row]';
		$n    = AB_MCP_Shortcode_Reader::parse( $text, array( 'tags' => array( 'vc_row', 'b' ) ) )['nodes'];
		self::assertSame( 'vc_row', $n[0]['tag'] );
		self::assertSame( array( '#text', 'b', '#text' ), array_column( $n[0]['children'], 'tag' ), 'vc_row_inner is not in the list: text, as an unregistered shortcode is.' );
	}

	public function testAnUnclosedTagStandsAloneAndTheRestStaysText(): void {
		$text = '[vc_row][vc_column]x';
		$n    = $this->nodes( $text );
		self::assertSame( array( 'vc_row', 'vc_column', '#text' ), array_column( $n, 'tag' ) );
		self::assertFalse( $n[0]['closed'] );
		self::assertSame( 8, $n[0]['end'] );
	}

	public function testGarbageNeverBreaksTheReader(): void {
		// Fixed seed: the same garbage every run.
		mt_srand( 4711 );
		$alphabet = array( '[', ']', '/', '"', "'", '=', ' ', 'a', 'b', 'vc_row', 'x', "\xC3", "\xA4", "\x00", "\u{00A0}" );
		for ( $round = 0; $round < 300; $round++ ) {
			$text = '';
			$len  = mt_rand( 0, 80 );
			for ( $i = 0; $i < $len; $i++ ) {
				$text .= $alphabet[ mt_rand( 0, count( $alphabet ) - 1 ) ];
			}
			$result = AB_MCP_Shortcode_Reader::parse( $text );
			$this->assertWellFormed( $text, $result['nodes'], 0, strlen( $text ) );
		}
	}

	/**
	 * Offsets inside their parent, increasing, never overlapping.
	 */
	private function assertWellFormed( string $text, array $nodes, int $from, int $to ): void {
		$pos = $from;
		foreach ( $nodes as $n ) {
			self::assertGreaterThanOrEqual( $pos, $n['start'], bin2hex( $text ) );
			self::assertGreaterThan( $n['start'], $n['end'] );
			self::assertLessThanOrEqual( $to, $n['end'] );
			if ( '#text' !== $n['tag'] && null !== $n['content'] ) {
				self::assertSame( '[/' . $n['tag'] . ']', substr( $text, $n['content'][1], strlen( $n['tag'] ) + 3 ) );
				$this->assertWellFormed( $text, $n['children'], $n['content'][0], $n['content'][1] );
			}
			$pos = $n['end'];
		}
	}

	/**
	 * WordPress' reading of a text, as a tree: get_shortcode_regex() with the
	 * given names, shortcode_parse_atts(), and the content read again — what
	 * do_shortcode() does when every handler calls do_shortcode() on its
	 * content. Escaped shortcodes are text.
	 */
	private function wordpressTree( string $text, array $tags ): array {
		$out = array();
		preg_replace_callback(
			'/' . get_shortcode_regex( $tags ) . '/',
			function ( $m ) use ( &$out, $tags ) {
				if ( '[' === $m[1] && ']' === $m[6] ) {
					return '';
				}
				$out[] = array(
					'tag'      => $m[2],
					'atts'     => shortcode_parse_atts( (string) $m[3] ),
					'content'  => $m[5],
					'children' => null === $m[5] ? array() : $this->wordpressTree( $m[5], $tags ),
				);
				return '';
			},
			$text,
			-1,
			$count,
			PREG_UNMATCHED_AS_NULL
		);
		return $out;
	}

	/** The reader's tree in the same form. */
	private function readerTree( string $text, array $nodes ): array {
		$out = array();
		foreach ( $nodes as $n ) {
			if ( '#text' === $n['tag'] ) {
				continue;
			}
			$out[] = array(
				'tag'      => $n['tag'],
				'atts'     => $n['attrs'],
				'content'  => null === $n['content'] ? null : $this->at( $text, $n['content'] ),
				'children' => $this->readerTree( $text, $n['children'] ),
			);
		}
		return $out;
	}

	public function testWithATagListItReadsExactlyAsWordPress(): void {
		// Random texts from pieces of shortcode syntax, compared with
		// WordPress 7.0.2's own pattern (tests/support/shortcodes-functions.php).
		$tags   = array( 'a', 'b', 'vc_row', 'vc_row_inner' );
		$pieces = array( '[a]', '[/a]', '[b x="1"]', '[/b]', '[vc_row]', '[/vc_row]', "[vc_row_inner k='v']", '[/vc_row_inner]', '[a /]', '[[a]]', '[', ']', ' ', 'text', '"', "'", '=', '/', 'q=w', '[b', ' n="m n"', '[a x=y]', "\u{00A0}", '0="z"', '<b>', '\\\\' );
		mt_srand( 20261001 );
		for ( $round = 0; $round < 4000; $round++ ) {
			$text = '';
			for ( $i = mt_rand( 1, 12 ); $i > 0; $i-- ) {
				$text .= $pieces[ mt_rand( 0, count( $pieces ) - 1 ) ];
			}
			self::assertSame(
				$this->wordpressTree( $text, $tags ),
				$this->readerTree( $text, AB_MCP_Shortcode_Reader::parse( $text, array( 'tags' => $tags ) )['nodes'] ),
				(string) json_encode( $text )
			);
		}
	}

	public function testLongTextsStayLinear(): void {
		$start = microtime( true );
		$r     = AB_MCP_Shortcode_Reader::parse( str_repeat( '[a ', 100000 ) . ']' );
		self::assertLessThan( 2.0, microtime( true ) - $start, '100k unterminated tags in under two seconds.' );
		self::assertSame( array( '#text', 'a' ), array_column( $r['nodes'], 'tag' ), 'Every "[a" with a "[" after it is text; the last one is a tag.' );

		$r = AB_MCP_Shortcode_Reader::parse( str_repeat( '[a ', 100000 ) . ']', array( 'tags' => array( 'a' ) ) );
		self::assertCount( 1, $r['nodes'], 'With the tag list: one tag whose attribute text runs to the only "]".' );
		self::assertSame( 'a', $r['nodes'][0]['tag'] );
		self::assertCount( AB_MCP_Shortcode_Reader::MAX_ATTS, $r['nodes'][0]['attrs'], 'Its attributes are read up to the cap …' );
		self::assertTrue( $r['nodes'][0]['attrs_truncated'], '… and say so.' );
		self::assertTrue( $r['truncated'] );
		self::assertLessThan( 64 * 1024 * 1024, memory_get_peak_usage(), 'Without filling the memory.' );

		$r = AB_MCP_Shortcode_Reader::parse( str_repeat( '[b]', AB_MCP_Shortcode_Reader::MAX_NODES + 5 ) );
		self::assertTrue( $r['truncated'] );
		self::assertCount( AB_MCP_Shortcode_Reader::MAX_NODES + 1, $r['nodes'], 'The rest is one text node.' );
	}

	public function testDeepNestingIsCutButNotLost(): void {
		$depth = AB_MCP_Shortcode_Reader::MAX_DEPTH + 10;
		$text  = '';
		for ( $i = 0; $i < $depth; $i++ ) {
			$text .= '[t' . $i . ']';
		}
		for ( $i = $depth - 1; $i >= 0; $i-- ) {
			$text .= '[/t' . $i . ']';
		}
		$r = AB_MCP_Shortcode_Reader::parse( $text );
		self::assertTrue( $r['truncated'] );
		$node  = $r['nodes'][0];
		$level = 1;
		while ( array() !== $node['children'] ) {
			$node = $node['children'][0];
			++$level;
		}
		self::assertSame( AB_MCP_Shortcode_Reader::MAX_DEPTH, $level );
		self::assertNotNull( $node['content'], 'The deepest read node still knows its content range.' );
	}
}

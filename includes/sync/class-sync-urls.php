<?php
/**
 * The addresses of the sites that name an object by its id (Z8, decision
 * 12; the line of the plan: an unsafe way is locked where it shows). The
 * core reads, nothing is rebuilt: the HTML API reads tags and the values of
 * their attributes, WP_HTML_Decoder decodes, parse_str() reads a query as
 * PHP gives it to WordPress, url_to_postid() of the site whose content it
 * is resolves a path. Rewritten is only an id whose stored form is exactly
 * the one these readings give; every other form that names or may name an
 * id locks the object (*:reference_spelling).
 *
 * Where an address stands: with the host of a site (address_cuts() of
 * AB_MCP_Sync_Canon, also "//host"), in the value of an attribute of HTML
 * (with the host or without it), as a whole value (an attribute of a block,
 * _menu_item_url), and in another spelling of a site's host (capitals, the
 * default port: the same site, locked, never a foreign address).
 *
 * Safe, so rewritten: an address with the host of a site (the host is
 * replaced with it) or from a root both sites share; in an attribute, the
 * attribute stored exactly as set_attribute() writes its value; each
 * variable of QUERY_IDS that parse_str() reads stored once, exactly by its
 * name, with exactly the digits of its id (a list of them at commas); the
 * id url_to_postid() gives as exactly one raw segment of digits of a path
 * without dot segments, double slashes or percent escapes. Everything else
 * that names or may name an id locks: an address relative to the page, a
 * spelling the readings do not share, a path the core cannot be asked
 * about (then a segment of digits), an address of the other site.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-sync-css.php';
require_once __DIR__ . '/class-sync-json.php';

/**
 * Class AB_MCP_Sync_Urls
 */
final class AB_MCP_Sync_Urls {

	/**
	 * The query variables WP_Query reads as ids, of those WP takes from an
	 * address (WP::$public_query_vars, wp-includes/class-wp.php:18): p
	 * ((int), class-wp-query.php:831-836, :2247), page_id (absint(), :839),
	 * attachment_id and subpost_id (absint(), :885-889, :2241-2242), cat (a
	 * list of terms, :846, :1247) and author (a list of users, :847,
	 * :2398-2400). tag_id and the other private ones are no part of an
	 * address.
	 */
	const QUERY_IDS = array(
		'p'             => 'post',
		'page_id'       => 'post',
		'attachment_id' => 'post',
		'subpost_id'    => 'post',
		'cat'           => 'term',
		'author'        => 'user',
	);

	/** The variables of QUERY_IDS that hold a list. */
	const QUERY_LISTS = array( 'cat', 'author' );

	/**
	 * The attributes of HTML (and of shortcodes) whose value is an address the
	 * browser resolves against the page.
	 */
	const URL_ATTRIBUTES = array( 'href', 'src', 'srcset', 'action', 'formaction', 'cite', 'poster', 'data', 'background', 'url', 'link' );

	/** The separators of a query as stored: &, &amp;, &#38;, &#x26;, \u0026 of JSON. */
	const SEPARATOR = '/&amp;|&#0*38;|&#x0*26;|\\\\u0026|&(?!#|[A-Za-z0-9]+;)/i';

	/** A host with an optional port after "//" (also \/\/ of JSON): a spelling of an address. */
	const HOST = '#(?:(https?):)?(?://|\\\\/\\\\/)([A-Za-z0-9.\-]+(?::[0-9]+)?)#i';

	/**
	 * How far an address goes in a text: up to the first character that
	 * stands in no address as stored and ends one in HTML, CSS and JS
	 * (spaces, quotes, the backtick, brackets, braces, <, >, |, ^, a
	 * backslash); the escapes of JSON (\/, \uXXXX) are part of it.
	 */
	const EXTENT = '#\G(?:\\\\/|\\\\u[0-9a-fA-F]{4}|[^\s"\'`()<>{}|^\\\\])*#';

	/**
	 * An address of a scheme without a host whose query may hold an address
	 * (mailto:?body=…, sms:?body=…; Codex, round 23, A-1): read as stored in
	 * prose and by the net as an address of no site.
	 */
	const SCHEMES = '#(?<![A-Za-z0-9+.\-])(?:mailto|sms):#i';

	/** The same at the beginning of an address, with its scheme and its host and port. */
	const HOST_AT_START = '#^(?:(https?):)?(?://|\\\\/\\\\/)([^/?\#\\\\]*)#i';

	/**
	 * Where a root-relative address begins in a text node or in prose: a
	 * "/" after a character that stands in no address and continues no word
	 * (a backslash escapes it in JSON: read in the JSON).
	 */
	const ROOT = '#(?<![A-Za-z0-9_.~%\-/?\\\\])/#';

	/** How deep a query value that is an address is read in turn (query_values()); deeper, it locks. */
	const MAX_QUERY_DEPTH = 2;

	/**
	 * The ids the addresses of the sites in a text name.
	 *
	 * @param string $text      Text.
	 * @param array  $ctx       AB_MCP_Sync_Canon::context().
	 * @param array  $addresses AB_MCP_Sync_Canon::address_cuts() of the text.
	 * @param bool   $whole     The whole text is an address.
	 * @return array<int,array{at:int,len:int,space:string,id:int|null,what:string}>
	 *               id null: in a form that is not rewritten (the object
	 *               locks); at, len: the digits in the text.
	 */
	public static function ids( $text, array $ctx, array $addresses, $whole = false ) {
		$text = (string) $text;
		$out  = array();
		$done = array();
		// A text of JSON (global styles, theme.json, a meta value): one span,
		// read by its round trip (AB_MCP_Sync_Json, AB_MCP_Sync_Refs::
		// json_canon()), nothing in it one by one; deeper than json_decode()
		// reads, it locks.
		$json = AB_MCP_Sync_Json::read( $text );
		if ( false === $json ) {
			return array( self::lock( 0, strlen( $text ), ':json' ) );
		}
		if ( null !== $json ) {
			return array( self::json_hit( 0, $json ) );
		}
		// In the values of the attributes of tags, as the HTML API reads them;
		// CSS holds no tags (custom_css goes out through wp_custom_css_cb()
		// into the modifiable text of a style element,
		// WP_HTML_Tag_Processor::set_modifiable_text(): its text as text).
		// The text nodes beside them (Codex, round 22, B-1), a text without
		// tags as one node: a root-relative address in one is read as the
		// value of an attribute is (text_ids()).
		$nodes = empty( $ctx['css'] ) && ! $whole ? array( array( 'at' => 0, 'len' => strlen( $text ), 'text' => $text ) ) : array();
		if ( empty( $ctx['css'] ) && preg_match( '/<[A-Za-z]/', $text ) ) {
			$tags = null;
			// The HTML API reads UTF-8: a site in another charset is not read.
			if ( class_exists( 'WP_HTML_Tag_Processor' ) && ! empty( $ctx['utf8'] ) ) {
				require_once __DIR__ . '/class-sync-html.php';
				$tags = AB_MCP_Sync_Html::tag_list( $text );
			}
			if ( null === $tags ) {
				// Without the HTML API, or where it cannot read to the end,
				// nothing tells where a value ends, nor a text node from an
				// attribute: where an address may stand in one (also in JSON
				// of a value, or behind a reference), or a root-relative one
				// with a digit in a text, it locks.
				return array() !== $addresses || self::names_a_site( $text, $ctx ) || preg_match( '/<(?:script|style)\b|(?<![A-Za-z0-9_:\-])(?:' . implode( '|', self::URL_ATTRIBUTES ) . '|style)\s*=|=\s*["\']?\s*(?:[\w\-\/]+::)?[\[{&]/i', $text ) || preg_match( substr( self::ROOT, 0, -1 ) . '[^\s"\'<>]*[1-9]#', $text ) ? array( self::lock( 0, strlen( $text ), ':tags' ) ) : array();
			}
			$nodes = AB_MCP_Sync_Html::text_nodes( $text );
			foreach ( $tags as $tag ) {
				$raw = substr( $text, $tag['at'], $tag['len'] );
				foreach ( $tag['attrs'] as $name => $value ) {
					if ( ! is_string( $value ) ) {
						continue;
					}
					// JavaScript (an event attribute) is no address: the unit
					// locks for it (*:script, AB_MCP_Sync_Credentials).
					if ( 0 === strpos( $name, 'on' ) ) {
						continue;
					}
					// CSS of a style attribute as that of style: its addresses
					// one by one, as stored where it is stored as read.
					if ( 'style' === $name ) {
						$out = array_merge( $out, self::css_ids( $raw, $value, $ctx, $tag['at'] ) );
						continue;
					}
					// JSON in an attribute as the core reads it (data-wp-*, its
					// namespace before "::" off): read whole where it is stored
					// as it is read; not JSON, it locks. The JSON of a plugin in
					// any other data-* (Codex, round 21, B-1): the same where it
					// is JSON stored as it is read, else a text (the net locks
					// an address with an id in it).
					$json = AB_MCP_Sync_Html::json_value( $name, $value );
					if ( null !== $json ) {
						$out = array_merge( $out, self::json_attribute( $raw, $name, $value, $json, $ctx, $tag['at'], true ) );
						continue;
					}
					$json = AB_MCP_Sync_Html::data_json( $name, $value );
					if ( null !== $json ) {
						$hits = self::json_attribute( $raw, $name, $value, $json, $ctx, $tag['at'], false );
						if ( null !== $hits ) {
							$out = array_merge( $out, $hits );
							continue;
						}
					}
					if ( ! in_array( $name, self::URL_ATTRIBUTES, true ) && ! self::names_a_site( $value, $ctx ) ) {
						continue;
					}
					// Stored as the core writes it: read as stored, rewritten
					// there; otherwise read decoded, and nothing is rewritten.
					// The candidates of srcset (Codex, round 23, B-2) one by
					// one, each an address.
					$span   = AB_MCP_Sync_Html::value_span( $raw, $name, $value );
					$stored = null === $span ? null : substr( $raw, $span[0], $span[1] );
					if ( 'srcset' === $name ) {
						foreach ( self::srcset_candidates( null === $stored ? $value : $stored ) as $candidate ) {
							$out = array_merge( $out, self::address( $candidate[0], $ctx, null === $stored ? null : $tag['at'] + $span[0] + $candidate[1], $text ) );
						}
						continue;
					}
					$out = array_merge( $out, null === $stored ? self::address( $value, $ctx, null, $text ) : self::address( $stored, $ctx, $tag['at'] + $span[0], $text ) );
				}
				$done = array_merge( $done, self::element_done( $text, $ctx, $tag, $out ) );
			}
		}
		if ( $whole ) {
			return array_merge( $out, self::address( $text, $ctx, 0, $text ) );
		}
		// A text that is CSS (custom_css): its url(…) and escapes as CSS.
		if ( ! empty( $ctx['css'] ) ) {
			$out = array_merge( $out, self::css( $text, $ctx, 0, $text, true ) );
		}
		// In the attributes of shortcodes, as the core reads them: read as
		// stored where the value is stored as it is read, once.
		if ( false !== strpos( $text, '[' ) && class_exists( 'AB_MCP_Sync_Refs' ) ) {
			foreach ( AB_MCP_Sync_Refs::shortcode_units( $text ) as $unit ) {
				foreach ( $unit['atts'] as $name => $value ) {
					if ( ! is_string( $name ) || ! is_string( $value ) || ( ! self::names_an_address( $name, $value ) && ! self::names_a_site( $value, $ctx ) ) ) {
						continue;
					}
					$stored = isset( $unit['stored'][ strtolower( $name ) ] ) ? $unit['stored'][ strtolower( $name ) ] : array();
					foreach ( $stored as $pair ) {
						$done[] = array( $pair[1], $pair[1] + strlen( $pair[0] ) );
					}
					// The one pair of the name, outside [[…]]; address() reads it
					// as stored only where it is stored as it is read.
					$out = array_merge( $out, self::address( $value, $ctx, 1 === count( $stored ) && ! $unit['escaped'] ? $stored[0][1] : null, $text ) );
				}
			}
		}
		// The root-relative addresses of the text nodes, outside what was read
		// (a tag, a shortcode): rewritten where the node is stored as the
		// HTML API reads it (address() compares the stored bytes), else read
		// decoded (locked).
		foreach ( $nodes as $node ) {
			$out = array_merge( $out, self::text_ids( $node['text'], $ctx, $node['at'], $node['len'], $text, $done ) );
		}
		return array_merge( $out, self::prose( $text, $ctx, $addresses, $done, true ) );
	}

	/**
	 * The ids of the root-relative addresses of a text node (Codex, round
	 * 22, B-1: the visible text beside a link, a title, a description), read
	 * as the value of an attribute is: from each "/" that begins one (ROOT)
	 * to the end of an address (EXTENT, the punctuation after it none of
	 * it). Rewritten where it stands in the stored node (Codex, round 23,
	 * B-1): as it is, or as esc_html() writes it (&amp; for &; the stored
	 * spelling is read then, as an attribute is); a node with character
	 * references is searched for it, in order. An address that stands in
	 * the node only decoded (&#47;?p=12) is read decoded (locked). What
	 * stands in a span read already (a shortcode) is not read again.
	 *
	 * @param string $node The text of the node, as the HTML API reads it.
	 * @param array  $ctx  Context.
	 * @param int    $base Where the node stands in $text.
	 * @param int    $len  Its length there.
	 * @param string $text The text.
	 * @param array  $done Spans read already.
	 * @return array See ids().
	 */
	private static function text_ids( $node, array $ctx, $base, $len, $text, array $done ) {
		if ( ! preg_match( '/[1-9]/', $node ) || ! preg_match_all( self::ROOT, $node, $m, PREG_OFFSET_CAPTURE ) ) {
			return array();
		}
		$stored = (string) substr( $text, $base, $len );
		$cursor = 0;
		// An address with a host (prose() reads it) holds no root-relative
		// start of its own.
		$hosts = array();
		if ( preg_match_all( self::HOST, $node, $h, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $h[0] as $host ) {
				preg_match( self::EXTENT, $node, $e, 0, (int) $host[1] );
				$hosts[] = array( (int) $host[1], (int) $host[1] + strlen( $e[0] ) );
			}
		}
		$out = array();
		foreach ( $m[0] as $start ) {
			$at = (int) $start[1];
			// Not inside an address read (with a host, or one read here: its
			// query values are read with it), nor a span read already.
			if ( self::inside( $at, $hosts ) || self::inside( $base + $at, $done ) ) {
				continue;
			}
			preg_match( self::EXTENT, $node, $e, 0, $at );
			$url     = (string) preg_replace( '/[.,;:!)\]]+\z/', '', $e[0] );
			$hosts[] = array( $at, $at + strlen( $url ) );
			if ( ! preg_match( '/[1-9]/', $url ) ) {
				continue;
			}
			if ( $stored === $node ) {
				$out = array_merge( $out, self::address( $url, $ctx, $base + $at, $text ) );
				continue;
			}
			// In a node with character references: the address as it is, or
			// as esc_html() writes it, the next one after the last found.
			$found = null;
			foreach ( array_unique( array( $url, htmlspecialchars( $url, ENT_QUOTES, 'UTF-8', false ) ) ) as $spelling ) {
				$where = strpos( $stored, $spelling, $cursor );
				if ( false !== $where && ( null === $found || $where < $found[0] ) ) {
					$found = array( $where, $spelling );
				}
			}
			if ( null === $found ) {
				$out = array_merge( $out, self::address( $url, $ctx, null, $text ) );
				continue;
			}
			$cursor = $found[0] + strlen( $found[1] );
			$out    = array_merge( $out, self::address( $found[1], $ctx, $base + $found[0], $text ) );
		}
		return $out;
	}

	/**
	 * The spans of a tag that are read: the tag; for an element whose content
	 * the HTML API reads as text (script, style, textarea, title …) only its
	 * tags where the content is stored as it is read, the content left to the
	 * prose (read there, rewritten there); else the whole element, its
	 * content read decoded, every id in it locked.
	 *
	 * @param string $text Text.
	 * @param array  $ctx  Context.
	 * @param array  $tag  Of tag_list().
	 * @param array  $out  The ids, to add those of a decoded content to.
	 * @return array<int,array{0:int,1:int}>
	 */
	private static function element_done( $text, array $ctx, array $tag, array &$out ) {
		$end = $tag['at'] + $tag['len'];
		if ( null === $tag['text'] ) {
			return array( array( $tag['at'], $end ) );
		}
		$raw    = substr( $text, $tag['at'], $tag['len'] );
		$closer = strripos( $raw, '</' . strtolower( $tag['tag'] ) );
		$start  = false === $closer ? -1 : $closer - strlen( $tag['text'] );
		if ( $start > 0 && substr( $raw, $start, strlen( $tag['text'] ) ) === $tag['text'] ) {
			// A script of JSON: its content by its round trip, as a span;
			// what is no JSON of an object or a list locks.
			if ( 'SCRIPT' === $tag['tag'] && AB_MCP_Sync_Html::is_data_script( $tag['attrs'] ) ) {
				$json  = AB_MCP_Sync_Json::read( $tag['text'] );
				$out[] = is_array( $json ) ? self::json_hit( $tag['at'] + $start, $json ) : self::lock( $tag['at'], $tag['len'], ':json' );
				return array( array( $tag['at'], $end ) );
			}
			// Style: CSS (url(…) without a host, its escapes).
			if ( 'STYLE' === $tag['tag'] ) {
				$out = array_merge( $out, self::css( $tag['text'], $ctx, $tag['at'] + $start, $text, true ) );
			}
			return array( array( $tag['at'], $tag['at'] + $start ), array( $tag['at'] + $closer, $end ) );
		}
		$out = array_merge( $out, self::prose( $tag['text'], $ctx, AB_MCP_Sync_Canon::address_cuts( $tag['text'], $ctx ), array(), false ), 'STYLE' === $tag['tag'] ? self::css( $tag['text'], $ctx, 0, $tag['text'], false ) : array() );
		return array( array( $tag['at'], $end ) );
	}

	/**
	 * The ids of the addresses in the CSS of a style attribute, as in style
	 * (prose(): each address of the sites up to EXTENT, url(…) ending it):
	 * read in the stored value and rewritten there where it is stored as
	 * the HTML API reads it (value_span()), else read decoded, locked. The
	 * CSS itself (AB_MCP_Sync_Css) is read in the stored value only where it
	 * is the decoded one byte for byte: a quote written as a reference
	 * (&quot;) would make a string of CSS a comment; else decoded, locked.
	 *
	 * @param string $raw   The stored text of the tag.
	 * @param string $value The value, decoded.
	 * @param array  $ctx   Context.
	 * @param int    $base  Where the tag stands in the text.
	 * @return array See ids().
	 */
	private static function css_ids( $raw, $value, array $ctx, $base ) {
		$span = AB_MCP_Sync_Html::value_span( $raw, 'style', $value );
		if ( null === $span ) {
			return array_merge( self::prose( $value, $ctx, AB_MCP_Sync_Canon::address_cuts( $value, $ctx ), array(), false ), self::css( $value, $ctx, 0, $value, false ) );
		}
		$css = (string) substr( $raw, $span[0], $span[1] );
		$out = array();
		foreach ( array_merge( self::prose( $css, $ctx, AB_MCP_Sync_Canon::address_cuts( $css, $ctx ), array(), true ), $css === $value ? self::css( $css, $ctx, 0, $css, true ) : self::css( $value, $ctx, 0, $value, false ) ) as $hit ) {
			$hit['at'] += $base + $span[0];
			$out[]      = $hit;
		}
		return $out;
	}

	/**
	 * What CSS holds beside the addresses with a host (those prose() reads),
	 * as AB_MCP_Sync_Css::read() reads it (without its comments): the
	 * addresses of url(…), @import and image-set(…) without the host, read
	 * as any address (root: rewritten as stored where safe, relative to the
	 * page: page_ids(), a query with ids locks). The text locks where a
	 * backslash stands in the name of a function or an at-rule or in a value
	 * (AB_MCP_Sync_Css::escapes()), where an address is not read to its end
	 * (a comment or a backslash in it, a url( or an @import not read so),
	 * where it hides one (css_hides()) and where a data: is not free
	 * (data_free()). Any other string (a custom property that reaches
	 * image-set(var(…)) as an address) is not read here: an address with
	 * an id in it locks through the net over the raw value
	 * (AB_MCP_Sync_Net), which reads every string of the text whatever its
	 * quotes and escapes.
	 *
	 * @param string $css    CSS.
	 * @param array  $ctx    Context.
	 * @param int    $base   Where it stands in $text.
	 * @param string $text   The text it stands in.
	 * @param bool   $stored Whether it is stored as read (else locked).
	 * @return array See ids().
	 */
	private static function css( $css, array $ctx, $base, $text, $stored ) {
		$read = AB_MCP_Sync_Css::read( $css );
		if ( $read['lock'] || self::css_hides( $read, $ctx ) ) {
			return array( self::lock( $base, strlen( $css ), ':css' ) );
		}
		foreach ( $read['data'] as $url ) {
			if ( ! self::data_free( $url, $ctx ) ) {
				return array( self::lock( $base, strlen( $css ), ':css' ) );
			}
		}
		$out = array();
		foreach ( $read['urls'] as $url ) {
			$found = '' === $url['url'] ? null : self::read( $url['url'], $ctx );
			if ( null === $found || ! in_array( $found['form'], array( 'root', 'page' ), true ) ) {
				continue;
			}
			$out = array_merge( $out, self::address( $url['url'], $ctx, $stored ? $base + $url['at'] : null, $text ) );
		}
		return $out;
	}

	/**
	 * Whether an address data: of CSS is free: an image of
	 * AB_MCP_Sync_Css::DATA_IMAGES that decodes, and what it holds names no
	 * site (a host inside it cannot be replaced in place). An address with
	 * an id or a credential inside it (an SVG with a link, a script, JSON)
	 * locks through the net over the raw value (AB_MCP_Sync_Net), which
	 * decodes every data: address of the text.
	 *
	 * @param string $url The address.
	 * @param array  $ctx Context.
	 * @return bool
	 */
	private static function data_free( $url, array $ctx ) {
		$data = AB_MCP_Sync_Css::data( $url );
		if ( null === $data ) {
			return false;
		}
		return ! self::names_a_site( $data['content'], array_diff_key( $ctx, array( 'css' => true ) ) );
	}

	/**
	 * The (space, id) pairs one address names, as address() reads it where
	 * it stands (rewritable where it is safe, else locked: its id "?"): what
	 * the net over the raw value (AB_MCP_Sync_Net) compares with the pairs
	 * ids() read (AB_MCP_Sync_Canon::text(), addressed).
	 *
	 * @param string $url The address, to the end of an address (EXTENT).
	 * @param array  $ctx Context.
	 * @return string[] space:id, as often as named.
	 */
	public static function pairs( $url, array $ctx ) {
		$out = array();
		foreach ( self::hits_of( $url, $ctx ) as $hit ) {
			if ( isset( $hit['enc'] ) ) {
				$out = array_merge( $out, self::pairs( $hit['enc']['text'], $ctx ) );
				continue;
			}
			$out[] = $hit['space'] . ':' . ( null === $hit['id'] ? '?' : $hit['id'] );
		}
		return $out;
	}

	/**
	 * Whether CSS hides an address of the sites: a comment inside one (the
	 * characters before and after it, without a space, a quote, a bracket or
	 * a separator, give an address of a site), or escapes whose text decoded
	 * holds more hosts of a site, segments of digits after "/" or variables
	 * of an id after "?" than the text without them (escapes in strings, as
	 * a custom property may give them to image-set(var(…)); one that gives
	 * none of these, \201C, is free; outside of strings every backslash
	 * locks already, AB_MCP_Sync_Css::read()).
	 *
	 * @param array $read AB_MCP_Sync_Css::read().
	 * @param array $ctx  Context.
	 * @return bool
	 */
	private static function css_hides( array $read, array $ctx ) {
		foreach ( $read['comments'] as $at ) {
			$left  = preg_match( '#[^\s"\'`()<>{}|^;,]+\z#', (string) substr( $read['plain'], 0, $at ), $l ) ? $l[0] : '';
			$right = preg_match( '#\G[^\s"\'`()<>{}|^;,]+#', $read['plain'], $r, 0, $at ) ? $r[0] : '';
			if ( '' !== $left && '' !== $right && self::css_counts( $left . $right, $ctx )['sites'] > 0 ) {
				return true;
			}
		}
		$plain = self::css_counts( $read['plain'], $ctx );
		foreach ( self::css_counts( $read['decoded'], $ctx ) as $what => $count ) {
			if ( $count > $plain[ $what ] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * What in CSS makes an address: the hosts of a site in any spelling
	 * (HOST, site()), the segments of digits after "/", the variables of
	 * QUERY_IDS after "?" as parse_str() reads them.
	 *
	 * @param string $css CSS.
	 * @param array  $ctx Context.
	 * @return array<string,int>
	 */
	private static function css_counts( $css, array $ctx ) {
		$sites = 0;
		if ( preg_match_all( self::HOST, $css, $hosts, PREG_SET_ORDER ) ) {
			foreach ( $hosts as $host ) {
				$sites += null === self::site( $host[2], strtolower( $host[1] ), $ctx ) ? 0 : 1;
			}
		}
		$ids = 0;
		if ( preg_match_all( '#\?([^\s"\'`()<>{}]*)#', $css, $queries ) ) {
			foreach ( $queries[1] as $query ) {
				parse_str( $query, $vars );
				$ids += count( array_intersect_key( $vars, self::QUERY_IDS ) );
			}
		}
		return array(
			'sites'  => $sites,
			'digits' => (int) preg_match_all( '#/[0-9]+(?![^/?\#\s"\'),;}])#', $css ),
			'ids'    => $ids,
		);
	}

	/**
	 * JSON in an attribute: a span read whole (AB_MCP_Sync_Refs::json_canon():
	 * by its round trip through a producer of the core, else value by value,
	 * AB_MCP_Sync_Json), where the attribute is stored as it is read, as it
	 * is or as esc_attr() writes it (&quot;, &#039;: written back so,
	 * AB_MCP_Sync_Refs::rewrite_text()). Strict (data-wp-*, as the core reads
	 * it): what is no JSON of an object or a list locks, and what is not
	 * stored as it is read is read only (an id or an address in it locks).
	 * Loose (the JSON of a plugin in another data-*): what is no JSON, or is
	 * not stored as it is read, is a text (null: the net reads it).
	 *
	 * @param string $raw    The tag as stored.
	 * @param string $name   The attribute.
	 * @param string $value  Its value as the HTML API reads it.
	 * @param string $json   The JSON in it (json_value(), data_json()).
	 * @param array  $ctx    Context.
	 * @param int    $base   Where the tag stands in the text.
	 * @param bool   $strict As the core reads it (data-wp-*).
	 * @return array|null See ids(); null where a loose value is a text.
	 */
	private static function json_attribute( $raw, $name, $value, $json, array $ctx, $base, $strict ) {
		$read = AB_MCP_Sync_Json::read( $json );
		if ( ! is_array( $read ) ) {
			return $strict ? array( self::lock( $base, strlen( $raw ), ':json' ) ) : null;
		}
		$span = AB_MCP_Sync_Html::value_span( $raw, $name, $value );
		if ( null !== $span ) {
			$stored  = (string) substr( $raw, $span[0], $span[1] );
			$escaped = htmlspecialchars( $value, ENT_QUOTES, 'UTF-8', false );
			if ( $stored === $value ) {
				return array( self::json_hit( $base + $span[0] + strlen( $value ) - strlen( $json ), $read, null ) );
			}
			if ( $stored === $escaped && $value === $json ) {
				$hit        = self::json_hit( $base + $span[0], $read, 'attr' );
				$hit['len'] = strlen( $escaped );
				return array( $hit );
			}
		}
		if ( ! $strict ) {
			return null;
		}
		// Not stored as it is read: nothing in it is taken over, an id or a
		// host locks.
		$read['producer'] = null;
		$acc              = AB_MCP_Sync_Canon::acc();
		$form             = AB_MCP_Sync_Refs::json_canon( $read, $ctx, $acc, '' );
		return array() === $acc['spelling'] && 1 === count( $form['~s'] ) ? array() : array( self::lock( $base, strlen( $raw ), ':json' ) );
	}

	/**
	 * A span of stored JSON as a hit of ids(): AB_MCP_Sync_Canon::text()
	 * and AB_MCP_Sync_Refs::rewrite_text() read and write it whole.
	 *
	 * @param int         $at   Where it stands.
	 * @param array       $json Of AB_MCP_Sync_Json::read().
	 * @param string|null $esc  How the span is stored: null as it is, attr as
	 *                          esc_attr() writes it.
	 * @return array
	 */
	private static function json_hit( $at, array $json, $esc = null ) {
		return array(
			'at'    => (int) $at,
			'len'   => strlen( $json['raw'] ),
			'space' => 'json',
			'id'    => null,
			'what'  => ':json',
			'json'  => $json,
			'esc'   => $esc,
		);
	}

	/**
	 * The ids of the addresses in prose and in texts of no HTML: from each
	 * address of the sites to the first character that ends an address, and
	 * another spelling of a site's host (the same site).
	 *
	 * @param string $text      Text.
	 * @param array  $ctx       Context.
	 * @param array  $addresses address_cuts() of the text.
	 * @param array  $done      Spans read already.
	 * @param bool   $stored    Whether the text is stored so (else locked).
	 * @return array See ids().
	 */
	private static function prose( $text, array $ctx, array $addresses, array $done, $stored ) {
		$out = array();
		foreach ( $addresses as $address ) {
			// A percent-encoded address (the value of a query variable) is
			// read with the address it stands in (query_values()), not here.
			if ( self::inside( $address['at'], $done ) || 'percent' === $address['marker']['~addr'] ) {
				continue;
			}
			preg_match( self::EXTENT, $text, $m, 0, $address['at'] + $address['len'] );
			$url = substr( $text, $address['at'], $address['len'] ) . (string) preg_replace( '/[.,;:!)\]]+\z/', '', $m[0] );
			$out = array_merge( $out, self::address( $url, $ctx, $stored ? $address['at'] : null, $text ) );
		}
		// Every other host, and an address of SCHEMES: as stored where the
		// text is (an address of no site rewrites the values of its query;
		// Codex, round 23, A-1).
		foreach ( array( self::HOST, self::SCHEMES ) as $pattern ) {
			if ( ! preg_match_all( $pattern, $text, $hosts, PREG_OFFSET_CAPTURE ) ) {
				continue;
			}
			foreach ( $hosts[0] as $host ) {
				if ( self::inside( (int) $host[1], $done ) || self::at_cut( (int) $host[1], $addresses ) ) {
					continue;
				}
				preg_match( self::EXTENT, $text, $m, 0, (int) $host[1] );
				$out = array_merge( $out, self::address( $m[0], $ctx, $stored ? (int) $host[1] : null, $text ) );
			}
		}
		return $out;
	}

	/**
	 * The ids of one address.
	 *
	 * @param string   $url  The address: as stored at $at, or, where $at
	 *                       is null, as it is read (decoded).
	 * @param array    $ctx  Context.
	 * @param int|null $at   Where it stands in the text as stored; null where
	 *                       it is not stored so (nothing is rewritten).
	 * @param string   $text The text.
	 * @return array See ids().
	 */
	private static function address( $url, array $ctx, $at, $text ) {
		// As the URL parser reads it: without ASCII tabs and line breaks, C0
		// and spaces trimmed at both ends; read so, never rewritten so.
		$clean = trim( str_replace( array( "\t", "\n", "\r" ), '', $url ), "\x00..\x20" );
		if ( $clean !== $url ) {
			$url = $clean;
			$at  = null;
		}
		$read   = self::read( $url, $ctx );
		$stored = null !== $at && substr( $text, $at, strlen( $url ) ) === $url;
		// Rewritten where it is rewritten (safe) and stored as read; an
		// address of no site (foreign) holds nothing of its own to rewrite,
		// the values of its query are rewritten where it is stored as read.
		$at = ( $read['safe'] || 'foreign' === $read['form'] ) && $stored ? $at : null;
		return self::hits( $url, $read, $ctx, $at, 0 );
	}

	/**
	 * The ids of one address read (read()): of its query (query_ids()), of
	 * its path (path_ids()) and of the values of its query that are
	 * addresses in turn (query_values(); of an address relative to the
	 * page or of the other site: locked there as the address itself is).
	 * An address of no site (foreign) names no object of its own: only the
	 * values of its query (Codex, round 23, A-1). One that is no address
	 * as the URL parser reads it (userinfo, "%" in the host: A-5, A-6)
	 * locks.
	 *
	 * @param string   $url   Address.
	 * @param array    $read  read().
	 * @param array    $ctx   Context.
	 * @param int|null $at    Where it stands as stored, or null.
	 * @param int      $depth How many query values above it.
	 * @return array See ids().
	 */
	private static function hits( $url, array $read, array $ctx, $at, $depth ) {
		if ( 'invalid' === $read['form'] ) {
			return array( self::lock( (int) $at, strlen( $url ), $read['why'] ) );
		}
		$out = 'foreign' === $read['form'] ? array() : array_merge( self::query_ids( $url, $read['query'], $at ), self::path_ids( $url, $read, $ctx, $at ) );
		if ( null !== $read['query'] ) {
			$out = array_merge( $out, self::query_values( $url, $read['query'], $at, $ctx, $depth, 'page' === $read['form'] ) );
		}
		return $out;
	}

	/**
	 * The ids one address of the sites names, as stored (hits() where it
	 * stands at 0): for the readers of stored JSON (AB_MCP_Sync_Refs) and
	 * the net (AB_MCP_Sync_Net::references()).
	 *
	 * @param string $url The address.
	 * @param array  $ctx Context.
	 * @return array See ids(); at relative to $url.
	 */
	public static function hits_of( $url, array $ctx ) {
		$url = (string) $url;
		return self::address( $url, $ctx, 0, $url );
	}

	/**
	 * The values of the variables of a query of an address read, decoded
	 * as parse_str() decodes them (urldecode(); Codex, round 22, A-1:
	 * redirect_to, and every other variable; round 23, A-1: in an address
	 * of no site as well, a share link): a value that is an address of the
	 * sites, or holds an id of them, is one hit for the whole stored value
	 * ("enc": its decoded text and the one encoding that writes it byte
	 * for byte as it is stored – as it is, rawurlencode(), urlencode()),
	 * read and rewritten by its round trip (AB_MCP_Sync_Canon::text(),
	 * AB_MCP_Sync_Refs::rewrite_text(): decoded, the address inside read
	 * as any address with its host and ids, written back in that
	 * encoding). The values of its own query in turn, to MAX_QUERY_DEPTH
	 * from the address read (hits()): deeper, locked. Locked whole where
	 * an id inside is not read as stored or too deep (the round trip would
	 * count the depth anew), where no encoding writes the value so
	 * (lower-case escapes, a mix), where the address it stands in is not
	 * rewritten (not stored as read, another spelling of the host,
	 * relative to the page, of the other site), and where "&" cannot be
	 * told from "&amp;" (no WP_HTML_Decoder). A host of no site inside is
	 * none of this; a value stored as it is in an address relative to the
	 * page is read where it stands (ROOT), not here.
	 *
	 * @param string   $url   Address.
	 * @param int      $start Where its query begins.
	 * @param int|null $at    Where the address stands, where it is rewritten.
	 * @param array    $ctx   Context.
	 * @param int      $depth How many query values above it.
	 * @param bool     $page  Whether the address is relative to the page.
	 * @return array See ids().
	 */
	private static function query_values( $url, $start, $at, array $ctx, $depth, $page = false ) {
		$out   = array();
		$hash  = preg_match( '/(?<!&)#/', $url, $h, PREG_OFFSET_CAPTURE, $start ) ? (int) $h[0][1] : strlen( $url );
		$query = (string) substr( $url, $start, $hash - $start );
		// As readings(): a "&" is told from "&amp;" by the core only.
		if ( null !== $at && false !== strpos( $query, '&' ) && ! class_exists( 'WP_HTML_Decoder' ) ) {
			$at = null;
		}
		foreach ( preg_split( self::SEPARATOR, $query, -1, PREG_SPLIT_OFFSET_CAPTURE ) as $pair ) {
			$eq = strpos( $pair[0], '=' );
			if ( false === $eq ) {
				continue;
			}
			$name    = (string) substr( $pair[0], 0, $eq );
			$value   = (string) substr( $pair[0], $eq + 1 );
			$decoded = urldecode( $value );
			if ( ! self::names_an_address( '', $decoded ) && false === strpos( $decoded, '?' ) ) {
				continue;
			}
			$inner = self::read( $decoded, $ctx );
			$what  = '?' . $name;
			if ( $depth >= self::MAX_QUERY_DEPTH ) {
				$out[] = self::lock( (int) $at, strlen( $url ), $what . '?' );
				continue;
			}
			// The one encoding that writes the decoded value as it is stored.
			$encoder = null;
			foreach ( array( 'plain', 'rawurlencode', 'urlencode' ) as $candidate ) {
				if ( ( 'plain' === $candidate ? $decoded : call_user_func( $candidate, $decoded ) ) === $value ) {
					$encoder = $candidate;
					break;
				}
			}
			if ( $page && 'plain' === $encoder ) {
				continue;
			}
			// What the address inside names, read as any address; without an
			// id in it and without the host of a site, nothing is rewritten.
			$hits = self::hits( $decoded, $inner, $ctx, 0, $depth + 1 );
			if ( array() === $hits && ! ( $inner['own'] && 'host' === $inner['form'] ) ) {
				continue;
			}
			if ( null === $encoder || null === $at ) {
				$out[] = self::lock( (int) $at, strlen( $url ), $what );
				continue;
			}
			foreach ( $hits as $hit ) {
				if ( null === $hit['id'] && ! isset( $hit['enc'] ) ) {
					$out[] = self::lock( (int) $at, strlen( $url ), $what . $hit['what'] );
					continue 2;
				}
			}
			$out[] = array(
				'at'    => $at + $start + (int) $pair[1] + $eq + 1,
				'len'   => strlen( $value ),
				'space' => 'value',
				'id'    => null,
				'what'  => $what,
				'enc'   => array(
					'encoder' => $encoder,
					'text'    => $decoded,
				),
			);
		}
		return $out;
	}

	/**
	 * The candidates of a srcset as the HTML standard parses the attribute
	 * (Codex, round 23, B-2): each an address (a run of characters that are
	 * no white space; a "," only inside it) with its descriptors after
	 * white space up to the next ","; where the address ends with ","
	 * there are none.
	 *
	 * @param string $value The attribute as stored.
	 * @return array<int,array{0:string,1:int}> Each address and where it stands.
	 */
	public static function srcset_candidates( $value ) {
		$value = (string) $value;
		$out   = array();
		$n     = strlen( $value );
		$i     = 0;
		while ( $i < $n ) {
			$i += strspn( $value, " \t\n\r\f,", $i );
			if ( $i >= $n ) {
				break;
			}
			$len = strcspn( $value, " \t\n\r\f", $i );
			$url = (string) substr( $value, $i, $len );
			$at  = $i;
			$i  += $len;
			if ( ',' === substr( $url, -1 ) ) {
				$url = rtrim( $url, ',' );
			} else {
				// The descriptors, to the next ",".
				$end = strpos( $value, ',', $i );
				$i   = false === $end ? $n : $end + 1;
			}
			if ( '' !== $url ) {
				$out[] = array( $url, $at );
			}
		}
		return $out;
	}

	/**
	 * What an address is: its form, where its path and its query stand.
	 *
	 * @param string $url Address.
	 * @param array  $ctx Context.
	 * @return array{form:string,own:bool,safe:bool,path:int,end:int,query:int|null,why?:string}
	 *               form: host (the exact spelling of a site), variant
	 *               (another spelling of its host), other (the other site),
	 *               root (relative to the root), page (relative to the
	 *               page), foreign (a host of no site, another scheme:
	 *               nothing of its own is read, the values of its query
	 *               are; Codex, round 23, A-1), invalid (no address as the
	 *               URL parser reads it: userinfo before the host, "%" in
	 *               it – Codex, round 23, A-5/A-6: locked, why names it);
	 *               own: of the site whose content it is.
	 */
	private static function read( $url, array $ctx ) {
		$lead = $url;
		$form = null;
		$path = 0;
		// The exact spelling of an address of the context (the longest), its
		// path after it; else another spelling of a site's host.
		$exact = false;
		foreach ( isset( $ctx['forms'] ) ? array_keys( $ctx['forms'] ) : array() as $needle ) {
			if ( ( false === $exact || strlen( $needle ) > strlen( $exact ) ) && 1 === preg_match( '#^' . preg_quote( (string) $needle, '#' ) . AB_MCP_Sync_Canon::BOUNDARY . '#', $lead ) ) {
				$exact = (string) $needle;
			}
		}
		$own = true;
		$why = null;
		if ( preg_match( self::HOST_AT_START, $lead, $m ) && preg_match( '/[@%]/', $m[2], $bad ) ) {
			// Userinfo before the host, or an escape in it: the URL parser
			// reads another host than the text shows.
			$form = 'invalid';
			$why  = '@' === $bad[0] ? ':userinfo' : ':host';
			$path = strlen( $m[0] );
		} elseif ( false !== $exact ) {
			$own  = ! isset( $ctx['owners'][ $exact ] ) || $ctx['owners'][ $exact ];
			$form = $own ? 'host' : 'other';
			$path = strlen( $exact );
		} elseif ( preg_match( self::HOST_AT_START, $lead, $m ) ) {
			$site = self::site( $m[2], strtolower( $m[1] ), $ctx );
			if ( null === $site ) {
				$own  = false;
				$form = 'foreign';
				$path = strlen( $m[0] );
			} else {
				// The folder of the site after the host, as after the exact
				// form; outside it, the rules of the site do not route the
				// path.
				$under = self::under( $site, $lead, strlen( $m[0] ) );
				$own   = $site['own'] && null !== $under;
				$form  = 'variant';
				$path  = null === $under ? strlen( $m[0] ) : $under;
			}
		} elseif ( preg_match( '#^[A-Za-z][A-Za-z0-9+.\-]*:#', $lead, $m ) ) {
			$own  = false;
			$form = 'foreign';
			$path = strlen( $m[0] );
		} elseif ( 0 === strpos( $lead, '/' ) ) {
			foreach ( isset( $ctx['sites'] ) ? $ctx['sites'] : array() as $site ) {
				$under = self::under( $site, $lead, 0 );
				if ( null !== $under ) {
					$form = 'root';
					$path = $under;
					break;
				}
			}
			if ( null === $form ) {
				$own  = false;
				$form = 'foreign';
			}
		} else {
			$form = 'page';
		}
		$hash = preg_match( '/(?<!&)#/', $url, $h, PREG_OFFSET_CAPTURE, $path ) ? (int) $h[0][1] : strlen( $url );
		$mark = strpos( substr( $url, 0, $hash ), '?', $path );
		return array(
			'form'  => $form,
			'own'   => $own,
			// Rewritten only with the host, or from a root both sites share.
			'safe'  => 'host' === $form || ( 'root' === $form && ! empty( $ctx['one_root'] ) ),
			'path'  => $path,
			'end'   => false === $mark ? $hash : $mark,
			'query' => false === $mark ? null : $mark + 1,
			'why'   => $why,
		);
	}

	/**
	 * Where the path of a site ends in an address, at an offset: after the
	 * folder of the site (its path in the context) where the address goes
	 * on there with "/", "?", "#" or ends; null where it is outside it.
	 *
	 * @param array  $site Of the context.
	 * @param string $url  Address.
	 * @param int    $at   Offset of the path.
	 * @return int|null
	 */
	private static function under( array $site, $url, $at ) {
		return 1 === preg_match( '#^' . preg_quote( $site['path'], '#' ) . '(?:[/?\#]|\z)#', (string) substr( $url, $at ) ) ? $at + strlen( $site['path'] ) : null;
	}

	/**
	 * The site of a host as an address spells it, or null: compared in lower
	 * case, without the default port of its scheme and, as url_to_postid()
	 * compares hosts, without "www.".
	 *
	 * @param string $host   Host with its port, as written.
	 * @param string $scheme http, https or '' (without one).
	 * @param array  $ctx    Context.
	 * @return array|null
	 */
	private static function site( $host, $scheme, array $ctx ) {
		foreach ( isset( $ctx['sites'] ) ? $ctx['sites'] : array() as $site ) {
			if ( self::bare_host( $host, $scheme ) === self::bare_host( $site['host'], $site['scheme'] ) ) {
				return $site;
			}
		}
		return null;
	}

	/**
	 * @param string $host   Host with its port.
	 * @param string $scheme http, https or '' (both default ports).
	 * @return string In lower case, without the default port and "www.".
	 */
	private static function bare_host( $host, $scheme ) {
		$host = strtolower( $host );
		if ( preg_match( '/^(.*):([0-9]+)\z/', $host, $m ) && ( ( 'https' !== $scheme && '80' === $m[2] ) || ( 'http' !== $scheme && '443' === $m[2] ) ) ) {
			$host = $m[1];
		}
		return (string) preg_replace( '/^www\./', '', $host );
	}

	/**
	 * The ids of the query of an address as the core reads it: every
	 * reading the stored form may have (as stored, decoded as HTML by
	 * WP_HTML_Decoder, decoded as JSON) through parse_str(). Rewritten only
	 * where every reading names the same variables of QUERY_IDS, each from
	 * one pair stored exactly as name=value with the value of its ids in
	 * their spelling (AB_MCP_Sync_Canon::id_spelling(), a list at commas).
	 *
	 * @param string   $url   Address.
	 * @param int|null $start Where its query begins in it, or null.
	 * @param int|null $at    Where the address stands in the text, where it is
	 *                        rewritten; null where it is not.
	 * @return array See ids().
	 */
	private static function query_ids( $url, $start, $at ) {
		$readings = self::readings( $url );
		if ( null === $readings ) {
			return array( self::lock( (int) $at, strlen( $url ), '?' ) );
		}
		$named = array();
		foreach ( $readings as $reading ) {
			// The query of the URL standard: after the first "?" before the
			// first "#" (a fragment holds none).
			$hash = strpos( $reading, '#' );
			$head = false === $hash ? $reading : (string) substr( $reading, 0, $hash );
			$q    = strpos( $head, '?' );
			if ( false === $q ) {
				continue;
			}
			parse_str( (string) substr( $head, $q + 1 ), $vars );
			foreach ( $vars as $name => $value ) {
				// 0, -0 and empty name no object (?cat=0, ?author=, ?p=0).
				if ( isset( self::QUERY_IDS[ $name ] ) && ! self::no_object( (string) $name, $value ) ) {
					$named[ $name ] = true;
				}
			}
		}
		if ( array() === $named ) {
			return array();
		}
		$out = array();
		foreach ( array_keys( $named ) as $var ) {
			$pair = null === $start || null === $at ? null : self::exact_pair( $url, $start, $var );
			if ( null === $pair ) {
				$out[] = self::lock( (int) $at, strlen( $url ), '?' . $var );
				continue;
			}
			foreach ( $pair['ids'] as $token ) {
				$out[] = array(
					'at'    => $at + $pair['at'] + $token['at'],
					'len'   => $token['len'],
					'space' => self::QUERY_IDS[ $var ],
					'id'    => $token['id'],
					'what'  => '?' . $var,
				);
			}
		}
		return $out;
	}

	/**
	 * Whether a value of a variable of QUERY_IDS names no object: 0, -0,
	 * empty (AB_MCP_Sync_Canon::id_spelling() 0), for a list every item.
	 *
	 * @param string $var   Variable.
	 * @param mixed  $value Its value as parse_str() reads it.
	 * @return bool
	 */
	private static function no_object( $var, $value ) {
		if ( ! is_string( $value ) ) {
			return false;
		}
		return in_array( $var, self::QUERY_LISTS, true ) ? array() === AB_MCP_Sync_Canon::id_tokens( $value, '/,/' ) : 0 === AB_MCP_Sync_Canon::id_spelling( $value );
	}

	/**
	 * The one pair of a query stored exactly as var=value: of the pairs as
	 * stored (SEPARATOR), the only one parse_str() reads as $var in every
	 * reading, its name exactly $var, its value the ids in their spelling.
	 *
	 * @param string $url   Address.
	 * @param int    $start Where its query begins.
	 * @param string $var   Variable.
	 * @return array{at:int,ids:array}|null
	 */
	private static function exact_pair( $url, $start, $var ) {
		$hash  = preg_match( '/(?<!&)#/', $url, $h, PREG_OFFSET_CAPTURE, $start ) ? (int) $h[0][1] : strlen( $url );
		$found = null;
		$count = 0;
		foreach ( preg_split( self::SEPARATOR, (string) substr( $url, $start, $hash - $start ), -1, PREG_SPLIT_OFFSET_CAPTURE ) as $pair ) {
			$readings = self::readings( $pair[0] );
			$names    = false;
			foreach ( null === $readings ? array( $pair[0] ) : $readings as $reading ) {
				parse_str( $reading, $vars );
				$names = $names || array_key_exists( $var, $vars );
			}
			if ( ! $names ) {
				continue;
			}
			++$count;
			$found = $pair;
		}
		if ( 1 !== $count || 0 !== strpos( $found[0], $var . '=' ) ) {
			return null;
		}
		$value = (string) substr( $found[0], strlen( $var ) + 1 );
		$id    = AB_MCP_Sync_Canon::id_spelling( $value );
		// 0, -0 and empty name no object (?cat=0, ?author=): no ids, free.
		$ids = in_array( $var, self::QUERY_LISTS, true ) ? AB_MCP_Sync_Canon::id_tokens( $value, '/,/' ) : ( 0 === $id ? array() : array(
			array(
				'at'  => 0,
				'len' => strlen( $value ),
				'id'  => $id,
			),
		) );
		// One id of the pair in another spelling: none of it is rewritten.
		if ( in_array( null, array_column( $ids, 'id' ), true ) ) {
			return null;
		}
		foreach ( $ids as $i => $token ) {
			$ids[ $i ]['at'] += strlen( $var ) + 1;
		}
		return array(
			'at'  => $start + (int) $found[1],
			'ids' => $ids,
		);
	}

	/**
	 * A text with the escapes of JSON for ASCII decoded (\/, \uXXXX below
	 * 0x80), as JSON.parse() and json_decode() read them; a layer of the
	 * net over the raw value as well (AB_MCP_Sync_Net).
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function json_unescaped( $text ) {
		return (string) preg_replace_callback(
			'/\\\\u([0-9a-fA-F]{4})/',
			static function ( $m ) {
				$code = hexdec( $m[1] );
				return $code < 0x80 ? chr( $code ) : $m[0];
			},
			str_replace( '\\/', '/', (string) $text )
		);
	}

	/**
	 * The readings of a stored text: as stored, as JSON decodes it, as HTML
	 * decodes it (WP_HTML_Decoder::decode_text_node() of the core, which
	 * decodes at least what an attribute decodes); null where it holds a
	 * character reference and the decoder of the core is not there.
	 *
	 * @param string $text Text.
	 * @return string[]|null
	 */
	private static function readings( $text ) {
		$json = self::json_unescaped( $text );
		$out  = array( $text, $json );
		if ( false !== strpos( $json, '&' ) ) {
			if ( ! class_exists( 'WP_HTML_Decoder' ) ) {
				return null;
			}
			// As a text decodes: an attribute decodes no more (a reference
			// without ";" before a letter or "=" stays there).
			$out[] = WP_HTML_Decoder::decode_text_node( $json );
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * The ids the path of an address names, as the rewrite rules of the site
	 * whose content it is route it (ctx routes, routes()): where a rule puts
	 * digits of the path into a variable of QUERY_IDS (p, page_id …) they
	 * name that object; in any other variable (page, paged, year …) they
	 * name none. Rewritten only as stored in a plain path (no dot segments,
	 * no double slashes, no percent escapes, the rule matched as stored);
	 * where the routes are not known (another site, the rules not read) a
	 * segment of digits locks, as stored or decoded. An address relative to
	 * the page: page_ids().
	 *
	 * @param string   $url  Address.
	 * @param array    $read read().
	 * @param array    $ctx  Context.
	 * @param int|null $at   Where it stands in the text, where it is
	 *                       rewritten; null where it is not.
	 * @return array See ids().
	 */
	private static function path_ids( $url, array $read, array $ctx, $at ) {
		$path  = (string) substr( $url, $read['path'], $read['end'] - $read['path'] );
		// As JSON (in a script, a block attribute stored raw) gives it.
		$plain = self::json_unescaped( $path );
		// An encoded dot, slash or backslash makes other segments of it (the
		// browser and WP::parse_request() decode it): with a digit, it locks.
		if ( preg_match( '/%(?:2e|2f|5c)/i', $plain ) ) {
			return preg_match( '/[1-9]/', rawurldecode( $plain ) ) ? array( self::lock( (int) $at, strlen( $url ), '%post_id%' ) ) : array();
		}
		if ( 'page' === $read['form'] ) {
			return self::page_ids( $plain, $at, strlen( $url ) );
		}
		// Without a digit the path names no id: the rules are not searched.
		if ( ! preg_match( '/[0-9]/', rawurldecode( $plain ) ) ) {
			return array();
		}
		$routes = $read['own'] && 'other' !== $read['form'] && isset( $ctx['routes'] ) && is_array( $ctx['routes'] ) ? $ctx['routes'] : null;
		$normal = self::normal_path( $plain );
		$route  = null === $routes ? null : self::route( $routes, $normal );
		if ( null === $route ) {
			return preg_match( '#(?:^|/)0*[1-9][0-9]*(?:/|\z)#', rawurldecode( $plain ) ) ? array( self::lock( (int) $at, strlen( $url ), '%post_id%' ) ) : array();
		}
		// As stored: the rule matched the stored path, no dot segments, no
		// double slashes, no escapes.
		$stored = null !== $at && $route['stored'] && $normal === $path && ! preg_match( '#//|%#', $path );
		$out    = array();
		foreach ( $route['ids'] as $hit ) {
			// rule_ids() left out what names no object (0); an id in another
			// spelling (null) locks as well.
			$id = AB_MCP_Sync_Canon::id_spelling( $hit['raw'] );
			$out[] = ! $stored ? self::lock( (int) $at, strlen( $url ), '%post_id%' ) : array(
				'at'    => $at + $read['path'] + $hit['at'],
				'len'   => $hit['len'],
				'space' => self::QUERY_IDS[ $hit['var'] ],
				'id'    => $id,
				'what'  => '%post_id%',
			);
		}
		return $out;
	}

	/**
	 * The path of an address relative to the page: it resolves against the
	 * address of the page, which no rule tells here. A file (its last
	 * segment with an extension: photo2.jpg, an upload) names no object; a
	 * segment of digits, as stored or decoded (../12/), locks; else none.
	 *
	 * @param string   $path Path.
	 * @param int|null $at   Where the address stands.
	 * @param int      $len  Its length.
	 * @return array See ids().
	 */
	private static function page_ids( $path, $at, $len ) {
		$segments = array_values( array_filter( explode( '/', $path ), 'strlen' ) );
		if ( array() === $segments || preg_match( '/\.[A-Za-z0-9]+\z/', rawurldecode( (string) end( $segments ) ) ) ) {
			return array();
		}
		foreach ( $segments as $segment ) {
			if ( preg_match( '/^0*[1-9][0-9]*\z/', rawurldecode( $segment ) ) ) {
				return array( self::lock( (int) $at, $len, '%post_id%' ) );
			}
		}
		return array();
	}

	/**
	 * The rewrite rules of this site from its rows, for route(): the row
	 * rewrite_rules unpacked without classes (AB_MCP_Sync_Canon::
	 * unserialized(); an object in it: not read), and of the row
	 * permalink_structure whether the rules of pages are verbose and whether
	 * the permalinks hold index.php (WP_Rewrite::init(),
	 * using_index_permalinks()), and the reader of the pages of a name
	 * (AB_MCP_Sync_Rows::page_path()). Plain permalinks (the structure
	 * empty): no rule routes a path. Null where they cannot be read: a
	 * segment of digits locks.
	 *
	 * @return array{rules:array<string,string>,verbose:bool,index:bool,pages:callable}|null
	 */
	public static function routes() {
		$structure = AB_MCP_Sync_Rows::option( 'permalink_structure' );
		if ( null === $structure ) {
			return null;
		}
		$structure = is_array( $structure ) ? (string) $structure['value'] : '';
		if ( '' === $structure ) {
			return array(
				'rules'   => array(),
				'verbose' => false,
				'index'   => false,
				'pages'   => array( 'AB_MCP_Sync_Rows', 'page_path' ),
			);
		}
		$row  = AB_MCP_Sync_Rows::option( 'rewrite_rules' );
		$read = is_array( $row ) ? AB_MCP_Sync_Canon::unserialized( $row['value'] ) : null;
		if ( null === $read || ! is_array( $read['value'] ) || array() === $read['value'] ) {
			return null;
		}
		foreach ( $read['value'] as $match => $query ) {
			if ( ! is_string( $match ) || ! is_string( $query ) ) {
				return null;
			}
		}
		return array(
			'rules'   => $read['value'],
			'verbose' => 1 === preg_match( '/^[^%]*%(?:postname|category|tag|author)%/', $structure ),
			'index'   => 1 === preg_match( '#^/*index\.php#', $structure ),
			'pages'   => array( 'AB_MCP_Sync_Rows', 'page_path' ),
		);
	}

	/**
	 * Which variables of QUERY_IDS the rules fill from a path, and with which
	 * digits: the search of url_to_postid() and WP::parse_request() over the
	 * rules, that search alone (no query runs, no page is looked up). The
	 * path without index.php/ where the permalinks do not hold it, trimmed of
	 * its slashes; each rule as "#^rule#" against it, else against it
	 * decoded; the first that matches gives the query (WP_MatchesMapRegex::
	 * apply(), read by parse_str()). A verbose rule of pages holds only
	 * where get_page_by_path() finds the page of its path, of a status
	 * WP::parse_request() takes (routes pages): where there is none the
	 * search goes on, where there is one it ends there; where that is not
	 * known it goes on, and where the rules that may hold put ids in other
	 * places, it locks.
	 *
	 * @param array  $routes routes().
	 * @param string $path   Path, its dot segments resolved.
	 * @return array{ids:array<int,array{var:string,at:int,len:int,raw:string}>,stored:bool}|null
	 *               at: in the path; stored: without index.php/ taken out;
	 *               null: locks.
	 */
	private static function route( array $routes, $path ) {
		if ( ! class_exists( 'WP_MatchesMapRegex' ) ) {
			return null;
		}
		$request = $routes['index'] ? $path : str_replace( 'index.php/', '', $path );
		$stored  = $request === $path;
		$lead    = strlen( $request ) - strlen( ltrim( $request, '/' ) );
		$request = trim( $request, '/' );
		$found   = null;
		foreach ( '' === $request ? array() : $routes['rules'] as $match => $query ) {
			// Decoded only where it holds an escape: then path_ids() locks.
			if ( 1 !== preg_match( '#^' . $match . '#', $request, $m, PREG_OFFSET_CAPTURE ) && 1 !== preg_match( '#^' . $match . '#', urldecode( $request ), $m, PREG_OFFSET_CAPTURE ) ) {
				continue;
			}
			// A verbose rule of pages: where no page has its name the core goes
			// on, where one has it the search ends, where that is not known
			// the rules that may hold are compared.
			$known = true;
			if ( $routes['verbose'] && preg_match( '/pagename=\$matches\[([0-9]+)\]/', $query, $page ) && isset( $m[ (int) $page[1] ] ) ) {
				$named = isset( $routes['pages'] ) && is_callable( $routes['pages'] ) ? call_user_func( $routes['pages'], (string) $m[ (int) $page[1] ][0] ) : null;
				if ( false === $named ) {
					continue;
				}
				$known = true === $named;
			}
			$one = array(
				'ids'    => self::rule_ids( $query, $m, $lead ),
				'stored' => $stored,
			);
			if ( null === $one['ids'] ) {
				return null;
			}
			if ( null !== $found && $found['ids'] !== $one['ids'] ) {
				return null;
			}
			$found = null === $found ? $one : $found;
			if ( $known ) {
				break;
			}
		}
		return null === $found ? array(
			'ids'    => array(),
			'stored' => $stored,
		) : $found;
	}

	/**
	 * The variables of QUERY_IDS a matched rule fills, each from the group
	 * whose digits it takes.
	 *
	 * @param string $query The query of the rule.
	 * @param array  $m     Its match, with offsets.
	 * @param int    $lead  The slashes trimmed before the request.
	 * @return array|null Null where a variable of QUERY_IDS takes no group
	 *                    of the path (a fixed id of a rule).
	 */
	private static function rule_ids( $query, array $m, $lead ) {
		$query = (string) preg_replace( '!^.+\?!', '', $query );
		parse_str( WP_MatchesMapRegex::apply( $query, array_column( $m, 0 ) ), $vars );
		// The last pair of each name gives its value (parse_str()).
		$last = array();
		foreach ( explode( '&', $query ) as $pair ) {
			parse_str( $pair, $one );
			foreach ( array_keys( $one ) as $name ) {
				$last[ $name ] = (string) substr( $pair, strpos( $pair, '=' ) + 1 );
			}
		}
		$out = array();
		foreach ( $vars as $var => $value ) {
			if ( ! isset( self::QUERY_IDS[ $var ] ) || self::no_object( (string) $var, $value ) ) {
				continue;
			}
			// The value from exactly one group of the path, else a fixed id.
			if ( ! isset( $last[ $var ] ) || ! preg_match( '/^\$matches\[([0-9]+)\]\z/', $last[ $var ], $group ) || ! isset( $m[ (int) $group[1] ] ) ) {
				return null;
			}
			$group = $m[ (int) $group[1] ];
			$out[] = array(
				'var' => (string) $var,
				'at'  => $lead + (int) $group[1],
				'len' => strlen( (string) $group[0] ),
				'raw' => (string) $group[0],
			);
		}
		return $out;
	}


	/**
	 * A path with its dot segments removed, as the browser resolves it
	 * (RFC 3986, 5.2.4).
	 *
	 * @param string $path Path.
	 * @return string
	 */
	private static function normal_path( $path ) {
		$out = array();
		foreach ( explode( '/', $path ) as $segment ) {
			if ( '..' === $segment ) {
				array_pop( $out );
			} elseif ( '.' !== $segment ) {
				$out[] = $segment;
			}
		}
		return implode( '/', $out );
	}

	/**
	 * Whether a value of an attribute of a block or a shortcode (or of JSON)
	 * is an address (the plan, round 9): by its name (url, href, link, src,
	 * or a name that ends in Url, Href, Link, Src) or by its beginning
	 * (http:, https:, //, /, ?, ./, ../).
	 *
	 * @param string $name  Name of the attribute.
	 * @param string $value Value.
	 * @return bool
	 */
	public static function names_an_address( $name, $value ) {
		return 1 === preg_match( '/^(?:url|href|link|src)\z|(?:Url|URL|Href|Link|Src)\z/', (string) $name ) || 1 === preg_match( '#^(?:https?:|//|/|\?|\.\.?/)#i', (string) $value );
	}

	/**
	 * Whether a value of an attribute holds an address of the sites (also in
	 * another spelling of a host).
	 *
	 * @param string $value Value.
	 * @param array  $ctx   Context.
	 * @return bool
	 */
	private static function names_a_site( $value, array $ctx ) {
		if ( ! preg_match_all( self::HOST, $value, $m, PREG_SET_ORDER ) ) {
			return false;
		}
		foreach ( $m as $host ) {
			if ( null !== self::site( $host[2], strtolower( $host[1] ), $ctx ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param int   $at    Offset.
	 * @param array $spans Spans.
	 * @return bool Whether it lies in one.
	 */
	private static function inside( $at, array $spans ) {
		foreach ( $spans as $span ) {
			if ( $at >= $span[0] && $at < $span[1] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param int   $at        Offset.
	 * @param array $addresses address_cuts().
	 * @return bool Whether an address of the context stands there, or covers it.
	 */
	private static function at_cut( $at, array $addresses ) {
		foreach ( $addresses as $address ) {
			if ( $at >= $address['at'] && $at < $address['at'] + $address['len'] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * A place that locks.
	 *
	 * @param int    $at   Offset.
	 * @param int    $len  Length.
	 * @param string $what What.
	 * @return array
	 */
	private static function lock( $at, $len, $what ) {
		return array(
			'at'    => (int) $at,
			'len'   => (int) $len,
			'space' => 'post',
			'id'    => null,
			'what'  => $what,
		);
	}
}

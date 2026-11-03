<?php
/**
 * The addresses of the sites that name an object by its id (Z8, decision
 * 12; the line of the plan: an unsafe way is locked where it shows). One
 * rule for every place an address stands: rewritten is only what is on a
 * short list of safe forms; everything else that can be a reference by id
 * locks the object (*:reference_spelling).
 *
 * Found: every address of the sites in a text, with its host (address_cuts()
 * of AB_MCP_Sync_Canon, also without the scheme), and without it where an
 * address stands (the value of an attribute of HTML that holds one,
 * URL_ATTRIBUTE; the whole text where it is one: an attribute of a block,
 * _menu_item_url). Whether it names an object by id the core decides: a
 * query variable WP_Query reads as an id (QUERY_IDS), or the %post_id% of
 * the permalinks of the site whose content it is, in the path.
 *
 * Safe, so rewritten: an address with its host (the host is replaced with
 * it) or from the root of a site whose sites all share that root; the query
 * variable stored exactly by its name, its value exactly the digits of an
 * id (AB_MCP_Sync_Canon::id_spelling(); a list as cat=5,6, without spaces),
 * followed by a separator of the query or the end of the address; the id of
 * the path in the raw digits where the structure puts it, and not in a
 * place a date archive takes first. Everything else that names or may name
 * an id locks: an address relative to the page, a character reference or a
 * percent escape in the name or value, a value of another spelling, a
 * structure that could not be read, an address of the other site.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

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
	 * An attribute of HTML (or of a shortcode) whose value is an address the
	 * browser resolves against the page, with the offset of its value.
	 */
	const URL_ATTRIBUTE = '/(?<![A-Za-z0-9_:\-])(?:href|src|action|formaction|cite|poster|data|background|url|link)\s*=\s*(?|"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+))/i';

	/** A quoted value of an attribute, with its offset. */
	const QUOTED = '/[A-Za-z_:][A-Za-z0-9_:.\-]*\s*=\s*(?|"([^"]*)"|\'([^\']*)\')/';

	/** The separators of a query in the forms of an address: &, &amp;, &#38;, &#x26;, \u0026 of JSON. */
	const SEPARATOR = '/&amp;|&#0*38;|&#x0*26;|\\\\u0026|&(?!#|[A-Za-z0-9]+;)/i';

	/** The patterns of the tags of a permalink structure (WP_Rewrite::$rewritereplace, class-wp-rewrite.php:281-313). */
	const TAGS = array(
		'%year%'     => '[0-9]{4}',
		'%monthnum%' => '[0-9]{1,2}',
		'%day%'      => '[0-9]{1,2}',
		'%hour%'     => '[0-9]{1,2}',
		'%minute%'   => '[0-9]{1,2}',
		'%second%'   => '[0-9]{1,2}',
		'%post_id%'  => '([0-9]+)',
	);

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
		// An address in a quoted value ends with the value (a space inside it
		// is part of it); in prose at the first space.
		$spans = array();
		if ( preg_match_all( self::QUOTED, $text, $q, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $q[1] as $value ) {
				$spans[] = array( (int) $value[1], (int) $value[1] + strlen( $value[0] ) );
			}
		}
		$out = array();
		foreach ( $addresses as $address ) {
			$needle = substr( $text, $address['at'], $address['len'] );
			$own    = ! isset( $ctx['owners'][ $needle ] ) || $ctx['owners'][ $needle ];
			$end    = $whole ? strlen( $text ) : null;
			foreach ( $spans as $span ) {
				if ( $address['at'] >= $span[0] && $address['at'] < $span[1] ) {
					$end = $span[1];
				}
			}
			$out = array_merge( $out, self::url_ids( $text, $address['at'] + $address['len'], $end, $own ? 'host' : 'other', $ctx ) );
		}
		$values = $whole ? array( array( $text, 0 ) ) : array();
		if ( preg_match_all( self::URL_ATTRIBUTE, $text, $m, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $m[1] as $value ) {
				$values[] = array( $value[0], (int) $value[1] );
			}
		}
		foreach ( $values as $value ) {
			// The browser drops the spaces before an address.
			$at   = $value[1] + strlen( $value[0] ) - strlen( ltrim( $value[0] ) );
			$form = self::relative_form( ltrim( $value[0] ), $ctx );
			if ( null !== $form ) {
				$out = array_merge( $out, self::url_ids( $text, $at, $value[1] + strlen( $value[0] ), $form, $ctx ) );
			}
		}
		return $out;
	}

	/**
	 * The form of an address without its host: root (from the root of a
	 * site, "/…" under its path), page (relative to the page: "?p=", "./",
	 * "index.php"), or null where it is no address of the sites without its
	 * host (with a scheme or "//": address_cuts() reads the sites' own).
	 *
	 * @param string $value Value, as stored.
	 * @param array  $ctx   Context.
	 * @return string|null
	 */
	private static function relative_form( $value, array $ctx ) {
		if ( preg_match( '#^[A-Za-z][A-Za-z0-9+.\-]*:#', $value ) || 0 === strpos( $value, '//' ) ) {
			return null;
		}
		if ( 0 !== strpos( $value, '/' ) ) {
			return 'page';
		}
		foreach ( isset( $ctx['sites'] ) ? $ctx['sites'] : array() as $site ) {
			if ( 1 === preg_match( '#^' . preg_quote( $site['path'], '#' ) . '(?:[/?]|\z)#', $value ) ) {
				return 'root:' . $site['path'];
			}
		}
		return null;
	}

	/**
	 * The ids of one address, from where its path begins.
	 *
	 * @param string   $text  Text.
	 * @param int      $start Where the path begins (after the host and the
	 *                        path of the site, or the value of an address
	 *                        without its host).
	 * @param int|null $end   Where the address ends (the end of its value);
	 *                        null in prose: at the first character that
	 *                        ends an address, without the punctuation of a
	 *                        sentence.
	 * @param string   $form  host, other (an address of the other site),
	 *                        page, root:<path of the site>.
	 * @param array    $ctx   Context.
	 * @return array See ids().
	 */
	private static function url_ids( $text, $start, $end, $form, array $ctx ) {
		if ( null === $end ) {
			preg_match( '#\G(?:\\\\/|\\\\u[0-9a-fA-F]{4}|[^\s"\'<>\\\\])*#', $text, $m, 0, $start );
			$url = (string) preg_replace( '/[.;:!)\]]+\z/', '', $m[0] );
		} else {
			$url = (string) substr( $text, $start, $end - $start );
		}
		// The fragment: a "#" that begins no character reference.
		$url  = preg_match( '/(?<!&)#/', $url, $hash, PREG_OFFSET_CAPTURE ) ? substr( $url, 0, $hash[0][1] ) : $url;
		$mark = strpos( $url, '?' );
		$path = false === $mark ? $url : substr( $url, 0, $mark );
		$root = 0 === strpos( $form, 'root:' );
		// Rewritten only with the host, or from a root all sites share.
		$safe = 'host' === $form || ( $root && ! empty( $ctx['one_root'] ) );
		$out  = false === $mark ? array() : self::query_ids( (string) substr( $url, $mark + 1 ), $start + $mark + 1, $safe );
		// Decoded (character references, \u of JSON), the address may name
		// more ids than as stored ("&#63;p=12"): then it locks.
		if ( self::id_pairs( self::decoded( $url ) ) > self::id_pairs( $url ) ) {
			$out[] = array(
				'at'    => $start,
				'len'   => strlen( $url ),
				'space' => 'post',
				'id'    => null,
				'what'  => '?',
			);
		}
		$rel  = $root ? (string) substr( $path, strlen( substr( $form, 5 ) ) ) : $path;
		// The permalinks of the other site: not known here.
		$ctx = 'other' === $form ? array( 'permalink' => null ) + $ctx : $ctx;
		return array_merge( $out, self::path_ids( $rel, $start + strlen( $path ) - strlen( $rel ), 'page' === $form || 'other' === $form ? false : $safe, 'page' === $form, $ctx ) );
	}

	/**
	 * The ids of a query (QUERY_IDS): each variable, in any spelling the core
	 * decodes, names ids; stored exactly by its name, its value each id in
	 * the spelling of AB_MCP_Sync_Canon::id_spelling() (a list split at
	 * commas only), it is rewritten where $safe, else it locks.
	 *
	 * @param string $query The query, as stored (without "?").
	 * @param int    $at    Its offset in the text.
	 * @param bool   $safe  Whether the form of the address is rewritten.
	 * @return array See ids().
	 */
	private static function query_ids( $query, $at, $safe ) {
		$out = array();
		foreach ( preg_split( self::SEPARATOR, $query, -1, PREG_SPLIT_OFFSET_CAPTURE ) as $pair ) {
			$var = self::id_var( $pair[0] );
			if ( null === $var ) {
				continue;
			}
			$item = array(
				'at'    => $at + (int) $pair[1],
				'len'   => strlen( $pair[0] ),
				'space' => self::QUERY_IDS[ $var ],
				'id'    => null,
				'what'  => '?' . $var,
			);
			$eq = strpos( $pair[0], '=' );
			if ( ! $safe || false === $eq || substr( $pair[0], 0, $eq ) !== $var ) {
				$out[] = $item;
				continue;
			}
			$value = (string) substr( $pair[0], $eq + 1 );
			$id    = AB_MCP_Sync_Canon::id_spelling( $value );
			$ids   = in_array( $var, self::QUERY_LISTS, true ) ? AB_MCP_Sync_Canon::id_tokens( $value, '/,/' ) : ( 0 === $id ? array() : array(
				array(
					'at'  => 0,
					'len' => strlen( $value ),
					'id'  => $id,
				),
			) );
			// A list only where each of its ids is in its spelling.
			if ( in_array( null, array_column( $ids, 'id' ), true ) ) {
				$out[] = $item;
				continue;
			}
			foreach ( $ids as $token ) {
				$out[] = array(
					'at'  => $item['at'] + strlen( $var ) + 1 + $token['at'],
					'len' => $token['len'],
					'id'  => $token['id'],
				) + $item;
			}
		}
		return $out;
	}

	/**
	 * How many pairs of the query of an address name a variable of
	 * QUERY_IDS (id_var()).
	 *
	 * @param string $url Address, from its path on.
	 * @return int
	 */
	private static function id_pairs( $url ) {
		$mark = strpos( $url, '?' );
		if ( false === $mark ) {
			return 0;
		}
		$n = 0;
		foreach ( preg_split( self::SEPARATOR, (string) substr( $url, $mark + 1 ) ) as $pair ) {
			$n += null === self::id_var( $pair ) ? 0 : 1;
		}
		return $n;
	}

	/**
	 * An address as the browser and JSON decode it: character references
	 * and \u escapes, a slash of JSON.
	 *
	 * @param string $url Address.
	 * @return string
	 */
	private static function decoded( $url ) {
		$json = (string) preg_replace_callback(
			'/\\\\u([0-9a-fA-F]{4})/',
			static function ( $m ) {
				$code = hexdec( $m[1] );
				return $code < 0x80 ? chr( $code ) : $m[0];
			},
			str_replace( '\\/', '/', $url )
		);
		return html_entity_decode( $json, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	/**
	 * The variable of QUERY_IDS a pair of a query names, in any spelling the
	 * core decodes (character references, percent escapes, \u of JSON, the
	 * brackets of a list, spaces before), or null.
	 *
	 * @param string $pair The pair, as stored.
	 * @return string|null
	 */
	private static function id_var( $pair ) {
		$html = html_entity_decode( $pair, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		foreach ( array( $pair, $html, rawurldecode( $pair ), rawurldecode( $html ), json_decode( '"' . str_replace( '"', '', $pair ) . '"' ) ) as $read ) {
			$name = ltrim( (string) preg_replace( '/[=\[].*\z/s', '', (string) $read ) );
			if ( isset( self::QUERY_IDS[ $name ] ) ) {
				return $name;
			}
		}
		return null;
	}

	/**
	 * The id of the path of an address by the permalinks of the site
	 * (%post_id%, as the core reads it), from the root of the site.
	 *
	 * @param string $path The path, as stored, from the root of the site.
	 * @param int    $at   Its offset in the text.
	 * @param bool   $safe Whether the form of the address is rewritten.
	 * @param bool   $page The path is relative to the page.
	 * @param array  $ctx  Context: permalink, the structure of the site
	 *                     (null or missing where it is not known).
	 * @return array See ids().
	 */
	private static function path_ids( $path, $at, $safe, $page, array $ctx ) {
		$structure = isset( $ctx['permalink'] ) ? $ctx['permalink'] : null;
		$lock      = array(
			array(
				'at'    => $at,
				'len'   => strlen( $path ),
				'space' => 'post',
				'id'    => null,
				'what'  => '%post_id%',
			),
		);
		$decoded   = array_unique( array( $path, rawurldecode( $path ), html_entity_decode( $path, ENT_QUOTES | ENT_HTML5, 'UTF-8' ), str_replace( '\\/', '/', $path ) ) );
		if ( null === $structure ) {
			// Which digits the permalinks read, nothing tells: a segment of digits locks.
			foreach ( $decoded as $read ) {
				if ( preg_match( '#(?:^|/)[0-9]+(?:/|\z)#', $read ) ) {
					return $lock;
				}
			}
			return array();
		}
		if ( false === strpos( (string) $structure, '%post_id%' ) ) {
			return array();
		}
		$pattern = '#^/?' . self::structure_pattern( (string) $structure ) . '(?=/|\z)#';
		$found   = false;
		foreach ( $decoded as $read ) {
			$found = $found || ( $page ? 1 === preg_match( '#[0-9]#', $read ) : 1 === preg_match( $pattern, $read ) );
		}
		if ( ! $found ) {
			return array();
		}
		// Rewritten only in the raw digits, and not where a date archive
		// (year, month, day) reads them first: its rules come before the
		// posts' (WP_Rewrite::rewrite_rules()).
		if ( ! $safe || 1 !== preg_match( $pattern, $path, $m, PREG_OFFSET_CAPTURE ) || preg_match( '#^/?[0-9]{4}(?:/[0-9]{1,2}){0,2}/?\z#', $m[0][0] ) ) {
			return $lock;
		}
		$id = AB_MCP_Sync_Canon::id_spelling( $m[1][0] );
		return array(
			array(
				'at'    => $at + (int) $m[1][1],
				'len'   => strlen( $m[1][0] ),
				'space' => 'post',
				'id'    => 0 === $id ? null : $id,
				'what'  => '%post_id%',
			),
		);
	}

	/**
	 * A permalink structure as a pattern: the tags of TAGS by theirs, every
	 * other tag one segment, the rest as it stands.
	 *
	 * @param string $structure Structure, as stored.
	 * @return string
	 */
	private static function structure_pattern( $structure ) {
		$out = '';
		foreach ( preg_split( '/(%[A-Za-z0-9_-]+%)/', trim( $structure, '/' ), -1, PREG_SPLIT_DELIM_CAPTURE ) as $part ) {
			if ( isset( self::TAGS[ $part ] ) ) {
				$out .= self::TAGS[ $part ];
			} elseif ( preg_match( '/^%[A-Za-z0-9_-]+%\z/', $part ) ) {
				$out .= '[^/]+';
			} else {
				$out .= preg_quote( $part, '#' );
			}
		}
		return $out;
	}
}

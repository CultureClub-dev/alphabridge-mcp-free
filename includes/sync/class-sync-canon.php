<?php
/**
 * The canonical form canon/1 (Z8, decision 11 of the plan of 01.11.2026):
 * what the two sides of a takeover compare, built from the rows
 * (AB_MCP_Sync_Rows), hashed with SHA-256.
 *
 * THE RULES OF canon/1:
 * - Built from the rows, never through the filtered readers of WordPress.
 * - Without what WordPress writes by itself on each site: the columns and
 *   the meta, options and post types of AB_MCP_Sync_Exclusions (ids, guid,
 *   times of the last change, counters, the pingback queue, revisions,
 *   former slugs and dates). The kinds leave them out (AB_MCP_Sync_Db_Kinds).
 * - References as typed markers: {"~ref":"post","id":12} in the ID space of
 *   the site, {"~ref":"term","id":5}, an author as {"~ref":"user","login":…}
 *   (the same on both sides). mapped() puts the ids of one side into the
 *   ID space of the other, so that the forms of both can be compared.
 * - The addresses of both sites in every form the replace engine knows
 *   (AB_MCP_Replace_Engine::FORMS: plain, json, block_json, html, html_full,
 *   block_json_html) as {"~addr":<form>}, so that a page with a link to its
 *   own site hashes alike on staging and live. A string with markers in it
 *   is {"~s":[text, marker, text, …]}.
 * - Serialized values unpacked, nested ones too (up to MAX_LAYERS), with no
 *   class allowed: {"~php":<value>}. A value that holds an object, a
 *   reference or a record the walk of AB_MCP_Replace_Engine::carries_record()
 *   does not understand, that does not unpack, or that nests deeper than
 *   MAX_DEPTH locks its object (*:php_object, *:unreadable_value): it is
 *   kept as text for the hash and never taken over.
 * - A key of a stored array that begins with "~" gets one more "~", so no
 *   stored value reads as a marker.
 * - JSON with sorted keys (lists keep their order, a {"~set":[…]} is sorted
 *   by its items), floats as {"~f":"…"}, bytes that are not UTF-8 as
 *   {"~b":"<base64>"}; SHA-256 of that JSON.
 * - The version is part of every form: a form of another version never
 *   hashes alike, and version_refusal() refuses to compare it.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__ ) . '/class-replace-engine.php';
require_once __DIR__ . '/class-sync-kinds.php';
require_once __DIR__ . '/class-sync-rows.php';

/**
 * Class AB_MCP_Sync_Canon
 */
final class AB_MCP_Sync_Canon {

	/** The version of the canonical form both sides must compute. */
	const VERSION = 'canon/1';

	/** How deep a stored value may nest (AB_MCP_Replace_Engine::MAX_DEPTH). */
	const MAX_DEPTH = 64;

	/** How many serialized strings inside one another are unpacked (AB_MCP_Replace_Engine::MAX_LAYERS). */
	const MAX_LAYERS = 12;

	/**
	 * What may not follow an address for it to be one: a character that
	 * continues the host or the path segment, a dot or colon followed by one
	 * (a longer host, a port). "https://a.ch" is not the address in
	 * "https://a.chx.com" or "https://a.ch:8080".
	 */
	const BOUNDARY = '(?![A-Za-z0-9_~-]|[.:][A-Za-z0-9])';

	/** @var AB_MCP_Replace_Engine|null For carries_record(). */
	private static $engine = null;

	/* ----------------------------------------------------------- version */

	/**
	 * The refusal to compare with a side of another version.
	 *
	 * @param string $theirs The version the other side computed.
	 * @return WP_Error|null Null when it is this one.
	 */
	public static function version_refusal( $theirs ) {
		if ( self::VERSION === $theirs ) {
			return null;
		}
		return new WP_Error(
			'ab_mcp_sync_canon_version',
			sprintf( 'The other site computes its comparison data as %1$s and this site as %2$s, so the two cannot be compared. Update AlphaBridge MCP on both sites to the same version and try again.', is_string( $theirs ) && '' !== $theirs ? $theirs : 'an unknown version', self::VERSION ),
			array(
				'theirs' => is_string( $theirs ) ? $theirs : null,
				'ours'   => self::VERSION,
			)
		);
	}

	/* ----------------------------------------------------------- context */

	/**
	 * What a canonical form is computed with: the addresses that are neutral
	 * and the reader of user logins.
	 *
	 * @param string[]      $addresses The addresses of both sites (home and
	 *                                 siteurl of each), as stored.
	 * @param callable|null $users     function( int $id ): string|false|null,
	 *                                 the login; AB_MCP_Sync_Rows::user_login()
	 *                                 when null.
	 * @return array
	 */
	public static function context( array $addresses, $users = null ) {
		$forms = array();
		foreach ( $addresses as $address ) {
			$address = rtrim( trim( (string) $address ), '/' );
			if ( ! preg_match( '#^https?://[^/\s]+#i', $address ) ) {
				continue;
			}
			foreach ( AB_MCP_Replace_Engine::needles( $address ) as $needle ) {
				if ( ! isset( $forms[ $needle['needle'] ] ) ) {
					$forms[ $needle['needle'] ] = $needle['form'];
				}
			}
		}
		$needles = array_keys( $forms );
		usort(
			$needles,
			static function ( $a, $b ) {
				return strlen( (string) $b ) - strlen( (string) $a );
			}
		);
		$alts = array();
		foreach ( $needles as $needle ) {
			$alts[] = preg_quote( (string) $needle, '#' );
		}
		return array(
			'pattern' => array() === $alts ? '' : '#(?:' . implode( '|', $alts ) . ')' . self::BOUNDARY . '#',
			'forms'   => $forms,
			'users'   => is_callable( $users ) ? $users : array( 'AB_MCP_Sync_Rows', 'user_login' ),
		);
	}

	/**
	 * The addresses of this site as its rows hold them (home and siteurl),
	 * not as get_option() filters them.
	 *
	 * @return string[]|null Null when a row could not be read.
	 */
	public static function own_addresses() {
		$out = array();
		foreach ( array( 'home', 'siteurl' ) as $name ) {
			$row = AB_MCP_Sync_Rows::option( $name );
			if ( null === $row ) {
				return null;
			}
			if ( is_array( $row ) && '' !== $row['value'] ) {
				$out[] = $row['value'];
			}
		}
		return array_values( array_unique( $out ) );
	}

	/* ------------------------------------------------------- accumulator */

	/**
	 * What the walk over one object collects besides its form.
	 *
	 * @return array refs (typed references found by their schema), possible
	 *               (whole numbers without a schema), locks, unread (what
	 *               could not be read), credentials (decision 9).
	 */
	public static function acc() {
		return array(
			'refs'        => array(),
			'possible'    => array(),
			'locks'       => array(),
			'unread'      => array(),
			'credentials' => array(
				'b9'    => array(),
				'mixed' => array(),
			),
		);
	}

	/**
	 * Note a lock of AB_MCP_Sync_Kinds::LOCKS on the object.
	 *
	 * @param array  $acc  Accumulator.
	 * @param string $kind Kind.
	 * @param string $op   The operation of the lock (such as php_object).
	 * @param string $at   Where in the object.
	 */
	public static function lock( array &$acc, $kind, $op, $at ) {
		$lock = AB_MCP_Sync_Kinds::lock( $kind, $op );
		if ( null === $lock ) {
			return;
		}
		foreach ( $acc['locks'] as $known ) {
			if ( $known['key'] === $lock['key'] && $known['at'] === (string) $at ) {
				return;
			}
		}
		$acc['locks'][] = $lock + array( 'at' => (string) $at );
	}

	/**
	 * The result of one object: its form, hash and what the walk found.
	 *
	 * @param string $kind     Pair kind (post, term, menu, menu_item, …).
	 * @param string $ref      The object in the ID space of this site.
	 * @param array  $identity What identifies it apart from its id.
	 * @param array  $form     Its canonical form, without version and kind.
	 * @param array  $acc      Accumulator.
	 * @return array kind, ref, identity, canon, form, hash, size, refs,
	 *               possible, locks, credentials, complete, unread.
	 */
	public static function finish( $kind, $ref, array $identity, array $form, array $acc ) {
		$form = array(
			'canon' => self::VERSION,
			'kind'  => (string) $kind,
		) + $form;
		$json = self::encode( $form );
		return array(
			'kind'        => (string) $kind,
			'ref'         => (string) $ref,
			'identity'    => $identity,
			'canon'       => self::VERSION,
			'form'        => $form,
			'hash'        => hash( 'sha256', $json ),
			'size'        => strlen( $json ),
			'refs'        => $acc['refs'],
			'possible'    => $acc['possible'],
			'locks'       => $acc['locks'],
			'credentials' => $acc['credentials'],
			'complete'    => array() === $acc['unread'],
			'unread'      => $acc['unread'],
		);
	}

	/* ------------------------------------------------------------ values */

	/**
	 * A reference to an object of this site.
	 *
	 * @param string $space post or term.
	 * @param int    $id    Id.
	 * @return array
	 */
	public static function ref( $space, $id ) {
		return array(
			'~ref' => (string) $space,
			'id'   => (int) $id,
		);
	}

	/**
	 * A reference to a user, by login (the identity of an author on both
	 * sides, decision 25).
	 *
	 * @param int    $id  User id; 0 for none.
	 * @param array  $ctx Context.
	 * @param array  $acc Accumulator.
	 * @param string $at  Where.
	 * @return array|int The marker, or 0 for no user.
	 */
	public static function user( $id, array $ctx, array &$acc, $at ) {
		$id = (int) $id;
		if ( $id <= 0 ) {
			return 0;
		}
		$login = call_user_func( $ctx['users'], $id );
		if ( null === $login ) {
			$acc['unread'][] = (string) $at;
			return array(
				'~ref'   => 'user',
				'unread' => $id,
			);
		}
		$marker        = false === $login ? array(
			'~ref'    => 'user',
			'missing' => $id,
		) : array(
			'~ref'  => 'user',
			'login' => (string) $login,
		);
		$acc['refs'][] = array(
			'space'    => 'user',
			'id'       => $id,
			'login'    => false === $login ? null : (string) $login,
			'at'       => (string) $at,
			'strength' => 'strong',
		);
		return $marker;
	}

	/**
	 * Items whose order does not count.
	 *
	 * @param array $items Items.
	 * @return array
	 */
	public static function set( array $items ) {
		return array( '~set' => array_values( $items ) );
	}

	/**
	 * A text: as it is, or {"~s":[…]} with the addresses neutral and the
	 * given cuts replaced by their markers.
	 *
	 * @param string $text Text.
	 * @param array  $ctx  Context.
	 * @param array  $cuts Cuts of the caller: at (byte offset), len, marker.
	 * @return string|array
	 */
	public static function text( $text, array $ctx, array $cuts = array() ) {
		$text = (string) $text;
		$cuts = array_merge( $cuts, self::address_cuts( $text, $ctx ) );
		if ( array() === $cuts ) {
			return $text;
		}
		usort(
			$cuts,
			static function ( $a, $b ) {
				return array( $a['at'], $b['len'] ) <=> array( $b['at'], $a['len'] );
			}
		);
		$parts = array();
		$pos   = 0;
		foreach ( $cuts as $cut ) {
			if ( $cut['at'] < $pos ) {
				continue;
			}
			if ( $cut['at'] > $pos ) {
				$parts[] = substr( $text, $pos, $cut['at'] - $pos );
			}
			$parts[] = $cut['marker'];
			$pos     = $cut['at'] + $cut['len'];
		}
		if ( $pos < strlen( $text ) ) {
			$parts[] = substr( $text, $pos );
		}
		return array( '~s' => $parts );
	}

	/**
	 * Where the addresses of the context stand in a text.
	 *
	 * @param string $text Text.
	 * @param array  $ctx  Context.
	 * @return array<int,array{at:int,len:int,marker:array,form:string}>
	 */
	public static function address_cuts( $text, array $ctx ) {
		if ( '' === $ctx['pattern'] || '' === (string) $text ) {
			return array();
		}
		if ( ! preg_match_all( $ctx['pattern'], (string) $text, $m, PREG_OFFSET_CAPTURE ) ) {
			return array();
		}
		$out = array();
		foreach ( $m[0] as $hit ) {
			$form  = $ctx['forms'][ $hit[0] ];
			$out[] = array(
				'at'     => (int) $hit[1],
				'len'    => strlen( $hit[0] ),
				'marker' => array( '~addr' => $form ),
				'form'   => $form,
			);
		}
		return $out;
	}

	/**
	 * A stored value (meta, option, a widget's settings): unpacked where it is
	 * serialized, its texts with the addresses neutral. What cannot be
	 * unpacked locks the object and stays text.
	 *
	 * @param string|null $raw   As stored.
	 * @param array       $ctx   Context.
	 * @param array       $acc   Accumulator.
	 * @param string      $kind  Kind, for the locks.
	 * @param string      $at    Where.
	 * @param callable    $leaf  Optional: function( $value, string $path ): mixed|null,
	 *                           the schema of a kind; a non-null answer is the
	 *                           canonical leaf (a reference, for one).
	 * @return mixed
	 */
	public static function value( $raw, array $ctx, array &$acc, $kind, $at, $leaf = null ) {
		if ( null === $raw ) {
			return null;
		}
		return self::unpack( (string) $raw, $ctx, $acc, $kind, (string) $at, '', 0, $leaf );
	}

	/**
	 * A value already unpacked (the settings of a widget instance out of its
	 * option): the walk of value() over it.
	 *
	 * @param mixed         $value Value.
	 * @param array         $ctx   Context.
	 * @param array         $acc   Accumulator.
	 * @param string        $kind  Kind.
	 * @param string        $at    Where.
	 * @param callable|null $leaf  Schema (see value()).
	 * @return mixed
	 */
	public static function structure( $value, array $ctx, array &$acc, $kind, $at, $leaf = null ) {
		return self::walk( $value, $ctx, $acc, (string) $kind, (string) $at, '', 1, 0, $leaf );
	}

	/**
	 * A stored value unpacked, as WordPress reads it (maybe_unserialize()
	 * without classes), or null with the reason when it cannot be.
	 *
	 * @param string $raw As stored.
	 * @return array{ok:bool,value:mixed,why:string} why: '', php_object or unreadable_value.
	 */
	public static function unserialized( $raw ) {
		$raw = (string) $raw;
		if ( ! is_serialized( $raw ) ) {
			return array(
				'ok'    => true,
				'value' => $raw,
				'why'   => '',
			);
		}
		$trimmed = trim( $raw );
		if ( self::engine()->carries_record( $trimmed ) ) {
			return array(
				'ok'    => false,
				'value' => null,
				'why'   => 'php_object',
			);
		}
		// carries_record() walks the format and refuses every object,
		// Serializable, enum and reference record (and anything it does not
		// understand) before unserialize() sees it; no class is allowed besides.
		$value = @unserialize( $trimmed, array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize, WordPress.PHP.NoSilencedErrors.Discouraged -- no class is made; carries_record() refused objects before.
		if ( false === $value && 'b:0;' !== $trimmed ) {
			return array(
				'ok'    => false,
				'value' => null,
				'why'   => 'unreadable_value',
			);
		}
		return array(
			'ok'    => true,
			'value' => $value,
			'why'   => '',
		);
	}

	/* ---------------------------------------------------------- encoding */

	/**
	 * The JSON of a form: keys sorted, sets sorted, floats and bytes tagged.
	 *
	 * @param mixed $form Form.
	 * @return string
	 */
	public static function encode( $form ) {
		$json = json_encode( self::prepared( $form ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION, 4096 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- the same bytes on every site, without WordPress' re-encoding.
		return is_string( $json ) ? $json : '';
	}

	/**
	 * @param mixed $form Form.
	 * @return string SHA-256 of encode().
	 */
	public static function hash( $form ) {
		return hash( 'sha256', self::encode( $form ) );
	}

	/**
	 * A form with the ids of its references put into the ID space of the
	 * other side (decision 10: «beidseitig geändert» compares the mapped
	 * forms). A reference without a counterpart stays apart from every id
	 * of the other side.
	 *
	 * @param mixed $form Form.
	 * @param array $map  space => array( id here => id there ).
	 * @return mixed
	 */
	public static function mapped( $form, array $map ) {
		if ( ! is_array( $form ) ) {
			return $form;
		}
		if ( isset( $form['~ref'], $form['id'] ) && is_string( $form['~ref'] ) ) {
			$space = $form['~ref'];
			$id    = (int) $form['id'];
			unset( $form['id'] );
			if ( isset( $map[ $space ][ $id ] ) && null !== $map[ $space ][ $id ] ) {
				return array( 'id' => (int) $map[ $space ][ $id ] ) + $form;
			}
			return array( 'unmapped' => $id ) + $form;
		}
		foreach ( $form as $key => $item ) {
			$form[ $key ] = self::mapped( $item, $map );
		}
		return $form;
	}

	/* ----------------------------------------------------------- private */

	/**
	 * @param string        $raw   Text, maybe serialized.
	 * @param array         $ctx   Context.
	 * @param array         $acc   Accumulator.
	 * @param string        $kind  Kind.
	 * @param string        $at    Where the value stands.
	 * @param string        $path  Path inside the value.
	 * @param int           $layer Serialized strings unpacked above.
	 * @param callable|null $leaf  Schema.
	 * @return mixed
	 */
	private static function unpack( $raw, array $ctx, array &$acc, $kind, $at, $path, $layer, $leaf ) {
		if ( ! is_serialized( $raw ) ) {
			if ( null !== $leaf ) {
				$hit = call_user_func( $leaf, $raw, $path );
				if ( null !== $hit ) {
					return $hit;
				}
			}
			return self::text( $raw, $ctx );
		}
		if ( $layer >= self::MAX_LAYERS ) {
			self::lock( $acc, $kind, 'unreadable_value', $at );
			return self::text( $raw, $ctx );
		}
		$read = self::unserialized( $raw );
		if ( ! $read['ok'] ) {
			self::lock( $acc, $kind, $read['why'], $at );
			return self::text( $raw, $ctx );
		}
		return array( '~php' => self::walk( $read['value'], $ctx, $acc, $kind, $at, $path, $layer + 1, 0, $leaf ) );
	}

	/**
	 * @param mixed         $value Unpacked value.
	 * @param array         $ctx   Context.
	 * @param array         $acc   Accumulator.
	 * @param string        $kind  Kind.
	 * @param string        $at    Where the value stands.
	 * @param string        $path  Path inside it.
	 * @param int           $layer Serialized strings unpacked above.
	 * @param int           $depth Depth of $value.
	 * @param callable|null $leaf  Schema.
	 * @return mixed
	 */
	private static function walk( $value, array $ctx, array &$acc, $kind, $at, $path, $layer, $depth, $leaf ) {
		if ( $depth > self::MAX_DEPTH ) {
			self::lock( $acc, $kind, 'unreadable_value', $at );
			return null;
		}
		if ( is_string( $value ) ) {
			return self::unpack( $value, $ctx, $acc, $kind, $at, $path, $layer, $leaf );
		}
		if ( null !== $leaf && ! is_array( $value ) ) {
			$hit = call_user_func( $leaf, $value, $path );
			if ( null !== $hit ) {
				return $hit;
			}
		}
		if ( ! is_array( $value ) ) {
			return $value;
		}
		$out = array();
		foreach ( $value as $key => $item ) {
			$name         = is_string( $key ) && '' !== $key && '~' === $key[0] ? '~' . $key : $key;
			$out[ $name ] = self::walk( $item, $ctx, $acc, $kind, $at, '' === $path ? (string) $key : $path . '.' . $key, $layer, $depth + 1, $leaf );
		}
		return $out;
	}

	/**
	 * @param mixed $value Form.
	 * @return mixed What json_encode() writes alike on every site.
	 */
	private static function prepared( $value ) {
		if ( is_string( $value ) ) {
			return 1 === preg_match( '//u', $value ) ? $value : array( '~b' => base64_encode( $value ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- bytes JSON cannot carry.
		}
		if ( is_float( $value ) ) {
			return array( '~f' => var_export( $value, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- the shortest exact form of the float.
		}
		if ( ! is_array( $value ) ) {
			return $value;
		}
		if ( array( '~set' ) === array_keys( $value ) && is_array( $value['~set'] ) ) {
			$items = array();
			foreach ( $value['~set'] as $item ) {
				$prepared = self::prepared( $item );
				$items[]  = array( (string) json_encode( $prepared, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION, 4096 ), $prepared ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- see encode().
			}
			usort(
				$items,
				static function ( $a, $b ) {
					return strcmp( $a[0], $b[0] );
				}
			);
			return array( '~set' => array_column( $items, 1 ) );
		}
		if ( ! array_is_list( $value ) ) {
			ksort( $value, SORT_STRING );
		}
		foreach ( $value as $key => $item ) {
			$value[ $key ] = self::prepared( $item );
		}
		return $value;
	}

	/**
	 * @return AB_MCP_Replace_Engine
	 */
	private static function engine() {
		if ( null === self::$engine ) {
			self::$engine = new AB_MCP_Replace_Engine( 'x', 'x' );
		}
		return self::$engine;
	}
}

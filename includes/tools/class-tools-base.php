<?php
/**
 * Shared helpers for tool groups.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class AB_MCP_Tools_Base
 */
abstract class AB_MCP_Tools_Base {

	/**
	 * String argument.
	 *
	 * @param array  $a   Args.
	 * @param string $k   Key.
	 * @param string $d   Default.
	 * @return string
	 */
	protected static function s( $a, $k, $d = '' ) {
		return isset( $a[ $k ] ) && is_scalar( $a[ $k ] ) ? (string) $a[ $k ] : $d;
	}

	/**
	 * Integer argument.
	 *
	 * @param array  $a Args.
	 * @param string $k Key.
	 * @param int    $d Default.
	 * @return int
	 */
	protected static function i( $a, $k, $d = 0 ) {
		return isset( $a[ $k ] ) ? (int) $a[ $k ] : $d;
	}

	/**
	 * Boolean argument.
	 *
	 * @param array  $a Args.
	 * @param string $k Key.
	 * @param bool   $d Default.
	 * @return bool
	 */
	protected static function b( $a, $k, $d = false ) {
		if ( ! isset( $a[ $k ] ) ) {
			return $d;
		}
		$v = $a[ $k ];
		if ( is_bool( $v ) ) {
			return $v;
		}
		if ( is_string( $v ) ) {
			return in_array( strtolower( $v ), array( '1', 'true', 'yes', 'on' ), true );
		}
		return (bool) $v;
	}

	/**
	 * Array argument.
	 *
	 * @param array  $a Args.
	 * @param string $k Key.
	 * @param array  $d Default.
	 * @return array
	 */
	protected static function arr( $a, $k, $d = array() ) {
		return isset( $a[ $k ] ) && is_array( $a[ $k ] ) ? $a[ $k ] : $d;
	}

	/**
	 * Require keys to be present.
	 *
	 * @param array $a    Args.
	 * @param array $keys Required keys.
	 * @return true|WP_Error
	 */
	protected static function need( $a, array $keys ) {
		$missing = array();
		foreach ( $keys as $k ) {
			if ( ! isset( $a[ $k ] ) || '' === $a[ $k ] ) {
				$missing[] = $k;
			}
		}
		if ( empty( $missing ) ) {
			return true;
		}
		return new WP_Error(
			'ab_mcp_missing_arg',
			sprintf(
				/* translators: %s: comma-separated argument names */
				__( 'Missing required argument(s): %s', 'alphabridge-mcp' ),
				implode( ', ', $missing )
			)
		);
	}

	/**
	 * Clamp an int.
	 *
	 * @param mixed $n   Value.
	 * @param int   $min Min.
	 * @param int   $max Max.
	 * @return int
	 */
	protected static function clamp( $n, $min, $max ) {
		return max( $min, min( $max, (int) $n ) );
	}

	/**
	 * May the current user read terms of this taxonomy? Public taxonomies are
	 * readable (their terms appear on the public site anyway); non-public
	 * taxonomies (internal workflows, shop/membership systems, …) require the
	 * taxonomy's own assign_terms capability.
	 *
	 * @param WP_Taxonomy|false $tax_obj Taxonomy object from get_taxonomy().
	 * @return bool
	 */
	protected static function can_read_taxonomy( $tax_obj ) {
		if ( ! $tax_obj ) {
			return false;
		}
		return ! empty( $tax_obj->public ) || current_user_can( $tax_obj->cap->assign_terms );
	}

	/**
	 * Does a meta key look like a stored credential? Used to keep the meta
	 * tools from reading or writing another plugin's API keys, tokens or
	 * secrets even when such keys are stored under a public (non "_") name.
	 * This is defence-in-depth on top of the "_" prefix and is_protected_meta()
	 * checks, not a replacement for them.
	 *
	 * @param string $key Meta key.
	 * @return bool
	 */
	protected static function is_sensitive_meta_key( $key ) {
		$key = strtolower( trim( (string) $key ) );
		if ( '' === $key ) {
			return false;
		}

		// Bare credential names, matched as the WHOLE key and never as a
		// substring. A key called exactly "token" or "secret" is a credential in
		// practice, but the same word inside a longer name usually is not:
		// matching these as substrings would wrongly refuse token_count,
		// password_hint or credential_type. Plurals are listed explicitly.
		$exact = array(
			'token',
			'tokens',
			'secret',
			'secrets',
			'password',
			'passwords',
			'passphrase',
			'passphrases',
			'passcode',
			'passcodes',
			'pwd',
			'pwds',
			'otp',
			'otps',
			'credential',
			'credentials',
		);
		if ( in_array( $key, $exact, true ) ) {
			return true;
		}

		// High-precision compound credential patterns: these virtually never
		// occur in legitimate content meta, so they avoid false positives on
		// keys like token_count, email_signature, password_hint or
		// credential_type while still catching real API keys and tokens.
		//
		// Matched as written, on purpose. Normalising the key first — removing
		// separators, or treating camelCase as a word boundary — would catch
		// accessToken as well, but it also turns co_author into "coauthor",
		// which contains "oauth". Ordinary fields must not be refused, so the
		// guard stays literal and leaves such spellings to the meta capability
		// check that runs after it.
		$needles = array(
			'api_key',
			'apikey',
			'api-key',
			'api_token',
			'api_secret',
			'access_key',
			'access_token',
			'secret_key',
			'secret_token',
			'private_key',
			'client_secret',
			'consumer_key',
			'consumer_secret',
			'refresh_token',
			'auth_token',
			'bearer_token',
			'license_key',
			'oauth',
			'_token',
			'_secret',
			'_password',
			'passwd',
			'smtp_pass',
		);
		foreach ( $needles as $needle ) {
			if ( false !== strpos( $key, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether the caller may see a post's raw text.
	 *
	 * A post password guards the published text from readers, but `read_post`
	 * does not check it: for a published post that capability maps to plain
	 * `read`, so every account with a token passed the check and got the text
	 * without ever giving the password. Raw text of a protected post is
	 * therefore handed out only to someone who could edit the post — the rule
	 * the WordPress REST API applies in
	 * WP_REST_Posts_Controller::can_access_password_content().
	 *
	 * A revision (autosaves included) carries the text but never the
	 * password, and `read_post` on it maps to the parent's read right — so
	 * the parent's protection would be gone. WordPress' REST API hands a
	 * revision only to someone who may edit the parent; same rule here.
	 *
	 * @param WP_Post $post Post, revision or attachment.
	 * @return bool
	 */
	public static function raw_content_allowed( $post ) {
		if ( 'revision' === $post->post_type ) {
			$parent = (int) $post->post_parent;
			return $parent > 0 && current_user_can( 'edit_post', $parent );
		}
		if ( '' === (string) $post->post_password ) {
			return true;
		}
		return current_user_can( 'edit_post', $post->ID );
	}

	/**
	 * The refusal that goes with raw_content_allowed() being false.
	 *
	 * @param WP_Post $post Post that was refused.
	 * @return WP_Error
	 */
	protected static function raw_content_error( $post ) {
		if ( 'revision' === $post->post_type ) {
			return new WP_Error(
				'ab_mcp_forbidden',
				__( 'Revisions are available only to an account that may edit the post.', 'alphabridge-mcp' )
			);
		}
		return new WP_Error(
			'ab_mcp_password_protected',
			__( 'This post is password-protected. Its text is available over MCP only to an account that may edit the post.', 'alphabridge-mcp' )
		);
	}

	/**
	 * The excerpt for a summary. WordPress itself withholds the excerpt of a
	 * password-protected post; a revision carries the same text but never the
	 * password, so its excerpt — stored, or trimmed from the text — would show
	 * a protected parent's words. Revisions belong to whoever may edit the
	 * parent (the REST API's rule); everyone else gets no excerpt.
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	protected static function summary_excerpt( $post ) {
		if ( 'revision' === $post->post_type && ! current_user_can( 'edit_post', (int) $post->post_parent ) ) {
			return '';
		}
		return wp_strip_all_tags( get_the_excerpt( $post ) );
	}

	/**
	 * A stored date as RFC 3339 in the site's timezone, with the offset written
	 * out: "2026-06-16T09:00:00+02:00".
	 *
	 * WordPress keeps two columns, the UTC one (…_gmt) and the local one. The
	 * tools used to hand out the UTC column bare, "2026-06-16 07:00:00", and a
	 * reader took that for local time: on 26.09.2026 ChatGPT reported a post
	 * published at 09:00 in Zurich as published at 07:00. With the offset in the
	 * value nobody has to know which column it came from, and the value can be
	 * handed back to wp_create_post unchanged.
	 *
	 * The UTC column wins when it holds a date, because a local time can be
	 * ambiguous in the hour the clocks go back. A draft has no UTC time until it
	 * is published or scheduled ("0000-00-00 00:00:00"); WordPress then goes by
	 * the local column, and so does this. With neither, there is no date: null.
	 *
	 * @param string $gmt   UTC column, "Y-m-d H:i:s".
	 * @param string $local Local column, "Y-m-d H:i:s".
	 * @return string|null
	 */
	public static function site_time( $gmt, $local = '' ) {
		$when = self::stored_time( (string) $gmt, new DateTimeZone( 'UTC' ) );
		if ( null === $when ) {
			$when = self::stored_time( (string) $local, wp_timezone() );
		}
		return null === $when ? null : $when->setTimezone( wp_timezone() )->format( DATE_RFC3339 );
	}

	/**
	 * A database datetime in the given zone, or null for anything that is not
	 * exactly "Y-m-d H:i:s" naming a real moment.
	 *
	 * One check does all of it: the value has to come back unchanged.
	 * createFromFormat() refuses other formats and trailing characters, but it
	 * rolls "02-30" over into March and the zero date "0000-00-00 00:00:00"
	 * back to November of the year -1 instead of refusing them; neither comes
	 * back as it went in.
	 *
	 * @param string       $value Column value.
	 * @param DateTimeZone $zone  Zone the column is in.
	 * @return DateTimeImmutable|null
	 */
	private static function stored_time( $value, DateTimeZone $zone ) {
		$when = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, $zone );
		return ( false === $when || $when->format( 'Y-m-d H:i:s' ) !== $value ) ? null : $when;
	}

	/**
	 * A date an agent hands in, as WordPress wants it: the local post_date and,
	 * when the value names its offset, the matching post_date_gmt.
	 *
	 * Two forms. Site time — "Y-m-d H:i:s", "Y-m-d H:i" or "Y-m-d", with a space
	 * or "T" before the time — which is what WordPress itself means by a post
	 * date; post_date_gmt then stays empty and WordPress derives it. Or RFC 3339
	 * with "Z" or an offset (a space instead of "T" is allowed, as RFC 3339
	 * permits), the form every date in this plugin's answers takes,
	 * so a value read from another tool can be passed back unchanged; it is
	 * converted into both columns. Anything else is refused rather than guessed
	 * at: handed to WordPress, a malformed value is stored as it is or replaced
	 * by the current time.
	 *
	 * @param string $value Date as given.
	 * @return array{post_date:string,post_date_gmt:string}|WP_Error
	 */
	public static function parse_post_date( $value ) {
		$value   = trim( (string) $value );
		$refused = new WP_Error(
			'ab_mcp_invalid_date',
			__( 'Unreadable date. Use site time as "Y-m-d H:i:s" (or "Y-m-d H:i", "Y-m-d"), or RFC 3339 with an offset, for example "2026-10-02T09:00:00+02:00".', 'alphabridge-mcp' )
		);

		if ( 1 === preg_match( '/^(\d{4})-(\d{2})-(\d{2})(?:[ Tt](\d{2}):(\d{2})(?::(\d{2}))?)?\z/', $value, $m ) ) {
			$parts = self::date_parts( $m );
			if ( null === $parts ) {
				return $refused;
			}
			return array(
				'post_date'     => vsprintf( '%04d-%02d-%02d %02d:%02d:%02d', $parts ),
				'post_date_gmt' => '',
			);
		}

		if ( 1 === preg_match( '/^(\d{4})-(\d{2})-(\d{2})[ Tt](\d{2}):(\d{2})(?::(\d{2})(?:\.\d{1,9})?)?([Zz]|[+-]\d{2}:\d{2})\z/', $value, $m ) ) {
			$parts  = self::date_parts( $m );
			$offset = 'Z' === strtoupper( $m[7] ) ? '+00:00' : $m[7];
			if ( null === $parts || (int) substr( $offset, 1, 2 ) > 23 || (int) substr( $offset, 4, 2 ) > 59 ) {
				return $refused;
			}
			$when = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:sP', vsprintf( '%04d-%02d-%02d %02d:%02d:%02d', $parts ) . $offset );
			if ( false === $when ) {
				return $refused;
			}
			return array(
				'post_date'     => $when->setTimezone( wp_timezone() )->format( 'Y-m-d H:i:s' ),
				'post_date_gmt' => $when->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ),
			);
		}

		return $refused;
	}

	/**
	 * Year, month, day, hour, minute, second from a match, or null unless they
	 * name a real calendar day and a time of day. A missing time is midnight.
	 *
	 * @param array $m preg_match() groups 1 to 6.
	 * @return int[]|null
	 */
	private static function date_parts( array $m ) {
		$parts = array();
		for ( $i = 1; $i <= 6; $i++ ) {
			$parts[] = isset( $m[ $i ] ) && '' !== $m[ $i ] ? (int) $m[ $i ] : 0;
		}
		if ( ! checkdate( $parts[1], $parts[2], $parts[0] ) || $parts[3] > 23 || $parts[4] > 59 || $parts[5] > 59 ) {
			return null;
		}
		return $parts;
	}

	/**
	 * Compact summary of a post object.
	 *
	 * @param WP_Post $post Post.
	 * @return array
	 */
	protected static function post_summary( $post ) {
		return array(
			'id'        => (int) $post->ID,
			'type'      => $post->post_type,
			'status'    => $post->post_status,
			'title'     => get_the_title( $post ),
			'slug'      => $post->post_name,
			'link'      => get_permalink( $post ),
			'date'      => self::site_time( $post->post_date_gmt, $post->post_date ),
			'modified'  => self::site_time( $post->post_modified_gmt, $post->post_modified ),
			'author'    => (int) $post->post_author,
			'parent'    => (int) $post->post_parent,
			'excerpt'   => self::summary_excerpt( $post ),
			'menu_order'=> (int) $post->menu_order,
		);
	}
}

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
	 * value nobody has to know which column it came from.
	 *
	 * The UTC column wins when it holds a date, because a local time can be
	 * ambiguous in the hour the clocks go back. A draft whose date is not fixed
	 * yet has no UTC time ("0000-00-00 00:00:00"); WordPress then goes by the
	 * local column, and so does this. With neither, there is no date: null.
	 *
	 * Two cases are given in UTC ("…Z") instead. Before standard time some
	 * places ran on local mean time — Zurich on +00:29:46 until 1894 — and RFC
	 * 3339 writes offsets in whole minutes, so the site form would be off by
	 * those seconds. And a date at the edge of the calendar can leave the years
	 * 1 to 9999 once shifted into site time; RFC 3339 has four digits for the
	 * year. If UTC leaves them too, there is no date to give: null.
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
		if ( null === $when ) {
			return null;
		}
		$site = $when->setTimezone( wp_timezone() );
		if ( 0 === $site->getOffset() % 60 && self::four_digit_year( $site ) ) {
			return $site->format( DATE_RFC3339 );
		}
		$utc = $when->setTimezone( new DateTimeZone( 'UTC' ) );
		return self::four_digit_year( $utc ) ? $utc->format( 'Y-m-d\TH:i:s\Z' ) : null;
	}

	/**
	 * Is the year between 1 and 9999, the range WordPress stores and RFC 3339
	 * writes?
	 *
	 * @param DateTimeInterface $when Moment in the zone it will be written in.
	 * @return bool
	 */
	private static function four_digit_year( DateTimeInterface $when ) {
		$year = (int) $when->format( 'Y' );
		return $year >= 1 && $year <= 9999;
	}

	/**
	 * A database datetime read in the given zone, or null unless it is exactly
	 * "Y-m-d H:i:s" naming a real day in the years 1 to 9999.
	 *
	 * The form is checked in UTC, where no clock change interferes, by letting
	 * the value come back unchanged: createFromFormat() refuses other formats
	 * and trailing characters, but rolls "02-30" over into March and the zero
	 * date back to November of the year -1. Then the value is read in its own
	 * zone the way WordPress reads a local time (get_gmt_from_date() uses the
	 * same date_create()): one in a stretch the clocks skip moves forward, and
	 * one in a stretch they repeat is taken as PHP resolves it for that zone.
	 *
	 * @param string       $value Column value.
	 * @param DateTimeZone $zone  Zone the column is in.
	 * @return DateTimeImmutable|null
	 */
	private static function stored_time( $value, DateTimeZone $zone ) {
		$utc = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, new DateTimeZone( 'UTC' ) );
		if ( false === $utc || $utc->format( 'Y-m-d H:i:s' ) !== $value || (int) $utc->format( 'Y' ) < 1 ) {
			return null;
		}
		$when = date_create_immutable( $value, $zone );
		return false === $when ? null : $when;
	}

	/**
	 * A date an agent hands in, as WordPress wants it: the local post_date and,
	 * when the value names its offset, the matching post_date_gmt.
	 *
	 * Two forms. Site time — "Y-m-d H:i:s", "Y-m-d H:i" or "Y-m-d", with a space
	 * or "T" before the time — which is what WordPress itself means by a post
	 * date; post_date_gmt then stays empty and WordPress derives it. Or the RFC
	 * 3339 form with "Z" or an offset (a space instead of "T" is allowed, as RFC
	 * 3339 permits), the form the dates in this plugin's answers take; it is
	 * converted into both columns.
	 *
	 * Refused, each with its own reason rather than a guess: a value in neither
	 * form; a day or time of day that does not exist; a site time the clocks
	 * skip; a year outside 1 to 9999 on either side of the conversion; and a
	 * moment where the clocks go back that WordPress would read as the other of
	 * the two. WordPress schedules and re-reads a post by its local time: in
	 * Zurich on 25 October 2026 a post meant for 02:30+02:00 would be read as
	 * 02:30+01:00 and published an hour late.
	 *
	 * @param string $value Date as given.
	 * @return array{post_date:string,post_date_gmt:string}|WP_Error
	 */
	public static function parse_post_date( $value ) {
		$value = trim( (string) $value );

		if ( 1 === preg_match( '/^(\d{4})-(\d{2})-(\d{2})(?:[ Tt](\d{2}):(\d{2})(?::(\d{2}))?)?\z/', $value, $m ) ) {
			$parts = self::date_parts( $m );
			if ( null === $parts ) {
				return self::date_refused( 'no-such-date', $value );
			}
			$local = vsprintf( '%04d-%02d-%02d %02d:%02d:%02d', $parts );
			$read  = date_create_immutable( $local, wp_timezone() );
			if ( false === $read || $read->format( 'Y-m-d H:i:s' ) !== $local ) {
				return self::date_refused( 'skipped', $value );
			}
			// WordPress derives the UTC column from it; that year must fit too.
			if ( ! self::four_digit_year( $read->setTimezone( new DateTimeZone( 'UTC' ) ) ) ) {
				return self::date_refused( 'year', $value );
			}
			return array(
				'post_date'     => $local,
				'post_date_gmt' => '',
			);
		}

		if ( 1 === preg_match( '/^(\d{4})-(\d{2})-(\d{2})[ Tt](\d{2}):(\d{2})(?::(\d{2})(?:\.\d{1,9})?)?([Zz]|[+-]\d{2}:\d{2})\z/', $value, $m ) ) {
			$parts  = self::date_parts( $m );
			$offset = 'Z' === strtoupper( $m[7] ) ? '+00:00' : $m[7];
			if ( null === $parts || (int) substr( $offset, 1, 2 ) > 23 || (int) substr( $offset, 4, 2 ) > 59 ) {
				return self::date_refused( 'no-such-date', $value );
			}
			$when = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:sP', vsprintf( '%04d-%02d-%02d %02d:%02d:%02d', $parts ) . $offset );
			if ( false === $when ) {
				return self::date_refused( 'no-such-date', $value );
			}
			$local = $when->setTimezone( wp_timezone() );
			$gmt   = $when->setTimezone( new DateTimeZone( 'UTC' ) );
			if ( ! self::four_digit_year( $local ) || ! self::four_digit_year( $gmt ) ) {
				return self::date_refused( 'year', $value );
			}
			$read = date_create_immutable( $local->format( 'Y-m-d H:i:s' ), wp_timezone() );
			if ( false === $read || $read->getTimestamp() !== $when->getTimestamp() ) {
				return self::date_refused( 'repeated', $value, false === $read ? '' : $read->format( DATE_RFC3339 ) );
			}
			return array(
				'post_date'     => $local->format( 'Y-m-d H:i:s' ),
				'post_date_gmt' => $gmt->format( 'Y-m-d H:i:s' ),
			);
		}

		return self::date_refused( 'unreadable', $value );
	}

	/**
	 * The refusal for parse_post_date(), naming the reason it found.
	 *
	 * @param string $reason unreadable | no-such-date | skipped | year | repeated.
	 * @param string $value  The date as given.
	 * @param string $read   For "repeated": how WordPress would read it.
	 * @return WP_Error
	 */
	private static function date_refused( $reason, $value, $read = '' ) {
		switch ( $reason ) {
			case 'no-such-date':
				/* translators: %s: the date as given. */
				$message = sprintf( __( 'No such date or time: "%s".', 'alphabridge-mcp' ), $value );
				break;
			case 'skipped':
				/* translators: %s: the date as given. */
				$message = sprintf( __( '"%s" does not exist in the site\'s timezone: the clocks skip over it. Choose another time.', 'alphabridge-mcp' ), $value );
				break;
			case 'year':
				/* translators: %s: the date as given. */
				$message = sprintf( __( '"%s" falls outside the years WordPress can store (1 to 9999).', 'alphabridge-mcp' ), $value );
				break;
			case 'repeated':
				/* translators: 1: the date as given, 2: the same local time as WordPress reads it. */
				$message = sprintf( __( '"%1$s" falls where the clocks go back in the site\'s timezone, so its local time occurs twice. WordPress schedules posts by local time and would read it as "%2$s". Choose a time outside that stretch, or give that offset.', 'alphabridge-mcp' ), $value, $read );
				break;
			default:
				/* translators: %s: the date as given. */
				$message = sprintf( __( 'Unreadable date: "%s". Use site time as "Y-m-d H:i:s" (or "Y-m-d H:i", "Y-m-d"), or RFC 3339 with an offset, for example "2026-10-02T09:00:00+02:00".', 'alphabridge-mcp' ), $value );
		}
		return new WP_Error( 'ab_mcp_invalid_date', $message, array( 'reason' => $reason ) );
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

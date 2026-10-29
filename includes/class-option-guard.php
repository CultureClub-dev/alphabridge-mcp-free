<?php
/**
 * The guard on option names: which rows of wp_options no tool writes or
 * deletes (AlphaBridge's own state, credentials and the keys that run the
 * site), and the checks that keep a name from reaching such a row by another
 * spelling (length, invisible characters, the database's comparison order).
 * Free since 4.10.0 for wp_search_replace, which replaces in the options
 * table; before in Pro only (AB_MCP_Tools_Options_Settings_Pro, which keeps
 * its own copy for older cores).
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class AB_MCP_Option_Guard
 */
class AB_MCP_Option_Guard {

	/**
	 * The protected option blocklist as one public question, so every tool
	 * that writes option rows (wp_search_replace, the restore path) honours
	 * the same rules.
	 *
	 * @param string $name Option name.
	 * @return bool
	 */
	public static function option_is_blocked( $name ) {
		return self::is_blocked( $name );
	}

	/**
	 * Would this name be truncated by the option_name column?
	 *
	 * Public for the restore path, which writes option rows of its own and
	 * needs the same answer — see too_long_for_column().
	 *
	 * @param string $name Name.
	 * @return bool
	 */
	public static function option_name_too_long( $name ) {
		return self::too_long_for_column( $name );
	}

	/**
	 * Does this name carry a character the comparison order would skip?
	 *
	 * Public for the restore path, for the same reason as
	 * option_name_too_long() — see has_invisible_chars().
	 *
	 * @param string $name Name.
	 * @return bool
	 */
	public static function option_name_has_invisibles( $name ) {
		return self::has_invisible_chars( $name );
	}

	/**
	 * Does the option now hold this value?
	 *
	 * The one honest success signal for an option write — update_option's
	 * return value cannot distinguish "failed" from "already that value".
	 * Shared with the restore path, which proved this logic first; the
	 * comparison rules live here so the two cannot drift.
	 *
	 * @param string $name  Option name.
	 * @param mixed  $value Expected value.
	 * @return bool
	 */
	public static function option_value_matches( $name, $value ) {
		// Read back against a default nothing can equal. With null as the
		// default, a row a hook deleted again would read back as "null, just
		// as asked for" — the vanished write reported as a stored one.
		$missing = new stdClass();
		$back    = get_option( $name, $missing );
		if ( $missing === $back ) {
			return false;
		}
		// WordPress stores scalars as strings, so an option written as the
		// integer 5 reads back as '5' and a null as '' — compare scalars (null
		// among them) loosely and only structures strictly, or a successful
		// write would be reported as a failure. Arrays stay strict: they are
		// serialised whole, so the inner types come back unchanged and
		// array( '5' ) is not array( 5 ).
		$back_flat  = is_scalar( $back ) || null === $back;
		$value_flat = is_scalar( $value ) || null === $value;
		return ( $back_flat && $value_flat )
			? (string) $back === (string) $value
			: $back === $value;
	}

	/**
	 * May this option name be written or deleted at all?
	 *
	 * ONE question, not four. The restore path writes option rows just as the
	 * tools do and needs the same answer, and asking it through four separate
	 * bridges was an invitation to forget one when a guard was added — which
	 * happened twice over: plan_site_settings() never learned about the length
	 * limit, and the fold was something the restore never asked about at all.
	 * A new guard belongs in here, and every writer inherits it.
	 *
	 * @param string $name Name, already trimmed and redirected.
	 * @return WP_Error|false Error when refused, false when allowed.
	 */
	public static function option_write_refused( $name ) {
		if ( self::too_long_for_column( $name ) ) {
			return new WP_Error( 'ab_mcp_option_name_too_long', __( 'The option name is longer than WordPress can store. An option name holds at most 191 characters. Use a shorter name.', 'alphabridge-mcp' ) );
		}
		if ( self::has_invisible_chars( $name ) ) {
			return new WP_Error( 'ab_mcp_option_name_invalid', __( 'The option name contains characters that cannot be compared reliably. Leave out control and format characters (such as zero-width spaces).', 'alphabridge-mcp' ) );
		}
		if ( self::is_blocked( $name ) || self::folds_onto_blocked( $name ) ) {
			return new WP_Error( 'ab_mcp_blocked', __( 'This option is protected and cannot be changed via MCP. An administrator changes it in wp-admin, where WordPress or its plugin offers it.', 'alphabridge-mcp' ) );
		}
		return false;
	}

	/**
	 * Options that must never be written or deleted through the generic option
	 * tools. The full list (the reviewed free 3.x blocklist) plus the
	 * Site-Deploy connection option of the Pro add-on (`ab_mcp_pro_deploy`),
	 * which holds the encrypted FTP/SFTP credentials and the same-server
	 * verification state.
	 *
	 * @return string[]
	 */
	private static function blocklist() {
		return array(
			// Plugin secrets / internal state.
			AB_MCP_Settings::OPT_TOKENS,
			AB_MCP_Settings::OPT_TOOLSTATE,
			AB_MCP_Settings::OPT_OPTIONS,
			'ab_mcp_license',   // Legacy pre-2.0 unlock codes; still protected on upgraded sites.
			'ab_mcp_pro_deploy', // Pro Site-Deploy: encrypted FTP/SFTP credentials + verification state.
			'auth_key',
			'auth_salt',
			'secret',
			// WordPress refuses these two itself — with wp_die(), mid-request,
			// so the client gets an HTML death page instead of an answer.
			// Refusing them here turns that into an ordinary error.
			'alloptions',
			'notoptions',
			// Privilege / registration / code-execution surface.
			'default_role',
			'users_can_register',
			'active_plugins',
			'active_sitewide_plugins',
			'template',
			'stylesheet',
			'siteurl',
			'home',
			'cron',
			'mailserver_url',
			'mailserver_login',
			'mailserver_pass',
			'mailserver_port',
		);
	}

	/**
	 * The option name WordPress will really act on.
	 *
	 * It trims — get_option, update_option, add_option and delete_option all do,
	 * so a name checked before trimming is not the name that gets written and
	 * " siteurl " would walk straight past the block list.
	 *
	 * And it resolves the two legacy names core redirects. Core does that in
	 * get_option, update_option and add_option but NOT in delete_option, so a
	 * caller that does not resolve them itself would photograph one row, write a
	 * second and undo a third.
	 *
	 * @param mixed $name Name as the caller gave it.
	 * @return string
	 */
	protected static function real_option_name( $name ) {
		$name    = trim( (string) $name );
		$renamed = array(
			'blacklist_keys'    => 'disallowed_keys',
			'comment_whitelist' => 'comment_previously_approved',
		);
		return isset( $renamed[ $name ] ) ? $renamed[ $name ] : $name;
	}

	/**
	 * Settle on the row a caller-supplied option name really addresses, and take
	 * its snapshot.
	 *
	 * Two normalisations are in play and they feed each other: the option_name
	 * column is compared with a collation that folds (MySQL's Classical Latin
	 * equates i with j and u with v, inside plain ASCII), so a name can match a
	 * row spelled differently; and WordPress redirects two legacy names. A
	 * folded spelling of a legacy name needs both, in that order, so this loops
	 * until the name stops moving — and gives up rather than spin.
	 *
	 * The block list is asked in every round, about the name that round settled
	 * on, because only the last one is the row that will actually be written.
	 *
	 * @param mixed $given Name as the caller gave it.
	 * @return array|WP_Error array( 'name' => string, 'snapshot' => array )
	 */
	/**
	 * Would this name, once stored, be the same row as a protected one?
	 *
	 * Only for names with no row yet — where nothing can be looked up and the
	 * collation has not spoken. Two foldings are undone, both of them ones a
	 * comparison order in real use performs and PHP's does not:
	 *
	 *  - diacritics, folded by every _ai_ ("accent insensitive") collation,
	 *    which includes utf8mb4_unicode_ci — what wpdb picks when DB_COLLATE
	 *    is empty, i.e. the ordinary WordPress install;
	 *  - i/j and u/v, which the Classical Latin order equates inside plain
	 *    ASCII (the case that put settle_option here to begin with).
	 *
	 * Deliberately narrow. It refuses ONLY when the folded form lands on the
	 * block list, so a legitimate name like "grüße" is untouched: nothing on
	 * the list folds onto it. Folding first and asking the list afterwards
	 * would be the wrong way round — that would refuse far more than it must.
	 *
	 * @param string $name Trimmed, redirected name.
	 * @return bool
	 */
	protected static function folds_onto_blocked( $name ) {
		// THE RULE: both sides are reduced to the same form and compared there —
		// candidate and list entry, candidate and needle. A comparison order
		// equates two spellings; it does not move one towards the other, so the
		// test has to meet in the middle. Folding only the candidate and then
		// asking the ordinary guard would compare a folded name against an
		// unfolded list, which catches "ab_mcp_pro_déploy" (it folds ONTO the
		// listed spelling) and misses "actiue_plugins" (already in folded form,
		// so no fold moves it anywhere) — the same row either way, under u=v.
		//
		// Every reading of the candidate is tested, because one letter can read
		// two ways depending on the column — see collation_forms(). The targets
		// are plain ASCII and so have exactly one.
		foreach ( self::collation_forms( $name ) as $folded ) {
			foreach ( self::needles() as $needle ) {
				$n = self::collation_form( $needle );
				// The unresolved-character rule has to hold here too, not only
				// for whole names below. The needles are the credential-shaped
				// substrings, and on a host without ext-intl nothing decomposes,
				// so "my_tṓken" reached this line unfolded and strpos() alone
				// did not see the "token" inside it.
				if ( false !== strpos( $folded, $n ) || self::contains_with_unknowns( $folded, $n ) ) {
					return true;
				}
			}
			foreach ( self::blocklist() as $b ) {
				$target = self::collation_form( $b );
				if ( $folded === $target || self::lines_up_with( $folded, $target ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * The substrings that make a name a credential by its shape.
	 *
	 * Lifted out of is_blocked() so the folded comparison uses the same list
	 * rather than a second copy of it: two lists of one thing drift, and this
	 * one decides what is refused.
	 *
	 * @return string[]
	 */
	/**
	 * The two lists the write guard is made of, for a caller that has to
	 * turn them into SQL: the search & replace tool must keep refused names
	 * out of its scan BEFORE the LIKE on the value runs, or a name listed as
	 * skipped afterwards answers whether its value contains the needle.
	 *
	 * @return array{needles: string[], exact: string[]}
	 */
	public static function write_guard_lists() {
		return array(
			'needles' => self::needles(),
			'exact'   => array_map( 'strval', self::blocklist() ),
		);
	}

	protected static function needles() {
		return array(
			// Every option this plugin keeps for itself. As a needle rather
			// than as list entries, because the list only knows the names
			// somebody remembered to add: ab_mcp_pro_undo_index and
			// ab_mcp_pro_undo_lock (the undo ledger, which decides what a
			// restore writes) and ab_mcp_audit (the log) were all missing, and
			// the next feature's option would have been missing too. Written
			// this way it also inherits the folding and the backstop below.
			'ab_mcp',
			'secret', 'password', 'passwd', 'user_roles', 'api_key', 'apikey', 'api-key', 'access_key', 'private_key', 'consumer_key', 'client_secret', 'token', 'credential', 'oauth', 'bearer',
		);
	}

	/**
	 * One name in the form a folding comparison order reduces it to.
	 *
	 * Applied to BOTH the candidate and the list entry, so equality here means
	 * "the database would treat these as the same row".
	 *
	 * @param string $s Name.
	 * @return string
	 */
	protected static function collation_form( $s ) {
		$s = trim( (string) $s );

		// 1. Compatibility decomposition, then drop the combining marks.
		//
		// This is the step that carries the weight, because remove_accents()
		// works from a hand-kept table of Latin-1 and Latin Extended-A/B and
		// nothing else. "ṓ" (U+1E53, Latin Extended Additional) is not in it,
		// so "ab_mcp_pro_deplṓy" came through untouched while an
		// accent-insensitive collation reads it as ab_mcp_pro_deploy.
		// Decomposing turns it into "o" plus two marks, and the marks go.
		//
		// KD rather than D on purpose: the compatibility form also folds the
		// ligature "ﬁ" to "fi" and the fullwidth letters to ASCII, and a
		// UCA-based collation equates those as well — "ａｃｔｉｖｅ_plugins"
		// was another spelling of active_plugins that nothing here caught.
		if ( function_exists( 'normalizer_normalize' ) && class_exists( 'Normalizer' ) ) {
			$nfkd = normalizer_normalize( $s, Normalizer::FORM_KD );
			if ( is_string( $nfkd ) && '' !== $nfkd ) {
				$s = $nfkd;
			}
		}
		// Mn AND Me. The enclosing marks (U+20DD and friends) are combining
		// characters like the diacritics, weigh only on the secondary level in
		// the UCA table, and so vanish under an accent-insensitive collation
		// just the same — but they are category Me, not Mn, and they are not
		// Latin either, so neither the strip nor the wildcard backstop saw
		// "ab_mc(U+20DD)p_pro_deploy" for what the column reads it as:
		// ab_mcp_pro_deploy. Mc (spacing combining marks) is NOT stripped
		// here, because the collations disagree about it — see
		// collation_forms(), which reads such a name both ways instead of
		// betting on one.
		$bare = preg_replace( '/[\p{Mn}\p{Me}]/u', '', $s );
		if ( is_string( $bare ) && '' !== $bare ) {
			$s = $bare;
		}

		$s = strtr( $s, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz' );

		// 2. What has no decomposition at all — "ø", "æ", "ß", "đ" — is exactly
		// what remove_accents() does know. en_US, ALWAYS: a locale-driven fold
		// would disagree with the column and let a variant through.
		if ( function_exists( 'remove_accents' ) ) {
			$folded = remove_accents( $s, 'en_US' );
			if ( is_string( $folded ) && '' !== $folded ) {
				// It can hand back capitals ("Ä" to "A"), so the case fold has
				// to run again afterwards.
				$s = strtr( $folded, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz' );
			}
		}

		// 3. Classical Latin equates these inside ASCII.
		return strtr( $s, array( 'j' => 'i', 'v' => 'u' ) );
	}

	/**
	 * Does this name match a protected one once the letters we cannot fold are
	 * treated as unknown?
	 *
	 * The backstop for what step 1 and 2 of collation_form() cannot reach: a
	 * character with no decomposition and no entry in remove_accents(), or any
	 * accented letter at all on a host without the intl extension. Rather than
	 * guess what such a character folds to, each one stands for ANY single
	 * character, and only a name that then still lines up with a protected one
	 * — same length, every other letter identical — is refused.
	 *
	 * So "ab_mcp_tokenß" is refused (it lines up with ab_mcp_tokens) while
	 * "straße" is not: nothing on the list is six letters ending "stra?e".
	 *
	 * @param string $folded Name already through collation_form().
	 * @param string $target Protected name, likewise folded.
	 * @return bool
	 */
	protected static function lines_up_with( $folded, $target ) {
		$chars = self::unresolved_chars( $folded );
		if ( null === $chars ) {
			return false;
		}
		$pattern = '';
		foreach ( $chars as $c ) {
			// One unknown character stands for one character. The cases where
			// one letter becomes two ("æ" to "ae") all live in
			// remove_accents() and never reach this.
			$pattern .= self::is_unresolved( $c ) ? '.' : preg_quote( $c, '/' );
		}
		return 1 === preg_match( '/^' . $pattern . '$/u', $target );
	}

	/**
	 * Does a protected substring sit inside this name, once the letters we
	 * could not fold are treated as unknown?
	 *
	 * The needle equivalent of lines_up_with(). Walked by character rather
	 * than by regex because the needle is the fixed side here and the haystack
	 * is the one carrying wildcards, which is the wrong way round for a
	 * pattern match.
	 *
	 * @param string $folded Candidate, already through collation_form().
	 * @param string $needle Protected substring, likewise folded.
	 * @return bool
	 */
	protected static function contains_with_unknowns( $folded, $needle ) {
		$hay = self::unresolved_chars( $folded );
		if ( null === $hay ) {
			return false;
		}
		$ndl = preg_split( '//u', $needle, -1, PREG_SPLIT_NO_EMPTY );
		if ( ! is_array( $ndl ) || ! $ndl || count( $hay ) < count( $ndl ) ) {
			return false;
		}
		$last = count( $hay ) - count( $ndl );
		for ( $i = 0; $i <= $last; $i++ ) {
			$hit = true;
			foreach ( $ndl as $j => $want ) {
				$have = $hay[ $i + $j ];
				if ( $have === $want || self::is_unresolved( $have ) ) {
					continue;
				}
				$hit = false;
				break;
			}
			if ( $hit ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The characters of a folded name, or null when none of them is unresolved.
	 *
	 * Null means the exact comparison has already answered the question and
	 * the wildcard step has nothing to add.
	 *
	 * @param string $folded Folded name.
	 * @return array|null
	 */
	protected static function unresolved_chars( $folded ) {
		$chars = preg_split( '//u', (string) $folded, -1, PREG_SPLIT_NO_EMPTY );
		if ( ! is_array( $chars ) || ! $chars ) {
			return null;
		}
		foreach ( $chars as $c ) {
			if ( self::is_unresolved( $c ) ) {
				return $chars;
			}
		}
		return null;
	}

	/**
	 * A character this guard could not fold, and that a Latin comparison order
	 * might still equate with an ASCII letter.
	 *
	 * Restricted to the Latin script on purpose. Treating EVERY non-ASCII
	 * character as a wildcard would refuse ordinary names in other writing
	 * systems: "привет_option" carries six Cyrillic letters in a row, which
	 * would line up with the six of "secret" and be refused for nothing. No
	 * Latin collation folds Cyrillic or Han onto Latin letters, so those stay
	 * literal and only unfolded LATIN letters stand for something else.
	 *
	 * @param string $c One character.
	 * @return bool
	 */
	protected static function is_unresolved( $c ) {
		return 1 === preg_match( '/[^\x20-\x7E]/', $c ) && 1 === preg_match( '/\p{Latin}/u', $c );
	}

	/**
	 * Is this name longer than the option_name column can hold?
	 *
	 * varchar(191), counted in characters rather than bytes because that is how
	 * MySQL counts a utf8mb4 column. On a string that is not valid UTF-8 the
	 * split fails and the byte count stands in — it is never smaller than the
	 * character count, so the guard errs towards refusing.
	 *
	 * @param string $name Name.
	 * @return bool
	 */
	protected static function too_long_for_column( $name ) {
		$name  = (string) $name;
		$chars = preg_split( '//u', $name, -1, PREG_SPLIT_NO_EMPTY );
		$len   = is_array( $chars ) ? count( $chars ) : strlen( $name );
		return $len > 191;
	}

	/**
	 * Does this name carry a character the comparison order would skip?
	 *
	 * Control characters (Cc) and format characters (Cf) — NUL, soft hyphen,
	 * the zero-width joiners, the byte-order mark — weigh nothing in UCA, so
	 * the column reads a name with one in it as the name without. That is a
	 * different failure from an unfoldable letter: nothing stands in its
	 * place, it simply is not there, which the wildcard step cannot express.
	 *
	 * Also true when the string is not valid UTF-8: preg_match returns false
	 * on it, and a name that cannot even be examined is one this guard will
	 * not clear.
	 *
	 * @param string $name Name.
	 * @return bool
	 */
	protected static function has_invisible_chars( $name ) {
		$hit = preg_match( '/[\p{Cc}\p{Cf}]/u', (string) $name );
		return 1 === $hit || false === $hit;
	}

	/**
	 * Every form a comparison order might reduce this name to.
	 *
	 * Usually one. "ß" is the exception, and the reason this returns a list:
	 * remove_accents() maps it to a single "s", while the UCA-based collations
	 * WordPress defaults to (utf8mb4_unicode_ci and _520_ci) expand it to "ss"
	 * — and utf8mb4_general_ci does neither, treating it as "s". So the one
	 * letter reads two ways depending on the column, and "my_paßword" reached
	 * the needle test as "my_pasword", which does not contain "password",
	 * while the database would have landed it on a row that does.
	 *
	 * Checked against the rest of core's multi-letter mappings — "æ", "œ",
	 * "þ", "ĳ" and their capitals — and those agree with the UCA expansion, so
	 * "ß" is the only one that needs two readings.
	 *
	 * @param string $s Name.
	 * @return string[]
	 */
	protected static function collation_forms( $s ) {
		// Two letters read more than one way, and they COMBINE — which an
		// earlier version of this got wrong by offering each reading on its
		// own: "mailserver_paß" plus a spacing mark needed the sharp s read as
		// "ss" AND the mark dropped to show itself as mailserver_pass, and
		// neither single reading did. So the readings are applied in
		// succession, each to everything produced so far, and every
		// combination comes out. A third ambiguity later joins the same loop
		// rather than adding another special case.
		$raw = array( (string) $s );

		// 1. "ß": remove_accents maps it to one "s", the UCA collations
		//    WordPress defaults to expand it to "ss", and general_ci does
		//    neither.
		foreach ( $raw as $one ) {
			if ( false !== strpos( $one, 'ß' ) || false !== strpos( $one, 'ẞ' ) ) {
				$raw[] = str_replace( array( 'ß', 'ẞ' ), 'ss', $one );
			}
		}

		// 2. The spacing combining marks (Mc): the collations disagree about
		//    them. The old UCA 4.0.0 order behind utf8mb4_unicode_ci gives
		//    U+0903 no primary weight — "authः_key" IS the auth_key row there
		//    — while newer orders weigh the Indic vowel signs.
		foreach ( $raw as $one ) {
			$without = preg_replace( '/\p{Mc}/u', '', $one );
			if ( is_string( $without ) && '' !== $without && $without !== $one ) {
				$raw[] = $without;
			}
		}

		$forms = array();
		foreach ( array_unique( $raw ) as $one ) {
			$forms[] = self::collation_form( $one );
		}
		return array_values( array_unique( $forms ) );
	}

	protected static function settle_option( $given ) {
		$name = self::real_option_name( $given );
		// A name the column cannot hold is refused before anything else,
		// because past this length the name that gets CHECKED stops being the
		// name that gets WRITTEN. option_name is varchar(191); WordPress drops
		// STRICT_TRANS_TABLES from sql_mode (class-wpdb.php), so MySQL truncates
		// silently instead of erroring; and the default collation pads with
		// spaces when comparing. Together that is a way onto any protected row:
		// "siteurl" plus 184 spaces plus "x" is 192 characters, survives trim()
		// because of the trailing "x", matches no block-list entry, finds no row
		// to resolve against — and is then cut to "siteurl" plus 184 spaces,
		// which the UNIQUE KEY treats as the siteurl row, so add_option's
		// ON DUPLICATE KEY UPDATE overwrites it.
		if ( self::too_long_for_column( $name ) ) {
			return new WP_Error( 'ab_mcp_option_name_too_long', __( 'The option name is longer than WordPress can store, so nothing was changed. An option name holds at most 191 characters. Use a shorter name.', 'alphabridge-mcp' ) );
		}
		// Same reasoning, different mechanism: a character the comparison order
		// SKIPS ENTIRELY. In UCA a control or format character weighs nothing —
		// "ab_mcp_pro_de\0ploy" and "ab_mcp_pro_deploy" are one row to
		// utf8mb4_unicode_ci — and skipping is not something the wildcard step
		// can express, since that substitutes one character for another rather
		// than removing it. Folding them away instead would check a name the
		// write does not use. Refused, and nothing here is a loss: an option
		// name has no business carrying a NUL, a soft hyphen, a zero-width
		// joiner or a byte-order mark.
		if ( self::has_invisible_chars( $name ) ) {
			return new WP_Error( 'ab_mcp_option_name_invalid', __( 'The option name contains characters that cannot be compared reliably, so nothing was changed. Leave out control and format characters (such as zero-width spaces).', 'alphabridge-mcp' ) );
		}
		for ( $round = 0; $round < 4; $round++ ) {
			if ( self::is_blocked( $name ) ) {
				return new WP_Error( 'ab_mcp_blocked', __( 'This option is protected and cannot be changed via MCP. An administrator changes it in wp-admin, where WordPress or its plugin offers it.', 'alphabridge-mcp' ) );
			}
			// option_row asks for the NAME and the flag, never the value: the
			// row this name lands on is still unknown here, so nothing has been
			// cleared to read yet. Only once the name has settled AND passed the
			// guard is the value taken.
			$row = AB_MCP_Undo::option_row( $name );
			if ( null === $row ) {
				// Without that answer we cannot say WHICH row this name
				// addresses, and so cannot say whether it is a protected one.
				// A change that cannot be checked is one that must not happen.
				return new WP_Error( 'ab_mcp_option_unreadable', __( 'The option could not be looked up, so nothing was changed. Try again; if it keeps failing, the database error log names the cause.', 'alphabridge-mcp' ) );
			}
			if ( false === $row ) {
				// No row today. The loop above resolves a folded spelling by
				// asking which row it LANDS on — and with no row there is no
				// answer to that, so the guard above has only seen the name as
				// written. An INSERT is still governed by the collation: on a
				// site where it folds, "ab_mcp_pro_deploy" written as
				// "ab_mcp_pro_déploy" becomes the row a later
				// get_option( 'ab_mcp_pro_deploy' ) reads, and the unique key
				// on option_name then keeps the real option from ever being
				// created. The fixed point needs an existing row as its
				// witness; this is the case that has none.
				//
				// So ask the question the database would ask, on the name that
				// would be stored. Only a name that FOLDS ONTO a protected one
				// is refused — an ordinary non-ASCII option name folds onto
				// nothing on the list and stays writable.
				if ( self::folds_onto_blocked( $name ) ) {
					return new WP_Error( 'ab_mcp_blocked', __( 'This option is protected and cannot be changed via MCP. An administrator changes it in wp-admin, where WordPress or its plugin offers it.', 'alphabridge-mcp' ) );
				}
			}
			$settled = is_array( $row ) ? self::real_option_name( $row['name'] ) : $name;
			if ( $settled !== $name ) {
				$name = $settled;
				continue;
			}
			$snapshot = AB_MCP_Undo::option_snapshot( $name );
			if ( null === $snapshot ) {
				return new WP_Error( 'ab_mcp_option_unreadable', __( 'The option could not be looked up, so nothing was changed. Try again; if it keeps failing, the database error log names the cause.', 'alphabridge-mcp' ) );
			}
			return array( 'name' => $name, 'snapshot' => $snapshot );
		}
		return new WP_Error( 'ab_mcp_option_unreadable', __( 'The option name could not be settled, so nothing was changed. Try again; if it keeps failing, the database error log names the cause.', 'alphabridge-mcp' ) );
	}

	/**
	 * Is an option protected from writing/deleting via MCP? Blocks the exact
	 * names above plus any name that looks like a credential.
	 *
	 * @param string $name Option name.
	 * @return bool
	 */
	protected static function is_blocked( $name ) {
		// Trimmed here as well as at the call sites: WordPress compares the
		// trimmed name, so this guard has to answer for the trimmed name no
		// matter who asks — the restore path consults it through
		// option_is_blocked() with whatever a stored snapshot holds.
		// strtr, not strtolower: strtolower is locale-dependent before PHP 8.2
		// and this plugin supports 8.0, so under a Turkish locale 'SITEURL'
		// would lower-case to 'sıteurl' and walk past the list below.
		$name = strtr( trim( (string) $name ), 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz' );
		foreach ( self::needles() as $needle ) {
			if ( false !== strpos( $name, $needle ) ) {
				return true;
			}
		}
		foreach ( self::blocklist() as $b ) {
			if ( $name === strtolower( (string) $b ) ) {
				return true;
			}
		}
		return false;
	}
}

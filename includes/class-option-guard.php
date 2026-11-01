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
 * Since 4.17.0 (stage 2) the one list answers two questions. The first,
 * option_write_refused(), is the one every writer asked so far and stays as
 * strict as it was. The second, confirmed_write_refused(), is for a write the
 * person confirmed from its preview: it lets exactly the registration pair
 * through (users_can_register, default_role) and refuses everything else the
 * first refuses. option_class() names the class of a name from the two
 * answers, and resolve_option() settles which row a name given by a caller
 * really addresses, under either question, so that a condition, a preview
 * and the write all act on the same row. A refusal says, per group of names,
 * where the change is made instead.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class AB_MCP_Option_Guard
 */
class AB_MCP_Option_Guard {

	/**
	 * Class of a name no list entry covers: a tool writes it without more ado.
	 */
	const CLASS_OPEN = 'open';

	/**
	 * Class of a name a tool writes only with the confirmation of a preview:
	 * the names of CONFIRMED, in exactly that spelling.
	 */
	const CLASS_CONFIRM = 'confirm';

	/**
	 * Class of a name no tool writes or deletes.
	 */
	const CLASS_BLOCKED = 'blocked';

	/**
	 * The question of every writer before stage 2, and of every writer that
	 * does not confirm: the whole list is refused.
	 */
	const QUESTION_WRITE = 'write';

	/**
	 * The question of a write the person confirmed from its preview: the list
	 * without the names of CONFIRMED.
	 */
	const QUESTION_CONFIRMED = 'confirmed';

	/**
	 * The names on the list a confirmed write may change: whether anyone may
	 * register, and which role a new account gets. They are a pair because
	 * they act together — open registration is harmless with a role that may
	 * do little and a takeover with one that may do much — and the tool that
	 * writes them (Pro's wp_update_option) checks the pair as a whole and the
	 * capabilities the role really grants. Everything else on the list keeps
	 * its own way (see blocked_error()) or stays closed.
	 *
	 * Matched exactly, never folded or trimmed: a name only becomes one of
	 * these when resolve_option() has found it to be that row.
	 */
	const CONFIRMED = array( 'users_can_register', 'default_role' );

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
	 * Since 4.17.0 it is the first of two questions (see the head of this
	 * class) and unchanged in what it refuses: the registration pair stays
	 * refused here. Only the words of a refusal changed — they name, per
	 * group of names, where the change is made instead (blocked_error()).
	 *
	 * @param string $name Name, already trimmed and redirected.
	 * @return WP_Error|false Error when refused, false when allowed.
	 */
	public static function option_write_refused( $name ) {
		return self::refusal_error( $name, self::refusal( $name, self::QUESTION_WRITE ) );
	}

	/**
	 * May a write the person confirmed from its preview change this option?
	 *
	 * The second question, made of the same list as the first and not of a
	 * second one: two lists of one thing drift, and this one decides what is
	 * written. It differs from option_write_refused() in exactly one point —
	 * the names of CONFIRMED, spelled exactly so, are allowed — and asks the
	 * other three checks (length, invisible characters, the fold) just the
	 * same. So " default_role " or "DEFAULT_ROLE" handed in as they are, or a
	 * spelling that only folds onto one of the pair, are refused here as
	 * well: such a name is the pair only once resolve_option() has found it
	 * to be that row, and then it is handed in as the row is spelled.
	 *
	 * Allowed here means allowed to be ASKED for: the writer still owes the
	 * confirmation and its own checks of the value (Pro: the capabilities of
	 * the role against a fixed ceiling).
	 *
	 * @param string $name Name, already trimmed and redirected.
	 * @return WP_Error|false Error when refused, false when allowed.
	 */
	public static function confirmed_write_refused( $name ) {
		return self::refusal_error( $name, self::refusal( $name, self::QUESTION_CONFIRMED ) );
	}

	/**
	 * The class of a name, from the two questions: CLASS_OPEN when the first
	 * allows it, CLASS_CONFIRM when only the second does, CLASS_BLOCKED when
	 * neither does.
	 *
	 * Asked about the name as it will be written — the 'name' resolve_option()
	 * gives back, which carries this class as well — never about a name as a
	 * caller sent it: " default_role " is CLASS_BLOCKED here (it is not that
	 * row's spelling), and only resolve_option() can say that it addresses the
	 * row default_role. A condition that read the raw argument could skip the
	 * confirmation and then write an opened option all the same.
	 *
	 * @param string $name Name, already settled.
	 * @return string One of the CLASS_* constants.
	 */
	public static function option_class( $name ) {
		if ( '' === self::refusal( $name, self::QUESTION_WRITE ) ) {
			return self::CLASS_OPEN;
		}
		if ( '' === self::refusal( $name, self::QUESTION_CONFIRMED ) ) {
			return self::CLASS_CONFIRM;
		}
		return self::CLASS_BLOCKED;
	}

	/**
	 * Why a question refuses a name: '' when it does not, else 'too_long',
	 * 'invalid' or 'blocked'. The two questions and the class are made of
	 * this one place, so a guard added here reaches all of them.
	 *
	 * @param string $name     Name.
	 * @param string $question QUESTION_WRITE or QUESTION_CONFIRMED.
	 * @return string
	 */
	private static function refusal( $name, $question ) {
		if ( self::too_long_for_column( $name ) ) {
			return 'too_long';
		}
		if ( self::has_invisible_chars( $name ) ) {
			return 'invalid';
		}
		if ( self::listed( $name, $question ) || self::folds_onto_listed( $name, $question ) ) {
			return 'blocked';
		}
		return '';
	}

	/**
	 * The answer of option_write_refused() and confirmed_write_refused() for
	 * a reason of refusal().
	 *
	 * @param string $name   Name.
	 * @param string $reason What refusal() said.
	 * @return WP_Error|false
	 */
	private static function refusal_error( $name, $reason ) {
		if ( 'too_long' === $reason ) {
			return new WP_Error( 'ab_mcp_option_name_too_long', __( 'The option name is longer than WordPress can store. An option name holds at most 191 characters. Use a shorter name.', 'alphabridge-mcp' ) );
		}
		if ( 'invalid' === $reason ) {
			return new WP_Error( 'ab_mcp_option_name_invalid', __( 'The option name contains characters that cannot be compared reliably. Leave out control and format characters (such as zero-width spaces).', 'alphabridge-mcp' ) );
		}
		if ( '' !== $reason ) {
			return self::blocked_error( $name );
		}
		return false;
	}

	/**
	 * Is the name on the list for this question? The list as is_blocked()
	 * asks it, less the names the question lifts.
	 *
	 * @param string $name     Name.
	 * @param string $question QUESTION_WRITE or QUESTION_CONFIRMED.
	 * @return bool
	 */
	private static function listed( $name, $question ) {
		return self::is_blocked( $name ) && ! self::lifted( $name, $question );
	}

	/**
	 * Does the name fold onto the list for this question? As
	 * folds_onto_blocked() asks it, less the names the question lifts.
	 *
	 * @param string $name     Name.
	 * @param string $question QUESTION_WRITE or QUESTION_CONFIRMED.
	 * @return bool
	 */
	private static function folds_onto_listed( $name, $question ) {
		return self::folds_onto_blocked( $name ) && ! self::lifted( $name, $question );
	}

	/**
	 * Does this question lift the name off the list? Only the confirmed one,
	 * and only for a name of CONFIRMED in exactly its spelling — strict, so
	 * that neither a number nor another case nor a fold can pass for it.
	 *
	 * @param mixed  $name     Name.
	 * @param string $question QUESTION_WRITE or QUESTION_CONFIRMED.
	 * @return bool
	 */
	private static function lifted( $name, $question ) {
		return self::QUESTION_CONFIRMED === $question && in_array( $name, self::CONFIRMED, true );
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
		return array_keys( self::blocklist_groups() );
	}

	/**
	 * The block list with the group of each name, which decides what a
	 * refusal says (blocked_error()). One list with two columns rather than
	 * a second list of the same names beside it.
	 *
	 * @return array<string,string> Name => group.
	 */
	private static function blocklist_groups() {
		return array(
			// Plugin secrets / internal state.
			AB_MCP_Settings::OPT_TOKENS    => 'own',
			AB_MCP_Settings::OPT_TOOLSTATE => 'own',
			AB_MCP_Settings::OPT_OPTIONS   => 'own',
			'ab_mcp_license'               => 'own', // Legacy pre-2.0 unlock codes; still protected on upgraded sites.
			'ab_mcp_pro_deploy'            => 'own', // Pro Site-Deploy: encrypted FTP/SFTP credentials + verification state.
			'auth_key'                     => 'credential',
			'auth_salt'                    => 'credential',
			'secret'                       => 'credential',
			// The other keys and salts wp_salt() reads from the options when
			// wp-config.php does not define them (secret_key is caught by the
			// needle "secret", the salts also by "_salt"). auth, secure_auth
			// and logged_in sign the login cookies, nonce the nonces: a new
			// value signs everyone out or breaks every open form. Missing from
			// the list until 4.17.0.
			'secure_auth_key'              => 'credential',
			'secure_auth_salt'             => 'credential',
			'logged_in_key'                => 'credential',
			'logged_in_salt'               => 'credential',
			'nonce_key'                    => 'credential',
			'nonce_salt'                   => 'credential',
			// Freemius, which Pro bundles: the user and install keys of the
			// licence, in one serialised value no needle hits.
			'fs_accounts'                  => 'credential',
			// WordPress refuses these two itself — with wp_die(), mid-request,
			// so the client gets an HTML death page instead of an answer.
			// Refusing them here turns that into an ordinary error.
			'alloptions'                   => 'cache',
			'notoptions'                   => 'cache',
			// Privilege / registration / code-execution surface.
			'default_role'                 => 'registration',
			'users_can_register'           => 'registration',
			'active_plugins'               => 'plugins',
			'active_sitewide_plugins'      => 'network_plugins',
			'template'                     => 'theme',
			'stylesheet'                   => 'theme',
			'siteurl'                      => 'address',
			'home'                         => 'address',
			'cron'                         => 'cron',
			'mailserver_url'               => 'mail',
			'mailserver_login'             => 'mail',
			'mailserver_pass'              => 'mail',
			'mailserver_port'              => 'mail',
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
	 * The two lists the write guard is made of, for a caller that has to
	 * turn them into SQL: the search & replace tool must keep refused names
	 * out of its scan BEFORE the LIKE on the value runs, or a name listed as
	 * skipped afterwards answers whether its value contains the needle.
	 *
	 * The first question's lists: a caller that turns them into SQL refuses
	 * the registration pair as option_write_refused() does.
	 *
	 * @return array{needles: string[], exact: string[]}
	 */
	public static function write_guard_lists() {
		return array(
			'needles' => self::needles(),
			'exact'   => array_map( 'strval', self::blocklist() ),
		);
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
	protected static function needles() {
		return array_keys( self::needle_groups() );
	}

	/**
	 * The needles with the group of each, as blocklist_groups() has them for
	 * whole names. In this order: a name that holds two needles belongs to
	 * the first («ab_mcp_token» is AlphaBridge's own).
	 *
	 * @return array<string,string> Needle => group.
	 */
	private static function needle_groups() {
		return array(
			// Every option this plugin keeps for itself. As a needle rather
			// than as list entries, because the list only knows the names
			// somebody remembered to add: ab_mcp_pro_undo_index and
			// ab_mcp_pro_undo_lock (the undo ledger, which decides what a
			// restore writes) and ab_mcp_audit (the log) were all missing, and
			// the next feature's option would have been missing too. Written
			// this way it also inherits the folding and the backstop below.
			'ab_mcp'        => 'own',
			// The roles of the site with every capability (wp_user_roles, and
			// wp_2_user_roles on a network).
			'user_roles'    => 'roles',
			'secret'        => 'credential',
			'password'      => 'credential',
			'passwd'        => 'credential',
			'api_key'       => 'credential',
			'apikey'        => 'credential',
			'api-key'       => 'credential',
			'access_key'    => 'credential',
			'private_key'   => 'credential',
			'consumer_key'  => 'credential',
			'client_secret' => 'credential',
			'token'         => 'credential',
			'credential'    => 'credential',
			'oauth'         => 'credential',
			'bearer'        => 'credential',
			// Every salt by its shape, as "secret" catches secret_key: the four
			// of wp_salt() are on the list by name as well, and a plugin's own
			// salt is a key like them.
			'_salt'         => 'credential',
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
	 * Public since 4.17.0 (until then the protected settle_option(), which
	 * nothing in this plugin called), so that everything one confirmed write
	 * consists of — the condition that decides whether it needs a
	 * confirmation, the preview and the write — settles the name the same way
	 * and acts on the same row. THE CONTRACT for those callers:
	 *
	 *  - Call it with the name exactly as the caller sent it, and with the
	 *    same question in the condition, the preview and the write.
	 *  - The question decides which names the loop refuses, nothing else:
	 *    QUESTION_WRITE every name on the list, as before 4.17.0;
	 *    QUESTION_CONFIRMED the list without the names of CONFIRMED. Anything
	 *    else counts as QUESTION_WRITE.
	 *  - The name is trimmed and the two legacy names are redirected first,
	 *    so " default_role " is default_role from the start.
	 *  - Under QUESTION_CONFIRMED a name the list refuses is still looked up,
	 *    because "DEFAULT_ROLE" or a folded spelling may be the row
	 *    default_role; then the round after asks about the row's own name. A refused name that addresses no row, or itself, stays refused:
	 *    without a row nothing says which row the INSERT would become, and
	 *    only the exact spelling of the pair is opened. The lookup reads the
	 *    name and the autoload flag, never the value.
	 *  - The answer is array( 'name', 'class', 'snapshot' ): the row the write
	 *    addresses, its class (CLASS_OPEN, or CLASS_CONFIRM for the pair under
	 *    QUESTION_CONFIRMED — the one a condition asks about) and the snapshot
	 *    of AB_MCP_Undo::option_snapshot(). Or a WP_Error, always with nothing
	 *    changed: ab_mcp_option_name_too_long, ab_mcp_option_name_invalid,
	 *    ab_mcp_blocked (with the way of the name's group) or
	 *    ab_mcp_option_unreadable.
	 *
	 * @param mixed  $given    Name as the caller gave it.
	 * @param string $question QUESTION_WRITE (default) or QUESTION_CONFIRMED.
	 * @return array|WP_Error array( 'name' => string, 'class' => string, 'snapshot' => array )
	 */
	public static function resolve_option( $given, $question = self::QUESTION_WRITE ) {
		$question = self::QUESTION_CONFIRMED === $question ? self::QUESTION_CONFIRMED : self::QUESTION_WRITE;
		$name     = self::real_option_name( $given );
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
			$listed = self::listed( $name, $question );
			// The first question refuses at once, as it always did. The
			// confirmed one looks the name up first: "DEFAULT_ROLE" is refused
			// as a spelling but may be the row default_role, which it opens.
			// It is refused below unless the row turns out to be another.
			if ( $listed && self::QUESTION_WRITE === $question ) {
				return self::blocked_error( $name );
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
				//
				// Under the confirmed question the same holds for the pair: a
				// spelling that only FOLDS onto default_role ("defavlt_role",
				// "DEFAULT_ROLE") with no row as witness is refused, not opened —
				// the pair is opened by its exact name only. (A name the list
				// itself refused is refused below in any case: with no row, it
				// stays the name it is.)
				if ( self::folds_onto_listed( $name, $question ) ) {
					return self::blocked_error( $name );
				}
			}
			$settled = is_array( $row ) ? self::real_option_name( $row['name'] ) : $name;
			if ( $settled !== $name ) {
				$name = $settled;
				continue;
			}
			// The name has settled — on a row of this very spelling, or on no
			// row. Now the WHOLE question is asked about it, not only the list:
			// a row can be stored under a folded spelling ("actiue_plugins",
			// "defavlt_role"), and a folding column reads that row as the
			// protected option. The list alone does not know the spelling, so
			// the lookup above settles on it as on any other row; the fold
			// does, and refuses it. This also refuses what the list refused
			// (only the confirmed question gets this far with such a name).
			$why = self::refusal( $name, $question );
			if ( '' !== $why ) {
				return self::refusal_error( $name, $why );
			}
			$snapshot = AB_MCP_Undo::option_snapshot( $name );
			if ( null === $snapshot ) {
				return new WP_Error( 'ab_mcp_option_unreadable', __( 'The option could not be looked up, so nothing was changed. Try again; if it keeps failing, the database error log names the cause.', 'alphabridge-mcp' ) );
			}
			return array(
				'name'     => $name,
				'class'    => self::option_class( $name ),
				'snapshot' => $snapshot,
			);
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

	/**
	 * The refusal of a protected name, with the way of its group.
	 *
	 * Until 4.17.0 every protected name got one sentence: «An administrator
	 * changes it in wp-admin». For most of them AlphaBridge itself has the
	 * way — the plugin and theme switches with their checks, Pro's cron and
	 * network tools, the address tool, the confirmed write of the pair — and
	 * an assistant told only «wp-admin» sends the person there for nothing.
	 * So the answer names the way per group, as a reason, the steps and the
	 * link (AB_MCP_Guidance::compose()); the code stays ab_mcp_blocked, which
	 * callers test for.
	 *
	 * A tool of AlphaBridge MCP Pro is named only where the edition of this
	 * site has it (edition_has()); elsewhere the answer names the way in
	 * wp-admin — never Pro itself: these texts go to an assistant, and an
	 * assistant is not where an edition is advertised (see AB_MCP_Guidance).
	 *
	 * @param string $name Name as the question saw it.
	 * @return WP_Error
	 */
	private static function blocked_error( $name ) {
		return new WP_Error( 'ab_mcp_blocked', self::blocked_message( self::group_of( $name ) ) );
	}

	/**
	 * The group of a protected name: the group of its list entry, else of
	 * the first needle it holds, else of the entry or needle it folds onto.
	 * 'protected' when none can be named (a spelling only the wildcard step
	 * of the fold refuses), which keeps the sentence of before 4.17.0.
	 *
	 * @param string $name Name.
	 * @return string
	 */
	private static function group_of( $name ) {
		$lower = strtr( trim( (string) $name ), 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz' );
		foreach ( self::blocklist_groups() as $b => $group ) {
			if ( $lower === strtolower( (string) $b ) ) {
				return $group;
			}
		}
		foreach ( self::needle_groups() as $needle => $group ) {
			if ( false !== strpos( $lower, $needle ) ) {
				return $group;
			}
		}
		foreach ( self::collation_forms( $name ) as $folded ) {
			foreach ( self::blocklist_groups() as $b => $group ) {
				if ( $folded === self::collation_form( $b ) ) {
					return $group;
				}
			}
			foreach ( self::needle_groups() as $needle => $group ) {
				if ( false !== strpos( $folded, self::collation_form( $needle ) ) ) {
					return $group;
				}
			}
		}
		return 'protected';
	}

	/**
	 * What a refusal says for a group.
	 *
	 * @param string $group Group, as blocklist_groups() and needle_groups() name it.
	 * @return string
	 */
	private static function blocked_message( $group ) {
		switch ( $group ) {
			case 'plugins':
				return self::composed(
					__( 'This option is the list of active plugins. Written directly, a plugin that fails to load would stop every request of the site, this connection and wp_undo included, so only the plugin tools change it.', 'alphabridge-mcp' ),
					__( '1. Switch a plugin on with wp_activate_plugin: it checks the site before and after and takes the plugin back where the site does worse. 2. Switch one off with wp_deactivate_plugin, which also removes an entry whose plugin file is missing.', 'alphabridge-mcp' ),
					self::admin_link( 'plugins.php' )
				);
			case 'theme':
				return self::composed(
					__( 'This option names the active theme. Written directly, a theme that fails to load would stop every request of the site, and a theme and its parent could end up out of step, so only the theme tool changes it.', 'alphabridge-mcp' ),
					__( 'Switch the theme with wp_activate_theme: it writes the theme and its parent together, checks the site before and after, takes the switch back where the site does worse and records an undo point.', 'alphabridge-mcp' ),
					self::admin_link( 'themes.php' )
				);
			case 'network_plugins':
				return self::composed(
					__( 'This option is the list of plugins active on the whole network of a multisite installation; written as an option of one site, it would change nothing WordPress reads.', 'alphabridge-mcp' ),
					self::edition_has( 'wp_network_activate_plugin' )
						? __( 'Activate a plugin for the whole network with wp_network_activate_plugin; a network administrator deactivates one under Network Admin › Plugins.', 'alphabridge-mcp' )
						: __( 'A network administrator activates and deactivates plugins for the whole network under Network Admin › Plugins.', 'alphabridge-mcp' ),
					function_exists( 'network_admin_url' ) ? network_admin_url( 'plugins.php' ) : self::admin_link( 'network/plugins.php' )
				);
			case 'cron':
				return self::composed(
					__( 'This option holds every scheduled event of the site, and a value in the wrong form would delete them all.', 'alphabridge-mcp' ),
					self::edition_has( 'wp_schedule_cron' ) && self::edition_has( 'wp_unschedule_cron' )
						? __( '1. Schedule an event with wp_schedule_cron. 2. Remove one with wp_unschedule_cron. wp_get_cron lists them.', 'alphabridge-mcp' )
						: __( 'Scheduled events belong to the plugins that schedule them: change one in the settings of its plugin. wp_get_cron lists them.', 'alphabridge-mcp' ),
					self::admin_link( 'plugins.php' )
				);
			case 'address':
				return self::composed(
					__( 'This option is part of the address of the site. Changing it takes a check that the new address reaches this site, and a way back where it does not, so it is not written as an option.', 'alphabridge-mcp' ),
					self::edition_has( 'wp_change_site_address' )
						? __( 'Change the address with wp_change_site_address: it checks the new address before and after the change and shows a preview first.', 'alphabridge-mcp' )
						: __( 'An administrator changes the WordPress Address and the Site Address under Settings › General.', 'alphabridge-mcp' ),
					self::admin_link( 'options-general.php' )
				);
			case 'registration':
				return self::composed(
					__( 'This option decides whether anyone may register on the site and which role a new account gets, so it is changed only with a confirmation from a preview.', 'alphabridge-mcp' ),
					self::edition_has( 'wp_update_option' )
						? __( 'Change it with wp_update_option: the first call changes nothing and shows a preview with a confirm_token, and the same call with that token writes it and records an undo point.', 'alphabridge-mcp' )
						: __( 'An administrator changes it under Settings › General (Membership and New User Default Role).', 'alphabridge-mcp' ),
					self::admin_link( 'options-general.php' )
				);
			case 'own':
				return self::composed(
					__( 'This option belongs to AlphaBridge itself (its connections, settings or records), and no assistant changes it.', 'alphabridge-mcp' ),
					__( 'An administrator changes the settings of AlphaBridge on its pages in wp-admin.', 'alphabridge-mcp' ),
					class_exists( 'AB_MCP_Guidance' ) ? AB_MCP_Guidance::settings_url() : self::admin_link( 'admin.php?page=alphabridge-connection' )
				);
			case 'roles':
				return self::composed(
					__( 'This option holds the roles of the site with all their capabilities; one wrong value would take them from every role, administrators included.', 'alphabridge-mcp' ),
					__( 'An administrator gives an account another role under Users; the capabilities of a role itself are changed with a plugin for managing roles.', 'alphabridge-mcp' ),
					self::admin_link( 'users.php' )
				);
			case 'mail':
				return self::composed(
					__( 'This option belongs to posting by email, and one of these options holds the password of the mailbox, so no assistant changes them.', 'alphabridge-mcp' ),
					__( 'An administrator changes them under Settings › Writing (Post via email).', 'alphabridge-mcp' ),
					self::admin_link( 'options-writing.php' )
				);
			case 'cache':
				// No steps and no link: there is nothing for anyone to do.
				return __( 'This name is not a setting: WordPress keeps its cache of the options under it and refuses to write it itself. Nothing needs changing here.', 'alphabridge-mcp' );
		}
		// Keys, passwords and other credentials, and a spelling no group
		// could be named for: an administrator changes them where WordPress
		// or the plugin they belong to offers it (the salts of WordPress in
		// wp-config.php), or nobody does.
		return __( 'This option is protected and cannot be changed via MCP. An administrator changes it in wp-admin, where WordPress or its plugin offers it.', 'alphabridge-mcp' );
	}

	/**
	 * Does the edition of this site have this tool of AlphaBridge MCP Pro?
	 *
	 * The edition as Pro reports it (ab_mcp_site_edition; 'pro' or 'agency'
	 * only while its licence is active), read fail closed: anything unknown
	 * is the free edition. A tool of the Agency plan takes 'agency'
	 * (AB_MCP_Guidance::AGENCY_TOOLS); every other tool asked about here is
	 * one of Pro's.
	 *
	 * @param string $tool Tool name.
	 * @return bool
	 */
	private static function edition_has( $tool ) {
		$edition = function_exists( 'apply_filters' ) ? (string) apply_filters( 'ab_mcp_site_edition', 'free' ) : 'free';
		if ( class_exists( 'AB_MCP_Guidance' ) && 'agency' === AB_MCP_Guidance::edition_of( $tool ) ) {
			return 'agency' === $edition;
		}
		return in_array( $edition, array( 'pro', 'agency' ), true );
	}

	/**
	 * A refusal as AB_MCP_Guidance::compose() writes it, or reason and steps
	 * alone where this class runs without it (scripts that load the guard on
	 * its own).
	 *
	 * @param string $reason Why.
	 * @param string $steps  What to do.
	 * @param string $url    Where.
	 * @return string
	 */
	private static function composed( $reason, $steps, $url ) {
		if ( class_exists( 'AB_MCP_Guidance' ) ) {
			return AB_MCP_Guidance::compose( $reason, $steps, $url );
		}
		return trim( $reason . ' ' . $steps );
	}

	/**
	 * An address in wp-admin, or '' where admin_url() is not loaded.
	 *
	 * @param string $path Path below wp-admin.
	 * @return string
	 */
	private static function admin_link( $path ) {
		return function_exists( 'admin_url' ) ? admin_url( $path ) : '';
	}
}

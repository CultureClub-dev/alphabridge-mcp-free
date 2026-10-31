<?php
/**
 * «Staging first»: the pre-check of a write on a site that counts as the
 * production site (free since 4.15.0).
 *
 * A change on the production site is visible to visitors at once; on a
 * staging site it is checked first and carried over once it is approved.
 * So before a connection changes anything on a site that is neither the
 * confirmed staging site nor paired with one (AB_MCP_Staging, the plan of
 * 31.10.2026, decision 7), this gate pauses the first writing call and
 * hands the assistant what to say: the reason, the ways to a staging site,
 * and the offer to set it up now and to continue there. The setting
 * SETTING in ab_mcp_options has three values:
 *
 * - OFF: nothing is asked.
 * - ASK (the default, for every site, those updated from an earlier version
 *   included; Thomas, 31.10.2026): the first writing call of a connection is
 *   not run but answered as a result (not an error) with nothing_changed
 *   and staging_hint; the same call with staging_acknowledged true runs,
 *   and the connection is not asked again for ACK_DAYS. After that the
 *   question comes back, now with two more answers the assistant passes in
 *   staging_reminder: REMINDER_WEEK (another ACK_DAYS) or REMINDER_NEVER
 *   (no more for this connection, until the setting or the role of the site
 *   changes; the settings page shows it and takes it back). The question
 *   comes with the next writing call, not as a notification. The parameter
 *   says the person was asked; it cannot prove it (the concept says so).
 * - REQUIRE: every writing call is refused (staging_required, nothing ran)
 *   until a person releases the connection on the settings page, for
 *   RELEASE_HOURS or until revoked, with a history in OPTION. A role or a
 *   pair the hub wrote over the REST route does not lift this (decision 8):
 *   a token holder can write there, and the release has to stay a person's.
 *
 * What is a write: every tool AB_MCP_Tool_Registry::is_read_only() does not
 * count as reading, except the names of exempt_tools() (ways back and the
 * protection of the staging site; operation that must go on on the
 * production site; the carrying over from staging, once it exists). A
 * preview (apply=false, dry_run) counts as a write: the pause comes before
 * the first look at a change. The gate stands in
 * AB_MCP_REST_Controller::run_tool() after the policy gate, the arguments
 * and the redactor, and BEFORE the confirmation token
 * (AB_MCP_Confirm_Token): the first call of wp_update_core would otherwise
 * make a preview the pause throws away.
 *
 * A connection is an entry in ab_mcp_tokens, known to the call by the hash
 * of its token (AB_MCP_Auth::current_token_hash()); the acknowledgement and
 * the release live in that entry (FIELD_ACK, FIELD_NEVER, FIELD_RELEASE),
 * not in a transient, which an object cache can lose. A call through the
 * WordPress abilities adapter (AB_MCP_Own_Abilities) has no token: it is
 * kept by the id of its account in OPTION, and not exempt.
 *
 * A connection that existed before this version (its entry created before
 * the time this version first loaded, KEY_SINCE) is told that this is new
 * in 4.15.0; a connection made after that gets the same question without
 * «new». The stamp is written on the first load of the plugin and on
 * activation (AB_MCP_Plugin), before any request that could create a token
 * in the same request runs (tokens are made on admin-ajax, admin-post and
 * the OAuth token route, all after init), so no token made under this
 * version carries a created time before it.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-staging.php';

/**
 * Class AB_MCP_Staging_Gate
 */
class AB_MCP_Staging_Gate {

	/**
	 * Key of the setting in AB_MCP_Settings::OPT_OPTIONS; its default is in
	 * AB_MCP_Settings::defaults().
	 */
	const SETTING = 'staging_first';

	/**
	 * The version that brought the gate: what a connection from before it
	 * is told is new. A constant, not AB_MCP_VERSION: a later version asks
	 * such a connection the same, and what is new to it is still this.
	 */
	const SINCE_VERSION = '4.15.0';

	/**
	 * The three values of the setting.
	 */
	const OFF     = 'off';
	const ASK     = 'ask';
	const REQUIRE = 'require';

	/**
	 * Key in AB_MCP_Settings::OPT_OPTIONS: time() when this version first
	 * loaded on this site (stamp()).
	 */
	const KEY_SINCE = 'staging_first_since';

	/**
	 * The option of the gate's own: the history of releases and
	 * revocations ('history'), and the state of the callers without a token
	 * ('users', by account id). uninstall.php removes it.
	 */
	const OPTION = 'ab_mcp_staging_first';

	/**
	 * The two arguments every writing tool takes for the gate.
	 */
	const PARAM          = 'staging_acknowledged';
	const PARAM_REMINDER = 'staging_reminder';

	/**
	 * The two answers to the repeated question.
	 */
	const REMINDER_WEEK  = 'week';
	const REMINDER_NEVER = 'never';

	/**
	 * How long an acknowledgement holds (ASK), and a release (REQUIRE).
	 */
	const ACK_DAYS      = 7;
	const RELEASE_HOURS = 24;

	/**
	 * The status in the log of a call the gate answered instead of the tool.
	 */
	const STATUS_HELD = 'held';

	/**
	 * The fields in a token entry (AB_MCP_Settings::OPT_TOKENS), and in a
	 * user's state in OPTION.
	 */
	const FIELD_ACK        = 'staging_ack';
	const FIELD_NEVER      = 'staging_ack_never';
	const FIELD_RELEASE    = 'staging_release_until';
	const FIELD_RELEASE_BY = 'staging_release_by';

	/**
	 * The filter over exempt_tools().
	 */
	const FILTER = 'ab_mcp_staging_gate_exempt';

	/**
	 * Anchor of the section on the settings page.
	 */
	const ANCHOR = 'ab-staging-first';

	/**
	 * Entries the history keeps.
	 */
	const HISTORY_MAX = 50;

	/**
	 * What the answer of a held call carries under 'held'.
	 */
	const HELD = 'staging_first';

	/**
	 * Tools the gate never holds, by reason, so that the list grows by
	 * reason (Thomas, 31.10.2026: the end state is a staging site with a
	 * complete way back; this list must not stand in its way).
	 *
	 * (a) WAYS_BACK: the ways back and the protection of the staging site. A
	 * way back is not held, whatever the filter says.
	 * (b) OPERATION: what has to go on on the production site, with the
	 * setting REQUIRE as well, and is not a change of the site a person
	 * would check on staging first: answering and moderating comments, the
	 * status of an order. The diagnostic tools (wp_connection_doctor,
	 * wp_site_health, wp_site_info, wp_get_error_log) only read, so
	 * is_read_only() keeps them out of the gate already; a test holds that.
	 * (c) CARRY_OVER: the carrying over from the staging site (S2 of the
	 * staging concept), named here before it exists so that the setting
	 * REQUIRE does not refuse it once it does. The signed fetch of packages
	 * and media of S2 joins this list with its names.
	 */
	const WAYS_BACK  = array( 'wp_undo', 'wp_undo_discard', 'wp_set_preview_protection', 'wp_clear_cache' );
	const OPERATION  = array( 'wp_moderate_comment', 'wp_reply_comment', 'wp_wc_update_order_status' );
	const CARRY_OVER = array( 'wp_site_push', 'wp_site_sync' );

	/**
	 * The tools the gate never holds: the three groups, and what the filter
	 * FILTER adds. The filter can add names; it cannot take WAYS_BACK away.
	 *
	 * @return string[]
	 */
	public static function exempt_tools() {
		$exempt = array_merge( self::WAYS_BACK, self::OPERATION, self::CARRY_OVER );
		/**
		 * The tools «Staging first» never holds back. An add-on names the
		 * tools of its own that are operation of the production site or the
		 * carrying over from a staging site. Names of WAYS_BACK stay exempt
		 * whatever the filter returns.
		 *
		 * @since 4.15.0
		 *
		 * @param string[] $exempt Tool names.
		 */
		$filtered = apply_filters( self::FILTER, $exempt );
		$filtered = is_array( $filtered ) ? array_filter( array_map( 'strval', $filtered ), 'strlen' ) : array();
		return array_values( array_unique( array_merge( self::WAYS_BACK, $filtered ) ) );
	}

	/**
	 * Does the gate concern this tool: a writing tool that is not exempt?
	 * The same question decides which schemas take PARAM
	 * (AB_MCP_Tool_Registry::register()).
	 *
	 * @param string $name Tool name.
	 * @param array  $def  Tool definition.
	 * @return bool
	 */
	public static function concerns( $name, array $def ) {
		if ( AB_MCP_Tool_Registry::is_read_only( $name, $def ) ) {
			return false;
		}
		return ! in_array( (string) $name, self::exempt_tools(), true );
	}

	/* ---------------------------------------------------------- schema */

	/**
	 * The schema property PARAM of every tool the gate concerns.
	 *
	 * @return array
	 */
	public static function schema_property() {
		return array(
			'type'        => 'boolean',
			'description' => 'true says the person was asked whether to work on staging first and chose to go ahead on this site, after an answer with staging_hint; this connection is then not asked again for 7 days. A call without it may be answered with nothing_changed and staging_hint instead of running, on a site that counts as the production site.',
		);
	}

	/**
	 * The schema property PARAM_REMINDER of every tool the gate concerns.
	 *
	 * @return array
	 */
	public static function reminder_property() {
		return array(
			'type'        => 'string',
			'enum'        => array( self::REMINDER_WEEK, self::REMINDER_NEVER ),
			'description' => 'With staging_acknowledged true, once the staging question has come back after 7 days: the person\'s answer. week asks again in a week at the next change (the default); never asks this connection no more, until the setting Staging first or the role of the site changes.',
		);
	}

	/**
	 * The sentence added to the description of every tool the gate concerns.
	 *
	 * @return string
	 */
	public static function description_note() {
		return 'On a site that counts as the production site, a connection\'s first writing call may come back with nothing_changed and staging_hint instead of running (Staging first); the same call with staging_acknowledged true runs.';
	}

	/**
	 * The arguments without the gate's own, as the tool and the
	 * confirmation token receive them: a token issued with the
	 * acknowledgement in the arguments must match the call without it.
	 *
	 * @param array $args Arguments.
	 * @return array
	 */
	public static function without_params( array $args ) {
		unset( $args[ self::PARAM ], $args[ self::PARAM_REMINDER ] );
		return $args;
	}

	/* --------------------------------------------------------- setting */

	/**
	 * Is a value one of the three?
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	public static function is_setting( $value ) {
		return in_array( $value, array( self::OFF, self::ASK, self::REQUIRE ), true );
	}

	/**
	 * The setting as it counts: a stored value that is none of the three
	 * reads as the default, ASK.
	 *
	 * @return string
	 */
	public static function setting() {
		$value = AB_MCP_Settings::get( self::SETTING, self::ASK );
		return self::is_setting( $value ) ? $value : self::ASK;
	}

	/**
	 * Save the setting. A change takes every «no more» of the connections
	 * back: it was given under the setting that is gone.
	 *
	 * @param string $value OFF, ASK or REQUIRE.
	 * @return bool|WP_Error Whether it changed.
	 */
	public static function set_setting( $value ) {
		if ( ! self::is_setting( $value ) ) {
			return new WP_Error( 'ab_mcp_staging_first_invalid', __( 'Nothing was saved: the setting must be off, ask or require.', 'alphabridge-mcp' ) );
		}
		if ( self::setting() === $value ) {
			return false;
		}
		AB_MCP_Settings::set( self::SETTING, $value );
		self::clear_never_marks();
		return true;
	}

	/**
	 * The role of the site changed (AB_MCP_Staging::save()): every «no
	 * more» was given for the site as it was, so it is taken back.
	 */
	public static function on_role_changed() {
		self::clear_never_marks();
	}

	/**
	 * Take every «no more» back, of the connections and of the callers
	 * without a token. The acknowledgements of the last ACK_DAYS stay: a
	 * connection that was asked this week is not asked twice.
	 */
	public static function clear_never_marks() {
		$tokens  = AB_MCP_Settings::get_tokens();
		$changed = false;
		foreach ( $tokens as &$entry ) {
			if ( is_array( $entry ) && array_key_exists( self::FIELD_NEVER, $entry ) ) {
				unset( $entry[ self::FIELD_NEVER ] );
				$changed = true;
			}
		}
		unset( $entry );
		if ( $changed ) {
			update_option( AB_MCP_Settings::OPT_TOKENS, $tokens );
		}
		$own = self::own();
		foreach ( $own['users'] as $id => $state ) {
			if ( is_array( $state ) && array_key_exists( self::FIELD_NEVER, $state ) ) {
				unset( $own['users'][ $id ][ self::FIELD_NEVER ] );
				$changed = true;
			}
		}
		if ( $changed ) {
			update_option( self::OPTION, $own, false );
		}
	}

	/* ----------------------------------------------------------- stamp */

	/**
	 * Note when this version first loaded, once. Called on every load
	 * (AB_MCP_Plugin) and on activation; it writes only the one time the
	 * key is missing.
	 *
	 * @param int|null $now Current time (tests).
	 * @return bool Whether it was written now.
	 */
	public static function stamp( $now = null ) {
		if ( self::since() > 0 ) {
			return false;
		}
		AB_MCP_Settings::set( self::KEY_SINCE, null === $now ? time() : (int) $now );
		return true;
	}

	/**
	 * When this version first loaded; 0 before stamp().
	 *
	 * @return int
	 */
	public static function since() {
		return max( 0, (int) AB_MCP_Settings::get( self::KEY_SINCE, 0 ) );
	}

	/**
	 * Did this connection exist before this version: was its entry created
	 * before the version first loaded? Without a stamp, or without a
	 * created time, no.
	 *
	 * @param array $entry Token entry.
	 * @return bool
	 */
	public static function is_existing( array $entry ) {
		$since   = self::since();
		$created = isset( $entry['created'] ) ? (int) $entry['created'] : 0;
		return $since > 0 && $created > 0 && $created < $since;
	}

	/* ----------------------------------------------------------- site */

	/**
	 * Does the site stand outside the gate: is it the confirmed staging
	 * site, or does it hold a confirmed pair? With REQUIRE, a role or a
	 * pair counts only when a person confirmed it on the settings page
	 * (AB_MCP_Staging::SOURCE_FORM): one written over the REST route by a
	 * token holder would let the token holder lift the person's release.
	 * With ASK, every source counts: the pause stays a pause.
	 *
	 * @param string $setting ASK or REQUIRE.
	 * @return bool
	 */
	public static function site_exempt( $setting ) {
		$record = AB_MCP_Staging::record();
		$form   = static function ( $part ) use ( $setting ) {
			return self::REQUIRE !== $setting || AB_MCP_Staging::SOURCE_FORM === $part['source'];
		};
		if ( null !== $record['role'] && AB_MCP_Staging::ROLE_STAGING === $record['role']['value'] && $form( $record['role'] ) ) {
			return true;
		}
		if ( null !== $record['pair'] && $form( $record['pair'] ) ) {
			return true;
		}
		return false;
	}

	/* ------------------------------------------------------ connection */

	/**
	 * The connection of the current call: the token entry's hash, or the
	 * account of a call without a token (the abilities adapter).
	 *
	 * @return array{kind:string,id:string} kind 'token' or 'user'.
	 */
	public static function connection() {
		$hash = AB_MCP_Auth::current_token_hash();
		if ( '' !== $hash ) {
			return array(
				'kind' => 'token',
				'id'   => $hash,
			);
		}
		return array(
			'kind' => 'user',
			'id'   => (string) (int) get_current_user_id(),
		);
	}

	/**
	 * The option of the gate's own in a known shape.
	 *
	 * @return array{history:array,users:array}
	 */
	private static function own() {
		$own = get_option( self::OPTION, array() );
		$own = is_array( $own ) ? $own : array();
		return array(
			'history' => isset( $own['history'] ) && is_array( $own['history'] ) ? $own['history'] : array(),
			'users'   => isset( $own['users'] ) && is_array( $own['users'] ) ? $own['users'] : array(),
		);
	}

	/**
	 * The gate's fields of a connection: the entry of a token, the state of
	 * an account.
	 *
	 * @param array $conn connection().
	 * @return array
	 */
	public static function state( array $conn ) {
		if ( 'token' === $conn['kind'] ) {
			$entry = AB_MCP_Settings::get_token_by_hash( $conn['id'] );
			return is_array( $entry ) ? $entry : array();
		}
		$users = self::own()['users'];
		return isset( $users[ $conn['id'] ] ) && is_array( $users[ $conn['id'] ] ) ? $users[ $conn['id'] ] : array();
	}

	/**
	 * Write the gate's fields of a connection; a field set to null is
	 * removed.
	 *
	 * @param array $conn   connection().
	 * @param array $fields Field => value.
	 * @return bool Whether the connection was found.
	 */
	public static function save_state( array $conn, array $fields ) {
		if ( 'token' === $conn['kind'] ) {
			return AB_MCP_Settings::update_token_fields( $conn['id'], $fields );
		}
		$own   = self::own();
		$state = isset( $own['users'][ $conn['id'] ] ) && is_array( $own['users'][ $conn['id'] ] ) ? $own['users'][ $conn['id'] ] : array();
		foreach ( $fields as $key => $value ) {
			if ( null === $value ) {
				unset( $state[ $key ] );
			} else {
				$state[ $key ] = $value;
			}
		}
		$own['users'][ $conn['id'] ] = $state;
		update_option( self::OPTION, $own, false );
		return true;
	}

	/**
	 * Does the acknowledgement of a connection hold: one of the last
	 * ACK_DAYS, or a «no more»?
	 *
	 * @param array $state state().
	 * @param int   $now   Current time.
	 * @return bool
	 */
	public static function acknowledged( array $state, $now ) {
		if ( isset( $state[ self::FIELD_NEVER ] ) && is_array( $state[ self::FIELD_NEVER ] ) ) {
			return true;
		}
		$ack = isset( $state[ self::FIELD_ACK ] ) ? (int) $state[ self::FIELD_ACK ] : 0;
		return $ack > 0 && (int) $now < $ack + self::ACK_DAYS * DAY_IN_SECONDS;
	}

	/**
	 * Was this connection asked before: has it acknowledged once?
	 *
	 * @param array $state state().
	 * @return bool
	 */
	public static function asked_before( array $state ) {
		return isset( $state[ self::FIELD_ACK ] ) && (int) $state[ self::FIELD_ACK ] > 0;
	}

	/**
	 * Is a connection released to work without staging (REQUIRE)?
	 *
	 * @param array $state state().
	 * @param int   $now   Current time.
	 * @return bool
	 */
	public static function released( array $state, $now ) {
		$until = isset( $state[ self::FIELD_RELEASE ] ) ? (int) $state[ self::FIELD_RELEASE ] : 0;
		return $until > (int) $now;
	}

	/* ------------------------------------------------------------ gate */

	/**
	 * Gate a call. True when the tool may run; an array when the call is
	 * answered with staging_hint instead (ASK); a WP_Error when it is
	 * refused (REQUIRE).
	 *
	 * @param string   $name Tool name.
	 * @param array    $def  Tool definition.
	 * @param array    $args Arguments as sent.
	 * @param int|null $now  Current time (tests).
	 * @return true|array|WP_Error
	 */
	public static function gate( $name, array $def, array $args, $now = null ) {
		$now = null === $now ? time() : (int) $now;
		if ( ! self::concerns( $name, $def ) ) {
			return true;
		}
		$setting = self::setting();
		if ( self::OFF === $setting ) {
			return true;
		}
		if ( self::site_exempt( $setting ) ) {
			return true;
		}
		$conn  = self::connection();
		$state = self::state( $conn );
		if ( self::REQUIRE === $setting ) {
			return self::released( $state, $now ) ? true : self::refusal( $conn );
		}
		if ( self::acknowledged( $state, $now ) ) {
			return true;
		}
		if ( self::is_true( isset( $args[ self::PARAM ] ) ? $args[ self::PARAM ] : null ) ) {
			$fields = array( self::FIELD_ACK => $now );
			if ( self::asked_before( $state ) && isset( $args[ self::PARAM_REMINDER ] ) && self::REMINDER_NEVER === $args[ self::PARAM_REMINDER ] ) {
				$fields[ self::FIELD_NEVER ] = array(
					'at'      => $now,
					'setting' => $setting,
					'role'    => AB_MCP_Staging::role(),
				);
			}
			self::save_state( $conn, $fields );
			return true;
		}
		return self::held( $conn, $state );
	}

	/**
	 * Is an argument true: a boolean true, or the strings and numbers a
	 * client sends for it.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private static function is_true( $value ) {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( is_int( $value ) || is_string( $value ) ) {
			return true === filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
		}
		return false;
	}

	/**
	 * The answer of a held call (ASK): a result, not an error.
	 *
	 * @param array $conn  connection().
	 * @param array $state state().
	 * @return array
	 */
	public static function held( array $conn, array $state ) {
		$repeat   = self::asked_before( $state );
		$existing = 'token' === $conn['kind'] && ! $repeat && self::is_existing( $state );
		$out      = array(
			'nothing_changed' => true,
			'held'            => self::HELD,
			'staging_role'    => AB_MCP_Staging::role(),
			'staging_hint'    => self::hint( $existing, $repeat ),
			'asked_before'    => $repeat,
			'new_in_version'  => $existing ? self::SINCE_VERSION : null,
			'go_ahead_with'   => array( self::PARAM => true ),
		);
		if ( $repeat ) {
			$out['reminder_options'] = array( self::REMINDER_WEEK, self::REMINDER_NEVER );
		}
		return $out;
	}

	/**
	 * The hint for the assistant: the reason, the ways, the offer and how
	 * to go ahead. For a connection from before this version it opens with
	 * what is new; for a connection asked before, it is the question again
	 * with the two further answers. Written for the assistant, in English
	 * like the tool descriptions; the assistant tells the person in theirs.
	 *
	 * @param bool $existing The connection existed before this version.
	 * @param bool $repeat   The connection acknowledged once already.
	 * @return string
	 */
	public static function hint( $existing, $repeat ) {
		$reason = 'This site counts as the production site (no confirmed staging role and no pair), so a change here is visible to visitors at once; on a staging site it is checked first and carried over once it is approved. Nothing has been changed yet.';
		$ways   = 'how: a staging copy with the host\'s staging function, a staging plugin, an existing staging site connected to this one, or ' . AB_MCP_Staging::drafts_in_instructions() . '.';
		$offer  = 'Offer to set up staging now and to continue there: wp_staging_status shows where the site stands and the next step, and the prompt staging_workflow has the steps.';
		if ( $repeat ) {
			return 'Staging first, again: the week since the last answer is over. ' . $reason
				. ' Ask the person again whether to set up a staging site now and continue there, saying why and ' . $ways . ' ' . $offer
				. ' To go ahead on this site, call the same tool again with the same arguments and ' . self::PARAM . ' true, and pass the person\'s choice in ' . self::PARAM_REMINDER
				. ': ' . self::REMINDER_WEEK . ' asks again in a week, with the next change (no notification); ' . self::REMINDER_NEVER . ' asks this connection no more, until the setting Staging first or the role of the site changes. Without ' . self::PARAM_REMINDER . ', ' . self::REMINDER_WEEK . '.';
		}
		$opening = $existing
			? 'New in AlphaBridge MCP ' . self::SINCE_VERSION . ': Staging first. Before the first change, the assistant offers to work on a staging site. '
			: 'Staging first. ';
		return $opening . $reason
			. ' Ask the person whether to work on staging first, saying why and ' . $ways . ' ' . $offer
			. ' If the person would rather go ahead on this site, call the same tool again with the same arguments and ' . self::PARAM . ' true; this connection is then not asked again for ' . self::ACK_DAYS . ' days, with the next change after that.';
	}

	/**
	 * The refusal with REQUIRE: why, the steps and the link
	 * (AB_MCP_Guidance::compose()). A call without a token (the abilities
	 * adapter) has no connection the settings page could release; its
	 * steps say so.
	 *
	 * @param array $conn connection().
	 * @return WP_Error
	 */
	public static function refusal( array $conn ) {
		$reason = __( 'Nothing ran: this site requires staging first. It counts as the production site, where a change is visible to visitors at once, and a change here needs a release by a person on the settings page.', 'alphabridge-mcp' );
		$way    = __( '1. Work on a staging site instead: wp_staging_status shows where the site stands and the next step, and the prompt staging_workflow has the steps.', 'alphabridge-mcp' );
		if ( 'token' === $conn['kind'] ) {
			/* translators: %d: hours a release holds. */
			$release = sprintf( __( '2. To change this site anyway, an administrator releases this connection on the settings page of AlphaBridge MCP under Staging first («Work without staging»), for %d hours or until revoked. 3. Or an administrator sets Staging first to Ask or Off there.', 'alphabridge-mcp' ), self::RELEASE_HOURS );
		} else {
			$release = __( '2. This call came through the WordPress abilities adapter, which has no connection the settings page could release: change the site through an AlphaBridge connection an administrator releases there, or an administrator sets Staging first to Ask or Off.', 'alphabridge-mcp' );
		}
		return new WP_Error(
			'ab_mcp_staging_required',
			AB_MCP_Guidance::compose( $reason, $way . ' ' . $release, AB_MCP_Guidance::settings_url( self::ANCHOR ) )
		);
	}

	/* --------------------------------------------------------- release */

	/**
	 * Release a connection to work without staging, for RELEASE_HOURS
	 * (REQUIRE). A person on the settings page does this; nothing else.
	 *
	 * @param string   $hash    Token hash.
	 * @param int      $user_id Who releases.
	 * @param int|null $now     Current time (tests).
	 * @return int|false The time the release ends, or false for an unknown connection.
	 */
	public static function release( $hash, $user_id, $now = null ) {
		$now   = null === $now ? time() : (int) $now;
		$entry = AB_MCP_Settings::get_token_by_hash( (string) $hash );
		if ( ! is_array( $entry ) ) {
			return false;
		}
		$until = $now + self::RELEASE_HOURS * HOUR_IN_SECONDS;
		$who   = self::who( (int) $user_id, $now );
		AB_MCP_Settings::update_token_fields(
			(string) $hash,
			array(
				self::FIELD_RELEASE    => $until,
				self::FIELD_RELEASE_BY => $who,
			)
		);
		self::remember( array_merge( array( 'action' => 'release', 'hash' => (string) $hash, 'label' => isset( $entry['label'] ) ? (string) $entry['label'] : '', 'until' => $until ), $who ) );
		return $until;
	}

	/**
	 * Revoke a release before it ends.
	 *
	 * @param string   $hash    Token hash.
	 * @param int      $user_id Who revokes.
	 * @param int|null $now     Current time (tests).
	 * @return bool Whether a release was running.
	 */
	public static function revoke( $hash, $user_id, $now = null ) {
		$now   = null === $now ? time() : (int) $now;
		$entry = AB_MCP_Settings::get_token_by_hash( (string) $hash );
		if ( ! is_array( $entry ) ) {
			return false;
		}
		$running = self::released( $entry, $now );
		AB_MCP_Settings::update_token_fields(
			(string) $hash,
			array(
				self::FIELD_RELEASE    => null,
				self::FIELD_RELEASE_BY => null,
			)
		);
		if ( $running ) {
			self::remember( array_merge( array( 'action' => 'revoke', 'hash' => (string) $hash, 'label' => isset( $entry['label'] ) ? (string) $entry['label'] : '' ), self::who( (int) $user_id, $now ) ) );
		}
		return $running;
	}

	/**
	 * Ask a connection again: take its «no more» and its acknowledgement
	 * back (the settings page).
	 *
	 * @param array $conn connection().
	 * @return bool Whether the connection was found.
	 */
	public static function ask_again( array $conn ) {
		return self::save_state(
			$conn,
			array(
				self::FIELD_ACK   => null,
				self::FIELD_NEVER => null,
			)
		);
	}

	/**
	 * Who did it, when.
	 *
	 * @param int $user_id Who.
	 * @param int $now     When.
	 * @return array{user_id:int,user_login:string,at:int}
	 */
	private static function who( $user_id, $now ) {
		$user = $user_id > 0 ? get_user_by( 'id', $user_id ) : false;
		return array(
			'user_id'    => $user_id,
			'user_login' => ( is_object( $user ) && isset( $user->user_login ) ) ? (string) $user->user_login : '',
			'at'         => (int) $now,
		);
	}

	/**
	 * Add an entry to the history, dropping the oldest beyond HISTORY_MAX.
	 *
	 * @param array $entry Entry.
	 */
	private static function remember( array $entry ) {
		$own              = self::own();
		$own['history'][] = $entry;
		$own['history']   = array_slice( $own['history'], -self::HISTORY_MAX );
		update_option( self::OPTION, $own, false );
	}

	/**
	 * The history of releases and revocations, oldest first.
	 *
	 * @return array<int,array>
	 */
	public static function history() {
		return array_values( array_filter( self::own()['history'], 'is_array' ) );
	}

	/**
	 * The callers without a token the gate knows, by account id.
	 *
	 * @return array<int,array>
	 */
	public static function user_states() {
		$out = array();
		foreach ( self::own()['users'] as $id => $state ) {
			if ( is_array( $state ) && (int) $id > 0 ) {
				$out[ (int) $id ] = $state;
			}
		}
		return $out;
	}
}

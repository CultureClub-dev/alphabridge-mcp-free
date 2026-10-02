<?php
/**
 * Write access for AI assistants: the main switch of the site.
 *
 * Out of the box write access is off and AlphaBridge MCP only reads, and only
 * the ordinary things: content, media, terms, comments, settings and how the
 * site is built. With write access off («read», the slug of before), every
 * tool that AB_MCP_Tool_Registry::is_read_only() does not classify as reading
 * is refused, and so is every reading tool marked Mighty
 * (AB_MCP_Tool_Registry::is_mighty(), the readers of code, files, the
 * database or logs among them), whatever its switch says and whatever the
 * connection's access level allows. It holds for every connection alike:
 * Claude, ChatGPT, Cursor and any other client.
 *
 * Write access on («full») lets the switched-on tools run, the powerful ones
 * included. An administrator switches it on only by confirming a notice that
 * changes take effect at once and at the site owner's own risk, and switching
 * it on switches every tool, and through ab_mcp_reset_switches every item of
 * an add-on, on (switch_to_full()). Switching it off needs no confirmation.
 *
 * The interface calls the two states «write access off (read only)» and
 * «write access on (full power)» (label()); the slugs, the option and this
 * class's methods keep the names Read and Full they had in 4.4.0, so add-ons
 * and stored records stay valid.
 *
 * The state lives in the plugin's own option (AB_MCP_Settings::OPT_OPTIONS,
 * key 'site_mode'), so it is per site on a multisite network. Anything stored
 * there other than exactly 'full' reads as write access off: a mistake never
 * widens what assistants may do.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class AB_MCP_Site_Mode
 */
class AB_MCP_Site_Mode {

	const READ = 'read';
	const FULL = 'full';

	/**
	 * Key of the mode in AB_MCP_Settings::OPT_OPTIONS.
	 */
	const KEY = 'site_mode';

	/**
	 * Key of the record of the last confirmation of Full in
	 * AB_MCP_Settings::OPT_OPTIONS (see confirmation()).
	 */
	const KEY_CONFIRMATION = 'full_confirmation';

	/**
	 * Version of the notice an administrator confirms when switching write
	 * access on (notice_text() and checkbox_text()). The record of a
	 * confirmation names the version shown, so raise this whenever either
	 * English text changes; a form loaded under an older version is then
	 * refused instead of recorded as agreement to a text that was not on the
	 * screen. A test ties this number to the English wording.
	 *
	 * Version 1 was the notice of 4.4.0 («Full … can be destructive»). A
	 * confirmation recorded under it stays valid: nobody has to confirm again
	 * after the update, and the record keeps the wording that was confirmed.
	 *
	 * A translation can change without it (a language pack from
	 * translate.wordpress.org wins over the bundled one), so the record also
	 * holds the wording shown and its hash (notice_hash()).
	 */
	const NOTICE_VERSION = '2';

	/**
	 * How write access came on, in the record of the confirmation: confirmed
	 * with the box on the settings page, or set by code (a provisioning
	 * script, a test site), where nobody ticked anything.
	 */
	const SOURCE_FORM = 'settings_form';
	const SOURCE_CODE = 'code';

	/**
	 * Option with the history of the switch: every switch on or off,
	 * oldest first, at most HISTORY_MAX. The record of the last confirmation
	 * is replaced by the next one, and the log keeps 200 entries of every
	 * kind and can be cleared; this keeps the earlier switches, and «Clear
	 * log» leaves it alone. Its own option, not autoloaded: nothing reads it
	 * on an ordinary request.
	 */
	const OPT_HISTORY = 'ab_mcp_mode_history';
	const HISTORY_MAX = 20;

	/**
	 * The state of this site: self::FULL with write access on, self::READ
	 * with it off.
	 *
	 * @return string self::READ or self::FULL.
	 */
	public static function get() {
		return self::FULL === AB_MCP_Settings::get( self::KEY, self::READ ) ? self::FULL : self::READ;
	}

	/**
	 * Is write access on?
	 *
	 * @return bool
	 */
	public static function is_full() {
		return self::FULL === self::get();
	}

	/**
	 * May a tool run in the current state? With write access on, yes; with
	 * it off only when runs_in_read() says so. The tool's own switch and the
	 * token scope are checked separately.
	 *
	 * @param string $name Tool name.
	 * @param array  $def  Tool definition.
	 * @return bool
	 */
	public static function allows( $name, array $def ) {
		return self::is_full() || self::runs_in_read( $name, $def );
	}

	/**
	 * Does a tool run with write access off? Only a tool the registry
	 * classifies as reading (an unknown tool counts as writing) and that is
	 * not marked Mighty. A Mighty reader writes nothing, but what it reads —
	 * code, files, the database, logs, credentials — is what the site owner
	 * opens with write access, not what «read only» promises.
	 *
	 * @param string $name Tool name.
	 * @param array  $def  Tool definition.
	 * @return bool
	 */
	public static function runs_in_read( $name, array $def ) {
		return AB_MCP_Tool_Registry::is_read_only( (string) $name, $def ) && ! AB_MCP_Tool_Registry::is_mighty( $def );
	}

	/**
	 * The answer to a tool that is refused because write access is off. It
	 * says why — the tool writes, or it is one of the readers that run only
	 * with write access on — and the way, with a direct link to the switch
	 * (AB_MCP_Guidance::compose()): an administrator switches write access on
	 * at the top of Settings → AlphaBridge MCP, at the site owner's own risk.
	 * It does not say what a Mighty reader reads: wp_get_user_meta reads
	 * profile fields, not code, files, the database, logs or credentials.
	 *
	 * @param string $name Tool name.
	 * @param array  $def  Tool definition; without one the name decides, as
	 *                     in AB_MCP_Tool_Registry::is_read_only().
	 * @return WP_Error Code ab_mcp_read_mode.
	 */
	public static function refusal( $name, array $def = array() ) {
		$reason = AB_MCP_Tool_Registry::is_read_only( (string) $name, $def )
			/* translators: %s: tool name */
			? sprintf( __( 'Write access is off on this site, so the tool "%s" did not run: it is one of the reading tools that run only with write access on.', 'alphabridge-mcp' ), (string) $name )
			/* translators: %s: tool name */
			: sprintf( __( 'Write access is off on this site, so the tool "%s" did not run: it changes the site, and with write access off AI assistants only read.', 'alphabridge-mcp' ), (string) $name );
		return new WP_Error(
			'ab_mcp_read_mode',
			AB_MCP_Guidance::compose( $reason, self::way_text(), AB_MCP_Guidance::settings_url( 'ab-mode' ) )
		);
	}

	/**
	 * What the person does to switch write access on, in one sentence: who,
	 * where, and that it is at the site owner's own risk.
	 *
	 * @return string
	 */
	public static function way_text() {
		return __( 'To allow it, an administrator can switch on write access at the top of Settings → AlphaBridge MCP and confirm the notice there; that is at the site owner\'s own risk, and a current backup is advised.', 'alphabridge-mcp' );
	}

	/**
	 * The name of a state, for the settings page: «Write access off (read
	 * only)» or «Write access on (full power)».
	 *
	 * @param string $mode self::READ or self::FULL.
	 * @return string
	 */
	public static function label( $mode ) {
		return self::FULL === $mode ? _x( 'Write access on (full power)', 'site mode', 'alphabridge-mcp' ) : _x( 'Write access off (read only)', 'site mode', 'alphabridge-mcp' );
	}

	/**
	 * The notice shown before write access is switched on. Its wording is
	 * versioned by NOTICE_VERSION.
	 *
	 * @return string
	 */
	public static function notice_text() {
		return __( 'With write access, your AI assistants work directly on your live website. Changes take effect immediately: they can create, change and also delete content, files, settings and code, and not everything can be undone. You switch this on at your own risk. A current backup keeps you on the safe side.', 'alphabridge-mcp' );
	}

	/**
	 * The text of the box an administrator ticks to switch write access on.
	 * Its wording is versioned by NOTICE_VERSION.
	 *
	 * @return string
	 */
	public static function checkbox_text() {
		return __( 'Understood: changes take effect immediately, I switch on write access at my own risk and I have a current backup.', 'alphabridge-mcp' );
	}

	/**
	 * A hash of the notice and the box as the administrator sees them, in
	 * the current language: sha256 of notice_text(), a line break and
	 * checkbox_text(). The form carries it, and handle_site_mode() switches
	 * only while it still matches, so the record names exactly the wording on
	 * the screen, also when a language pack changed a translation.
	 *
	 * @return string 64 hex characters.
	 */
	public static function notice_hash() {
		return hash( 'sha256', self::notice_text() . "\n" . self::checkbox_text() );
	}

	/**
	 * The sentence of the consent screen about the main switch: whatever
	 * access level the connection gets, the switch on the site decides
	 * whether assistants may write. With write access off it says that the
	 * connection reads only until an administrator switches it on, so
	 * approving does not suggest the connection can write.
	 *
	 * @return string
	 */
	public static function consent_text() {
		if ( self::is_full() ) {
			return __( 'Whatever access level you choose, the switch for write access on this site decides whether AI assistants may write; it is on right now.', 'alphabridge-mcp' );
		}
		return __( 'Whatever access level you choose, the switch for write access on this site decides whether AI assistants may write; it is off right now, so the connection only reads until an administrator switches it on at the top of Settings → AlphaBridge MCP.', 'alphabridge-mcp' );
	}

	/**
	 * The sentence the server instructions carry while write access is off,
	 * so an assistant knows before its first call why writing, and the
	 * Mighty readers of code, files, the database, logs or credentials, fail,
	 * and that it should lead the person to the switch.
	 *
	 * Not translated: the instructions are written for the assistant, in
	 * the language of the rest of them.
	 *
	 * @return string
	 */
	public static function instructions_sentence() {
		return 'WRITE ACCESS IS OFF: AlphaBridge MCP only reads on this site, so every tool that creates, changes or deletes, and every tool marked Mighty that reads code, files, the database, logs or credentials, is refused until an administrator switches on write access at the top of Settings → AlphaBridge MCP (' . AB_MCP_Guidance::settings_url( 'ab-mode' ) . '); when the person asks for a change, tell them that kindly and where the switch is.';
	}

	/**
	 * The record of the last switch-on of write access, or null when there
	 * is none:
	 * user_id, user_login, time (Unix, UTC), notice_version, plugin_version,
	 * locale and source (SOURCE_FORM or SOURCE_CODE); confirmed on the
	 * settings page, also notice_hash, notice_text and checkbox_text, the
	 * wording the administrator confirmed.
	 *
	 * It stays when write access goes off again: it documents who accepted
	 * the notice last, and the next switch-on replaces it. The earlier ones
	 * are in history(). A record of notice version 1 (4.4.0) stays as valid
	 * as one of the version in force.
	 *
	 * @return array|null
	 */
	public static function confirmation() {
		$record = AB_MCP_Settings::get( self::KEY_CONFIRMATION, null );
		return is_array( $record ) && ! empty( $record['time'] ) ? $record : null;
	}

	/**
	 * The history of the switch, oldest first: one entry per switch, with
	 * mode ('full' or 'read'), user_id, user_login, time and source, and for
	 * a switch-on the rest of its record (see confirmation()).
	 *
	 * @return array<int,array>
	 */
	public static function history() {
		$history = get_option( self::OPT_HISTORY, array() );
		return is_array( $history ) ? array_values( array_filter( $history, 'is_array' ) ) : array();
	}

	/**
	 * «Write access on since <date> · confirmed by <account> · every
	 * connection keeps its access level» with write access on, from the record
	 * of the confirmation; '' with it off. The date is the site's date and
	 * time in digits, like every other date on the settings page. Switched on
	 * by code it says so, and that nobody confirmed it on this page; on
	 * without any record (written straight into the option) it says that no
	 * confirmation is recorded.
	 *
	 * @return string Unescaped text.
	 */
	public static function full_since_text() {
		if ( ! self::is_full() ) {
			return '';
		}
		$record = self::confirmation();
		if ( null === $record ) {
			return __( 'Write access is on; no confirmation on this page is recorded for it.', 'alphabridge-mcp' );
		}
		$date = wp_date( 'Y-m-d H:i', (int) $record['time'] );
		$date = is_string( $date ) ? $date : '—';
		if ( self::SOURCE_FORM !== ( $record['source'] ?? '' ) ) {
			/* translators: %s: date and time. */
			return sprintf( __( 'Write access on since %s · set by code, not confirmed on this page · every connection keeps its access level', 'alphabridge-mcp' ), $date );
		}
		$login = isset( $record['user_login'] ) && '' !== (string) $record['user_login'] ? (string) $record['user_login'] : '#' . (int) ( $record['user_id'] ?? 0 );
		/* translators: 1: date and time, 2: user name of the administrator who confirmed. */
		return sprintf( __( 'Write access on since %1$s · confirmed by %2$s · every connection keeps its access level', 'alphabridge-mcp' ), $date, $login );
	}

	/**
	 * Switch write access on and record who switched, when, which version of
	 * the notice was in force, under which plugin version and how: in the
	 * option (confirmation()), in the history (history()) and in the log.
	 *
	 * Switching on switches everything on: every tool (saved switches, the
	 * rule for switches saved before 4.4.0 and 'mighty_since' no longer
	 * apply, AB_MCP_Settings::switch_all_tools_on()) and, through the action
	 * ab_mcp_reset_switches, every item of an add-on (the Pro add-on's
	 * abilities of other plugins). An administrator can switch single ones off
	 * under Fine-tuning afterwards; that holds until write access is switched
	 * on the next time. An update alone resets nothing.
	 *
	 * The caller has checked the capability and the nonce; for SOURCE_FORM
	 * also the ticked box and that the form showed the notice in force
	 * (notice_hash()), and the record then holds that wording. It is public
	 * so that code an administrator runs on purpose (a provisioning script, a
	 * test site) can switch too; that is SOURCE_CODE, the default, and the
	 * settings page then says that nobody confirmed it there.
	 *
	 * @param int    $user_id The administrator who switched.
	 * @param string $source  SOURCE_FORM or SOURCE_CODE; anything else is code.
	 * @return array The record.
	 */
	public static function switch_to_full( $user_id, $source = self::SOURCE_CODE ) {
		$user_id = (int) $user_id;
		$source  = self::SOURCE_FORM === $source ? self::SOURCE_FORM : self::SOURCE_CODE;
		$login   = self::login_of( $user_id );
		$locale  = function_exists( 'get_user_locale' ) ? get_user_locale( $user_id ) : get_locale();
		$record  = array(
			'user_id'        => $user_id,
			'user_login'     => $login,
			'time'           => time(),
			'notice_version' => self::NOTICE_VERSION,
			'plugin_version' => defined( 'AB_MCP_VERSION' ) ? AB_MCP_VERSION : '',
			'locale'         => (string) $locale,
			'source'         => $source,
		);
		if ( self::SOURCE_FORM === $source ) {
			$record['notice_hash']   = self::notice_hash();
			$record['notice_text']   = self::notice_text();
			$record['checkbox_text'] = self::checkbox_text();
		}

		AB_MCP_Settings::set( self::KEY_CONFIRMATION, $record );
		AB_MCP_Settings::set( self::KEY, self::FULL );
		// The mode changed hands; the notice about the update has done its job.
		AB_MCP_Settings::set( AB_MCP_Settings::KEY_MODE_NOTICE, false );
		// Whoever switches write access on wants it to work: everything on.
		AB_MCP_Settings::switch_all_tools_on();
		/**
		 * Fires when write access is switched on, right after the core
		 * switched every tool on. An add-on switches its own items on here,
		 * so that write access means everything (the Pro add-on empties its
		 * list of switched-off abilities of other plugins).
		 *
		 * @since 4.5.0
		 *
		 * @param int    $user_id The administrator who switched.
		 * @param string $source  self::SOURCE_FORM or self::SOURCE_CODE.
		 */
		do_action( 'ab_mcp_reset_switches', $user_id, $source );

		$who = '' !== $login ? $login : '?';
		AB_MCP_Audit_Log::record(
			'site_mode',
			array(),
			'ok',
			self::SOURCE_FORM === $source
				? sprintf(
					'Write access (Full) switched on by %1$s (user %2$d) on the settings page; notice version %3$s confirmed, wording sha256 %4$s; plugin %5$s; locale %6$s; every switch on.',
					$who,
					$user_id,
					$record['notice_version'],
					$record['notice_hash'],
					$record['plugin_version'],
					$record['locale']
				)
				: sprintf(
					'Write access (Full) switched on by %1$s (user %2$d) by code, without the box on the settings page; notice version %3$s not confirmed; plugin %4$s; locale %5$s; every switch on.',
					$who,
					$user_id,
					$record['notice_version'],
					$record['plugin_version'],
					$record['locale']
				)
		);

		self::changed( self::FULL, $user_id );
		// After the action, so that what an add-on adds to the record (Pro
		// its version) is in the history too.
		$now = self::confirmation();
		self::remember( array( 'mode' => self::FULL ) + ( null !== $now ? $now : $record ) );
		return $record;
	}

	/**
	 * Switch write access off. Needs no confirmation: it only takes away.
	 * The switches of the fine-tuning stay as they are, the record of the last
	 * confirmation stays; the history notes the switch.
	 *
	 * @param int    $user_id The administrator who switched.
	 * @param string $source  SOURCE_FORM or SOURCE_CODE; anything else is code.
	 */
	public static function switch_to_read( $user_id, $source = self::SOURCE_CODE ) {
		$user_id = (int) $user_id;
		$login   = self::login_of( $user_id );

		AB_MCP_Settings::set( self::KEY, self::READ );
		AB_MCP_Settings::set( AB_MCP_Settings::KEY_MODE_NOTICE, false );

		AB_MCP_Audit_Log::record(
			'site_mode',
			array(),
			'ok',
			sprintf( 'Write access switched off (Read) by %1$s (user %2$d).', '' !== $login ? $login : '?', $user_id )
		);

		self::changed( self::READ, $user_id );
		self::remember(
			array(
				'mode'       => self::READ,
				'user_id'    => $user_id,
				'user_login' => $login,
				'time'       => time(),
				'source'     => self::SOURCE_FORM === $source ? self::SOURCE_FORM : self::SOURCE_CODE,
			)
		);
	}

	/**
	 * Add a switch to the history, dropping the oldest beyond HISTORY_MAX.
	 *
	 * @param array $entry The switch.
	 */
	private static function remember( array $entry ) {
		$history   = self::history();
		$history[] = $entry;
		update_option( self::OPT_HISTORY, array_slice( $history, -self::HISTORY_MAX ), false );
	}

	/**
	 * The login of a user, '' when there is none.
	 *
	 * @param int $user_id User id.
	 * @return string
	 */
	private static function login_of( $user_id ) {
		$user = $user_id > 0 ? get_user_by( 'id', $user_id ) : false;
		return $user && isset( $user->user_login ) ? (string) $user->user_login : '';
	}

	/**
	 * Tell add-ons that write access was switched on or off.
	 *
	 * @param string $mode    The new mode.
	 * @param int    $user_id Who switched.
	 */
	private static function changed( $mode, $user_id ) {
		/**
		 * Fires after write access was switched on ('full') or off ('read').
		 *
		 * @since 4.4.0
		 *
		 * @param string $mode    'read' or 'full'.
		 * @param int    $user_id The administrator who switched.
		 */
		do_action( 'ab_mcp_site_mode_changed', $mode, $user_id );
	}
}

<?php
/**
 * The site mode: Read or Full.
 *
 * Out of the box AlphaBridge MCP only reads. In Read, every tool that
 * AB_MCP_Tool_Registry::is_read_only() does not classify as reading is
 * refused, whatever its switch says and whatever the connection's access
 * level allows. Full lets the switched-on tools write, the powerful ones
 * included, and an administrator turns it on only by confirming a notice that
 * it can be destructive and is at the site owner's own risk.
 *
 * The mode lives in the plugin's own option (AB_MCP_Settings::OPT_OPTIONS,
 * key 'site_mode'), so it is per site on a multisite network. Anything stored
 * there other than exactly 'full' reads as Read: a mistake never widens what
 * assistants may do.
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
	 * Version of the notice an administrator confirms when switching to
	 * Full (notice_text() and checkbox_text()). The record of a confirmation
	 * names the version shown, so raise this whenever either text changes in
	 * meaning; a form loaded under an older version is then refused instead of
	 * recorded as agreement to a text that was not on the screen.
	 */
	const NOTICE_VERSION = '1';

	/**
	 * The mode of this site.
	 *
	 * @return string self::READ or self::FULL.
	 */
	public static function get() {
		return self::FULL === AB_MCP_Settings::get( self::KEY, self::READ ) ? self::FULL : self::READ;
	}

	/**
	 * Is this site in Full?
	 *
	 * @return bool
	 */
	public static function is_full() {
		return self::FULL === self::get();
	}

	/**
	 * May a tool run in the current mode? In Read only the tools the
	 * registry classifies as reading; an unknown tool counts as writing there.
	 * The tool's own switch and the token scope are checked separately.
	 *
	 * @param string $name Tool name.
	 * @param array  $def  Tool definition.
	 * @return bool
	 */
	public static function allows( $name, array $def ) {
		return self::is_full() || AB_MCP_Tool_Registry::is_read_only( (string) $name, $def );
	}

	/**
	 * The answer to a writing tool called in Read. It names the way: who can
	 * switch, where, and what switching means.
	 *
	 * @param string $name Tool name.
	 * @return WP_Error
	 */
	public static function refusal( $name ) {
		return new WP_Error(
			'ab_mcp_read_mode',
			sprintf(
				/* translators: %s: tool name */
				__( 'AlphaBridge MCP is in read mode on this site; the tool "%s" writes. An administrator can switch to Full at the top of Settings → AlphaBridge MCP — switching it on can be destructive and is at the site owner\'s own risk.', 'alphabridge-mcp' ),
				(string) $name
			)
		);
	}

	/**
	 * The name of a mode in one word, for the settings page.
	 *
	 * @param string $mode self::READ or self::FULL.
	 * @return string
	 */
	public static function label( $mode ) {
		return self::FULL === $mode ? _x( 'Full', 'site mode', 'alphabridge-mcp' ) : _x( 'Read', 'site mode', 'alphabridge-mcp' );
	}

	/**
	 * The notice shown next to the switch to Full. Its wording is versioned
	 * by NOTICE_VERSION.
	 *
	 * @return string
	 */
	public static function notice_text() {
		return __( 'Full lets AI assistants create, change and delete content, files, settings and code on this live site. Switching it on can be destructive and is at your own risk. Make sure you have a current backup.', 'alphabridge-mcp' );
	}

	/**
	 * The text of the box an administrator ticks to switch to Full. Its
	 * wording is versioned by NOTICE_VERSION.
	 *
	 * @return string
	 */
	public static function checkbox_text() {
		return __( 'I understand that switching to Full can be destructive and is at my own risk, and I have a current backup.', 'alphabridge-mcp' );
	}

	/**
	 * The sentence the consent screen shows while the site is in Read: the
	 * access level is granted as chosen, but approving should not suggest the
	 * connection can write before an administrator switches to Full.
	 *
	 * @return string
	 */
	public static function consent_text() {
		return __( 'This site is in read mode: whatever access level you choose, the connection can only read until an administrator switches AlphaBridge MCP to Full.', 'alphabridge-mcp' );
	}

	/**
	 * The sentence the server instructions carry in Read, so an assistant
	 * knows before its first call why writing fails and whom to ask.
	 *
	 * Not translated: the instructions are written for the assistant, in
	 * the language of the rest of them.
	 *
	 * @return string
	 */
	public static function instructions_sentence() {
		return 'READ MODE: AlphaBridge MCP only reads on this site, so every tool that creates, changes or deletes is refused until an administrator switches it to Full at the top of Settings → AlphaBridge MCP.';
	}

	/**
	 * The record of the last confirmation of Full, or null when there is
	 * none: user_id, user_login, time (Unix, UTC), notice_version,
	 * plugin_version, locale.
	 *
	 * It stays when the site goes back to Read: it documents who accepted
	 * the notice last, and the next switch to Full replaces it.
	 *
	 * @return array|null
	 */
	public static function confirmation() {
		$record = AB_MCP_Settings::get( self::KEY_CONFIRMATION, null );
		return is_array( $record ) && ! empty( $record['time'] ) ? $record : null;
	}

	/**
	 * «Full since <date>, confirmed by <account>» for a site in Full, from
	 * the record of the confirmation; '' in Read. The date is the site's
	 * date and time in digits, like every other date on the settings page.
	 * Full without a record (set by code, not on the settings page) says
	 * that no confirmation is recorded.
	 *
	 * @return string Unescaped text.
	 */
	public static function full_since_text() {
		if ( ! self::is_full() ) {
			return '';
		}
		$record = self::confirmation();
		if ( null === $record ) {
			return __( 'Full is on; no confirmation on the settings page is recorded for it.', 'alphabridge-mcp' );
		}
		$date  = wp_date( 'Y-m-d H:i', (int) $record['time'] );
		$login = isset( $record['user_login'] ) && '' !== (string) $record['user_login'] ? (string) $record['user_login'] : '#' . (int) ( $record['user_id'] ?? 0 );
		/* translators: 1: date and time, 2: user name of the administrator who confirmed. */
		return sprintf( __( 'Full since %1$s, confirmed by %2$s', 'alphabridge-mcp' ), is_string( $date ) ? $date : '—', $login );
	}

	/**
	 * Switch this site to Full and record who confirmed the notice, when,
	 * which version of it, and under which plugin version: in the option
	 * (confirmation()) and in the log.
	 *
	 * The caller has checked the capability, the nonce and the ticked box;
	 * this only writes. It is public so that code an administrator runs on
	 * purpose (a provisioning script, a test site) can do the same.
	 *
	 * @param int $user_id The administrator who confirmed.
	 * @return array The record.
	 */
	public static function switch_to_full( $user_id ) {
		$user_id = (int) $user_id;
		$login   = self::login_of( $user_id );
		$locale  = function_exists( 'get_user_locale' ) ? get_user_locale( $user_id ) : get_locale();
		$record  = array(
			'user_id'        => $user_id,
			'user_login'     => $login,
			'time'           => time(),
			'notice_version' => self::NOTICE_VERSION,
			'plugin_version' => defined( 'AB_MCP_VERSION' ) ? AB_MCP_VERSION : '',
			'locale'         => (string) $locale,
		);

		AB_MCP_Settings::set( self::KEY_CONFIRMATION, $record );
		AB_MCP_Settings::set( self::KEY, self::FULL );
		// The mode changed hands; the notice about the update has done its job.
		AB_MCP_Settings::set( AB_MCP_Settings::KEY_MODE_NOTICE, false );

		AB_MCP_Audit_Log::record(
			'site_mode',
			array(),
			'ok',
			sprintf(
				'Full switched on by %1$s (user %2$d); notice version %3$s confirmed; plugin %4$s; locale %5$s.',
				'' !== $login ? $login : '?',
				$user_id,
				$record['notice_version'],
				$record['plugin_version'],
				$record['locale']
			)
		);

		self::changed( self::FULL, $user_id );
		return $record;
	}

	/**
	 * Switch this site back to Read. Needs no confirmation: it only takes
	 * away. The record of the last confirmation of Full stays.
	 *
	 * @param int $user_id The administrator who switched.
	 */
	public static function switch_to_read( $user_id ) {
		$user_id = (int) $user_id;
		$login   = self::login_of( $user_id );

		AB_MCP_Settings::set( self::KEY, self::READ );
		AB_MCP_Settings::set( AB_MCP_Settings::KEY_MODE_NOTICE, false );

		AB_MCP_Audit_Log::record(
			'site_mode',
			array(),
			'ok',
			sprintf( 'Read switched on by %1$s (user %2$d).', '' !== $login ? $login : '?', $user_id )
		);

		self::changed( self::READ, $user_id );
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
	 * Tell add-ons that the mode changed.
	 *
	 * @param string $mode    The new mode.
	 * @param int    $user_id Who switched.
	 */
	private static function changed( $mode, $user_id ) {
		/**
		 * Fires after an administrator switched the site mode.
		 *
		 * @since 4.4.0
		 *
		 * @param string $mode    'read' or 'full'.
		 * @param int    $user_id The administrator who switched.
		 */
		do_action( 'ab_mcp_site_mode_changed', $mode, $user_id );
	}
}

<?php
/**
 * Site settings tool (fixed, safe field list).
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-tools-base.php';

/**
 * Class AB_MCP_Tools_Options_Settings
 */
class AB_MCP_Tools_Options_Settings extends AB_MCP_Tools_Base {

	/**
	 * Register tools.
	 *
	 * @param AB_MCP_Tool_Registry $r Registry.
	 */
	public static function register( AB_MCP_Tool_Registry $r ) {

		$r->register(
			'wp_get_site_settings',
			array(
				'description' => 'Get common site settings: title, tagline, home URL (url), WordPress URL (wp_url), admin email, timezone, language, posts per page, date format and permalink structure. timezone is the named timezone, empty when the site uses a fixed UTC offset; permalink is empty for plain permalinks. Needs manage_options. Read-only; takes no arguments. Versions, theme and plugins are reported by wp_site_info.',
				'capability'  => 'manage_options',
				'callback'    => array( __CLASS__, 'get_site_settings' ),
			)
		);

		// Free since 4.9.0, before in Pro only; Pro registers its own over it.
		$r->register(
			'wp_update_site_settings',
			array(
				'description' => 'Update the common site settings title, tagline, admin email, timezone and posts per page; fields left out keep their values (wp_get_site_settings reads them). An admin_email that is not a valid address is ignored. Each field is read back: changed lists the fields that took (a value already set counts), adjusted what a filter stored in place of the value sent, failed what did not take. When every field written failed, the call is an error naming that only. undo is the token of an undo point for wp_undo, or null when none could be kept; the writes happen either way. Needs manage_options.',
				'open_world'  => true, // title and tagline show on every page.
				'capability'  => 'manage_options',
				'dangerous'   => true,
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'title'          => array( 'type' => 'string', 'description' => 'Site title (option blogname).' ),
						'tagline'        => array( 'type' => 'string', 'description' => 'Site tagline (option blogdescription); an empty string clears it.' ),
						'admin_email'    => array( 'type' => 'string', 'description' => 'Administration email address (option admin_email). Ignored unless it is a valid address.' ),
						'timezone'       => array( 'type' => 'string', 'description' => 'PHP timezone identifier stored in timezone_string, e.g. Europe/Zurich. A value WordPress rejects does not take: listed under failed when other fields stored, otherwise the call is an error.' ),
						'posts_per_page' => array( 'type' => 'integer', 'description' => 'Posts per page on blog pages (Settings › Reading).' ),
					),
				),
				'callback'    => array( __CLASS__, 'update_site_settings' ),
			)
		);
	}

	/**
	 * Get site settings.
	 *
	 * @return array
	 */
	public static function get_site_settings() {
		return array(
			'title'          => get_option( 'blogname' ),
			'tagline'        => get_option( 'blogdescription' ),
			'url'            => home_url(),
			'wp_url'         => site_url(),
			'admin_email'    => get_option( 'admin_email' ),
			'timezone'       => get_option( 'timezone_string' ),
			'language'       => get_option( 'WPLANG' ) ? get_option( 'WPLANG' ) : get_locale(),
			'posts_per_page' => (int) get_option( 'posts_per_page' ),
			'date_format'    => get_option( 'date_format' ),
			'permalink'      => get_option( 'permalink_structure' ),
		);
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
	 * Update site settings.
	 *
	 * @param array $a Args.
	 * @return array
	 */
	public static function update_site_settings( $a ) {
		// Work out what would change first, so the undo snapshot covers exactly
		// the options that are about to be written — no more, and never one that
		// the caller did not touch. The guards are unchanged: admin_email is
		// still only accepted when it is a valid address.
		$writes = array();
		$field  = array();   // option name => the field name the caller used
		if ( array_key_exists( 'title', $a ) ) {
			$writes['blogname'] = self::s( $a, 'title' );
			$field['blogname']  = 'title';
		}
		if ( array_key_exists( 'tagline', $a ) ) {
			$writes['blogdescription'] = self::s( $a, 'tagline' );
			$field['blogdescription']  = 'tagline';
		}
		if ( array_key_exists( 'admin_email', $a ) && is_email( self::s( $a, 'admin_email' ) ) ) {
			$writes['admin_email'] = self::s( $a, 'admin_email' );
			$field['admin_email']  = 'admin_email';
		}
		if ( array_key_exists( 'timezone', $a ) ) {
			$writes['timezone_string'] = self::s( $a, 'timezone' );
			$field['timezone_string']  = 'timezone';
		}
		if ( array_key_exists( 'posts_per_page', $a ) ) {
			$writes['posts_per_page'] = self::i( $a, 'posts_per_page' );
			$field['posts_per_page']  = 'posts_per_page';
		}
		$changed = array_values( $field );

		$undo          = null;
		$before_values = array();
		if ( ! empty( $writes ) ) {
			$before  = array();
			$missing = new stdClass();
			foreach ( array_keys( $writes ) as $opt ) {
				$before[ $opt ] = get_option( $opt );
				// Kept apart from the snapshot: the snapshot is what an undo
				// writes back, this is what the read-back compares against,
				// and it has to tell an absent row from a stored false.
				$before_values[ $opt ] = get_option( $opt, $missing );
			}
			$undo = AB_MCP_Undo::record(
				array(
					'tool'    => 'wp_update_site_settings',
					'cap'     => 'manage_options',
					'kind'    => 'site_settings',
					'summary' => sprintf( 'Site settings: %s', implode( ', ', $changed ) ),
					'items'   => count( $writes ),
					'data'    => $before,
				)
			);
		}

		// Every one of these is read back, for the reason update_option_tool
		// spells out: update_option() cannot tell "failed" from "already that
		// value". Settings are written in a batch, so the outcome is per
		// field — a rejected timezone must not disappear behind four fields
		// that did store.
		$stored   = array();
		$adjusted = array();
		$failed   = array();
		foreach ( $writes as $opt => $value ) {
			update_option( $opt, $value );
			if ( self::option_value_matches( $opt, $value ) ) {
				$stored[] = $field[ $opt ];
				continue;
			}
			$missing = new stdClass();
			$now     = get_option( $opt, $missing );
			$was     = array_key_exists( $opt, $before_values ) ? $before_values[ $opt ] : $missing;
			$unmoved = ( $missing === $now && $missing === $was )
				|| ( $missing !== $now && $missing !== $was && self::option_value_matches( $opt, $was ) );
			if ( $unmoved ) {
				$failed[] = $field[ $opt ];
				continue;
			}
			// Stored, but not as asked: sanitize_option and the filters may
			// rewrite. That is a store with a caveat, not a failure.
			$stored[]                    = $field[ $opt ];
			$adjusted[ $field[ $opt ] ] = ( $missing === $now ) ? null : $now;
		}

		// "changed" now names what the site REALLY holds, not what was
		// attempted — the old list said the latter and called it the former.
		$out = array(
			'updated' => array() !== $stored,
			'changed' => $stored,
			'undo'    => $undo,
		);
		if ( $adjusted ) {
			$out['adjusted'] = $adjusted;
		}
		if ( $failed ) {
			$out['failed'] = $failed;
		}
		if ( array() === $stored && $writes ) {
			return new WP_Error(
				'ab_mcp_write_failed',
				__( 'None of the settings changed — a filter or the database rejected the writes. Change them in wp-admin under Settings › General and Settings › Reading, or check which plugin filters these options.', 'alphabridge-mcp' ),
				array( 'undo' => $undo, 'failed' => $failed )
			);
		}
		return $out;
	}
}

<?php
/**
 * The record of a change of the site address that is not finished
 * (since 4.17.0).
 *
 * The tool that changes the address of a site, wp_change_site_address of the
 * Pro add-on, writes this record before its first change (write-ahead) and
 * removes it once the change went through or was taken back completely. A
 * record that stays says that the site may be left between two addresses:
 * the request ended halfway, the way back failed in part, or only the
 * removal of the record failed. This plugin never writes it. It reads it, so
 * that wp_site_info (block alphabridge) and the card «Connection check» on
 * AlphaBridge → AI Connection name the open change, and an administrator
 * closes it there (close()), which removes the record and nothing else.
 *
 * THE FORMAT, for the writer. One option of its own, OPTION, not a key of
 * ab_mcp_options: AB_MCP_Settings::set() writes that whole array anew with
 * update_option() on every change of a setting, and the emergency way back
 * of the writer has to update this record with plain SQL, without the hooks
 * of update_option(). The row's option_value is one JSON object
 * (wp_json_encode(); a string, so update_option() stores it as it is),
 * written without autoload. Its keys:
 *
 *  - v      int, required: the format, FORMAT (1). Another value is a format
 *           this version cannot read.
 *  - state  string, required, one of STATES:
 *           started             written before the first change; the change
 *                               runs, or its request ended before it was
 *                               completed or taken back;
 *           rolled_back_partial the change failed and the way back did not
 *                               complete: rest names what is left;
 *           rescued             the request ended during the change, and the
 *                               emergency way back at its end wrote the old
 *                               values back; rest names what it could not
 *                               confirm (a persistent object cache, say);
 *           rescue_incomplete   as rescued, but at least one step of the
 *                               emergency way back failed: rest names it;
 *           done                the change went through, and the removal of
 *                               the record that follows did not.
 *  - old    object, required: home and siteurl before the change, each the
 *           row as stored (http:// or https://, at most MAX_URL bytes).
 *  - new    object, required: home and siteurl the change writes, the same
 *           form. For an undo of an address change, old is the address it
 *           leaves and new the one it goes back to.
 *  - time   int, optional: when the change started (Unix time).
 *  - user   int, optional: the id of the account that started it.
 *  - rest   list of strings, optional: what is still open, each a sentence
 *           a person reads (at most MAX_REST, each at most MAX_TEXT bytes).
 *  - next   string, optional: the next step, one or more sentences
 *           (at most MAX_TEXT bytes).
 *  - undo   string, optional: the id of the undo point of the change
 *           (undo_ and 32 hex digits).
 *
 * rest and next are shown as written, escaped: the writer writes them in the
 * language of its own texts. Further keys are the writer's own and ignored
 * here. A key named above that is present in another form makes the whole
 * record unreadable, never a record with a gap: a rest left out would hide
 * what is still open. An unreadable record is still shown, as «not
 * finished, record unreadable», with the button to close it.
 *
 * READ AS THE ROW. The record is read with $wpdb->get_var() on option_value,
 * not with get_option(): pre_option_* and option_* can replace any value, and
 * the display must show what is stored. A lookup that fails (the database
 * answers with an error) is told apart from «no record»: an open change
 * hidden by a broken query would read as none.
 *
 * CLOSING. close() removes the row, but only for the record the page showed
 * (its fingerprint, a page loaded before the writer changed it closes
 * nothing) and not while a change of the site runs under
 * AB_MCP_Change_Lock (its row younger than its TIMEOUT), which an address
 * change holds. A writer that still runs and finds no row to update writes
 * it anew.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class AB_MCP_Site_Address_Journal
 */
final class AB_MCP_Site_Address_Journal {

	/**
	 * The option that holds the record.
	 */
	const OPTION = 'ab_mcp_site_address_journal';

	/**
	 * The format this version reads (key v).
	 */
	const FORMAT = 1;

	/**
	 * The states of a record (key state); see the head of this class.
	 */
	const STATES = array( 'started', 'rolled_back_partial', 'rescued', 'rescue_incomplete', 'done' );

	/**
	 * At most this many entries in rest.
	 */
	const MAX_REST = 50;

	/**
	 * At most this many bytes in an entry of rest and in next.
	 */
	const MAX_TEXT = 2000;

	/**
	 * At most this many bytes in an address.
	 */
	const MAX_URL = 2048;

	/**
	 * At most this many bytes in the whole row: more is no record of this
	 * format.
	 */
	const MAX_ROW = 262144;

	/**
	 * The record as it stands.
	 *
	 * @return array|null Null when there is none. Otherwise 'readable' and
	 *                    'lookup_failed' (bools), and for a row that was
	 *                    read its 'fingerprint'; a readable record adds the
	 *                    keys of parse().
	 */
	public static function read() {
		$raw = self::row( self::OPTION );
		if ( false === $raw ) {
			return array(
				'readable'      => false,
				'lookup_failed' => true,
			);
		}
		if ( null === $raw ) {
			return null;
		}
		$entry = self::parse( $raw );
		$out   = array(
			'readable'      => null !== $entry,
			'lookup_failed' => false,
			'fingerprint'   => self::fingerprint( $raw ),
		);
		return null === $entry ? $out : $out + $entry;
	}

	/**
	 * A record in the format of this class, read from the row's value:
	 * array( state, old, new, time, user, rest, next, undo ) with time,
	 * user, next and undo null where the record has none, or null where it
	 * is no record of this format. Public, so the writer can check what it
	 * writes against what is shown.
	 *
	 * @param mixed $raw The value of the row.
	 * @return array|null
	 */
	public static function parse( $raw ) {
		if ( ! is_string( $raw ) || '' === $raw || strlen( $raw ) > self::MAX_ROW ) {
			return null;
		}
		$data = json_decode( $raw, true, 8 );
		if ( ! is_array( $data ) || ! array_key_exists( 'v', $data ) || self::FORMAT !== $data['v'] ) {
			return null;
		}
		if ( ! isset( $data['state'] ) || ! in_array( $data['state'], self::STATES, true ) ) {
			return null;
		}
		$old = self::addresses( isset( $data['old'] ) ? $data['old'] : null );
		$new = self::addresses( isset( $data['new'] ) ? $data['new'] : null );
		if ( null === $old || null === $new ) {
			return null;
		}
		$entry = array(
			'state' => $data['state'],
			'old'   => $old,
			'new'   => $new,
			'time'  => null,
			'user'  => null,
			'rest'  => array(),
			'next'  => null,
			'undo'  => null,
		);
		foreach ( array( 'time', 'user' ) as $key ) {
			if ( ! array_key_exists( $key, $data ) ) {
				continue;
			}
			if ( ! is_int( $data[ $key ] ) || $data[ $key ] < 1 ) {
				return null;
			}
			$entry[ $key ] = $data[ $key ];
		}
		if ( array_key_exists( 'rest', $data ) ) {
			if ( ! is_array( $data['rest'] ) || array_values( $data['rest'] ) !== $data['rest'] || count( $data['rest'] ) > self::MAX_REST ) {
				return null;
			}
			foreach ( $data['rest'] as $item ) {
				$item = self::text( $item );
				if ( null === $item ) {
					return null;
				}
				$entry['rest'][] = $item;
			}
		}
		if ( array_key_exists( 'next', $data ) ) {
			$entry['next'] = self::text( $data['next'] );
			if ( null === $entry['next'] ) {
				return null;
			}
		}
		if ( array_key_exists( 'undo', $data ) ) {
			if ( ! is_string( $data['undo'] ) || ! preg_match( '/^undo_[0-9a-f]{32}\z/', $data['undo'] ) ) {
				return null;
			}
			$entry['undo'] = $data['undo'];
		}
		return $entry;
	}

	/**
	 * The fingerprint of a row's value, which the form to close a record
	 * carries: what the page showed.
	 *
	 * @param string $raw The value of the row.
	 * @return string
	 */
	public static function fingerprint( $raw ) {
		return hash( 'sha256', (string) $raw );
	}

	/**
	 * Close the record the page showed: remove its row.
	 *
	 * @param mixed $fingerprint fingerprint() of the record the page showed.
	 * @return string 'closed'; 'gone' when there is no record any more;
	 *                'changed' when the record is not the one the page
	 *                showed; 'busy' while a change of the site runs (or the
	 *                lock cannot be read); 'kept' when the row could not be
	 *                read or is still there after the removal.
	 */
	public static function close( $fingerprint ) {
		$raw = self::row( self::OPTION );
		if ( false === $raw ) {
			return 'kept';
		}
		if ( null === $raw ) {
			return 'gone';
		}
		if ( ! is_string( $fingerprint ) || ! hash_equals( self::fingerprint( $raw ), $fingerprint ) ) {
			return 'changed';
		}
		if ( self::change_running() ) {
			return 'busy';
		}
		delete_option( self::OPTION );
		return null === self::row( self::OPTION ) ? 'closed' : 'kept';
	}

	/**
	 * What wp_site_info says of the record in its block alphabridge, as the
	 * key open_address_change: null without a record, so the key is left
	 * out.
	 *
	 * @return array|null
	 */
	public static function for_site_info() {
		$entry = self::read();
		if ( null === $entry ) {
			return null;
		}
		if ( $entry['lookup_failed'] ) {
			return array(
				'readable'      => false,
				'lookup_failed' => true,
				'note'          => self::lookup_failed_text(),
			);
		}
		if ( ! $entry['readable'] ) {
			return array(
				'readable' => false,
				'note'     => self::unreadable_text() . ' ' . self::check_text(),
			);
		}
		$out = array(
			'readable'   => true,
			'state'      => $entry['state'],
			'state_text' => self::state_text( $entry['state'] ),
			'old'        => $entry['old'],
			'new'        => $entry['new'],
			'started'    => null === $entry['time'] ? null : gmdate( 'Y-m-d\TH:i:s\Z', $entry['time'] ),
			'user'       => $entry['user'],
			'rest'       => $entry['rest'],
			'next'       => $entry['next'],
		);
		if ( null !== $entry['undo'] ) {
			$out['undo'] = $entry['undo'];
		}
		$out['note'] = self::title_text() . ' ' . self::check_text();
		return $out;
	}

	/**
	 * What a state means, as a sentence.
	 *
	 * @param string $state One of STATES.
	 * @return string
	 */
	public static function state_text( $state ) {
		switch ( $state ) {
			case 'started':
				return __( 'Started and not finished: the change may still be running, or its request ended before the change was completed or taken back.', 'alphabridge-mcp' );
			case 'rolled_back_partial':
				return __( 'Taken back in part: the change failed, and the way back did not complete.', 'alphabridge-mcp' );
			case 'rescued':
				return __( 'Taken back after an abort: the request ended during the change, and the old address was written back as it ended.', 'alphabridge-mcp' );
			case 'rescue_incomplete':
				return __( 'Taken back after an abort, but not completely: the request ended during the change, and a step of the way back failed.', 'alphabridge-mcp' );
			case 'done':
				return __( 'Finished: the change went through; only its record was not removed.', 'alphabridge-mcp' );
		}
		return '';
	}

	/**
	 * The first sentence of a readable record.
	 *
	 * @return string
	 */
	public static function title_text() {
		return __( 'An address change of this site is not finished.', 'alphabridge-mcp' );
	}

	/**
	 * The first sentence of a record that cannot be read.
	 *
	 * @return string
	 */
	public static function unreadable_text() {
		return __( 'An address change of this site is not finished, and its record cannot be read, so what is still open is not known.', 'alphabridge-mcp' );
	}

	/**
	 * What a failed lookup says.
	 *
	 * @return string
	 */
	public static function lookup_failed_text() {
		return __( 'Whether an address change of this site is not finished could not be read: the database answered with an error. Try again; if it stays, the database error log names the cause.', 'alphabridge-mcp' );
	}

	/**
	 * What to do with an open record.
	 *
	 * @return string
	 */
	public static function check_text() {
		return __( 'Check under Settings › General which address the site has now. An administrator closes the record under “Connection check” on AlphaBridge → AI Connection once the address is right and nothing is open any more; closing removes only the record and changes nothing on the site.', 'alphabridge-mcp' );
	}

	/**
	 * The home and siteurl of old or new, or null where they are not both
	 * an address.
	 *
	 * @param mixed $pair Value of old or new.
	 * @return array|null
	 */
	private static function addresses( $pair ) {
		if ( ! is_array( $pair ) ) {
			return null;
		}
		$out = array();
		foreach ( array( 'home', 'siteurl' ) as $key ) {
			if ( ! isset( $pair[ $key ] ) || ! is_string( $pair[ $key ] ) ) {
				return null;
			}
			$url = $pair[ $key ];
			if ( strlen( $url ) > self::MAX_URL || ! preg_match( '~^https?://[^\x00-\x20\x7f]+\z~i', $url ) ) {
				return null;
			}
			$out[ $key ] = $url;
		}
		return $out;
	}

	/**
	 * A text of rest or next: a string that is not empty once trimmed, at
	 * most MAX_TEXT bytes, with control characters read as spaces; null
	 * otherwise.
	 *
	 * @param mixed $text Value.
	 * @return string|null
	 */
	private static function text( $text ) {
		if ( ! is_string( $text ) || strlen( $text ) > self::MAX_TEXT ) {
			return null;
		}
		$text = trim( (string) preg_replace( '/[\x00-\x1f\x7f]+/', ' ', $text ) );
		return '' === $text ? null : $text;
	}

	/**
	 * Is a change of the site running under AB_MCP_Change_Lock? Its row
	 * holds the time it was taken or last kept fresh, and it counts until
	 * TIMEOUT has passed, as WordPress's own lock counts (WP_Upgrader::
	 * create_lock()); a row that holds no time is no lock WordPress wrote.
	 * Read on this site's table: the address change, the one that writes the
	 * record, runs on a single site only. A lock that cannot be read counts
	 * as running.
	 *
	 * @return bool
	 */
	private static function change_running() {
		if ( ! class_exists( 'AB_MCP_Change_Lock' ) ) {
			return false;
		}
		$since = self::row( AB_MCP_Change_Lock::OPTION );
		if ( false === $since ) {
			return true;
		}
		return null !== $since && (int) $since > time() - AB_MCP_Change_Lock::TIMEOUT;
	}

	/**
	 * The value of an option's row, read from the table: the string, null
	 * when there is no row, false when the database answered with an error.
	 * Null as well where WordPress has set up no database (a script that
	 * loads this class on its own): there is nothing to read.
	 *
	 * @param string $name Option name.
	 * @return string|null|false
	 */
	private static function row( $name ) {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) || ! method_exists( $wpdb, 'prepare' ) || empty( $wpdb->options ) ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the row as stored, past the filters and caches of get_option().
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $name ) );
		if ( null === $value && ! empty( $wpdb->last_error ) ) {
			return false;
		}
		return null === $value ? null : (string) $value;
	}
}

<?php
/**
 * The undo part of a tool's answer, and the read-back after a write, in one
 * form for every tool that changes something (since 4.13.0).
 *
 * Undo: every tool with a way back answers with "undo", the token for
 * wp_undo or null, and where it is null with "undo_note", the reason. The
 * reason is the one AB_MCP_Undo::record_result() gives — switched off,
 * nothing to keep, a value that cannot be stored, over a size limit, the
 * snapshot or the index not written — which already names the way, so the
 * same sentence reaches the answer instead of a null that could mean any of
 * them. A tool without a way back says so with "not undoable: …".
 *
 * Read-back: "saved_fields" holds what the site stores after the write, read
 * again past the object cache; where that is not what the call asked for,
 * "saved_differs" names the fields and "warning" says so. A plugin can
 * rewrite a value on save (a sanitize callback of register_meta(), a kses
 * filter on a title), or stop it; "updated: true" alone would hide both.
 * Whether a write that changed nothing at all is an error is the tool's
 * decision (AB_MCP_Undo_Meta::write()).
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class AB_MCP_Undo_Answer
 */
class AB_MCP_Undo_Answer {

	/**
	 * Largest value, in bytes as stored, that saved_fields shows. A longer one
	 * (a page builder's layout in a meta key, say) would double the answer;
	 * it is named in saved_fields_omitted, and still compared.
	 */
	const SHOW_MAX = 4096;

	/**
	 * The codes of AB_MCP_Undo::record_result(), whose messages say why there
	 * is no undo point and what to do about it.
	 */
	const RECORD_CODES = array(
		'ab_mcp_undo_disabled',
		'ab_mcp_undo_expired',
		'ab_mcp_undo_no_data',
		'ab_mcp_undo_encode_failed',
		'ab_mcp_undo_too_large',
		'ab_mcp_undo_write_failed',
		'ab_mcp_undo_index_failed',
		'ab_mcp_undo_bad_after',
	);

	/**
	 * Put the outcome of a recording into an answer: "undo" with the token,
	 * or null and "undo_note" with the reason.
	 *
	 * @param array                $res     Answer so far.
	 * @param string|WP_Error|null $outcome Token, the error of record_result(),
	 *                                      or a reason in words.
	 * @return array
	 */
	public static function with( array $res, $outcome ) {
		if ( is_string( $outcome ) && class_exists( 'AB_MCP_Undo' ) && AB_MCP_Undo::valid_token( $outcome ) ) {
			$res['undo'] = $outcome;
			unset( $res['undo_note'] );
			return $res;
		}
		$res['undo']      = null;
		$res['undo_note'] = self::note( $outcome );
		return $res;
	}

	/**
	 * Why there is no undo point, in one sentence that names the way.
	 *
	 * @param WP_Error|string|null $why The error of record_result(), or a reason in words.
	 * @return string
	 */
	public static function note( $why ) {
		if ( is_wp_error( $why ) ) {
			$msg = trim( (string) $why->get_error_message() );
			// The messages of record_result() say why and what to do; any
			// other error is framed so the answer still names a way.
			if ( '' !== $msg && in_array( (string) $why->get_error_code(), self::RECORD_CODES, true ) ) {
				return $msg;
			}
			return sprintf(
				/* translators: %s: the reason, as the undo store gave it */
				__( 'No undo point was recorded (%s). Check the result of the change before relying on it; wp_undo_list shows the undo points there are.', 'alphabridge-mcp' ),
				'' !== $msg ? $msg : (string) $why->get_error_code()
			);
		}
		if ( is_string( $why ) && '' !== trim( $why ) ) {
			return $why;
		}
		return __( 'No undo point was recorded. Check the result of the change before relying on it; wp_undo_list shows the undo points there are.', 'alphabridge-mcp' );
	}

	/**
	 * The note of a tool that has no way back: "not undoable: " and why.
	 *
	 * @param string $reason Why, with the way (what to check or keep first).
	 * @return string
	 */
	public static function not_undoable( $reason ) {
		/* translators: %s: why the change has no way back, and what to do first */
		return sprintf( __( 'not undoable: %s', 'alphabridge-mcp' ), (string) $reason );
	}

	/**
	 * The note when the call changed nothing: there is nothing to take back.
	 *
	 * @param string $reader The tool that shows the value.
	 * @return string
	 */
	public static function nothing_changed( $reader ) {
		return sprintf(
			/* translators: %s: tool name, e.g. wp_get_post_meta */
			__( 'No undo point: the site already held what was asked for, so nothing changed. %s shows it.', 'alphabridge-mcp' ),
			(string) $reader
		);
	}

	/**
	 * The note when the state before the write could not be read: an undo
	 * point taken on a guess could delete what it should put back.
	 *
	 * @param string $reader The tool that shows the value.
	 * @return string
	 */
	public static function unreadable( $reader ) {
		return sprintf(
			/* translators: %s: tool name, e.g. wp_get_post_meta */
			__( 'No undo point: the previous value could not be read from the database, so there was nothing reliable to keep. Check the result with %s before relying on the change.', 'alphabridge-mcp' ),
			(string) $reader
		);
	}

	/**
	 * Does AlphaBridge MCP Pro on this site run a wp_undo of its own that
	 * cannot reverse an undo point of this tool? Pro registers its wp_undo
	 * over this plugin's, and its restore refuses a tool its policy() does
	 * not name; a token would then be one neither wp_undo nor
	 * wp_undo_discard could use there. Pro says it hands the undo points of a
	 * tool to this plugin's restore by naming the tool in its policy, or on
	 * the filter ab_mcp_undo_core_restores.
	 *
	 * @param string $tool Tool name.
	 * @return bool
	 */
	public static function pro_cannot_undo( $tool ) {
		if ( ! class_exists( 'AB_MCP_Pro_Undo_Restore' ) || ! method_exists( 'AB_MCP_Pro_Undo_Restore', 'policy' ) ) {
			return false;
		}
		$policy = AB_MCP_Pro_Undo_Restore::policy();
		if ( is_array( $policy ) && isset( $policy[ (string) $tool ] ) ) {
			return false;
		}
		/**
		 * Whether the wp_undo that runs on this site reverses the undo points
		 * of a tool of this plugin with this plugin's restore, though Pro's
		 * restore does not name the tool. AlphaBridge MCP Pro answers true for
		 * the tools whose undo points its wp_undo hands on.
		 *
		 * @param bool   $handed Default false.
		 * @param string $tool   Tool name.
		 */
		return ! apply_filters( 'ab_mcp_undo_core_restores', false, (string) $tool );
	}

	/**
	 * The note for pro_cannot_undo().
	 *
	 * @param string $tool   Tool name.
	 * @param string $reader The tool that shows the result.
	 * @return string
	 */
	public static function pro_note( $tool, $reader ) {
		return sprintf(
			/* translators: 1: tool name, 2: tool that shows the result */
			__( 'No undo point: the wp_undo of AlphaBridge MCP Pro, which runs on this site, does not reverse changes of %1$s. Check the result with %2$s before relying on the change.', 'alphabridge-mcp' ),
			(string) $tool,
			(string) $reader
		);
	}

	/**
	 * The fixed notes this class words itself, for the check that each one
	 * names a way (AnswerWayTest). not_undoable() is a frame: the reason it
	 * is given names the way.
	 *
	 * @return string[]
	 */
	public static function own_notes() {
		return array(
			self::note( null ),
			self::note( new WP_Error( 'ab_mcp_undo_other' ) ),
			self::nothing_changed( 'wp_get_post_meta' ),
			self::unreadable( 'wp_get_post_meta' ),
			self::pro_note( 'wp_update_media', 'wp_get_media' ),
			self::differs_warning( array( 'x' ), 'wp_get_post_meta' ),
		);
	}

	/* ------------------------------------------------------------ read-back */

	/**
	 * The form WordPress stores a value of a meta key in, as get_*_meta()
	 * gives it back: a list or map as it is (serialized and read back with
	 * its types), anything else as text — true as "1", false and null as "".
	 *
	 * @param mixed $value Value as the call sent it.
	 * @return mixed
	 */
	public static function stored_form( $value ) {
		if ( is_array( $value ) ) {
			return $value;
		}
		if ( is_bool( $value ) ) {
			return $value ? '1' : '';
		}
		return null === $value ? '' : (string) $value;
	}

	/**
	 * Add the read-back to an answer: saved_fields with what is stored now,
	 * and where that is not what was asked, saved_differs and a warning.
	 *
	 * @param array    $res    Answer so far.
	 * @param array    $asked  field => value in stored_form(), as the call asked.
	 * @param array    $saved  field => value as read back; a field missing here was not found at all.
	 * @param string   $reader The tool that reads these fields.
	 * @param string[] $hidden Fields whose stored value the reader would not show: compared, not shown.
	 * @return array
	 */
	public static function saved( array $res, array $asked, array $saved, $reader, array $hidden = array() ) {
		$shown   = array();
		$omitted = array();
		$off     = array();
		foreach ( $asked as $field => $want ) {
			$field = (string) $field;
			$have  = array_key_exists( $field, $saved ) ? $saved[ $field ] : null;
			if ( ! array_key_exists( $field, $saved ) || ! self::same( $have, $want ) ) {
				$off[] = $field;
			}
			if ( in_array( $field, $hidden, true ) ) {
				$omitted[ $field ] = 'not_shown';
				continue;
			}
			if ( self::bytes( $have ) > self::SHOW_MAX ) {
				$omitted[ $field ] = 'too_long';
				continue;
			}
			$shown[ $field ] = $have;
		}
		$res['saved_fields'] = (object) $shown;
		if ( array() !== $omitted ) {
			$res['saved_fields_omitted'] = $omitted;
		}
		if ( array() !== $off ) {
			$res['saved_differs'] = $off;
			$res['warning']       = self::differs_warning( $off, $reader );
		}
		return $res;
	}

	/**
	 * Is the value read back the one asked for? Exactly, types included. A
	 * sanitize callback can make an object of the value, which WordPress
	 * stores serialized and gives back as a new object each time: objects
	 * are compared by what they hold, also inside an array (a stored object
	 * AB_MCP_Redactor::value_back() put back), and nothing here may throw
	 * once the write has happened.
	 *
	 * @param mixed $have Value as read back.
	 * @param mixed $want Value as asked, in stored_form().
	 * @return bool
	 */
	public static function same( $have, $want ) {
		if ( $have === $want ) {
			return true;
		}
		if ( ! is_array( $have ) && ! is_object( $have ) ) {
			return false;
		}
		try {
			return serialize( $have ) === serialize( $want ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- compared as WordPress stores them.
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * The size of a value as WordPress stores it: a list, a map or an object
	 * serialized, anything else as text. A value that cannot be measured
	 * counts as too long to show.
	 *
	 * @param mixed $value Value as read back.
	 * @return int
	 */
	public static function bytes( $value ) {
		if ( null === $value || is_scalar( $value ) ) {
			return strlen( (string) $value );
		}
		try {
			return strlen( serialize( $value ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- the size as WordPress stores it.
		} catch ( \Throwable $e ) {
			return PHP_INT_MAX;
		}
	}

	/**
	 * The warning of saved().
	 *
	 * @param string[] $fields Fields that differ.
	 * @param string   $reader The tool that reads them.
	 * @return string
	 */
	public static function differs_warning( array $fields, $reader ) {
		return sprintf(
			/* translators: 1: comma-separated field names, 2: tool name */
			__( 'The site stored something else than asked for: %1$s. saved_fields shows what is stored now; a plugin may rewrite or refuse these values on save. Check them with %2$s before relying on the change.', 'alphabridge-mcp' ),
			implode( ', ', $fields ),
			(string) $reader
		);
	}
}

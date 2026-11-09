<?php
/**
 * Every write of a step with a call of the core, forward and back, in one
 * order (Codex round 3 of branch 11, A6): the rights first (their hooks,
 * map_meta_cap and user_has_cap, run here), then the last read of the
 * object, then the comparison of that read with the state the engine
 * expects of the step (the whole object as current() reads it, or the whole
 * row on the way back), then at once the call; between the read and the
 * call no call runs a hook. Where the state differs, nothing is written:
 * the engine reads the change as another hand's and the step deviates
 * (decision A). A hook of the rights that allows and changes the object
 * (its own field as well as another) is never written over unseen.
 *
 * Every writer of the engine writes through it (AB_MCP_Sync_Post_Writer,
 * AB_MCP_Sync_Term_Writer, AB_MCP_Sync_Attachment_Writer,
 * AB_MCP_Sync_Menu_Writer, AB_MCP_Sync_Menu_Item_Writer,
 * AB_MCP_Sync_Widget_Area_Writer, AB_MCP_Sync_Css_Writer,
 * AB_MCP_Sync_Option_Writer) where it asks a right before a call of the
 * core. A raw way back (AB_MCP_Sync_Shared_Row::put_back(), the raw meta)
 * is byte for byte conditional in the database itself and calls no core.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-sync-shared-row.php';

final class AB_MCP_Sync_Write {

	/** The code of a write refused because the object changed before it. */
	const CHANGED = 'ab_mcp_sync_changed_before_write';

	/**
	 * Every refusal of this helper in this request (a right missing, the
	 * last read failed, the state changed): no call of the core was made, so
	 * nothing of it is the step's own (refused(); Codex round 4 of branch
	 * 11, item 3). An error the call of the core answers is none of them.
	 *
	 * @var WeakMap|null
	 */
	private static $refusals = null;

	/**
	 * The rights, the last read, the comparison, the call: in this order.
	 *
	 * @param callable $rights  (): true|string|WP_Error, a string what this
	 *                          account may not do, a whole sentence in the
	 *                          language of the site («This account may not …»).
	 * @param callable $read    (): mixed|WP_Error, the state now.
	 * @param callable $matches ( mixed $now ): bool, whether it is the state
	 *                          the engine expects.
	 * @param callable $call    (): mixed, the call of the core.
	 * @return mixed|WP_Error What the call answers, or the refusal.
	 */
	public static function core( callable $rights, callable $read, callable $matches, callable $call ) {
		$may = $rights();
		if ( true !== $may ) {
			// A WP_Error of the writer carries its whole sentence; a string is
			// the whole sentence of what this account may not do, set in here.
			/* translators: %s: what this account may not do, a whole sentence in the language of the site with the capability (This account may not change the menus of this site (edit_theme_options).). */
			return self::refusal( is_wp_error( $may ) ? $may : new WP_Error( 'ab_mcp_sync_rights', sprintf( __( 'Not written: %s Connect with an account that may, or leave the object out of the plan.', 'alphabridge-mcp' ), (string) $may ) ) );
		}
		$now = $read();
		if ( is_wp_error( $now ) ) {
			return self::refusal( $now );
		}
		if ( true !== $matches( $now ) ) {
			return self::refusal( self::changed() );
		}
		return $call();
	}

	/**
	 * Whether an error is a refusal of this helper: no call of the core was
	 * made for it (the engine then owns nothing of the part, whatever reads
	 * other than before; Codex round 4 of branch 11, item 3).
	 *
	 * @param mixed $error An answer of a writer.
	 * @return bool
	 */
	public static function refused( $error ) {
		return $error instanceof WP_Error && null !== self::$refusals && isset( self::$refusals[ $error ] );
	}

	/**
	 * A refusal, noted as one.
	 *
	 * @param WP_Error $error The refusal.
	 * @return WP_Error
	 */
	private static function refusal( WP_Error $error ) {
		if ( null === self::$refusals ) {
			self::$refusals = new WeakMap();
		}
		self::$refusals[ $error ] = true;
		return $error;
	}

	/**
	 * A write of a step forward: the state the engine read right before the
	 * call ($pre, the writer's current()) against current() now. A new
	 * object (no id yet) has no state to compare: the rights, then the call.
	 * An object here without a state to compare is never written (fail
	 * closed: every call of the engine hands it).
	 *
	 * What the call needs besides (a row it writes whole, the dates of an
	 * item, the caches of the option API emptied) is read in $needs, as the
	 * first part of the last read, before current() and the comparison
	 * (Codex round 4 of branch 11, item 4): between the comparison and the
	 * call of the core no read, no cache and no hook. The comparison covers
	 * what $needs read where it is part of the object (the rows of an area
	 * as its fields, the positions of a menu, the row of an item).
	 *
	 * @param string          $w       Writer class.
	 * @param int|string|null $live_id The object here, null for a new one.
	 * @param mixed           $pre     current() right before the call.
	 * @param callable        $rights  As core().
	 * @param callable        $call    ( mixed $needed ): mixed, the call of the
	 *                                 core with what $needs read.
	 * @param callable|null   $needs   (): mixed|WP_Error, what the call needs.
	 * @return mixed|WP_Error
	 */
	public static function forward( $w, $live_id, $pre, callable $rights, callable $call, ?callable $needs = null ) {
		$needed = null;
		$first  = static function () use ( $needs, &$needed ) {
			$needed = null === $needs ? null : $needs();
			return is_wp_error( $needed ) ? $needed : true;
		};
		$then   = static function () use ( $call, &$needed ) {
			return $call( $needed );
		};
		if ( null === $live_id ) {
			return self::core( $rights, $first, static fn( $now ) => true, $then );
		}
		return self::core(
			$rights,
			static function () use ( $first, $w, $live_id ) {
				$done = $first();
				return is_wp_error( $done ) ? $done : $w::current( $live_id );
			},
			static fn( $now ) => is_array( $pre ) && is_array( $now ) && self::same( $pre, $now ),
			$then
		);
	}

	/**
	 * A write of the way back: the right (true, or the right missing), the
	 * last read, whether it holds what the step wrote ($matches), then the
	 * call; a refusal leaves the part as it is (left) with its note.
	 *
	 * @param callable $rights  (): true|string|WP_Error, a string the right
	 *                          that is missing, a WP_Error the whole note.
	 * @param callable $read    (): mixed|WP_Error, the state now.
	 * @param callable $matches ( mixed $now ): bool.
	 * @param callable $call    (): mixed, the call of the core.
	 * @param string   $changed The note where the state differs.
	 * @return mixed|WP_Error|array{state:string,note:string}
	 */
	public static function back( callable $rights, callable $read, callable $matches, callable $call, $changed ) {
		$whole = null;
		$done  = self::core(
			static function () use ( $rights, &$whole ) {
				$may = $rights();
				if ( is_wp_error( $may ) ) {
					$whole = $may->get_error_message();
					return new WP_Error( 'ab_mcp_sync_rights', $whole );
				}
				return true === $may ? true : new WP_Error( 'ab_mcp_sync_rights', (string) $may );
			},
			$read,
			$matches,
			$call
		);
		if ( is_wp_error( $done ) && in_array( $done->get_error_code(), array( 'ab_mcp_sync_rights', self::CHANGED ), true ) ) {
			if ( self::CHANGED === $done->get_error_code() ) {
				$note = (string) $changed;
			} elseif ( null !== $whole ) {
				$note = $whole;
			} else {
				/* translators: %s: what this account may not do, a whole sentence in the language of the site with the capability (This account may not edit the menu item 5 (edit_theme_options, edit_post).). */
				$note = sprintf( __( 'Not put back: %s It was asked again right before the write (a filter may have changed the rights). Set it by hand in wp-admin with an account that may.', 'alphabridge-mcp' ), $done->get_error_message() );
			}
			return array(
				'state' => 'left',
				'note'  => $note,
			);
		}
		return $done;
	}

	/**
	 * Whether two states are the same, byte for byte (their serialized
	 * bytes: never an empty answer for both, as an encoding that fails could
	 * give).
	 *
	 * @param mixed $a A state.
	 * @param mixed $b Another.
	 * @return bool
	 */
	public static function same( $a, $b ) {
		return hash_equals( hash( 'sha256', AB_MCP_Sync_Shared_Row::field( $a ) ), hash( 'sha256', AB_MCP_Sync_Shared_Row::field( $b ) ) );
	}

	/**
	 * The refusal of a write whose object changed right before it.
	 *
	 * @return WP_Error
	 */
	public static function changed() {
		return new WP_Error( self::CHANGED, __( 'Nothing was written: the object changed right before the call, after the last check of the rights (a hook of this site, or another hand). The step deviates; check the object in wp-admin, then make a new report and plan again.', 'alphabridge-mcp' ) );
	}
}

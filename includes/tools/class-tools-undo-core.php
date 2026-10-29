<?php
/**
 * Undo tools: list, apply and discard the undo points that tools record
 * before they change something (wp_update_post, wp_bulk_update_status,
 * wp_update_site_settings).
 *
 * The tools here deliberately hold no security logic of their own. Ownership
 * and expiry live in AB_MCP_Undo, and every permission decision lives in
 * AB_MCP_Undo_Restore, which re-checks the capability of the ORIGINAL tool.
 * An undo can therefore never be a privilege escalation: whoever may undo an
 * action could have performed it in the first place.
 *
 * Free since 4.9.0, before in Pro only (AB_MCP_Tools_Undo there). Pro
 * registers its own wp_undo, wp_undo_list and wp_undo_discard over these,
 * which also reverse the changes of its own tools.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class AB_MCP_Tools_Undo_Core
 */
class AB_MCP_Tools_Undo_Core extends AB_MCP_Tools_Base {

	/**
	 * Register tools.
	 *
	 * @param AB_MCP_Tool_Registry $r Registry.
	 */
	public static function register( AB_MCP_Tool_Registry $r ) {

		$r->register(
			'wp_undo_list',
			array(
				'description' => 'List the account\'s own undo points: the snapshots that wp_update_post, wp_bulk_update_status and wp_update_site_settings took before they changed something. Returns undo_points, newest first, each with token, tool, kind, summary, items, bytes, created_at, expires_at and reversible (false, with a reason, where this connection may not act on it), plus count and ttl_days. Only the account\'s own undo points are visible. They expire after the undo window of this site, at most 7 days (the result reports it as ttl_days), and the store keeps a bounded number and size of points (by default the newest 50 and 100 MB in total), dropping the oldest first. Listing changes nothing: points past these limits are left out of the list and removed the next time a point is recorded. wp_undo restores a point; wp_undo_discard drops one. With undo switched off on the site, the answer says so.',
				'capability'  => 'read',
				'readonly'    => true,
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'limit' => array( 'type' => 'integer', 'description' => 'How many of the newest undo points to return, 1-50 (clamped; 0 counts as the default); it caps this answer only, not the store. Default 20.' ),
					),
				),
				'callback'    => array( __CLASS__, 'undo_list' ),
			)
		);

		$r->register(
			'wp_undo',
			array(
				'description' => 'Reverse one earlier change using its undo token (from the "undo" field of the original result, or from wp_undo_list): the fields of a post changed with wp_update_post, the statuses wp_bulk_update_status set, or the site settings changed with wp_update_site_settings. dry_run=true shows exactly what would be restored and what refused, without changing anything; wp_undo_discard drops a point without restoring it. Undoing needs the same permission as the original action, and each item is re-checked against the CURRENT state, so a post this account may no longer edit is reported as blocked rather than restored. A fully successful undo consumes the token. A partial one consumes it too and returns rest_token, a new undo point with only what did not come back, so a later call retries just that; where nothing came back, the token is kept. Afterwards the page caches of the supported caching plugins are asked to purge the restored posts, and after an undo of site settings every page of this site; page_cache_purged lists them, page_cache_skipped names a cache left out on a multisite network and why.',
				'open_world'  => true, // restores published content and settings.
				'capability'  => 'read',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'token'   => array( 'type' => 'string', 'description' => 'The undo token, e.g. undo_1a2b..., from the undo field of the original result or from wp_undo_list.' ),
						'dry_run' => array( 'type' => 'boolean', 'description' => 'true returns what the undo would restore and changes nothing. Default false.' ),
					),
					'required'   => array( 'token' ),
				),
				'callback'    => array( __CLASS__, 'undo' ),
			)
		);

		$r->register(
			'wp_undo_discard',
			array(
				'description' => 'Throw away an undo point and its snapshot without restoring anything; this is permanent, the change it covered can then no longer be undone. Meant for a change confirmed good; all=true drops all of the account\'s own undo points at once. Discarding needs the same permission and access level as undoing the original tool: a token that is malformed, no longer stored or not the account\'s own, or one this connection may not act on, is refused; with all=true such points are kept and counted in kept. Returns discarded (the count) and token, or all. Other users\' undo points are never touched.',
				'capability'  => 'read',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'token' => array( 'type' => 'string', 'description' => 'The undo token to discard (undo_…), as wp_undo_list or the original result shows it. Needed unless all=true.' ),
						'all'   => array( 'type' => 'boolean', 'description' => 'true discards all of the account\'s own undo points; token is then ignored.' ),
					),
				),
				'callback'    => array( __CLASS__, 'undo_discard' ),
			)
		);
	}

	/**
	 * The same "switched off" answer for all three tools.
	 *
	 * @return WP_Error
	 */
	protected static function off() {
		return new WP_Error(
			'ab_mcp_undo_disabled',
			__( 'Undo is switched off on this site, so no snapshots are being kept. An administrator switches it on with the wp_undo tool under Settings › AlphaBridge MCP › Fine-tuning.', 'alphabridge-mcp' )
		);
	}

	/**
	 * List undo points.
	 *
	 * @param array $a Args.
	 * @return array|WP_Error
	 */
	public static function undo_list( $a ) {
		if ( ! AB_MCP_Undo::enabled() ) {
			return self::off();
		}

		// No cleanup here: the tool is marked read-only, so listing must not
		// delete anything (OpenAI review finding, 16.10.2026). entries_for()
		// leaves out what the undo window and the bounds drop; recording a new
		// point removes it, and saving the undo window runs gc() for the
		// expired ones. entries_for() still takes the index lock: that is
		// bookkeeping of the lock option, not a change to any undo point.
		$limit   = (int) self::i( $a, 'limit', 20 );
		$entries = AB_MCP_Undo::entries_for( $limit ?: 20 );
		if ( is_wp_error( $entries ) ) {
			return $entries;
		}

		// Say per entry whether THIS token can actually act on it. Hiding the
		// others would be worse: the operator would wonder where their undo
		// point went instead of learning that they need a wider token.
		foreach ( $entries as $i => $e ) {
			$gate                       = AB_MCP_Undo_Restore::may_touch( isset( $e['tool'] ) ? $e['tool'] : '' );
			$entries[ $i ]['reversible'] = ! is_wp_error( $gate );
			if ( is_wp_error( $gate ) ) {
				$entries[ $i ]['reason'] = $gate->get_error_message();
			}
		}

		return array(
			'undo_points' => $entries,
			'count'       => count( $entries ),
			'ttl_hours'   => round( AB_MCP_Undo::ttl() / HOUR_IN_SECONDS, 1 ),
			'ttl_days'    => round( AB_MCP_Undo::ttl() / DAY_IN_SECONDS, 2 ),
			'note'        => empty( $entries )
				? __( 'No undo points. They are created by destructive tools and expire automatically.', 'alphabridge-mcp' )
				: __( 'Pass a token to wp_undo with dry_run=true to see what a restore would do.', 'alphabridge-mcp' ),
		);
	}

	/**
	 * Restore one undo point.
	 *
	 * @param array $a Args.
	 * @return array|WP_Error
	 */
	public static function undo( $a ) {
		if ( ! AB_MCP_Undo::enabled() ) {
			return self::off();
		}

		$token = self::s( $a, 'token', '' );
		if ( '' === $token ) {
			return new WP_Error( 'ab_mcp_undo_no_token', __( 'Which undo point? Pass a token from wp_undo_list.', 'alphabridge-mcp' ) );
		}

		// claim() checks the token format, ownership and expiry, and reads the
		// snapshot back. It does NOT delete anything — a failed restore has to
		// leave the undo point where it was.
		$claim = AB_MCP_Undo::claim( $token );
		if ( is_wp_error( $claim ) ) {
			return $claim;
		}

		$dry  = self::b( $a, 'dry_run', false );
		$opts = array(
			'force' => self::b( $a, 'force', false ),
			'parts' => isset( $claim['parts'] ) ? (array) $claim['parts'] : array(),
		);
		if ( $dry ) {
			$plan = AB_MCP_Undo_Restore::plan( $claim['entry'], $claim['data'], $opts );
			if ( is_wp_error( $plan ) ) {
				return $plan;
			}
			return array(
				'dry_run'    => true,
				'token'      => $token,
				'tool'       => $plan['tool'],
				'kind'       => $plan['kind'],
				'summary'    => $claim['entry']['summary'],
				'would_undo' => $plan['restorable'],
				'blocked'    => $plan['blocked'],
				'note'       => __( 'Nothing was changed. Call wp_undo again without dry_run to apply this.', 'alphabridge-mcp' ),
			);
		}

		// Reserve the point before touching anything. Two wp_undo calls racing on
		// the same token would otherwise both read this snapshot and both write
		// it back, so the second one would undo a change that was made in
		// between. The reservation is released in every exit path.
		$reserved = AB_MCP_Undo::begin_apply( $token );
		if ( is_wp_error( $reserved ) ) {
			return $reserved;
		}
		$nonce = (string) $reserved;

		try {
			$res = AB_MCP_Undo_Restore::apply( $claim['entry'], $claim['data'], $opts );
		} catch ( \Throwable $e ) {
			// \Throwable, not \Exception: a TypeError inside a restore callback
			// must release the reservation just like any other failure.
			AB_MCP_Undo::end_apply( $token, $nonce );
			throw $e;
		}
		if ( is_wp_error( $res ) ) {
			AB_MCP_Undo::end_apply( $token, $nonce );
			return $res;
		}

		// Consumption rule: a complete restore uses the token up. A partial
		// one puts what did not come back into a new undo point, which
		// replaces this one: a retry then puts back only that, never again
		// what came back and may have been changed since. Where nothing came
		// back the point stays as it was, so the operator can fix the cause
		// and run the same undo again. Consumption and release are ONE
		// nonce-checked operation in the store, so a second holder can never
		// be raced here; a request that read this point before is refused
		// once it is used up.
		$complete   = empty( $res['blocked'] ) && empty( $res['failed'] );
		$consume    = $complete;
		$rest_token = null;
		if ( ! $complete ) {
			$rest = AB_MCP_Undo_Restore::remaining( $claim['entry'], $claim['data'], $res );
			if ( null !== $rest ) {
				$e    = $claim['entry'];
				$made = AB_MCP_Undo::record_result(
					array(
						'tool'       => (string) $e['tool'],
						'cap'        => (string) $e['cap'],
						'kind'       => (string) $e['kind'],
						'summary'    => (string) $e['summary'],
						'items'      => isset( $rest['fields'] ) ? 1 : count( (array) $rest ),
						'data'       => $rest,
						'expires_at' => (int) $e['expires_at'],
					)
				);
				if ( is_wp_error( $made ) ) {
					$res['warning'] = sprintf(
						/* translators: %s: why the rest could not be kept */
						__( 'Partially restored, but what did not come back could not be kept as an undo point of its own (%s). This undo point was kept: running it again would also put back what came back now, over any change since. Discard it with wp_undo_discard unless nothing has changed since.', 'alphabridge-mcp' ),
						$made->get_error_message()
					);
				} else {
					$rest_token = (string) $made;
					$consume    = true;
				}
			}
		}
		$fin = AB_MCP_Undo::finish_apply( $token, $nonce, $consume );
		if ( $consume && is_wp_error( $fin ) ) {
			// The restore has HAPPENED — only the bookkeeping failed. Try once
			// more; if the point still cannot be taken out, the reservation is
			// deliberately NOT released: it keeps the point blocked for the
			// next 120 seconds while the operator discards it by hand.
			usleep( 150000 );
			$fin = AB_MCP_Undo::finish_apply( $token, $nonce, true );
			if ( is_wp_error( $fin ) ) {
				// The two-minute promise below counts from NOW, not from a
				// begin_apply() that a long restore may have left far behind.
				AB_MCP_Undo::extend_apply( $token, $nonce );
				if ( null !== $rest_token ) {
					// The old point stays; a second one with the rest would
					// double it.
					AB_MCP_Undo::discard( $rest_token );
					$rest_token     = null;
					$res['warning'] = __( 'Partially restored, but this undo point could not be replaced by one with what did not come back. It stays blocked for two minutes — discard it with wp_undo_discard, and do NOT run this undo again: it would also put back what came back now.', 'alphabridge-mcp' );
				} else {
					$res['warning'] = __( 'Restored in full, but the undo point could not be removed. It stays blocked for two minutes — discard it with wp_undo_discard, and do NOT run this undo again.', 'alphabridge-mcp' );
				}
				$complete = false;
				$consume  = false;
			}
		}

		// Only now, after the undo point's bookkeeping: a slow or failing page
		// cache can then neither hold the reservation nor leave it half-booked.
		$res = array_merge( $res, AB_MCP_Undo_Restore::after_restore( $res ) );

		$res['token']    = $token;
		$res['consumed'] = $consume;
		if ( null !== $rest_token ) {
			$res['rest_token'] = $rest_token;
		}
		if ( empty( $res['warning'] ) ) {
			if ( $complete ) {
				$res['note'] = __( 'Restored. This undo point has been used up.', 'alphabridge-mcp' );
			} elseif ( null !== $rest_token ) {
				$res['note'] = __( 'Partially restored. This undo point has been used up; what did not come back is kept as a new one: pass rest_token to wp_undo once the cause is fixed.', 'alphabridge-mcp' );
			} else {
				$res['note'] = __( 'Nothing was restored. The undo point was kept so you can retry once the cause is fixed.', 'alphabridge-mcp' );
			}
		}

		return $res;
	}

	/**
	 * Discard one undo point, or all of the current user's.
	 *
	 * @param array $a Args.
	 * @return array|WP_Error
	 */
	public static function undo_discard( $a ) {
		if ( ! AB_MCP_Undo::enabled() ) {
			return self::off();
		}

		// Throwing an undo point away is not a read: it destroys the only way
		// back from a change. It therefore needs the same permission and scope
		// as reversing it would — otherwise a content-scoped token could wipe
		// the undo points that a full-scoped one created for plugin files.
		if ( self::b( $a, 'all', false ) ) {
			$skipped = 0;
			$n       = AB_MCP_Undo::clear_for_user(
				function ( $entry ) use ( &$skipped ) {
					$tool = isset( $entry['tool'] ) ? (string) $entry['tool'] : '';
					if ( is_wp_error( AB_MCP_Undo_Restore::may_touch( $tool ) ) ) {
						$skipped++;
						return false;
					}
					return true;
				}
			);
			if ( is_wp_error( $n ) ) {
				return $n;
			}
			$out = array(
				'discarded' => $n,
				'all'       => true,
			);
			if ( $skipped ) {
				$out['kept']  = $skipped;
				$out['note']  = __( 'Some undo points were kept: they came from tools this token may not act on. Use a wider-scoped token to discard those too.', 'alphabridge-mcp' );
			}
			return $out;
		}

		$token = self::s( $a, 'token', '' );
		if ( '' === $token ) {
			return new WP_Error( 'ab_mcp_undo_no_token', __( 'Pass a token, or all=true to discard every undo point of yours.', 'alphabridge-mcp' ) );
		}

		// Read the point first so its source tool can be checked. claim() would
		// also decode the snapshot, which a discard has no business doing, so
		// the lighter metadata lookup is used.
		$entry = AB_MCP_Undo::entry_for( $token );
		if ( is_wp_error( $entry ) ) {
			return $entry;
		}
		$gate = AB_MCP_Undo_Restore::may_touch( isset( $entry['tool'] ) ? $entry['tool'] : '' );
		if ( is_wp_error( $gate ) ) {
			return $gate;
		}

		$res = AB_MCP_Undo::discard( $token );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return array(
			'discarded' => 1,
			'token'     => $token,
		);
	}
}

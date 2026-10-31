<?php
/**
 * Undo store: an option-backed index of recent reversible actions, with the
 * larger snapshots kept as protected files.
 *
 * A token is an INDEX, not a bearer credential: it carries no data and grants
 * no right. Ownership is bound to the WordPress user (checked again at restore
 * time), and every restore re-checks the original tool's capability. See
 * AB_MCP_Undo_Restore for the restore side.
 *
 * The index mirrors AB_MCP_Audit_Log — an option-backed ring buffer, no schema.
 * Small database snapshots (post statuses, an option value) live inline in the
 * index; anything larger and every file snapshot goes to one gzip file per
 * token under a protected uploads subfolder, the same directory pattern as the
 * transfer sessions.
 *
 * Four limits keep the store bounded, and they are meant to be read together:
 * the undo window (7 days; shorter where an administrator set fewer days on
 * the settings screen of AlphaBridge MCP Pro, which offers up to 30), the
 * number of points (the newest 50), the size of one point as it is STORED
 * (1 MB after compression), and the size of the whole store (100 MB, oldest
 * points dropped first). Every one of them has a filter.
 *
 * Until 4.9.0 this store came with Pro only. Its option, lock and folder keep
 * the names Pro gave them, so the undo points and the window of a site that
 * ran Pro carry over as they are, and Pro and the core read and write the
 * same store.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class AB_MCP_Undo
 */
class AB_MCP_Undo {

	const OPTION   = 'ab_mcp_pro_undo_index';
	const LOCK     = 'ab_mcp_pro_undo_lock';
	const SUBDIR   = 'ab-mcp-undo';
	const DB_KINDS = 'post_status,option,site_settings,theme,post_fields,meta,term,menu_item,widget,comment';

	/**
	 * The undo window as set on the settings screen of Pro, in whole days.
	 * Days and not seconds because that is the unit the screen offers; a
	 * stored value the screen could not show again would make the setting lie.
	 */
	const TTL_OPTION = 'ab_mcp_pro_undo_ttl_days';

	/**
	 * Seven days by default. A day was too short to be a way back: a change
	 * made on a Friday could no longer be undone on the Monday after.
	 */
	const TTL_DAYS_DEFAULT = 7;
	const TTL_DAYS_MIN     = 1;

	/**
	 * Seven days at most here (max_days()); Pro keeps points for up to 30. A
	 * longer window stored by Pro reads as seven while Pro is not there to
	 * say otherwise, a shorter one is kept.
	 */
	const TTL_DAYS_MAX = 7;

	/**
	 * Recording suspended? Set while a restore runs, so undoing does not create
	 * an undo point of its own.
	 *
	 * @var bool
	 */
	private static $suspended = false;

	/**
	 * Is undo recording/restoring switched on? Off is a valid state — the
	 * feature adds a snapshot to destructive actions and some operators prefer
	 * not to keep content around at all.
	 *
	 * @return bool
	 */
	public static function enabled() {
		// Switching wp_undo off in the admin has to stop the RECORDING too, not
		// just hide the tools. Otherwise the site keeps writing snapshots of
		// options and file contents that nobody can ever reach.
		$on = true;
		if ( class_exists( 'AB_MCP_Settings' ) ) {
			$on = AB_MCP_Settings::is_tool_enabled( 'wp_undo', array( 'dangerous' => false ) );
		}
		return (bool) apply_filters( 'ab_mcp_undo_enabled', $on );
	}

	/**
	 * Stop recording (used around a restore).
	 */
	public static function suspend() {
		self::$suspended = true;
	}

	/**
	 * Resume recording.
	 */
	public static function resume() {
		self::$suspended = false;
	}

	/**
	 * @return bool
	 */
	public static function is_suspended() {
		return self::$suspended;
	}

	/**
	 * How long an undo point lives, in seconds: the window (ttl_days()). The
	 * ab_mcp_undo_ttl filter receives that value and has the last word, so a
	 * site that fixed its window in code keeps it; only the 60-second floor
	 * applies to what the filter returns, not the 1-to-7-day range. Under a filter a point keeps the expiry it was
	 * recorded with (see expires_of()).
	 *
	 * @return int
	 */
	public static function ttl() {
		return max( 60, (int) apply_filters( 'ab_mcp_undo_ttl', self::ttl_days() * DAY_IN_SECONDS ) );
	}

	/**
	 * Is the window set in code, by a callback on ab_mcp_undo_ttl? Then the
	 * filter decides and not the stored window — also when it happens to
	 * answer with the same value.
	 *
	 * @return bool
	 */
	public static function ttl_filtered() {
		return (bool) has_filter( 'ab_mcp_undo_ttl' );
	}

	/**
	 * The window in days (1 to max_days(), default 7): what was set on the
	 * settings screen of Pro, if anything, within this store's range. Before
	 * the filter ab_mcp_undo_ttl.
	 *
	 * @return int
	 */
	public static function ttl_days() {
		return self::clamp_days( get_option( self::TTL_OPTION, self::TTL_DAYS_DEFAULT ) );
	}

	/**
	 * The longest window, in days: seven, or what the filter
	 * ab_mcp_undo_max_days says. AlphaBridge MCP Pro keeps its points in this
	 * same index and answers with its own limit (30 days): a point this
	 * plugin records there then lives as long as the window set in Pro, and
	 * the clean-up when it records (expires_of()) leaves Pro's longer-lived
	 * points alone. Never below the shortest window.
	 *
	 * @return int
	 */
	public static function max_days() {
		return max( self::TTL_DAYS_MIN, (int) apply_filters( 'ab_mcp_undo_max_days', self::TTL_DAYS_MAX ) );
	}

	/**
	 * Turn whatever is stored or submitted into a whole number of days within
	 * the range. A value that is not a number at all — a hand-edited row, an
	 * empty field — falls back to the default instead of to the lower bound,
	 * because one day would silently cut a site's window short. The clamping
	 * happens on the float, before the conversion: an out-of-range float has
	 * no defined integer in PHP.
	 *
	 * @param mixed $raw Stored or submitted value.
	 * @return int
	 */
	public static function clamp_days( $raw ) {
		if ( is_string( $raw ) ) {
			$raw = trim( $raw );
		}
		if ( ! ( is_int( $raw ) || is_float( $raw ) || ( is_string( $raw ) && is_numeric( $raw ) ) ) ) {
			return self::TTL_DAYS_DEFAULT;
		}
		$days = (float) $raw;
		if ( ! is_finite( $days ) ) {
			return self::TTL_DAYS_DEFAULT;
		}
		return (int) floor( max( (float) self::TTL_DAYS_MIN, min( (float) self::max_days(), $days ) ) );
	}

	/**
	 * When an undo point expires: the moment recorded with it, or earlier when
	 * the window has been shortened since. Shortening has to reach the points
	 * already kept — an administrator who cuts the window for privacy means the
	 * snapshots that exist, not only the next ones. Lengthening does not
	 * stretch them: a point keeps the expiry it was handed out with.
	 *
	 * Only the screen's window reaches back. A filter may answer differently
	 * from one request to the next (per user, per role), and every clean-up
	 * runs over all users' points: the request with the shortest window would
	 * delete the points other users were promised for longer. Under a filter a
	 * point therefore keeps its recorded expiry, as it did before the window
	 * could be set on screen.
	 *
	 * @param array    $e   Index entry (valid_entry()).
	 * @param int|null $ttl ttl(), when the caller already has it.
	 * @return int Unix time.
	 */
	public static function expires_of( array $e, $ttl = null ) {
		if ( self::ttl_filtered() ) {
			return (int) $e['expires_at'];
		}
		$ttl = ( null === $ttl ) ? self::ttl() : (int) $ttl;
		return min( (int) $e['expires_at'], (int) $e['created_at'] + $ttl );
	}

	/**
	 * Ring-buffer size: the newest N undo points are kept.
	 *
	 * @return int
	 */
	public static function max_entries() {
		return max( 1, (int) apply_filters( 'ab_mcp_undo_max_entries', 50 ) );
	}

	/**
	 * Largest serialized database snapshot kept inline in the index.
	 *
	 * @return int
	 */
	public static function inline_max() {
		return max( 0, (int) apply_filters( 'ab_mcp_undo_inline_max', 64 * 1024 ) );
	}

	/**
	 * Largest single snapshot, measured as STORED: the compressed size of a
	 * payload file (gzip where zlib is available, else the plain size), the
	 * plain size of an inline one. Measuring before compression refused large
	 * posts and files whose snapshot takes a fraction of the limit on disk.
	 *
	 * @return int
	 */
	public static function snapshot_max() {
		return max( 0, (int) apply_filters( 'ab_mcp_undo_snapshot_max', MB_IN_BYTES ) );
	}

	/**
	 * Largest snapshot BEFORE compression: the bound that keeps encoding it,
	 * and inflating it again on the way back, inside a request's memory.
	 * Eight times the stored limit, so 8 MB by default — JSON text compresses
	 * well, and a snapshot that fits the stored limit may be several times
	 * larger raw. The read side inflates against this ceiling, so a crafted
	 * file cannot talk it into more. Never below the stored limit, or an
	 * incompressible snapshot that fits could not be read back.
	 *
	 * @return int
	 */
	public static function raw_max() {
		$snap = self::snapshot_max();
		$def  = ( $snap > intdiv( PHP_INT_MAX, 8 ) ) ? PHP_INT_MAX : 8 * $snap;
		return max( $snap, (int) apply_filters( 'ab_mcp_undo_raw_max', $def ) );
	}

	/**
	 * Ceiling for the whole store: every point's stored size, inline rows and
	 * files alike; the oldest points go first. 100 MB by default. Under the
	 * default count and per-point limits the store holds at most 50 × 1 MB (an
	 * inline row is far smaller), so this default never drops a point the ring
	 * buffer would keep; it is what bounds the store once a site raises
	 * ab_mcp_undo_max_entries or ab_mcp_undo_snapshot_max.
	 *
	 * @return int
	 */
	public static function max_total_bytes() {
		return max( 1, (int) apply_filters( 'ab_mcp_undo_max_total_bytes', 100 * MB_IN_BYTES ) );
	}

	/**
	 * Mint a fresh token: undo_ + 32 hex.
	 *
	 * @return string
	 */
	public static function new_token() {
		return 'undo_' . bin2hex( self::random_bytes( 16 ) );
	}

	/**
	 * Is this a well-formed token? Format only — never proof of ownership.
	 *
	 * @param string $token Candidate.
	 * @return bool
	 */
	public static function valid_token( $token ) {
		return (bool) preg_match( '/^undo_[0-9a-f]{32}$/', (string) $token );
	}

	/**
	 * Record a reversible action and return its token, or null if nothing was
	 * stored (undo off, snapshot too large, or a non-serialisable value). A null
	 * result is normal and means "this action has no undo".
	 *
	 * The callers' contract since undo exists, kept as it was. A caller that
	 * has to say WHY there is no undo point uses record_result().
	 *
	 * @param array $rec { tool, cap, kind, summary, items, data }.
	 * @return string|null
	 */
	public static function record( array $rec ) {
		$result = self::record_result( $rec );
		return is_wp_error( $result ) ? null : $result;
	}

	/**
	 * Record a reversible action: the token, or a WP_Error whose code says why
	 * there is no undo point — switched off (ab_mcp_undo_disabled), nothing to
	 * keep (ab_mcp_undo_no_data), a value that cannot be stored
	 * (ab_mcp_undo_encode_failed), over a size limit (ab_mcp_undo_too_large),
	 * the snapshot file not written (ab_mcp_undo_write_failed) or the index
	 * not updated (ab_mcp_undo_index_failed). One null for all of them left a
	 * caller unable to tell "undo is off" from "the disk is full".
	 *
	 * @param array $rec { tool, cap, kind, summary, items, data, expires_at?, created_at?, replaces?, after? }.
	 * @return string|WP_Error
	 */
	public static function record_result( array $rec ) {
		if ( ! self::enabled() ) {
			return new WP_Error(
				'ab_mcp_undo_disabled',
				__( 'Undo is switched off on this site, so no undo point was recorded. An administrator switches it on with the wp_undo tool under Settings › AlphaBridge MCP › Fine-tuning, unless the ab_mcp_undo_enabled filter turns it off in code.', 'alphabridge-mcp' ),
				array( 'reason' => 'switched_off' )
			);
		}
		if ( self::$suspended ) {
			return new WP_Error(
				'ab_mcp_undo_disabled',
				__( 'Recording is paused while an undo is applied, so the undo itself gets no undo point. To reverse an undo, use the tool of the original change again.', 'alphabridge-mcp' ),
				array( 'reason' => 'suspended' )
			);
		}
		// A point that holds the rest of another (a partial undo) keeps that
		// one's expiry: narrowing never lengthens the window, and a window
		// that has passed gives no point at all.
		$expires = time() + self::ttl();
		if ( isset( $rec['expires_at'] ) ) {
			$expires = min( $expires, (int) $rec['expires_at'] );
			if ( $expires <= time() ) {
				return new WP_Error(
					'ab_mcp_undo_expired',
					__( 'The undo window of this undo point has already passed, so no undo point was recorded. Check the result of the change before relying on an undo.', 'alphabridge-mcp' )
				);
			}
		}
		$kind = isset( $rec['kind'] ) ? (string) $rec['kind'] : '';
		$data = isset( $rec['data'] ) ? $rec['data'] : null;
		if ( '' === $kind || null === $data ) {
			return new WP_Error(
				'ab_mcp_undo_no_data',
				__( 'There was no snapshot to keep for this action, so no undo point was recorded. Check the result of the change before relying on an undo.', 'alphabridge-mcp' )
			);
		}
		$prepared = self::prepare( $kind, $data );
		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}
		$serialized = $prepared['serialized'];
		$size       = $prepared['size'];
		$inline     = $prepared['inline'];
		$blob       = $prepared['blob'];
		$stored     = $prepared['stored'];
		$token      = self::new_token();
		$file       = null;
		$sha        = '';

		if ( null === $inline ) {
			$written = self::payload_write( $token, (string) $serialized, $blob );
			if ( is_wp_error( $written ) ) {
				return new WP_Error(
					'ab_mcp_undo_write_failed',
					sprintf(
						/* translators: %s: the reason the file could not be written. */
						__( 'The snapshot file could not be written (%s), so no undo point was recorded. Check that the uploads folder is writable and has free space.', 'alphabridge-mcp' ),
						$written->get_error_message()
					),
					array( 'cause' => $written->get_error_code() )
				);
			}
			$file = $written['file'];
			$sha  = $written['sha256'];
		}

		$now   = time();
		$entry = array(
			'token'      => $token,
			'tool'       => isset( $rec['tool'] ) ? (string) $rec['tool'] : '',
			'cap'        => isset( $rec['cap'] ) ? (string) $rec['cap'] : '',
			'kind'       => $kind,
			'summary'    => isset( $rec['summary'] ) ? self::clip( (string) $rec['summary'], 200 ) : '',
			'items'      => isset( $rec['items'] ) ? (int) $rec['items'] : 0,
			'bytes'      => $size,
			// What the point takes as stored — what the total limit counts.
			'stored'     => $stored,
			'user'       => (int) get_current_user_id(),
			// The rest of a partial undo keeps the age of its snapshot, so a
			// window shortened later counts from when it was taken.
			'created_at' => isset( $rec['created_at'] ) && (int) $rec['created_at'] > 0 && (int) $rec['created_at'] <= $now ? (int) $rec['created_at'] : $now,
			'expires_at' => $expires,
			'payload'    => $file,
			'sha256'     => $sha,
			'inline'     => $inline,
		);
		// The rest of a page-builder point keeps the state its pages were
		// left in (set_after()), so a later change is still refused.
		if ( isset( $rec['after'] ) && is_array( $rec['after'] ) && array() !== $rec['after'] ) {
			$entry['after'] = $rec['after'];
			if ( ! self::valid_entry( $entry ) ) {
				self::payload_delete( $file );
				return new WP_Error( 'ab_mcp_undo_bad_after', __( 'The state after the change is not in a form an undo point keeps. Check the result of the change before relying on an undo.', 'alphabridge-mcp' ) );
			}
		}

		// The payload file is on disk before the index knows about it. If the
		// index write does not happen — lock contention, a database error, or
		// the ring buffer immediately pruning this entry away — the file would
		// be an orphan nothing ever deletes, and the token would be a promise
		// that cannot be kept. So: confirm the entry actually landed, and clean
		// up if it did not.
		$landed   = false;
		$replaces = isset( $rec['replaces'] ) ? (string) $rec['replaces'] : '';
		$locked   = self::with_lock(
			function () use ( $entry, $replaces, &$landed ) {
				$index = self::read_index();
				// The rest of a partial undo takes the place of the point it
				// replaces: the bounds go oldest first by position, and the
				// rest is as old as that point's snapshot. The replaced point
				// does not count against them, so it never pushes out a point
				// of anybody else; it stays right in front of its rest until
				// wp_undo consumes it.
				$old = array();
				foreach ( $index as $i => $e ) {
					if ( '' !== $replaces && $e['token'] === $replaces ) {
						$old         = array( $e );
						$index[ $i ] = $entry;
						break;
					}
				}
				if ( array() === $old ) {
					$index[] = $entry;
				}
				// Expire and cap in the same critical section. The files of
				// the points this drops go only once the shorter index is
				// stored: if the write fails, the old index still lists them,
				// and a point it lists must keep its snapshot.
				$sel  = self::select_live( $index );
				$live = array();
				foreach ( $sel['live'] as $e ) {
					if ( array() !== $old && $e['token'] === $entry['token'] ) {
						$live = array_merge( $live, $old );
						$old  = array();
					}
					$live[] = $e;
				}
				$sel['live'] = array_merge( $old, $live );
				self::write_index( $sel['live'] );
				// Confirm against what is actually STORED, not the local array:
				// update_option can fail (a database error, a dropped
				// connection), and the local copy would still contain the entry.
				wp_cache_delete( self::OPTION, 'options' );
				foreach ( self::read_index() as $e ) {
					if ( $e['token'] === $entry['token'] ) {
						$landed = true;
						break;
					}
				}
				if ( $landed ) {
					foreach ( $sel['dropped'] as $e ) {
						self::payload_delete_entry( $e );
					}
				}
			}
		);

		if ( ! $locked || ! $landed ) {
			self::payload_delete( $file );
			return new WP_Error(
				'ab_mcp_undo_index_failed',
				__( 'The undo index could not be updated (it was busy, or the database did not keep the write), so no undo point was recorded. If this keeps happening, check the database error log.', 'alphabridge-mcp' ),
				array( 'reason' => $locked ? 'not_stored' : 'busy' )
			);
		}

		// Safety net against the one gap the orphan sweep cannot see: the file
		// was written before the index knew about it, and a sweep running in
		// that window (after a pathological pause) judges by mtime alone. If
		// the file is gone now, the entry is a promise without a snapshot —
		// take it back out rather than hand over a token that cannot restore.
		if ( null !== $file ) {
			$dir = self::dir();
			if ( is_wp_error( $dir ) || ! is_file( trailingslashit( $dir ) . $file ) ) {
				if ( ! self::remove( $token ) ) {
					// One retry; if the entry still cannot be taken out, claim()
					// fails closed on the missing file, so no wrong restore can
					// come of it — the entry just lingers until the TTL prunes it.
					usleep( 50000 );
					self::remove( $token );
				}
				return new WP_Error(
					'ab_mcp_undo_write_failed',
					__( 'The snapshot file was gone before the undo point was committed, so no undo point was recorded. Check that no other plugin or job empties the ab-mcp-undo folder in the uploads folder.', 'alphabridge-mcp' ),
					array( 'cause' => 'vanished' )
				);
			}
		}

		return $token;
	}

	/**
	 * The "over a limit" answer, with the numbers and the way around it.
	 *
	 * @param int    $bytes   Size that was measured.
	 * @param int    $limit   Limit it exceeded.
	 * @param string $measure raw (before compression), compressed, stored
	 *                        (no zlib, stored plain) or total (the whole store).
	 * @return WP_Error
	 */
	private static function too_large( $bytes, $limit, $measure ) {
		if ( 'raw' === $measure ) {
			/* translators: 1: snapshot size, 2: limit. */
			$msg = __( 'The previous state is %1$s before compression, over the %2$s an undo point may hold uncompressed, so no undo point was recorded. An administrator can raise the limit with the ab_mcp_undo_raw_max filter; changes in smaller steps stay undoable.', 'alphabridge-mcp' );
		} elseif ( 'total' === $measure ) {
			/* translators: 1: snapshot size, 2: limit. */
			$msg = __( 'The snapshot takes %1$s, more than the whole undo store may hold (%2$s), so no undo point was recorded. An administrator can raise the limit with the ab_mcp_undo_max_total_bytes filter.', 'alphabridge-mcp' );
		} else {
			/* translators: 1: snapshot size, 2: limit. */
			$msg = __( 'The snapshot takes %1$s as stored, over the %2$s limit per undo point, so no undo point was recorded. An administrator can raise the limit with the ab_mcp_undo_snapshot_max filter; changes in smaller steps stay undoable.', 'alphabridge-mcp' );
		}
		return new WP_Error(
			'ab_mcp_undo_too_large',
			sprintf( $msg, size_format( $bytes, 1 ), size_format( $limit, 1 ) ),
			array(
				'bytes'    => (int) $bytes,
				'limit'    => (int) $limit,
				'measured' => (string) $measure,
			)
		);
	}

	/**
	 * Kinds whose undo point may grow by parts under one token. A call that
	 * changes many pages (wp_search_replace over builder pages) keeps each
	 * page's raw copies as a part of its own, so the size limit applies per
	 * part and not to the sum: 25 Elementor pages of 30–100 KB each would
	 * otherwise be one point "too large" exactly where a way back matters.
	 */
	const PART_KINDS = array( 'builder_data' );

	/**
	 * Most parts one point may have. A call writes at most 25 pages (plan
	 * review S4), and a restore reads every part into memory before it plans,
	 * so the bound is kept close to that.
	 */
	const MAX_PARTS = 100;

	/**
	 * Encode a snapshot and measure it as it would be stored, without
	 * storing anything: the checks record_result() runs before it writes.
	 *
	 * @param string $kind Undo kind.
	 * @param mixed  $data Snapshot.
	 * @return array|WP_Error { serialized, size, inline (string|null), blob (string|null), stored }.
	 */
	private static function prepare( $kind, $data ) {
		if ( ! self::is_safe_value( $data ) ) {
			return new WP_Error(
				'ab_mcp_undo_encode_failed',
				__( 'The previous state holds a value an undo point cannot store safely (only text, numbers and lists of them), so no undo point was recorded. Check the result of the change before relying on an undo.', 'alphabridge-mcp' ),
				array( 'reason' => 'unsafe_value' )
			);
		}
		$serialized = self::encode( $data );
		if ( null === $serialized ) {
			return new WP_Error(
				'ab_mcp_undo_encode_failed',
				__( 'The previous state could not be encoded for an undo point (a value that is not valid text, for example), so no undo point was recorded. Check the result of the change before relying on an undo.', 'alphabridge-mcp' ),
				array( 'reason' => 'not_encodable' )
			);
		}
		$size = strlen( $serialized );
		if ( $size > self::raw_max() ) {
			return self::too_large( $size, self::raw_max(), 'raw' );
		}

		$is_db  = in_array( (string) $kind, explode( ',', self::DB_KINDS ), true );
		$inline = null;
		$blob   = null;

		// Inline means stored as it is, so its plain size is its stored size
		// and has to fit the per-point limit too.
		if ( $is_db && $size <= self::inline_max() && $size <= self::snapshot_max() ) {
			$inline = $serialized;
			$stored = $size;
		} else {
			// Compress first, then measure what will actually be on disk.
			$blob   = self::compress( $serialized );
			$stored = strlen( $blob );
			if ( $stored > self::snapshot_max() ) {
				return self::too_large( $stored, self::snapshot_max(), ( "\x1f\x8b" === substr( $blob, 0, 2 ) ) ? 'compressed' : 'stored' );
			}
		}
		// A point larger than the whole store would only push out every other
		// point and then be dropped itself; refuse it before writing anything.
		if ( $stored > self::max_total_bytes() ) {
			return self::too_large( $stored, self::max_total_bytes(), 'total' );
		}
		return array(
			'serialized' => $serialized,
			'size'       => $size,
			'inline'     => $inline,
			'blob'       => $blob,
			'stored'     => $stored,
		);
	}
	/**
	 * Would this snapshot fit an undo point? The same answers record_result()
	 * gives — switched off, nothing to keep, not storable, too large — without
	 * storing anything, so a preview can say beforehand that applying will
	 * need allow_without_undo.
	 *
	 * @param string $kind Undo kind.
	 * @param mixed  $data Snapshot.
	 * @return true|WP_Error
	 */
	public static function check_size( $kind, $data ) {
		if ( ! self::enabled() ) {
			return new WP_Error(
				'ab_mcp_undo_disabled',
				__( 'Undo is switched off on this site, so no undo point would be recorded. An administrator switches it on with the wp_undo tool under Settings › AlphaBridge MCP › Fine-tuning.', 'alphabridge-mcp' ),
				array( 'reason' => 'switched_off' )
			);
		}
		if ( '' === (string) $kind || null === $data ) {
			return new WP_Error( 'ab_mcp_undo_no_data', __( 'There is no snapshot to keep for this action. Check the result of the change before relying on an undo.', 'alphabridge-mcp' ) );
		}
		$prepared = self::prepare( $kind, $data );
		return is_wp_error( $prepared ) ? $prepared : true;
	}

	/**
	 * Add a part to an undo point of the current user: another snapshot of
	 * the same kind under the same token, measured on its own against the
	 * per-point limit. wp_undo restores all parts of a token together.
	 *
	 * The answers are record_result()'s, plus ab_mcp_undo_not_found (no such
	 * point of this user), ab_mcp_undo_in_progress (it is being restored right
	 * now) and ab_mcp_undo_kind_mismatch (another kind, or a kind without parts).
	 *
	 * @param string $token Token returned by record_result().
	 * @param array  $rec   { kind, summary?, items, data }.
	 * @return true|WP_Error
	 */
	public static function append_part( $token, array $rec ) {
		if ( ! self::enabled() ) {
			return new WP_Error(
				'ab_mcp_undo_disabled',
				__( 'Undo is switched off on this site, so no undo point was recorded. An administrator switches it on with the wp_undo tool under Settings › AlphaBridge MCP › Fine-tuning.', 'alphabridge-mcp' ),
				array( 'reason' => 'switched_off' )
			);
		}
		if ( self::$suspended ) {
			return new WP_Error(
				'ab_mcp_undo_disabled',
				__( 'Recording is paused while an undo is applied, so the undo itself gets no undo point. To reverse an undo, use the tool of the original change again.', 'alphabridge-mcp' ),
				array( 'reason' => 'suspended' )
			);
		}
		if ( ! self::valid_token( $token ) ) {
			return new WP_Error( 'ab_mcp_undo_bad_token', __( 'That is not a valid undo token. wp_undo_list shows your undo points and their tokens.', 'alphabridge-mcp' ) );
		}
		$kind = isset( $rec['kind'] ) ? (string) $rec['kind'] : '';
		$data = isset( $rec['data'] ) ? $rec['data'] : null;
		if ( '' === $kind || null === $data ) {
			return new WP_Error( 'ab_mcp_undo_no_data', __( 'There was no snapshot to keep for this action, so no undo point was recorded. Check the result of the change before relying on an undo.', 'alphabridge-mcp' ) );
		}
		if ( ! in_array( $kind, self::PART_KINDS, true ) ) {
			return new WP_Error( 'ab_mcp_undo_kind_mismatch', __( 'Undo points of this kind do not take parts. Change the remaining pages in a separate call; it gets an undo point of its own.', 'alphabridge-mcp' ) );
		}
		$entry = self::find_owned( $token );
		if ( is_wp_error( $entry ) ) {
			return $entry;
		}
		if ( $entry['kind'] !== $kind ) {
			return new WP_Error( 'ab_mcp_undo_kind_mismatch', __( 'The part is of another kind than the undo point it should join. Change the remaining pages in a separate call; it gets an undo point of its own.', 'alphabridge-mcp' ) );
		}
		$parts = isset( $entry['parts'] ) && is_array( $entry['parts'] ) ? $entry['parts'] : array();
		if ( count( $parts ) >= self::MAX_PARTS ) {
			return new WP_Error(
				'ab_mcp_undo_too_large',
				sprintf(
					/* translators: %d: most parts one undo point may have. */
					__( 'This undo point already has %d parts, the most one point may have, so no further part was recorded. Change the remaining pages in a separate call; it gets an undo point of its own.', 'alphabridge-mcp' ),
					self::MAX_PARTS
				),
				array(
					'bytes'    => count( $parts ) + 1,
					'limit'    => self::MAX_PARTS,
					'measured' => 'parts',
				)
			);
		}
		$prepared = self::prepare( $kind, $data );
		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}
		if ( self::stored_size( $entry ) + $prepared['stored'] > self::max_total_bytes() ) {
			return self::too_large( self::stored_size( $entry ) + $prepared['stored'], self::max_total_bytes(), 'total' );
		}

		// The part's file name comes from the token, the part number and the
		// salt, like the first one's: unguessable, and never the same twice.
		$n       = count( $parts ) + 1;
		$written = self::payload_write( $token . '#' . $n . '#' . bin2hex( self::random_bytes( 4 ) ), (string) $prepared['serialized'], (string) $prepared['blob'] );
		if ( is_wp_error( $written ) ) {
			return new WP_Error(
				'ab_mcp_undo_write_failed',
				sprintf(
					/* translators: %s: the reason the file could not be written. */
					__( 'The snapshot file could not be written (%s), so no undo point was recorded. Check that the uploads folder is writable and has free space.', 'alphabridge-mcp' ),
					$written->get_error_message()
				),
				array( 'cause' => $written->get_error_code() )
			);
		}
		$part = array(
			'payload' => $written['file'],
			'sha256'  => $written['sha256'],
			'bytes'   => (int) $prepared['size'],
			'stored'  => (int) $prepared['stored'],
		);
		$items  = isset( $rec['items'] ) ? (int) $rec['items'] : 0;
		$uid    = (int) get_current_user_id();
		$result = new WP_Error( 'ab_mcp_undo_not_found', __( 'No undo point with that token for your account. wp_undo_list shows yours; an undo point belongs to the account that made the change.', 'alphabridge-mcp' ) );
		$locked = self::with_lock(
			function () use ( $token, $uid, $part, $items, &$result ) {
				$index = self::read_index();
				foreach ( $index as $i => $e ) {
					if ( $e['token'] !== $token ) {
						continue;
					}
					if ( (int) $e['user'] !== $uid ) {
						return;
					}
					$busy = isset( $e['applying'] ) ? (int) $e['applying'] : 0;
					if ( $busy && ( time() - $busy ) < self::APPLY_TIMEOUT ) {
						$result = new WP_Error( 'ab_mcp_undo_in_progress', __( 'This undo point is being restored right now, so nothing can be added to it. Change the remaining pages in a separate call; it gets an undo point of its own.', 'alphabridge-mcp' ) );
						return;
					}
					$parts                  = isset( $e['parts'] ) && is_array( $e['parts'] ) ? $e['parts'] : array();
					$parts[]                = $part;
					$index[ $i ]['parts']   = $parts;
					$index[ $i ]['stored']  = self::stored_size( $e ) + $part['stored'];
					$index[ $i ]['bytes']   = (int) $e['bytes'] + $part['bytes'];
					$index[ $i ]['items']   = (int) $e['items'] + $items;
					self::write_index( $index );
					// Landed only when the stored index names the part.
					wp_cache_delete( self::OPTION, 'options' );
					$result = new WP_Error( 'ab_mcp_undo_index_failed', __( 'The undo index could not be updated, so this part of the undo point was not recorded. If this keeps happening, check the database error log.', 'alphabridge-mcp' ), array( 'reason' => 'not_stored' ) );
					foreach ( self::read_index() as $e2 ) {
						if ( $e2['token'] === $token && isset( $e2['parts'] ) && in_array( $part['payload'], self::part_payloads( $e2['parts'] ), true ) ) {
							$result = true;
						}
					}
					return;
				}
			}
		);
		if ( true !== $result || ! $locked ) {
			self::payload_delete( $part['payload'] );
			if ( ! $locked ) {
				return new WP_Error( 'ab_mcp_undo_index_failed', __( 'The undo index was busy, so this part of the undo point was not recorded. Try again in a moment.', 'alphabridge-mcp' ), array( 'reason' => 'busy' ) );
			}
		}
		return $result;
	}

	/**
	 * Keep, with an undo point, the state one post was left in by the change
	 * it undoes: a sha256 per stored copy, taken right after the write. wp_undo
	 * compares the copies with it and refuses where somebody has changed the
	 * page since — restoring would throw that work away — unless force is set.
	 *
	 * @param string               $token   Token of the current user's point.
	 * @param int                  $post_id Post.
	 * @param array<string,string> $hashes  loc => sha256 (64 hex).
	 * @return true|WP_Error
	 */
	public static function set_after( $token, $post_id, array $hashes ) {
		if ( ! self::valid_token( $token ) ) {
			return new WP_Error( 'ab_mcp_undo_bad_token', __( 'That is not a valid undo token. wp_undo_list shows your undo points and their tokens.', 'alphabridge-mcp' ) );
		}
		$post_id = (int) $post_id;
		$clean   = array();
		foreach ( $hashes as $loc => $sha ) {
			if ( ! is_string( $loc ) || '' === $loc || strlen( $loc ) > 255 || ! is_string( $sha ) || ! preg_match( '/^[0-9a-f]{64}$/', $sha ) ) {
				return new WP_Error( 'ab_mcp_undo_bad_after', __( 'The state after the change is not in a form an undo point keeps. Check the result of the change before relying on an undo.', 'alphabridge-mcp' ) );
			}
			$clean[ $loc ] = $sha;
		}
		if ( $post_id <= 0 || array() === $clean ) {
			return new WP_Error( 'ab_mcp_undo_bad_after', __( 'The state after the change is not in a form an undo point keeps. Check the result of the change before relying on an undo.', 'alphabridge-mcp' ) );
		}
		$uid    = (int) get_current_user_id();
		$result = new WP_Error( 'ab_mcp_undo_not_found', __( 'No undo point with that token for your account. wp_undo_list shows yours; an undo point belongs to the account that made the change.', 'alphabridge-mcp' ) );
		$locked = self::with_lock(
			function () use ( $token, $uid, $post_id, $clean, &$result ) {
				$index = self::read_index();
				foreach ( $index as $i => $e ) {
					if ( $e['token'] !== $token ) {
						continue;
					}
					if ( (int) $e['user'] !== $uid ) {
						return;
					}
					$after                       = isset( $e['after'] ) && is_array( $e['after'] ) ? $e['after'] : array();
					$after[ (string) $post_id ]  = $clean;
					$index[ $i ]['after']        = $after;
					self::write_index( $index );
					wp_cache_delete( self::OPTION, 'options' );
					$result = new WP_Error( 'ab_mcp_undo_index_failed', __( 'The undo index could not be updated with the state after the change. If this keeps happening, check the database error log.', 'alphabridge-mcp' ), array( 'reason' => 'not_stored' ) );
					foreach ( self::read_index() as $e2 ) {
						if ( $e2['token'] === $token && isset( $e2['after'][ (string) $post_id ] ) && $e2['after'][ (string) $post_id ] === $clean ) {
							$result = true;
						}
					}
					return;
				}
			}
		);
		if ( ! $locked ) {
			return new WP_Error( 'ab_mcp_undo_busy', __( 'The undo index is busy; try again in a moment.', 'alphabridge-mcp' ) );
		}
		return $result;
	}


	/**
	 * This user's undo points, newest first. Reading only: points past the undo
	 * window or beyond the store's bounds are left out of the list, not
	 * removed — recording a new point and gc() remove them (wp_undo_list is
	 * marked read-only, so listing must change nothing). Snapshot CONTENTS are
	 * never returned — only metadata.
	 *
	 * @param int $limit Max rows.
	 * @return array
	 */
	public static function entries_for( $limit = 20 ) {
		$uid    = (int) get_current_user_id();
		$limit  = max( 1, min( 50, (int) $limit ) );
		$out    = array();
		$ttl    = self::ttl();
		$locked = self::with_lock(
			function () use ( &$out, $uid, $limit, $ttl ) {
				$index = self::select_live( self::read_index() )['live'];
				foreach ( array_reverse( $index ) as $e ) {
					if ( (int) $e['user'] !== $uid ) {
						continue;
					}
					$out[] = array(
						'token'        => $e['token'],
						'tool'         => $e['tool'],
						'kind'         => $e['kind'],
						'summary'      => $e['summary'],
						'items'        => (int) $e['items'],
						'bytes'        => (int) $e['bytes'],
						'stored_bytes' => self::stored_size( $e ),
						'created_at'   => gmdate( 'c', (int) $e['created_at'] ),
						'expires_at'   => gmdate( 'c', self::expires_of( $e, $ttl ) ),
					);
					if ( count( $out ) >= $limit ) {
						break;
					}
				}
			}
		);
		if ( ! $locked ) {
			// "The index is busy" and "you have no undo points" must not look
			// the same — an operator would go hunting for a vanished token.
			return new WP_Error( 'ab_mcp_undo_busy', __( 'The undo index is busy; try again in a moment.', 'alphabridge-mcp' ) );
		}
		return $out;
	}

	/**
	 * Fetch an undo point and its snapshot for restore, or a WP_Error. Enforces
	 * ownership (current user) and expiry. Does NOT delete it — a failed or
	 * dry-run restore must leave the point in place.
	 *
	 * @param string $token Token.
	 * @return array|WP_Error { entry, data }.
	 */
	public static function claim( $token ) {
		if ( ! self::valid_token( $token ) ) {
			return new WP_Error( 'ab_mcp_undo_bad_token', __( 'That is not a valid undo token. wp_undo_list shows your undo points and their tokens.', 'alphabridge-mcp' ) );
		}
		$entry = self::find_owned( $token );
		if ( is_wp_error( $entry ) ) {
			return $entry;
		}
		if ( time() > self::expires_of( $entry ) ) {
			return new WP_Error( 'ab_mcp_undo_expired', __( 'This undo point has expired. Undo points are kept for up to 7 days (with AlphaBridge MCP Pro for the window set under Settings › AlphaBridge MCP); this change can no longer be undone with it. An earlier version of a post can still come back with wp_restore_revision.', 'alphabridge-mcp' ) );
		}

		if ( null !== $entry['inline'] ) {
			$data = self::decode( (string) $entry['inline'] );
		} else {
			$read = self::payload_read( (string) $entry['payload'], (string) $entry['sha256'] );
			if ( is_wp_error( $read ) ) {
				return $read;
			}
			$data = self::decode( $read );
		}
		if ( null === $data || ! self::is_safe_value( $data ) ) {
			return new WP_Error( 'ab_mcp_undo_corrupt', __( 'The stored snapshot could not be read back. This undo point cannot be used; wp_undo_discard removes it.', 'alphabridge-mcp' ) );
		}
		// Every part is read and checked before anything is restored: a point
		// whose third part is damaged must not be put back two thirds of the way.
		$parts = array();
		foreach ( isset( $entry['parts'] ) && is_array( $entry['parts'] ) ? $entry['parts'] : array() as $part ) {
			$read = self::payload_read( (string) $part['payload'], (string) $part['sha256'] );
			if ( is_wp_error( $read ) ) {
				return $read;
			}
			$part_data = self::decode( $read );
			if ( null === $part_data || ! self::is_safe_value( $part_data ) ) {
				return new WP_Error( 'ab_mcp_undo_corrupt', __( 'A part of the stored snapshot could not be read back. This undo point cannot be used; wp_undo_discard removes it.', 'alphabridge-mcp' ) );
			}
			$parts[] = $part_data;
		}
		return array(
			'entry' => $entry,
			'data'  => $data,
			'parts' => $parts,
		);
	}

	/**
	 * One undo point's metadata, owner-checked, WITHOUT decoding its snapshot.
	 * Used where only the origin needs to be known — discarding a point should
	 * not have to read the contents it is about to delete.
	 *
	 * @param string $token Token.
	 * @return array|WP_Error
	 */
	public static function entry_for( $token ) {
		if ( ! self::valid_token( $token ) ) {
			return new WP_Error( 'ab_mcp_undo_bad_token', __( 'That is not a valid undo token. wp_undo_list shows your undo points and their tokens.', 'alphabridge-mcp' ) );
		}
		return self::find_owned( $token );
	}

	/**
	 * How long a restore may hold an undo point before another request is
	 * allowed to try it. Long enough for a slow file write, short enough that a
	 * crashed request does not lock the point away for the rest of its life.
	 */
	const APPLY_TIMEOUT = 120;

	/**
	 * Reserve an undo point for a restore. Two parallel wp_undo calls on the
	 * same token would otherwise both read the same snapshot and both apply it,
	 * so the second one would overwrite whatever happened in between. Owner and
	 * expiry are re-checked inside the lock, not before it.
	 *
	 * @param string $token Token.
	 * @return true|WP_Error
	 */
	public static function begin_apply( $token ) {
		if ( ! self::valid_token( $token ) ) {
			return new WP_Error( 'ab_mcp_undo_bad_token', __( 'That is not a valid undo token. wp_undo_list shows your undo points and their tokens.', 'alphabridge-mcp' ) );
		}
		$uid    = (int) get_current_user_id();
		$nonce  = bin2hex( self::random_bytes( 8 ) );
		$result = new WP_Error( 'ab_mcp_undo_not_found', __( 'No undo point with that token for your account. wp_undo_list shows yours; an undo point belongs to the account that made the change.', 'alphabridge-mcp' ) );

		$locked = self::with_lock(
			function () use ( $token, $uid, $nonce, &$result ) {
				$index   = self::read_index();
				$changed = false;
				foreach ( $index as $i => $e ) {
					if ( $e['token'] !== $token ) {
						continue;
					}
					if ( (int) $e['user'] !== $uid ) {
						break;
					}
					$busy = isset( $e['applying'] ) ? (int) $e['applying'] : 0;
					if ( $busy && ( time() - $busy ) < self::APPLY_TIMEOUT ) {
						$result = new WP_Error( 'ab_mcp_undo_in_progress', __( 'This undo point is already being restored by another request. Wait a moment, then check with wp_undo_list whether it is still there.', 'alphabridge-mcp' ) );
						break;
					}
					$index[ $i ]['applying']    = time();
					$index[ $i ]['applying_by'] = $nonce;
					$changed                    = true;
					$result                     = $nonce;
					break;
				}
				if ( $changed ) {
					self::write_index( $index );
					// The reservation only exists once it is STORED. If the
					// write did not stick, the restore must not run believing
					// it holds a lock nobody else can see.
					wp_cache_delete( self::OPTION, 'options' );
					$stored = false;
					foreach ( self::read_index() as $e2 ) {
						if ( $e2['token'] === $token && isset( $e2['applying_by'] )
							&& hash_equals( (string) $e2['applying_by'], $nonce ) ) {
							$stored = true;
							break;
						}
					}
					if ( ! $stored ) {
						$result = new WP_Error( 'ab_mcp_undo_busy', __( 'The undo index is busy; try again in a moment.', 'alphabridge-mcp' ) );
					}
				}
			}
		);

		if ( ! $locked ) {
			return new WP_Error( 'ab_mcp_undo_busy', __( 'The undo index is busy; try again in a moment.', 'alphabridge-mcp' ) );
		}
		return $result;
	}

	/**
	 * Close out a reservation made by begin_apply() — either by CONSUMING the
	 * undo point (a complete restore: the entry and its snapshot are removed)
	 * or by RELEASING it (a partial or failed one: the entry stays, the
	 * reservation is cleared). One nonce-checked operation under one lock, so
	 * release and consumption cannot race each other or a second holder: if a
	 * restore overran the 120s window and another request legitimately took the
	 * point over, the first holder's late call touches nothing and says so.
	 *
	 * @param string $token   Token.
	 * @param string $nonce   The value begin_apply() returned.
	 * @param bool   $consume Remove the point (true) or just release it.
	 * @return true|WP_Error true only when the requested outcome is STORED.
	 */
	public static function finish_apply( $token, $nonce, $consume ) {
		$result = true;
		$locked = self::with_lock(
			function () use ( $token, $nonce, $consume, &$result ) {
				$index = self::read_index();
				$found = null;
				foreach ( $index as $i => $e ) {
					if ( $e['token'] === $token ) {
						$found = $i;
						break;
					}
				}
				if ( null === $found ) {
					// Already gone — for a consume that IS the desired end
					// state, and a release of a vanished entry has nothing left
					// to do either.
					$result = true;
					return;
				}
				$e = $index[ $found ];
				if ( ! isset( $e['applying_by'] ) || ! hash_equals( (string) $e['applying_by'], (string) $nonce ) ) {
					$result = new WP_Error( 'ab_mcp_undo_taken_over', __( 'This undo point was taken over by another request; nothing was changed here. Check with wp_undo_list whether it is still there.', 'alphabridge-mcp' ) );
					return;
				}
				if ( $consume ) {
					self::payload_delete_entry( $e );
					unset( $index[ $found ] );
				} else {
					unset( $index[ $found ]['applying'], $index[ $found ]['applying_by'] );
				}
				self::write_index( array_values( $index ) );
				// Trust only what is stored: a consumption that did not stick
				// would let a later wp_undo re-apply the snapshot.
				wp_cache_delete( self::OPTION, 'options' );
				foreach ( self::read_index() as $e2 ) {
					if ( $e2['token'] !== $token ) {
						continue;
					}
					if ( $consume || isset( $e2['applying_by'] ) ) {
						$result = new WP_Error( 'ab_mcp_undo_busy', __( 'The undo index is busy; try again in a moment.', 'alphabridge-mcp' ) );
					}
					return;
				}
			}
		);
		if ( ! $locked ) {
			return new WP_Error( 'ab_mcp_undo_busy', __( 'The undo index is busy; try again in a moment.', 'alphabridge-mcp' ) );
		}
		return $result;
	}

	/**
	 * Does this holder still have its reservation? Renewed once half the
	 * window has passed. A restore asks before each item: one that outlives
	 * the window must not write on once another request has taken the point
	 * over (a retry after a client's timeout, say).
	 *
	 * @param string $token Token.
	 * @param string $nonce The value begin_apply() returned.
	 * @return bool
	 */
	public static function hold_apply( $token, $nonce ) {
		$mine = static function ( $e ) use ( $token, $nonce ) {
			return $e['token'] === $token && isset( $e['applying_by'] ) && hash_equals( (string) $e['applying_by'], (string) $nonce );
		};
		wp_cache_delete( self::OPTION, 'options' );
		foreach ( self::read_index() as $e ) {
			if ( ! $mine( $e ) ) {
				continue;
			}
			if ( time() - (int) $e['applying'] < (int) ( self::APPLY_TIMEOUT / 2 ) ) {
				return true;
			}
			$held   = false;
			$locked = self::with_lock(
				function () use ( $mine, &$held ) {
					// Read afresh under the lock: the copy read above may
					// be older than a takeover that came in between.
					wp_cache_delete( self::OPTION, 'options' );
					$index = self::read_index();
					foreach ( $index as $i => $x ) {
						if ( $mine( $x ) ) {
							$now                     = time();
							$index[ $i ]['applying'] = $now;
							self::write_index( $index );
							// Trust only what is stored: a renewal that did not
							// stick holds only while the old reservation runs.
							wp_cache_delete( self::OPTION, 'options' );
							foreach ( self::read_index() as $y ) {
								if ( $mine( $y ) ) {
									$held = (int) $y['applying'] >= $now || time() - (int) $y['applying'] < self::APPLY_TIMEOUT;
								}
							}
							return;
						}
					}
				}
			);
			// Busy: still this holder's only while the reservation read a
			// moment ago has not run out — before that, nobody may take it.
			return $held || ( ! $locked && time() - (int) $e['applying'] < self::APPLY_TIMEOUT );
		}
		return false;
	}

	/**
	 * Renew a held reservation so its 120s window counts from NOW. Used after a
	 * long restore whose consumption failed: the promise "the point stays
	 * blocked for two minutes" has to hold from the moment it is made, not
	 * from a begin_apply() that may be minutes in the past. Nonce-checked and
	 * best-effort — a failure just means the window is shorter.
	 *
	 * @param string $token Token.
	 * @param string $nonce The value begin_apply() returned.
	 */
	public static function extend_apply( $token, $nonce ) {
		self::with_lock(
			function () use ( $token, $nonce ) {
				$index = self::read_index();
				foreach ( $index as $i => $e ) {
					if ( $e['token'] === $token && isset( $e['applying_by'] )
						&& hash_equals( (string) $e['applying_by'], (string) $nonce ) ) {
						$index[ $i ]['applying'] = time();
						self::write_index( $index );
						return;
					}
				}
			}
		);
	}

	/**
	 * Release a reservation without consuming the point. Wrapper kept for
	 * readability at the call sites; see finish_apply().
	 *
	 * @param string $token Token.
	 * @param string $nonce The value begin_apply() returned.
	 */
	public static function end_apply( $token, $nonce ) {
		self::finish_apply( $token, $nonce, false );
	}

	/**
	 * Delete one undo point and its snapshot. Owner-checked.
	 *
	 * @param string $token Token.
	 * @return true|WP_Error
	 */
	public static function discard( $token ) {
		if ( ! self::valid_token( $token ) ) {
			return new WP_Error( 'ab_mcp_undo_bad_token', __( 'That is not a valid undo token. wp_undo_list shows your undo points and their tokens.', 'alphabridge-mcp' ) );
		}
		// Ownership, the reservation check and the removal happen under ONE
		// lock. Checked-then-removed in two steps, a begin_apply() slipping in
		// between would see its freshly reserved point deleted from under it.
		$uid    = (int) get_current_user_id();
		$result = new WP_Error( 'ab_mcp_undo_not_found', __( 'No undo point with that token for your account. wp_undo_list shows yours; an undo point belongs to the account that made the change.', 'alphabridge-mcp' ) );
		$locked = self::with_lock(
			function () use ( $token, $uid, &$result ) {
				$index = self::read_index();
				$found = null;
				foreach ( $index as $i => $e ) {
					if ( $e['token'] === $token ) {
						if ( (int) $e['user'] !== $uid ) {
							return; // not yours: same answer as not found.
						}
						$found = $i;
						break;
					}
				}
				if ( null === $found ) {
					return;
				}
				$e    = $index[ $found ];
				$busy = isset( $e['applying'] ) ? (int) $e['applying'] : 0;
				if ( $busy && ( time() - $busy ) < self::APPLY_TIMEOUT ) {
					// A point being restored RIGHT NOW is not up for discarding —
					// the holder decides its fate through finish_apply().
					$result = new WP_Error( 'ab_mcp_undo_in_progress', __( 'This undo point is being restored right now; try again in a moment.', 'alphabridge-mcp' ) );
					return;
				}
				self::payload_delete_entry( $e );
				unset( $index[ $found ] );
				self::write_index( array_values( $index ) );
				// Gone from STORAGE, or it did not happen.
				wp_cache_delete( self::OPTION, 'options' );
				foreach ( self::read_index() as $e2 ) {
					if ( $e2['token'] === $token ) {
						$result = new WP_Error( 'ab_mcp_undo_busy', __( 'The undo index is busy; try again in a moment.', 'alphabridge-mcp' ) );
						return;
					}
				}
				$result = true;
			}
		);
		if ( ! $locked ) {
			return new WP_Error( 'ab_mcp_undo_busy', __( 'The undo index is busy; try again in a moment.', 'alphabridge-mcp' ) );
		}
		return $result;
	}

	/**
	 * Drop the file undo points of one plugin or theme folder, of every
	 * account: after an update of that plugin or theme was kept they would
	 * write a file of the previous version into the new one. A point that is
	 * being restored right now stays, as with discard().
	 *
	 * The points are those of Pro's file tools, in the store this plugin
	 * shares with Pro; wp_update_plugin and wp_update_theme (free since
	 * 4.11.0, AB_MCP_Update_Guard) drop them after a kept update, as Pro's
	 * update guard did.
	 *
	 * @param string $jail plugin|theme.
	 * @param string $slug The folder.
	 * @return string[] What the dropped points were about ("folder/file").
	 */
	public static function discard_files_of( $jail, $slug ) {
		$tools = ( 'theme' === $jail )
			? array( 'wp_write_theme_file', 'wp_delete_theme_file' )
			: array( 'wp_write_plugin_file', 'wp_delete_plugin_file' );
		$slug  = (string) $slug;
		$out   = array();
		if ( '' === $slug ) {
			return $out;
		}
		self::with_lock(
			function () use ( $tools, $slug, &$out ) {
				$keep = array();
				foreach ( self::read_index() as $e ) {
					$busy = isset( $e['applying'] ) ? (int) $e['applying'] : 0;
					if ( 'file' === $e['kind'] && in_array( $e['tool'], $tools, true ) && 0 === strpos( (string) $e['summary'], $slug . '/' )
						&& ! ( $busy && ( time() - $busy ) < self::APPLY_TIMEOUT ) ) {
						self::payload_delete_entry( $e );
						$out[] = (string) $e['summary'];
						continue;
					}
					$keep[] = $e;
				}
				if ( $out ) {
					self::write_index( $keep );
				}
			}
		);
		return $out;
	}

	/**
	 * Remove expired points (and their files). Safe to call often.
	 *
	 * @return int Number removed.
	 */
	public static function gc() {
		$removed = 0;
		$ttl     = self::ttl();
		self::with_lock(
			function () use ( &$removed, $ttl ) {
				$index = self::read_index();
				$keep  = array();
				$now   = time();
				foreach ( $index as $e ) {
					if ( $now > self::expires_of( $e, $ttl ) ) {
						self::payload_delete_entry( $e );
						$removed++;
						continue;
					}
					$keep[] = $e;
				}
				self::write_index( $keep );
				$live = array();
				foreach ( $keep as $e ) {
					foreach ( self::entry_payloads( $e ) as $name ) {
						$live[ $name ] = true;
					}
				}
				$removed += self::sweep_orphans( $live );
			}
		);
		return $removed;
	}

	/**
	 * Delete snapshot files that no index entry points at any more. They are the
	 * residue of a crash between writing the file and committing the index, and
	 * nothing else would ever remove them. Only files older than the TTL, and
	 * never older than a day, are touched, so a snapshot being written by a
	 * concurrent request is safe. The day cap keeps the sweep where it was
	 * before the window grew to days: an orphan is a copy of site content that
	 * no undo point can reach, and a window of days is no reason to keep it.
	 *
	 * @param array $live Map of payload basenames still referenced.
	 * @return int Number deleted.
	 */
	private static function sweep_orphans( array $live ) {
		$dir = self::dir();
		if ( is_wp_error( $dir ) ) {
			return 0;
		}
		$cutoff  = time() - min( self::ttl(), DAY_IN_SECONDS );
		$removed = 0;
		foreach ( (array) glob( trailingslashit( $dir ) . '*.bin' ) as $path ) {
			$name = basename( $path );
			if ( isset( $live[ $name ] ) || ! preg_match( '/^[0-9a-f]{40}\.bin$/', $name ) ) {
				continue;
			}
			$mtime = @filemtime( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( false === $mtime || $mtime > $cutoff ) {
				continue;
			}
			if ( @unlink( $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				$removed++;
			}
		}
		return $removed;
	}

	/**
	 * Delete every undo point belonging to the current user. Other users' points
	 * are left alone — an undo point is personal, and wp_undo_discard is a tool
	 * call, not an admin-wide reset.
	 *
	 * @param callable|null $allow Optional predicate receiving the entry; return
	 *                             false to keep it. Used to honour token scope.
	 * @return int Number removed.
	 */
	public static function clear_for_user( $allow = null ) {
		$uid     = (int) get_current_user_id();
		$removed = 0;
		$locked  = self::with_lock(
			function () use ( &$removed, $uid, $allow ) {
				$index = self::read_index();
				$keep  = array();
				foreach ( $index as $e ) {
					$mine = ( (int) $e['user'] === $uid );
					// A point being restored right now is not ours to delete
					// out from under that request.
					$busy = isset( $e['applying'] ) && ( time() - (int) $e['applying'] ) < self::APPLY_TIMEOUT;
					if ( $mine && ! $busy && ( null === $allow || call_user_func( $allow, $e ) ) ) {
						self::payload_delete_entry( $e );
						$removed++;
						continue;
					}
					$keep[] = $e;
				}
				self::write_index( $keep );
			}
		);
		if ( ! $locked ) {
			return new WP_Error( 'ab_mcp_undo_busy', __( 'The undo index is busy; try again in a moment.', 'alphabridge-mcp' ) );
		}
		return $removed;
	}

	/**
	 * Delete every undo point and its files (admin "clear" button).
	 *
	 * @return int Number removed.
	 */
	public static function clear_all() {
		$removed = 0;
		self::with_lock(
			function () use ( &$removed ) {
				$index = self::read_index();
				foreach ( $index as $e ) {
					self::payload_delete_entry( $e );
					$removed++;
				}
				self::write_index( array() );
			}
		);
		return $removed;
	}

	/**
	 * The autoload flag of an option as stored right now ('yes'/'no'), read
	 * before a write so a restore does not silently flip a no-autoload option to
	 * yes and slow the site down. Returns null if the option does not exist.
	 *
	 * @param string $name Option name.
	 * @return string|null
	 */
	public static function option_autoload( $name ) {
		$row = self::option_row( $name );
		// The two-value answer everything on the restore side wants: the flag,
		// or null for "not there". A lookup that could not run reads as "not
		// there" here, which is what that side has always assumed; only the
		// recorder has to tell the two apart, and it uses option_row directly.
		return is_array( $row ) ? $row['autoload'] : null;
	}

	/**
	 * Look the option row up, with three answers that have to stay apart: the
	 * row, "there is no such row", and "the question could not be asked". A
	 * failed query answers exactly like an absent row, and a recorder turns
	 * absent into existed=false while a restore turns existed=false into a
	 * DELETE — so the difference is the difference between an undo point and
	 * losing an option.
	 *
	 * It answers with the name the DATABASE matched, which is not always the
	 * name it was asked with: option_name is compared with a collation, and
	 * collations fold — some inside plain ASCII, MySQL's Classical Latin
	 * equating i with j and u with v. So "sjteurl" finds the siteurl row, and
	 * only the database can say so. Everything downstream must mean THAT row.
	 *
	 * query() and last_result, not get_row() and not get_var():
	 *  - get_var() reports an EMPTY column as null, and autoload is only NOT
	 *    NULL, not non-empty, so one malformed row would read as no row at all.
	 *  - get_row() throws away the one signal that says whether the query RAN.
	 *    A SELECT that ran answers with its row count, 0 included; false means
	 *    it was never made — and a connection that went away and could not be
	 *    re-established returns false with last_error still EMPTY, because
	 *    query() returns before last_error is ever assigned.
	 *
	 * @param string $name Option name.
	 * @return array|false|null array( 'name', 'autoload' ) when the row is
	 *                          there, false when it is not, null when the
	 *                          lookup could not run.
	 */
	public static function option_row( $name ) {
		global $wpdb;
		$ran = $wpdb->query( $wpdb->prepare( "SELECT option_name, autoload FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $name ) ); // phpcs:ignore WordPress.DB
		if ( false === $ran || '' !== (string) $wpdb->last_error ) {
			return null;
		}
		$rows = $wpdb->last_result;
		if ( ! is_array( $rows ) || ! isset( $rows[0] ) || ! is_object( $rows[0] ) ) {
			return false;
		}
		$flag = self::normalize_autoload( isset( $rows[0]->autoload ) ? (string) $rows[0]->autoload : '' );
		return array(
			'name' => isset( $rows[0]->option_name ) ? (string) $rows[0]->option_name : (string) $name,
			// A flag we cannot read is still a row. Core only autoloads the
			// values it knows, so anything else is simply not autoloaded.
			'autoload' => ( null === $flag ) ? 'no' : $flag,
		);
	}

	/**
	 * The option half of a snapshot, decided in one place because getting it
	 * wrong is destructive: 'existed' is what a restore reads as "delete it
	 * again". When the row cannot be looked up at all this returns null and the
	 * caller records NOTHING — no undo point is better than one that would
	 * remove an option the site still has.
	 *
	 * @param string $name Option name.
	 * @return array|null Snapshot data, or null when no honest one can be made.
	 */
	public static function option_snapshot( $name ) {
		$row = self::option_row( $name );
		if ( null === $row ) {
			return null;
		}
		$there = is_array( $row );
		// The snapshot is of the row the database matched, not of the name we
		// were handed, or an undo would address something else than the write.
		$real = $there ? $row['name'] : (string) $name;
		return array(
			'name'     => $real,
			'value'    => $there ? get_option( $real ) : null,
			'autoload' => $there ? $row['autoload'] : null,
			'existed'  => $there,
		);
	}

	/**
	 * WordPress 6.6 widened the autoload column: besides 'yes' and 'no' it now
	 * writes 'on', 'off', 'auto', 'auto-on' and 'auto-off'. Everything here
	 * speaks the two-value language, so translate once, at the edge, into the
	 * only thing a restore has to get right — is it autoloaded or not. Core
	 * answers that itself where it can; the list is the fallback for older
	 * versions, where only 'yes' and 'no' exist anyway.
	 *
	 * Anything unrecognised comes back as null so a hand-edited snapshot flag
	 * stays refusable instead of being quietly read as "not autoloaded".
	 *
	 * @param mixed $raw Raw column value, or a flag out of a stored snapshot.
	 * @return string|null 'yes', 'no', or null when it is not a flag at all.
	 */
	public static function normalize_autoload( $raw ) {
		if ( ! is_string( $raw ) || '' === $raw ) {
			return null;
		}
		// The vocabulary is fixed — these are the spellings the column can hold.
		// WHICH of them count as autoloaded is a site decision, and core's list
		// is filterable, so the two questions are asked separately: a spelling a
		// site filtered out is still a valid flag that simply does not autoload,
		// not a corrupt snapshot.
		if ( ! in_array( $raw, array( 'yes', 'no', 'on', 'off', 'auto', 'auto-on', 'auto-off' ), true ) ) {
			return null;
		}
		$on = function_exists( 'wp_autoload_values_to_autoload' )
			? (array) wp_autoload_values_to_autoload()
			: array( 'yes', 'on', 'auto', 'auto-on' );
		return in_array( $raw, $on, true ) ? 'yes' : 'no';
	}

	/**
	 * Scalar, or a (nested) array of scalars. Objects and resources are refused
	 * so a snapshot is always safe to serialise and to hand back.
	 *
	 * @param mixed $value Value.
	 * @param int   $depth Guard.
	 * @return bool
	 */
	public static function is_safe_value( $value, $depth = 0 ) {
		if ( $depth > 20 ) {
			return false;
		}
		if ( is_scalar( $value ) || null === $value ) {
			return true;
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $v ) {
				if ( ! self::is_safe_value( $v, $depth + 1 ) ) {
					return false;
				}
			}
			return true;
		}
		return false;
	}

	/* ------------------------------------------------------------- serialisation */

	/**
	 * Serialise a snapshot for storage.
	 *
	 * Deliberately JSON, NOT serialize(): a stored snapshot is read back later
	 * and PHP's unserialize() can instantiate objects, which turns any write
	 * access to the index option or the snapshot directory into object
	 * injection. JSON cannot construct anything. is_safe_value() already limits
	 * snapshots to scalars and arrays, so nothing is lost.
	 *
	 * Raw file bytes are not valid UTF-8 and would make json_encode fail, so a
	 * "bytes" field is base64-encoded and marked. If a value still cannot be
	 * encoded (binary inside an option value, say), this returns null and the
	 * caller records no undo point — fail closed rather than store something
	 * that cannot be read back.
	 *
	 * @param mixed $data Snapshot.
	 * @return string|null JSON envelope, or null if it cannot be encoded.
	 */
	public static function encode( $data ) {
		$envelope = array(
			'v'    => 1,
			'b64'  => false,
			'data' => $data,
		);
		if ( is_array( $data ) && isset( $data['bytes'] ) && is_string( $data['bytes'] ) ) {
			$copy          = $data;
			$copy['bytes'] = base64_encode( $data['bytes'] ); // phpcs:ignore
			$envelope['b64']  = true;
			$envelope['data'] = $copy;
		}
		// JSON_PRESERVE_ZERO_FRACTION, or a float of 5.0 encodes as "5" and comes
		// back an integer. WordPress serialises arrays with their types, so
		// a:1:{i:0;d:5;} would be restored as a:1:{i:0;i:5;} — a different row,
		// silently, because the snapshot itself was already wrong.
		$json = wp_json_encode( $envelope, JSON_PRESERVE_ZERO_FRACTION );
		return ( false === $json || null === $json ) ? null : $json;
	}

	/**
	 * Read a stored snapshot back. Returns null on anything unexpected.
	 *
	 * @param string $blob JSON envelope.
	 * @return mixed|null
	 */
	public static function decode( $blob ) {
		$envelope = json_decode( (string) $blob, true );
		if ( ! is_array( $envelope ) || ! array_key_exists( 'data', $envelope ) || ! isset( $envelope['v'] ) ) {
			return null;
		}
		if ( 1 !== (int) $envelope['v'] ) {
			return null;
		}
		$data = $envelope['data'];
		if ( ! empty( $envelope['b64'] ) && is_array( $data ) && isset( $data['bytes'] ) && is_string( $data['bytes'] ) ) {
			$raw = base64_decode( $data['bytes'], true ); // phpcs:ignore
			if ( false === $raw ) {
				return null;
			}
			$data['bytes'] = $raw;
		}
		return $data;
	}

	/**
	 * Does an index entry have every field, with a plausible type? A malformed
	 * entry (hand-edited option, partial write) must not reach the restore path.
	 *
	 * @param mixed $e Candidate entry.
	 * @return bool
	 */
	public static function valid_entry( $e ) {
		if ( ! is_array( $e ) ) {
			return false;
		}
		foreach ( array( 'token', 'tool', 'cap', 'kind', 'summary', 'items', 'bytes', 'user', 'created_at', 'expires_at' ) as $k ) {
			if ( ! array_key_exists( $k, $e ) ) {
				return false;
			}
		}
		if ( ! self::valid_token( $e['token'] ) ) {
			return false;
		}
		foreach ( array( 'items', 'bytes', 'user', 'created_at', 'expires_at' ) as $k ) {
			if ( ! is_int( $e[ $k ] ) && ! ctype_digit( (string) $e[ $k ] ) ) {
				return false;
			}
		}
		// Points recorded before the stored size was kept have no "stored";
		// where it is there it decides what the total limit counts, so it has
		// to be a number like the others.
		if ( array_key_exists( 'stored', $e ) && ! is_int( $e['stored'] ) && ! ctype_digit( (string) $e['stored'] ) ) {
			return false;
		}
		foreach ( array( 'tool', 'cap', 'kind', 'summary' ) as $k ) {
			if ( ! is_string( $e[ $k ] ) ) {
				return false;
			}
		}
		// Exactly one storage location, and a payload name must look like one.
		$has_inline  = array_key_exists( 'inline', $e ) && null !== $e['inline'];
		$has_payload = array_key_exists( 'payload', $e ) && null !== $e['payload'] && '' !== $e['payload'];
		if ( $has_inline === $has_payload ) {
			return false;
		}
		if ( $has_payload && ! preg_match( '/^[0-9a-f]{40}\.bin$/', (string) $e['payload'] ) ) {
			return false;
		}
		// A file-backed entry MUST carry its hash. Treating a missing one as
		// "skip the check" would turn a tampered index into a way of feeding
		// arbitrary bytes to the restore.
		if ( $has_payload && ( ! isset( $e['sha256'] ) || ! preg_match( '/^[0-9a-f]{64}$/', (string) $e['sha256'] ) ) ) {
			return false;
		}
		if ( $has_inline && ! is_string( $e['inline'] ) ) {
			return false;
		}
		// Parts: each one a file with its hash, like the first. A part without
		// its hash would feed unchecked bytes to the restore, so the whole entry
		// goes.
		if ( array_key_exists( 'parts', $e ) ) {
			if ( ! is_array( $e['parts'] ) || ! $has_payload || count( $e['parts'] ) > self::MAX_PARTS || ! in_array( (string) $e['kind'], self::PART_KINDS, true ) ) {
				return false;
			}
			foreach ( $e['parts'] as $part ) {
				if ( ! is_array( $part ) || ! isset( $part['payload'], $part['sha256'], $part['bytes'], $part['stored'] )
					|| ! preg_match( '/^[0-9a-f]{40}\.bin$/', (string) $part['payload'] )
					|| ! preg_match( '/^[0-9a-f]{64}$/', (string) $part['sha256'] )
					|| ( ! is_int( $part['bytes'] ) && ! ctype_digit( (string) $part['bytes'] ) )
					|| ( ! is_int( $part['stored'] ) && ! ctype_digit( (string) $part['stored'] ) ) ) {
					return false;
				}
			}
		}
		// The state after the change, per post: loc => sha256.
		if ( array_key_exists( 'after', $e ) ) {
			if ( ! is_array( $e['after'] ) ) {
				return false;
			}
			foreach ( $e['after'] as $post_id => $hashes ) {
				if ( ! ctype_digit( (string) $post_id ) || ! is_array( $hashes ) ) {
					return false;
				}
				foreach ( $hashes as $loc => $sha ) {
					if ( ! is_string( $loc ) || '' === $loc || ! is_string( $sha ) || ! preg_match( '/^[0-9a-f]{64}$/', $sha ) ) {
						return false;
					}
				}
			}
		}
		return true;
	}

	/* --------------------------------------------------------------- internals */

	/**
	 * The protected uploads subfolder for snapshot files. index.php + a
	 * "deny" .htaccess, same as the transfer sessions. On nginx .htaccess is
	 * ignored, which is why the FILENAME is unguessable rather than the token.
	 *
	 * @return string|WP_Error
	 */
	public static function dir() {
		$up  = wp_upload_dir();
		if ( ! empty( $up['error'] ) ) {
			return new WP_Error( 'ab_mcp_undo_uploads', __( 'The uploads directory is not available. The uploads folder must exist and be writable; the host can check it.', 'alphabridge-mcp' ) );
		}
		$dir = trailingslashit( $up['basedir'] ) . self::SUBDIR;
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'ab_mcp_undo_dir', __( 'Could not create the undo directory. The uploads folder must be writable; the host can check it.', 'alphabridge-mcp' ) );
		}
		if ( ! file_exists( $dir . '/index.php' ) ) {
			file_put_contents( $dir . '/index.php', "<?php // Silence is golden.\n" ); // phpcs:ignore
		}
		if ( ! file_exists( $dir . '/.htaccess' ) ) {
			file_put_contents( $dir . '/.htaccess', "Require all denied\n" ); // phpcs:ignore
		}
		return $dir;
	}

	/**
	 * File name for a token's snapshot. Derived from the token AND wp_salt, so a
	 * token seen in a chat log does not name a fetchable path.
	 *
	 * @param string $token Token.
	 * @return string Basename (no directory).
	 */
	private static function payload_name( $token ) {
		return sha1( $token . '|' . wp_salt( 'auth' ) ) . '.bin';
	}

	/**
	 * The bytes a payload file will hold: gzip-compressed where zlib is
	 * available, the plain payload otherwise or when compression fails.
	 * payload_read() tells the two apart by the gzip magic bytes.
	 *
	 * @param string $payload Serialized snapshot.
	 * @return string
	 */
	private static function compress( $payload ) {
		if ( ! function_exists( 'gzencode' ) ) {
			return $payload;
		}
		$blob = gzencode( $payload, 6 );
		return ( false === $blob ) ? $payload : $blob;
	}

	/**
	 * Write a snapshot to its file, gzip-compressed when available.
	 *
	 * @param string      $token   Token.
	 * @param string      $payload Serialized snapshot.
	 * @param string|null $blob    compress( $payload ) when the caller already
	 *                             measured it; compressed here otherwise.
	 * @return array|WP_Error { file, sha256 }.
	 */
	private static function payload_write( $token, $payload, $blob = null ) {
		$dir = self::dir();
		if ( is_wp_error( $dir ) ) {
			return $dir;
		}
		$name = self::payload_name( $token );
		if ( ! is_string( $blob ) ) {
			$blob = self::compress( $payload );
		}
		$path = trailingslashit( $dir ) . $name;
		// Write to a sibling temp file and rename into place: an interrupted
		// write must never leave a half-written snapshot that later reads as a
		// valid-looking file. rename() within one directory is atomic.
		$tmp = $path . '.' . bin2hex( self::random_bytes( 4 ) ) . '.tmp';
		if ( false === file_put_contents( $tmp, $blob ) ) { // phpcs:ignore
			return new WP_Error( 'ab_mcp_undo_write', __( 'Could not store the undo snapshot. The uploads folder must be writable and have free space; the host can check it.', 'alphabridge-mcp' ) );
		}
		if ( ! @rename( $tmp, $path ) ) { // phpcs:ignore
			@unlink( $tmp ); // phpcs:ignore
			return new WP_Error( 'ab_mcp_undo_write', __( 'Could not store the undo snapshot. The uploads folder must be writable and have free space; the host can check it.', 'alphabridge-mcp' ) );
		}
		// sha256 over the RAW (uncompressed) payload, so a corrupted or truncated
		// file is caught on read regardless of compression.
		return array(
			'file'   => $name,
			'sha256' => hash( 'sha256', $payload ),
		);
	}

	/**
	 * Read a snapshot back and verify it against its sha256.
	 *
	 * @param string $name Basename.
	 * @param string $sha  Expected sha256 of the raw payload.
	 * @return string|WP_Error Serialized snapshot.
	 */
	private static function payload_read( $name, $sha ) {
		$dir = self::dir();
		if ( is_wp_error( $dir ) ) {
			return $dir;
		}
		// Guard the basename: it must be exactly what payload_name() produces.
		if ( ! preg_match( '/^[0-9a-f]{40}\.bin$/', (string) $name ) ) {
			return new WP_Error( 'ab_mcp_undo_badfile', __( 'The undo snapshot reference is invalid. This undo point cannot be used; wp_undo_discard removes it.', 'alphabridge-mcp' ) );
		}
		$path = trailingslashit( $dir ) . $name;
		if ( ! is_file( $path ) ) {
			return new WP_Error( 'ab_mcp_undo_missing', __( 'The undo snapshot file is missing. This undo point cannot be used; wp_undo_discard removes it.', 'alphabridge-mcp' ) );
		}
		// Check the size on disk BEFORE reading: a tampered file must not be
		// pulled into memory first and rejected afterwards. What record()
		// writes is never larger than the stored limit; the margin covers
		// files written before the limit was measured after compression, when
		// an incompressible snapshot could exceed it by the gzip overhead.
		$on_disk = @filesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $on_disk || $on_disk > ( self::snapshot_max() + 64 * KB_IN_BYTES ) ) {
			return new WP_Error( 'ab_mcp_undo_corrupt', __( 'The undo snapshot file has an implausible size. This undo point cannot be used; wp_undo_discard removes it.', 'alphabridge-mcp' ) );
		}
		$blob = file_get_contents( $path ); // phpcs:ignore
		if ( false === $blob ) {
			return new WP_Error( 'ab_mcp_undo_read', __( 'Could not read the undo snapshot. Try again; if it keeps failing, wp_undo_discard removes this undo point.', 'alphabridge-mcp' ) );
		}
		$raw     = $blob;
		$raw_max = self::raw_max();
		if ( function_exists( 'gzdecode' ) && "\x1f\x8b" === substr( $blob, 0, 2 ) ) {
			// The length cap is passed INTO gzdecode: a crafted stream that
			// would expand far beyond it fails right here instead of being
			// inflated in full and measured afterwards. One byte over the cap,
			// so "exactly at the cap" and "past it" stay apart — without
			// running past PHP_INT_MAX when a filter sets the cap that high.
			$decoded = @gzdecode( $blob, ( $raw_max < PHP_INT_MAX ) ? $raw_max + 1 : PHP_INT_MAX ); // phpcs:ignore
			if ( false !== $decoded ) {
				$raw = $decoded;
			}
		}
		// A snapshot can never legitimately be larger than the uncompressed
		// cap that was applied when it was written. Anything bigger did not
		// come from here, and a compressed file that expands past it is a
		// decompression bomb.
		if ( strlen( $raw ) > $raw_max ) {
			return new WP_Error( 'ab_mcp_undo_corrupt', __( 'The undo snapshot is larger than any snapshot this plugin writes. This undo point cannot be used; wp_undo_discard removes it.', 'alphabridge-mcp' ) );
		}
		// The hash is not optional. An entry without one never passes
		// valid_entry(), so reaching here without a hash means the caller
		// bypassed the index — refuse rather than trust the bytes.
		if ( ! preg_match( '/^[0-9a-f]{64}$/', (string) $sha ) || ! hash_equals( (string) $sha, hash( 'sha256', $raw ) ) ) {
			return new WP_Error( 'ab_mcp_undo_corrupt', __( 'The undo snapshot failed its integrity check. This undo point cannot be used; wp_undo_discard removes it.', 'alphabridge-mcp' ) );
		}
		return $raw;
	}

	/**
	 * Delete a snapshot file if there is one.
	 *
	 * @param string|null $name Basename or null.
	 */
	private static function payload_delete( $name ) {
		if ( empty( $name ) || ! preg_match( '/^[0-9a-f]{40}\.bin$/', (string) $name ) ) {
			return;
		}
		$dir = self::dir();
		if ( is_wp_error( $dir ) ) {
			return;
		}
		$path = trailingslashit( $dir ) . $name;
		if ( is_file( $path ) ) {
			@unlink( $path ); // phpcs:ignore
		}
	}

	/**
	 * The snapshot files of one entry: its own and those of its parts.
	 *
	 * @param array $e Index entry.
	 * @return string[] Basenames.
	 */
	private static function entry_payloads( array $e ) {
		$out = array();
		if ( ! empty( $e['payload'] ) ) {
			$out[] = (string) $e['payload'];
		}
		if ( isset( $e['parts'] ) && is_array( $e['parts'] ) ) {
			$out = array_merge( $out, self::part_payloads( $e['parts'] ) );
		}
		return $out;
	}

	/**
	 * The file names of a list of parts.
	 *
	 * @param array $parts Parts of an entry.
	 * @return string[]
	 */
	private static function part_payloads( array $parts ) {
		$out = array();
		foreach ( $parts as $part ) {
			if ( is_array( $part ) && ! empty( $part['payload'] ) ) {
				$out[] = (string) $part['payload'];
			}
		}
		return $out;
	}

	/**
	 * Delete every snapshot file of an entry, its parts' included. One place,
	 * so no clean-up path can forget a part and leave a copy of site content
	 * behind that no undo point reaches.
	 *
	 * @param array $e Index entry.
	 */
	private static function payload_delete_entry( array $e ) {
		foreach ( self::entry_payloads( $e ) as $name ) {
			self::payload_delete( $name );
		}
	}

	/**
	 * Find an entry that belongs to the current user, or a WP_Error. A token for
	 * another user's entry reports the same "not found" as a missing token, so
	 * the index cannot be probed.
	 *
	 * @param string $token Token.
	 * @return array|WP_Error
	 */
	private static function find_owned( $token ) {
		$uid = (int) get_current_user_id();
		foreach ( self::read_index() as $e ) {
			if ( $e['token'] === $token ) {
				if ( (int) $e['user'] !== $uid ) {
					break;
				}
				return $e;
			}
		}
		return new WP_Error( 'ab_mcp_undo_not_found', __( 'No undo point with that token for your account. wp_undo_list shows yours; an undo point belongs to the account that made the change.', 'alphabridge-mcp' ) );
	}

	/**
	 * Remove one entry and its file (owner already checked by the caller).
	 *
	 * @param string $token Token.
	 */
	private static function remove( $token ) {
		$gone = false;
		$ok   = self::with_lock(
			function () use ( $token, &$gone ) {
				$index = self::read_index();
				$keep  = array();
				foreach ( $index as $e ) {
					if ( $e['token'] === $token ) {
						self::payload_delete_entry( $e );
						continue;
					}
					$keep[] = $e;
				}
				self::write_index( $keep );
				// Success means the entry is GONE FROM STORAGE, not that the
				// code ran: a write that did not stick would leave the point
				// applicable while the caller reports it consumed.
				wp_cache_delete( self::OPTION, 'options' );
				$gone = true;
				foreach ( self::read_index() as $e2 ) {
					if ( $e2['token'] === $token ) {
						$gone = false;
						break;
					}
				}
			}
		);
		return $ok && $gone;
	}

	/**
	 * Which entries are past the undo window or beyond the bounds (count,
	 * then total size, oldest first in both) and which stay, without deleting
	 * anything. Recording a point deletes the dropped ones once the index
	 * without them is stored; entries_for() only leaves them out.
	 *
	 * @param array $index Index, oldest first.
	 * @return array{live: array, dropped: array}
	 */
	private static function select_live( array $index ) {
		$now     = time();
		$ttl     = self::ttl();
		$live    = array();
		$dropped = array();
		foreach ( $index as $e ) {
			if ( $now > self::expires_of( $e, $ttl ) ) {
				$dropped[] = $e;
				continue;
			}
			$live[] = $e;
		}
		$max = self::max_entries();
		if ( count( $live ) > $max ) {
			$dropped = array_merge( $dropped, array_slice( $live, 0, count( $live ) - $max ) );
			$live    = array_slice( $live, -$max );
		}
		// The count alone does not bound the bytes once a filter raises the
		// count or the per-point limit. record_result() refuses a point larger
		// than this cap, so the newest point is never the one that goes.
		$cap   = self::max_total_bytes();
		$total = 0;
		foreach ( $live as $e ) {
			$total += self::stored_size( $e );
		}
		while ( $total > $cap && ! empty( $live ) ) {
			$e         = array_shift( $live );
			$total    -= self::stored_size( $e );
			$dropped[] = $e;
		}
		return array(
			'live'    => $live,
			'dropped' => $dropped,
		);
	}

	/**
	 * What one point takes as stored. Points recorded before this was kept
	 * count with their size before compression.
	 *
	 * @param array $e Index entry.
	 * @return int
	 */
	private static function stored_size( array $e ) {
		if ( isset( $e['stored'] ) ) {
			return max( 0, (int) $e['stored'] );
		}
		if ( isset( $e['inline'] ) && is_string( $e['inline'] ) ) {
			return strlen( $e['inline'] );
		}
		return max( 0, (int) $e['bytes'] );
	}

	/**
	 * What the store holds right now, every user's points together: how many
	 * points and how many bytes they take as stored. Metadata only — for the
	 * settings screen, which shows the use next to the limits. An expired
	 * point counts until a clean-up has removed it: its copy of the content is
	 * still on disk or in the database, and with undo switched off nothing
	 * else would show that it is.
	 *
	 * @return array { points, bytes }
	 */
	public static function usage() {
		$points = 0;
		$bytes  = 0;
		foreach ( self::read_index() as $e ) {
			$points++;
			$bytes += self::stored_size( $e );
		}
		return array(
			'points' => $points,
			'bytes'  => $bytes,
		);
	}

	/**
	 * @return array
	 */
	private static function read_index() {
		$index = get_option( self::OPTION, array() );
		if ( ! is_array( $index ) ) {
			return array();
		}
		// Drop anything malformed rather than letting it reach the restore path.
		$clean = array();
		foreach ( $index as $e ) {
			if ( self::valid_entry( $e ) ) {
				$clean[] = $e;
			}
		}
		return $clean;
	}

	/**
	 * @param array $index Index.
	 */
	private static function write_index( array $index ) {
		update_option( self::OPTION, $index, false );
	}

	/**
	 * Mutex around the index so two concurrent recordings do not lose each
	 * other. add_option is an INSERT that fails when the row exists, which is
	 * atomic; a stale lock is stolen with a compare-and-swap so two requests
	 * cannot both think they took it.
	 *
	 * Returns false WITHOUT running the critical section when the lock could
	 * not be acquired. Callers must treat that as "did not happen" — running
	 * unlocked would silently drop whatever another request wrote.
	 *
	 * @param callable $fn Critical section.
	 * @return bool Whether the section ran.
	 */
	private static function with_lock( $fn ) {
		global $wpdb;

		$held = false;
		for ( $i = 0; $i < 25; $i++ ) {
			// add_option is the atomic primitive here: the options table has a
			// unique key on option_name, so exactly one concurrent request can
			// win this.
			if ( add_option( self::LOCK, (string) time(), '', false ) ) {
				$held = true;
				break;
			}
			$since = (int) get_option( self::LOCK, 0 );
			if ( $since && ( time() - $since ) > 10 ) {
				// Stale lock from a crashed request. Steal it with a
				// compare-and-swap, not update_option: two requests noticing the
				// same stale lock in the same moment would otherwise BOTH
				// believe they hold it.
				$stolen = 0;
				if ( isset( $wpdb ) && ! empty( $wpdb->options ) ) {
					$stolen = (int) $wpdb->update(
						$wpdb->options,
						array( 'option_value' => (string) time() ),
						array(
							'option_name'  => self::LOCK,
							'option_value' => (string) $since,
						)
					);
					wp_cache_delete( self::LOCK, 'options' );
				}
				if ( 1 === $stolen ) {
					$held = true;
					break;
				}
			}
			usleep( 20000 );
		}

		// Running the critical section without the lock would silently lose
		// concurrent index updates. Refusing is the honest outcome: for a
		// recording that means "no undo point", which is already a normal
		// result, and for the reading paths it means an empty answer rather
		// than a corrupted index.
		if ( ! $held ) {
			return false;
		}

		try {
			$fn();
		} finally {
			delete_option( self::LOCK );
		}
		return true;
	}

	/**
	 * @param int $n Bytes.
	 * @return string
	 */
	private static function random_bytes( $n ) {
		if ( function_exists( 'random_bytes' ) ) {
			try {
				return random_bytes( $n );
			} catch ( \Exception $e ) {
				// fall through
			}
		}
		$out = '';
		for ( $i = 0; $i < $n; $i++ ) {
			$out .= chr( wp_rand( 0, 255 ) );
		}
		return $out;
	}

	/**
	 * @param string $s   String.
	 * @param int    $max Max length.
	 * @return string
	 */
	private static function clip( $s, $max ) {
		return strlen( $s ) > $max ? substr( $s, 0, $max ) : $s;
	}
}

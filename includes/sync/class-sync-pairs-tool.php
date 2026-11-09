<?php
/**
 * wp_site_pairs on the production site (Z8, decisions 13 and 15 of the plan
 * of 01.11.2026; branch 6, part 1): propose object pairs from the inventory
 * of the staging site, confirm or discard them, list them.
 *
 * - propose and list run at once (the `when` of the confirmation,
 *   AB_MCP_Tools_Staging::pairs_when(), answers a strict false for them
 *   only): propose reads (AB_MCP_Sync_Proposal) and writes
 *   only its proposal into the protected store, and the canon change of
 *   active pairs (decision 10); list reads the pairs.
 * - confirm and discard run only with the token of the confirmation in the
 *   same call (AB_MCP_Confirm_Token, decision 15): the first call answers
 *   with a preview and a token, the same arguments with the token run it.
 *   The state the token is bound to is the SHA-256 of the plan: the
 *   proposal, every pair to make or align anew with its bases, the snapshot
 *   of every pair touched as it is now (none where a ref has no pair), the
 *   print of the pair of sites and the connection. A change of any of them,
 *   another account or another connection refuses the token. A confirmation
 *   repeated after it ran meets the pairs it made and gets a new preview,
 *   which names them as paired meanwhile (the replay of decision 15).
 * - Ownership: a proposal goes on and is confirmed only by the account and
 *   connection that made it (AB_MCP_Sync_Proposal::find()).
 *
 * One confirmation makes or aligns at most MAX pairs and does all or
 * nothing: a pair it cannot write ends it, and what it wrote before is
 * taken back (discarded, or put back to its snapshot); what cannot be taken
 * back is named.
 *
 * Messages are English without gettext, as the other classes of the
 * takeover; each names the way.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-sync-proposal.php';

/**
 * Class AB_MCP_Sync_Pairs_Tool
 */
final class AB_MCP_Sync_Pairs_Tool {

	/** The actions of wp_site_pairs. */
	const ACTIONS = array( 'propose', 'confirm', 'discard', 'list' );

	/** The categories confirm takes by name. */
	const BY_CATEGORY = array( 'equal', 'differs', 'realign' );

	/** Pairs one confirmation makes, aligns or discards at most. */
	const MAX = 500;

	/** Operations an answer names at most (all are counted). */
	const NAMED = 200;

	/* ------------------------------------------------- the confirmation */

	/**
	 * The state a token of confirm or discard is bound to.
	 *
	 * @param array $a Arguments.
	 * @return string|WP_Error
	 */
	public static function state( array $a ) {
		$plan = self::plan( $a );
		return is_wp_error( $plan ) ? $plan : hash( 'sha256', AB_MCP_Sync_Canon::encode( $plan['state'] ) );
	}

	/**
	 * What confirm or discard would do.
	 *
	 * @param array $a Arguments.
	 * @return array|WP_Error
	 */
	public static function preview( array $a ) {
		$plan = self::plan( $a );
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}
		$ops = $plan['ops'];
		$out = array(
			'action' => $plan['action'],
			'counts' => self::counts( $ops ),
			'pairs'  => array_map( array( __CLASS__, 'named' ), array_slice( $ops, 0, self::NAMED ) ),
		);
		if ( array() !== $plan['skipped'] ) {
			$out['skipped'] = array_slice( $plan['skipped'], 0, self::NAMED );
		}
		if ( isset( $plan['rest'] ) && null !== $plan['rest'] ) {
			$out['more_after'] = $plan['rest'];
		}
		$out['effect'] = 'confirm' === $plan['action']
			? 'Writes the table of object pairs of this site only: no content changes on either site. A pair made from an entry equal is active (both sides as its base); from differs, or a chosen pair of other content, it is unclear, and the report asks which side wins; realign makes the state of each side now its new base.'
			: 'Removes these pairs from the table of object pairs of this site only: their objects stay as they are on both sites, each without a pair from then on (an object of this site then shows as only live, one of the staging site as only staging).';
		return $out;
	}

	/* ---------------------------------------------------------- the tool */

	/**
	 * wp_site_pairs.
	 *
	 * @param array $a Arguments (without the token).
	 * @return array|WP_Error
	 */
	public static function call( array $a ) {
		$action = isset( $a['action'] ) ? $a['action'] : null;
		switch ( $action ) {
			case 'propose':
				return AB_MCP_Sync_Proposal::propose( $a );
			case 'list':
				return self::listed( $a );
			case 'confirm':
			case 'discard':
				$plan = self::plan( $a );
				if ( is_wp_error( $plan ) ) {
					return $plan;
				}
				return 'confirm' === $action ? self::confirm( $plan ) : self::discard( $plan );
		}
		return self::no_action();
	}

	/**
	 * discard as a person confirmed it on the page «Staging» (Z8, decision
	 * 35, branch 12): the token of the tool is the state the page showed
	 * (state()). One plan, compared with it, and the snapshots of that very
	 * plan deleted conditionally (discard(), AB_MCP_Sync_Pairs::discard()),
	 * with no plan made anew in between, all of it under the lock of the
	 * write boundary (AB_MCP_Sync_Run::under_lock()), so no step of a run
	 * changes a pair between the comparison and the deletion (Codex round 1
	 * of branch 12, A: before, the page compared one plan and the tool made
	 * a second one, whose newer state it deleted).
	 *
	 * @param array  $a     Arguments: action discard, pair_ids.
	 * @param string $state The state the page showed (state()).
	 * @return array|WP_Error As discard() returns it.
	 */
	public static function discard_as_shown( array $a, $state ) {
		require_once __DIR__ . '/class-sync-shown.php';
		if ( ! isset( $a['action'] ) || 'discard' !== $a['action'] ) {
			return self::no_action();
		}
		$ids = self::numbers( isset( $a['pair_ids'] ) ? $a['pair_ids'] : null );
		if ( null === $ids || array() === $ids || count( $ids ) > self::MAX || count( array_unique( $ids ) ) !== count( $ids ) ) {
			return self::selection( sprintf( 'discard takes pair_ids: the ids of the pairs to end (wp_site_pairs action=list names them), each once, at most %d.', self::MAX ) );
		}
		// One reading under the lock, compared with the print of the form,
		// and the rows of that reading deleted byte for byte, nothing read in
		// between (AB_MCP_Sync_Shown::done(); Codex rounds 1 and 2 of
		// branch 12, A).
		return AB_MCP_Sync_Shown::done(
			static function () use ( $ids ) {
				return self::rows_as_read( $ids );
			},
			static function ( array $rows ) {
				return self::state_of_rows( $rows );
			},
			is_string( $state ) ? $state : '',
			static function ( array $rows ) {
				return self::discard_rows( $rows );
			},
			static function () {
				return new WP_Error( 'ab_mcp_sync_pairs_changed', 'No pair was discarded: a pair changed since the page was loaded (a run took it over or back, or it was aligned anew). Look at it again on the page AlphaBridge → Staging and discard it then.' );
			},
			'No pair is discarded'
		);
	}

	/**
	 * The pairs to end, each read once (AB_MCP_Sync_Pairs::shown_row()), of
	 * the source of the confirmed key.
	 *
	 * @param int[] $ids Pair ids.
	 * @return array[]|WP_Error
	 */
	private static function rows_as_read( array $ids ) {
		$source = AB_MCP_Sync_Proposal::source_id();
		if ( is_wp_error( $source ) ) {
			return $source;
		}
		$rows = array();
		foreach ( $ids as $id ) {
			$row = AB_MCP_Sync_Pairs::shown_row( $id );
			if ( is_wp_error( $row ) ) {
				return $row;
			}
			if ( null === $row || $source !== $row['source_id'] ) {
				return self::selection( sprintf( 'There is no pair %d of the staging site whose key is confirmed here.', $id ) );
			}
			$rows[] = $row;
		}
		return $rows;
	}

	/**
	 * The print of pairs as read or shown (each with snapshot_hash): the
	 * state discard of them is bound to, as state() gives it while they are
	 * as read.
	 *
	 * @param array[] $rows Pairs with snapshot_hash.
	 * @return string
	 */
	private static function state_of_rows( array $rows ) {
		$ops = array();
		foreach ( $rows as $row ) {
			$ops[] = self::discard_op( $row, (string) $row['snapshot_hash'] );
		}
		return hash( 'sha256', AB_MCP_Sync_Canon::encode( self::state_of_ops( $ops ) ) );
	}

	/**
	 * End the pairs as read: each row deleted byte for byte as read
	 * (AB_MCP_Sync_Pairs::discard_row()), in one transaction, all or nothing.
	 *
	 * @param array[] $rows Pairs as read (rows_as_read()).
	 * @return array|WP_Error As discard() answers.
	 */
	private static function discard_rows( array $rows ) {
		$ops  = array();
		$done = AB_MCP_Sync_Pairs::transaction(
			static function () use ( $rows, &$ops ) {
				foreach ( $rows as $row ) {
					$result = AB_MCP_Sync_Pairs::discard_row( $row['raw'] );
					if ( is_wp_error( $result ) ) {
						return new WP_Error( 'ab_mcp_sync_pairs_changed', sprintf( 'Pair %1$d was not discarded: it is not the pair as read any more (%2$s), so nothing was ended; the database took back all of it. Look at it again on the page AlphaBridge → Staging and discard it then.', (int) $row['id'], $result->get_error_message() ) );
					}
					$ops[] = self::discard_op( $row, (string) $row['snapshot_hash'] );
				}
				return true;
			}
		);
		return is_wp_error( $done ) ? $done : self::discarded( $ops );
	}

	/* -------------------------------------------------------------- plan */

	/**
	 * What confirm or discard does, read fresh: the operations, what is
	 * skipped and the state the token is bound to.
	 *
	 * @param array $a Arguments.
	 * @return array|WP_Error action, ops, skipped, state.
	 */
	public static function plan( array $a ) {
		$action = isset( $a['action'] ) ? $a['action'] : null;
		if ( ! in_array( $action, array( 'confirm', 'discard' ), true ) ) {
			return self::no_action();
		}
		$source = AB_MCP_Sync_Proposal::source_id();
		if ( is_wp_error( $source ) ) {
			return $source;
		}
		$plan = 'confirm' === $action ? self::plan_confirm( $a, $source ) : self::plan_discard( $a, $source );
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}
		$plan['action'] = $action;
		$plan['source'] = $source;
		// The arguments bind the action, the proposal and the choice; the
		// state binds what they meet now: each operation with the pair it
		// finds and the object of this site read fresh, the print of the pair
		// of sites, the connection.
		$plan['state'] = self::state_of_ops( $plan['ops'] );
		return $plan;
	}

	/**
	 * The state a plan binds, of its operations (plan()).
	 *
	 * @param array[] $ops The operations.
	 * @return array
	 */
	private static function state_of_ops( array $ops ) {
		return array(
			'connection' => AB_MCP_Sync_Proposal::connection(),
			'ops'        => $ops,
			'print'      => (string) AB_MCP_Sync_Anchor::pair_print(),
		);
	}

	/**
	 * The state discard of one pair is bound to, from the pair as a page
	 * showed it (AB_MCP_Sync_Pairs::page_source_shown(): decoded with the
	 * snapshot hash of the same row), without reading it again: what
	 * state() gives for it while the pair is as shown, so that
	 * discard_as_shown() deletes exactly that row or nothing (Codex round 2
	 * of branch 12, A: the page showed one row and its form bound a newer
	 * one read after it).
	 *
	 * @param array $pair The pair as shown, with snapshot_hash.
	 * @return string|null Null where the row gave no snapshot.
	 */
	public static function state_shown( array $pair ) {
		return empty( $pair['snapshot_hash'] ) ? null : self::state_of_rows( array( $pair ) );
	}

	/**
	 * The operation that ends a pair (plan_discard(), state_shown()).
	 *
	 * @param array  $pair     The pair (decoded).
	 * @param string $expected Its snapshot hash.
	 * @return array
	 */
	private static function discard_op( array $pair, $expected ) {
		return array(
			'op'          => 'discard',
			'pair_id'     => (int) $pair['id'],
			'kind'        => $pair['kind'],
			'live_ref'    => $pair['live_ref'],
			'staging_ref' => $pair['staging_ref'],
			'state'       => $pair['state'],
			'expected'    => (string) $expected,
		);
	}

	/**
	 * The plan of confirm: the entries chosen, each checked against the
	 * proposal and the pairs as they are now.
	 *
	 * @param array  $a      Arguments.
	 * @param string $source Source.
	 * @return array|WP_Error ops, skipped, proposal.
	 */
	private static function plan_confirm( array $a, $source ) {
		$found = AB_MCP_Sync_Proposal::find( isset( $a['proposal_id'] ) ? $a['proposal_id'] : null, $source );
		if ( is_wp_error( $found ) ) {
			return $found;
		}
		if ( null === $found['index'] ) {
			return new WP_Error( 'ab_mcp_sync_pairs_not_done', 'This proposal is not worked out yet: the inventory of this site is still being read. Call wp_site_pairs with action=propose and this proposal_id until it answers done true, then confirm.' );
		}
		$select     = self::numbers( isset( $a['select'] ) ? $a['select'] : array() );
		$categories = isset( $a['categories'] ) ? $a['categories'] : array();
		$choices    = isset( $a['choices'] ) ? $a['choices'] : array();
		if ( null === $select || ! is_array( $categories ) || ! array_is_list( $categories ) || array() !== array_diff( $categories, self::BY_CATEGORY ) || ! is_array( $choices ) || ! array_is_list( $choices ) ) {
			return self::selection( 'select is a list of entry numbers, categories a list of equal, differs and realign, choices a list of entry, staging_ref and live_ref.' );
		}
		if ( array() === $select && array() === $categories && array() === $choices ) {
			return self::selection( 'Nothing is chosen.' );
		}
		$after = isset( $a['after'] ) && is_numeric( $a['after'] ) ? max( 0, (int) $a['after'] ) : 0;
		$want  = $select;
		foreach ( $choices as $choice ) {
			if ( ! is_array( $choice ) || ! isset( $choice['entry'], $choice['staging_ref'], $choice['live_ref'] ) || null === self::numbers( array( $choice['entry'] ) ) ) {
				return self::selection( 'A choice is an object of entry (its number), staging_ref and live_ref, both refs among the candidates of that uncertain entry.' );
			}
			$want[] = (int) $choice['entry'];
		}
		$entries = AB_MCP_Sync_Proposal::entries( $found, $want );
		if ( is_wp_error( $entries ) ) {
			return $entries;
		}
		foreach ( $want as $n ) {
			if ( ! isset( $entries[ $n ] ) ) {
				return self::selection( sprintf( 'The proposal has no entry %d (it has %d).', $n, (int) $found['index']['total'] ) );
			}
		}
		$chosen  = array();
		$skipped = array();
		foreach ( $select as $n ) {
			$entry = $entries[ $n ];
			if ( in_array( $entry['category'], AB_MCP_Sync_Proposal::BY_CHOICE, true ) ) {
				return self::selection( sprintf( 'Entry %1$d is %2$s: it is paired only by the choice of a person. Name the pair the person chose in choices (entry, staging_ref, live_ref).', $n, $entry['category'] ) );
			}
			if ( ! in_array( $entry['category'], array( 'equal', 'differs', 'realign' ), true ) ) {
				return self::selection( sprintf( 'Entry %1$d is %2$s and is not paired: %3$s', $n, $entry['category'], isset( $entry['reason'] ) ? $entry['reason'] : 'it has no counterpart to pair it with.' ) );
			}
			if ( 'realign' === $entry['category'] && null === $entry['confirm'] ) {
				return self::selection( sprintf( 'Entry %1$d cannot be aligned anew: %2$s', $n, $entry['reason'] ) );
			}
			$chosen[ $n ] = $entry;
		}
		$rest = null;
		if ( array() !== $categories ) {
			$room = self::MAX - count( $chosen ) - count( $choices );
			if ( $room < 1 ) {
				return self::selection( sprintf( 'One confirmation makes at most %d pairs, and select and choices fill them already. Confirm the categories in a call of their own.', self::MAX ) );
			}
			$more = AB_MCP_Sync_Proposal::of_categories( $found, $categories, $after, $room );
			if ( is_wp_error( $more ) ) {
				return $more;
			}
			if ( $more['more'] && array() !== $more['entries'] ) {
				$rest = (int) end( $more['entries'] )['n'];
			}
			foreach ( $more['entries'] as $entry ) {
				if ( 'realign' === $entry['category'] && null === $entry['confirm'] ) {
					$skipped[] = array(
						'entry'  => $entry['n'],
						'reason' => $entry['reason'],
					);
					continue;
				}
				$chosen[ $entry['n'] ] = $entry;
			}
		}
		ksort( $chosen );
		$ops = array();
		foreach ( $chosen as $n => $entry ) {
			$ops[] = 'realign' === $entry['category'] ? self::realign_op( $entry ) : self::create_op( $n, $entry['kind'], $entry['staging'], $entry['live'], $entry['confirm'] );
		}
		$used = array();
		foreach ( $choices as $choice ) {
			$n     = (int) $choice['entry'];
			$entry = $entries[ $n ];
			if ( ! in_array( $entry['category'], AB_MCP_Sync_Proposal::BY_CHOICE, true ) ) {
				return self::selection( sprintf( 'Entry %d is not uncertain; choose it with select.', $n ) );
			}
			$s = self::candidate( $entry['candidates']['staging'], $choice['staging_ref'] );
			$l = self::candidate( $entry['candidates']['live'], $choice['live_ref'] );
			if ( null === $s || null === $l ) {
				return self::selection( sprintf( 'The refs of a choice for entry %d are not among its candidates.', $n ) );
			}
			if ( ! is_string( $s['hash'] ) || ! is_string( $l['hash'] ) ) {
				return self::selection( sprintf( 'A candidate of entry %d is not compared (it has no hash), so it is not paired; take it over by hand.', $n ) );
			}
			// The same once mapped (decision 10), each reference to the
			// object of a pair naming its other object (Codex round 2 of
			// part 2, b): active; else unclear.
			$equal = AB_MCP_Sync_Proposal::alike( $s, $l );
			$ops[] = self::create_op( $n, $entry['kind'], $s, $l, $equal ? 'active' : 'unclear' );
		}
		if ( count( $ops ) > self::MAX ) {
			return self::selection( sprintf( 'One confirmation makes at most %d pairs. Confirm in parts: categories with after (the number of the last entry of the part before), or fewer numbers in select.', self::MAX ) );
		}
		foreach ( $ops as $op ) {
			if ( 'create' !== $op['op'] ) {
				continue;
			}
			foreach ( array( 'live_ref', 'staging_ref' ) as $column ) {
				$key = $op['kind'] . "\0" . $column . "\0" . $op[ $column ];
				if ( isset( $used[ $key ] ) ) {
					return self::selection( sprintf( 'The same object is chosen twice (entries %1$d and %2$d); each object has one pair.', $used[ $key ], $op['entry'] ) );
				}
				$used[ $key ] = $op['entry'];
			}
		}
		// The objects of this site read fresh (Codex B3): the state binds them.
		$live = AB_MCP_Sync_Proposal::live_now(
			array_map(
				static function ( $op ) {
					return array(
						'kind' => $op['kind'],
						'ref'  => $op['live_ref'],
					);
				},
				$ops
			)
		);
		if ( is_wp_error( $live ) ) {
			return $live;
		}
		$fresh = array();
		foreach ( $ops as $op ) {
			$op['live_now'] = $live[ $op['kind'] . "\0" . $op['live_ref'] ];
			$now            = 'gone' === $op['live_now'] ? 'the object of this site is gone since the proposal' : self::now( $source, $op );
			if ( is_wp_error( $now ) ) {
				return $now;
			}
			if ( is_string( $now ) ) {
				$skipped[] = array(
					'entry'  => $op['entry'],
					'reason' => $now,
				);
				continue;
			}
			$fresh[] = $op;
		}
		if ( array() === $fresh ) {
			return new WP_Error(
				'ab_mcp_sync_pairs_nothing',
				sprintf(
					'Nothing is left to confirm here: %1$d of the entries chosen are paired already, gone or changed since the proposal (%2$s). Propose again (wp_site_pairs action=propose) to see the pairs as they are%3$s.',
					count( $skipped ),
					implode( '; ', array_map( static fn( $s ) => 'entry ' . $s['entry'] . ': ' . $s['reason'], array_slice( $skipped, 0, 5 ) ) ),
					null === $rest ? '' : sprintf( ', or go on with the same categories and after=%d, where more entries of them are left', $rest )
				)
			);
		}
		return array(
			'ops'     => $fresh,
			'skipped' => $skipped,
			'rest'    => $rest,
		);
	}

	/**
	 * The plan of discard: each pair of this source as it is now.
	 *
	 * @param array  $a      Arguments.
	 * @param string $source Source.
	 * @return array|WP_Error ops, skipped.
	 */
	private static function plan_discard( array $a, $source ) {
		$ids = self::numbers( isset( $a['pair_ids'] ) ? $a['pair_ids'] : null );
		if ( null === $ids || array() === $ids || count( $ids ) > self::MAX || count( array_unique( $ids ) ) !== count( $ids ) ) {
			return self::selection( sprintf( 'discard takes pair_ids: the ids of the pairs to end (wp_site_pairs action=list names them), each once, at most %d.', self::MAX ) );
		}
		$ops = array();
		foreach ( $ids as $id ) {
			$pair = AB_MCP_Sync_Pairs::get( $id );
			if ( is_wp_error( $pair ) ) {
				return $pair;
			}
			if ( null === $pair || $source !== $pair['source_id'] ) {
				return self::selection( sprintf( 'There is no pair %d of the staging site whose key is confirmed here.', $id ) );
			}
			$snapshot = AB_MCP_Sync_Pairs::snapshot( $id );
			if ( is_wp_error( $snapshot ) ) {
				return $snapshot;
			}
			$ops[] = self::discard_op( $pair, AB_MCP_Sync_Pairs::snapshot_hash( $snapshot ) );
		}
		return array(
			'ops'     => $ops,
			'skipped' => array(),
		);
	}

	/**
	 * The operation that makes a pair of an entry.
	 *
	 * @param int    $n       Entry.
	 * @param string $kind    Kind.
	 * @param array  $s       Staging side.
	 * @param array  $l       Production side.
	 * @param string $state   active or unclear.
	 * @return array
	 */
	private static function create_op( $n, $kind, array $s, array $l, $state ) {
		return array(
			'op'           => 'create',
			'entry'        => (int) $n,
			'kind'         => (string) $kind,
			'live_ref'     => (string) $l['ref'],
			'staging_ref'  => (string) $s['ref'],
			'state'        => 'active' === $state ? 'active' : 'unclear',
			'canon'        => AB_MCP_Sync_Canon::VERSION,
			'base_live'    => (string) $l['hash'],
			'base_staging' => (string) $s['hash'],
			'identity'     => array(
				'live'    => $l['identity'],
				'staging' => $s['identity'],
			),
		) + self::instances_of( $kind, $s, $l );
	}

	/**
	 * The instances of a widget area on both sides at its new bases, which
	 * the confirmation keeps in base_extra (instances: live, staging): the
	 * report tells an instance removed on staging from them (decision 21;
	 * branch 6, part 2). None for another kind, or where a side does not
	 * name its instances.
	 *
	 * @param string $kind Kind.
	 * @param array  $s    Staging side.
	 * @param array  $l    Production side.
	 * @return array instances, or nothing.
	 */
	private static function instances_of( $kind, array $s, array $l ) {
		if ( 'widget_area' !== $kind || ! isset( $s['instances'], $l['instances'] ) || ! is_array( $s['instances'] ) || ! is_array( $l['instances'] ) ) {
			return array();
		}
		return array(
			'instances' => array(
				'live'    => array_values( $l['instances'] ),
				'staging' => array_values( $s['instances'] ),
			),
		);
	}

	/**
	 * The operation that aligns a pair anew.
	 *
	 * @param array $entry Entry realign.
	 * @return array
	 */
	private static function realign_op( array $entry ) {
		return array(
			'op'           => 'realign',
			'entry'        => (int) $entry['n'],
			'pair_id'      => (int) $entry['pair']['id'],
			'kind'         => (string) $entry['kind'],
			'live_ref'     => (string) $entry['live']['ref'],
			'staging_ref'  => (string) $entry['staging']['ref'],
			'from'         => $entry['pair']['state'],
			'state'        => 'active',
			'canon'        => AB_MCP_Sync_Canon::VERSION,
			'base_live'    => (string) $entry['live']['hash'],
			'base_staging' => (string) $entry['staging']['hash'],
			'expected'     => $entry['pair']['snapshot'],
			'identity'     => array(
				'live'    => $entry['live']['identity'],
				'staging' => $entry['staging']['identity'],
			),
		) + self::instances_of( $entry['kind'], $entry['staging'], $entry['live'] );
	}

	/**
	 * An operation against the pairs as they are now: null where it holds,
	 * a reason where it is skipped.
	 *
	 * @param string $source Source.
	 * @param array  $op     Operation.
	 * @return string|null|WP_Error
	 */
	private static function now( $source, array $op ) {
		if ( 'create' === $op['op'] ) {
			foreach ( array( 'live_ref' => 'find_live', 'staging_ref' => 'find_staging' ) as $column => $find ) {
				$pair = AB_MCP_Sync_Pairs::$find( $source, $op['kind'], $op[ $column ] );
				if ( is_wp_error( $pair ) ) {
					return $pair;
				}
				if ( null !== $pair ) {
					return sprintf( 'paired meanwhile (pair %d)', (int) $pair['id'] );
				}
			}
			return null;
		}
		$snapshot = AB_MCP_Sync_Pairs::snapshot( $op['pair_id'] );
		if ( is_wp_error( $snapshot ) ) {
			return 'ab_mcp_staging_pair_missing' === $snapshot->get_error_code() ? sprintf( 'pair %d is gone', $op['pair_id'] ) : $snapshot;
		}
		return hash_equals( $op['expected'], AB_MCP_Sync_Pairs::snapshot_hash( $snapshot ) ) ? null : sprintf( 'pair %d changed since the proposal', $op['pair_id'] );
	}

	/**
	 * A candidate of an uncertain entry by its ref.
	 *
	 * @param array[] $list Candidates.
	 * @param mixed   $ref  Ref.
	 * @return array|null
	 */
	private static function candidate( array $list, $ref ) {
		foreach ( $list as $one ) {
			if ( AB_MCP_Sync_Canon::encode( $one['ref'] ) === AB_MCP_Sync_Canon::encode( $ref ) ) {
				return $one;
			}
		}
		return null;
	}

	/* ------------------------------------------------------------ write */

	/**
	 * Make and align the pairs of a plan in one transaction of the database
	 * (Codex B2): all or nothing, also where the process ends in between.
	 *
	 * @param array $plan plan().
	 * @return array|WP_Error
	 */
	private static function confirm( array $plan ) {
		$out  = array();
		$done = AB_MCP_Sync_Pairs::transaction(
			static function () use ( $plan, &$out ) {
				foreach ( $plan['ops'] as $op ) {
					if ( 'create' === $op['op'] ) {
						$pair = AB_MCP_Sync_Pairs::create(
							array(
								'source_id'    => $plan['source'],
								'kind'         => $op['kind'],
								'live_ref'     => $op['live_ref'],
								'staging_ref'  => $op['staging_ref'],
								'state'        => $op['state'],
								'canon'        => $op['canon'],
								'base_live'    => $op['base_live'],
								'base_staging' => $op['base_staging'],
								'base_origin'  => 'proposal',
							) + ( isset( $op['instances'] ) ? array( 'base_extra' => array( 'instances' => $op['instances'] ) ) : array() )
						);
					} else {
						$bases = array(
							'canon'        => $op['canon'],
							'base_live'    => $op['base_live'],
							'base_staging' => $op['base_staging'],
						);
						if ( isset( $op['instances'] ) ) {
							// The instances of both sides now, beside what base_extra
							// holds already (the mapping a run kept stays, decision 23).
							$now  = AB_MCP_Sync_Pairs::get( $op['pair_id'] );
							$kept = is_array( $now ) && is_array( $now['base_extra'] ) ? $now['base_extra'] : array();
							$bases['base_extra'] = array( 'instances' => $op['instances'] ) + $kept;
						}
						$pair = AB_MCP_Sync_Pairs::realign( $op['pair_id'], $bases, $op['expected'], true );
					}
					if ( is_wp_error( $pair ) ) {
						return new WP_Error(
							'ab_mcp_sync_pairs_confirm_failed',
							sprintf( 'Entry %1$d could not be paired (%2$s), so the confirmation kept nothing: the database took back all of it. Propose again (wp_site_pairs action=propose) and confirm anew.', $op['entry'], $pair->get_error_message() ),
							array( 'entry' => $op['entry'] )
						);
					}
					$out[] = array(
						'entry'       => $op['entry'],
						'pair_id'     => (int) $pair['id'],
						'kind'        => $pair['kind'],
						'live_ref'    => $pair['live_ref'],
						'staging_ref' => $pair['staging_ref'],
						'state'       => $pair['state'],
						'done'        => 'create' === $op['op'] ? 'made' : 'aligned anew',
					);
				}
				return true;
			}
		);
		if ( is_wp_error( $done ) ) {
			return $done;
		}
		$answer = array(
			'done'   => true,
			'counts' => self::counts( $plan['ops'] ),
			'pairs'  => array_slice( $out, 0, self::NAMED ),
		);
		if ( array() !== $plan['skipped'] ) {
			$answer['skipped'] = array_slice( $plan['skipped'], 0, self::NAMED );
		}
		$answer['next'] = 'The pairs are in the table of object pairs of this site; no content changed. wp_site_pairs action=list shows them; action=discard with pair_ids ends a pair.';
		if ( null !== $plan['rest'] ) {
			$answer['more_after'] = $plan['rest'];
			$answer['next']      .= sprintf( ' More entries of these categories are left: confirm them with the same categories and after=%d.', $plan['rest'] );
		}
		return $answer;
	}

	/**
	 * End the pairs of a plan in one transaction of the database: all or
	 * nothing. Any account with manage_options and the access level Full may
	 * (pairs are data of the site, not of an account; the log of the tool
	 * calls names the account).
	 *
	 * @param array $plan plan().
	 * @return array|WP_Error
	 */
	private static function discard( array $plan ) {
		$done = AB_MCP_Sync_Pairs::transaction(
			static function () use ( $plan ) {
				foreach ( $plan['ops'] as $op ) {
					$result = AB_MCP_Sync_Pairs::discard( $op['pair_id'], $op['expected'] );
					if ( is_wp_error( $result ) ) {
						return new WP_Error( 'ab_mcp_sync_pairs_discard_failed', sprintf( 'Pair %1$d could not be ended (%2$s), so the confirmation ended nothing: the database took back all of it. Read the pairs again (wp_site_pairs action=list) and discard them anew.', $op['pair_id'], $result->get_error_message() ) );
					}
				}
				return true;
			}
		);
		if ( is_wp_error( $done ) ) {
			return $done;
		}
		return self::discarded( $plan['ops'] );
	}

	/**
	 * The answer of discard: the pairs ended.
	 *
	 * @param array[] $ops The operations done.
	 * @return array
	 */
	private static function discarded( array $ops ) {
		return array(
			'done'   => true,
			'counts' => array( 'discarded' => count( $ops ) ),
			'pairs'  => array_map(
				static function ( $op ) {
					return array(
						'pair_id'     => $op['pair_id'],
						'kind'        => $op['kind'],
						'live_ref'    => $op['live_ref'],
						'staging_ref' => $op['staging_ref'],
						'state'       => $op['state'],
						'done'        => 'ended',
					);
				},
				array_slice( $ops, 0, self::NAMED )
			),
			'next'   => 'The objects of these pairs are without a pair now; no content changed. Propose again (wp_site_pairs action=propose) to pair them anew.',
		);
	}

	/* ------------------------------------------------------------- list */

	/**
	 * wp_site_pairs action list: the pairs of the source, a page at a time.
	 *
	 * @param array $a after, kind, state.
	 * @return array|WP_Error
	 */
	private static function listed( array $a ) {
		$source = AB_MCP_Sync_Proposal::source_id();
		if ( is_wp_error( $source ) ) {
			return $source;
		}
		$kind  = isset( $a['kind'] ) ? (string) $a['kind'] : null;
		$state = isset( $a['state'] ) ? (string) $a['state'] : null;
		if ( null !== $state && ! in_array( $state, AB_MCP_Sync_Pairs::STATES, true ) ) {
			return self::selection( 'state is one of ' . implode( ', ', AB_MCP_Sync_Pairs::STATES ) . '.' );
		}
		$page = AB_MCP_Sync_Pairs::page_source( $source, isset( $a['after'] ) && is_numeric( $a['after'] ) ? max( 0, (int) $a['after'] ) : 0 );
		if ( is_wp_error( $page ) ) {
			return $page;
		}
		$pairs = array();
		foreach ( $page['pairs'] as $pair ) {
			if ( ( null !== $kind && $kind !== $pair['kind'] ) || ( null !== $state && $state !== $pair['state'] ) ) {
				continue;
			}
			$pairs[] = array(
				'pair_id'     => (int) $pair['id'],
				'kind'        => $pair['kind'],
				'live_ref'    => $pair['live_ref'],
				'staging_ref' => $pair['staging_ref'],
				'state'       => $pair['state'],
				'canon'       => $pair['canon'],
				'override'    => $pair['override'],
				'base_origin' => $pair['base_origin'],
				'base_time'   => (int) $pair['base_time'],
				'last_run'    => $pair['last_run'],
			);
		}
		return array(
			'source_id'  => $source,
			'pairs'      => $pairs,
			'next_after' => $page['next'],
			'next'       => null === $page['next'] ? 'These are all pairs of this page and after.' : 'More pairs: call wp_site_pairs again with action=list and after=' . $page['next'] . '.',
		);
	}

	/* ---------------------------------------------------------- helpers */

	/**
	 * Operations counted by what they do.
	 *
	 * @param array[] $ops Operations.
	 * @return array<string,int>
	 */
	private static function counts( array $ops ) {
		$out = array();
		foreach ( $ops as $op ) {
			$key         = 'create' === $op['op'] ? 'made_' . $op['state'] : ( 'realign' === $op['op'] ? 'aligned' : 'discarded' );
			$out[ $key ] = ( $out[ $key ] ?? 0 ) + 1;
		}
		ksort( $out );
		return $out;
	}

	/**
	 * An operation as a preview names it: without hashes and snapshots.
	 *
	 * @param array $op Operation.
	 * @return array
	 */
	private static function named( array $op ) {
		if ( isset( $op['live_now'] ) ) {
			$op['changed_since_proposal'] = $op['live_now'] !== $op['base_live'];
		}
		unset( $op['base_live'], $op['base_staging'], $op['expected'], $op['canon'], $op['live_now'], $op['instances'] );
		return $op;
	}

	/**
	 * Whole numbers from 1, from a list of integers or their digits.
	 *
	 * @param mixed $list List.
	 * @return int[]|null Null where it is not such a list.
	 */
	private static function numbers( $list ) {
		if ( ! is_array( $list ) || ! array_is_list( $list ) ) {
			return null;
		}
		$out = array();
		foreach ( $list as $n ) {
			if ( ! ( is_int( $n ) || ( is_string( $n ) && 1 === preg_match( '/^[0-9]{1,18}\z/', $n ) ) ) || (int) $n < 1 ) {
				return null;
			}
			$out[] = (int) $n;
		}
		return $out;
	}

	/**
	 * @param string $what What is wrong with the selection.
	 * @return WP_Error
	 */
	private static function selection( $what ) {
		return new WP_Error( 'ab_mcp_sync_pairs_selection', $what . ' Nothing was changed. Check the entries of the proposal (wp_site_pairs action=propose with the proposal_id) or the pairs (action=list), then call again.' );
	}

	/**
	 * @return WP_Error No action, or an unknown one.
	 */
	private static function no_action() {
		return new WP_Error( 'ab_mcp_sync_pairs_action', 'wp_site_pairs needs action: propose, confirm, discard or list. Pass one of them and call again.' );
	}
}

<?php
/**
 * The decision function of the report (Z8, decisions 1, 14, 16, 21 and 22
 * of the plan of 01.11.2026; concept «Echtes Staging», section 4; branch 6,
 * part 2): what each object of the two inventories is, and what a takeover
 * would do with it, from the lines of both sides and the pairs alone (no
 * database). wp_site_diff (AB_MCP_Sync_Report) gives it the lines and stores
 * what it decides.
 *
 * THE STATE OF A SIDE of a pair, against the base of that side in its own ID
 * space (decision 10): unchanged (its hash is the base), changed, deleted
 * (not on its site, or in the trash) or not compared (held by a credential,
 * without a hash, not read whole, an auto-draft, or of another version of the
 * canonical form). A missing staging object is deleted only where the
 * inventory of the staging site holds every object of its kind (the packages
 * of one series, AB_MCP_Sync_Proposal::complete()): incomplete never means
 * deleted. This site's inventory is read whole; an object of it missing
 * because its name holds a credential (B9, counted by its kind and the
 * SHA-256 of its ref) is not compared, never deleted, and its entry shows
 * neither side by its name: both by the SHA-256 of their refs, as a held
 * line (Codex round 1 of part 2, A). Where the difference counts, deleted
 * says which: in the trash, or removed (rule 0, rule 2, row 10).
 *
 * THE ORDER (concept section 4, «Vorrang»), the first that applies:
 *
 *  0. A pair residual (made by a run and left after its way back or an
 *     error, decision 14, finding R6-4): it keeps its mapping and is shown as
 *     rest with its run and object; no row of the table applies, nothing is
 *     taken over or created for it, also where staging changes it. Its
 *     object no longer on this site: «rest removed»; in the trash here it is
 *     still the rest (Codex round 1 of part 2, C). A widget area so as a
 *     whole, its instance mapping (base_extra) as it is (decision 23).
 *  1. A pair unclear: like a conflict (a person chooses per object) until a
 *     person confirms its bases (wp_site_pairs); a side a credential holds
 *     (B9) is not compared, and offers no choice (Codex round 2 of part 2).
 *  2. A pair taken over as deleted (not reached in Z8, decision 22): this
 *     site has it again (there and not in the trash: one in the trash is
 *     still deleted, Codex round 1 of part 2, C) → the live state is its
 *     base, then rule 3 with the staging side; else deleted on staging →
 *     nothing; there again on staging → «restored on staging», which Z8 does
 *     not bring back.
 *  3. A pair active: the table TABLE, one row for each of the nine states of
 *     both sides, two for changed on both (alike or not: the mapped forms and
 *     refs_agree(), the rule of the proposal as well); row
 *     10 ends the pair only where both objects are removed, in the trash on
 *     a side the pair is taken over as deleted (with Z8d); a pair with an
 *     override (a confirmed target such as status draft, decision 17) is
 *     compared against that target (base_live is its hash) and names it.
 *  4. No pair: WITHOUT (only staging, only live, new on both; the match of
 *     AB_MCP_Sync_Proposal::match(), so the report and the proposal name an
 *     object alike).
 *
 * LOCKED (decisions 1, 21, 22, 25, 28): an entry carries the locks of the
 * lines of both sides (the type, the builder, an option of a plugin, a theme
 * mod, roles, a changeset, the credential check) and those of what it would
 * do: a deletion (*:delete; nothing is deleted in Z8, an object deleted on
 * staging shows as «deleted on staging – taken over from Z8d»), bringing back
 * a deleted object (*:restore_deleted), creating Additional CSS
 * (custom_css:create), creating a term with meta (term:create_with_meta), a
 * menu with an item removed on staging (menu:remove_item, the menu with all
 * its items), a widget area with an instance removed on staging
 * (widget_area:remove_instance, against the instances of its base in
 * base_extra), and mutual references. Each with its reason and stage.
 *
 * THE GRAPH (decision 16): the necessary references of the staging object of
 * every entry a takeover would write (take over, create) or a person may
 * choose. A reference whose target has an active pair with its object on
 * this site is named by that pair (the target stays as it is here, also
 * where it is locked). A target created in the same run is an edge: the
 * target is created first. Any other target makes the entry wait: an
 * unclear pair, a pair whose object is gone on a side, a target without a
 * pair that is not created (locked, new on both sides, not compared, held)
 * or not in the inventory of the staging site (an object the takeover does
 * not read). A weak reference (the parent of an attachment, decision 16;
 * branch 10) waits as a strong one does, but a target created in the same
 * run is no edge: a step of its own sets it after its target. Objects that need each
 * other to be created (a cycle of edges, one object referring to itself
 * included) are locked (*:mutual_references, Z11c), and whatever needs a
 * locked or waiting target waits for it.
 *
 * Its words (the reason of a lock, the why of a decision, the words of the
 * environment) stay English: they are compared across the two sites and go
 * into the hashes of the report and the plan.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-sync-proposal.php';

/**
 * Class AB_MCP_Sync_Decision
 */
final class AB_MCP_Sync_Decision {

	/**
	 * The table of an active pair (concept section 4): the state of the
	 * staging side, of this side, the result and what a takeover does. Row 5
	 * is changed on both with other mapped forms, row 6 with the same (and
	 * the references to objects of pairs agreeing, refs_agree()). Row 10 is
	 * deleted_both where both objects are removed, deleted_both_trash where
	 * one is in the trash (table_result()).
	 */
	const TABLE = array(
		1  => array( 'unchanged', 'unchanged', 'equal', 'none' ),
		2  => array( 'changed', 'unchanged', 'changed_on_staging', 'take_over' ),
		3  => array( 'deleted', 'unchanged', 'deleted_on_staging', 'delete' ),
		4  => array( 'unchanged', 'changed', 'changed_live', 'keep' ),
		5  => array( 'changed', 'changed', 'conflict', 'choose' ),
		6  => array( 'changed', 'changed', 'changed_alike', 'none' ),
		7  => array( 'deleted', 'changed', 'deleted_against_changed_live', 'keep' ),
		8  => array( 'unchanged', 'deleted', 'deleted_live', 'none' ),
		9  => array( 'changed', 'deleted', 'deleted_live_changed_staging', 'keep' ),
		10 => array( 'deleted', 'deleted', 'deleted_both', 'none' ),
	);

	/** The table without a pair: the staging side, this side, the result, what a takeover does. */
	const WITHOUT = array(
		1 => array( 'new', 'absent', 'only_staging', 'create' ),
		2 => array( 'absent', 'new', 'only_live', 'keep' ),
		3 => array( 'new', 'new', 'new_both', 'pair' ),
	);

	/** What each result says. */
	const WORDS = array(
		'equal'                        => 'Equal on both sides: nothing to take over.',
		'changed_on_staging'           => 'Changed on staging, unchanged on this site: taken over.',
		'deleted_on_staging'           => 'Deleted on staging – taken over from Z8d: nothing is deleted in this version, and no status stands in for a deletion. Delete it here by hand in wp-admin where it should go.',
		'changed_live'                 => 'Changed on this site, unchanged on staging: kept and shown.',
		'conflict'                     => 'Changed on both sides, differently: a conflict. A person chooses: staging wins, keep this site, or leave it open.',
		'changed_alike'                => 'Changed on both sides alike: nothing to take over; the bases of the pair move on with the next run.',
		'deleted_against_changed_live' => 'Deleted on staging, changed on this site: a conflict, kept on this site by default; the deletion is taken over from Z8d.',
		'deleted_live'                 => 'Deleted on this site, unchanged on staging: nothing to do, the deletion holds.',
		'deleted_live_changed_staging' => 'Deleted on this site, changed on staging: a conflict, deleted here by default; bringing it back from staging follows with Z8d.',
		'deleted_both'                 => 'Deleted on both sides, removed on both (neither is in the trash): nothing to do; wp_site_pairs action=discard ends the pair.',
		'deleted_both_trash'           => 'Deleted on both sides, in the trash on at least one: nothing to do. The pair is then taken over as deleted (the deletion holds), which follows with Z8d; until then it stays as it is, and it is not ended: only a pair whose objects are removed on both sides is, so an object in the trash can still come back to its pair.',
		'only_staging'                 => 'Only on staging, new: created on this site.',
		'only_live'                    => 'Only on this site, new (written here): kept and shown. Refresh the staging site to have it there as well.',
		'new_both'                     => 'New on both sides, without a pair: pair them first (wp_site_pairs action=propose, then confirm).',
		'unclear'                      => 'An unclear pair: treated as a conflict, a person chooses which side wins, until a person confirms its bases (wp_site_pairs action=propose shows it for a new alignment, confirm sets them).',
		'residual'                     => 'Rest after a way back or an error: the pair keeps its mapping, and nothing is taken over or created for it, also where staging changes it. Only a confirmed new alignment moves it on (wp_site_pairs action=propose shows it, confirm makes it active with the bases of both sides now, discard ends the pair).',
		'residual_removed'             => 'Rest removed: the object of this rest is no longer on this site. wp_site_pairs action=discard ends the pair.',
		'deleted_done'                 => 'Taken over as deleted and deleted on staging: nothing to do.',
		'restored_on_staging'          => 'Taken over as deleted, and there again on staging: bringing it back here follows with Z8d.',
		'not_compared'                 => 'Not compared.',
		'held'                         => 'A name of this object holds a credential by the rules of this site: shown by the SHA-256 of its ref only, never compared. Take it over by hand.',
		'in_trash'                     => 'In the trash on its site, without a pair: not taken over.',
	);

	/** The order of the entries by what a takeover does, then by result. */
	const ACTION_ORDER = array( 'choose', 'take_over', 'create', 'waits', 'locked', 'pair', 'keep', 'none' );

	/** The pair kinds whose objects are in the ID space of posts, and of terms. */
	const SPACES = AB_MCP_Sync_Proposal::SPACES;

	/** The kinds of units and of the theme that runs (Z8b, E1, E2): lines of the environment with pairs. */
	const UNIT_KINDS = array( 'plugin', 'theme', 'mu_plugin', 'language', 'font', 'upload', 'theme_choice' );

	/**
	 * The kinds of unit whose files the swap of the code takes over (Z8b
	 * branch 5, AB_MCP_Sync_Code_Swap): of the others nothing is taken over
	 * in this version.
	 */
	const SWAPPED = array( 'plugin', 'theme' );

	/** The results of a unit, by its parts and its pair (Z8b, E1, E2), and what each says. */
	const UNIT_WORDS = array(
		'equal'              => 'Equal on both sides: the same files by their contents, and for a plugin the same main files running.',
		'differs'            => 'Differs between the sides, and it has no pair: which side changed is not known until a person confirms a common base for its pair.',
		'same_value'         => 'AlphaBridge itself, the same version on both sides: shown by its version only, never taken over.',
		'own_differs'        => 'AlphaBridge itself, in another version on each side: shown by its version only, never taken over.',
		'only_staging'       => 'Only on staging.',
		'only_live'          => 'Only on this site.',
		'not_compared'       => 'Not compared: see why.',
		'changed_on_staging' => 'Changed on staging since the base of its pair, unchanged on this site.',
		'changed_live'       => 'Changed on this site since the base of its pair, unchanged on staging: kept and shown.',
		'conflict'           => 'Changed on both sides since the base of its pair, differently.',
		'changed_alike'      => 'Changed on both sides alike since the base of its pair: nothing to take over.',
		'kept_apart'         => 'Unchanged on both sides since the bases of its pair, which differ: the sides differ now as they did then, kept so; nothing to take over.',
		'open'               => 'A part of its pair has no base yet: which side changed it is not known until a person confirms that part (wp_site_pairs, units); nothing of it is taken over.',
		'deleted_on_staging' => 'Removed on staging since its pair was made.',
		'deleted_live'       => 'Removed on this site since its pair was made: nothing to do.',
		'deleted_against_changed_live' => 'Removed on staging since its pair was made, and changed on this site since its base: a conflict, kept on this site.',
		'deleted_live_changed_staging' => 'Removed on this site since its pair was made, and changed on staging since its base: a conflict, the removal holds here; nothing of staging is brought back in this version.',
	);

	/** What differs in a unit, in words, by its parts. */
	const DIFFERS_IN = array(
		'files'            => 'It differs in its files.',
		'activation'       => 'It differs in which of its main files run, or in which theme runs.',
		'files activation' => 'It differs in its files and in which of its main files run.',
	);

	/** Paths of an entry of a unit shown at most per list; the others follow in entries of their own (paged()), the counts say how many there are. */
	const MAX_PATHS = 100;

	/** The words of an entry that carries further paths of the unit of the entry before it (paged()). */
	const MORE_PATHS = 'Further paths of the unit of the entry before: the list they belong to (only_staging, only_live or differs), the number of the first of them in that list, and up to 100 paths in their order.';

	/** Said in the entry of a unit whose paths go on in the entries after it. */
	const PATHS_GO_ON = 'Each list of paths shows its first 100 here; the others follow, in their order, in the entries right after this one (result more_paths).';

	/* ------------------------------------------------------------ report */

	/**
	 * Every entry of a report, from the lines of both sides and the pairs.
	 *
	 * @param array[]  $staging  Lines of the staging site (a held one reduced).
	 * @param array[]  $live     Lines of this site (likewise).
	 * @param array[]  $pairs    The pairs of the source (AB_MCP_Sync_Pairs::decode());
	 *                           a pair of a unit (UNIT_KINDS) is counted in
	 *                           pairs only, its entry is that of the
	 *                           environment (AB_MCP_Sync_Env::compare()).
	 * @param callable $complete function( string $kind, ?string $subtype ): bool,
	 *                           whether the staging side holds every object of
	 *                           a kind and subtype (null: of every subtype).
	 * @param array    $b9       kind NUL SHA-256 of the ref => true: objects of
	 *                           this site whose name holds a credential.
	 * @return array entries (numbered), counts, blocked, pairs.
	 */
	public static function report( array $staging, array $live, array $pairs, callable $complete, array $b9 = array() ) {
		$ctx   = self::context( $complete, $b9, $pairs );
		$by    = array(
			's' => self::index( $staging ),
			'l' => self::index( $live ),
		);
		$items = array();
		$taken = array(
			's' => array(),
			'l' => array(),
		);
		foreach ( $pairs as $pair ) {
			// A pair of a unit is decided with the environment only
			// (unit_item()), never as an object: no second entry, no choice
			// of an object, no name of it a line of the environment holds
			// (Codex, whole round over 4c66939..f25da74, A).
			if ( in_array( $pair['kind'], self::UNIT_KINDS, true ) ) {
				continue;
			}
			$s = self::lookup( $by['s'], $pair['kind'], $pair['staging_ref'] );
			$l = self::lookup( $by['l'], $pair['kind'], $pair['live_ref'] );
			if ( null !== $s ) {
				$taken['s'][ $s ] = true;
			}
			if ( null !== $l ) {
				$taken['l'][ $l ] = true;
			}
			$items[] = self::pair_item( $pair, null === $s ? null : $staging[ $s ], null === $l ? null : $live[ $l ], $ctx );
		}
		// Without a pair: an auto-draft and an object in the trash stand
		// alone; every other line goes to the match, with the lines of the
		// pairs, so the path of a post under a paired parent is known.
		$rest = array(
			's' => array(),
			'l' => array(),
		);
		foreach ( array(
			's' => $staging,
			'l' => $live,
		) as $side => $lines ) {
			foreach ( $lines as $i => $line ) {
				$status = isset( $line['post_status'] ) && is_string( $line['post_status'] ) ? $line['post_status'] : '';
				if ( ! isset( $taken[ $side ][ $i ] ) && in_array( $status, array( 'trash', 'auto-draft' ), true ) ) {
					$items[] = self::lone_item( $side, $line, 'trash' === $status );
					continue;
				}
				$rest[ $side ][] = $line;
			}
		}
		$matched = AB_MCP_Sync_Proposal::match(
			$rest['s'],
			$rest['l'],
			$pairs,
			static function ( $kind, $subtype ) use ( $complete ) {
				return true === call_user_func( $complete, $kind, $subtype );
			}
		);
		$lines = array(
			's' => self::by_ref( $staging ),
			'l' => self::by_ref( $live ),
		);
		foreach ( $matched['entries'] as $entry ) {
			if ( 'realign' === $entry['category'] ) {
				continue;
			}
			$items[] = self::match_item( $entry, $lines );
		}
		self::operation_locks( $items );
		self::graph( $items, $pairs );
		return self::finish( $items, $pairs );
	}

	/**
	 * What the decision of a pair needs beside its lines.
	 *
	 * @param callable $complete See report().
	 * @param array    $b9       See report().
	 * @param array[]  $pairs    See report().
	 * @return array complete, b9, kinds (the pair kinds this site's inventory
	 *               reads: an object of another one missing here is not
	 *               deleted, it was never read) and paired (by side and ID
	 *               space, the id of the pair of each object a pair names,
	 *               for refs_agree(); the pairs tell it, no line of a target
	 *               is needed).
	 */
	public static function context( callable $complete, array $b9 = array(), array $pairs = array() ) {
		return array(
			'complete' => $complete,
			'b9'       => $b9,
			'kinds'    => AB_MCP_Sync_Proposal::db_kinds(),
			'paired'   => AB_MCP_Sync_Proposal::paired( $pairs ),
		);
	}

	/* ------------------------------------------------------------ a pair */

	/**
	 * The item of a pair, by the order: rule 0, then 1 to 3.
	 *
	 * @param array      $pair Pair.
	 * @param array|null $s    Its staging line.
	 * @param array|null $l    Its line on this site.
	 * @param array      $ctx  context().
	 * @return array
	 */
	public static function pair_item( array $pair, $s, $l, array $ctx ) {
		// A name this site calls a credential (B9): its staging line is
		// reduced as a held one, so neither side shows the name (Codex round
		// 1 of part 2, A; the import holds a line by the rules of the day it
		// came, this report by those of now).
		$b9 = null === $l && self::held_here( $pair, $ctx );
		if ( $b9 && null !== $s && ! isset( $s['held'] ) ) {
			$s = AB_MCP_Sync_Proposal::reduced( $s, 'credential_name' );
		}
		$subtype = self::subtype( null !== $l ? $l : $s );
		$ss      = self::side_state( 's', $s, $pair, $pair['base_staging'], $ctx, $subtype );
		$ls      = self::side_state( 'l', $l, $pair, $pair['base_live'], $ctx, $subtype );
		$item    = array(
			'kind'    => (string) $pair['kind'],
			'subtype' => $subtype,
			's'       => $s,
			'l'       => $l,
			'pair'    => $pair,
			'sides'   => array(
				'staging' => $ss[0],
				'live'    => $ls[0],
			),
			'b9'      => $b9,
		);
		// Rule 0: a rest keeps its mapping, nothing applies to it. Removed
		// only where its object is no longer on this site; in the trash here
		// it is still the rest.
		if ( 'residual' === $pair['state'] ) {
			if ( self::trashed( $l ) ) {
				return self::result( $item, 'residual', '0', null, 'none', 'In the trash on this site: still a rest. A rest is removed only where its object is no longer on this site.' );
			}
			return self::result( $item, 'deleted' === $ls[0] ? 'residual_removed' : 'residual', '0', null, 'none' );
		}
		if ( AB_MCP_Sync_Canon::VERSION !== $pair['canon'] ) {
			return self::result( $item, 'not_compared', '3', null, 'none', sprintf( 'The bases of this pair are of the version %1$s of the comparison data, this site reads %2$s, so they are not compared here. Propose again (wp_site_pairs action=propose), which works the pair out in the version of now.', (string) $pair['canon'], AB_MCP_Sync_Canon::VERSION ) );
		}
		// Rule 1: unclear, like a conflict. A side a credential holds (a line
		// held, or its name a credential here since it was paired) is not
		// compared and offers no choice, as in the other states of a pair
		// (Codex round 2 of part 2, a).
		if ( 'unclear' === $pair['state'] ) {
			if ( $b9 || isset( $s['held'] ) || isset( $l['held'] ) ) {
				return self::result( $item, 'not_compared', '1', null, 'none', self::WORDS['held'] );
			}
			return self::result( $item, 'unclear', '1', null, 'choose' );
		}
		// Rule 2: taken over as deleted (not reached in Z8).
		if ( 'deleted' === $pair['state'] ) {
			if ( null !== $l && ! isset( $l['held'] ) && ! self::trashed( $l ) ) {
				// This site has it again: its state is the base, then rule 3.
				// In the trash here it is still deleted (fail closed).
				$item['sides']['live'] = 'unchanged';
				$row                   = self::row( $ss[0], 'unchanged', false );
				if ( null === $row ) {
					return self::result( $item, 'not_compared', '2', null, 'none', $ss[1] );
				}
				return self::table_result( $item, '2', $row, 'Taken over as deleted, and there again on this site: its state here is the base, then the table with the staging side.' );
			}
			if ( 'deleted' === $ss[0] ) {
				return self::result( $item, 'deleted_done', '2', null, 'none' );
			}
			if ( 'not_compared' === $ss[0] ) {
				return self::result( $item, 'not_compared', '2', null, 'none', $ss[1] );
			}
			$item['locks'][] = self::lock( '*:restore_deleted' );
			return self::result( $item, 'restored_on_staging', '2', null, 'locked' );
		}
		// Rule 3: the table.
		foreach ( array( $ss, $ls ) as $state ) {
			if ( 'not_compared' === $state[0] ) {
				return self::result( $item, 'not_compared', '3', null, 'none', $state[1] );
			}
		}
		return self::table_result( $item, '3', self::row( $ss[0], $ls[0], 'changed' === $ss[0] && 'changed' === $ls[0] && AB_MCP_Sync_Proposal::mapped_same( $s, $l ) && self::refs_agree( $s, $l, $ctx ) ), null );
	}

	/**
	 * Whether the references of the two lines of a pair name the same
	 * objects where a pair names their targets (Codex round 1 of part 2, A;
	 * round 2, B; round 3, B): AB_MCP_Sync_Proposal::refs_agree(), the rule
	 * the proposal applies to «equal» as well, of the places of both lines
	 * by the pairs of their sides. A target of a pair is compared by its
	 * pair, a target without one by the identity the mapped form names it by
	 * (mapped_same(), beside this); no line of a target is needed.
	 *
	 * @param array $s   The staging line.
	 * @param array $l   This site's line.
	 * @param array $ctx context().
	 * @return bool
	 */
	public static function refs_agree( array $s, array $l, array $ctx ) {
		return AB_MCP_Sync_Proposal::refs_agree(
			AB_MCP_Sync_Proposal::places( $s, $ctx['paired']['s'] ),
			AB_MCP_Sync_Proposal::places( $l, $ctx['paired']['l'] )
		);
	}

	/**
	 * The item of a row of the table, with the locks of what the row would
	 * do in Z8: a deletion on staging (rows 3 and 7, decision 22), bringing
	 * back from staging what this site deleted (row 9); row 10 by whether an
	 * object of it is in the trash.
	 *
	 * @param array       $item Item.
	 * @param string      $rule 2 or 3.
	 * @param int         $row  Row.
	 * @param string|null $note Why, where it says more than its words.
	 * @return array
	 */
	private static function table_result( array $item, $rule, $row, $note ) {
		$result = self::TABLE[ $row ][2];
		// Row 10: the pair ends only where both objects are removed; in the
		// trash on a side it is taken over as deleted (concept section 4).
		if ( 10 === $row && ( self::trashed( $item['s'] ) || self::trashed( $item['l'] ) ) ) {
			$result = 'deleted_both_trash';
		}
		$out = self::result( $item, $result, $rule, $row, self::TABLE[ $row ][3], $note );
		if ( 3 === $row || 7 === $row ) {
			$out['locks'][] = self::lock( '*:delete' );
		}
		if ( 9 === $row ) {
			$out['locks'][] = self::lock( '*:restore_deleted' );
		}
		return $out;
	}

	/**
	 * The state of one side of a pair, and why where it is not compared.
	 *
	 * @param string      $side    s or l.
	 * @param array|null  $line    Its line.
	 * @param array       $pair    Pair.
	 * @param string      $base    The base of that side.
	 * @param array       $ctx     context().
	 * @param string|null $subtype The subtype of the object, where a line names it.
	 * @return array{0:string,1:string|null} unchanged, changed, deleted or not_compared; why.
	 */
	public static function side_state( $side, $line, array $pair, $base, array $ctx, $subtype ) {
		if ( null === $line ) {
			if ( ! isset( $ctx['kinds'][ $pair['kind'] ] ) ) {
				return array( 'not_compared', sprintf( 'The inventory of this version reads no objects of the kind %s, so a missing one is not called deleted.', (string) $pair['kind'] ) );
			}
			if ( 's' === $side ) {
				// Incomplete never means deleted.
				return true === call_user_func( $ctx['complete'], (string) $pair['kind'], $subtype )
					? array( 'deleted', null )
					: array( 'not_compared', 'The inventory of the staging site does not show that it holds every object of this kind (it was read across packages, or objects of it were withheld from the account that made it), so an object missing there is not called deleted. Where objects were withheld, export again with an account that may read every object (wp_site_export).' );
			}
			if ( self::held_here( $pair, $ctx ) ) {
				return array( 'not_compared', self::WORDS['held'] );
			}
			return array( 'deleted', null );
		}
		if ( isset( $line['held'] ) ) {
			return array( 'not_compared', self::WORDS['held'] );
		}
		$status = isset( $line['post_status'] ) && is_string( $line['post_status'] ) ? $line['post_status'] : '';
		if ( 'trash' === $status ) {
			return array( 'deleted', null );
		}
		if ( 'auto-draft' === $status ) {
			return array( 'not_compared', 'An auto-draft WordPress made for the editor (an object nobody saved yet, removed by WordPress after seven days): no content, never taken over.' );
		}
		if ( ! isset( $line['hash'] ) || ! is_string( $line['hash'] ) ) {
			return array( 'not_compared', AB_MCP_Sync_Proposal::why_no_hash( array( 'reason_code' => $line['reason_code'] ?? null, 'target' => $line['target'] ?? null ) ) );
		}
		if ( empty( $line['complete'] ) ) {
			return array( 'not_compared', 's' === $side ? 'The staging site did not read this object whole (a part of its rows could not be read), so it is not compared. Make the inventory again on the staging site (wp_site_export).' : 'This site did not read this object whole (a part of its rows could not be read), so it is not compared. Try again; if it keeps failing, the database error log names the cause.' );
		}
		if ( ( $line['canon'] ?? null ) !== $pair['canon'] ) {
			return array( 'not_compared', 'The line is of another version of the comparison data than the bases of its pair. Make the inventory of both sites with the same version of AlphaBridge MCP.' );
		}
		return array( hash_equals( (string) $base, (string) $line['hash'] ) ? 'unchanged' : 'changed', null );
	}

	/**
	 * The row of the table for the states of both sides.
	 *
	 * @param string $s     State of the staging side.
	 * @param string $l     State of this side.
	 * @param bool   $alike Changed on both with the same mapped form.
	 * @return int|null
	 */
	public static function row( $s, $l, $alike ) {
		foreach ( self::TABLE as $n => $row ) {
			if ( $row[0] !== $s || $row[1] !== $l ) {
				continue;
			}
			// Changed on both: row 5 unless the mapped forms are the same, then 6.
			if ( 5 === $n && $alike ) {
				continue;
			}
			return $n;
		}
		return null;
	}

	/* -------------------------------------------------------- no pair */

	/**
	 * The item of an entry of the match: an object without a pair (rule 4).
	 *
	 * @param array $entry An entry of AB_MCP_Sync_Proposal::match().
	 * @param array $lines Lines by side: kind NUL encoded ref => line.
	 * @return array
	 */
	private static function match_item( array $entry, array $lines ) {
		$s    = self::line_of( $entry['staging'], $entry['kind'], $lines['s'] );
		$l    = self::line_of( $entry['live'], $entry['kind'], $lines['l'] );
		$item = array(
			'kind'    => (string) $entry['kind'],
			'subtype' => (string) $entry['subtype'],
			's'       => $s,
			'l'       => $l,
			'pair'    => null,
			'shown'   => array(
				'staging' => $entry['staging'],
				'live'    => $entry['live'],
			),
		);
		switch ( $entry['category'] ) {
			case 'held':
				$item['sides'] = array(
					'staging' => array() === $entry['staging'] ? 'absent' : 'new',
					'live'    => array() === $entry['live'] ? 'absent' : 'new',
				);
				return self::result( $item, 'held', '4', null, 'none' );
			case 'not_compared':
				$item['sides'] = array(
					'staging' => null === $entry['staging'] ? 'absent' : 'new',
					'live'    => null === $entry['live'] ? 'absent' : 'new',
				);
				return self::result( $item, 'not_compared', '4', null, 'none', $entry['reason'] ?? null );
			case 'staging_only':
				$item['sides'] = array(
					'staging' => 'new',
					'live'    => 'absent',
				);
				if ( null === $s || ! isset( $s['hash'] ) || ! is_string( $s['hash'] ) || empty( $s['complete'] ) ) {
					return self::result( $item, 'not_compared', '4', 1, 'none', $entry['reason'] ?? 'The staging site did not read this object whole, so it is not created here. Make the inventory again on the staging site (wp_site_export).' );
				}
				return self::result( $item, 'only_staging', '4', 1, 'create' );
			case 'live_only':
				$item['sides'] = array(
					'staging' => 'absent',
					'live'    => 'new',
				);
				return self::result( $item, 'only_live', '4', 2, 'keep', $entry['reason'] ?? null );
		}
		// equal, differs, uncertain: the same identity new on both sides.
		$item['sides']    = array(
			'staging' => 'new',
			'live'    => 'new',
		);
		$item['proposal'] = $entry['category'];
		if ( isset( $entry['candidates'] ) ) {
			$item['shown']['candidates'] = $entry['candidates'];
		}
		return self::result( $item, 'new_both', '4', 3, 'pair', 'uncertain' === $entry['category'] ? ( $entry['reason'] ?? null ) : null );
	}

	/**
	 * The item of an object without a pair that stands alone: in the trash,
	 * or an auto-draft.
	 *
	 * @param string $side  s or l.
	 * @param array  $line  Line.
	 * @param bool   $trash In the trash (else an auto-draft).
	 * @return array
	 */
	private static function lone_item( $side, array $line, $trash ) {
		$item = array(
			'kind'    => (string) $line['kind'],
			'subtype' => self::subtype( $line ),
			's'       => 's' === $side ? $line : null,
			'l'       => 'l' === $side ? $line : null,
			'pair'    => null,
			'sides'   => array(
				'staging' => 's' === $side ? ( $trash ? 'deleted' : 'not_compared' ) : 'absent',
				'live'    => 'l' === $side ? ( $trash ? 'deleted' : 'not_compared' ) : 'absent',
			),
		);
		return $trash
			? self::result( $item, 'in_trash', '4', null, 'none' )
			: self::result( $item, 'not_compared', '4', null, 'none', 'An auto-draft WordPress made for the editor (an object nobody saved yet, removed by WordPress after seven days): no content, never taken over.' );
	}

	/**
	 * The line of a side of an entry of the match.
	 *
	 * @param mixed  $side  The side as the match shows it.
	 * @param string $kind  Kind.
	 * @param array  $lines kind NUL encoded ref => line.
	 * @return array|null
	 */
	private static function line_of( $side, $kind, array $lines ) {
		if ( ! is_array( $side ) || ! array_key_exists( 'ref', $side ) ) {
			return null;
		}
		return $lines[ $kind . "\0" . AB_MCP_Sync_Canon::encode( $side['ref'] ) ] ?? null;
	}

	/* ------------------------------------------------- operation locks */

	/**
	 * The locks of what a takeover would do (decisions 21, 25, 28): creating
	 * Additional CSS, or updating CSS this site does not link to its
	 * stylesheet; creating a term (or menu) with meta; a menu with an item
	 * removed on staging, as a whole; a widget area with an instance removed
	 * on staging, against the instances of its base.
	 *
	 * @param array[] $items Items (changed in place).
	 */
	private static function operation_locks( array &$items ) {
		$menus = array(
			'l' => array(),
			's' => array(),
		);
		foreach ( $items as $i => $item ) {
			if ( '0' === $item['rule'] ) {
				// A rest: nothing applies to it (rule 0), its own removal on
				// staging still holds its menu below.
				if ( 'menu_item' === $item['kind'] && 'deleted' === ( $item['sides']['staging'] ?? null ) && 'deleted' !== ( $item['sides']['live'] ?? null ) ) {
					foreach ( self::menus_of( $item['l'] ) as $menu ) {
						$menus['l'][ $menu ] = true;
					}
				}
				continue;
			}
			$writes = in_array( $item['action'], array( 'create', 'take_over', 'choose' ), true );
			if ( 'custom_css' === $item['kind'] && $writes && ( 'create' === $item['action'] || ! self::linked( $item['l'] ) ) ) {
				$items[ $i ]['locks'][] = self::lock( 'custom_css:create' );
			}
			if ( in_array( $item['kind'], array( 'term', 'menu' ), true ) && 'create' === $item['action'] && self::has_meta( $item['s'] ) ) {
				$items[ $i ]['locks'][] = self::lock( 'term:create_with_meta' );
			}
			if ( 'widget_area' === $item['kind'] && null !== $item['pair'] && 'changed' === ( $item['sides']['staging'] ?? null ) && self::removed_instances( $item ) ) {
				$items[ $i ]['locks'][] = self::lock( 'widget_area:remove_instance' );
			}
			// An item of a menu removed on staging: its menus, on both sides.
			if ( 'menu_item' === $item['kind'] && null !== $item['pair'] && 'deleted' === ( $item['sides']['staging'] ?? null ) && 'deleted' !== ( $item['sides']['live'] ?? null ) ) {
				foreach ( self::menus_of( $item['l'] ) as $menu ) {
					$menus['l'][ $menu ] = true;
				}
			}
		}
		if ( array() === $menus['l'] ) {
			return;
		}
		// The staging menus of those menus, by their pairs.
		foreach ( $items as $item ) {
			if ( 'menu' === $item['kind'] && null !== $item['pair'] && isset( $menus['l'][ (string) $item['pair']['live_ref'] ] ) ) {
				$menus['s'][ (string) $item['pair']['staging_ref'] ] = true;
			}
		}
		foreach ( $items as $i => $item ) {
			$hit = false;
			if ( '0' === $item['rule'] ) {
				continue;
			} elseif ( 'menu' === $item['kind'] ) {
				$hit = ( null !== $item['l'] && isset( $menus['l'][ (string) $item['l']['ref'] ] ) ) || ( null !== $item['s'] && isset( $menus['s'][ (string) $item['s']['ref'] ] ) );
			} elseif ( 'menu_item' === $item['kind'] ) {
				foreach ( self::menus_of( $item['l'] ) as $menu ) {
					$hit = $hit || isset( $menus['l'][ $menu ] );
				}
				foreach ( self::menus_of( $item['s'] ) as $menu ) {
					$hit = $hit || isset( $menus['s'][ $menu ] );
				}
			}
			if ( $hit ) {
				$items[ $i ]['locks'][] = self::lock( 'menu:remove_item' );
			}
		}
	}

	/**
	 * Whether this site links its CSS to the stylesheet (custom_css_post_id):
	 * only then is it updated (decision 25).
	 *
	 * @param array|null $line Line of this site.
	 * @return bool
	 */
	private static function linked( $line ) {
		return is_array( $line ) && ! isset( $line['held'] ) && ! empty( $line['identity']['linked'] );
	}

	/**
	 * Whether a staging object has meta: rows in its form, or a unit of meta
	 * left out as a credential.
	 *
	 * @param array|null $line Line.
	 * @return bool
	 */
	private static function has_meta( $line ) {
		if ( ! is_array( $line ) ) {
			return false;
		}
		if ( ! isset( $line['meta'] ) || ! is_int( $line['meta'] ) || $line['meta'] > 0 ) {
			// Without the count (a line of another version): fail closed.
			return true;
		}
		foreach ( isset( $line['b9'] ) && is_array( $line['b9'] ) ? $line['b9'] : array() as $at ) {
			if ( is_string( $at ) && 0 === strpos( $at, 'meta:' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether an instance of the base of a widget area is missing on staging
	 * now; true where the instances of either are not known (fail closed).
	 *
	 * @param array $item Item of a pair.
	 * @return bool
	 */
	private static function removed_instances( array $item ) {
		$base = $item['pair']['base_extra']['instances']['staging'] ?? null;
		$now  = is_array( $item['s'] ) ? ( $item['s']['instances'] ?? null ) : null;
		if ( ! is_array( $base ) || ! is_array( $now ) ) {
			return true;
		}
		$have = array();
		foreach ( $now as $id ) {
			$have[ AB_MCP_Sync_Canon::encode( $id ) ] = true;
		}
		foreach ( $base as $id ) {
			if ( ! isset( $have[ AB_MCP_Sync_Canon::encode( $id ) ] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The menus of a menu item line, as term ids of its site.
	 *
	 * @param array|null $line Line.
	 * @return string[]
	 */
	private static function menus_of( $line ) {
		if ( ! is_array( $line ) || isset( $line['held'] ) || ! isset( $line['identity']['menus'] ) || ! is_array( $line['identity']['menus'] ) ) {
			return array();
		}
		return array_map( 'strval', $line['identity']['menus'] );
	}

	/* ------------------------------------------------------------ graph */

	/**
	 * The necessary references between the entries (decision 16): edges to
	 * targets created in the same run, waits for targets that cannot be
	 * named on this site, mutual references locked, waiting passed on.
	 *
	 * @param array[] $items Items (changed in place).
	 * @param array[] $pairs Pairs.
	 */
	private static function graph( array &$items, array $pairs ) {
		$at_staging = array();
		foreach ( $items as $i => $item ) {
			$refs = array();
			if ( null !== $item['s'] && ! isset( $item['s']['held'] ) ) {
				$refs[] = (string) $item['s']['ref'];
			}
			// The staging candidates of an object new on both sides.
			foreach ( $item['shown']['candidates']['staging'] ?? array() as $candidate ) {
				if ( isset( $candidate['ref'] ) && is_string( $candidate['ref'] ) ) {
					$refs[] = $candidate['ref'];
				}
			}
			foreach ( self::SPACES as $space => $kinds ) {
				if ( in_array( $item['kind'], $kinds, true ) ) {
					foreach ( $refs as $ref ) {
						$at_staging[ $space ][ $ref ] = $i;
					}
				}
			}
		}
		$paired = array();
		foreach ( $pairs as $pair ) {
			foreach ( self::SPACES as $space => $kinds ) {
				if ( in_array( $pair['kind'], $kinds, true ) ) {
					$paired[ $space ][ (string) $pair['staging_ref'] ] = $pair;
				}
			}
		}
		$edges = array();
		$weak  = array();
		foreach ( $items as $i => $item ) {
			if ( ! in_array( $item['action'], array( 'take_over', 'create', 'choose' ), true ) || null === $item['s'] ) {
				continue;
			}
			foreach ( isset( $item['s']['refs'] ) && is_array( $item['s']['refs'] ) ? $item['s']['refs'] : array() as $ref ) {
				$space    = $ref['space'] ?? null;
				$strength = $ref['strength'] ?? null;
				if ( ! isset( self::SPACES[ $space ] ) || ! in_array( $strength, array( 'strong', 'weak' ), true ) ) {
					continue;
				}
				$id = (string) $ref['id'];
				$j  = $at_staging[ $space ][ $id ] ?? null;
				$p  = $paired[ $space ][ $id ] ?? null;
				$why = self::target( $items, $j, $p, $space, $id );
				if ( true === $why ) {
					continue;
				}
				if ( 'edge' === $why ) {
					// A weak reference (the parent of an attachment, decision 16)
					// is set by a step of its own after its target: no edge, so a
					// post and the image that hangs under it need no order of each
					// other (branch 10).
					if ( 'strong' === $strength ) {
						$edges[ $i ][ $j ] = true;
					} else {
						$weak[ $i ][ $j ] = true;
					}
					continue;
				}
				$items[ $i ]['waits'][] = array(
					'space' => $space,
					'id'    => (int) $ref['id'],
					'at'    => (string) ( $ref['at'] ?? '' ),
					'why'   => $why,
				);
			}
		}
		// Cycles of objects created in the run: none of them comes first.
		foreach ( self::cycles( $edges ) as $members ) {
			foreach ( $members as $i ) {
				$items[ $i ]['locks'][] = self::lock( '*:mutual_references' );
			}
		}
		// What needs a target that is held back waits for it, by a weak
		// reference as well (its target is not written then either).
		$back = array();
		foreach ( array( $edges, $weak ) as $of ) {
			foreach ( $of as $i => $targets ) {
				foreach ( array_keys( $targets ) as $j ) {
					$back[ $j ][] = $i;
				}
			}
		}
		$queue = array();
		$told  = array();
		foreach ( $items as $i => $item ) {
			if ( self::held_back( $item ) ) {
				$queue[] = $i;
			}
		}
		$done = array();
		while ( array() !== $queue ) {
			$j = array_shift( $queue );
			if ( isset( $done[ $j ] ) ) {
				continue;
			}
			$done[ $j ] = true;
			foreach ( $back[ $j ] ?? array() as $i ) {
				if ( isset( $told[ $i ][ $j ] ) ) {
					continue;
				}
				$told[ $i ][ $j ]      = true;
				$items[ $i ]['waits'][] = array(
					'space' => in_array( $items[ $j ]['kind'], self::SPACES['term'], true ) ? 'term' : 'post',
					'id'    => (int) $items[ $j ]['s']['ref'],
					'at'    => '',
					'why'   => sprintf( 'It needs the %1$s %2$s of the staging site, which is created in the same run but is held back itself (locked, or waiting for another).', $items[ $j ]['kind'], (string) $items[ $j ]['s']['ref'] ),
				);
				$queue[] = $i;
			}
		}
	}

	/**
	 * How a necessary reference to a staging object is named on this site:
	 * true where its pair names it, edge where it is created in the same
	 * run, else why the entry waits.
	 *
	 * @param array[]    $items Items.
	 * @param int|null   $j     The item of the target on the staging side.
	 * @param array|null $p     The pair of the target.
	 * @param string     $space post or term.
	 * @param string     $id    Its staging id.
	 * @return true|string
	 */
	private static function target( array $items, $j, $p, $space, $id ) {
		$what = null === $j ? $space . ' ' . $id : $items[ $j ]['kind'] . ' ' . $id;
		if ( null !== $p ) {
			if ( 'unclear' === $p['state'] ) {
				return sprintf( 'Its target, the %1$s of the staging site, has an unclear pair (pair %2$d): a person confirms which side wins first.', $what, (int) $p['id'] );
			}
			if ( 'deleted' === $p['state'] ) {
				return sprintf( 'Its target, the %1$s of the staging site, was taken over as deleted (pair %2$d).', $what, (int) $p['id'] );
			}
			if ( null === $j ) {
				return sprintf( 'Its target, the %1$s of its pair %2$d, is not in the inventory of the staging site (deleted there, or not read).', $what, (int) $p['id'] );
			}
			if ( 'deleted' === ( $items[ $j ]['sides']['live'] ?? null ) ) {
				return sprintf( 'Its target, the %1$s of the staging site, is gone on this site (pair %2$d).', $what, (int) $p['id'] );
			}
			if ( 'deleted' === ( $items[ $j ]['sides']['staging'] ?? null ) ) {
				return sprintf( 'Its target, the %1$s of the staging site, is in the trash there (pair %2$d).', $what, (int) $p['id'] );
			}
			return true;
		}
		if ( null === $j ) {
			return sprintf( 'Its target, the %s of the staging site, is not in its inventory: an object of a type the takeover does not read, or one withheld from the account that made the inventory. Until it is, this object is not taken over.', $what );
		}
		if ( 'create' === $items[ $j ]['action'] ) {
			return 'edge';
		}
		if ( 'new_both' === $items[ $j ]['result'] ) {
			return sprintf( 'Its target, the %s of the staging site, has no pair yet but an object of its identity on this site: pair them first (wp_site_pairs).', $what );
		}
		return sprintf( 'Its target, the %1$s of the staging site, has no pair and is not created here (%2$s).', $what, $items[ $j ]['result'] );
	}

	/**
	 * The groups of the edges that reach themselves (strongly connected,
	 * more than one, or one with an edge to itself): Tarjan, without
	 * recursion.
	 *
	 * @param array<int,array<int,true>> $edges From => targets.
	 * @return int[][]
	 */
	public static function cycles( array $edges ) {
		$index  = array();
		$low    = array();
		$stack  = array();
		$on     = array();
		$out    = array();
		$next   = 0;
		$nodes  = array_keys( $edges );
		foreach ( $edges as $targets ) {
			$nodes = array_merge( $nodes, array_keys( $targets ) );
		}
		foreach ( array_unique( $nodes ) as $start ) {
			if ( isset( $index[ $start ] ) ) {
				continue;
			}
			$work = array( array( $start, array_keys( $edges[ $start ] ?? array() ), 0 ) );
			$index[ $start ] = $next;
			$low[ $start ]   = $next;
			++$next;
			$stack[]        = $start;
			$on[ $start ]   = true;
			while ( array() !== $work ) {
				$top = count( $work ) - 1;
				list( $v, $targets, $k ) = $work[ $top ];
				if ( $k < count( $targets ) ) {
					$work[ $top ][2] = $k + 1;
					$w               = $targets[ $k ];
					if ( ! isset( $index[ $w ] ) ) {
						$index[ $w ] = $next;
						$low[ $w ]   = $next;
						++$next;
						$stack[] = $w;
						$on[ $w ] = true;
						$work[]  = array( $w, array_keys( $edges[ $w ] ?? array() ), 0 );
					} elseif ( isset( $on[ $w ] ) ) {
						$low[ $v ] = min( $low[ $v ], $index[ $w ] );
					}
					continue;
				}
				array_pop( $work );
				if ( array() !== $work ) {
					$parent         = $work[ count( $work ) - 1 ][0];
					$low[ $parent ] = min( $low[ $parent ], $low[ $v ] );
				}
				if ( $low[ $v ] === $index[ $v ] ) {
					$group = array();
					do {
						$w = array_pop( $stack );
						unset( $on[ $w ] );
						$group[] = $w;
					} while ( $w !== $v );
					if ( count( $group ) > 1 || isset( $edges[ $v ][ $v ] ) ) {
						sort( $group );
						$out[] = $group;
					}
				}
			}
		}
		return $out;
	}

	/**
	 * Whether an item a takeover would write is held back: locked, or
	 * waiting.
	 *
	 * @param array $item Item.
	 * @return bool
	 */
	private static function held_back( array $item ) {
		return in_array( $item['action'], array( 'create', 'take_over', 'choose' ), true ) && ( array() !== self::locks_of( $item ) || ! empty( $item['waits'] ) );
	}

	/**
	 * Every lock of an item: those of the lines of both sides (a held line
	 * names none) and those of what it would do, one per key.
	 *
	 * @param array $item Item.
	 * @return array[] key => key, stage, reason; by key.
	 */
	private static function locks_of( array $item ) {
		$locks = array();
		foreach ( array( 's', 'l' ) as $side ) {
			$line = $item[ $side ];
			foreach ( is_array( $line ) && ! isset( $line['held'] ) && isset( $line['locks'] ) && is_array( $line['locks'] ) ? $line['locks'] : array() as $lock ) {
				$locks[ (string) ( $lock['key'] ?? '' ) ] = array(
					'key'    => (string) ( $lock['key'] ?? '' ),
					'stage'  => $lock['stage'] ?? null,
					'reason' => $lock['reason'] ?? null,
				);
			}
		}
		foreach ( $item['locks'] ?? array() as $lock ) {
			$locks[ $lock['key'] ] = $lock;
		}
		ksort( $locks );
		return $locks;
	}

	/* ------------------------------------------------------------ finish */

	/**
	 * The entries of the items: the action once locks and waits are known,
	 * in the order of ACTION_ORDER, numbered; the counts and what is locked.
	 *
	 * @param array[] $items Items.
	 * @param array[] $pairs Pairs.
	 * @return array entries, counts, blocked, pairs.
	 */
	private static function finish( array $items, array $pairs ) {
		$entries = array();
		foreach ( $items as $item ) {
			$entries[] = self::entry( $item );
		}
		$order = array_flip( self::ACTION_ORDER );
		usort(
			$entries,
			static function ( $a, $b ) use ( $order ) {
				return array( $order[ $a['action'] ], $a['result'], $a['kind'], $a['sort'] ) <=> array( $order[ $b['action'] ], $b['result'], $b['kind'], $b['sort'] );
			}
		);
		$results = array();
		$actions = array_fill_keys( self::ACTION_ORDER, 0 );
		$blocked = array();
		foreach ( $entries as $n => $entry ) {
			unset( $entries[ $n ]['sort'] );
			$entries[ $n ] = array( 'n' => $n + 1 ) + $entries[ $n ];
			$results[ $entry['result'] ] = ( $results[ $entry['result'] ] ?? 0 ) + 1;
			++$actions[ $entry['action'] ];
			foreach ( $entry['locks'] ?? array() as $lock ) {
				if ( ! isset( $blocked[ $lock['key'] ] ) ) {
					$blocked[ $lock['key'] ] = $lock + array( 'entries' => 0 );
				}
				++$blocked[ $lock['key'] ]['entries'];
			}
		}
		ksort( $results );
		ksort( $blocked );
		$states = array_fill_keys( AB_MCP_Sync_Pairs::STATES, 0 );
		foreach ( $pairs as $pair ) {
			if ( isset( $states[ $pair['state'] ] ) ) {
				++$states[ $pair['state'] ];
			}
		}
		return array(
			'entries' => array_values( $entries ),
			'counts'  => array(
				'entries' => count( $entries ),
				'results' => $results,
				'actions' => $actions,
			),
			'blocked' => array_values( $blocked ),
			'pairs'   => $states,
		);
	}

	/**
	 * The entry of an item, as the report stores and shows it: no hash, no
	 * mapped form, no value; a held side by the SHA-256 of its ref.
	 *
	 * @param array $item Item.
	 * @return array
	 */
	private static function entry( array $item ) {
		$locks  = self::locks_of( $item );
		$action = $item['action'];
		// What a takeover would write, held back: locked, or waiting.
		if ( in_array( $action, array( 'take_over', 'create', 'delete', 'choose' ), true ) && array() !== $locks ) {
			$action = 'locked';
		} elseif ( in_array( $action, array( 'take_over', 'create', 'choose' ), true ) && ! empty( $item['waits'] ) ) {
			$action = 'waits';
		}
		$out = array(
			'kind'    => $item['kind'],
			'subtype' => $item['subtype'],
			'result'  => $item['result'],
			'rule'    => $item['rule'],
			'row'     => $item['row'],
			'action'  => $action,
			'sides'   => $item['sides'],
			'staging' => self::show( $item, 's' ),
			'live'    => self::show( $item, 'l' ),
			'pair'    => null === $item['pair'] ? null : array(
				'id'       => (int) $item['pair']['id'],
				'state'    => $item['pair']['state'],
				'override' => $item['pair']['override'],
				'last_run' => $item['pair']['last_run'],
			),
			'words'   => self::WORDS[ $item['result'] ],
		);
		if ( null !== $item['reason'] ) {
			$out['reason'] = $item['reason'];
		}
		if ( isset( $item['proposal'] ) ) {
			$out['proposal'] = $item['proposal'];
		}
		if ( isset( $item['shown']['candidates'] ) ) {
			$out['candidates'] = array(
				'staging' => array_map( array( __CLASS__, 'plain' ), $item['shown']['candidates']['staging'] ),
				'live'    => array_map( array( __CLASS__, 'plain' ), $item['shown']['candidates']['live'] ),
			);
		}
		if ( null !== $item['pair'] && null !== $item['pair']['override'] ) {
			$fields = array();
			foreach ( $item['pair']['override'] as $field => $value ) {
				$fields[] = $field . ' ' . ( is_bool( $value ) ? ( $value ? 'true' : 'false' ) : (string) $value );
			}
			$out['note'] = 'Compared against the confirmed target: taken over with ' . implode( ', ', $fields ) . '.';
		}
		if ( array() !== $locks ) {
			$out['locks'] = array_values( $locks );
		}
		if ( ! empty( $item['waits'] ) ) {
			$out['waits_for'] = $item['waits'];
		}
		if ( 'choose' === $action ) {
			$out['choices'] = array( 'staging_wins', 'keep_live', 'leave_open' );
		}
		$one         = null !== $item['s'] ? $item['s'] : $item['l'];
		$out['sort'] = AB_MCP_Sync_Canon::encode( array( is_array( $one ) && isset( $one['identity'] ) ? $one['identity'] : array(), is_array( $one ) ? ( $one['ref'] ?? ( $one['ref_sha256'] ?? '' ) ) : '', null === $item['pair'] ? 0 : (int) $item['pair']['id'] ) );
		return $out;
	}

	/**
	 * One side of an entry as shown: its ref, identity, status and the
	 * status of a post (and the path of parents and the instances of a widget
	 * area, as the match shows them); a held one by the SHA-256 of its ref;
	 * a side not there by the ref of its pair, or by the SHA-256 of it where
	 * this site calls the name a credential.
	 *
	 * @param array  $item Item.
	 * @param string $side s or l.
	 * @return array|null
	 */
	private static function show( array $item, $side ) {
		$shown = $item['shown'][ 's' === $side ? 'staging' : 'live' ] ?? null;
		$line  = $item[ $side ];
		if ( null === $line ) {
			if ( null !== $item['pair'] ) {
				$ref = (string) $item['pair'][ 's' === $side ? 'staging_ref' : 'live_ref' ];
				// A name this site calls a credential: by the SHA-256 of the
				// ref of its pair only, as a held line (pair_item()).
				if ( ! empty( $item['b9'] ) ) {
					return array(
						'held'       => 'credential_name',
						'ref_sha256' => hash( 'sha256', AB_MCP_Sync_Canon::encode( $ref ) ),
					);
				}
				return array( 'ref' => $ref );
			}
			if ( ! is_array( $shown ) || array() === $shown ) {
				return null;
			}
			return isset( $shown[0]['held'] ) ? $shown : self::plain( $shown );
		}
		if ( isset( $line['held'] ) ) {
			return array(
				'held'       => (string) $line['held'],
				'ref_sha256' => (string) $line['ref_sha256'],
			);
		}
		$out = array(
			'ref'      => $line['ref'],
			'identity' => $line['identity'] ?? array(),
			'status'   => (string) ( $line['status'] ?? '' ),
		);
		if ( isset( $line['post_status'] ) ) {
			$out['post_status'] = $line['post_status'];
		}
		if ( is_array( $shown ) && isset( $shown['path'] ) ) {
			$out['path'] = $shown['path'];
		}
		if ( 'widget_area' === $item['kind'] && isset( $line['instances'] ) ) {
			$out['instances'] = $line['instances'];
		}
		return $out;
	}

	/**
	 * A side of the match without its hashes and what only the match uses.
	 *
	 * @param mixed $side Side.
	 * @return mixed
	 */
	private static function plain( $side ) {
		if ( ! is_array( $side ) ) {
			return $side;
		}
		unset( $side['hash'], $side['mapped'], $side['mapped_possible'], $side['complete'], $side['locks'], $side['places'] );
		return $side;
	}

	/* ------------------------------------------------------------ units */

	/**
	 * The entry of a unit (or of the theme that runs) from its two lines of
	 * the environment and its pair (Z8b, E1, E2): equal only where every part
	 * is the same on both sides (the files by the hash of their manifest,
	 * AB_MCP_Sync_File_Manifest::unit(); the main files that run; the theme
	 * that runs); without a pair a difference is not attributed to a side;
	 * with an active pair each part against its own base (its own column or
	 * base_extra, AB_MCP_Sync_Pairs::part_bases()), by the table of a pair;
	 * the files changed on both sides are locked without a choice
	 * (code:live_changed, with the way «update by hand» for a plugin that
	 * keeps writing into its folder). What could not be read, a lock of a
	 * line (code:live_extra, code:link, code:theme_root) and AlphaBridge
	 * itself (code:own) are never compared by their files. A unit on one
	 * side only is only there (or removed, with an active pair) only where
	 * that side read it whole and holds no lock, and where the other side
	 * listed the folder it would lie in whole ($unread); else it is not
	 * compared, or locked by its lock. The files of a plugin or theme are
	 * taken over (unit_way(), unit_new()); the rest is locked with the lock
	 * of its kind (AB_MCP_Sync_Env::LOCKS).
	 * Where the files differ and both sides read them whole, the paths: only
	 * on staging, only here, other contents (never by mtime), every one of
	 * them with their counts (paged() shows MAX_PATHS a list in the entry and
	 * the others in entries after it).
	 *
	 * @param string     $kind  A kind of UNIT_KINDS.
	 * @param string     $ref   Its ref (the name of the unit).
	 * @param array|null $s     The line of the staging site.
	 * @param array|null $l     The line of this site.
	 * @param array|null $pair  Its pair (by kind and staging_ref), or null.
	 * @param array      $files 's' and 'l': the entries of each side (AB_MCP_Sync_Env::entries_of()), or null.
	 * @param array      $unread 's' and 'l': for the side without a line, why the folder it would lie in was not listed whole there (a code of AB_MCP_Sync_Env::UNIT_WHY), or null.
	 * @return array An entry of the environment.
	 */
	public static function unit_item( $kind, $ref, $s, $l, $pair, array $files, array $unread = array() ) {
		$entry = array(
			'kind'    => 'environment',
			'subtype' => (string) $kind,
			'ref'     => (string) $ref,
			'result'  => 'not_compared',
			'action'  => 'none',
			'staging' => null === $s ? null : self::unit_shown( $s ),
			'live'    => null === $l ? null : self::unit_shown( $l ),
		);
		$locks = array();
		$why   = null;
		$held  = $s['held'] ?? ( $l['held'] ?? null );
		$lock  = AB_MCP_Sync_Env::LOCKS[ $kind ] ?? null;
		if ( null !== $pair ) {
			$entry['pair'] = array(
				'id'    => (int) $pair['id'],
				'state' => (string) $pair['state'],
			);
		}
		// AlphaBridge itself (E12), on either side or both: shown by its
		// version, never hashed nor taken over, not even where it is on one
		// side only.
		if ( ! empty( $s['own'] ) || ! empty( $l['own'] ) ) {
			// A version not read is never the same (GitHub-Codex on ed1e512).
			foreach ( array( 's' => $s, 'l' => $l ) as $side => $line ) {
				if ( ! empty( $line['own'] ) && ! is_string( $line['version'] ?? null ) ) {
					$entry['action'] = 'locked';
					return self::unit_entry( $entry, array( self::code_lock( 'code:own' ) ), sprintf( 'The version of AlphaBridge itself could not be read on %s: it is not compared, and never taken over.', 's' === $side ? 'the staging site' : 'this site' ), $held );
				}
			}
			$same            = ! empty( $s['own'] ) && ! empty( $l['own'] ) && $s['version'] === $l['version'];
			$entry['result'] = $same ? 'same_value' : ( null === $s || null === $l ? ( null === $s ? 'only_live' : 'only_staging' ) : 'own_differs' );
			if ( ! $same ) {
				$entry['action'] = 'locked';
				$locks[]         = self::code_lock( 'code:own' );
			}
			return self::unit_entry( $entry, $locks, null, $held );
		}
		if ( null === $s || null === $l ) {
			$there   = null === $s ? $l : $s;
			$missing = $unread[ null === $s ? 's' : 'l' ] ?? null;
			if ( 'root' === ( $there['form'] ?? null ) ) {
				$why = self::unit_why( $there, null === $s ? 'this site' : 'the staging site' );
			} elseif ( isset( $there['lock'] ) && is_string( $there['lock'] ) ) {
				// Locked by what it holds on the side that has it: never only
				// there, never removed.
				$entry['action'] = 'locked';
				return self::unit_entry( $entry, array( self::code_lock( $there['lock'] ) ), null, $held );
			} elseif ( 'theme_choice' !== $kind && ( empty( $there['complete'] ) || null === ( $there['hash'] ?? null ) ) ) {
				return self::unit_entry( $entry, array(), self::unit_why( $there, null === $s ? 'this site' : 'the staging site' ), $held );
			} elseif ( null !== $missing ) {
				return self::unit_entry( $entry, array(), sprintf( 'Whether it is on %1$s is not known: the folder it would lie in could not be read whole there (%2$s).', null === $s ? 'the staging site' : 'this site', self::cause_said( $missing ) ), $held );
			} elseif ( null !== $pair && 'active' === $pair['state'] ) {
				// Removed on one side since its pair was made: the side that
				// has it, each part against its own base, so that a change
				// there is a conflict with the removal (TABLE, rows 7 and 9;
				// GitHub-Codex on free#147, P2).
				if ( $pair['canon'] !== AB_MCP_Sync_Canon::VERSION ) {
					return self::unit_entry( $entry, array(), sprintf( 'The bases of its pair are of the version %1$s of the comparison data, this site reads %2$s.', (string) $pair['canon'], AB_MCP_Sync_Canon::VERSION ), $held );
				}
				$states  = array();
				$changed = false;
				foreach ( AB_MCP_Sync_Pairs::PARTS[ $kind ] as $part ) {
					$have = self::part_hash( $kind, $part, $there );
					$base = AB_MCP_Sync_Pairs::part_bases( $pair, $part );
					if ( null === $have ) {
						return self::unit_entry( $entry, array(), 'theme_choice' === $kind ? 'The theme that runs could not be read on ' . ( null === $s ? 'this site' : 'the staging site' ) . '.' : 'Which main files run could not be read on ' . ( null === $s ? 'this site' : 'the staging site' ) . '.', $held );
					}
					if ( null === $base ) {
						return self::unit_entry( $entry, array(), 'Its pair holds no base of the part ' . $part . '.', $held );
					}
					$moved            = ! hash_equals( (string) $base[ null === $s ? 'live' : 'staging' ], $have );
					$changed          = $changed || $moved;
					$states[ $part ]  = self::TABLE[ null === $s ? ( $moved ? 7 : 3 ) : ( $moved ? 9 : 8 ) ][2];
				}
				$entry['parts']  = $states;
				$entry['result'] = self::TABLE[ null === $s ? ( $changed ? 7 : 3 ) : ( $changed ? 9 : 8 ) ][2];
				$entry['action'] = null === $s ? ( $changed ? 'keep' : 'locked' ) : ( $changed ? 'keep' : 'none' );
			} else {
				$entry['result'] = null === $s ? 'only_live' : 'only_staging';
				$entry['action'] = null === $s ? 'keep' : self::unit_new( $kind );
			}
			if ( 'locked' === $entry['action'] && null !== $lock ) {
				$locks[] = $lock;
			}
			return self::unit_entry( $entry, $locks, $why, $held );
		}
		// A line locked by what it holds: never compared by its files.
		foreach ( array( $s, $l ) as $line ) {
			if ( isset( $line['lock'] ) && is_string( $line['lock'] ) ) {
				$locks[] = self::code_lock( $line['lock'] );
			}
		}
		if ( array() !== $locks ) {
			$entry['action'] = 'locked';
			return self::unit_entry( $entry, $locks, null, $held );
		}
		foreach ( array( 's' => $s, 'l' => $l ) as $side => $line ) {
			if ( 'theme_choice' !== $kind && ( empty( $line['complete'] ) || null === ( $line['hash'] ?? null ) ) ) {
				return self::unit_entry( $entry, array(), self::unit_why( $line, 's' === $side ? 'the staging site' : 'this site' ), $held );
			}
		}
		$parts = AB_MCP_Sync_Pairs::PARTS[ $kind ];
		$have  = array();
		foreach ( $parts as $part ) {
			$have[ $part ] = array(
				's' => self::part_hash( $kind, $part, $s ),
				'l' => self::part_hash( $kind, $part, $l ),
			);
			if ( null === $have[ $part ]['s'] || null === $have[ $part ]['l'] ) {
				return self::unit_entry( $entry, array(), 'theme_choice' === $kind ? 'The theme that runs could not be read on ' . ( null === $have[ $part ]['s'] ? 'the staging site' : 'this site' ) . '.' : 'Which main files run could not be read on ' . ( null === $have[ $part ]['s'] ? 'the staging site' : 'this site' ) . '.', $held );
			}
		}
		$differs = array();
		foreach ( $parts as $part ) {
			if ( ! hash_equals( $have[ $part ]['s'], $have[ $part ]['l'] ) ) {
				$differs[] = $part;
			}
		}
		$states = null;
		if ( null === $pair ) {
			$entry['result'] = array() === $differs ? 'equal' : 'differs';
		} elseif ( 'active' !== $pair['state'] || AB_MCP_Sync_Canon::VERSION !== $pair['canon'] ) {
			return self::unit_entry( $entry, array(), 'active' !== $pair['state'] ? sprintf( 'Its pair is %s: a person confirms a new alignment first (wp_site_pairs).', (string) $pair['state'] ) : sprintf( 'The bases of its pair are of the version %1$s of the comparison data, this site reads %2$s.', (string) $pair['canon'], AB_MCP_Sync_Canon::VERSION ), $held );
		} else {
			$states = array();
			foreach ( $parts as $part ) {
				$base = AB_MCP_Sync_Pairs::part_bases( $pair, $part );
				if ( null === $base ) {
					// A part without a base yet (Z44): open, until a person
					// confirms it; the other parts are judged by their own.
					$states[ $part ] = 'open';
					continue;
				}
				$ss = hash_equals( $base['staging'], $have[ $part ]['s'] ) ? 'unchanged' : 'changed';
				$ls = hash_equals( $base['live'], $have[ $part ]['l'] ) ? 'unchanged' : 'changed';
				$row = self::row( $ss, $ls, ! in_array( $part, $differs, true ) );
				// Unchanged on both sides since bases that differ: the part
				// differs now, kept so (Frage F, c), never equal (Codex,
				// whole round over 4c66939..f25da74, A).
				$states[ $part ] = 1 === $row && in_array( $part, $differs, true ) ? 'kept_apart' : self::TABLE[ $row ][2];
			}
			$entry['parts'] = $states;
			foreach ( array( 'conflict', 'changed_on_staging', 'changed_live', 'changed_alike', 'open', 'kept_apart', 'equal' ) as $result ) {
				if ( in_array( $result, $states, true ) ) {
					$entry['result'] = $result;
					break;
				}
			}
		}
		if ( array() !== $differs ) {
			$entry['differs'] = $differs;
		}
		if ( in_array( 'files', $differs, true ) ) {
			$paths = self::unit_paths( $files['s'] ?? null, $files['l'] ?? null );
			if ( null !== $paths ) {
				$entry['paths'] = $paths;
			}
		}
		list( $entry, $locks ) = self::unit_way( $entry, $kind, $states, $lock );
		return self::unit_entry( $entry, $locks, null, $held );
	}

	/**
	 * What a takeover does with a unit both sides have (unit_item(), its
	 * last part): locked where its files changed on both sides
	 * (code:live_changed, no choice «staging wins»; E1); taken over where the
	 * files of a plugin or theme (SWAPPED) changed on staging and are
	 * unchanged here, and no part of it is in conflict (Z8b branch 5: the swap
	 * of the code, AB_MCP_Sync_Code_Swap, takes the files; which main files
	 * run stays with its own part and its own step); kept where only this
	 * site changed; else locked with the lock of its kind where the sides
	 * differ in what this version does not take over (a unit of another
	 * kind; of a plugin, which of its main files run).
	 *
	 * @param array       $entry  The entry so far (result, parts).
	 * @param string      $kind   A kind of UNIT_KINDS.
	 * @param array|null  $states The state of each part against its base; null without an active pair.
	 * @param array|null  $lock   The lock of the kind (AB_MCP_Sync_Env::LOCKS), or null.
	 * @return array The entry with its action, and its locks.
	 */
	private static function unit_way( array $entry, $kind, $states, $lock ) {
		$locks = array();
		$files = is_array( $states ) && isset( $states['files'] ) ? $states['files'] : null;
		if ( 'conflict' === $files ) {
			$locks[] = self::code_lock( 'code:live_changed' );
		}
		$swapped = in_array( $kind, self::SWAPPED, true );
		$takes   = $swapped && 'changed_on_staging' === $files && 'changed_on_staging' === $entry['result'];
		// Without a pair nothing says which side changed: no lock of a
		// stage for a kind the swap takes, the way is the pair (its words).
		$waits = $swapped ? ( ! $takes && in_array( $entry['result'], array( 'changed_on_staging', 'conflict' ), true ) ) : in_array( $entry['result'], array( 'differs', 'changed_on_staging', 'conflict' ), true );
		if ( $waits && null !== $lock ) {
			$locks[] = $lock;
		}
		if ( array() !== $locks ) {
			$entry['action'] = 'locked';
		} elseif ( $takes ) {
			$entry['action'] = 'take_over';
		} elseif ( 'changed_live' === $entry['result'] ) {
			$entry['action'] = 'keep';
		}
		return array( $entry, $locks );
	}

	/**
	 * What a takeover does with a unit only the staging site has, read whole
	 * there and without a pair: made here where the swap of the code makes
	 * such a unit (SWAPPED; Z8b branch 5, F5), else locked with the lock of
	 * its kind.
	 *
	 * @param string $kind A kind of UNIT_KINDS.
	 * @return string create or locked.
	 */
	private static function unit_new( $kind ) {
		return in_array( $kind, self::SWAPPED, true ) ? 'create' : 'locked';
	}

	/**
	 * An entry of a unit with its locks, its why, a held name and its words.
	 *
	 * @param array       $entry Entry.
	 * @param array[]     $locks Locks (key, stage, reason; art for those of the kinds).
	 * @param string|null $why   Why not compared.
	 * @param string|null $held  Why a name is held.
	 * @return array
	 */
	private static function unit_entry( array $entry, array $locks, $why, $held ) {
		$keyed = array();
		foreach ( $locks as $lock ) {
			$keyed[ $lock['key'] ] = array(
				'key'    => (string) $lock['key'],
				'stage'  => (string) $lock['stage'],
				'reason' => (string) $lock['reason'],
			);
		}
		ksort( $keyed );
		if ( array() !== $keyed ) {
			$entry['locks'] = array_values( $keyed );
		}
		if ( null !== $why ) {
			$entry['why'] = $why;
		}
		if ( null !== $held ) {
			$entry['held'] = (string) $held;
		}
		$words = self::UNIT_WORDS[ $entry['result'] ];
		if ( isset( $entry['differs'] ) ) {
			$words .= ' ' . self::DIFFERS_IN[ implode( ' ', $entry['differs'] ) ];
		}
		if ( ! empty( $entry['paths']['only_live'] ) ) {
			$words .= ' A path only on this site is not in the version of the staging site: a takeover puts the old folder whole into the backup, so the path leaves its place and comes back only through the way back.';
		}
		if ( 'locked' === $entry['action'] ) {
			$words .= ' Nothing of it is taken over in this version.';
		}
		if ( null !== $held ) {
			$words .= sprintf( ' Its name holds a credential by the rules of a site (held: %s), so it is shown by its SHA-256 only.', $held );
		}
		$entry['words'] = $words;
		return $entry;
	}

	/**
	 * A lock of a unit that no stage lifts (AB_MCP_Sync_Env::CODE_LOCKS).
	 *
	 * @param string $key Key.
	 * @return array
	 */
	private static function code_lock( $key ) {
		return array( 'key' => (string) $key ) + AB_MCP_Sync_Env::CODE_LOCKS[ $key ];
	}

	/**
	 * The hash of a part of a unit as its line says it: the files by the hash
	 * of the manifest; the main files of a plugin that run, and the theme that
	 * runs, by the SHA-256 of their canonical form; null where it is not read.
	 *
	 * @param string $kind Kind.
	 * @param string $part files or activation.
	 * @param array  $line Line.
	 * @return string|null
	 */
	public static function part_hash( $kind, $part, array $line ) {
		if ( 'files' === $part ) {
			return isset( $line['hash'] ) && is_string( $line['hash'] ) ? $line['hash'] : null;
		}
		if ( 'theme_choice' === $kind ) {
			if ( ! is_string( $line['stylesheet'] ?? null ) || ! is_string( $line['template'] ?? null ) ) {
				return null;
			}
			return AB_MCP_Sync_Canon::hash(
				array(
					'stylesheet' => $line['stylesheet'],
					'template'   => $line['template'],
				)
			);
		}
		if ( ! isset( $line['active'] ) || ! is_array( $line['active'] ) ) {
			return null;
		}
		$active = array_map( 'strval', $line['active'] );
		usort( $active, 'strcmp' );
		return AB_MCP_Sync_Canon::hash( array( 'active' => $active ) );
	}

	/**
	 * The paths in which the files of a unit differ between the sides
	 * (AB_MCP_Sync_File_Manifest::paths()), a path one side holds by its
	 * SHA-256 by that SHA-256 on the other side too; every path of each list,
	 * with the count of each (paged() shows them in parts). Null where a side
	 * has no entries.
	 *
	 * @param array[]|null $s Entries of the staging side.
	 * @param array[]|null $l Entries of this side.
	 * @return array|null
	 */
	private static function unit_paths( $s, $l ) {
		if ( ! is_array( $s ) || ! is_array( $l ) ) {
			return null;
		}
		$hashed = array();
		foreach ( array_merge( $s, $l ) as $entry ) {
			if ( 1 === preg_match( AB_MCP_Sync_Env::HASHED, (string) $entry[0] ) ) {
				$hashed[ (string) $entry[0] ] = true;
			}
		}
		$alike = static function ( array $entries ) use ( $hashed ) {
			foreach ( $entries as $i => $entry ) {
				$sha = AB_MCP_Sync_Env::hashed( (string) $entry[0] );
				if ( isset( $hashed[ $sha ] ) ) {
					$entries[ $i ][0] = $sha;
				}
			}
			return $entries;
		};
		$paths = AB_MCP_Sync_File_Manifest::paths( $alike( $s ), $alike( $l ) );
		$out   = array();
		foreach ( $paths as $key => $list ) {
			$out[ $key ]            = $list;
			$out[ $key . '_count' ] = count( $list );
		}
		ksort( $out );
		return $out;
	}

	/**
	 * Why a line of a unit was not compared, in words.
	 *
	 * @param array  $line  Line.
	 * @param string $where the staging site, or this site.
	 * @return string
	 */
	private static function unit_why( array $line, $where ) {
		return sprintf( 'It could not be read whole on %1$s (%2$s).', $where, self::cause_said( (string) ( $line['cause'] ?? '' ) ) );
	}

	/**
	 * Why a unit or a folder of units was not read whole, as a part of a
	 * sentence.
	 *
	 * @param string $code A code of AB_MCP_Sync_Env::UNIT_WHY.
	 * @return string
	 */
	private static function cause_said( $code ) {
		$say = array(
			'unreadable'             => 'a folder or file of it could not be read',
			'changed'                => 'a file of it changed while it was read',
			'too_large'              => 'it could not be hashed within one call',
			'too_many'               => 'it holds more files and folders than one manifest holds',
			'too_many_lines'         => 'its files do not fit into one package',
			'unknown'                => 'WordPress names no such folder',
			'by_code'                => 'code decides where these files lie, a filter or a network of sites',
			'not_a_folder'           => 'its place is no folder',
			'attachments_unreadable' => 'the file list of an attachment could not be read whole',
			'attachments_too_many'   => 'the file lists of the attachments are too many to hold within one call',
			'moved'                  => 'the unit the call before stopped at (kept by its SHA-256 only) is gone since',
		);
		return $say[ (string) $code ] ?? 'not known why';
	}

	/**
	 * The entry of a unit as the report holds it: each list of its paths with
	 * its first MAX_PATHS in the entry, the others in entries of their own
	 * right after it (result more_paths, action none, MAX_PATHS paths each,
	 * with the list and the number of the first), so that every path is in
	 * the report and a page of it stays small (the cursor of wp_site_diff
	 * reaches them like any entry). An entry without paths as it is.
	 *
	 * @param array $entry An entry of unit_item().
	 * @return array[] The entry, then the entries of its further paths.
	 */
	public static function paged( array $entry ) {
		if ( ! isset( $entry['paths'] ) ) {
			return array( $entry );
		}
		$more = array();
		foreach ( array( 'differs', 'only_live', 'only_staging' ) as $list ) {
			foreach ( array_chunk( array_slice( $entry['paths'][ $list ], self::MAX_PATHS ), self::MAX_PATHS ) as $i => $chunk ) {
				$one = array(
					'kind'    => 'environment',
					'subtype' => $entry['subtype'],
					'ref'     => $entry['ref'],
					'result'  => 'more_paths',
					'action'  => 'none',
					'staging' => null,
					'live'    => null,
					'paths'   => array(
						'from'  => self::MAX_PATHS * ( $i + 1 ) + 1,
						'list'  => $list,
						'paths' => $chunk,
					),
					'words'   => self::MORE_PATHS,
				);
				if ( isset( $entry['held'] ) ) {
					$one['held'] = $entry['held'];
				}
				$more[] = $one;
			}
			$entry['paths'][ $list ] = array_slice( $entry['paths'][ $list ], 0, self::MAX_PATHS );
		}
		if ( array() !== $more ) {
			$entry['paths']['more'] = count( $more );
			ksort( $entry['paths'] );
			$entry['words'] .= ' ' . self::PATHS_GO_ON;
		}
		return array_merge( array( $entry ), $more );
	}

	/**
	 * A side of a unit as the report shows it: no hash, no SHA-256, no mtime.
	 *
	 * @param array $line Line.
	 * @return array
	 */
	private static function unit_shown( array $line ) {
		unset( $line['env'], $line['ref'], $line['hash'], $line['sha256'], $line['mtime'], $line['held'] );
		foreach ( $line as $field => $value ) {
			if ( is_string( $value ) && 0 === strpos( $value, 'sha256:' ) ) {
				$line[ $field ] = 'not shown';
			}
		}
		return $line;
	}

	/* ---------------------------------------------------------- helpers */

	/**
	 * An item with its result.
	 *
	 * @param array       $item   Item.
	 * @param string      $result Result (WORDS).
	 * @param string      $rule   The rule of the order (0 to 4).
	 * @param int|null    $row    The row of its table.
	 * @param string      $action What a takeover does.
	 * @param string|null $reason Why, where it says more than its words.
	 * @return array
	 */
	private static function result( array $item, $result, $rule, $row, $action, $reason = null ) {
		return array_merge(
			array(
				'locks' => array(),
				'waits' => array(),
			),
			$item,
			array(
				'result' => $result,
				'rule'   => $rule,
				'row'    => $row,
				'action' => $action,
				'reason' => $reason,
			)
		);
	}

	/**
	 * A lock of the table, with its reason and stage.
	 *
	 * @param string $key Key of AB_MCP_Sync_Kinds::LOCKS.
	 * @return array
	 */
	private static function lock( $key ) {
		return array(
			'key'    => $key,
			'stage'  => AB_MCP_Sync_Kinds::LOCKS[ $key ]['stage'],
			'reason' => AB_MCP_Sync_Kinds::LOCKS[ $key ]['reason'],
		);
	}

	/**
	 * Whether the object of this site of a pair is missing because its name
	 * holds a credential by the rules of this site (B9: counted by its kind
	 * and the SHA-256 of its ref).
	 *
	 * @param array $pair Pair.
	 * @param array $ctx  context().
	 * @return bool
	 */
	private static function held_here( array $pair, array $ctx ) {
		return isset( $ctx['b9'][ $pair['kind'] . "\0" . hash( 'sha256', AB_MCP_Sync_Canon::encode( (string) $pair['live_ref'] ) ) ] );
	}

	/**
	 * Whether a line is of an object in the trash of its site (deleted, but
	 * there: where the difference counts, rule 0, rule 2, row 10).
	 *
	 * @param array|null $line Line.
	 * @return bool
	 */
	private static function trashed( $line ) {
		return is_array( $line ) && 'trash' === ( $line['post_status'] ?? null );
	}

	/**
	 * The subtype of a line: its post type or taxonomy, else ''; null for
	 * none (a held line, no line).
	 *
	 * @param array|null $line Line.
	 * @return string|null
	 */
	private static function subtype( $line ) {
		if ( ! is_array( $line ) || isset( $line['held'] ) || ! isset( $line['identity'] ) || ! is_array( $line['identity'] ) || array() === $line['identity'] ) {
			return null;
		}
		return (string) ( $line['identity']['post_type'] ?? ( $line['identity']['taxonomy'] ?? '' ) );
	}

	/**
	 * Lines by kind NUL the key of their ref: the encoded ref, and for a
	 * held line the SHA-256 of it.
	 *
	 * @param array[] $lines Lines.
	 * @return array<string,int>
	 */
	private static function index( array $lines ) {
		$out = array();
		foreach ( $lines as $i => $line ) {
			$kind = (string) ( $line['kind'] ?? '' );
			if ( isset( $line['held'] ) ) {
				$out[ $kind . "\0sha:" . (string) ( $line['ref_sha256'] ?? '' ) ] = $i;
				continue;
			}
			$out[ $kind . "\0" . AB_MCP_Sync_Canon::encode( $line['ref'] ) ] = $i;
		}
		return $out;
	}

	/**
	 * The line a pair names by its ref, or by the SHA-256 of it.
	 *
	 * @param array<string,int> $by   index().
	 * @param string            $kind Kind.
	 * @param string            $ref  Ref.
	 * @return int|null
	 */
	private static function lookup( array $by, $kind, $ref ) {
		$encoded = AB_MCP_Sync_Canon::encode( (string) $ref );
		foreach ( array( $encoded, 'sha:' . hash( 'sha256', $encoded ) ) as $key ) {
			if ( isset( $by[ $kind . "\0" . $key ] ) ) {
				return $by[ $kind . "\0" . $key ];
			}
		}
		return null;
	}

	/**
	 * Lines that are not held, by kind NUL the encoded ref.
	 *
	 * @param array[] $lines Lines.
	 * @return array<string,array>
	 */
	private static function by_ref( array $lines ) {
		$out = array();
		foreach ( $lines as $line ) {
			if ( ! isset( $line['held'] ) ) {
				$out[ $line['kind'] . "\0" . AB_MCP_Sync_Canon::encode( $line['ref'] ) ] = $line;
			}
		}
		return $out;
	}
}

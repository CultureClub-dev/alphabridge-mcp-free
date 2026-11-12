<?php
/**
 * The proposal of the pairs of units (Z8b branch 2b; plan E2, 3.1 Z44):
 * which plugin, theme and theme that runs of the staging site is the one of
 * this production site, and what a person confirms for it. A part of
 * AB_MCP_Sync_Proposal (wp_site_pairs action=propose with environment).
 *
 * THE TWO SIDES are read whole: the environment of the staging site as one
 * whole series of its packages (AB_MCP_Sync_Report::environment(), each
 * manifest checked again), the environment of this site to its end
 * (AB_MCP_Sync_Report::live_environment()), each unit by its manifest
 * (AB_MCP_Sync_File_Manifest::unit(), Z1). What each side is, without a
 * pair, decides AB_MCP_Sync_Env::compare() with AB_MCP_Sync_Decision::
 * unit_item(), as the report says it (Zweig 2).
 *
 * THE ENTRIES, by the categories of the proposal: equal (the same name and
 * every part the same: confirmed as active, each part equal); differs (the
 * same name, a part other: a part that differs takes a base only with the
 * mode a person chooses for it, after the differences are shown, Frage F: b
 * live_base, c keep_apart; part by part, the parts that are the same by
 * select; a part not chosen stays without a base, open, a); realign (a pair
 * of a unit in the state unclear or residual, every part anew the same way;
 * or an active pair with a part whose decision waits or can change: open,
 * kept apart, changed here, changed alike, in conflict, or changed on staging
 * by the base live_base set, with the states of its parts: a part chosen
 * anew, the others as they are); staging_only and live_only (on one side
 * only: no pair, no base made up for the other side); not_compared (a side
 * not read whole, a lock of what no manifest shows or of a further root of
 * themes, Z2, AlphaBridge itself, a name a pair cannot hold, with why); held
 * (a name a credential holds, by its SHA-256 only). Any other unit with an
 * active pair is not proposed again. Only plugin, theme and theme_choice are
 * proposed; the further kinds of units get their pairs with their branches.
 *
 * Each entry keeps the lines of both sides (lines, never shown), from which
 * the confirmation sets the bases (AB_MCP_Sync_Pairs::set_unit_base()), and
 * shows each side as the report does, without a hash, with the paths that
 * differ (the first PATHS of each list and how many; the report shows each).
 * The words of an entry stay English where they are stored, and are said in
 * the language of the site in an answer (AB_MCP_Sync_Said).
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-sync-env.php';
require_once __DIR__ . '/class-sync-pairs.php';

/**
 * Class AB_MCP_Sync_Unit_Proposal
 */
final class AB_MCP_Sync_Unit_Proposal {

	/** The kinds of units a proposal pairs (Z8b branch 2b). */
	const KINDS = array( 'plugin', 'theme', 'theme_choice' );

	/** Paths of an entry shown at most per list. */
	const PATHS = 10;

	/** What a person chooses for a unit whose parts differ (stored, said in an answer). */
	const CHOOSE = 'A part that differs is confirmed only with the mode a person chooses for it (wp_site_pairs action=confirm with units), part by part or together: live_base makes the state of this site of that part now the base of both sides, so the difference counts as a change on staging; keep_apart keeps the difference as it is now, and a later change counts again. A part not chosen stays as it is, without a base where it has none (unclear).';

	/** Where every path is (stored, said in an answer). */
	const ALL_PATHS = 'Each list of paths shows its first 10 here, with how many it holds; the report (wp_site_diff with environment) shows every path.';

	/** The states of a part with an active pair at which a decision of a person waits or can change. */
	const PENDING = array( 'open', 'kept_apart', 'conflict', 'changed_live', 'changed_alike' );

	/** Why a name is not paired (the words of the proposal of objects). */
	const NO_REF = 'Its name cannot be held by a pair (longer than 191 characters, a control character, or not UTF-8), so it is not paired. Take it over by hand.';

	/** Why AlphaBridge itself is not paired. */
	const OWN = 'AlphaBridge itself is never paired: it is shown by its version only and never taken over.';

	/** Why a locked unit is not paired (its locks with their reasons beside it). */
	const LOCKED = 'It is locked by what it holds or where it lies (see locks), so it is not paired.';

	/** Why a held name is not paired. */
	const HELD = 'A name of this unit holds a credential by the rules of a site, so it is shown by its SHA-256 only and gets no pair. Take it over by hand, or rename it on both sites.';

	/** Why a pair is not aligned anew where a side is not read whole. */
	const NOT_READY = 'A new alignment needs both sides of the unit read whole, each part of it. To end the pair, call wp_site_pairs with action=discard and its pair id.';

	/** Why a pair of a unit on neither side is not aligned anew. */
	const GONE = 'The unit is on neither site any more. To end the pair, call wp_site_pairs with action=discard and its pair id.';

	/**
	 * The entries of the units of both sides, and how many have an active
	 * pair (no database).
	 *
	 * @param array[] $staging The lines of the environment of the staging site.
	 * @param array[] $live    The lines of the environment of this site.
	 * @param array[] $pairs   The pairs of the source (AB_MCP_Sync_Pairs::decode()).
	 * @return array{entries:array[],paired:int} Entries with sort, not numbered.
	 */
	public static function entries( array $staging, array $live, array $pairs ) {
		$by_pair = array();
		foreach ( $pairs as $pair ) {
			if ( in_array( $pair['kind'], self::KINDS, true ) ) {
				$by_pair[ $pair['kind'] . "\0" . (string) $pair['staging_ref'] ] = $pair;
				$by_pair[ $pair['kind'] . "\0" . (string) $pair['live_ref'] ]    = $pair;
			}
		}
		$lines  = array(
			's' => self::by_ref( $staging ),
			'l' => self::by_ref( $live ),
		);
		// The units with an active pair as the report judges them, part by part.
		$active = array_values( array_filter( $pairs, static fn( $p ) => in_array( $p['kind'], self::KINDS, true ) && 'active' === $p['state'] ) );
		$judged = array();
		if ( array() !== $active ) {
			foreach ( AB_MCP_Sync_Env::compare( $staging, $live, $active )['entries'] as $one ) {
				if ( 'environment' === $one['kind'] && 'more_paths' !== $one['result'] && isset( $one['pair'] ) ) {
					$judged[ (int) $one['pair']['id'] ] = $one;
				}
			}
		}
		$seen   = array();
		$out    = array();
		$paired = 0;
		foreach ( AB_MCP_Sync_Env::compare( $staging, $live, array() )['entries'] as $said ) {
			$kind = (string) $said['subtype'];
			if ( 'environment' !== $said['kind'] || ! in_array( $kind, self::KINDS, true ) || 'more_paths' === $said['result'] ) {
				continue;
			}
			$ref  = (string) $said['ref'];
			$s    = $lines['s'][ $kind ][ $ref ] ?? null;
			$l    = $lines['l'][ $kind ][ $ref ] ?? null;
			$pair = $by_pair[ $kind . "\0" . $ref ] ?? null;
			if ( null !== $pair ) {
				$seen[ (int) $pair['id'] ] = true;
				if ( in_array( $pair['state'], array( 'unclear', 'residual' ), true ) ) {
					$out[] = self::realign( $kind, $ref, $said, $s, $l, $pair );
				} elseif ( 'active' === $pair['state'] ) {
					// A decision of a person waits or can change (a part open,
					// kept apart, changed here, changed alike, a conflict, or
					// the difference live_base made a change on staging):
					// offered part by part; else not proposed again.
					$states = $judged[ (int) $pair['id'] ]['parts'] ?? array();
					if ( self::waits( $states, $pair ) ) {
						$out[] = self::realign( $kind, $ref, $said, $s, $l, $pair ) + array( 'states' => $states );
					} else {
						++$paired;
					}
				}
				continue;
			}
			$out[] = self::unpaired( $kind, $ref, $said, $s, $l );
		}
		foreach ( $pairs as $pair ) {
			if ( in_array( $pair['kind'], self::KINDS, true ) && in_array( $pair['state'], array( 'unclear', 'residual' ), true ) && ! isset( $seen[ (int) $pair['id'] ] ) ) {
				$out[] = self::entry( 'realign', (string) $pair['kind'], (string) $pair['live_ref'], null, null ) + array(
					'pair'    => self::pair_of( $pair ),
					'confirm' => null,
					'reason'  => self::GONE,
				);
			}
		}
		return array(
			'entries' => $out,
			'paired'  => $paired,
		);
	}

	/**
	 * Whether a part of an active pair waits for a decision of a person or
	 * holds one that can change (PENDING, or changed on staging by the base
	 * live_base set).
	 *
	 * @param array $states Part => its state in the report.
	 * @param array $pair   The pair (decoded).
	 * @return bool
	 */
	private static function waits( array $states, array $pair ) {
		$modes = $pair['base_extra']['modes'] ?? array();
		foreach ( $states as $part => $state ) {
			if ( in_array( $state, self::PENDING, true ) || ( 'changed_on_staging' === $state && is_array( $modes ) && 'live_base' === ( $modes[ $part ] ?? null ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The entry of a unit without a pair.
	 *
	 * @param string     $kind Kind.
	 * @param string     $ref  Its name (its ref).
	 * @param array      $said Its entry of the environment without a pair.
	 * @param array|null $s    The line of the staging site.
	 * @param array|null $l    The line of this site.
	 * @return array
	 */
	private static function unpaired( $kind, $ref, array $said, $s, $l ) {
		$entry = self::entry( 'not_compared', $kind, $ref, $said['staging'], $said['live'] );
		$why   = self::why_not( $ref, $said, $s, $l );
		if ( null !== $why ) {
			return array_merge( $entry, $why );
		}
		if ( 'only_staging' === $said['result'] || 'only_live' === $said['result'] ) {
			$entry['category'] = 'only_staging' === $said['result'] ? 'staging_only' : 'live_only';
			return $entry;
		}
		if ( ! in_array( $said['result'], array( 'equal', 'differs' ), true ) || null === $s || null === $l ) {
			$entry['reason'] = isset( $said['why'] ) ? (string) $said['why'] : self::NOT_READY;
			return $entry;
		}
		$entry['category'] = $said['result'];
		return array_merge( $entry, self::compared( $kind, $said, $s, $l ) );
	}

	/**
	 * The entry of a pair of a unit in the state unclear or residual: every
	 * part anew, the same way as a new pair, where both sides are read whole.
	 *
	 * @param string     $kind Kind.
	 * @param string     $ref  Its name.
	 * @param array      $said Its entry of the environment without a pair.
	 * @param array|null $s    The line of the staging site.
	 * @param array|null $l    The line of this site.
	 * @param array      $pair The pair.
	 * @return array
	 */
	private static function realign( $kind, $ref, array $said, $s, $l, array $pair ) {
		$entry = self::entry( 'realign', $kind, $ref, $said['staging'], $said['live'] ) + array( 'pair' => self::pair_of( $pair ) );
		$why   = self::why_not( $ref, $said, $s, $l );
		if ( null !== $why || ! in_array( $said['result'], array( 'equal', 'differs' ), true ) || null === $s || null === $l || (string) $pair['live_ref'] !== $ref || (string) $pair['staging_ref'] !== $ref ) {
			$entry['confirm'] = null;
			$entry['reason']  = self::NOT_READY;
			if ( null !== $why ) {
				$entry['why'] = $why['reason'];
			} elseif ( isset( $said['why'] ) ) {
				$entry['why'] = $said['why'];
			}
			return $entry;
		}
		return array_merge( $entry, self::compared( $kind, $said, $s, $l ) );
	}

	/**
	 * Why a unit is not paired at all, as the category and reason of its
	 * entry; null where nothing keeps it from a pair.
	 *
	 * @param string     $ref  Its name.
	 * @param array      $said Its entry of the environment without a pair.
	 * @param array|null $s    The line of the staging site.
	 * @param array|null $l    The line of this site.
	 * @return array|null category, reason.
	 */
	private static function why_not( $ref, array $said, $s, $l ) {
		if ( isset( $said['held'] ) ) {
			return array(
				'category' => 'held',
				'reason'   => self::HELD,
			);
		}
		if ( ! AB_MCP_Sync_Pairs::valid_ref( $ref ) || 1 === preg_match( AB_MCP_Sync_Env::HASHED, $ref ) ) {
			return array(
				'category' => 'not_compared',
				'reason'   => self::NO_REF,
			);
		}
		if ( ! empty( $s['own'] ) || ! empty( $l['own'] ) ) {
			return array(
				'category' => 'not_compared',
				'reason'   => self::OWN,
			);
		}
		$locks = array();
		foreach ( $said['locks'] ?? array() as $lock ) {
			// A lock of what the unit holds (Z2), not the lock of the stage of
			// its kind (nothing of it is taken over in this version).
			if ( isset( AB_MCP_Sync_Env::CODE_LOCKS[ $lock['key'] ] ) && 'code:live_changed' !== $lock['key'] ) {
				$locks[] = $lock;
			}
		}
		if ( array() !== $locks ) {
			return array(
				'category' => 'not_compared',
				'reason'   => self::LOCKED,
				'locks'    => $locks,
			);
		}
		return null;
	}

	/**
	 * What an entry of a unit read whole on both sides says: each part equal
	 * or differs, what to confirm, the paths that differ, the lines.
	 *
	 * @param string $kind Kind.
	 * @param array  $said Its entry of the environment without a pair.
	 * @param array  $s    The line of the staging site.
	 * @param array  $l    The line of this site.
	 * @return array
	 */
	private static function compared( $kind, array $said, array $s, array $l ) {
		$differs = isset( $said['differs'] ) && is_array( $said['differs'] ) ? $said['differs'] : array();
		$parts   = array();
		foreach ( AB_MCP_Sync_Pairs::PARTS[ $kind ] as $part ) {
			$parts[ $part ] = in_array( $part, $differs, true ) ? 'differs' : 'equal';
		}
		$out = array(
			'parts'   => $parts,
			'confirm' => array() === $differs ? 'active' : 'modes',
			'lines'   => array(
				'staging' => $s,
				'live'    => $l,
			),
		);
		if ( array() !== $differs ) {
			$out['differs'] = $differs;
			$out['note']    = self::CHOOSE;
		}
		if ( isset( $said['paths'] ) && is_array( $said['paths'] ) ) {
			$paths = array();
			foreach ( array( 'differs', 'only_live', 'only_staging' ) as $list ) {
				$paths[ $list ]            = array_slice( (array) ( $said['paths'][ $list ] ?? array() ), 0, self::PATHS );
				$paths[ $list . '_count' ] = (int) ( $said['paths'][ $list . '_count' ] ?? 0 );
			}
			// Every path, with its count, in the report (more_paths).
			$paths['note'] = self::ALL_PATHS;
			$out['paths']  = $paths;
		}
		return $out;
	}

	/**
	 * An entry with its sides as the report shows them.
	 *
	 * @param string     $category Category.
	 * @param string     $kind     Kind.
	 * @param string     $ref      Its name.
	 * @param array|null $staging  The staging side as shown.
	 * @param array|null $live     This side as shown.
	 * @return array
	 */
	private static function entry( $category, $kind, $ref, $staging, $live ) {
		return array(
			'category' => $category,
			'kind'     => $kind,
			'subtype'  => '',
			'unit'     => true,
			'ref'      => $ref,
			'staging'  => $staging,
			'live'     => $live,
			'sort'     => "unit\0" . $kind . "\0" . $ref,
		);
	}

	/**
	 * A pair as an entry names it: its id, its state and the hash of its
	 * snapshot (the confirmation aligns it only as the proposal saw it).
	 *
	 * @param array $pair The pair.
	 * @return array
	 */
	private static function pair_of( array $pair ) {
		return array(
			'id'       => (int) $pair['id'],
			'state'    => (string) $pair['state'],
			'snapshot' => hash( 'sha256', AB_MCP_Sync_Proposal::pair_snapshot( $pair ) ),
		);
	}

	/**
	 * The lines of the units of a side by kind and name (no line of entries
	 * of the files, no page).
	 *
	 * @param array[] $lines Lines.
	 * @return array<string,array<string,array>>
	 */
	private static function by_ref( array $lines ) {
		$out = array();
		foreach ( $lines as $line ) {
			if ( is_array( $line ) && isset( $line['env'], $line['ref'] ) && in_array( $line['env'], self::KINDS, true ) ) {
				$out[ (string) $line['env'] ][ (string) $line['ref'] ] = $line;
			}
		}
		return $out;
	}
}

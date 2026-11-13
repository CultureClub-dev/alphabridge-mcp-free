<?php
/**
 * The plan of a run of the code (Z8b branch 5; plan E5, E8, E19, 3.1 Z24,
 * Z30, Z41, Z42): the units of code a person chose in a report, a plugin or
 * a theme of the staging site each, as the steps of a run of their own.
 *
 * A RUN OF THE CODE HOLDS NO STEP OF THE CONTENTS (E19, Z30): code can
 * change what the contents are compared by (a theme, the types a plugin
 * registers), so a run that swaps code ends after it, and the contents are
 * planned on a new report. of() is the rule: called by
 * AB_MCP_Sync_Push::plan() alone, the one place that makes the steps of a
 * run, it takes the plan over where the selection names a unit of code by
 * its number, and then the plan holds only steps of the code (and the frame
 * of every run: its check before, its caches and its check after). A
 * selection that names units and contents together is refused with the way;
 * all=true never plans a unit (AB_MCP_Sync_Push::verdict() names it as not
 * written).
 *
 * THE STEPS: for each unit one step that stages it («bereitstellen», over
 * as many calls as it takes, nothing of the site changes) and one that
 * swaps it («tauschen», one call), the swap after its stage
 * (AB_MCP_Sync_Code_Ops::run()).
 *
 * THE RELEASE (E8, Z24, the early refusal; Z41): a unit whose code runs on
 * this site (read fresh, AB_MCP_Sync_Code_Swap::runs(); not known counts as
 * running) is planned only with its number in accept. accept is part of the
 * selection, so the token of the plan is bound to it with every other
 * argument (AB_MCP_Confirm_Token::gate()), and to the state of the plan,
 * which holds each unit with whether it runs and whether it is released.
 *
 * WHAT A RUN OF THE CODE KEEPS (the pre-check of branch 5, V20): the
 * packages of the environment of the report (the signed manifest of every
 * unit is read from them until its swap) and the packages of code are bound
 * to the run (bind()), each binding checked; a run whose packages are not
 * all bound is not left open.
 *
 * Messages are in gettext, in the language of the site; their translations
 * come with text/z8b. Codes stay as the tools name them.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__ ) . '/class-shape.php';
require_once dirname( __DIR__ ) . '/class-store-inputs.php';
require_once dirname( __DIR__ ) . '/class-protected-store.php';
require_once __DIR__ . '/class-sync-canon.php';
require_once __DIR__ . '/class-sync-decision.php';
require_once __DIR__ . '/class-sync-import.php';
require_once __DIR__ . '/class-sync-pairs.php';
require_once __DIR__ . '/class-sync-resources.php';
require_once __DIR__ . '/class-sync-said.php';
require_once __DIR__ . '/class-sync-code-ops.php';
require_once __DIR__ . '/class-sync-code-stage.php';
require_once __DIR__ . '/class-sync-code-swap.php';

/**
 * Class AB_MCP_Sync_Code_Plan
 */
final class AB_MCP_Sync_Code_Plan {

	/** Bytes reserved for the journal of a step of the code. */
	const JOURNAL_BYTES = 65536;

	/** Paths a preview names of one list at most; the report holds every one. */
	const NAMED = 100;

	/**
	 * The plan of a run of the code, where the selection names a unit of
	 * code (a plugin or a theme of the report) by its number; null where it
	 * names none: the caller plans the contents.
	 *
	 * @param array $found     The report (AB_MCP_Sync_Report::find()).
	 * @param array $entries   The entries chosen, by their number.
	 * @param array $selection The selection in its one spelling: select, choices, as_draft, all, accept, packages.
	 * @param array $with      source, anchor, print, report_hash, plan_hash, existing.
	 * @return array|null|WP_Error A plan as AB_MCP_Sync_Push::plan() returns it, with code (env, packages, units) and no objects.
	 */
	public static function of( array $found, array $entries, array $selection, array $with ) {
		$units = array();
		foreach ( $selection['select'] as $n ) {
			if ( isset( $entries[ $n ] ) && 'environment' === $entries[ $n ]['kind'] && in_array( $entries[ $n ]['subtype'], AB_MCP_Sync_Decision::SWAPPED, true ) ) {
				$units[ $n ] = $entries[ $n ];
			}
		}
		$others = count( array_unique( array_merge( $selection['select'], array_keys( $selection['choices'] ), $selection['as_draft'] ) ) ) - count( $units );
		if ( array() === $units ) {
			return array() === $selection['accept'] ? null : self::refused( 'ab_mcp_sync_push_selection', __( 'accept names entries, but the selection names no plugin and no theme: accept releases the swap of a unit of code whose code runs on this site, by the number of its entry in select. Leave accept out, or select the unit.', 'alphabridge-mcp' ) );
		}
		if ( $selection['all'] || $others > 0 ) {
			return self::refused( 'ab_mcp_sync_push_code_mix', __( 'A run of the code holds no step of the contents: code can change what the contents are compared by (the theme, the types a plugin registers). Plan the plugins and themes alone (their numbers in select, without all, choices and as_draft), run that plan to its end, make a new report (wp_site_diff), then plan the contents.', 'alphabridge-mcp' ) );
		}
		foreach ( $selection['accept'] as $n ) {
			if ( ! isset( $units[ $n ] ) ) {
				/* translators: %d: entry number of the report. */
				return self::refused( 'ab_mcp_sync_push_selection', sprintf( __( 'Entry %d is in accept but is no plugin or theme chosen in select: accept releases a unit of code chosen there. Name it in select as well, or leave it out of accept.', 'alphabridge-mcp' ), $n ) );
			}
		}
		$env = isset( $found['head']['environment'] ) && is_array( $found['head']['environment'] ) ? array_values( $found['head']['environment'] ) : array();
		foreach ( $selection['packages'] as $id ) {
			$purpose = AB_MCP_Sync_Import::purpose_of( $id );
			if ( 'code' !== $purpose ) {
				/* translators: %s: id of a package. */
				return is_wp_error( $purpose ) ? $purpose : self::refused( 'ab_mcp_sync_push_packages', sprintf( __( 'The package %s is no package of code taken over here, and a run of the code takes only those (packages may stay empty where every unit comes from wordpress.org). Export the files the preview names on the staging site (wp_site_export with purpose=code and this report_hash), take the package over here (wp_site_import_chunk), then plan again.', 'alphabridge-mcp' ), $id ) );
			}
		}
		ksort( $units );
		$items  = array();
		$shown  = array();
		$locked = array();
		$pairs  = array();
		$bytes  = 0;
		$seq    = 1;
		foreach ( $units as $n => $entry ) {
			$made = self::unit( (int) $n, $entry, $env, $selection, $with );
			if ( is_wp_error( $made ) ) {
				return $made;
			}
			if ( isset( $made['locked'] ) ) {
				$locked[] = $made['locked'];
				continue;
			}
			$stage   = array( 'seq' => ++$seq, 'op' => 'stage', 'stage_seq' => null ) + $made['item'];
			$items[] = $stage;
			$items[] = array( 'seq' => ++$seq, 'op' => 'swap', 'stage_seq' => $stage['seq'] ) + $made['item'];
			$shown[] = array( 'stage' => $stage['seq'], 'swap' => $seq ) + $made['shown'];
			$bytes  += 2 * $made['bytes'] + 2 * self::JOURNAL_BYTES;
			if ( null !== $made['item']['pair_id'] ) {
				$pairs[ $made['item']['pair_id'] ] = $made['item']['pair_hash'];
			}
		}
		if ( array() === $items ) {
			/* translators: %s: why each unit chosen is not taken over, each with its entry number (one or more whole sentences). */
			return new WP_Error( 'ab_mcp_sync_push_nothing', sprintf( __( 'Nothing of the selection is taken over: no unit of code chosen is one a run swaps now. %s Use the way each of them names, then plan again (wp_site_push action=plan).', 'alphabridge-mcp' ), self::locked_said( $locked ) ), array( 'locked' => $locked ) );
		}
		$code = array(
			'env'      => $env,
			'packages' => $selection['packages'],
			'units'    => $items,
		);
		// Only a plan that reads back in the form its steps take is made.
		if ( null === AB_MCP_Store_Inputs::read( 'plan.code', $code ) ) {
			return self::refused( 'ab_mcp_sync_code_plan', __( 'The units of code of this plan do not read in the form a run of the code holds them (the packages of the environment of the report, a name or a hash of a unit), so no run is made. Take the environment of the staging site over anew, make a new report (wp_site_diff) and plan again.', 'alphabridge-mcp' ) );
		}
		$steps = array( array( 'op' => 'check', 'phase' => 1, 'depends' => array() ) );
		foreach ( $items as $item ) {
			$steps[] = array(
				'op'      => 'write',
				'phase'   => 2,
				'depends' => null === $item['stage_seq'] ? array( 1 ) : array( 1, $item['stage_seq'] ),
				'pair_id' => $item['pair_id'],
			);
		}
		$steps[]   = array( 'op' => 'post', 'phase' => 7, 'depends' => array( 1 ) );
		$steps[]   = array( 'op' => 'verify', 'phase' => 8, 'depends' => array( 1, $seq + 1 ) );
		$resources = AB_MCP_Sync_Resources::check( $bytes );
		if ( AB_MCP_Sync_Resources::INSUFFICIENT === $resources['level'] ) {
			return AB_MCP_Sync_Resources::refusal( $resources );
		}
		ksort( $pairs );
		$plan = array(
			'report_id'   => $found['gid'],
			'report_hash' => $with['report_hash'],
			'source_id'   => $with['source'],
			'key_id'      => (string) $with['anchor']['key_id'],
			'pair_print'  => $with['print'],
			'selection'   => $selection,
			'packages'    => $selection['packages'],
			'objects'     => array(),
			'code'        => $code,
			'code_shown'  => $shown,
			'steps'       => $steps,
			'locked'      => $locked,
			'skipped'     => array(),
			'resources'   => $resources,
			'environment' => true,
			'bytes'       => $bytes,
			'plan_hash'   => $with['plan_hash'],
			// What the token confirms: each unit with its step, what is done
			// to it, the unit of the staging site by its hash, its pair as it
			// is, whether its code runs and whether it is released.
			'state'       => hash(
				'sha256',
				AB_MCP_Sync_Canon::encode(
					array(
						'code'        => $code,
						'pairs'       => $pairs,
						'print'       => $with['print'],
						'report_hash' => $with['report_hash'],
						'selection'   => $selection,
					)
				)
			),
		);
		if ( null !== $with['existing'] ) {
			$plan['existing_run'] = $with['existing'];
		}
		return $plan;
	}

	/**
	 * One unit chosen: what a run does with it (item, with what the preview
	 * shows of it and the bytes it reserves), or why it does nothing
	 * (locked); the refusal of the whole plan where its pair is gone or not
	 * active any more (as for an object).
	 *
	 * @param int      $n         Its entry.
	 * @param array    $entry     The entry of the report.
	 * @param string[] $env       The packages of the environment of the report.
	 * @param array    $selection The selection.
	 * @param array    $with      As of().
	 * @return array|WP_Error item, shown, bytes; or locked.
	 */
	private static function unit( $n, array $entry, array $env, array $selection, array $with ) {
		$kind   = (string) $entry['subtype'];
		$ref    = (string) $entry['ref'];
		$action = (string) $entry['action'];
		$lock   = static function ( $reason, $stage = null ) use ( $entry, $n ) {
			return array(
				'locked' => array(
					'n'       => $n,
					'kind'    => 'environment',
					'subtype' => (string) $entry['subtype'],
					'action'  => (string) $entry['action'],
					'result'  => (string) $entry['result'],
					'reason'  => (string) $reason,
					'stage'   => $stage,
				),
			);
		};
		if ( ! in_array( $action, array( 'take_over', 'create' ), true ) ) {
			$first = isset( $entry['locks'][0] ) && is_array( $entry['locks'][0] ) ? $entry['locks'][0] : null;
			if ( 'locked' === $action ) {
				return $lock( null === $first ? __( 'Locked in the report.', 'alphabridge-mcp' ) : AB_MCP_Sync_Said::text( (string) $first['reason'] ), null === $first ? null : (string) $first['stage'] );
			}
			/* translators: %s: the action of the entry, as the report names it (keep, …). */
			return $lock( sprintf( __( 'The report says %s for this entry: a takeover writes nothing for it.', 'alphabridge-mcp' ), $action ) );
		}
		$pair_id   = null;
		$pair_hash = null;
		if ( 'take_over' === $action ) {
			$pair_id = isset( $entry['pair']['id'] ) && is_int( $entry['pair']['id'] ) ? $entry['pair']['id'] : 0;
			$pair    = AB_MCP_Sync_Pairs::get( $pair_id );
			if ( is_wp_error( $pair ) || null === $pair ) {
				/* translators: 1: pair number, 2: entry number of the report. */
				return new WP_Error( 'ab_mcp_sync_push_pair', sprintf( __( 'The pair %1$d of entry %2$d is no longer there. Make a new report (wp_site_diff) and plan again.', 'alphabridge-mcp' ), $pair_id, $n ) );
			}
			if ( 'active' !== $pair['state'] || $kind !== $pair['kind'] || $ref !== $pair['live_ref'] || $with['source'] !== $pair['source_id'] ) {
				/* translators: 1: pair number, 2: entry number of the report, 3: the state of the pair, as the tools name it (unclear, residual, …). */
				return new WP_Error( 'ab_mcp_sync_push_pair', sprintf( __( 'The pair %1$d of entry %2$d is %3$s since the report, and only an active pair is written over. Make a new report (wp_site_diff) and plan again.', 'alphabridge-mcp' ), $pair_id, $n, (string) $pair['state'] ) );
			}
			$snapshot = AB_MCP_Sync_Pairs::snapshot( $pair_id );
			if ( ! is_string( $snapshot ) ) {
				return is_wp_error( $snapshot ) ? $snapshot : new WP_Error( 'ab_mcp_sync_push_pair', sprintf( __( 'The pair %1$d of entry %2$d is no longer there. Make a new report (wp_site_diff) and plan again.', 'alphabridge-mcp' ), $pair_id, $n ) );
			}
			$pair_hash = AB_MCP_Sync_Pairs::snapshot_hash( $snapshot );
		}
		$signed = AB_MCP_Sync_Code_Stage::signed( $env, $kind, $ref );
		if ( is_wp_error( $signed ) ) {
			return $lock( $signed->get_error_message() );
		}
		$here    = isset( $entry['live'] ) && is_array( $entry['live'] ) && array_key_exists( 'version', $entry['live'] ) ? $entry['live']['version'] : null;
		$unit_op = 'create';
		if ( 'take_over' === $action ) {
			$order = '' === $signed['version'] && '' === $here ? 'same' : AB_MCP_Sync_Code_Ops::order( $signed['version'], $here );
			if ( null === $order ) {
				return $lock( __( 'The version of this unit is not known on one of the two sites (its head names none on one side only, or names one that is no version), so whether the staging site has an older version is not known and it is not taken over (code:version_unknown). Give the plugin or theme a Version in its head on both sites, make a new report (wp_site_diff) and plan again; or update it by hand in wp-admin.', 'alphabridge-mcp' ) );
			}
			if ( 'older' === $order ) {
				/* translators: 1: the version on the staging site, 2: the version on this site. */
				return $lock( sprintf( __( 'The staging site has an older version of this unit (%1$s) than this site (%2$s), and a takeover never goes down a version (code:downgrade). Update it on the staging site, take its environment over anew, make a new report (wp_site_diff) and plan again.', 'alphabridge-mcp' ), (string) $signed['version'], (string) $here ) );
			}
			$unit_op = 'same' === $order ? 'edit' : 'update';
		}
		// Whether code of the unit runs here, read fresh; not known counts as
		// running (never taken over unreleased on a row that did not read).
		$runs     = 'create' === $unit_op ? false : AB_MCP_Sync_Code_Swap::runs( $kind, $ref );
		$released = in_array( $n, $selection['accept'], true );
		if ( false !== $runs && ! $released ) {
			return $lock(
				'theme' === $kind
					/* translators: 1: entry number of the report, 2: the folder of the theme. */
					? sprintf( __( 'Entry %1$d swaps code this site runs: the theme %2$s is the theme that runs or its parent (or whether it runs could not be read). It is taken over only with its number in accept, by which the person releases code of it to run in the swap, its new version too; files come back through the way back, what its routines change in the database does not. Pass accept with this number, or leave the entry out.', 'alphabridge-mcp' ), $n, $ref )
					/* translators: 1: entry number of the report, 2: the folder or file of the plugin. */
					: sprintf( __( 'Entry %1$d swaps code this site runs: the plugin %2$s is active (or whether it is could not be read). It is taken over only with its number in accept, by which the person releases code of it to run in the swap, its new version too; files come back through the way back, what its routines change in the database does not. Pass accept with this number, or leave the entry out.', 'alphabridge-mcp' ), $n, $ref )
			);
		}
		$need = AB_MCP_Sync_Code_Stage::need( $env, $kind, $ref );
		if ( is_wp_error( $need ) ) {
			return $lock( $need->get_error_message() );
		}
		if ( true === $need['same'] ) {
			return $lock( __( 'This site already has the unit as the staging site has it (the same files), so nothing of it is taken over. Make a new report (wp_site_diff): where it still shows the unit as changed, confirm its pair anew (wp_site_pairs).', 'alphabridge-mcp' ) );
		}
		$gone  = isset( $entry['paths']['only_live'] ) && is_array( $entry['paths']['only_live'] ) ? array_values( array_filter( $entry['paths']['only_live'], 'is_string' ) ) : array();
		$count = isset( $entry['paths']['only_live_count'] ) && is_int( $entry['paths']['only_live_count'] ) ? $entry['paths']['only_live_count'] : count( $gone );
		return array(
			'item'  => array(
				'n'            => $n,
				'kind'         => $kind,
				'ref'          => $ref,
				'unit_op'      => $unit_op,
				'pair_id'      => $pair_id,
				'pair_hash'    => $pair_hash,
				'staging_hash' => $signed['hash'],
				'version'      => array(
					'staging' => $signed['version'],
					'live'    => $here,
				),
				'runs'         => false !== $runs,
				'accept'       => $released,
			),
			'shown' => array(
				'entry'     => $n,
				'kind'      => $kind,
				'ref'       => $ref,
				'operation' => 'create' === $unit_op ? 'create' : ( false !== $runs ? 'swap_running_code' : 'swap' ),
				'version'   => array(
					'here'    => $here,
					'staging' => $signed['version'],
				),
				'runs'      => false !== $runs,
				'accept'    => $released,
				'source'    => array() === $selection['packages'] ? 'wordpress.org' : 'packages',
				'need'      => array(
					'files' => count( $need['paths'] ),
					'bytes' => $need['bytes'],
					'paths' => array_slice( $need['paths'], 0, self::NAMED ),
				),
				'to_backup' => array(
					'count' => $count,
					'paths' => array_slice( $gone, 0, self::NAMED ),
				),
			),
			'bytes' => $signed['bytes'],
		);
	}

	/**
	 * The units of a plan of the code as the preview shows them (Z42, through
	 * AB_MCP_Sync_Push::shown()): per entry the operation, the version here
	 * and on the staging site, whether its code runs and is released, where
	 * its files come from and which ones the stage needs, the sentence on
	 * the routines, and the paths this site has that the new version lacks,
	 * with where they lie afterwards.
	 *
	 * @param array $plan A plan of of().
	 * @return array[]
	 */
	public static function shown( array $plan ) {
		$out = array();
		foreach ( isset( $plan['code_shown'] ) && is_array( $plan['code_shown'] ) ? $plan['code_shown'] : array() as $unit ) {
			$unit['says']               = __( 'Files come back through the way back; what the routines of the new version change in the database does not.', 'alphabridge-mcp' );
			$unit['to_backup']['says'] = 0 === $unit['to_backup']['count']
				? __( 'Every path of this unit on this site is in the new version as well.', 'alphabridge-mcp' )
				: __( 'These paths lie on this site and are not in the new version: after the swap each of them lies in the backup, back through the way back. The report lists every one of them (the entry of the unit and the entries after it).', 'alphabridge-mcp' );
			$unit['need']['says']      = 'wordpress.org' === $unit['source']
				? __( 'No package of code was passed: the unit comes from wordpress.org in the exact version of the staging site, taken only where it is the unit the report compared. Where it is not from wordpress.org, export these paths on the staging site (wp_site_export with purpose=code, this unit and its paths; without paths every file), take the package over here and pass it in packages.', 'alphabridge-mcp' )
				: __( 'The unit is built from the packages of code passed and from the files this site has already; the stage needs these paths from a package.', 'alphabridge-mcp' );
			$out[]                      = $unit;
		}
		return $out;
	}

	/**
	 * The unit of a step of a plan of the code as the preview names it with
	 * the step (AB_MCP_Sync_Push::shown()); null for a step of no unit.
	 *
	 * @param array $plan A plan.
	 * @param int   $seq  The step.
	 * @return array|null entry, kind, ref, op (stage or swap).
	 */
	public static function step( array $plan, $seq ) {
		foreach ( isset( $plan['code']['units'] ) && is_array( $plan['code']['units'] ) ? $plan['code']['units'] : array() as $unit ) {
			if ( $seq === $unit['seq'] ) {
				return array(
					'entry' => $unit['n'],
					'kind'  => $unit['kind'],
					'ref'   => $unit['ref'],
					'op'    => $unit['op'],
				);
			}
		}
		return null;
	}

	/**
	 * Bind what a run of the code reads until its swaps to the run (V20): the
	 * packages of the environment of its report and its packages of code,
	 * each binding checked.
	 *
	 * @param array $plan   A plan of of().
	 * @param int   $run_id The run made of it.
	 * @return true|WP_Error
	 */
	public static function bind( array $plan, $run_id ) {
		$groups = AB_MCP_Protected_Store::groups( 'in' );
		if ( is_wp_error( $groups ) ) {
			return $groups;
		}
		$by_label = array();
		foreach ( $groups as $group ) {
			$by_label[ (string) $group['label'] ] = (string) $group['gid'];
		}
		foreach ( array_merge( $plan['code']['env'], $plan['code']['packages'] ) as $id ) {
			$label = AB_MCP_Sync_Import::LABEL . $id;
			$bound = isset( $by_label[ $label ] ) ? AB_MCP_Protected_Store::bind( 'in', $by_label[ $label ], $run_id ) : null;
			if ( true !== $bound ) {
				/* translators: %s: id of a package. */
				return is_wp_error( $bound ) ? $bound : self::refused( 'ab_mcp_sync_code_bind', sprintf( __( 'The package %s of this plan is no longer in the protected store, so the run of the code cannot keep it until its swaps. Take the environment (and the packages of code) of the staging site over anew, make a new report (wp_site_diff) and plan again.', 'alphabridge-mcp' ), $id ) );
			}
		}
		return true;
	}

	/**
	 * The first locks of a plan in words, each with its entry.
	 *
	 * @param array[] $locked Locks.
	 * @return string
	 */
	private static function locked_said( array $locked ) {
		$out = array();
		foreach ( array_slice( $locked, 0, 5 ) as $lock ) {
			/* translators: 1: entry number of the report, 2: why it is not taken over (one or more whole sentences). */
			$out[] = sprintf( __( 'Entry %1$d: %2$s', 'alphabridge-mcp' ), (int) $lock['n'], (string) $lock['reason'] );
		}
		return implode( ' ', $out );
	}

	/**
	 * A refusal of a plan of the code.
	 *
	 * @param string $code    Code.
	 * @param string $message Message with the way.
	 * @return WP_Error
	 */
	private static function refused( $code, $message ) {
		return new WP_Error( $code, $message );
	}
}

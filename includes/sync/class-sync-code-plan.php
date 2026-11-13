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
require_once __DIR__ . '/class-sync-env.php';
require_once __DIR__ . '/class-sync-import.php';
require_once __DIR__ . '/class-sync-pairs.php';
require_once __DIR__ . '/class-sync-proposal.php';
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

	/**
	 * The fields of the entry of a unit the plan decides on, in the form
	 * AB_MCP_Sync_Decision writes them: the kind of the unit, its folder or
	 * file, what the report does with it. Other fields are read where they
	 * are used.
	 */
	const ENTRY = array(
		'type'   => 'record',
		'fields' => array(
			'subtype' => array(
				'type'      => 'string',
				'empty'     => false,
				'max_bytes' => null,
				'in'        => AB_MCP_Sync_Decision::SWAPPED,
			),
			'ref'     => array(
				'type'      => 'string',
				'empty'     => false,
				'max_bytes' => null,
			),
			'action'  => array(
				'type'      => 'string',
				'empty'     => false,
				'max_bytes' => null,
			),
		),
		'extra'  => true,
	);

	/** A group of the area in as the store lists it: its id and its label, the packages of a plan are found by. */
	const GROUP = array(
		'type'   => 'record',
		'fields' => array(
			'gid'   => AB_MCP_Store_Inputs::TABLE['sidecar.gid']['form'],
			'label' => AB_MCP_Store_Inputs::TABLE['sidecar.label']['form'],
		),
		'extra'  => true,
	);

	/** The pair the entry of a unit that is taken over names: its id, 1 or more. */
	const PAIR = array(
		'type'   => 'record',
		'fields' => array(
			'pair' => array(
				'type'   => 'record',
				'fields' => array(
					'id' => array(
						'type'   => 'int',
						'digits' => false,
						'min'    => 1,
						'max'    => null,
					),
				),
				'extra'  => true,
			),
		),
		'extra'  => true,
	);

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
		if ( $selection['all'] || $others > 0 || array() !== $selection['choices'] || array() !== $selection['as_draft'] ) {
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
			$made = self::unit( $found, $n, $entry, $env, $selection, $with );
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
			// The packages of objects and media of the run: none. Its packages
			// of code are in code (code.packages), read by the stage alone, so
			// the check of the run (AB_MCP_Sync_Engine::lines()) never takes
			// one for a package of objects (S1 of the handover of branch 5).
			'packages'    => array(),
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
	 * @param array    $found     The report.
	 * @param int      $n         Its entry.
	 * @param array    $entry     The entry of the report.
	 * @param string[] $env       The packages of the environment of the report.
	 * @param array    $selection The selection.
	 * @param array    $with      As of().
	 * @return array|WP_Error item, shown, bytes; or locked.
	 */
	private static function unit( array $found, $n, array $entry, array $env, array $selection, array $with ) {
		$read = self::entry_of( $entry, $n );
		if ( is_wp_error( $read ) ) {
			return $read;
		}
		$kind   = $read['subtype'];
		$ref    = $read['ref'];
		$action = $read['action'];
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
			$pair = self::pair_of( $entry, $n, $kind, $ref, $with );
			if ( is_wp_error( $pair ) ) {
				return $pair;
			}
			list( $pair_id, $pair_hash ) = $pair;
		}
		$signed = AB_MCP_Sync_Code_Stage::signed( $env, $kind, $ref );
		if ( is_wp_error( $signed ) ) {
			return $lock( $signed->get_error_message() );
		}
		$here    = null;
		$unit_op = 'create';
		if ( 'take_over' === $action ) {
			$unit_op = self::version_op( $signed['version'], $entry );
			if ( is_wp_error( $unit_op ) ) {
				return $lock( $unit_op->get_error_message() );
			}
			$here = $entry['live']['version'];
		}
		// Whether code of the unit runs here, read fresh, for a unit made too
		// (a row of active_plugins can name a plugin whose folder is gone, and
		// it runs once the folder is there again). Not known is no state
		// accept releases: the unit is locked with its own reason and way
		// (the decision of the lead on reading 3, 13.11.).
		$runs = AB_MCP_Sync_Code_Swap::runs( $kind, $ref );
		if ( null === $runs ) {
			return $lock(
				'theme' === $kind
					/* translators: 1: entry number of the report, 2: the folder of the theme. */
					? sprintf( __( 'Whether the theme %2$s of entry %1$d runs on this site could not be read (the options stylesheet and template do not read in the form WordPress writes them, or the theme was switched while they were read), so it is not taken over, with accept or without (code:runs_unknown): accept releases code that is read to run, never a state not read. Check the theme of this site in wp-admin (Appearance), then make a new report (wp_site_diff) and plan again.', 'alphabridge-mcp' ), $n, $ref )
					/* translators: 1: entry number of the report, 2: the folder or file of the plugin. */
					: sprintf( __( 'Whether the plugin %2$s of entry %1$d runs on this site could not be read (the option active_plugins does not read in the form WordPress writes it), so it is not taken over, with accept or without (code:runs_unknown): accept releases code that is read to run, never a state not read. Check the plugins of this site in wp-admin (deactivating and activating one makes WordPress write the option anew), then make a new report (wp_site_diff) and plan again.', 'alphabridge-mcp' ), $n, $ref )
			);
		}
		$released = in_array( $n, $selection['accept'], true );
		if ( $runs && ! $released ) {
			return $lock(
				'theme' === $kind
					/* translators: 1: entry number of the report, 2: the folder of the theme. */
					? sprintf( __( 'Entry %1$d swaps code this site runs: the theme %2$s is the theme that runs or its parent. It is taken over only with its number in accept, by which the person releases code of it to run in the swap, its new version too; files come back through the way back, what its routines change in the database does not. Pass accept with this number, or leave the entry out.', 'alphabridge-mcp' ), $n, $ref )
					/* translators: 1: entry number of the report, 2: the folder or file of the plugin. */
					: sprintf( __( 'Entry %1$d swaps code this site runs: the plugin %2$s is active. It is taken over only with its number in accept, by which the person releases code of it to run in the swap, its new version too; files come back through the way back, what its routines change in the database does not. Pass accept with this number, or leave the entry out.', 'alphabridge-mcp' ), $n, $ref )
			);
		}
		$need = AB_MCP_Sync_Code_Stage::need( $env, $kind, $ref );
		if ( is_wp_error( $need ) ) {
			return $lock( $need->get_error_message() );
		}
		if ( true === $need['same'] ) {
			return $lock( __( 'This site already has the unit as the staging site has it (the same files), so nothing of it is taken over. Make a new report (wp_site_diff): where it still shows the unit as changed, confirm its pair anew (wp_site_pairs).', 'alphabridge-mcp' ) );
		}
		// Every path of the unit here that the new version lacks, named in the
		// preview (Z42, E1, F47; reading 4; A1 of Codex round 1 on the code):
		// none where the unit is made, else read now and those of the report.
		$gone = 'create' === $unit_op ? array() : self::to_backup( $found, $n, $entry, $signed['entries'], $need['here'] );
		if ( is_wp_error( $gone ) ) {
			return $lock( $gone->get_error_message() );
		}
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
				'runs'         => $runs,
				'accept'       => $released,
			),
			'shown' => array(
				'entry'     => $n,
				'kind'      => $kind,
				'ref'       => $ref,
				'operation' => 'create' === $unit_op ? 'create' : ( $runs ? 'swap_running_code' : 'swap' ),
				'version'   => array(
					'here'    => $here,
					'staging' => $signed['version'],
				),
				'runs'      => $runs,
				'accept'    => $released,
				'source'    => array() === $selection['packages'] ? 'wordpress.org' : 'packages',
				'need'      => array(
					'files' => count( $need['paths'] ),
					'bytes' => $need['bytes'],
					'paths' => $need['paths'],
				),
				'to_backup' => array(
					'count' => count( $gone ),
					'paths' => $gone,
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
				: __( 'These paths lie on this site and are not in the new version: after the swap each of them lies in the backup, back through the way back.', 'alphabridge-mcp' );
			$unit['need']['says']      = 'wordpress.org' === $unit['source']
				? __( 'No package of code was passed: the unit comes from wordpress.org in the exact version of the staging site, taken only where it is the unit the report compared. Where it is not from wordpress.org, export these paths on the staging site (wp_site_export with purpose=code, this unit and its paths; without paths every file), take the package over here and pass it in packages.', 'alphabridge-mcp' )
				: __( 'The unit is built from the packages of code passed and from the files this site has already; the stage needs these paths from a package.', 'alphabridge-mcp' );
			$out[]                      = $unit;
		}
		return $out;
	}

	/**
	 * The fields of the entry of a unit that decide its step (which unit,
	 * what the report does with it), read in the form a report of this
	 * version writes them (ENTRY), never cast; the refusal of the plan with
	 * the way where they do not read so.
	 *
	 * @param array $entry The entry.
	 * @param int   $n     Its number.
	 * @return array|WP_Error subtype, ref, action (and the other fields as they are).
	 */
	private static function entry_of( array $entry, $n ) {
		$read = AB_MCP_Shape::read( $entry, self::ENTRY );
		if ( null === $read ) {
			/* translators: %d: entry number of the report. */
			return self::refused( 'ab_mcp_sync_push_report', sprintf( __( 'Entry %d of the report does not read in the form a report of this version writes (its subtype, ref or action), so no unit of it is planned and nothing of this site changed. Make a new report (wp_site_diff) and plan again.', 'alphabridge-mcp' ), $n ) );
		}
		return $read;
	}

	/**
	 * The paths of the unit of an entry that lie on this site and are not in
	 * the version of the staging site, as the preview names them before a
	 * swap (Z42, reading 4; A1 of Codex round 1 on the code of branch 5, the
	 * decision of the lead of 13.11.): read now from the files of the unit
	 * here (AB_MCP_Sync_Code_Stage::need(), the reading of the stage), each
	 * path whose name the signed manifest of the staging site lacks, and
	 * named only where they are the paths of the report (gone()), name by
	 * name. Names are compared by their SHA-256 (name_key()): a path read
	 * here is a name the report holds as it is or, where a rule holds it,
	 * by its SHA-256 (AB_MCP_Sync_Env::path_name()). Named then as the
	 * report names them, a name a rule holds by its SHA-256.
	 *
	 * Locked with its own reason where the report does not hold them whole
	 * (code:paths_unknown), where the files here are not read whole
	 * (code:paths_unread: not known, never «none»), and where they are not
	 * the paths of the report, one added here or gone since
	 * (code:paths_changed).
	 *
	 * @param array         $found  The report.
	 * @param int           $n      The entry of the unit.
	 * @param array         $entry  The entry.
	 * @param array[]       $signed The entries of the signed manifest of the unit (AB_MCP_Sync_Code_Stage::signed()).
	 * @param string[]|null $here   The paths of the unit here as read now (AB_MCP_Sync_Code_Stage::need()), null where not read whole.
	 * @return string[]|WP_Error
	 */
	private static function to_backup( array $found, $n, array $entry, array $signed, $here ) {
		$said = self::gone( $found, $n, $entry );
		if ( null === $said ) {
			return self::refused( 'code:paths_unknown', __( 'The report does not hold every path of this unit that lies on this site and is not in the version of the staging site (its list only_live and the entries more_paths after it do not read whole), and the preview names each of them before a swap, so it is not taken over (code:paths_unknown). Make a new report (wp_site_diff) and plan again.', 'alphabridge-mcp' ) );
		}
		if ( null === $here ) {
			return self::refused( 'code:paths_unread', __( 'The files of this unit on this site could not be read whole now, and the preview names each path of it that is not in the version of the staging site before a swap, so it is not taken over (code:paths_unread). Where the time of this call did not suffice, plan again (wp_site_push action=plan). Where the lock stays, a file or folder of the plugin or theme here cannot be read: make it readable, then plan again; where the unit is no longer on this site, make a new report (wp_site_diff).', 'alphabridge-mcp' ) );
		}
		$there = array();
		foreach ( $signed as $one ) {
			$there[ self::name_key( $one[0] ) ] = true;
		}
		$now = array();
		foreach ( $here as $path ) {
			if ( ! isset( $there[ AB_MCP_Sync_Env::hashed( $path ) ] ) ) {
				$now[ AB_MCP_Sync_Env::hashed( $path ) ] = true;
			}
		}
		$then = array();
		foreach ( $said as $path ) {
			$then[ self::name_key( $path ) ] = true;
		}
		ksort( $now, SORT_STRING );
		ksort( $then, SORT_STRING );
		if ( $now !== $then ) {
			return self::refused( 'code:paths_changed', __( 'The paths of this unit on this site that are not in the version of the staging site are not those of the report any more (a file was added here or removed since the report was made), and the preview names each of them before a swap, so it is not taken over (code:paths_changed). Make a new report (wp_site_diff), then plan again.', 'alphabridge-mcp' ) );
		}
		return $said;
	}

	/**
	 * A name of a path as a line holds it, by its SHA-256: a name of the form
	 * of a hash is one already (AB_MCP_Sync_Env::path_name() writes a name a
	 * rule holds so, and never leaves a name of that form as it is), another
	 * one is hashed. Both spellings of one path have the same.
	 *
	 * @param string $name A path as a line names it.
	 * @return string
	 */
	private static function name_key( $name ) {
		if ( 1 === preg_match( AB_MCP_Sync_Env::HASHED, $name ) ) {
			return $name;
		}
		return AB_MCP_Sync_Env::hashed( $name );
	}

	/**
	 * Every path of the unit of an entry that lies on this site and is not in
	 * the version of the staging site (Z42), in the order of the report: its
	 * list only_live, the first ones in the entry, the others in the entries
	 * of result more_paths right after it (AB_MCP_Sync_Decision::paged()),
	 * as many as only_live_count names. Null where the report does not hold
	 * them so (no list, an entry after it missing or of another unit, a gap):
	 * not known, never «none».
	 *
	 * @param array $found The report.
	 * @param int   $n     The entry of the unit.
	 * @param array $entry The entry.
	 * @return string[]|null
	 */
	private static function gone( array $found, $n, array $entry ) {
		if ( ! isset( $entry['paths'] ) || ! is_array( $entry['paths'] ) ) {
			return null;
		}
		$paths = $entry['paths'];
		if ( ! isset( $paths['only_live'], $paths['only_live_count'] ) || ! is_array( $paths['only_live'] ) || ! is_int( $paths['only_live_count'] ) ) {
			return null;
		}
		$gone = array_values( $paths['only_live'] );
		// more names the entries more_paths right after the unit, a whole
		// number of 1 or more, and is there only where there are such entries
		// (AB_MCP_Sync_Decision::paged()); without it the lists of the entry
		// are whole, which the count below checks. Another value is no form.
		if ( array_key_exists( 'more', $paths ) ) {
			if ( ! is_int( $paths['more'] ) || $paths['more'] < 1 ) {
				return null;
			}
			$gone = self::gone_after( $found, $n, $entry, $paths['more'], $gone );
			if ( null === $gone ) {
				return null;
			}
		}
		if ( count( $gone ) !== $paths['only_live_count'] || array() !== array_filter( $gone, static fn( $path ) => ! is_string( $path ) || '' === $path ) ) {
			return null;
		}
		return $gone;
	}

	/**
	 * The paths of the list only_live in the entries more_paths after the
	 * entry of a unit, appended to those of the entry in their order; null
	 * where an entry is missing, of another unit or leaves a gap.
	 *
	 * @param array    $found The report.
	 * @param int      $n     The entry of the unit.
	 * @param array    $entry The entry.
	 * @param int      $more  The entries after it (1 or more).
	 * @param string[] $gone  The paths of the entry.
	 * @return array|null
	 */
	private static function gone_after( array $found, $n, array $entry, $more, array $gone ) {
		$next = AB_MCP_Sync_Proposal::entries( $found, range( $n + 1, $n + $more ) );
		if ( is_wp_error( $next ) ) {
			return null;
		}
		for ( $i = $n + 1; $i <= $n + $more; $i++ ) {
			if ( ! isset( $next[ $i ] ) || ! is_array( $next[ $i ] ) ) {
				return null;
			}
			$one = $next[ $i ];
			if ( ! isset( $one['result'], $one['subtype'], $one['ref'], $one['paths']['list'], $one['paths']['from'], $one['paths']['paths'] ) || 'more_paths' !== $one['result'] || $entry['subtype'] !== $one['subtype'] || $entry['ref'] !== $one['ref'] || ! is_array( $one['paths']['paths'] ) ) {
				return null;
			}
			if ( 'only_live' !== $one['paths']['list'] ) {
				continue;
			}
			if ( count( $gone ) + 1 !== $one['paths']['from'] ) {
				return null;
			}
			foreach ( $one['paths']['paths'] as $path ) {
				$gone[] = $path;
			}
		}
		return $gone;
	}

	/**
	 * The operation of a takeover of a unit by its two versions (E9, Z12):
	 * the same version on both sides is an edit, a newer one on the staging
	 * site an update. A version not known on either side (none, empty or
	 * not of the row unit.version, the decision of the lead on reading 2,
	 * 13.11.) and an older one on the staging site lock the unit.
	 *
	 * @param mixed $staging The version of the signed line of the staging site.
	 * @param array $entry   The entry of the report: live.version, the version here.
	 * @return string|WP_Error edit or update; the reason of the lock (code:version_unknown, code:downgrade).
	 */
	public static function version_op( $staging, array $entry ) {
		if ( isset( $entry['live'] ) && is_array( $entry['live'] ) && array_key_exists( 'version', $entry['live'] ) ) {
			$order = AB_MCP_Sync_Code_Ops::order( $staging, $entry['live']['version'] );
			if ( 'same' === $order ) {
				return 'edit';
			}
			if ( 'newer' === $order ) {
				return 'update';
			}
			if ( 'older' === $order ) {
				/* translators: 1: the version on the staging site, 2: the version on this site. */
				return new WP_Error( 'code:downgrade', sprintf( __( 'The staging site has an older version of this unit (%1$s) than this site (%2$s), and a takeover never goes down a version (code:downgrade). Update it on the staging site, take its environment over anew, make a new report (wp_site_diff) and plan again.', 'alphabridge-mcp' ), $staging, $entry['live']['version'] ) );
			}
		}
		return new WP_Error( 'code:version_unknown', __( 'The version of this unit is not known on this site, on the staging site or on both (its head names none, or names one that is no version), so whether the staging site has an older version is not known and it is not taken over (code:version_unknown). Give the plugin or theme a Version in its head on both sites, make a new report (wp_site_diff) and plan again; or update it by hand in wp-admin.', 'alphabridge-mcp' ) );
	}

	/**
	 * The pair of a unit that is taken over, as the plan binds it: the pair
	 * the entry names (its id in the form of PAIR), active and of this unit
	 * and source, with the hash of its snapshot; the refusal of the plan with
	 * the way where the entry names none in its form or the pair is gone or
	 * changed (as for an object).
	 *
	 * @param array  $entry The entry of the report.
	 * @param int    $n     Its number.
	 * @param string $kind  plugin or theme.
	 * @param string $ref   The unit.
	 * @param array  $with  As of().
	 * @return array|WP_Error pair_id, pair_hash.
	 */
	private static function pair_of( array $entry, $n, $kind, $ref, array $with ) {
		$read = AB_MCP_Shape::read( $entry, self::PAIR );
		if ( null === $read ) {
			/* translators: %d: entry number of the report. */
			return new WP_Error( 'ab_mcp_sync_push_pair', sprintf( __( 'Entry %d of the report names no pair in the form a report of this version writes it (a whole number of 1 or more), so its unit is not taken over and nothing of this site changed. Make a new report (wp_site_diff) and plan again.', 'alphabridge-mcp' ), $n ) );
		}
		$pair_id = $read['pair']['id'];
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
		return array( $pair_id, AB_MCP_Sync_Pairs::snapshot_hash( $snapshot ) );
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
		$by_label = self::by_label( $groups );
		if ( is_wp_error( $by_label ) ) {
			return $by_label;
		}
		foreach ( array_merge( $plan['code']['env'], $plan['code']['packages'] ) as $id ) {
			$label = AB_MCP_Sync_Import::LABEL . $id;
			$bound = null;
			if ( array_key_exists( $label, $by_label ) ) {
				$bound = AB_MCP_Protected_Store::bind( 'in', $by_label[ $label ], $run_id );
			}
			if ( true !== $bound ) {
				/* translators: %s: id of a package. */
				return is_wp_error( $bound ) ? $bound : self::refused( 'ab_mcp_sync_code_bind', sprintf( __( 'The package %s of this plan is no longer in the protected store, so the run of the code cannot keep it until its swaps. Take the environment (and the packages of code) of the staging site over anew, make a new report (wp_site_diff) and plan again.', 'alphabridge-mcp' ), $id ) );
			}
		}
		return true;
	}

	/**
	 * The groups of the area in by their label, each read in the form of
	 * GROUP (its id and its label), never cast (the decision of the lead of
	 * 13.11. on 1a-3, question 2); the refusal where one does not read so.
	 *
	 * @param array[] $groups The groups (AB_MCP_Protected_Store::groups()).
	 * @return array<string,string>|WP_Error Label => gid.
	 */
	private static function by_label( array $groups ) {
		$by_label = array();
		foreach ( $groups as $group ) {
			$read = AB_MCP_Shape::read( $group, self::GROUP );
			if ( null === $read ) {
				return self::refused( 'ab_mcp_sync_code_bind', __( 'A group of the packages taken over does not read in the form of the protected store (its id or its label), so the packages of this plan are not bound to the run and no run is made. Ask the host to check the folder of the protected store; then make a new report (wp_site_diff) and plan again.', 'alphabridge-mcp' ) );
			}
			$by_label[ $read['label'] ] = $read['gid'];
		}
		return $by_label;
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

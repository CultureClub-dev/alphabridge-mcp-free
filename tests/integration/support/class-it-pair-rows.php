<?php
/**
 * A row of the table of the pairs put there as it is, past
 * AB_MCP_Sync_Pairs (Z8b branch 2b). Test code only.
 *
 * Since branch 2b the bases of the pair of a unit are set only by
 * AB_MCP_Sync_Pairs::set_unit_base() (Z44): from the lines of both sides read
 * whole, with the confirmation of a person or the proof of a step. A case
 * that judges a unit against bases it names (the decision of a part, the
 * moving on of a part by rebase(), a pair in the state unclear) arranges its
 * pair here, as a row that a confirmation or a step had left. No case of the
 * public way uses it: the normal case F55 makes its pairs through
 * wp_site_pairs.
 *
 * @package AlphaBridge_MCP
 */

/**
 * Class AB_MCP_IT_Pair_Rows
 */
final class AB_MCP_IT_Pair_Rows {

	/**
	 * Put a row of a pair: the fields as create() takes them (base_extra as
	 * an array), state active and the time now where not named.
	 *
	 * @param array $fields Fields.
	 * @return array The pair as AB_MCP_Sync_Pairs::get() reads it.
	 */
	public static function put( array $fields ) {
		global $wpdb;
		$row = array_merge(
			array(
				'state'      => 'active',
				'override'   => null,
				'base_extra' => null,
				'base_time'  => time(),
				'last_run'   => null,
			),
			$fields
		);
		foreach ( array( 'override', 'base_extra' ) as $column ) {
			$row[ $column ] = null === $row[ $column ] ? null : AB_MCP_Sync_Pairs::encode_json( $column, $row[ $column ] );
			if ( is_wp_error( $row[ $column ] ) ) {
				throw new RuntimeException( $row[ $column ]->get_error_message() );
			}
		}
		$row['updated'] = time();
		$done           = $wpdb->insert( AB_MCP_Sync_Schema::table( 'pairs' ), $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test code.
		if ( false === $done ) {
			throw new RuntimeException( 'The row of the pair was not written: ' . $wpdb->last_error );
		}
		$pair = AB_MCP_Sync_Pairs::get( (int) $wpdb->insert_id );
		if ( ! is_array( $pair ) ) {
			throw new RuntimeException( 'The row of the pair was not read back.' );
		}
		return $pair;
	}

	/**
	 * Put a closed run of a source with one step, as its journal holds it
	 * (Behebung 1 of branch 2b, A2 and A3: the proof of a step that made a
	 * unit, AB_MCP_Sync_Unit_Proof::of_step(), and the state before a step a
	 * unit goes back to, AB_MCP_Sync_Pairs::restore()). The engine writes
	 * such a step with branch 5; here the row is put as it is.
	 *
	 * @param string      $source      The source of the run.
	 * @param array|null  $result      The result of the step (unit: kind, ref, written, live_after_write, checked).
	 * @param int|null    $pair_id     The pair of the step.
	 * @param string|null $pair_before The snapshot of the pair before the step.
	 * @param string      $state       The state of the step.
	 * @return array{run_id:int,seq:int}
	 */
	public static function journal( $source, $result, $pair_id = null, $pair_before = null, $state = 'done' ) {
		global $wpdb;
		$now  = time();
		$done = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test code.
			AB_MCP_Sync_Schema::table( 'runs' ),
			array(
				'type'      => 'push',
				'source_id' => (string) $source,
				'state'     => 'closed_done',
				'created'   => $now,
				'updated'   => $now,
				'closed'    => $now,
				'heartbeat' => $now,
			)
		);
		if ( false === $done ) {
			throw new RuntimeException( 'The run was not written: ' . $wpdb->last_error );
		}
		$run_id = (int) $wpdb->insert_id;
		$row    = array(
			'run_id'  => $run_id,
			'seq'     => 1,
			'op'      => 'write',
			'depends' => '[]',
			'state'   => (string) $state,
			'time'    => $now,
		);
		if ( null !== $pair_id ) {
			$row['pair_id'] = (int) $pair_id;
		}
		if ( null !== $pair_before ) {
			$row['pair_before'] = (string) $pair_before;
		}
		if ( null !== $result ) {
			$row['result'] = AB_MCP_Sync_Canon::encode( $result );
		}
		if ( false === $wpdb->insert( AB_MCP_Sync_Schema::table( 'steps' ), $row ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test code.
			throw new RuntimeException( 'The step was not written: ' . $wpdb->last_error );
		}
		return array(
			'run_id' => $run_id,
			'seq'    => 1,
		);
	}
}

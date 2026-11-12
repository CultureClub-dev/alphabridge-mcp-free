<?php
/**
 * The inputs of the decisions of the protected store, in one table (Z8b
 * branch 3, Behebung 4, Option C1): what the clean-up keeps or removes
 * (retention), what an open mark keeps (protection), the times of files, the
 * budgets and the clocks. Each row names its source, the field it reads, its
 * form (AB_MCP_Shape) and what its consumer does where the value is not of
 * the form. Every one of these reads goes through read(): the value in its
 * form, or null, which each consumer takes as unknown:
 *
 * - retention: the group stays, and the clean-up counts it as unknown
 *   (AB_MCP_Protected_Store::kept() null, the contract of Behebung 3);
 * - period: a close stores no period of it, but keep_until 0, which keeps
 *   the backups of the run (AB_MCP_Sync_Schema::keep_until());
 * - protection: nothing in stage, backups and marks goes, and the clean-up
 *   counts what it kept so as unknown (guard());
 * - file_time: the file or folder stays;
 * - budget: the budget is not known, and the call is refused with the cause
 *   and the way (AB_MCP_Sync_Backup::fits(), AB_MCP_Store_Budget).
 *
 * A read of one of these inputs outside the table is a named limit
 * (GRENZEN-CODE.md, G-3-10): no test sees it, only review and Codex.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-shape.php';

/**
 * Class AB_MCP_Store_Inputs
 */
final class AB_MCP_Store_Inputs {

	/** A whole number of 1 or more as PHP holds it (no text). */
	const RUN = array(
		'type'   => 'int',
		'digits' => false,
		'min'    => 1,
		'max'    => null,
	);

	/** A whole number of 0 or more as PHP holds it (no text). */
	const COUNT = array(
		'type'   => 'int',
		'digits' => false,
		'min'    => 0,
		'max'    => null,
	);

	/** A whole number of 1 or more a column of the database gives, as int or its decimal digits. */
	const DB_RUN = array(
		'type'   => 'int',
		'digits' => true,
		'min'    => 1,
		'max'    => null,
	);

	/** A whole number of 0 or more a column of the database gives, as int or its decimal digits. */
	const DB_COUNT = array(
		'type'   => 'int',
		'digits' => true,
		'min'    => 0,
		'max'    => null,
	);

	/** The flags json_encode() writes a sidecar with (write_entry()). */
	const SIDECAR_FLAGS = JSON_UNESCAPED_SLASHES;

	/** The flags json_encode() writes a mark with (put_mark(), close_mark_in()). */
	const MARK_FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

	/**
	 * The table: id => consumer, source, field, form. The id of a field of a
	 * sidecar is 'sidecar.' and the type its row of
	 * AB_MCP_Protected_Store::SIDECAR names (the area of an entry is the one
	 * of its row: whole()).
	 */
	const TABLE = array(
		/* -------------------------------- retention: the inputs of kept() */
		'retention.now'            => array(
			'consumer' => 'retention',
			'source'   => 'AB_MCP_Protected_Store::cleanup()',
			'field'    => 'the time of the clean-up ($now; time() where none is given)',
			'form'     => self::RUN,
		),
		'retention.open'           => array(
			'consumer' => 'retention',
			'source'   => 'AB_MCP_Sync_Schema::open_runs()',
			'field'    => 'the runs that are not closed, as kept() takes them',
			'form'     => array(
				'type'      => 'list',
				'empty'     => true,
				'item'      => self::RUN,
				'unique'    => true,
				'max_items' => null,
			),
		),
		'retention.backups'        => array(
			'consumer' => 'retention',
			'source'   => 'AB_MCP_Sync_Schema::backup_rows()',
			'field'    => 'run => whether the retention keeps its backups, as kept() takes them',
			'form'     => array(
				'type'  => 'map',
				'empty' => true,
				'key'   => self::RUN,
				'value' => array( 'type' => 'bool' ),
			),
		),
		'retention.keep'           => array(
			'consumer' => 'retention',
			'source'   => 'AB_MCP_Protected_Store::keeps()',
			'field'    => 'the seconds an area keeps a group by its age',
			'form'     => self::COUNT,
		),
		/* ------------------------------- the rows of the runs (database) */
		'runs.open_ids'            => array(
			'consumer' => 'retention',
			'source'   => 'AB_MCP_Sync_Schema::open_runs()',
			'field'    => 'run_id of the rows whose state is not closed (get_col())',
			'form'     => array(
				'type'      => 'list',
				'empty'     => true,
				'item'      => self::DB_RUN,
				'unique'    => true,
				'max_items' => null,
			),
		),
		'runs.run_id'              => array(
			'consumer' => 'retention',
			'source'   => 'AB_MCP_Sync_Schema::backup_rows()',
			'field'    => 'run_id of a row',
			'form'     => self::DB_RUN,
		),
		'runs.state'               => array(
			'consumer' => 'retention',
			'source'   => 'AB_MCP_Sync_Schema::backup_rows()',
			'field'    => 'state of a row',
			'form'     => array(
				'type'      => 'string',
				'empty'     => false,
				'max_bytes' => null,
				'in'        => array( 'open', 'interrupted', 'reverting', 'closed_done', 'closed_findings', 'closed_aborted', 'closed_checked', 'closed_reverted' ),
			),
		),
		'runs.type'                => array(
			'consumer' => 'retention',
			'source'   => 'AB_MCP_Sync_Schema::backup_rows()',
			'field'    => 'type of a row that is not closed',
			'form'     => array(
				'type'      => 'string',
				'empty'     => false,
				'max_bytes' => null,
				'in'        => array( 'push', 'revert' ),
			),
		),
		'runs.keep_until'          => array(
			'consumer' => 'retention',
			'source'   => 'AB_MCP_Sync_Schema::backup_rows()',
			'field'    => 'keep_until of a closed row (0: no period stored, kept)',
			'form'     => self::DB_COUNT,
		),
		'runs.forward_closed_at'   => array(
			'consumer' => 'retention',
			'source'   => 'AB_MCP_Sync_Schema::backup_rows()',
			'field'    => 'forward_closed_at of a closed row (0: no qualified close)',
			'form'     => self::DB_COUNT,
		),
		'runs.plan'                => array(
			'consumer' => 'retention',
			'source'   => 'AB_MCP_Sync_Schema::backup_rows()',
			'field'    => 'plan of a way back that is not closed: the runs it takes back (reverts)',
			'form'     => array(
				'type'  => 'json',
				'codec' => 'AB_MCP_Sync_Canon',
				'depth' => 64,
				'value' => array(
					'type'   => 'record',
					'fields' => array(
						'reverts' => array(
							'type'      => 'list',
							'empty'     => false,
							'item'      => self::RUN,
							'unique'    => true,
							'max_items' => null,
						),
					),
					'extra'  => true,
				),
			),
		),
		/* --------------------------------- periods: where a close stores one */
		'period.days'              => array(
			'consumer' => 'period',
			'source'   => 'AB_MCP_Protected_Store::backup_days()',
			'field'    => 'the filter ab_mcp_staging_backup_days',
			'form'     => array(
				'type'   => 'int',
				'digits' => true,
				'min'    => 0,
				'max'    => 3650,
			),
		),
		'period.clock'             => array(
			'consumer' => 'period',
			'source'   => 'AB_MCP_Sync_Run::now()',
			'field'    => 'the clock of the runs (AB_MCP_Sync_Run::$clock, time() where none is set)',
			'form'     => self::RUN,
		),
		/* ----------------------- a sidecar: retention, budget, owners */
		'sidecar.json'             => array(
			'consumer' => 'sidecar',
			'source'   => 'AB_MCP_Protected_Store::sidecar()',
			'field'    => 'the text of a sidecar, as write_entry() writes it',
			'form'     => array(
				'type'   => 'json',
				'codec'  => 'json',
				'flags'  => self::SIDECAR_FLAGS,
				'object' => false,
				'depth'  => 4,
				'value'  => array(
					'type'   => 'record',
					'fields' => array(),
					'extra'  => true,
				),
			),
		),
		'sidecar.format'           => array(
			'consumer' => 'sidecar',
			'source'   => 'AB_MCP_Protected_Store::whole()',
			'field'    => 'format of a sidecar',
			'form'     => array(
				'type'      => 'string',
				'empty'     => false,
				'max_bytes' => null,
				'in'        => array( 'ab-mcp-store/1' ),
			),
		),
		'sidecar.gid'              => array(
			'consumer' => 'sidecar',
			'source'   => 'AB_MCP_Protected_Store::whole()',
			'field'    => 'gid of a sidecar',
			'form'     => array(
				'type'      => 'string',
				'empty'     => false,
				'max_bytes' => null,
				'pattern'   => '/^[0-9a-f]{32}\z/',
			),
		),
		'sidecar.time'             => array(
			'consumer' => 'sidecar',
			'source'   => 'AB_MCP_Protected_Store::whole()',
			'field'    => 'created of a sidecar',
			'form'     => self::RUN,
		),
		'sidecar.count'            => array(
			'consumer' => 'sidecar',
			'source'   => 'AB_MCP_Protected_Store::whole()',
			'field'    => 'user_id, declared, used of a sidecar, run_id of out, in and reports',
			'form'     => self::COUNT,
		),
		'sidecar.run'              => array(
			'consumer' => 'sidecar',
			'source'   => 'AB_MCP_Protected_Store::whole()',
			'field'    => 'run_id of a sidecar of backups and stage',
			'form'     => self::RUN,
		),
		'sidecar.label'            => array(
			'consumer' => 'sidecar',
			'source'   => 'AB_MCP_Protected_Store::whole()',
			'field'    => 'label of a sidecar',
			'form'     => array(
				'type'      => 'string',
				'empty'     => true,
				'max_bytes' => null,
				'pattern'   => '/^[A-Za-z0-9._:-]{0,191}\z/',
			),
		),
		'sidecar.text'             => array(
			'consumer' => 'sidecar',
			'source'   => 'AB_MCP_Protected_Store::whole()',
			'field'    => 'binding of a sidecar',
			'form'     => array(
				'type'      => 'string',
				'empty'     => true,
				'max_bytes' => null,
			),
		),
		'sidecar.bool'             => array(
			'consumer' => 'sidecar',
			'source'   => 'AB_MCP_Protected_Store::whole()',
			'field'    => 'closed of a sidecar',
			'form'     => array( 'type' => 'bool' ),
		),
		/* --------------------------------- protection: the marks (guard()) */
		'mark.json'                => array(
			'consumer' => 'protection',
			'source'   => 'AB_MCP_Protected_Store::read_mark()',
			'field'    => 'a mark: state, the trees it names (role, path, form, device, inode, hash), target, the time it closed',
			'form'     => array(
				'type'   => 'json',
				'codec'  => 'json',
				'flags'  => self::MARK_FLAGS,
				'object' => false,
				'depth'  => 8,
				'value'  => array(
					'type'   => 'record',
					'fields' => array(
						'format' => array(
							'type'      => 'string',
							'empty'     => false,
							'max_bytes' => null,
							'in'        => array( 'ab-mcp-mark/1' ),
						),
						'mid'    => array(
							'type'      => 'string',
							'empty'     => false,
							'max_bytes' => null,
							'pattern'   => '/^[0-9a-f]{32}\z/',
						),
						'state'  => array(
							'type'      => 'string',
							'empty'     => false,
							'max_bytes' => null,
							'in'        => array( 'open', 'closed' ),
						),
						'trees'  => array(
							'type'      => 'list',
							'empty'     => false,
							'unique'    => true,
							'max_items' => 2,
							'item'      => array(
								'type'   => 'record',
								'fields' => array(
									'role' => array(
										'type'      => 'string',
										'empty'     => false,
										'max_bytes' => null,
										'in'        => array( 'backup', 'stage' ),
									),
									'path' => array(
										'type'      => 'string',
										'empty'     => false,
										'max_bytes' => null,
									),
									'form' => array(
										'type'      => 'string',
										'empty'     => false,
										'max_bytes' => null,
										'in'        => array( 'folder', 'file' ),
									),
									'dev'  => self::COUNT,
									'ino'  => self::COUNT,
									'hash' => array(
										'type'      => 'string',
										'empty'     => false,
										'max_bytes' => null,
										'pattern'   => '/^[0-9a-f]{64}\z/',
										'nullable'  => true,
									),
								),
								'extra'  => false,
							),
						),
						'target' => array(
							'type'      => 'string',
							'empty'     => false,
							'max_bytes' => null,
						),
						'closed' => self::COUNT,
					),
					'extra'  => true,
				),
			),
		),
		/* ----------------------------------------------- times of files */
		'file.mtime'               => array(
			'consumer' => 'file_time',
			'source'   => 'AB_MCP_Protected_Store::sweep(), delete_path(), by lstat() of the seam',
			'field'    => 'mtime of a foreign sidecar, a temp file, a folder of a mark without one',
			'form'     => self::COUNT,
		),
		/* ------------------------------------------------------- budgets */
		'budget.max_packages'      => array(
			'consumer' => 'budget',
			'source'   => 'AB_MCP_Store_Budget::package_refusal()',
			'field'    => 'the filter ab_mcp_store_max_packages',
			'form'     => array(
				'type'   => 'int',
				'digits' => true,
				'min'    => 0,
				'max'    => null,
			),
		),
		'budget.max_bytes'         => array(
			'consumer' => 'budget',
			'source'   => 'AB_MCP_Store_Budget::package_refusal()',
			'field'    => 'the filter ab_mcp_store_max_bytes',
			'form'     => array(
				'type'   => 'int',
				'digits' => true,
				'min'    => 0,
				'max'    => null,
			),
		),
		'budget.max_exports'       => array(
			'consumer' => 'budget',
			'source'   => 'AB_MCP_Store_Budget::package_refusal()',
			'field'    => 'the filter ab_mcp_store_max_exports',
			'form'     => array(
				'type'   => 'int',
				'digits' => true,
				'min'    => 0,
				'max'    => null,
			),
		),
		'budget.declared'          => array(
			'consumer' => 'budget',
			'source'   => 'AB_MCP_Protected_Store, opening a group',
			'field'    => 'declared of the meta of a group (the bytes a package will hold)',
			'form'     => self::COUNT,
		),
		'budget.book_run'          => array(
			'consumer' => 'budget',
			'source'   => 'AB_MCP_Store_Budget::book(), release()',
			'field'    => 'the run of a booking, as its caller gives it',
			'form'     => self::RUN,
		),
		'budget.book_bytes'        => array(
			'consumer' => 'budget',
			'source'   => 'AB_MCP_Store_Budget::book()',
			'field'    => 'the bytes a run books, as its caller gives them',
			'form'     => self::COUNT,
		),
		'budget.free'              => array(
			'consumer' => 'budget',
			'source'   => 'AB_MCP_Protected_Store::free_space(), by free() of the seam',
			'field'    => 'the free bytes of the disk of the store (disk_free_space())',
			'form'     => array(
				'type' => 'number',
				'min'  => 0,
				'max'  => null,
			),
		),
		'budget.bookings'          => array(
			'consumer' => 'budget',
			'source'   => 'AB_MCP_Store_Budget::read_bookings()',
			'field'    => 'the file bookings.json: run => bytes, booked, user_id',
			'form'     => array(
				'type'   => 'json',
				'codec'  => 'json',
				'flags'  => 0,
				'object' => true,
				'depth'  => 4,
				'value'  => array(
					'type'  => 'map',
					'empty' => true,
					'key'   => self::RUN,
					'value' => array(
						'type'   => 'record',
						'fields' => array(
							'bytes'   => self::COUNT,
							'booked'  => self::COUNT,
							'user_id' => self::COUNT,
						),
						'extra'  => false,
					),
				),
			),
		),
		'backup.run'               => array(
			'consumer' => 'budget',
			'source'   => 'AB_MCP_Sync_Backup::group(), fits()',
			'field'    => 'the run of a backup, as the engine gives it (the run_id of its row)',
			'form'     => self::DB_RUN,
		),
		'budget.payload'           => array(
			'consumer' => 'budget',
			'source'   => 'AB_MCP_Sync_Backup::size_of()',
			'field'    => 'the bytes of a backup as written (null where it cannot be written as JSON)',
			'form'     => self::COUNT,
		),
		'budget.listing'           => array(
			'consumer' => 'budget',
			'source'   => 'AB_MCP_Protected_Store::stock()',
			'field'    => 'the names in an area, as its stock of groups is read for a budget',
			'form'     => array(
				'type'      => 'list',
				'empty'     => true,
				'item'      => array(
					'type'      => 'string',
					'empty'     => false,
					'max_bytes' => null,
				),
				'unique'    => true,
				'max_items' => null,
			),
		),
	);

	/**
	 * The value of an input in the form of its row, or null (unknown); null
	 * also for an id the table has not.
	 *
	 * @param string $id    Row.
	 * @param mixed  $value Value as read.
	 * @return mixed|null
	 */
	public static function read( $id, $value ) {
		return is_string( $id ) && isset( self::TABLE[ $id ] ) ? AB_MCP_Shape::read( $value, self::TABLE[ $id ]['form'] ) : null;
	}
}

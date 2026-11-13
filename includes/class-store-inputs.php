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
 * - file_time: the file or folder stays (a building folder of build_dir()
 *   with what is in it);
 * - budget: the budget is not known, and the call is refused with the cause
 *   and the way (AB_MCP_Sync_Backup::fits(), AB_MCP_Store_Budget);
 * - package: a package of code whose index is not of its form is not used
 *   (AB_MCP_Sync_Code_Package::taken() refuses; Z8b branch 4);
 * - stage: a stage whose state is not of its form is never ready, and a
 *   unit whose host of updates is not known is fetched from no wordpress.org
 *   (AB_MCP_Sync_Code_Stage; Z8b branch 4).
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

	/** A SHA-256 in hex, as the store and the manifests write it. */
	const SHA256 = array(
		'type'      => 'string',
		'empty'     => false,
		'max_bytes' => null,
		'pattern'   => '/^[0-9a-f]{64}\z/',
	);

	/** The names in a folder, as scandir() gives them: texts, none twice. */
	const NAMES = array(
		'type'      => 'list',
		'empty'     => true,
		'item'      => array(
			'type'      => 'string',
			'empty'     => false,
			'max_bytes' => null,
		),
		'unique'    => true,
		'max_items' => null,
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
			'source'   => 'AB_MCP_Sync_Run::now(): keep_until(), set_state(), create(), the steps, context(), restore_part(), the age of a temp file (delete_path()), AB_MCP_Sync_Export::discard_stale()',
			'field'    => 'the clock of the runs (AB_MCP_Sync_Run::$clock, time() where none is set)',
			'form'     => self::RUN,
		),
		'period.qualified'         => array(
			'consumer' => 'period',
			'source'   => 'AB_MCP_Sync_Run::set_state(), keep_until()',
			'field'    => 'whether a close qualifies (rule 2), as the caller gives it',
			'form'     => array( 'type' => 'bool' ),
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
		/* ---------------- the state of a package of code (Z8b branch 4) */
		'code.index'               => array(
			'consumer' => 'package',
			'source'   => 'AB_MCP_Sync_Code_Package::index(), taken()',
			'field'    => 'the index a package of code keeps in its group (unpacked()): each file with its unit, path, size, SHA-256, pieces and chunks; what the stage takes from the package',
			'form'     => array(
				'type'  => 'json',
				'codec' => 'AB_MCP_Sync_Canon',
				'depth' => 8,
				'value' => array(
					'type'   => 'record',
					'fields' => array(
						'files'  => array(
							'type'      => 'list',
							'empty'     => false,
							'item'      => array(
								'type'   => 'record',
								'fields' => array(
									'chunks' => array(
										'type'      => 'list',
										'empty'     => false,
										'item'      => self::COUNT,
										'unique'    => true,
										'max_items' => null,
									),
									'kind'   => array(
										'type'      => 'string',
										'empty'     => false,
										'max_bytes' => null,
										'in'        => array( 'plugin', 'theme' ),
									),
									'path'   => array(
										'type'      => 'string',
										'empty'     => false,
										'max_bytes' => 4096,
									),
									'pieces' => self::RUN,
									'ref'    => array(
										'type'      => 'string',
										'empty'     => false,
										'max_bytes' => 764,
									),
									'sha256' => self::SHA256,
									'size'   => self::COUNT,
								),
								'extra'  => false,
							),
							'unique'    => true,
							'max_items' => null,
						),
						'format' => array(
							'type'      => 'string',
							'empty'     => false,
							'max_bytes' => null,
							'in'        => array( 'ab-sync-code/1' ),
						),
					),
					'extra'  => false,
				),
			),
		),
		/* ------------------ the stage of a unit of code (Z8b branch 4) */
		'stage.update_uri'         => array(
			'consumer' => 'stage',
			'source'   => 'AB_MCP_Sync_Code_Stage::update_host(), from the signed line of the unit (AB_MCP_Sync_Env)',
			'field'    => 'update_uri of the line: the host the head of the unit names for its updates, or none; whether a package may come from wordpress.org (from_org(), D1)',
			'form'     => array(
				'type'      => 'string',
				'empty'     => true,
				'max_bytes' => null,
			),
		),
		'stage.update_head'        => array(
			'consumer' => 'stage',
			'source'   => 'AB_MCP_Sync_Env::host_of_head(), the head of a main file or style.css on the staging site (head_read())',
			'field'    => 'the head as it reads whole: Update URI, the host it names goes into the line of the unit; a head not read whole is unknown, the host of the unit too (Codex round 1 of branch 4, A2)',
			'form'     => array(
				'type'   => 'record',
				'fields' => array(
					'Update URI' => array(
						'type'      => 'string',
						'empty'     => true,
						'max_bytes' => null,
					),
				),
				'extra'  => false,
			),
		),
		'stage.head'               => array(
			'consumer' => 'stage',
			'source'   => 'AB_MCP_Sync_Code_Stage::head_refusal(), the head of each PHP file of a plugin or style.css of a theme in the stage (head_read())',
			'field'    => 'Plugin Name, Requires at least and Requires PHP of the new version, as it reads whole: whether it runs here; unknown refuses, never ready (Codex round 1 of branch 4, A4)',
			'form'     => array(
				'type'   => 'record',
				'fields' => array(
					'Plugin Name'       => array(
						'type'      => 'string',
						'empty'     => true,
						'max_bytes' => null,
					),
					'Requires PHP'      => array(
						'type'      => 'string',
						'empty'     => true,
						'max_bytes' => null,
					),
					'Requires at least' => array(
						'type'      => 'string',
						'empty'     => true,
						'max_bytes' => null,
					),
				),
				'extra'  => false,
			),
		),
		'stage.version'            => array(
			'consumer' => 'stage',
			'source'   => 'AB_MCP_Sync_Code_Stage::from_org(), the version of the signed line of the unit',
			'field'    => 'the version the package of wordpress.org is fetched in (its exact address); unknown fetches nothing',
			'form'     => array(
				'type'      => 'string',
				'empty'     => false,
				'max_bytes' => 64,
			),
		),
		'stage.state'              => array(
			'consumer' => 'stage',
			'source'   => 'AB_MCP_Sync_Code_Stage::state_of(), the last state file of the group of a stage',
			'field'    => 'the state of a stage: its unit, the signed hash, its tree, the files to check with the size and SHA-256 the bytes checked are bound to (Codex round 1, A3) and how far, ready or refused; only ready lets the swap take it (branch 5)',
			'form'     => array(
				'type'  => 'json',
				'codec' => 'AB_MCP_Sync_Canon',
				'depth' => 8,
				'value' => array(
					'type'   => 'record',
					'fields' => array(
						'done'    => self::COUNT,
						'format'  => array(
							'type'      => 'string',
							'empty'     => false,
							'max_bytes' => null,
							'in'        => array( 'ab-code-stage/1' ),
						),
						'lint'    => array( 'type' => 'bool' ),
						'notes'   => array(
							'type'      => 'list',
							'empty'     => true,
							'item'      => array(
								'type'      => 'string',
								'empty'     => false,
								'max_bytes' => 4096,
							),
							'unique'    => true,
							'max_items' => 20,
						),
						'refusal' => array(
							'type'     => 'record',
							'fields'   => array(
								'code'    => array(
									'type'      => 'string',
									'empty'     => false,
									'max_bytes' => 64,
								),
								'line'    => array(
									'type'     => 'int',
									'digits'   => false,
									'min'      => 0,
									'max'      => null,
									'nullable' => true,
								),
								'message' => array(
									'type'      => 'string',
									'empty'     => false,
									'max_bytes' => 4096,
								),
								'path'    => array(
									'type'      => 'string',
									'empty'     => false,
									'max_bytes' => 4096,
									'nullable'  => true,
								),
							),
							'extra'    => false,
							'nullable' => true,
						),
						'seq'     => self::RUN,
						'signed'  => self::SHA256,
						'source'  => array(
							'type'      => 'string',
							'empty'     => false,
							'max_bytes' => null,
							'in'        => array( 'wordpress.org', 'packages' ),
						),
						'state'   => array(
							'type'      => 'string',
							'empty'     => false,
							'max_bytes' => null,
							'in'        => array( 'checking', 'ready', 'refused' ),
						),
						'todo'    => array(
							'type'      => 'list',
							'empty'     => true,
							'item'      => array(
								'type'   => 'record',
								'fields' => array(
									'path'   => array(
										'type'      => 'string',
										'empty'     => false,
										'max_bytes' => 4096,
									),
									'sha256' => self::SHA256,
									'size'   => self::COUNT,
								),
								'extra'  => false,
							),
							'unique'    => true,
							'max_items' => null,
						),
						'tree'    => array(
							'type'   => 'record',
							'fields' => array(
								'hash' => self::SHA256,
								'name' => array(
									'type'      => 'string',
									'empty'     => false,
									'max_bytes' => 255,
								),
							),
							'extra'  => false,
						),
						'unit'    => array(
							'type'   => 'record',
							'fields' => array(
								'kind' => array(
									'type'      => 'string',
									'empty'     => false,
									'max_bytes' => null,
									'in'        => array( 'plugin', 'theme' ),
								),
								'ref'  => array(
									'type'      => 'string',
									'empty'     => false,
									'max_bytes' => 764,
								),
							),
							'extra'  => false,
						),
					),
					'extra'  => false,
				),
			),
		),
		'tmp.build_mtime'          => array(
			'consumer' => 'file_time',
			'source'   => 'AB_MCP_Protected_Store, in delete_path() by lstat() of the seam (Z8b branch 4, D4)',
			'field'    => 'mtime of a building folder of build_dir(), its age against TMP_AGE: past it, it goes with what is in it',
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
			'form'     => self::NAMES,
		),
		'budget.held_names'        => array(
			'consumer' => 'budget',
			'source'   => 'AB_MCP_Protected_Store::held(), by scandir() of the seam',
			'field'    => 'the names in the folder of a group, as put() counts what it holds',
			'form'     => self::NAMES,
		),
		'budget.file_size'         => array(
			'consumer' => 'budget',
			'source'   => 'AB_MCP_Protected_Store::held(), by lstat() of the seam and the trailer of a gzip file',
			'field'    => 'the bytes of a file of a group: its size, of a gzip file the raw size its trailer names (ISIZE)',
			'form'     => self::COUNT,
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

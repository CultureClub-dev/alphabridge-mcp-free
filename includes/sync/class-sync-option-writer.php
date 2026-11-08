<?php
/**
 * The write side of the kind option (contract A of the plan of 01.11.2026;
 * Z8 branch 11, decisions 17, 19, 23, 25, 27 and 33): what a step of a run
 * writes for one core option, how it is backed up, read back and put back.
 * The same methods as the other kinds (AB_MCP_Sync_Engine::WRITERS); the
 * object is named by its name (ref_of()).
 *
 * WHAT IS WRITTEN (decision 27): a core option with a schema
 * (AB_MCP_Sync_Option_Schema::CORE, from populate_options()), its name
 * settled as the option guard settles it and not refused by it
 * (AB_MCP_Sync_Option_Schema::classify(), the guard on the settled name),
 * with the value of the staging site in the ID space of this site
 * (AB_MCP_Sync_Refs::rewrite_option(): a page, a category, the sticky posts
 * by their pairs, the addresses of a text). Any other option is locked
 * (option:unclassified, Z8d). No option is created (option:create) and none
 * is deleted (decisions 21, 22).
 *
 * THE WAY FORWARD: update_option(), in the rights of manage_options; before
 * the first part the value through sanitize_option() as update_option()
 * runs it, without writing (decision 17, the sanitizers ahead): what it
 * would store otherwise refuses (option:sanitizer). The row is the one
 * part, read back from the row under the lock of the boundary; the takeover
 * lock covers the option (pre_update_option keeps the old value for another
 * hand, decision 19; delete_option() is not refusable).
 *
 * THE WAY BACK (decision 23): the row raw, byte for byte conditional, where
 * it holds what the step read back, after the same checks as forward (the
 * right, the schema, the guard, the sanitizer of the backed-up value).
 *
 * REWRITE (decision 33, A19): an option of the permalinks (the type rewrite
 * of the schema) builds the rewrite rules anew after it was written or put
 * back (post_write()), as the permalink screen does.
 *
 * Messages are English without gettext, as the other classes of the
 * takeover; each names the way.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-sync-shared-row.php';
require_once __DIR__ . '/class-sync-option-schema.php';
require_once __DIR__ . '/class-sync-refs.php';
require_once __DIR__ . '/class-sync-db-kinds.php';
require_once __DIR__ . '/class-sync-post-writer.php';

/**
 * Class AB_MCP_Sync_Option_Writer
 */
final class AB_MCP_Sync_Option_Writer {

	/** The one part of a step, by its way: pre_update_option refuses another hand (decision 19). */
	const WAY_ROW = 'refusable';

	/** The rules a value before is composed by: the same as the kind post's. */
	const VALUE_RULES = AB_MCP_Sync_Post_Writer::VALUE_RULES;

	/** The order of the parts on the way back: the row only. */
	const PART_ORDER = array( 'row' );

	/**
	 * The object a ref names: the option name as stored, null for none.
	 *
	 * @param mixed $raw Ref.
	 * @return string|null
	 */
	public static function ref_of( $raw ) {
		$name = is_scalar( $raw ) ? (string) $raw : '';
		return '' === $name ? null : $name;
	}

	/* ------------------------------------------------------------ prepare */

	/**
	 * What a step writes for one option: the value for update_option(), the
	 * bytes it is to store, the target form and its hash.
	 *
	 * @param array $line The line of the package (content value, form, identity, locks).
	 * @param array $item The item of the plan (op, live_ref).
	 * @param array $ctx  map, addresses, routes; pending (the check only).
	 * @return array|WP_Error
	 */
	public static function target_of( array $line, array $item, array $ctx ) {
		$name = isset( $line['identity']['name'] ) && is_string( $line['identity']['name'] ) ? $line['identity']['name'] : '';
		if ( 'update' !== $item['op'] ) {
			return AB_MCP_Sync_Post_Writer::locked( 'option:create', sprintf( 'the option %s is not on this site', $name ) );
		}
		$held = AB_MCP_Sync_Post_Writer::line_lock( $line );
		if ( null !== $held ) {
			return $held;
		}
		if ( (string) $item['live_ref'] !== $name ) {
			return AB_MCP_Sync_Post_Writer::locked( 'option:unclassified', sprintf( 'the option %1$s of the staging site is paired with the option %2$s of this site, and an option is written under its own name only', $name, (string) $item['live_ref'] ) );
		}
		$class = AB_MCP_Sync_Option_Schema::classify( $name );
		if ( 'core' !== $class['status'] ) {
			return self::unclassified( $name, $class );
		}
		if ( ! isset( $line['content']['row']['value'], $line['form'] ) || ! is_array( $line['form'] ) ) {
			return AB_MCP_Sync_Post_Writer::locked( 'post:content_missing', sprintf( 'the package carries no value of the option %s', $name ) );
		}
		$raw     = AB_MCP_Sync_Post_Writer::bytes( $line['content']['row']['value'] );
		$plan    = AB_MCP_Sync_Refs::plan( $ctx['map'], $ctx['addresses'], isset( $ctx['routes'] ) ? $ctx['routes'] : null );
		$changed = false;
		$written = AB_MCP_Sync_Refs::rewrite_option( $class['schema'], $raw, $plan, $changed );
		$pending = isset( $ctx['pending'] ) && is_array( $ctx['pending'] ) ? $ctx['pending'] : array();
		$missing = AB_MCP_Sync_Post_Writer::unresolved( $plan['missing'], $pending );
		if ( array() !== $missing ) {
			return AB_MCP_Sync_Post_Writer::locked( 'option:reference_unmapped', AB_MCP_Sync_Post_Writer::missing_text( $missing ) );
		}
		$value = $written;
		if ( 'refs' === $class['schema']['type'] ) {
			$read = AB_MCP_Sync_Canon::unserialized( $written );
			if ( ! $read['ok'] || ! is_array( $read['value'] ) || AB_MCP_Sync_Shared_Row::field( $read['value'] ) !== $written ) {
				return AB_MCP_Sync_Post_Writer::locked( 'option:off_schema', sprintf( 'the list of the option %s does not unpack to a list update_option() would store again as it is', $name ) );
			}
			$value = $read['value'];
		}
		$target = AB_MCP_Sync_Canon::mapped( $line['form'], $ctx['map'] );
		return array(
			'type'        => $name,
			'name'        => $name,
			'schema'      => $class['schema'],
			'status'      => null,
			'value'       => $value,
			'columns'     => array( 'value' => $written ),
			'target_form' => $target,
			'target_hash' => AB_MCP_Sync_Canon::hash( $target ),
			'identity'    => $line['identity'],
			'as_draft'    => false,
		);
	}

	/**
	 * The refusal of an option that is no core option with a schema here: its
	 * own lock (another kind, the guard, a plugin), as classify() names it.
	 *
	 * @param string $name  Name.
	 * @param array  $class AB_MCP_Sync_Option_Schema::classify().
	 * @return WP_Error
	 */
	private static function unclassified( $name, array $class ) {
		$lock = AB_MCP_Sync_Kinds::lock( 'option', 'unclassified' );
		return new WP_Error(
			'ab_mcp_sync_locked',
			sprintf( 'Not written (option:unclassified, from %1$s): %2$s The option %3$s: %4$s Use wp-admin (Settings) to change it by hand, or leave it out of the plan.', (string) $lock['stage'], (string) $lock['reason'], $name, null === $class['reason'] ? $class['status'] : (string) $class['reason'] ),
			array( 'lock' => $lock + array( 'at' => 'option ' . $name ) )
		);
	}

	/**
	 * The checks before the part (decision 17, step 4): the right, the class
	 * of the name as it is now (the option guard on the settled name), the
	 * option there, the schema, the sanitizer ahead.
	 *
	 * @param array       $prepared target_of().
	 * @param string|null $live_id  The option here (its name).
	 * @param int         $now      Time (not used).
	 * @return true|WP_Error
	 */
	public static function precheck( array $prepared, $live_id, $now ) {
		unset( $now );
		$name = (string) $prepared['name'];
		if ( ! current_user_can( 'manage_options' ) ) {
			return AB_MCP_Sync_Post_Writer::rights( sprintf( 'change the option %s (manage_options)', $name ) );
		}
		$class = AB_MCP_Sync_Option_Schema::classify( $name );
		if ( 'core' !== $class['status'] ) {
			return self::unclassified( $name, $class );
		}
		$current = self::current( null === $live_id ? $name : (string) $live_id );
		if ( is_wp_error( $current ) ) {
			return $current;
		}
		if ( null === $current ) {
			return AB_MCP_Sync_Post_Writer::locked( 'option:create', sprintf( 'the option %s is not on this site', $name ) );
		}
		if ( ! AB_MCP_Sync_Option_Schema::fits( $prepared['schema'], $prepared['columns']['value'] ) ) {
			return AB_MCP_Sync_Post_Writer::locked( 'option:off_schema', sprintf( 'the value of the staging site for %s', $name ) );
		}
		$refusal = self::sanitizer_refusal( $name, $prepared['value'], $prepared['columns']['value'] );
		return null === $refusal ? true : AB_MCP_Sync_Post_Writer::locked( 'option:sanitizer', $refusal );
	}

	/**
	 * Why sanitize_option() would make update_option() store other bytes than
	 * the target, or null (decision 17: the sanitizers ahead, as
	 * update_option() runs it: wp-includes/option.php, sanitize_option()).
	 *
	 * @param string $name  Name.
	 * @param mixed  $value The value handed over.
	 * @param string $bytes The bytes it is to store.
	 * @return string|null
	 */
	private static function sanitizer_refusal( $name, $value, $bytes ) {
		$clean = sanitize_option( (string) $name, $value );
		$form  = AB_MCP_Sync_Post_Writer::db_form( $clean );
		return null !== $form && $form === (string) $bytes ? null : sprintf( 'sanitize_option() of this site changes the value of %s', $name );
	}

	/**
	 * Whether the option here is one this version writes as it is: its
	 * canonical form carries no lock.
	 *
	 * @param string $live_id Option name.
	 * @param array  $source  AB_MCP_Sync_Inventory::source().
	 * @return true|WP_Error
	 */
	public static function live_rule( $live_id, array $source ) {
		$read = self::live_read( (string) $live_id, $source );
		$lock = AB_MCP_Sync_Post_Writer::read_lock( $read, sprintf( 'the option %s of this site', (string) $live_id ) );
		return null === $lock ? true : $lock;
	}

	/**
	 * The parts of the journal: the row.
	 *
	 * @param array      $prepared target_of().
	 * @param array|null $current  current().
	 * @return array[]
	 */
	public static function journal_parts( array $prepared, $current ) {
		return array(
			array(
				'part'   => 'row',
				'way'    => self::WAY_ROW,
				'before' => is_array( $current ) ? self::row_hash( $current['row'] ) : null,
				'target' => self::row_hash( $prepared['columns'] ),
			),
		);
	}

	/* ---------------------------------------------------------- reading */

	/**
	 * The option here, from its row: its value as stored; null where it is
	 * not there; a WP_Error where the row could not be read.
	 *
	 * @param string $live_id Option name.
	 * @return array{row:array,meta:array,terms:array}|null|WP_Error
	 */
	public static function current( $live_id ) {
		$row = AB_MCP_Sync_Rows::option( (string) $live_id );
		if ( false === $row ) {
			return null;
		}
		if ( ! is_array( $row ) ) {
			return new WP_Error( 'ab_mcp_sync_rows_unread', sprintf( 'The row of the option %s could not be read, so nothing is written for it. Try again; if it keeps failing, the database error log names the cause.', (string) $live_id ) );
		}
		return array(
			'row'   => array( 'value' => (string) $row['value'] ),
			'meta'  => array(),
			'terms' => array(),
		);
	}

	/**
	 * @param array|null $current current(), null for none.
	 * @param string     $part    The name of the part.
	 * @return string|null
	 */
	public static function part_hash( $current, $part ) {
		if ( 'row' !== (string) $part ) {
			return null;
		}
		return is_array( $current ) ? self::row_hash( $current['row'] ) : null;
	}

	/**
	 * @param array $columns value.
	 * @return string
	 */
	public static function row_hash( array $columns ) {
		return AB_MCP_Sync_Shared_Row::hash( isset( $columns['value'] ) ? (string) $columns['value'] : null );
	}

	/**
	 * @param string $live_id Option name.
	 * @param array  $source  AB_MCP_Sync_Inventory::source().
	 * @return array|null
	 */
	private static function live_read( $live_id, array $source ) {
		$kind = AB_MCP_Sync_Db_Kinds::for_pair( 'option' );
		$read = null === $kind ? null : $kind->canonical(
			array(
				'kind'   => 'option',
				'ref'    => (string) $live_id,
				'source' => $source,
			)
		);
		return is_array( $read ) ? $read : null;
	}

	/**
	 * @param string $live_id Option name.
	 * @param array  $source  AB_MCP_Sync_Inventory::source().
	 * @return string|null
	 */
	public static function live_hash( $live_id, array $source ) {
		$read = self::live_read( (string) $live_id, $source );
		return is_array( $read ) && isset( $read['hash'] ) && ! empty( $read['complete'] ) ? (string) $read['hash'] : null;
	}

	/* ----------------------------------------------------------- writing */

	/**
	 * @param array       $prepared target_of().
	 * @param string|null $live_id  Option name.
	 * @param array|null  $current  current() right before the row call.
	 * @return array<string,string>
	 */
	public static function with_row( array $prepared, $live_id, $current = null ) {
		unset( $prepared, $live_id, $current );
		return array();
	}

	/**
	 * @param array  $prepared target_of().
	 * @param string $name     Part.
	 * @return bool
	 */
	public static function through_row( array $prepared, $name ) {
		unset( $prepared, $name );
		return false;
	}

	/**
	 * Write the option: update_option() with the value of the target, the
	 * right asked right before (filters ran since the check, branch 10, A5).
	 *
	 * @param array       $prepared target_of().
	 * @param string|null $live_id  Option name.
	 * @return string|WP_Error The name.
	 */
	public static function write_row( array $prepared, $live_id, $pre = null ) {
		$name = (string) $prepared['name'];
		if ( null === $live_id || (string) $live_id !== $name ) {
			return AB_MCP_Sync_Post_Writer::locked( 'option:create', sprintf( 'the option %s is not on this site', $name ) );
		}
		// The right, the last read of the option against the state the engine
		// read, the call (AB_MCP_Sync_Write; Codex round 3 of branch 11, A6).
		return AB_MCP_Sync_Write::forward(
			self::class,
			$name,
			$pre,
			static fn() => current_user_can( 'manage_options' ) ? true : AB_MCP_Sync_Post_Writer::rights( sprintf( 'change the option %s (manage_options)', $name ) ),
			static function () use ( $name, $prepared ) {
				// Only this value into the row while the call runs (Codex round
				// 5 of branch 11, C1).
				AB_MCP_Sync_Guard::only( $name, $prepared['value'], static fn() => update_option( $name, $prepared['value'] ) );
				return $name;
			},
			static function () use ( $name ) {
				// update_option() compares with the value it reads through the
				// cache of the options and writes nothing where they are equal:
				// emptied first, it compares with the row as it is (Codex round
				// 1, A1); before the last read, so that no cache is touched
				// between the comparison and the call (Codex round 4, item 4).
				AB_MCP_Sync_Shared_Row::forget( $name );
				return null;
			}
		);
	}

	/**
	 * An option has no part of its own call besides the row.
	 *
	 * @param string     $live_id  Option name.
	 * @param string     $name     Part.
	 * @param array      $prepared target_of().
	 * @param array|null $pre      current().
	 * @return WP_Error
	 */
	public static function write_part( $live_id, $name, array $prepared, $pre ) {
		unset( $live_id, $prepared, $pre );
		return new WP_Error( 'ab_mcp_sync_write_failed', sprintf( 'The part %s is of a name the kind option does not write. Check the object in wp-admin.', (string) $name ) );
	}

	/**
	 * After the writes and after the way back (decision 33): the caches of
	 * the option; for an option of the permalinks the rewrite rules built
	 * anew from it (A19), as the permalink screen does after it saved them.
	 *
	 * @param string $live_id Option name.
	 * @return string[] What was emptied.
	 */
	public static function post_write( $live_id ) {
		$name = (string) $live_id;
		AB_MCP_Sync_Shared_Row::forget( $name );
		$done = array( 'options' );
		$type = isset( AB_MCP_Sync_Option_Schema::CORE[ $name ]['type'] ) ? AB_MCP_Sync_Option_Schema::CORE[ $name ]['type'] : '';
		if ( 'rewrite' === $type && isset( $GLOBALS['wp_rewrite'] ) && $GLOBALS['wp_rewrite'] instanceof WP_Rewrite ) {
			// The structure as the option holds it now, then the rules from it.
			$GLOBALS['wp_rewrite']->init();
			flush_rewrite_rules( false );
			$done[] = 'rewrite_rules';
		}
		return $done;
	}

	/**
	 * What the takeover lock covers: the option.
	 *
	 * @param string|null $live_id Option name.
	 * @param string      $subtype The name as the plan has it.
	 * @return array{posts:array,taxonomies:array,terms:array,options:string[]}
	 */
	public static function guard_of( $live_id, $subtype ) {
		return array(
			'posts'      => array(),
			'taxonomies' => array(),
			'terms'      => array(),
			'options'    => array( null === $live_id ? (string) $subtype : (string) $live_id ),
		);
	}

	/**
	 * @param array $object The object of a journal.
	 * @return string Its name.
	 */
	public static function subtype_of( array $object ) {
		return isset( $object['identity']['name'] ) ? (string) $object['identity']['name'] : '';
	}

	/**
	 * @param array $prepared target_of().
	 * @return string
	 */
	public static function title_of( array $prepared ) {
		return (string) $prepared['name'];
	}

	/* ---------------------------------------------------------- way back */

	/**
	 * Whether this account may put the row back (decision 28, as forward):
	 * manage_options, a core option the guard does not refuse.
	 *
	 * @param string $part    Part.
	 * @param string $live_id Option name.
	 * @param array  $backup  current() of the backup.
	 * @param array  $now     current() now.
	 * @return true|string
	 */
	public static function restore_right( $part, $live_id, array $backup, array $now ) {
		unset( $part, $backup, $now );
		if ( ! current_user_can( 'manage_options' ) ) {
			return sprintf( 'change the option %s (manage_options)', (string) $live_id );
		}
		$class = AB_MCP_Sync_Option_Schema::classify( (string) $live_id );
		return 'core' === $class['status'] ? true : sprintf( 'write the option %s, which is no core option with a schema here (or the option guard refuses it)', (string) $live_id );
	}

	/**
	 * Put the row back raw, byte for byte conditional (decision 23): where
	 * it holds what the step read back, and the backed-up value keeps the
	 * schema and the sanitizer as forward.
	 *
	 * @param string $name    Part.
	 * @param string $live_id Option name.
	 * @param array  $backup  current() of the backup.
	 * @param string $hash    The hash the row has to hold.
	 * @param int    $now     Time.
	 * @return array{state:string,note?:string}|WP_Error
	 */
	public static function restore_part( $name, $live_id, array $backup, $hash, $now ) {
		unset( $now );
		if ( 'row' !== (string) $name ) {
			return array(
				'state' => 'left',
				'note'  => sprintf( 'A part %s of an option is of a name this version does not know: left as it is.', (string) $name ),
			);
		}
		$option  = (string) $live_id;
		$current = self::current( $option );
		if ( is_wp_error( $current ) ) {
			return $current;
		}
		if ( null === $current ) {
			return array(
				'state' => 'left',
				'note'  => sprintf( 'The option %s is no longer on this site.', $option ),
			);
		}
		$was = (string) $backup['row']['value'];
		if ( hash_equals( self::row_hash( $backup['row'] ), self::row_hash( $current['row'] ) ) ) {
			return array( 'state' => 'unchanged' );
		}
		if ( ! hash_equals( (string) $hash, self::row_hash( $current['row'] ) ) ) {
			return array(
				'state' => 'left',
				'note'  => sprintf( 'The option %s was changed since the step wrote it, so it is left as it is.', $option ),
			);
		}
		$schema = isset( AB_MCP_Sync_Option_Schema::CORE[ $option ] ) ? AB_MCP_Sync_Option_Schema::CORE[ $option ] : null;
		$read   = AB_MCP_Sync_Canon::unserialized( $was );
		if ( null === $schema || ! AB_MCP_Sync_Option_Schema::fits( $schema, $was ) || ! $read['ok'] || null !== self::sanitizer_refusal( $option, $read['value'], $was ) ) {
			return array(
				'state' => 'left',
				'note'  => sprintf( 'Not put back: the backed-up value of %s does not keep the schema of the option or would be changed by sanitize_option() of this site now, so it is left as it is; its value before stands beside it (value_before). Set it by hand in wp-admin.', $option ),
			);
		}
		// The right and the class of the name again, after sanitize_option()
		// and its filters (Codex round 1 of branch 11, A5), right before the
		// write.
		$right = self::restore_right( 'row', $option, $backup, $current );
		if ( true !== $right ) {
			return array(
				'state' => 'left',
				'note'  => sprintf( 'Not put back: this account may not %s, asked again right before the write (a filter may have changed the rights). Set it by hand in wp-admin with an account that may.', $right ),
			);
		}
		if ( ! AB_MCP_Sync_Shared_Row::put_back( $option, (string) $current['row']['value'], $was ) ) {
			return array(
				'state' => 'left',
				'note'  => sprintf( 'The option %s changed while it was put back, so it is left as it is.', $option ),
			);
		}
		$after = self::current( $option );
		return array( 'state' => is_array( $after ) && hash_equals( self::row_hash( $backup['row'] ), self::row_hash( $after['row'] ) ) ? 'restored' : 'unclear' );
	}

	/**
	 * What the option held before the step, never a credential.
	 *
	 * @param string     $part    Part.
	 * @param array|null $backup  current() of the backup.
	 * @param array|null $now     current() now.
	 * @param mixed      $live_id Option name.
	 * @return string
	 */
	public static function before_value( $part, $backup, $now, $live_id ) {
		unset( $part, $now );
		if ( ! is_array( $backup ) ) {
			return 'none: the option was not on this site before the run.';
		}
		return AB_MCP_Sync_Post_Writer::cut( 'value: ' . AB_MCP_Sync_Post_Writer::shown_value( (string) $live_id, (string) $backup['row']['value'] ), AB_MCP_Sync_Post_Writer::PART_CHARS );
	}
}

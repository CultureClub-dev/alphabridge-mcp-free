<?php
/**
 * The write side of the kinds, registered (contract A): which class writes
 * the objects of a kind and subtype. Apart from AB_MCP_Sync_Kinds, whose
 * register() takes one engine per kind and only whole.
 *
 * THE CONTRACT
 * - register( $kind, $subtypes, $class ): true, or a WP_Error and nothing
 *   registered. $subtypes is WHOLE ('*'), the kind with every subtype, or a
 *   list of subtypes. A kind is registered whole once, or by subtype with
 *   each subtype once (from one class or several); never both.
 * - Names: a kind of a-z, 0-9 and _ that starts with a letter, at most 32
 *   characters (as AB_MCP_Sync_Kinds::register()); a subtype of a-z, 0-9, _
 *   and -, 1 to 191 characters. '' is no subtype to register: of( $kind, '' )
 *   (a line of the plan without post type and taxonomy) answers from WHOLE.
 * - A subtype stands for each entry of AB_MCP_Sync_Kinds::OWNERSHIP of its
 *   kind whose name after the area (post_type:, option: …) it is; one with
 *   a stage is refused.
 * - of( $kind, $subtype ): the class of the subtype, else of the whole kind,
 *   else null. whole( $kind ): the class of the whole kind; null for a kind
 *   registered by subtype, even with one class for every subtype.
 * - Codes of a refusal: ab_mcp_sync_writer_invalid (a name),
 *   ab_mcp_sync_writer_class (no class loaded), ab_mcp_sync_writer_locked
 *   (an entry with a stage), ab_mcp_sync_writer_whole (whole and by
 *   subtype), ab_mcp_sync_writer_duplicate (registered already); texts for a
 *   developer, not translated (as there).
 *
 * This plugin registers the eight kinds of AB_MCP_Sync_Engine::WRITERS, each
 * whole with the same class, at the end of class-sync-engine.php as it loads
 * (as AB_MCP_Sync_Code_Ops admits its entrance).
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-sync-kinds.php';

/**
 * Class AB_MCP_Sync_Writers
 */
final class AB_MCP_Sync_Writers {

	/** The subtypes of a kind registered whole. */
	const WHOLE = '*';

	/** @var array<string,array<string,string>> The class by kind, then by subtype (WHOLE: the whole kind). */
	private static $writers = array();

	/**
	 * @param string          $kind     Kind of the plan (post, term).
	 * @param string|string[] $subtypes WHOLE, or the subtypes.
	 * @param string          $class    The class, loaded.
	 * @return true|WP_Error
	 */
	public static function register( $kind, $subtypes, $class ) {
		$refused = self::refusal( $kind, $subtypes, $class );
		if ( null !== $refused ) {
			return $refused;
		}
		foreach ( self::WHOLE === $subtypes ? array( self::WHOLE ) : $subtypes as $subtype ) {
			self::$writers[ $kind ][ $subtype ] = $class;
		}
		return true;
	}

	/**
	 * @param string $kind    Kind.
	 * @param string $subtype Subtype ('' where the line names none).
	 * @return string|null The class.
	 */
	public static function of( $kind, $subtype ) {
		$at = self::$writers[ (string) $kind ] ?? array();
		return $at[ (string) $subtype ] ?? $at[ self::WHOLE ] ?? null;
	}

	/**
	 * @param string $kind Kind.
	 * @return string|null The class of the whole kind.
	 */
	public static function whole( $kind ) {
		return self::$writers[ (string) $kind ][ self::WHOLE ] ?? null;
	}

	/**
	 * Forget every registration (a test).
	 */
	public static function reset() {
		self::$writers = array();
	}

	/** The refusal of a registration (the arguments of register()), null for none. */
	private static function refusal( $kind, $subtypes, $class ) {
		$list = self::WHOLE === $subtypes ? array() : $subtypes;
		if ( ! self::named( $kind, $list, self::WHOLE === $subtypes ) ) {
			return new WP_Error( 'ab_mcp_sync_writer_invalid', 'A writer needs a kind of a-z, 0-9 and _ that starts with a letter, at most 32 characters, and AB_MCP_Sync_Writers::WHOLE or a list of subtypes of a-z, 0-9, _ and -, 1 to 191 characters each. Use such names.' );
		}
		if ( ! is_string( $class ) || ! class_exists( $class ) ) {
			return new WP_Error( 'ab_mcp_sync_writer_class', sprintf( 'No class of the name given for the kind %s is loaded. Use the name of a loaded class: load the file of the writer first.', $kind ), array( 'kind' => $kind ) );
		}
		foreach ( AB_MCP_Sync_Kinds::OWNERSHIP as $key => $entry ) {
			if ( $kind === $entry['kind'] && isset( $entry['stage'] ) && in_array( substr( $key, strpos( $key, ':' ) + 1 ), $list, true ) ) {
				return new WP_Error(
					'ab_mcp_sync_writer_locked',
					sprintf( '%1$s is locked until %2$s: %3$s Use only subtypes whose entry has no stage.', $key, $entry['stage'], $entry['reason'] ),
					array(
						'kind'  => $kind,
						'key'   => $key,
						'stage' => $entry['stage'],
					)
				);
			}
		}
		$taken = array_merge( array_keys( self::$writers[ $kind ] ?? array() ), array() === $list ? array( self::WHOLE ) : $list );
		$once  = array_unique( $taken );
		if ( in_array( self::WHOLE, $once, true ) && count( $once ) > 1 ) {
			return new WP_Error( 'ab_mcp_sync_writer_whole', sprintf( 'The kind %s would be registered whole and by subtype, and a kind has one writer for every subtype or one per subtype. Use one of the two ways.', $kind ), array( 'kind' => $kind ) );
		}
		if ( count( $once ) !== count( $taken ) ) {
			return new WP_Error( 'ab_mcp_sync_writer_duplicate', sprintf( 'A writer of the kind %s is registered already for a subtype given (or for the whole kind), and each has one. Use AB_MCP_Sync_Writers::of() to reach it.', $kind ), array( 'kind' => $kind ) );
		}
		return null;
	}

	/** Whether the kind and the subtypes ($list, none where $whole) are names of the contract. */
	private static function named( $kind, $list, $whole ) {
		if ( ! is_string( $kind ) || ! preg_match( '/^[a-z][a-z0-9_]{0,31}\z/', $kind ) || ! is_array( $list ) || ( ! $whole && array() === $list ) ) {
			return false;
		}
		foreach ( $list as $subtype ) {
			if ( ! is_string( $subtype ) || ! preg_match( '/^[a-z0-9_-]{1,191}\z/', $subtype ) ) {
				return false;
			}
		}
		return true;
	}
}

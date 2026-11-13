<?php
/**
 * The swap of a unit of code on the production site (Z8b branch 5; plan E5,
 * E6, E9, 3.1 Z3 to Z23): the one mechanics that puts a staged plugin or
 * theme in the place of the one this site runs, and back.
 *
 * WHAT RUNS (active_plugins(), theme(), runs(); plan E8, 3.1 Z24): whether
 * code of a unit runs on this site is read fresh from the rows of the
 * options (active_plugins; stylesheet and template), past every cache and
 * every filter, in the form of their rows of AB_MCP_Store_Inputs::TABLE
 * (site.active_plugins, site.theme). A row that cannot be read, or holds
 * another form, is not known: never «does not run» (a swap of running code
 * takes its release, AB_MCP_Sync_Code_Ops::run()), and never «still active»
 * (the comparison at the end of a swap).
 *
 * THE CALLS TO THE OUTSIDE (plan 8.3, branch 5: rename(), the journal and
 * the mark of the swap, the option of the reservation, the note of the
 * replacement, OPcache, the probes before and after, the shutdown; the rows
 * of the options read) go through AB_MCP_Sync_Code_Swap_Calls, held by the
 * seam of this class; only the tests put another object there. No filter
 * and no option reaches it.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__ ) . '/class-shape.php';
require_once dirname( __DIR__ ) . '/class-store-inputs.php';
require_once __DIR__ . '/class-sync-canon.php';
require_once __DIR__ . '/class-sync-rows.php';

/**
 * The calls of the swap to the outside: the real ones.
 */
final class AB_MCP_Sync_Code_Swap_Calls {

	/**
	 * A stored option row as it is now, past every cache and filter.
	 *
	 * @param string $name Option.
	 * @return array|false|null The row (its value as stored); false where there is none; null where it cannot be read.
	 */
	public function option( $name ) {
		return AB_MCP_Sync_Rows::option( (string) $name );
	}
}

/**
 * Class AB_MCP_Sync_Code_Swap
 */
final class AB_MCP_Sync_Code_Swap {

	/** The calls to the outside (the seam); only the tests put another object here. */
	private static $naht_sync_code_swap = null;

	/**
	 * The calls object: the stand-in of a test, else the real calls.
	 *
	 * @return AB_MCP_Sync_Code_Swap_Calls
	 */
	private static function calls() {
		return self::$naht_sync_code_swap ?? new AB_MCP_Sync_Code_Swap_Calls();
	}

	/**
	 * Back to the real calls.
	 */
	public static function forget() {
		self::$naht_sync_code_swap = null;
	}

	/* ------------------------------------------------------- what runs */

	/**
	 * The main files of the plugins that run on this site now, as the row of
	 * active_plugins holds them (unpacked without classes, in the form of
	 * its row site.active_plugins); none where the site has no such row (no
	 * plugin was ever activated); null where the row cannot be read or is
	 * not of its form (unknown).
	 *
	 * @return string[]|null
	 */
	public static function active_plugins() {
		$row = self::calls()->option( 'active_plugins' );
		if ( false === $row ) {
			return array();
		}
		if ( ! is_array( $row ) || ! array_key_exists( 'value', $row ) || ! is_string( $row['value'] ) ) {
			return null;
		}
		$read = AB_MCP_Sync_Canon::unserialized( $row['value'] );
		$list = true === $read['ok'] ? AB_MCP_Store_Inputs::read( 'site.active_plugins', $read['value'] ) : null;
		return null === $list ? null : array_values( $list );
	}

	/**
	 * The theme that runs on this site now: the rows of stylesheet and
	 * template, each in the form of its row site.theme; the stylesheet is
	 * read again after the template, and a stylesheet that changed in
	 * between (a switch of the theme while it is read) is not known. Null
	 * where a row is missing, cannot be read or is not of its form.
	 *
	 * @return array{stylesheet:string,template:string}|null
	 */
	public static function theme() {
		$read = array();
		foreach ( array( 'stylesheet', 'template', 'again' ) as $option ) {
			$row             = self::calls()->option( 'again' === $option ? 'stylesheet' : $option );
			$read[ $option ] = is_array( $row ) && array_key_exists( 'value', $row ) ? AB_MCP_Store_Inputs::read( 'site.theme', $row['value'] ) : null;
		}
		if ( null === $read['stylesheet'] || null === $read['template'] || $read['again'] !== $read['stylesheet'] ) {
			return null;
		}
		return array(
			'stylesheet' => $read['stylesheet'],
			'template'   => $read['template'],
		);
	}

	/**
	 * The main files of a plugin (its folder, or its one file) that run on
	 * this site now; null where that is not known (active_plugins()).
	 *
	 * @param string $ref The plugin: the name of its folder or of its file.
	 * @return string[]|null
	 */
	public static function active_mains( $ref ) {
		$active = self::active_plugins();
		if ( null === $active || ! is_string( $ref ) || '' === $ref ) {
			return null;
		}
		$mains = array();
		foreach ( $active as $main ) {
			if ( $main === $ref || 0 === strpos( $main, $ref . '/' ) ) {
				$mains[] = $main;
			}
		}
		sort( $mains, SORT_STRING );
		return $mains;
	}

	/**
	 * Whether code of a unit runs on this site now (E5 2, E8): a plugin one
	 * of whose main files is active; a theme that is the stylesheet that runs
	 * or its template (the parent of the theme that runs). Null where that is
	 * not known: a row not read, or a kind that is neither.
	 *
	 * @param string $kind plugin or theme.
	 * @param string $ref  The unit.
	 * @return bool|null
	 */
	public static function runs( $kind, $ref ) {
		if ( 'plugin' === $kind ) {
			$mains = self::active_mains( $ref );
			return null === $mains ? null : array() !== $mains;
		}
		if ( 'theme' !== $kind || ! is_string( $ref ) || '' === $ref ) {
			return null;
		}
		$theme = self::theme();
		return null === $theme ? null : ( $ref === $theme['stylesheet'] || $ref === $theme['template'] );
	}
}

<?php
/**
 * The write side of the kind menu (contract A of the plan of 01.11.2026; Z8
 * branch 11, decisions 16, 17, 19, 21, 23, 24, 25 and 28): a menu (pair kind
 * menu: its nav_menu term with its positions) and an item of a menu (pair
 * kind menu_item, AB_MCP_Sync_Menu_Item_Writer below), each an object of its
 * own. The same methods as the other kinds (AB_MCP_Sync_Engine::WRITERS).
 *
 * A MENU is written as a term of nav_menu (the way of the kind term,
 * AB_MCP_Sync_Term_Writer: wp_insert_term() and wp_update_term() with the
 * slug of the staging site, the sanitizers ahead, the rights of the
 * taxonomy, edit_theme_options); its positions are a field of the row
 * theme_mods_<stylesheet> (decision 24): each position of the theme a part
 * of its own (location:<name>), written through set_theme_mod(
 * 'nav_menu_locations', … ) as the menu screen of the core writes them
 * (unchecked: the key unset; checked: the id of the menu), only for
 * positions the active theme of this site registers
 * (menu:location_unregistered) and only as whole numbers on the menu
 * itself. A position that names the menu itself reads as «self» in the
 * journal, so a new menu has its target before it has an id. The rest of
 * the row (every other position, every other theme mod) is a part of the
 * state: what changes there besides is another hand's (decision 24).
 *
 * NOTHING IS REMOVED (decisions 21, 22): a menu with an item removed on
 * staging is locked as a whole in the report (menu:remove_item); no step
 * deletes a menu or an item; a new menu and new items stay as rests on the
 * way back.
 *
 * THE WAY BACK (decision 23): the row of the term and its meta as the kind
 * term puts them back; a position raw, byte for byte conditional in the
 * row of the theme mods (AB_MCP_Sync_Shared_Row::put_back()), only where it
 * holds what the step read back, to a menu that is there and a position the
 * theme registers.
 *
 * Messages are English without gettext, as the other classes of the
 * takeover; each names the way.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-sync-shared-row.php';
require_once __DIR__ . '/class-sync-refs.php';
require_once __DIR__ . '/class-sync-db-kinds.php';
require_once __DIR__ . '/class-sync-post-writer.php';
require_once __DIR__ . '/class-sync-term-writer.php';

/**
 * Class AB_MCP_Sync_Menu_Writer: a menu, its term and its positions.
 */
final class AB_MCP_Sync_Menu_Writer {

	/** The parts of a step, by their way (decision 19). */
	const WAY_ROW      = AB_MCP_Sync_Term_Writer::WAY_ROW;
	const WAY_META     = AB_MCP_Sync_Term_Writer::WAY_META;
	const WAY_LOCATION = 'refusable';

	/** The rules a value before is composed by: the same as the kind post's. */
	const VALUE_RULES = AB_MCP_Sync_Post_Writer::VALUE_RULES;

	/** The order of the parts on the way back. */
	const PART_ORDER = array( 'row', 'meta:', 'location:' );

	/** A position that names the menu itself, in the journal. */
	const SELF = 'self';

	/* ------------------------------------------------------------ prepare */

	/**
	 * The positions a line names for its menu (its form on the staging
	 * site), names only, for the order of the plan (Codex round 1 of branch
	 * 11, B2); none where the form names none.
	 *
	 * @param array $line The line of the package.
	 * @return string[]
	 */
	public static function line_positions( array $line ) {
		$named = isset( $line['form']['locations']['~set'] ) && is_array( $line['form']['locations']['~set'] ) ? $line['form']['locations']['~set'] : array();
		return array_values( array_unique( array_filter( $named, static fn( $l ) => is_string( $l ) && '' !== $l ) ) );
	}

	/**
	 * The menu each position of the active theme names here, by its id (the
	 * positions in another spelling or of no menu left out), for the order
	 * of the plan (B2); none where the theme mods do not read.
	 *
	 * @return array<string,int>
	 */
	public static function held_positions() {
		$positions = self::positions( null );
		$out       = array();
		foreach ( is_array( $positions ) ? $positions['locations'] : array() as $location => $value ) {
			$id = AB_MCP_Sync_Canon::id_spelling( $value );
			if ( null !== $id && $id > 0 ) {
				$out[ (string) $location ] = $id;
			}
		}
		return $out;
	}

	/**
	 * What a step writes for one menu: the term (as the kind term), its meta
	 * and its positions.
	 *
	 * @param array $line The line of the package (content, form, identity, locks).
	 * @param array $item The item of the plan (op, live_ref).
	 * @param array $ctx  map, addresses, routes; pending (the check only).
	 * @return array|WP_Error
	 */
	public static function target_of( array $line, array $item, array $ctx ) {
		$taxonomy = isset( $line['identity']['taxonomy'] ) ? (string) $line['identity']['taxonomy'] : '';
		if ( 'nav_menu' !== $taxonomy ) {
			return AB_MCP_Sync_Post_Writer::locked( 'menu:item_shape', sprintf( 'the menu of the staging site is a term of %s, not of nav_menu', $taxonomy ) );
		}
		$held = AB_MCP_Sync_Post_Writer::line_lock( $line );
		if ( null !== $held ) {
			return $held;
		}
		if ( ! isset( $line['content']['row'], $line['content']['meta'], $line['form'] ) || ! is_array( $line['form'] ) ) {
			return AB_MCP_Sync_Post_Writer::locked( 'post:content_missing', 'the package carries no content of the menu (a row of it could not be read on the staging site)' );
		}
		// The positions of the menu on the staging site: names, in its form.
		$named = isset( $line['form']['locations']['~set'] ) && is_array( $line['form']['locations']['~set'] ) ? $line['form']['locations']['~set'] : null;
		if ( null === $named ) {
			return AB_MCP_Sync_Post_Writer::locked( 'post:content_missing', 'the form of the menu in the package names no positions' );
		}
		$row = array();
		foreach ( AB_MCP_Sync_Term_Writer::WRITTEN_COLUMNS as $column ) {
			if ( ! isset( $line['content']['row'][ $column ] ) ) {
				return AB_MCP_Sync_Post_Writer::locked( 'post:content_missing', sprintf( 'the package names no column %s of the menu', $column ) );
			}
			$row[ $column ] = AB_MCP_Sync_Post_Writer::bytes( $line['content']['row'][ $column ] );
		}
		if ( 'nav_menu' !== $row['taxonomy'] ) {
			return AB_MCP_Sync_Post_Writer::locked( 'menu:item_shape', sprintf( 'the row of the menu names the taxonomy %s', $row['taxonomy'] ) );
		}
		if ( '0' !== $row['term_group'] ) {
			return AB_MCP_Sync_Post_Writer::locked( 'term:term_group', sprintf( 'the menu of the staging site carries the term_group %s', $row['term_group'] ) );
		}
		if ( '0' !== $row['parent'] ) {
			return AB_MCP_Sync_Post_Writer::locked( 'term:parent', sprintf( 'the menu of the staging site has the parent %s, and menus have none', $row['parent'] ) );
		}
		$plan    = AB_MCP_Sync_Refs::plan( $ctx['map'], $ctx['addresses'], isset( $ctx['routes'] ) ? $ctx['routes'] : null );
		$changed = false;
		$texts   = array();
		foreach ( array( 'name', 'description' ) as $column ) {
			$plan['missing']  = array();
			$texts[ $column ] = AB_MCP_Sync_Refs::rewrite_text( $row[ $column ], $plan, array(), $changed );
			$missing          = AB_MCP_Sync_Post_Writer::unresolved( $plan['missing'], isset( $ctx['pending'] ) && is_array( $ctx['pending'] ) ? $ctx['pending'] : array() );
			if ( array() !== $missing ) {
				return AB_MCP_Sync_Post_Writer::locked( 'menu:reference_unmapped', sprintf( 'the %1$s of the menu: %2$s', $column, AB_MCP_Sync_Post_Writer::missing_text( $missing ) ) );
			}
		}
		$meta = array();
		foreach ( $line['content']['meta'] as $pair ) {
			$key = AB_MCP_Sync_Post_Writer::bytes( $pair[0] );
			if ( 'create' === $item['op'] ) {
				return AB_MCP_Sync_Post_Writer::locked( 'term:create_with_meta', sprintf( 'meta key %s of the new menu', $key ) );
			}
			$plan['missing'] = array();
			$written         = AB_MCP_Sync_Post_Writer::meta_value( AB_MCP_Sync_Post_Writer::bytes( $pair[1] ), $plan, $changed, 'term:meta_rule' );
			if ( is_wp_error( $written ) ) {
				return $written;
			}
			if ( array() !== $plan['missing'] ) {
				return AB_MCP_Sync_Post_Writer::locked( 'menu:reference_unmapped', sprintf( 'a reference in the meta key %s of the menu without a counterpart on this site', $key ) );
			}
			$meta[ $key ][] = $written;
		}
		$locations = array();
		foreach ( $named as $location ) {
			if ( ! is_string( $location ) || '' === $location ) {
				return AB_MCP_Sync_Post_Writer::locked( 'menu:item_shape', 'the package names a position of the menu that is no name' );
			}
			$locations[] = $location;
		}
		$locations = array_values( array_unique( $locations ) );
		sort( $locations, SORT_STRING );
		$target = AB_MCP_Sync_Canon::mapped( $line['form'], $ctx['map'] );
		return array(
			'type'        => 'nav_menu',
			'status'      => null,
			'args'        => array(
				'name'        => $texts['name'],
				'slug'        => $row['slug'],
				'description' => $texts['description'],
				'parent'      => 0,
			),
			'columns'     => array(
				'name'        => $texts['name'],
				'slug'        => $row['slug'],
				'term_group'  => '0',
				'taxonomy'    => 'nav_menu',
				'description' => $texts['description'],
				'parent'      => '0',
			),
			'meta'        => $meta,
			'terms'       => array(),
			'locations'   => $locations,
			'target_form' => $target,
			'target_hash' => AB_MCP_Sync_Canon::hash( $target ),
			'identity'    => $line['identity'],
			'as_draft'    => false,
			'pending'     => false,
			'parents'     => array(),
		);
	}

	/**
	 * The checks before the first part: edit_theme_options, the checks of
	 * the kind term (rights of the taxonomy, slug, sanitizers ahead, meta by
	 * its right), the positions registered by the active theme here and the
	 * row of the theme mods readable.
	 *
	 * @param array    $prepared target_of().
	 * @param int|null $live_id  The menu here, null for a new one.
	 * @param int      $now      Time.
	 * @return true|WP_Error
	 */
	public static function precheck( array $prepared, $live_id, $now ) {
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return AB_MCP_Sync_Post_Writer::rights( 'change the menus of this site (edit_theme_options)' );
		}
		if ( null !== $live_id ) {
			$current = self::current( (int) $live_id );
			if ( is_wp_error( $current ) ) {
				return $current;
			}
			if ( null === $current ) {
				return new WP_Error( 'ab_mcp_sync_target_gone', sprintf( 'The menu %d of this site is no longer there, so nothing is written for it. Make a new report (wp_site_diff) and plan again.', (int) $live_id ) );
			}
		}
		$ok = AB_MCP_Sync_Term_Writer::precheck( $prepared, $live_id, $now );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		$positions = self::positions( null === $live_id ? null : (int) $live_id );
		if ( is_wp_error( $positions ) ) {
			return $positions;
		}
		$registered = get_registered_nav_menus();
		foreach ( $prepared['locations'] as $location ) {
			if ( ! isset( $registered[ $location ] ) ) {
				return AB_MCP_Sync_Post_Writer::locked( 'menu:location_unregistered', sprintf( 'the position %s, which the active theme of this site does not register', $location ) );
			}
		}
		return true;
	}

	/**
	 * Whether the menu here is one this version writes as it is: its
	 * canonical form carries no lock (a position in another spelling locks
	 * every menu, AB_MCP_Sync_Kind_Menu::more()).
	 *
	 * @param int   $live_id The menu.
	 * @param array $source  AB_MCP_Sync_Inventory::source().
	 * @return true|WP_Error
	 */
	public static function live_rule( $live_id, array $source ) {
		$lock = AB_MCP_Sync_Post_Writer::read_lock( self::live_read( (int) $live_id, $source ), sprintf( 'the menu %d of this site', (int) $live_id ) );
		return null === $lock ? true : $lock;
	}

	/**
	 * The parts of the journal: the row and the meta of the term (as the kind
	 * term), and each position whose value changes: one the target names
	 * (self), one the menu holds here and the target does not (unset).
	 *
	 * @param array      $prepared target_of().
	 * @param array|null $current  current(); null for a new menu.
	 * @return array[]
	 */
	public static function journal_parts( array $prepared, $current ) {
		$out   = AB_MCP_Sync_Term_Writer::journal_parts( $prepared, is_array( $current ) ? $current : null );
		$state = is_array( $current ) ? $current : self::positions( null );
		$parts = is_array( $state ) && isset( $state['parts'] ) ? $state['parts'] : array();
		foreach ( self::location_targets( $prepared, $parts ) as $name => $target ) {
			$before = isset( $parts[ $name ] ) ? $parts[ $name ] : null;
			if ( $before === $target ) {
				continue;
			}
			$out[] = array(
				'part'   => $name,
				'way'    => self::WAY_LOCATION,
				'before' => AB_MCP_Sync_Shared_Row::hash( $before ),
				'target' => AB_MCP_Sync_Shared_Row::hash( $target ),
			);
		}
		return $out;
	}

	/**
	 * The positions the step decides: each the target names (self), each the
	 * menu holds here and the target does not (null: unset), by their parts.
	 *
	 * @param array $prepared target_of().
	 * @param array $parts    The parts of the state here.
	 * @return array<string,string|null> location:<name> => self or null.
	 */
	private static function location_targets( array $prepared, array $parts ) {
		$out = array();
		foreach ( $parts as $name => $value ) {
			if ( 0 === strpos( (string) $name, 'location:' ) && self::SELF === $value ) {
				$out[ (string) $name ] = null;
			}
		}
		foreach ( $prepared['locations'] as $location ) {
			$out[ 'location:' . $location ] = self::SELF;
		}
		ksort( $out, SORT_STRING );
		return $out;
	}

	/* ---------------------------------------------------------- reading */

	/**
	 * The menu here: its term (as the kind term reads it) and the positions of
	 * the active theme as parts (location:<name>: self where the position
	 * names this menu, else the bytes of its value) with the rest of the row
	 * of the theme mods (mods:rest); null where it is not there or no menu.
	 *
	 * @param int $live_id The menu.
	 * @return array|null|WP_Error
	 */
	public static function current( $live_id ) {
		$term = AB_MCP_Sync_Term_Writer::current( (int) $live_id );
		if ( ! is_array( $term ) ) {
			return $term;
		}
		if ( 'nav_menu' !== $term['row']['taxonomy'] ) {
			return null;
		}
		$positions = self::positions( (int) $live_id );
		if ( is_wp_error( $positions ) ) {
			return $positions;
		}
		return $term + array( 'parts' => $positions['parts'] );
	}

	/**
	 * The positions of the active theme, from the rows (the option stylesheet,
	 * the row of its theme mods): the option, its bytes, the positions and the
	 * parts; for $self the positions that name it read self.
	 *
	 * @param int|null $self The menu, null for none.
	 * @return array{option:string,raw:string|null,mods:array,locations:array,parts:array}|WP_Error
	 */
	public static function positions( $self ) {
		$sheet = AB_MCP_Sync_Rows::option( 'stylesheet' );
		if ( ! is_array( $sheet ) || '' === (string) $sheet['value'] ) {
			return new WP_Error( 'ab_mcp_sync_rows_unread', 'The active theme of this site could not be read (the option stylesheet), so no position of a menu is written. Try again; if it keeps failing, the database error log names the cause.' );
		}
		$option = 'theme_mods_' . (string) $sheet['value'];
		$row    = AB_MCP_Sync_Shared_Row::read( $option );
		if ( null === $row || ( is_array( $row ) && ( ! $row['ok'] || ! is_array( $row['value'] ) ) ) ) {
			return new WP_Error( 'ab_mcp_sync_rows_unread', sprintf( 'The theme mods of this site (%s) could not be read or do not unpack, so no position of a menu is written. Check them in wp-admin (Appearance → Menus → Manage Locations).', $option ) );
		}
		$mods      = false === $row ? array() : $row['value'];
		$locations = array_key_exists( 'nav_menu_locations', $mods ) ? $mods['nav_menu_locations'] : array();
		if ( ! is_array( $locations ) ) {
			return new WP_Error( 'ab_mcp_sync_rows_unread', sprintf( 'The positions of the menus in %s are no list, so no position of a menu is written. Check them in wp-admin (Appearance → Menus → Manage Locations).', $option ) );
		}
		$rest = $mods;
		unset( $rest['nav_menu_locations'] );
		$parts = array();
		foreach ( $locations as $location => $value ) {
			$parts[ 'location:' . $location ] = null !== $self && AB_MCP_Sync_Canon::id_spelling( $value ) === (int) $self ? self::SELF : AB_MCP_Sync_Shared_Row::field( $value );
		}
		$parts['mods:rest'] = AB_MCP_Sync_Shared_Row::field( $rest );
		return array(
			'option'    => $option,
			'raw'       => false === $row ? null : $row['raw'],
			'mods'      => $mods,
			'locations' => $locations,
			'parts'     => $parts,
		);
	}

	/**
	 * The fields of the row of the theme mods as they are now, for the backup
	 * of a new menu (the engine): its positions go back on the way back,
	 * where they are still the step's own (decision 23), the menu stays.
	 *
	 * @return array<string,string> location:<name> and mods:rest => bytes.
	 */
	public static function shared_state() {
		$positions = self::positions( null );
		return is_array( $positions ) ? $positions['parts'] : array();
	}

	/**
	 * Whether a part is a field of the shared row (a position).
	 *
	 * @param string $name The name of the part.
	 * @return bool
	 */
	public static function shared_part( $name ) {
		return 0 === strpos( (string) $name, 'location:' );
	}

	/**
	 * @param array|null $current current(), null for no menu.
	 * @param string     $part    The name of the part.
	 * @return string|null
	 */
	public static function part_hash( $current, $part ) {
		$part = (string) $part;
		if ( 'row' === $part || 0 === strpos( $part, 'meta:' ) ) {
			return AB_MCP_Sync_Term_Writer::part_hash( is_array( $current ) ? array_intersect_key( $current, array_flip( array( 'row', 'meta', 'terms' ) ) ) : null, $part );
		}
		if ( 0 === strpos( $part, 'terms:' ) ) {
			return AB_MCP_Sync_Post_Writer::terms_hash( array() );
		}
		if ( ! is_array( $current ) ) {
			return null;
		}
		return AB_MCP_Sync_Shared_Row::hash( isset( $current['parts'][ $part ] ) ? $current['parts'][ $part ] : null );
	}

	/**
	 * @param array $columns The written columns of the term.
	 * @return string
	 */
	public static function row_hash( array $columns ) {
		return AB_MCP_Sync_Term_Writer::row_hash( $columns );
	}

	/**
	 * @param int   $live_id The menu.
	 * @param array $source  AB_MCP_Sync_Inventory::source().
	 * @return array|null
	 */
	private static function live_read( $live_id, array $source ) {
		$kind = AB_MCP_Sync_Db_Kinds::for_pair( 'menu' );
		$read = null === $kind ? null : $kind->canonical(
			array(
				'kind'   => 'menu',
				'ref'    => (string) (int) $live_id,
				'source' => $source,
			)
		);
		return is_array( $read ) ? $read : null;
	}

	/**
	 * @param int   $live_id The menu.
	 * @param array $source  AB_MCP_Sync_Inventory::source().
	 * @return string|null
	 */
	public static function live_hash( $live_id, array $source ) {
		$read = self::live_read( (int) $live_id, $source );
		return is_array( $read ) && isset( $read['hash'] ) && ! empty( $read['complete'] ) ? (string) $read['hash'] : null;
	}

	/* ----------------------------------------------------------- writing */

	/**
	 * The parts the row call writes besides the row: none; for a new menu,
	 * whose state before the call is no menu, the positions and the rest of
	 * the theme mods as they are now (the term call leaves them as they are,
	 * so a change there is another hand's), and each position the step sets
	 * that no menu holds yet as absent (Codex round 1 of branch 11, B1: the
	 * field is not there before the call nor after it).
	 *
	 * @param array      $prepared target_of().
	 * @param int|null   $live_id  The menu here, null for a new one.
	 * @param array|null $current  current() right before the row call.
	 * @return array<string,string>
	 */
	public static function with_row( array $prepared, $live_id, $current = null ) {
		unset( $current );
		if ( null !== $live_id ) {
			return array();
		}
		$positions = self::positions( null );
		$parts     = is_array( $positions ) ? $positions['parts'] : array();
		$out       = array();
		foreach ( $parts as $name => $value ) {
			$out[ (string) $name ] = AB_MCP_Sync_Shared_Row::hash( $value );
		}
		foreach ( array_keys( self::location_targets( $prepared, $parts ) ) as $name ) {
			if ( ! isset( $out[ $name ] ) ) {
				$out[ $name ] = AB_MCP_Sync_Shared_Row::hash( null );
			}
		}
		return $out;
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
	 * Write the term: as the kind term writes it (wp_insert_term(),
	 * wp_update_term() on nav_menu); edit_theme_options asked right before.
	 *
	 * @param array      $prepared target_of().
	 * @param int|null   $live_id  The menu here, null for a new one.
	 * @param array|null $pre      current() right before the call (the engine).
	 * @return int|WP_Error
	 */
	public static function write_row( array $prepared, $live_id, $pre = null ) {
		// The right, the last read against the state the engine read, the
		// call (AB_MCP_Sync_Write; Codex round 3 of branch 11, A6).
		return AB_MCP_Sync_Write::forward(
			self::class,
			null === $live_id ? null : (int) $live_id,
			$pre,
			static fn() => current_user_can( 'edit_theme_options' ) ? true : AB_MCP_Sync_Post_Writer::rights( 'change the menus of this site (edit_theme_options)' ),
			static fn() => AB_MCP_Sync_Term_Writer::write_row( $prepared, $live_id )
		);
	}

	/**
	 * Write one part with a call of its own: a meta key (as the kind term), a
	 * position through set_theme_mod( 'nav_menu_locations', … ) as the menu
	 * screen writes them (the id of the menu, or the key unset); the right
	 * and the registered position asked right before.
	 *
	 * @param int        $live_id  The menu.
	 * @param string     $name     The part.
	 * @param array      $prepared target_of().
	 * @param array|null $pre      current() right before the call.
	 * @return true|WP_Error
	 */
	public static function write_part( $live_id, $name, array $prepared, $pre ) {
		$name = (string) $name;
		if ( 0 === strpos( $name, 'meta:' ) ) {
			// As the kind term writes it, compared as the whole menu with its
			// positions (Codex round 4 of branch 11, item 2: a hook of the
			// right that moves a position is seen before the write).
			return AB_MCP_Sync_Term_Writer::write_part( (int) $live_id, $name, $prepared, $pre, self::class );
		}
		if ( 0 !== strpos( $name, 'location:' ) ) {
			return new WP_Error( 'ab_mcp_sync_write_failed', sprintf( 'The part %s is of a name the kind menu does not write. Check the menu in wp-admin (Appearance → Menus).', $name ) );
		}
		$location = substr( $name, strlen( 'location:' ) );
		$set      = in_array( $location, $prepared['locations'], true );
		// The right and the registered position, the last read of the menu
		// against the state the engine read, the call (AB_MCP_Sync_Write;
		// Codex round 3 of branch 11, A6).
		return AB_MCP_Sync_Write::forward(
			self::class,
			(int) $live_id,
			$pre,
			static function () use ( $location, $set ) {
				$refusal = self::location_refusal( $location, $set );
				return null === $refusal ? true : $refusal;
			},
			static function () use ( $live_id, $location, $set ) {
				// The caches of the row emptied first (Codex round 1 of branch
				// 11, A1): set_theme_mod() reads the theme mods again through the
				// cache of the options, so it changes the row as it is now, the
				// one read here, and no other field of it goes back to a cached
				// value.
				AB_MCP_Sync_Shared_Row::forget( 'stylesheet' );
				$positions = self::positions( (int) $live_id );
				if ( is_wp_error( $positions ) ) {
					return $positions;
				}
				AB_MCP_Sync_Shared_Row::forget( $positions['option'] );
				$locations = $positions['locations'];
				if ( $set ) {
					$locations[ $location ] = (int) $live_id;
				} else {
					unset( $locations[ $location ] );
				}
				set_theme_mod( 'nav_menu_locations', $locations );
				return true;
			}
		);
	}

	/**
	 * Why a position is not written: the right, or a position the active
	 * theme does not register (asked for a position that is set).
	 *
	 * @param string $location The position.
	 * @param bool   $set      Whether it is set to the menu (else unset).
	 * @return WP_Error|null
	 */
	private static function location_refusal( $location, $set ) {
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return AB_MCP_Sync_Post_Writer::rights( sprintf( 'set the position %s of a menu (edit_theme_options)', $location ) );
		}
		$registered = get_registered_nav_menus();
		if ( $set && ! isset( $registered[ $location ] ) ) {
			return AB_MCP_Sync_Post_Writer::locked( 'menu:location_unregistered', sprintf( 'the position %s, which the active theme of this site does not register', $location ) );
		}
		return null;
	}

	/**
	 * After the writes and the way back (decision 33): the caches of the term,
	 * of the theme mods and the page cache of the whole site (a menu shows on
	 * every page).
	 *
	 * @param int $live_id The menu.
	 * @return string[] What was emptied.
	 */
	public static function post_write( $live_id ) {
		$done      = AB_MCP_Sync_Term_Writer::post_write( (int) $live_id );
		$positions = self::positions( null );
		if ( is_array( $positions ) ) {
			AB_MCP_Sync_Shared_Row::forget( $positions['option'] );
			$done[] = 'options';
		}
		if ( class_exists( 'AB_MCP_Page_Cache' ) && is_array( AB_MCP_Page_Cache::purge_site() ) ) {
			$done[] = 'page_cache_site';
		}
		return $done;
	}

	/**
	 * What the takeover lock covers: the term, new terms of nav_menu, and the
	 * row of the theme mods (the positions).
	 *
	 * @param int|null $live_id The menu, null for a new one.
	 * @param string   $subtype nav_menu.
	 * @return array{posts:array,taxonomies:string[],terms:array,options:string[]}
	 */
	public static function guard_of( $live_id, $subtype ) {
		unset( $subtype );
		$positions = self::positions( null );
		return array(
			'posts'      => array(),
			'taxonomies' => array( 'nav_menu' ),
			'terms'      => null === $live_id ? array() : array( (int) $live_id => 'nav_menu' ),
			'options'    => is_array( $positions ) ? array( $positions['option'] ) : array(),
		);
	}

	/**
	 * @param array $object The object of a journal.
	 * @return string nav_menu.
	 */
	public static function subtype_of( array $object ) {
		unset( $object );
		return 'nav_menu';
	}

	/**
	 * @param array $prepared target_of().
	 * @return string The name of the menu.
	 */
	public static function title_of( array $prepared ) {
		return (string) $prepared['args']['name'];
	}

	/* ---------------------------------------------------------- way back */

	/**
	 * Whether this account may put a part back (as forward): edit_theme_options;
	 * the row and the meta as the kind term asks them; a position to a menu
	 * that is there, of a position the theme registers.
	 *
	 * @param string $part    Part.
	 * @param int    $live_id The menu.
	 * @param array  $backup  current() of the backup.
	 * @param array  $now     current() now.
	 * @return true|string
	 */
	public static function restore_right( $part, $live_id, array $backup, array $now ) {
		$part = (string) $part;
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return 'change the menus of this site (edit_theme_options)';
		}
		if ( 'row' === $part || 0 === strpos( $part, 'meta:' ) ) {
			return AB_MCP_Sync_Term_Writer::restore_right( $part, (int) $live_id, array_intersect_key( $backup, array_flip( array( 'row', 'meta', 'terms' ) ) ), array_intersect_key( $now, array_flip( array( 'row', 'meta', 'terms' ) ) ) );
		}
		if ( 0 === strpos( $part, 'location:' ) ) {
			$value = isset( $backup['parts'][ $part ] ) ? $backup['parts'][ $part ] : null;
			if ( null === $value ) {
				return true;
			}
			$registered = get_registered_nav_menus();
			if ( ! isset( $registered[ substr( $part, strlen( 'location:' ) ) ] ) ) {
				return sprintf( 'set the position %s, which the active theme of this site does not register now', substr( $part, strlen( 'location:' ) ) );
			}
			$menu = self::SELF === $value ? (int) $live_id : self::menu_of( $value );
			if ( null === $menu || ( $menu > 0 && ! is_array( AB_MCP_Sync_Rows::term( $menu, 'nav_menu' ) ) ) ) {
				return sprintf( 'set the position %s to a menu that is no longer there', substr( $part, strlen( 'location:' ) ) );
			}
		}
		return true;
	}

	/**
	 * The menu a stored value of a position names (0 for none), null where it
	 * is no whole number in the spelling of an id.
	 *
	 * @param string $bytes The bytes of the value (AB_MCP_Sync_Shared_Row::field()).
	 * @return int|null
	 */
	private static function menu_of( $bytes ) {
		$read = AB_MCP_Sync_Canon::unserialized( (string) $bytes );
		if ( ! $read['ok'] ) {
			return null;
		}
		$id = AB_MCP_Sync_Canon::id_spelling( $read['value'] );
		return null === $id ? null : (int) $id;
	}

	/**
	 * Put one part back (decision 23): the row and the meta as the kind term;
	 * a position raw, byte for byte conditional in the row of the theme mods,
	 * where it holds what the step read back.
	 *
	 * @param string $name    The name of the part.
	 * @param int    $live_id The menu.
	 * @param array  $backup  current() of the backup.
	 * @param string $hash    The hash the part has to hold.
	 * @param int    $now     Time.
	 * @return array{state:string,note?:string}|WP_Error
	 */
	public static function restore_part( $name, $live_id, array $backup, $hash, $now ) {
		$name = (string) $name;
		if ( 'row' === $name || 0 === strpos( $name, 'meta:' ) ) {
			return AB_MCP_Sync_Term_Writer::restore_part( $name, (int) $live_id, array_intersect_key( $backup, array_flip( array( 'row', 'meta', 'terms' ) ) ), $hash, $now );
		}
		if ( 0 !== strpos( $name, 'location:' ) ) {
			return array(
				'state' => 'left',
				'note'  => sprintf( 'A part %s of a menu is of a name this version does not know: left as it is.', $name ),
			);
		}
		$positions = self::positions( (int) $live_id );
		if ( is_wp_error( $positions ) ) {
			return $positions;
		}
		$was = isset( $backup['parts'][ $name ] ) ? $backup['parts'][ $name ] : null;
		$is  = isset( $positions['parts'][ $name ] ) ? $positions['parts'][ $name ] : null;
		if ( $was === $is ) {
			return array( 'state' => 'unchanged' );
		}
		if ( ! hash_equals( (string) $hash, AB_MCP_Sync_Shared_Row::hash( $is ) ) || null === $positions['raw'] ) {
			return array(
				'state' => 'left',
				'note'  => sprintf( 'The position %s was changed since the step wrote it, so it is left as it is.', substr( $name, strlen( 'location:' ) ) ),
			);
		}
		$location  = substr( $name, strlen( 'location:' ) );
		$mods      = $positions['mods'];
		$locations = $positions['locations'];
		if ( null === $was ) {
			unset( $locations[ $location ] );
		} elseif ( self::SELF === $was ) {
			$locations[ $location ] = (int) $live_id;
		} else {
			$read = AB_MCP_Sync_Canon::unserialized( $was );
			if ( ! $read['ok'] ) {
				return array(
					'state' => 'left',
					'note'  => sprintf( 'The backed-up value of the position %s does not unpack, so it is left as it is.', $location ),
				);
			}
			$locations[ $location ] = $read['value'];
		}
		$mods['nav_menu_locations'] = $locations;
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return array(
				'state' => 'left',
				'note'  => sprintf( 'Not put back: this account may not %s, asked again right before the write (a filter may have changed the rights). Set it by hand in wp-admin with an account that may.', sprintf( 'set the position %s (edit_theme_options)', $location ) ),
			);
		}
		if ( ! AB_MCP_Sync_Shared_Row::put_back( $positions['option'], (string) $positions['raw'], AB_MCP_Sync_Shared_Row::field( $mods ) ) ) {
			return array(
				'state' => 'left',
				'note'  => sprintf( 'The theme mods changed while the position %s was put back, so it is left as it is.', $location ),
			);
		}
		$after = self::positions( (int) $live_id );
		return array( 'state' => is_array( $after ) && ( isset( $after['parts'][ $name ] ) ? $after['parts'][ $name ] : null ) === $was ? 'restored' : 'unclear' );
	}

	/**
	 * What a part held before the step, never a credential.
	 *
	 * @param string     $part    Part.
	 * @param array|null $backup  current() of the backup; null for a new menu.
	 * @param array|null $now     current() now.
	 * @param mixed      $live_id The menu.
	 * @return string
	 */
	public static function before_value( $part, $backup, $now, $live_id ) {
		$part = (string) $part;
		if ( 0 !== strpos( $part, 'location:' ) ) {
			return AB_MCP_Sync_Term_Writer::before_value( $part, is_array( $backup ) ? array_intersect_key( $backup, array_flip( array( 'row', 'meta', 'terms' ) ) ) : null, is_array( $now ) ? array_intersect_key( $now, array_flip( array( 'row', 'meta', 'terms' ) ) ) : null, $live_id );
		}
		if ( ! is_array( $backup ) ) {
			return 'none: the menu was not on this site before the run.';
		}
		$was = isset( $backup['parts'][ $part ] ) ? $backup['parts'][ $part ] : null;
		if ( null === $was ) {
			return sprintf( 'position %s: no menu', substr( $part, strlen( 'location:' ) ) );
		}
		if ( self::SELF === $was ) {
			return sprintf( 'position %s: this menu', substr( $part, strlen( 'location:' ) ) );
		}
		$menu = self::menu_of( $was );
		return null === $menu ? sprintf( 'position %s: a value that is no menu id', substr( $part, strlen( 'location:' ) ) ) : sprintf( 'position %1$s: the menu %2$d', substr( $part, strlen( 'location:' ) ), $menu );
	}
}

/**
 * Class AB_MCP_Sync_Menu_Item_Writer: an item of a menu, through the core
 * way of the menu screen (wp_update_nav_menu_item()).
 *
 * WHAT IS WRITTEN: the row of the item (title, attribute title,
 * description, status, position, the parent the core takes from its
 * object), the meta the core writes from its fields (_menu_item_type, the
 * parent item, the object by _menu_item_type: a post or a term by its pair,
 * the item itself for a custom link; the object type, target, classes, XFN
 * and the address of a custom link), and its menu; in one call of the core,
 * the row call, which the journal says it writes them all (with_row()).
 * Before it every field goes through what that call would make of it
 * (sanitize_key(), sanitize_html_class(), sanitize_url(), the title blanked
 * where it is the title of its object, sanitize_post() of
 * wp_insert_post()), and a deviation refuses (menu:sanitizer). Any other
 * meta key goes by its right as for a post (decision 28). Rights:
 * edit_theme_options, edit_post at an item here, the right to make items
 * for a new one, assign_term at its menu (as the REST API and the free
 * writer ask it, AB_MCP_Tools_Menus_Users::add_menu_item()).
 *
 * THE WAY BACK: the row through wp_update_post() with the backed-up columns,
 * meta raw byte for byte conditional, the menu through wp_set_object_terms()
 * (AB_MCP_Sync_Post_Writer::restore_meta(), restore_terms()).
 */
final class AB_MCP_Sync_Menu_Item_Writer {

	/** The parts of a step, by their way (decision 19). */
	const WAY_ROW   = 'refusable';
	const WAY_META  = 'refusable';
	const WAY_TERMS = 'not_refusable';

	/** The rules a value before is composed by: the same as the kind post's. */
	const VALUE_RULES = AB_MCP_Sync_Post_Writer::VALUE_RULES;

	/** The order of the parts on the way back: the row, the meta, the menu. */
	const PART_ORDER = array( 'row', 'meta:', 'terms:' );

	/** The columns of an item the core writes from its fields (and the parent it takes from its object). */
	const ROW = array( 'post_title', 'post_excerpt', 'post_content', 'post_status', 'menu_order', 'post_parent', 'post_type' );

	/** The meta wp_update_nav_menu_item() writes from the fields of an item. */
	const CORE_META = array( '_menu_item_type', '_menu_item_menu_item_parent', '_menu_item_object_id', '_menu_item_object', '_menu_item_target', '_menu_item_classes', '_menu_item_xfn', '_menu_item_url' );

	/** The meta of an item the core keeps for itself: the time an item lost its menu. */
	const LEFT_OUT = array( '_menu_item_orphaned' );

	/** The types of items the core knows (wp_setup_nav_menu_item()). */
	const TYPES = array( 'custom', 'post_type', 'taxonomy', 'post_type_archive' );

	/** The value of _menu_item_object_id that names the item itself, in the journal. */
	const SELF = '~self';

	/* ------------------------------------------------------------ prepare */

	/**
	 * What a step writes for one item: the fields for wp_update_nav_menu_item(),
	 * the columns and meta as the core stores them, its menu, the target.
	 *
	 * @param array $line The line of the package (content, form, identity, locks).
	 * @param array $item The item of the plan (op, live_ref).
	 * @param array $ctx  map, addresses, routes; pending (the check only).
	 * @return array|WP_Error
	 */
	public static function target_of( array $line, array $item, array $ctx ) {
		$held = AB_MCP_Sync_Post_Writer::line_lock( $line );
		if ( null !== $held ) {
			return $held;
		}
		if ( ! isset( $line['content']['row'], $line['content']['meta'], $line['content']['terms'], $line['form'] ) || ! is_array( $line['form'] ) || ! is_array( $line['content']['terms'] ) ) {
			return AB_MCP_Sync_Post_Writer::locked( 'post:content_missing', 'the package carries no content of the menu item (a row of it could not be read on the staging site)' );
		}
		$menus = array();
		foreach ( $line['content']['terms'] as $term ) {
			if ( ! is_array( $term ) || 'nav_menu' !== AB_MCP_Sync_Post_Writer::bytes( $term['taxonomy'] ?? '' ) ) {
				return AB_MCP_Sync_Post_Writer::locked( 'menu:item_shape', 'the menu item of the staging site carries a term of another taxonomy than nav_menu' );
			}
			$menus[] = (int) $term['term_id'];
		}
		$row = array();
		foreach ( self::ROW as $column ) {
			if ( ! isset( $line['content']['row'][ $column ] ) ) {
				return AB_MCP_Sync_Post_Writer::locked( 'post:content_missing', sprintf( 'the package names no column %s of the menu item', $column ) );
			}
			$row[ $column ] = AB_MCP_Sync_Post_Writer::bytes( $line['content']['row'][ $column ] );
		}
		if ( 'nav_menu_item' !== $row['post_type'] ) {
			return AB_MCP_Sync_Post_Writer::locked( 'menu:item_shape', sprintf( 'the menu item of the staging site is a post of the type %s', $row['post_type'] ) );
		}
		if ( ! in_array( $row['post_status'], array( 'publish', 'draft' ), true ) ) {
			return AB_MCP_Sync_Post_Writer::locked( 'menu:item_shape', sprintf( 'the menu item of the staging site has the status %s; the core gives an item publish or draft', $row['post_status'] ) );
		}
		if ( 1 !== preg_match( '/^[1-9][0-9]*\z/', $row['menu_order'] ) ) {
			return AB_MCP_Sync_Post_Writer::locked( 'menu:item_shape', sprintf( 'the menu item of the staging site has the position %s; the core gives an item one from 1 on', $row['menu_order'] ) );
		}
		$meta = array();
		foreach ( $line['content']['meta'] as $pair ) {
			$meta[ AB_MCP_Sync_Post_Writer::bytes( $pair[0] ) ][] = AB_MCP_Sync_Post_Writer::bytes( $pair[1] );
		}
		foreach ( self::CORE_META as $key ) {
			if ( ! isset( $meta[ $key ] ) || 1 !== count( $meta[ $key ] ) ) {
				return AB_MCP_Sync_Post_Writer::locked( 'menu:item_shape', sprintf( 'the menu item of the staging site has %1$d rows of %2$s, where the core writes one', isset( $meta[ $key ] ) ? count( $meta[ $key ] ) : 0, $key ) );
			}
		}
		$type = $meta['_menu_item_type'][0];
		if ( ! in_array( $type, self::TYPES, true ) ) {
			return AB_MCP_Sync_Post_Writer::locked( 'menu:item_shape', sprintf( 'the menu item of the staging site is of the type %s', $type ) );
		}
		$live_id = 'update' === $item['op'] ? (int) $item['live_ref'] : null;
		$plan    = AB_MCP_Sync_Refs::plan( $ctx['map'], $ctx['addresses'], isset( $ctx['routes'] ) ? $ctx['routes'] : null );
		$pending = isset( $ctx['pending'] ) && is_array( $ctx['pending'] ) ? $ctx['pending'] : array();
		$changed = false;
		$written = array();
		foreach ( self::CORE_META as $key ) {
			if ( '_menu_item_object_id' === $key && 'custom' === $type ) {
				// The item itself: its id once it is there (the core sets it).
				$written[ $key ] = self::SELF;
				continue;
			}
			$plan['missing'] = array();
			$written[ $key ] = AB_MCP_Sync_Refs::rewrite_menu_item_meta( $key, $meta[ $key ][0], $type, $live_id, $plan, $changed );
			if ( array() !== AB_MCP_Sync_Post_Writer::unresolved( $plan['missing'], $pending ) ) {
				return AB_MCP_Sync_Post_Writer::locked( 'menu:reference_unmapped', sprintf( '%1$s of the menu item: %2$s', $key, AB_MCP_Sync_Post_Writer::missing_text( $plan['missing'] ) ) );
			}
		}
		// A reference the run makes first (the check only): its id here is not
		// known yet; the boundary maps it by the pair its step made.
		$parent_pending = '0' !== $meta['_menu_item_menu_item_parent'][0] && $written['_menu_item_menu_item_parent'] === $meta['_menu_item_menu_item_parent'][0] && isset( $pending['post'][ (int) $meta['_menu_item_menu_item_parent'][0] ] ) && ! isset( $ctx['map']['post'][ (int) $meta['_menu_item_menu_item_parent'][0] ] );
		$object_pending = in_array( $type, array( 'post_type', 'taxonomy' ), true ) && ! isset( $ctx['map'][ 'taxonomy' === $type ? 'term' : 'post' ][ (int) $meta['_menu_item_object_id'][0] ] );
		$texts          = array();
		foreach ( array( 'post_title', 'post_excerpt', 'post_content' ) as $column ) {
			$plan['missing']  = array();
			$texts[ $column ] = AB_MCP_Sync_Refs::rewrite_text( $row[ $column ], $plan, array(), $changed );
			if ( array() !== AB_MCP_Sync_Post_Writer::unresolved( $plan['missing'], $pending ) ) {
				return AB_MCP_Sync_Post_Writer::locked( 'menu:reference_unmapped', sprintf( 'the %1$s of the menu item: %2$s', $column, AB_MCP_Sync_Post_Writer::missing_text( $plan['missing'] ) ) );
			}
		}
		// The menu, by its pair; one the run makes first is pending.
		if ( 1 !== count( $menus ) ) {
			return AB_MCP_Sync_Post_Writer::locked( 'menu:item_shape', sprintf( 'the menu item of the staging site is in %d menus, where the core keeps it in one', count( $menus ) ) );
		}
		$staging_menu = (int) $menus[0];
		$plan['missing'] = array();
		$menu            = AB_MCP_Sync_Refs::mapped_id( $plan, 'term', $staging_menu );
		$menu_pending    = null === $menu && isset( $pending['term'][ $staging_menu ] );
		if ( null === $menu && ! $menu_pending ) {
			return AB_MCP_Sync_Post_Writer::locked( 'menu:reference_unmapped', sprintf( 'the menu %d of the staging site the item is in', $staging_menu ) );
		}
		// The other meta (decision 28): by its right, as for a post.
		$other = array();
		$extra = AB_MCP_Redactor::extra_keys();
		foreach ( $meta as $key => $values ) {
			$key = (string) $key;
			if ( in_array( $key, self::CORE_META, true ) || in_array( $key, self::LEFT_OUT, true ) || AB_MCP_Sync_Exclusions::post_meta( $key ) || AB_MCP_Sync_Credentials::names_credential( $key, $extra ) ) {
				continue;
			}
			if ( 'create' === $item['op'] && ! AB_MCP_Sync_Post_Writer::new_meta_allowed( $key, 'nav_menu_item' ) ) {
				return AB_MCP_Sync_Post_Writer::locked( 'post:create_with_object_meta', sprintf( 'meta key %s of the new menu item', $key ) );
			}
			foreach ( $values as $raw ) {
				$plan['missing'] = array();
				$value           = AB_MCP_Sync_Post_Writer::meta_value( $raw, $plan, $changed );
				if ( is_wp_error( $value ) ) {
					return $value;
				}
				if ( array() !== AB_MCP_Sync_Post_Writer::unresolved( $plan['missing'], $pending ) ) {
					return AB_MCP_Sync_Post_Writer::locked( 'menu:reference_unmapped', sprintf( 'a reference in the meta key %s of the menu item without a counterpart on this site', $key ) );
				}
				$other[ $key ][] = $value;
			}
		}
		$classes = AB_MCP_Sync_Canon::unserialized( $written['_menu_item_classes'] );
		if ( ! $classes['ok'] || ! is_array( $classes['value'] ) || ! array_is_list( $classes['value'] ) || array() !== array_filter( $classes['value'], static fn( $c ) => ! is_string( $c ) ) ) {
			return AB_MCP_Sync_Post_Writer::locked( 'menu:sanitizer', 'the classes of the menu item are no list of names (_menu_item_classes)' );
		}
		$target = AB_MCP_Sync_Canon::mapped( $line['form'], $ctx['map'] );
		$fields = array(
			'menu-item-object-id'   => 'custom' === $type ? 0 : (int) $written['_menu_item_object_id'],
			'menu-item-object'      => $written['_menu_item_object'],
			'menu-item-parent-id'   => (int) $written['_menu_item_menu_item_parent'],
			'menu-item-position'    => (int) $row['menu_order'],
			'menu-item-type'        => $type,
			'menu-item-title'       => $texts['post_title'],
			'menu-item-url'         => $written['_menu_item_url'],
			'menu-item-description' => $texts['post_content'],
			'menu-item-attr-title'  => $texts['post_excerpt'],
			'menu-item-target'      => $written['_menu_item_target'],
			'menu-item-classes'     => implode( ' ', $classes['value'] ),
			'menu-item-xfn'         => $written['_menu_item_xfn'],
			'menu-item-status'      => $row['post_status'],
		);
		$pending = $menu_pending || $parent_pending || $object_pending;
		return array(
			'type'           => 'nav_menu_item',
			'status'         => null,
			'fields'         => $fields,
			'menu'           => null === $menu ? 0 : (int) $menu,
			'pending'        => $pending,
			'columns'        => array(
				'post_title'   => $texts['post_title'],
				'post_excerpt' => $texts['post_excerpt'],
				'post_content' => $texts['post_content'],
				'post_status'  => $row['post_status'],
				'menu_order'   => $row['menu_order'],
				// The parent the core takes from the object (an object the run
				// makes first has none here yet: the check only).
				'post_parent'  => $pending ? '0' : (string) self::original_parent( $fields, $live_id ),
				'post_type'    => 'nav_menu_item',
			),
			'core'           => $written,
			'meta'           => $other,
			'target_form'    => $target,
			'target_hash'    => AB_MCP_Sync_Canon::hash( $target ),
			'identity'       => $line['identity'],
			'as_draft'       => false,
		);
	}

	/**
	 * The checks before the first part (decision 17, step 4): the rights; the
	 * menu; what wp_update_nav_menu_item() would store for every field (the
	 * sanitizers ahead); the other meta by its right; the parent the core
	 * takes from the object, into the target of the row.
	 *
	 * @param array    $prepared target_of().
	 * @param int|null $live_id  The item here, null for a new one.
	 * @param int      $now      Time (not used).
	 * @return true|WP_Error
	 */
	public static function precheck( array $prepared, $live_id, $now ) {
		unset( $now );
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return AB_MCP_Sync_Post_Writer::rights( 'change the menus of this site (edit_theme_options)' );
		}
		$current = null;
		if ( null !== $live_id ) {
			$current = self::current( (int) $live_id );
			if ( is_wp_error( $current ) ) {
				return $current;
			}
			if ( null === $current ) {
				return new WP_Error( 'ab_mcp_sync_target_gone', sprintf( 'The menu item %d of this site is no longer there, so nothing is written for it. Make a new report (wp_site_diff) and plan again.', (int) $live_id ) );
			}
			if ( ! current_user_can( 'edit_post', (int) $live_id ) ) {
				return AB_MCP_Sync_Post_Writer::rights( sprintf( 'edit the menu item %d (edit_post)', (int) $live_id ) );
			}
		} else {
			$pto = get_post_type_object( 'nav_menu_item' );
			if ( ! is_object( $pto ) || ! current_user_can( $pto->cap->create_posts ) ) {
				return AB_MCP_Sync_Post_Writer::rights( 'make a menu item (create_posts of nav_menu_item)' );
			}
		}
		if ( empty( $prepared['pending'] ) ) {
			$menu = (int) $prepared['menu'];
			if ( ! is_array( AB_MCP_Sync_Rows::term( $menu, 'nav_menu' ) ) ) {
				return AB_MCP_Sync_Post_Writer::locked( 'menu:reference_unmapped', sprintf( 'the menu %d of this site is not there', $menu ) );
			}
			if ( ! current_user_can( 'assign_term', $menu ) ) {
				return AB_MCP_Sync_Post_Writer::rights( sprintf( 'add items to the menu %d (assign_term)', $menu ) );
			}
			$refusal = self::sanitizer_refusal( $prepared, $live_id );
			if ( null !== $refusal ) {
				return AB_MCP_Sync_Post_Writer::locked( 'menu:sanitizer', $refusal );
			}
		}
		// The other meta of an item here (decision 28): each key whose values
		// differ, by the right of its operation at the item.
		$keys = is_array( $current ) ? self::differing( $prepared, $current ) : array_keys( $prepared['meta'] );
		foreach ( $keys as $key ) {
			if ( is_array( $current ) ) {
				$may = AB_MCP_Sync_Post_Writer::meta_right( (int) $live_id, $key, self::targets( $prepared, $key ), AB_MCP_Sync_Post_Writer::meta_values( $current, $key ) );
				if ( true !== $may ) {
					return AB_MCP_Sync_Post_Writer::locked( '*:meta_without_rule', sprintf( 'meta key %1$s of the menu item on this site: %2$s', $key, $may ) );
				}
			}
			foreach ( isset( $prepared['meta'][ $key ] ) ? $prepared['meta'][ $key ] : array() as $value ) {
				if ( AB_MCP_Sync_Post_Writer::db_form( sanitize_meta( (string) $key, $value, 'post', 'nav_menu_item' ) ) !== AB_MCP_Sync_Post_Writer::stored_form( $value ) ) {
					return AB_MCP_Sync_Post_Writer::locked( 'menu:sanitizer', sprintf( 'meta key %s (sanitize_meta() changes its value)', $key ) );
				}
			}
		}
		if ( null !== $live_id ) {
			$spelled = array_merge( self::CORE_META, $keys );
			$refusal = AB_MCP_Sync_Post_Writer::spelling_refusal( (int) $live_id, $spelled );
			if ( null !== $refusal ) {
				return $refusal;
			}
		}
		return true;
	}

	/**
	 * Why wp_update_nav_menu_item() would store another value than the target
	 * for a field, or null (wp-includes/nav-menu.php, the function step by
	 * step): the keys, the classes and the XFN by sanitize_key() and
	 * sanitize_html_class(), the address of a custom link by sanitize_url()
	 * (and none for another type), the title blanked where it is the title of
	 * the object here, the description of a blank item, the parent item not
	 * the item itself, the texts through sanitize_post() of wp_insert_post()
	 * in the rights of this account.
	 *
	 * @param array    $prepared target_of().
	 * @param int|null $live_id  The item here.
	 * @return string|null
	 */
	private static function sanitizer_refusal( array $prepared, $live_id ) {
		$f    = $prepared['fields'];
		$core = $prepared['core'];
		$type = (string) $f['menu-item-type'];
		foreach ( array( '_menu_item_type' => $type, '_menu_item_object' => 'custom' === $type ? 'custom' : sanitize_key( (string) $f['menu-item-object'] ), '_menu_item_target' => sanitize_key( (string) $f['menu-item-target'] ) ) as $key => $stored ) {
			if ( sanitize_key( (string) $core[ $key ] ) !== (string) $core[ $key ] || $stored !== (string) $core[ $key ] ) {
				return sprintf( '%s (sanitize_key() changes it, or the core writes another one)', $key );
			}
		}
		$classes = array_map( 'sanitize_html_class', explode( ' ', (string) $f['menu-item-classes'] ) );
		if ( AB_MCP_Sync_Shared_Row::field( $classes ) !== (string) $core['_menu_item_classes'] ) {
			return '_menu_item_classes (sanitize_html_class() changes them)';
		}
		if ( implode( ' ', array_map( 'sanitize_html_class', explode( ' ', (string) $f['menu-item-xfn'] ) ) ) !== (string) $core['_menu_item_xfn'] ) {
			return '_menu_item_xfn (sanitize_html_class() changes it)';
		}
		$url = 'custom' === $type ? sanitize_url( trim( (string) $f['menu-item-url'] ) ) : '';
		if ( $url !== (string) $core['_menu_item_url'] ) {
			return '_menu_item_url (sanitize_url() changes it, or the item is no custom link and the core keeps no address for it)';
		}
		if ( (string) (int) $core['_menu_item_menu_item_parent'] !== (string) $core['_menu_item_menu_item_parent'] || ( null !== $live_id && (int) $core['_menu_item_menu_item_parent'] === (int) $live_id ) ) {
			return '_menu_item_menu_item_parent (not the id of another item)';
		}
		if ( 'custom' !== $type ) {
			$original = self::original_title( $type, (string) $f['menu-item-object'], (int) $f['menu-item-object-id'] );
			$title    = (string) $f['menu-item-title'] === $original ? '' : (string) $f['menu-item-title'];
			if ( $title !== (string) $prepared['columns']['post_title'] ) {
				return 'the title is the title of its object on this site, which the core stores as no title';
			}
			if ( '' === $title && '' === (string) $f['menu-item-description'] ) {
				return 'the item has no title and no description, which the core stores as a description of one space';
			}
		}
		$fields    = array( 'post_title' => (string) $f['menu-item-title'], 'post_content' => (string) $f['menu-item-description'], 'post_excerpt' => (string) $f['menu-item-attr-title'] );
		$slashed   = wp_slash( $fields );
		$sanitized = (array) sanitize_post( $slashed + array( 'post_type' => 'nav_menu_item' ), 'db' );
		foreach ( array_keys( $fields ) as $field ) {
			if ( ! isset( $sanitized[ $field ] ) || (string) $sanitized[ $field ] !== (string) $slashed[ $field ] ) {
				return sprintf( 'field %s (the sanitizing of a save changes it, kses without unfiltered_html)', $field );
			}
		}
		return null;
	}

	/**
	 * The title of the object of an item on this site, from its rows, as
	 * wp_update_nav_menu_item() compares the title with it.
	 *
	 * @param string $type      _menu_item_type.
	 * @param string $object    _menu_item_object.
	 * @param int    $object_id The object here.
	 * @return string|null
	 */
	private static function original_title( $type, $object, $object_id ) {
		if ( 'post_type' === $type ) {
			$row = AB_MCP_Sync_Rows::post( (int) $object_id );
			return is_array( $row ) ? (string) $row['post_title'] : null;
		}
		if ( 'taxonomy' === $type ) {
			$row = AB_MCP_Sync_Rows::term( (int) $object_id, (string) $object );
			return is_array( $row ) ? (string) $row['name'] : null;
		}
		$pto = get_post_type_object( (string) $object );
		return is_object( $pto ) && isset( $pto->labels->archives ) ? (string) $pto->labels->archives : null;
	}

	/**
	 * The parent the core gives the row of an item (post_parent): that of
	 * its object (a post's parent, a term's parent), else the one the item
	 * has (0 for a new one).
	 *
	 * @param array    $f       The fields of target_of().
	 * @param int|null $live_id The item here, null for a new one.
	 * @return int
	 */
	private static function original_parent( array $f, $live_id ) {
		if ( 'post_type' === $f['menu-item-type'] ) {
			$row = AB_MCP_Sync_Rows::post( (int) $f['menu-item-object-id'] );
			return is_array( $row ) ? (int) $row['post_parent'] : 0;
		}
		if ( 'taxonomy' === $f['menu-item-type'] ) {
			$row = AB_MCP_Sync_Rows::term( (int) $f['menu-item-object-id'], (string) $f['menu-item-object'] );
			return is_array( $row ) ? (int) $row['parent'] : 0;
		}
		$row = null === $live_id ? null : AB_MCP_Sync_Rows::post( (int) $live_id );
		return is_array( $row ) ? (int) $row['post_parent'] : 0;
	}

	/**
	 * Whether the item here is one this version writes as it is.
	 *
	 * @param int   $live_id The item.
	 * @param array $source  AB_MCP_Sync_Inventory::source().
	 * @return true|WP_Error
	 */
	public static function live_rule( $live_id, array $source ) {
		$lock = AB_MCP_Sync_Post_Writer::read_lock( self::live_read( (int) $live_id, $source ), sprintf( 'the menu item %d of this site', (int) $live_id ) );
		return null === $lock ? true : $lock;
	}

	/**
	 * The parts of the journal: the row; each meta key of the core whose
	 * stored value differs from the target (for a new item each), written
	 * with the row; the menu where it differs; each other meta key whose
	 * values differ, with a call of its own.
	 *
	 * @param array      $prepared target_of().
	 * @param array|null $current  current(); null for a new item.
	 * @return array[]
	 */
	public static function journal_parts( array $prepared, $current ) {
		$out = array(
			array(
				'part'   => 'row',
				'way'    => self::WAY_ROW,
				'before' => is_array( $current ) ? self::row_hash( $current['row'] ) : null,
				'target' => self::row_hash( $prepared['columns'] ),
			),
		);
		foreach ( self::core_targets( $prepared ) as $name => $hash ) {
			$before = is_array( $current ) ? self::part_hash( $current, $name ) : self::part_hash( null, $name );
			if ( is_array( $current ) && $before === $hash ) {
				continue;
			}
			$out[] = array(
				'part'   => $name,
				'way'    => 0 === strpos( $name, 'terms:' ) ? self::WAY_TERMS : self::WAY_META,
				'before' => $before,
				'target' => $hash,
			);
		}
		foreach ( is_array( $current ) ? self::differing( $prepared, $current ) : array_keys( $prepared['meta'] ) as $key ) {
			$out[] = array(
				'part'   => 'meta:' . $key,
				'way'    => self::WAY_META,
				'before' => is_array( $current ) ? AB_MCP_Sync_Post_Writer::meta_hash( AB_MCP_Sync_Post_Writer::meta_values( $current, $key ) ) : AB_MCP_Sync_Post_Writer::meta_hash( array() ),
				'target' => AB_MCP_Sync_Post_Writer::meta_hash( self::targets( $prepared, $key ) ),
			);
		}
		return $out;
	}

	/**
	 * The parts the core writes with the row (wp_update_nav_menu_item()):
	 * every meta key of CORE_META and the menu, by the hash it is to hold.
	 *
	 * @param array $prepared target_of().
	 * @return array<string,string>
	 */
	private static function core_targets( array $prepared ) {
		$out = array();
		foreach ( self::CORE_META as $key ) {
			$out[ 'meta:' . $key ] = AB_MCP_Sync_Post_Writer::meta_hash( array( (string) $prepared['core'][ $key ] ) );
		}
		$out['terms:nav_menu'] = AB_MCP_Sync_Post_Writer::terms_hash( array( (string) (int) $prepared['menu'] ) );
		return $out;
	}

	/**
	 * The other meta keys whose values differ between the target and the item
	 * here (never a key of the core, one it keeps for itself, one of group B
	 * or with the name of a credential).
	 *
	 * @param array $prepared target_of().
	 * @param array $current  current().
	 * @return string[]
	 */
	private static function differing( array $prepared, array $current ) {
		$keys = array_keys( $prepared['meta'] );
		foreach ( $current['meta'] as $row ) {
			$keys[] = (string) $row['key'];
		}
		$out   = array();
		$extra = AB_MCP_Redactor::extra_keys();
		foreach ( array_unique( array_map( 'strval', $keys ) ) as $key ) {
			if ( in_array( $key, self::CORE_META, true ) || in_array( $key, self::LEFT_OUT, true ) || AB_MCP_Sync_Credentials::names_credential( $key, $extra ) ) {
				continue;
			}
			if ( self::targets( $prepared, $key ) !== AB_MCP_Sync_Post_Writer::meta_values( $current, $key ) ) {
				$out[] = $key;
			}
		}
		sort( $out, SORT_STRING );
		return $out;
	}

	/**
	 * @param array  $prepared target_of().
	 * @param string $key      Key.
	 * @return string[]
	 */
	private static function targets( array $prepared, $key ) {
		$out = array();
		foreach ( isset( $prepared['meta'][ $key ] ) ? $prepared['meta'][ $key ] : array() as $value ) {
			$out[] = AB_MCP_Sync_Post_Writer::stored_form( $value );
		}
		return $out;
	}

	/* ---------------------------------------------------------- reading */

	/**
	 * The item here, from its rows: the columns of ROW, its meta (without the
	 * keys of group B and the time the core keeps of an item without a
	 * menu), its terms, and its id (self); null where it is not there or no
	 * menu item.
	 *
	 * @param int $live_id The item.
	 * @return array|null|WP_Error
	 */
	public static function current( $live_id ) {
		$post = AB_MCP_Sync_Post_Writer::current( (int) $live_id );
		if ( ! is_array( $post ) ) {
			return $post;
		}
		if ( 'nav_menu_item' !== $post['row']['post_type'] ) {
			return null;
		}
		$row = array();
		foreach ( self::ROW as $column ) {
			$row[ $column ] = (string) $post['row'][ $column ];
		}
		$meta = array();
		foreach ( $post['meta'] as $one ) {
			if ( ! in_array( $one['key'], self::LEFT_OUT, true ) ) {
				$meta[] = $one;
			}
		}
		return array(
			'row'   => $row,
			'meta'  => $meta,
			'terms' => $post['terms'],
			'self'  => (int) $live_id,
		);
	}

	/**
	 * The hash of one part: the row, a meta key (the object of an item that is
	 * the item itself as SELF), the terms of a taxonomy.
	 *
	 * @param array|null $current current(), null for no item.
	 * @param string     $part    The name of the part.
	 * @return string|null
	 */
	public static function part_hash( $current, $part ) {
		$part = (string) $part;
		if ( 'row' === $part ) {
			return is_array( $current ) ? self::row_hash( $current['row'] ) : null;
		}
		$state = is_array( $current ) ? $current : array(
			'row'   => array(),
			'meta'  => array(),
			'terms' => array(),
		);
		if ( 0 === strpos( $part, 'meta:' ) ) {
			$key    = substr( $part, 5 );
			$values = AB_MCP_Sync_Post_Writer::meta_values( $state, $key );
			if ( '_menu_item_object_id' === $key && isset( $state['self'] ) ) {
				foreach ( $values as $i => $value ) {
					if ( (string) (int) $state['self'] === (string) $value ) {
						$values[ $i ] = self::SELF;
					}
				}
			}
			return AB_MCP_Sync_Post_Writer::meta_hash( $values );
		}
		return AB_MCP_Sync_Post_Writer::terms_hash( AB_MCP_Sync_Post_Writer::term_entries( $state, substr( $part, 6 ) ) );
	}

	/**
	 * @param array $columns The columns of ROW.
	 * @return string
	 */
	public static function row_hash( array $columns ) {
		$row = array();
		foreach ( self::ROW as $column ) {
			$row[ $column ] = isset( $columns[ $column ] ) ? (string) $columns[ $column ] : '';
		}
		return AB_MCP_Sync_Canon::hash( $row );
	}

	/**
	 * @param int   $live_id The item.
	 * @param array $source  AB_MCP_Sync_Inventory::source().
	 * @return array|null
	 */
	private static function live_read( $live_id, array $source ) {
		$kind = AB_MCP_Sync_Db_Kinds::for_pair( 'menu_item' );
		$read = null === $kind ? null : $kind->canonical(
			array(
				'kind'   => 'menu_item',
				'ref'    => (string) (int) $live_id,
				'source' => $source,
			)
		);
		return is_array( $read ) ? $read : null;
	}

	/**
	 * @param int   $live_id The item.
	 * @param array $source  AB_MCP_Sync_Inventory::source().
	 * @return string|null
	 */
	public static function live_hash( $live_id, array $source ) {
		$read = self::live_read( (int) $live_id, $source );
		return is_array( $read ) && isset( $read['hash'] ) && ! empty( $read['complete'] ) ? (string) $read['hash'] : null;
	}

	/* ----------------------------------------------------------- writing */

	/**
	 * The parts the row call writes besides the row: every meta key of the
	 * core (wp_update_nav_menu_item() writes each with update_post_meta()),
	 * and the menu where the call sets it (a new item, or one here that is
	 * not in that menu: the core sets the term only then).
	 *
	 * @param array      $prepared target_of().
	 * @param int|null   $live_id  The item here, null for a new one.
	 * @param array|null $current  current() right before the row call.
	 * @return array<string,string>
	 */
	public static function with_row( array $prepared, $live_id, $current = null ) {
		$out = self::core_targets( $prepared );
		if ( null !== $live_id && is_array( $current ) && in_array( (int) $prepared['menu'], array_map( 'intval', AB_MCP_Sync_Post_Writer::term_keys( $current, 'nav_menu' ) ), true ) ) {
			unset( $out['terms:nav_menu'] );
		}
		return $out;
	}

	/**
	 * Whether a part is written by the row call: the meta of the core and the
	 * menu.
	 *
	 * @param array  $prepared target_of().
	 * @param string $name     The name of the part.
	 * @return bool
	 */
	public static function through_row( array $prepared, $name ) {
		unset( $prepared );
		$name = (string) $name;
		return 'terms:nav_menu' === $name || ( 0 === strpos( $name, 'meta:' ) && in_array( substr( $name, 5 ), self::CORE_META, true ) );
	}

	/**
	 * Write the item: wp_update_nav_menu_item() with the fields of the target,
	 * slashed (it hands them to wp_insert_post() and update_post_meta(), which
	 * unslash them); the rights asked right before.
	 *
	 * @param array      $prepared target_of().
	 * @param int|null   $live_id  The item here, null for a new one.
	 * @param array|null $pre      current() right before the call (the engine).
	 * @return int|WP_Error The id of the item written.
	 */
	public static function write_row( array $prepared, $live_id, $pre = null ) {
		$menu = (int) $prepared['menu'];
		// The rights, the last read against the state the engine read, the
		// call (AB_MCP_Sync_Write; Codex round 3 of branch 11, A6).
		return AB_MCP_Sync_Write::forward(
			self::class,
			null === $live_id ? null : (int) $live_id,
			$pre,
			static fn() => current_user_can( 'edit_theme_options' ) && current_user_can( 'assign_term', $menu ) && ( null === $live_id || current_user_can( 'edit_post', (int) $live_id ) ) ? true : AB_MCP_Sync_Post_Writer::rights( sprintf( 'write an item of the menu %d (edit_theme_options, assign_term, edit_post)', $menu ) ),
			static fn() => self::item_call( $prepared, $live_id, $menu )
		);
	}

	/**
	 * The call of the core for an item: its fields slashed, the dates of an
	 * item here as they are, wp_update_nav_menu_item().
	 *
	 * @param array    $prepared target_of().
	 * @param int|null $live_id  The item here, null for a new one.
	 * @param int      $menu     The menu here.
	 * @return int|WP_Error
	 */
	private static function item_call( array $prepared, $live_id, $menu ) {
		$fields = $prepared['fields'];
		foreach ( array( 'menu-item-title', 'menu-item-url', 'menu-item-description', 'menu-item-attr-title', 'menu-item-classes', 'menu-item-xfn', 'menu-item-target', 'menu-item-object' ) as $field ) {
			$fields[ $field ] = wp_slash( (string) $fields[ $field ] );
		}
		// The dates of an item here as they are: without them the core gives
		// the item the time of the call (wp_resolve_post_date() of no date),
		// which the item's form does not hold and the way back would not give
		// back.
		if ( null !== $live_id ) {
			$row = AB_MCP_Sync_Rows::post( (int) $live_id );
			if ( ! is_array( $row ) ) {
				return new WP_Error( 'ab_mcp_sync_rows_unread', sprintf( 'The row of the menu item %d could not be read right before it was written, so nothing is written. Try again; if it keeps failing, the database error log names the cause.', (int) $live_id ) );
			}
			$fields['menu-item-post-date']     = (string) $row['post_date'];
			$fields['menu-item-post-date-gmt'] = (string) $row['post_date_gmt'];
		}
		// Where menu items keep revisions (a plugin adds them), none deleted
		// by the save of one here: keeping_revisions().
		$id = null === $live_id ? wp_update_nav_menu_item( $menu, 0, $fields ) : AB_MCP_Sync_Post_Writer::keeping_revisions(
			(int) $live_id,
			static function () use ( $menu, $live_id, $fields ) {
				return wp_update_nav_menu_item( $menu, (int) $live_id, $fields );
			}
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		if ( ! is_int( $id ) || $id < 1 || ( null !== $live_id && $id !== (int) $live_id ) ) {
			return new WP_Error( 'ab_mcp_sync_write_failed', 'WordPress wrote no menu item (wp_update_nav_menu_item() gave no id, or another one). Nothing more of the step is written.' );
		}
		return $id;
	}

	/**
	 * Write one other meta key with a call of its own, to exactly the values
	 * of the target; the right at the item asked right before.
	 *
	 * @param int        $live_id  The item.
	 * @param string     $name     meta:<key>.
	 * @param array      $prepared target_of().
	 * @param array|null $pre      current() right before the call.
	 * @return true|WP_Error
	 */
	public static function write_part( $live_id, $name, array $prepared, $pre ) {
		$name = (string) $name;
		if ( 0 !== strpos( $name, 'meta:' ) ) {
			return new WP_Error( 'ab_mcp_sync_write_failed', sprintf( 'The part %s is of a name the kind menu item writes with its row only. Check the item in wp-admin (Appearance → Menus).', $name ) );
		}
		$key  = substr( $name, 5 );
		$from = is_array( $pre ) ? AB_MCP_Sync_Post_Writer::meta_values( $pre, $key ) : array();
		// The right, the last read against the state the engine read, the
		// call (AB_MCP_Sync_Write; Codex round 3 of branch 11, A6).
		return AB_MCP_Sync_Write::forward(
			self::class,
			(int) $live_id,
			$pre,
			static function () use ( $live_id, $key, $prepared, $from ) {
				$may = AB_MCP_Sync_Post_Writer::meta_right( (int) $live_id, $key, self::targets( $prepared, $key ), $from );
				return true === $may ? true : new WP_Error( 'ab_mcp_sync_rights', sprintf( 'The meta %1$s of the menu item was not written: %2$s at the item. Connect with an account that may, or leave the item out of the plan.', $key, $may ) );
			},
			static fn() => AB_MCP_Sync_Post_Writer::write_meta( (int) $live_id, $key, isset( $prepared['meta'][ $key ] ) ? $prepared['meta'][ $key ] : array(), $from )
		);
	}

	/**
	 * After the writes and the way back (decision 33): the caches of the item
	 * and of its menu, and the page cache of the whole site.
	 *
	 * @param int $live_id The item.
	 * @return string[] What was emptied.
	 */
	public static function post_write( $live_id ) {
		clean_post_cache( (int) $live_id );
		wp_cache_delete( (int) $live_id, 'post_meta' );
		wp_cache_delete( (int) $live_id, 'nav_menu_relationships' );
		$done = array( 'post', 'post_meta', 'term_relationships' );
		if ( class_exists( 'AB_MCP_Page_Cache' ) && is_array( AB_MCP_Page_Cache::purge_site() ) ) {
			$done[] = 'page_cache_site';
		}
		return $done;
	}

	/**
	 * What the takeover lock covers: the item here.
	 *
	 * @param int|null $live_id The item, null for a new one.
	 * @param string   $subtype The subtype of the plan.
	 * @return array{posts:array,taxonomies:array,terms:array,options:array}
	 */
	public static function guard_of( $live_id, $subtype ) {
		unset( $subtype );
		return array(
			'posts'      => null === $live_id ? array() : array( (int) $live_id => 'nav_menu_item' ),
			'taxonomies' => array(),
			'terms'      => array(),
			'options'    => array(),
		);
	}

	/**
	 * @param array $object The object of a journal.
	 * @return string nav_menu_item.
	 */
	public static function subtype_of( array $object ) {
		unset( $object );
		return 'nav_menu_item';
	}

	/**
	 * @param array $prepared target_of().
	 * @return string
	 */
	public static function title_of( array $prepared ) {
		return (string) $prepared['fields']['menu-item-title'];
	}

	/* ---------------------------------------------------------- way back */

	/**
	 * Whether this account may put a part back (as forward):
	 * edit_theme_options and edit_post at the item; a key of the core as the
	 * core writes it with the item; another key by the right of each
	 * operation; the menu by assign_term at the menu of the backup.
	 *
	 * @param string $part    Part.
	 * @param int    $live_id The item.
	 * @param array  $backup  current() of the backup.
	 * @param array  $now     current() now.
	 * @return true|string
	 */
	public static function restore_right( $part, $live_id, array $backup, array $now ) {
		$part = (string) $part;
		if ( ! current_user_can( 'edit_theme_options' ) || ! current_user_can( 'edit_post', (int) $live_id ) ) {
			return sprintf( 'edit the menu item %d (edit_theme_options, edit_post)', (int) $live_id );
		}
		if ( 'terms:nav_menu' === $part ) {
			foreach ( AB_MCP_Sync_Post_Writer::term_keys( $backup, 'nav_menu' ) as $menu ) {
				if ( ! current_user_can( 'assign_term', (int) $menu ) ) {
					return sprintf( 'put the item back into the menu %d (assign_term)', (int) $menu );
				}
			}
			return true;
		}
		if ( 0 === strpos( $part, 'meta:' ) && ! in_array( substr( $part, 5 ), self::CORE_META, true ) ) {
			$key = substr( $part, 5 );
			$may = AB_MCP_Sync_Post_Writer::ops_right( 'post', (int) $live_id, $key, AB_MCP_Sync_Post_Writer::restore_ops( AB_MCP_Sync_Post_Writer::meta_values( $backup, $key ), isset( $now['meta'] ) ? AB_MCP_Sync_Post_Writer::meta_values( $now, $key ) : array() ) );
			return true === $may ? true : sprintf( 'change the meta key %1$s of the menu item (%2$s)', $key, $may );
		}
		return true;
	}

	/**
	 * Put one part back (decision 23): the row through wp_update_post() with
	 * the backed-up columns where it holds what the step read back; meta raw,
	 * byte for byte conditional; the menu through wp_set_object_terms().
	 *
	 * @param string $name    The name of the part.
	 * @param int    $live_id The item.
	 * @param array  $backup  current() of the backup.
	 * @param string $hash    The hash the part has to hold.
	 * @param int    $now     Time.
	 * @return array{state:string,note?:string}|WP_Error
	 */
	public static function restore_part( $name, $live_id, array $backup, $hash, $now ) {
		unset( $now );
		$name = (string) $name;
		$id   = (int) $live_id;
		if ( 'row' === $name ) {
			$current = self::current( $id );
			if ( ! is_array( $current ) ) {
				return is_wp_error( $current ) ? $current : array(
					'state' => 'left',
					'note'  => 'The menu item is no longer on this site.',
				);
			}
			$before = self::row_hash( $backup['row'] );
			$is     = self::row_hash( $current['row'] );
			if ( hash_equals( $before, $is ) ) {
				return array( 'state' => 'unchanged' );
			}
			if ( ! hash_equals( (string) $hash, $is ) ) {
				return array(
					'state' => 'left',
					'note'  => 'The row of the menu item was changed since the step wrote it, so it is left as it is.',
				);
			}
			$fields    = array( 'post_title' => (string) $backup['row']['post_title'], 'post_content' => (string) $backup['row']['post_content'], 'post_excerpt' => (string) $backup['row']['post_excerpt'] );
			$slashed   = wp_slash( $fields );
			$sanitized = (array) sanitize_post( $slashed + array( 'post_type' => 'nav_menu_item' ), 'db' );
			foreach ( array_keys( $fields ) as $field ) {
				if ( ! isset( $sanitized[ $field ] ) || (string) $sanitized[ $field ] !== (string) $slashed[ $field ] ) {
					return array(
						'state' => 'left',
						'note'  => sprintf( 'Not put back: the sanitizing of this account would change the backed-up %s (kses without unfiltered_html). Set it by hand in wp-admin with an account that may.', $field ),
					);
				}
			}
			// The rights after sanitize_post() and its filters (A5), the last
			// read against what the step wrote, the call (AB_MCP_Sync_Write;
			// Codex rounds 2 and 3 of branch 11, A6).
			$done = AB_MCP_Sync_Write::back(
				static fn() => current_user_can( 'edit_theme_options' ) && current_user_can( 'edit_post', $id ) ? true : sprintf( 'edit the menu item %d (edit_theme_options, edit_post)', $id ),
				static fn() => self::current( $id ),
				static fn( $again ) => is_array( $again ) && hash_equals( (string) $hash, self::row_hash( $again['row'] ) ),
				static function () use ( $id, $backup ) {
					return AB_MCP_Sync_Post_Writer::keeping_revisions(
						$id,
						static function () use ( $id, $backup ) {
							return wp_update_post(
								wp_slash(
									array(
										'ID'           => $id,
										'post_title'   => (string) $backup['row']['post_title'],
										'post_excerpt' => (string) $backup['row']['post_excerpt'],
										'post_content' => (string) $backup['row']['post_content'],
										'post_status'  => (string) $backup['row']['post_status'],
										'menu_order'   => (int) $backup['row']['menu_order'],
										'post_parent'  => (int) $backup['row']['post_parent'],
									)
								),
								true
							);
						}
					);
				},
				'The row of the menu item changed as it was put back (a hook of this site), so it is left as it is.'
			);
			if ( is_wp_error( $done ) || is_array( $done ) ) {
				return $done;
			}
			$after = self::current( $id );
			return array( 'state' => is_array( $after ) && hash_equals( $before, self::row_hash( $after['row'] ) ) ? 'restored' : 'unclear' );
		}
		if ( 0 === strpos( $name, 'meta:' ) ) {
			$key   = substr( $name, 5 );
			$state = self::current( $id );
			if ( ! is_array( $state ) ) {
				return is_wp_error( $state ) ? $state : array(
					'state' => 'left',
					'note'  => 'The menu item is no longer on this site.',
				);
			}
			// The hash of the journal names the item itself as SELF; the meta
			// of the row as it is now holds its id: compared by this kind, put
			// back by the bytes.
			if ( ! hash_equals( (string) $hash, (string) self::part_hash( $state, $name ) ) && ! hash_equals( (string) self::part_hash( $backup, $name ), (string) self::part_hash( $state, $name ) ) ) {
				return array(
					'state' => 'left',
					'note'  => sprintf( 'The meta %s was changed since the step wrote it, so it is left as it is.', $key ),
				);
			}
			return AB_MCP_Sync_Post_Writer::restore_meta( $id, $key, AB_MCP_Sync_Post_Writer::meta_values( $backup, $key ), AB_MCP_Sync_Post_Writer::meta_hash( AB_MCP_Sync_Post_Writer::meta_values( $state, $key ) ) );
		}
		if ( 'terms:nav_menu' === $name ) {
			return AB_MCP_Sync_Post_Writer::restore_terms( $id, 'nav_menu', $backup, $hash );
		}
		return array(
			'state' => 'left',
			'note'  => sprintf( 'A part %s of a menu item is of a name this version does not know: left as it is.', $name ),
		);
	}

	/**
	 * What a part held before the step, never a credential.
	 *
	 * @param string     $part    Part.
	 * @param array|null $backup  current() of the backup; null for a new item.
	 * @param array|null $now     current() now.
	 * @param mixed      $live_id The item.
	 * @return string
	 */
	public static function before_value( $part, $backup, $now, $live_id ) {
		unset( $live_id );
		if ( ! is_array( $backup ) ) {
			return 'none: the menu item was not on this site before the run.';
		}
		$part = (string) $part;
		if ( 0 === strpos( $part, 'meta:' ) ) {
			return AB_MCP_Sync_Post_Writer::cut( AB_MCP_Sync_Post_Writer::meta_before( substr( $part, 5 ), AB_MCP_Sync_Post_Writer::meta_values( $backup, substr( $part, 5 ) ) ), AB_MCP_Sync_Post_Writer::PART_CHARS );
		}
		if ( 0 === strpos( $part, 'terms:' ) ) {
			return 'menus: ' . implode( ', ', array_map( 'strval', AB_MCP_Sync_Post_Writer::term_keys( $backup, substr( $part, 6 ) ) ) );
		}
		$out = array();
		foreach ( self::ROW as $column ) {
			$was = isset( $backup['row'][ $column ] ) ? (string) $backup['row'][ $column ] : '';
			if ( ! is_array( $now ) || $was !== ( isset( $now['row'][ $column ] ) ? (string) $now['row'][ $column ] : '' ) ) {
				$out[] = $column . ': ' . AB_MCP_Sync_Post_Writer::shown_value( '', $was );
			}
		}
		return AB_MCP_Sync_Post_Writer::cut( implode( '; ', $out ), AB_MCP_Sync_Post_Writer::PART_CHARS );
	}
}

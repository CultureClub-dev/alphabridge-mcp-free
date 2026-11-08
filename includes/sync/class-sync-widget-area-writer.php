<?php
/**
 * The write side of the kind widget_area (contract A of the plan of
 * 01.11.2026; Z8 branch 11, decisions 17, 19, 23, 24, 25 and 28): a widget
 * area (a sidebar) with its instances, named by the area (ref_of()). The
 * same methods as the other kinds (AB_MCP_Sync_Engine::WRITERS).
 *
 * THE OBJECT IS A FIELD (decision 24): the list of the area is a field of
 * sidebars_widgets (the part row), each instance a field of the row of its
 * widget (instance:<base>-<number> in widget_<base>). The state of a step is
 * every field of those rows (every area, every instance of every widget
 * this site registers or names, and the rest of each row), so whatever a
 * call changes besides its own part is another hand's (the engine,
 * part_names()); a field is compared by the bytes PHP stores it with.
 *
 * WHAT IS WRITTEN: an instance through the widget writer of this plugin
 * (AB_MCP_Widget_Writer: WP_Widget::update() and widget_update_callback, in
 * the rights of this account), compared ahead with what it is to store (a
 * deviation refuses: widget_area:sanitizer), then into its row with
 * update_option() as the widget tools write it; the list of the area with
 * wp_set_sidebars_widgets(). The instances first, then the list, so the
 * list never names an instance that is not there.
 *
 * THE INSTANCES (decisions 3 and 23): the instance of the staging site and
 * that of this site are one by the mapping of the pair (base_extra: the
 * mapping a run made, map; else the instances of both sides at the base,
 * instances, by their place, where both lists hold the same widgets in the
 * same order); an instance the base does not know is new and gets its
 * number of the staging site where it is free here, else the next one
 * («Nummern neu, wo live belegt»). An instance known at the base without a
 * counterpart here, or with one placed in another area, locks the area
 * (widget_area:instance_unmapped, Z8d); an instance of this area that the
 * target does not name would be removed (widget_area:remove_instance, Z8d);
 * a widget not registered here locks (widget_area:widget_unregistered); an
 * area this site does not have is not made (widget_area:create, Z8d). The
 * pair takes the new mapping (base_extra) with its bases.
 *
 * THE WAY BACK (decision 23): a field raw, byte for byte conditional in its
 * row (AB_MCP_Sync_Shared_Row::put_back()), only where it holds what the
 * step read back, after the same checks as forward (the widget registered,
 * its update() keeping the backed-up settings). An instance the run made
 * stays, and so does the list that names it: the whole area is a rest,
 * residual, its mapping kept in base_extra (made(); the engine).
 *
 * Messages are English without gettext, as the other classes of the
 * takeover; each names the way.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-sync-shared-row.php';
require_once __DIR__ . '/class-sync-refs.php';
require_once __DIR__ . '/class-sync-pairs.php';
require_once __DIR__ . '/class-sync-db-kinds.php';
require_once __DIR__ . '/class-sync-post-writer.php';
require_once dirname( __DIR__ ) . '/tools/class-widget-writer.php';

/**
 * Class AB_MCP_Sync_Widget_Area_Writer
 */
final class AB_MCP_Sync_Widget_Area_Writer {

	/** The parts of a step, by their way: update_option() of a covered row, refusable (decision 19). */
	const WAY_FIELD = 'refusable';

	/** The rules a value before is composed by: the same as the kind post's. */
	const VALUE_RULES = AB_MCP_Sync_Post_Writer::VALUE_RULES;

	/** The order of the parts on the way back: the instances, then the list. */
	const PART_ORDER = array( 'instance:', 'row' );

	/** The row of the areas. */
	const AREAS = 'sidebars_widgets';

	/**
	 * The rest of the row of a widget that holds no instance yet (not there,
	 * or the empty list the install of WordPress leaves), as the widgets of
	 * WordPress write it with the first one (WP_Widget::save_settings()).
	 */
	const FRESH_REST = array( '_multiwidget' => 1 );

	/**
	 * The object a ref names: the area, null for none.
	 *
	 * @param mixed $raw Ref.
	 * @return string|null
	 */
	public static function ref_of( $raw ) {
		$area = is_scalar( $raw ) ? (string) $raw : '';
		return '' === $area || 'array_version' === $area ? null : $area;
	}

	/* ------------------------------------------------------------ prepare */

	/**
	 * What a step writes for one area: each instance of the staging site with
	 * its counterpart here and its settings in this site's ids and addresses,
	 * the list of the area, the mapping for the pair, the target.
	 *
	 * @param array $line The line of the package (content, form, identity, locks).
	 * @param array $item The item of the plan (op, live_ref, pair_id).
	 * @param array $ctx  map, addresses, routes; pending (the check only).
	 * @return array|WP_Error
	 */
	public static function target_of( array $line, array $item, array $ctx ) {
		$area = isset( $line['identity']['area'] ) && is_string( $line['identity']['area'] ) ? $line['identity']['area'] : '';
		if ( 'update' !== $item['op'] || (string) $item['live_ref'] !== $area ) {
			return AB_MCP_Sync_Post_Writer::locked( 'widget_area:create', sprintf( 'the area %s is not on this site', $area ) );
		}
		$held = AB_MCP_Sync_Post_Writer::line_lock( $line );
		if ( null !== $held ) {
			return $held;
		}
		if ( ! isset( $line['content']['meta'], $line['form'] ) || ! is_array( $line['content']['meta'] ) || ! is_array( $line['form'] ) ) {
			return AB_MCP_Sync_Post_Writer::locked( 'post:content_missing', sprintf( 'the package carries no instances of the area %s', $area ) );
		}
		$state = self::state( $area );
		if ( is_wp_error( $state ) ) {
			return $state;
		}
		if ( null === $state ) {
			return AB_MCP_Sync_Post_Writer::locked( 'widget_area:create', sprintf( 'the area %s is not on this site', $area ) );
		}
		$pair  = isset( $item['pair_id'] ) ? AB_MCP_Sync_Pairs::get( (int) $item['pair_id'] ) : null;
		$extra = is_array( $pair ) && is_array( $pair['base_extra'] ) ? $pair['base_extra'] : array();
		$known = self::known( $extra );
		if ( is_wp_error( $known ) ) {
			return $known;
		}
		$plan    = AB_MCP_Sync_Refs::plan( $ctx['map'], $ctx['addresses'], isset( $ctx['routes'] ) ? $ctx['routes'] : null );
		$pending = isset( $ctx['pending'] ) && is_array( $ctx['pending'] ) ? $ctx['pending'] : array();
		$taken   = array();
		$list    = array();
		$staging = array();
		$map     = array();
		$writes  = array();
		foreach ( $line['content']['meta'] as $pair ) {
			$id   = AB_MCP_Sync_Post_Writer::bytes( $pair[0] );
			$raw  = AB_MCP_Sync_Post_Writer::bytes( $pair[1] );
			$base = self::base_of( $id );
			if ( '' === $base || '' === $raw ) {
				return AB_MCP_Sync_Post_Writer::locked( 'widget_area:widget_unregistered', sprintf( 'the instance %s of the staging site is no widget with a number and settings', $id ) );
			}
			$one = array(
				'number'   => (int) substr( $id, strlen( $base ) + 1 ),
				'settings' => $raw,
			);
			if ( null === AB_MCP_Widget_Writer::widget( $base ) ) {
				return AB_MCP_Sync_Post_Writer::locked( 'widget_area:widget_unregistered', sprintf( 'the widget %s of the instance %s is not registered on this site', $base, $id ) );
			}
			$read = AB_MCP_Sync_Canon::unserialized( $one['settings'] );
			if ( ! $read['ok'] || ! is_array( $read['value'] ) ) {
				return AB_MCP_Sync_Post_Writer::locked( 'widget_area:unreadable_value', sprintf( 'the settings of the instance %s of the staging site', $id ) );
			}
			$plan['missing'] = array();
			$changed         = false;
			$settings        = AB_MCP_Sync_Refs::rewrite_widget( $base, $read['value'], $plan, $changed );
			if ( array() !== AB_MCP_Sync_Post_Writer::unresolved( $plan['missing'], $pending ) ) {
				return AB_MCP_Sync_Post_Writer::locked( 'widget_area:reference_unmapped', sprintf( 'the instance %1$s: %2$s', $id, AB_MCP_Sync_Post_Writer::missing_text( $plan['missing'] ) ) );
			}
			$live = self::counterpart( $id, $base, (int) $one['number'], $known, $state, $taken, $area );
			if ( is_wp_error( $live ) ) {
				return $live;
			}
			$taken[ $live ] = true;
			$list[]         = $live;
			$staging[]      = $id;
			$map[ $id ]     = $live;
			$number         = (int) substr( $live, strlen( $base ) + 1 );
			$writes[ $live ] = array(
				'base'     => $base,
				'number'   => $number,
				'settings' => $settings,
				'new'      => ! isset( $state['parts'][ 'instance:' . $live ] ),
				'staging'  => $id,
			);
		}
		// An instance of this area here that the target does not name would be
		// removed from it: a deletion (decisions 21 and 22).
		foreach ( $state['list'] as $live ) {
			if ( ! isset( $taken[ (string) $live ] ) ) {
				return AB_MCP_Sync_Post_Writer::locked( 'widget_area:remove_instance', sprintf( 'the instance %1$s of the area %2$s on this site has no counterpart on the staging site', (string) $live, $area ) );
			}
		}
		$kept        = $extra;
		$kept['map'] = $map + ( isset( $extra['map'] ) && is_array( $extra['map'] ) ? $extra['map'] : array() );
		ksort( $kept['map'], SORT_STRING );
		$done              = $kept;
		$done['instances'] = array(
			'live'    => $list,
			'staging' => $staging,
		);
		$target = AB_MCP_Sync_Canon::mapped( $line['form'], $ctx['map'] );
		return array(
			'type'            => 'widget_area',
			'area'            => $area,
			'status'          => null,
			'list'            => $list,
			'writes'          => $writes,
			'columns'         => array( 'area' => AB_MCP_Sync_Shared_Row::field( $list ) ),
			'base_extra'      => $done,
			'base_extra_kept' => $kept,
			'target_form'     => $target,
			'target_hash'     => AB_MCP_Sync_Canon::hash( $target ),
			'identity'        => $line['identity'],
			'as_draft'        => false,
		);
	}

	/**
	 * The instances the pair knows: staging id => live id, from the mapping a
	 * run made (map) and from the instances of both sides at the base by
	 * their place where both lists name the same widgets in the same order;
	 * an instance of the staging side at the base without such a counterpart
	 * maps to null (locked by counterpart()).
	 *
	 * @param array $extra base_extra of the pair.
	 * @return array<string,string|null>|WP_Error
	 */
	private static function known( array $extra ) {
		$out = array();
		if ( isset( $extra['instances']['staging'], $extra['instances']['live'] ) && is_array( $extra['instances']['staging'] ) && is_array( $extra['instances']['live'] ) ) {
			$s     = array_values( $extra['instances']['staging'] );
			$l     = array_values( $extra['instances']['live'] );
			$alike = count( $s ) === count( $l );
			foreach ( $s as $i => $id ) {
				$alike = $alike && is_string( $id ) && is_string( $l[ $i ] ) && self::base_of( $id ) === self::base_of( $l[ $i ] );
			}
			foreach ( $s as $i => $id ) {
				$out[ (string) $id ] = $alike ? (string) $l[ $i ] : null;
			}
		}
		foreach ( isset( $extra['map'] ) && is_array( $extra['map'] ) ? $extra['map'] : array() as $id => $live ) {
			$out[ (string) $id ] = is_string( $live ) ? $live : null;
		}
		return $out;
	}

	/**
	 * The instance of this site for one of the staging site: the one the pair
	 * maps it to (of the same widget, in this area or in none), or a new one
	 * with the number of the staging site where it is free here, else the
	 * next free number.
	 *
	 * @param string $id     The instance of the staging site.
	 * @param string $base   Its widget.
	 * @param int    $number Its number there.
	 * @param array  $known  known().
	 * @param array  $state  state() of this area.
	 * @param array  $taken  The instances of this site the area takes already.
	 * @param string $area   The area.
	 * @return string|WP_Error
	 */
	private static function counterpart( $id, $base, $number, array $known, array $state, array $taken, $area ) {
		if ( array_key_exists( $id, $known ) ) {
			$live = $known[ $id ];
			if ( null === $live || self::base_of( $live ) !== $base || ! isset( $state['parts'][ 'instance:' . $live ] ) || isset( $taken[ $live ] ) ) {
				return AB_MCP_Sync_Post_Writer::locked( 'widget_area:instance_unmapped', sprintf( 'the instance %1$s of the staging site was in the area at its base, and its counterpart on this site is not known (or no longer there)', $id ) );
			}
			$elsewhere = self::placed( $live, $state, $area );
			if ( null !== $elsewhere ) {
				return AB_MCP_Sync_Post_Writer::locked( 'widget_area:instance_unmapped', sprintf( 'the counterpart %1$s of the instance %2$s is placed in the area %3$s on this site', $live, $id, $elsewhere ) );
			}
			return $live;
		}
		$used = array();
		foreach ( array_keys( $state['parts'] ) as $name ) {
			if ( 0 === strpos( (string) $name, 'instance:' . $base . '-' ) ) {
				$used[ (int) substr( (string) $name, strlen( 'instance:' . $base . '-' ) ) ] = true;
			}
		}
		foreach ( $state['placed'] as $placed => $unused ) {
			if ( self::base_of( (string) $placed ) === $base ) {
				$used[ (int) substr( (string) $placed, strlen( $base ) + 1 ) ] = true;
			}
		}
		foreach ( array_keys( $taken ) as $placed ) {
			if ( self::base_of( (string) $placed ) === $base ) {
				$used[ (int) substr( (string) $placed, strlen( $base ) + 1 ) ] = true;
			}
		}
		if ( $number < 2 || isset( $used[ $number ] ) ) {
			$number = array() === $used ? 2 : max( 2, max( array_keys( $used ) ) + 1 );
		}
		return $base . '-' . $number;
	}

	/**
	 * The area of this site another than $area that places an instance, or
	 * null (wp_inactive_widgets counts: a widget there is placed).
	 *
	 * @param string $live  The instance.
	 * @param array  $state state().
	 * @param string $area  The area of the step.
	 * @return string|null
	 */
	private static function placed( $live, array $state, $area ) {
		return isset( $state['placed'][ $live ] ) && $state['placed'][ $live ] !== $area ? (string) $state['placed'][ $live ] : null;
	}

	/**
	 * The widget of an instance id (text of text-2), '' for none.
	 *
	 * @param string $id Instance id.
	 * @return string
	 */
	private static function base_of( $id ) {
		return 1 === preg_match( '/^(.+)-(\d+)\z/', (string) $id, $m ) ? $m[1] : '';
	}

	/**
	 * The checks before the first part: edit_theme_options; each instance
	 * through the widget writer ahead (its update() keeps the settings, its
	 * widget registered), each row written by the widgets of WordPress.
	 *
	 * @param array       $prepared target_of().
	 * @param string|null $live_id  The area.
	 * @param int         $now      Time (not used).
	 * @return true|WP_Error
	 */
	public static function precheck( array $prepared, $live_id, $now ) {
		unset( $now );
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return AB_MCP_Sync_Post_Writer::rights( 'change the widgets of this site (edit_theme_options)' );
		}
		$state = self::state( null === $live_id ? (string) $prepared['area'] : (string) $live_id );
		if ( is_wp_error( $state ) ) {
			return $state;
		}
		if ( null === $state ) {
			return AB_MCP_Sync_Post_Writer::locked( 'widget_area:create', sprintf( 'the area %s is not on this site', (string) $prepared['area'] ) );
		}
		foreach ( $prepared['writes'] as $live => $write ) {
			$refusal = self::sanitizer_refusal( $write['base'], (int) $write['number'], $write['settings'], $state );
			if ( null !== $refusal ) {
				return $refusal;
			}
		}
		return true;
	}

	/**
	 * Why the widget writer would store other settings than the target, or
	 * the row of the widget is not one the widgets of WordPress wrote: as a
	 * refusal, or null.
	 *
	 * @param string $base     The widget.
	 * @param int    $number   The number here.
	 * @param array  $settings The settings of the target.
	 * @param array  $state    state().
	 * @return WP_Error|null
	 */
	private static function sanitizer_refusal( $base, $number, array $settings, array $state ) {
		if ( ! isset( $state['rows'][ $base ] ) || ! $state['rows'][ $base ]['marked'] ) {
			return AB_MCP_Sync_Post_Writer::locked( 'widget_area:widget_unregistered', sprintf( 'the row widget_%s of this site was not written by the widgets of WordPress (no _multiwidget), so it is not changed', $base ) );
		}
		$old   = isset( $state['rows'][ $base ]['value'][ $number ] ) && is_array( $state['rows'][ $base ]['value'][ $number ] ) ? $state['rows'][ $base ]['value'][ $number ] : array();
		$clean = AB_MCP_Widget_Writer::sanitize( $base, $number, $settings, $old );
		if ( is_wp_error( $clean ) ) {
			return AB_MCP_Sync_Post_Writer::locked( 'unregistered_widget' === $clean->get_error_code() || 'ab_mcp_unregistered_widget' === $clean->get_error_code() ? 'widget_area:widget_unregistered' : 'widget_area:sanitizer', sprintf( 'the instance %1$s-%2$d: %3$s', $base, $number, $clean->get_error_message() ) );
		}
		if ( AB_MCP_Sync_Shared_Row::field( $clean ) !== AB_MCP_Sync_Shared_Row::field( $settings ) ) {
			return AB_MCP_Sync_Post_Writer::locked( 'widget_area:sanitizer', sprintf( 'the instance %1$s-%2$d (the update() of the widget or widget_update_callback changes its settings)', $base, $number ) );
		}
		return null;
	}

	/**
	 * Whether the area here is one this version writes as it is.
	 *
	 * @param string $live_id The area.
	 * @param array  $source  AB_MCP_Sync_Inventory::source().
	 * @return true|WP_Error
	 */
	public static function live_rule( $live_id, array $source ) {
		$lock = AB_MCP_Sync_Post_Writer::read_lock( self::live_read( (string) $live_id, $source ), sprintf( 'the widget area %s of this site', (string) $live_id ) );
		return null === $lock ? true : $lock;
	}

	/**
	 * The parts of the journal: each instance whose settings differ from the
	 * target (a new one with none before), then the list where it differs.
	 *
	 * @param array      $prepared target_of().
	 * @param array|null $current  current().
	 * @return array[]
	 */
	public static function journal_parts( array $prepared, $current ) {
		$out   = array();
		$parts = is_array( $current ) ? $current['parts'] : array();
		foreach ( $prepared['writes'] as $live => $write ) {
			$name   = 'instance:' . $live;
			$before = isset( $parts[ $name ] ) ? $parts[ $name ] : null;
			$target = AB_MCP_Sync_Shared_Row::field( $write['settings'] );
			if ( $before === $target ) {
				continue;
			}
			$out[] = array(
				'part'   => $name,
				'way'    => self::WAY_FIELD,
				'before' => AB_MCP_Sync_Shared_Row::hash( $before ),
				'target' => AB_MCP_Sync_Shared_Row::hash( $target ),
			);
		}
		$before = is_array( $current ) ? self::row_hash( $current['row'] ) : null;
		$target = self::row_hash( $prepared['columns'] );
		if ( $before !== $target ) {
			$out[] = array(
				'part'   => 'row',
				'way'    => self::WAY_FIELD,
				'before' => $before,
				'target' => $target,
			);
		}
		return $out;
	}

	/* ---------------------------------------------------------- reading */

	/**
	 * The area here as a step reads it: the rows of the areas and of every
	 * widget this site registers or names, each field a part (decision 24);
	 * null where the area is not there; a WP_Error where a row could not be
	 * read or does not unpack (fail closed).
	 *
	 * @param string $area The area.
	 * @return array{list:array,placed:array,rows:array,parts:array}|null|WP_Error
	 */
	private static function state( $area ) {
		$areas = AB_MCP_Sync_Shared_Row::read( self::AREAS );
		if ( ! is_array( $areas ) || ! $areas['ok'] || ! is_array( $areas['value'] ) ) {
			return false === $areas ? null : self::unread( self::AREAS );
		}
		if ( ! array_key_exists( $area, $areas['value'] ) || 'array_version' === $area || ! is_array( $areas['value'][ $area ] ) ) {
			return null;
		}
		$parts  = array();
		$placed = array();
		$bases  = array();
		$rest   = array();
		foreach ( $areas['value'] as $key => $ids ) {
			if ( 'array_version' === $key || ! is_array( $ids ) ) {
				$rest[ $key ] = $ids;
				continue;
			}
			if ( (string) $key !== $area ) {
				$parts[ 'area:' . $key ] = AB_MCP_Sync_Shared_Row::field( $ids );
			}
			foreach ( $ids as $id ) {
				if ( is_string( $id ) ) {
					$placed[ $id ] = (string) $key;
					$base          = self::base_of( $id );
					if ( '' !== $base ) {
						$bases[ $base ] = true;
					}
				}
			}
		}
		$parts['rest:sidebars_widgets'] = AB_MCP_Sync_Shared_Row::field( $rest );
		global $wp_widget_factory;
		if ( $wp_widget_factory && ! empty( $wp_widget_factory->widgets ) ) {
			foreach ( $wp_widget_factory->widgets as $widget ) {
				if ( $widget instanceof WP_Widget && isset( $widget->id_base ) && '' !== (string) $widget->id_base ) {
					$bases[ (string) $widget->id_base ] = true;
				}
			}
		}
		ksort( $bases, SORT_STRING );
		$rows = array();
		foreach ( array_keys( $bases ) as $base ) {
			$base = (string) $base;
			$row  = AB_MCP_Sync_Shared_Row::read( 'widget_' . $base );
			if ( null === $row || ( is_array( $row ) && ( ! $row['ok'] || ! is_array( $row['value'] ) ) ) ) {
				return self::unread( 'widget_' . $base );
			}
			$value  = false === $row ? array() : $row['value'];
			$fresh  = array() === $value;
			$others = $fresh ? self::FRESH_REST : array();
			foreach ( $value as $key => $settings ) {
				if ( is_int( $key ) ) {
					$parts[ 'instance:' . $base . '-' . $key ] = AB_MCP_Sync_Shared_Row::field( $settings );
				} else {
					$others[ $key ] = $settings;
				}
			}
			$parts[ 'rest:widget_' . $base ] = AB_MCP_Sync_Shared_Row::field( $others );
			$rows[ $base ]                   = array(
				'raw'    => false === $row ? null : $row['raw'],
				'value'  => $value,
				'marked' => $fresh || ! empty( $value['_multiwidget'] ),
			);
		}
		return array(
			'list'   => array_values( $areas['value'][ $area ] ),
			'placed' => $placed,
			'rows'   => $rows,
			'areas'  => $areas,
			'parts'  => $parts,
		);
	}

	/**
	 * @param string $option The row.
	 * @return WP_Error
	 */
	private static function unread( $option ) {
		return new WP_Error( 'ab_mcp_sync_rows_unread', sprintf( 'The row %s of this site could not be read or does not unpack, so no widget is written. Try again; if it keeps failing, check the widgets in wp-admin (Appearance → Widgets).', $option ) );
	}

	/**
	 * The area here: its list (the row), every other field of the rows of the
	 * widgets and the areas as parts; null where it is not there.
	 *
	 * @param string $live_id The area.
	 * @return array{row:array,meta:array,terms:array,parts:array}|null|WP_Error
	 */
	public static function current( $live_id ) {
		$state = self::state( (string) $live_id );
		if ( ! is_array( $state ) ) {
			return $state;
		}
		return array(
			'row'   => array( 'area' => AB_MCP_Sync_Shared_Row::field( $state['list'] ) ),
			'meta'  => array(),
			'terms' => array(),
			'parts' => $state['parts'],
		);
	}

	/**
	 * @param array|null $current current(), null for none.
	 * @param string     $part    The name of the part.
	 * @return string|null
	 */
	public static function part_hash( $current, $part ) {
		$part = (string) $part;
		if ( 'row' === $part ) {
			return is_array( $current ) ? self::row_hash( $current['row'] ) : null;
		}
		if ( ! is_array( $current ) ) {
			return null;
		}
		return AB_MCP_Sync_Shared_Row::hash( isset( $current['parts'][ $part ] ) ? $current['parts'][ $part ] : null );
	}

	/**
	 * @param array $columns area: the bytes of the list.
	 * @return string
	 */
	public static function row_hash( array $columns ) {
		return AB_MCP_Sync_Shared_Row::hash( isset( $columns['area'] ) ? (string) $columns['area'] : null );
	}

	/**
	 * @param string $live_id The area.
	 * @param array  $source  AB_MCP_Sync_Inventory::source().
	 * @return array|null
	 */
	private static function live_read( $live_id, array $source ) {
		$kind = AB_MCP_Sync_Db_Kinds::for_pair( 'widget_area' );
		$read = null === $kind ? null : $kind->canonical(
			array(
				'kind'   => 'widget_area',
				'ref'    => (string) $live_id,
				'source' => $source,
			)
		);
		return is_array( $read ) ? $read : null;
	}

	/**
	 * @param string $live_id The area.
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
	 * @param string|null $live_id  The area.
	 * @param array|null  $current  current().
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
	 * Write the list of the area: wp_set_sidebars_widgets() with the row as
	 * stored and this one field changed (the widget tools write it so, past
	 * the filtered read of wp_get_sidebars_widgets()); edit_theme_options
	 * asked right before.
	 *
	 * @param array       $prepared target_of().
	 * @param string|null $live_id  The area.
	 * @return string|WP_Error The area.
	 */
	public static function write_row( array $prepared, $live_id ) {
		$area = (string) $prepared['area'];
		if ( null === $live_id || (string) $live_id !== $area ) {
			return AB_MCP_Sync_Post_Writer::locked( 'widget_area:create', sprintf( 'the area %s is not on this site', $area ) );
		}
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return AB_MCP_Sync_Post_Writer::rights( 'change the widgets of this site (edit_theme_options)' );
		}
		// update_option() in wp_set_sidebars_widgets() compares with the
		// cached row: emptied first (Codex round 1 of branch 11, A1).
		AB_MCP_Sync_Shared_Row::forget( self::AREAS );
		$areas = AB_MCP_Sync_Shared_Row::read( self::AREAS );
		if ( ! is_array( $areas ) || ! $areas['ok'] || ! is_array( $areas['value'] ) || ! array_key_exists( $area, $areas['value'] ) ) {
			return self::unread( self::AREAS );
		}
		$map          = $areas['value'];
		$map[ $area ] = array_values( $prepared['list'] );
		wp_set_sidebars_widgets( $map );
		return $area;
	}

	/**
	 * Write one instance into the row of its widget: the settings of the
	 * target, through the widget writer once more right before (its update()
	 * and filters may answer otherwise now), with update_option() as the
	 * widget tools write it (_multiwidget kept).
	 *
	 * @param string     $live_id  The area.
	 * @param string     $name     instance:<base>-<number>.
	 * @param array      $prepared target_of().
	 * @param array|null $pre      current() right before the call.
	 * @return true|WP_Error
	 */
	public static function write_part( $live_id, $name, array $prepared, $pre ) {
		unset( $live_id, $pre );
		$name = (string) $name;
		$live = 0 === strpos( $name, 'instance:' ) ? substr( $name, strlen( 'instance:' ) ) : '';
		if ( ! isset( $prepared['writes'][ $live ] ) ) {
			return new WP_Error( 'ab_mcp_sync_write_failed', sprintf( 'The part %s is of a name the kind widget_area does not write. Check the area in wp-admin (Appearance → Widgets).', $name ) );
		}
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return AB_MCP_Sync_Post_Writer::rights( 'change the widgets of this site (edit_theme_options)' );
		}
		$write = $prepared['writes'][ $live ];
		$value = self::widget_row( $write['base'] );
		if ( is_wp_error( $value ) ) {
			return $value;
		}
		$old   = isset( $value[ $write['number'] ] ) && is_array( $value[ $write['number'] ] ) ? $value[ $write['number'] ] : array();
		$clean = AB_MCP_Widget_Writer::sanitize( $write['base'], (int) $write['number'], $write['settings'], $old );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}
		// The row read again after the update() of the widget and
		// widget_update_callback (Codex round 1 of branch 11, A2): what they
		// changed in it (another instance) stays as they left it, and the
		// engine reads that change as another hand's; never written back from
		// the row as it was before them.
		$value = self::widget_row( $write['base'] );
		if ( is_wp_error( $value ) ) {
			return $value;
		}
		$value[ (int) $write['number'] ] = $clean;
		$value['_multiwidget']           = 1;
		update_option( 'widget_' . $write['base'], $value );
		return true;
	}

	/**
	 * The row of a widget as it is now, its caches emptied first, so that
	 * update_option() compares with it (Codex round 1 of branch 11, A1):
	 * array() for none; a refusal where it does not read or the widgets of
	 * WordPress did not write it (no _multiwidget).
	 *
	 * @param string $base The widget.
	 * @return array|WP_Error
	 */
	private static function widget_row( $base ) {
		AB_MCP_Sync_Shared_Row::forget( 'widget_' . $base );
		$row = AB_MCP_Sync_Shared_Row::read( 'widget_' . $base );
		if ( null === $row || ( is_array( $row ) && ( ! $row['ok'] || ! is_array( $row['value'] ) ) ) ) {
			return self::unread( 'widget_' . $base );
		}
		$value = false === $row ? array() : $row['value'];
		if ( array() !== $value && empty( $value['_multiwidget'] ) ) {
			return AB_MCP_Sync_Post_Writer::locked( 'widget_area:widget_unregistered', sprintf( 'the row widget_%s of this site was not written by the widgets of WordPress (no _multiwidget), so it is not changed', $base ) );
		}
		return $value;
	}

	/**
	 * The instances a step made that are there now (decision 23: they stay,
	 * and the area with them): each instance part of the journal that was
	 * not there before the step.
	 *
	 * @param array  $journal The journal of the step.
	 * @param string $live_id The area.
	 * @return string[]
	 */
	public static function made( array $journal, $live_id ) {
		$state = self::current( (string) $live_id );
		$out   = array();
		foreach ( isset( $journal['parts'] ) && is_array( $journal['parts'] ) ? $journal['parts'] : array() as $part ) {
			$name = is_array( $part ) && isset( $part['part'] ) ? (string) $part['part'] : '';
			if ( 0 === strpos( $name, 'instance:' ) && AB_MCP_Sync_Shared_Row::hash( null ) === (string) $part['before'] && is_array( $state ) && isset( $state['parts'][ $name ] ) ) {
				$out[] = substr( $name, strlen( 'instance:' ) );
			}
		}
		return $out;
	}

	/**
	 * After the writes and the way back (decision 33): the caches of the rows
	 * of the areas and of the widgets, the copy of the areas the core keeps
	 * for the request, the page cache of the whole site.
	 *
	 * @param string $live_id The area.
	 * @return string[] What was emptied.
	 */
	public static function post_write( $live_id ) {
		unset( $live_id );
		AB_MCP_Sync_Shared_Row::forget( self::AREAS );
		foreach ( self::bases() as $base ) {
			AB_MCP_Sync_Shared_Row::forget( 'widget_' . $base );
		}
		$GLOBALS['_wp_sidebars_widgets'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- the request copy of the core, cleared as wp_set_sidebars_widgets() clears it.
		$done                            = array( 'options', 'sidebars_widgets' );
		if ( class_exists( 'AB_MCP_Page_Cache' ) && is_array( AB_MCP_Page_Cache::purge_site() ) ) {
			$done[] = 'page_cache_site';
		}
		return $done;
	}

	/**
	 * The widgets this site registers or the areas name.
	 *
	 * @return string[]
	 */
	private static function bases() {
		$bases = array();
		global $wp_widget_factory;
		if ( $wp_widget_factory && ! empty( $wp_widget_factory->widgets ) ) {
			foreach ( $wp_widget_factory->widgets as $widget ) {
				if ( $widget instanceof WP_Widget && isset( $widget->id_base ) && '' !== (string) $widget->id_base ) {
					$bases[ (string) $widget->id_base ] = true;
				}
			}
		}
		$areas = AB_MCP_Sync_Shared_Row::read( self::AREAS );
		foreach ( is_array( $areas ) && is_array( $areas['value'] ) ? $areas['value'] : array() as $ids ) {
			foreach ( is_array( $ids ) ? $ids : array() as $id ) {
				$base = self::base_of( (string) $id );
				if ( '' !== $base ) {
					$bases[ $base ] = true;
				}
			}
		}
		ksort( $bases, SORT_STRING );
		return array_map( 'strval', array_keys( $bases ) );
	}

	/**
	 * What the takeover lock covers: the row of the areas and the rows of
	 * every widget this site registers or names.
	 *
	 * @param string|null $live_id The area.
	 * @param string      $subtype The subtype of the plan.
	 * @return array{posts:array,taxonomies:array,terms:array,options:string[]}
	 */
	public static function guard_of( $live_id, $subtype ) {
		unset( $live_id, $subtype );
		$options = array( self::AREAS );
		foreach ( self::bases() as $base ) {
			$options[] = 'widget_' . $base;
		}
		return array(
			'posts'      => array(),
			'taxonomies' => array(),
			'terms'      => array(),
			'options'    => $options,
		);
	}

	/**
	 * @param array $object The object of a journal.
	 * @return string widget_area.
	 */
	public static function subtype_of( array $object ) {
		unset( $object );
		return 'widget_area';
	}

	/**
	 * @param array $prepared target_of().
	 * @return string
	 */
	public static function title_of( array $prepared ) {
		return (string) $prepared['area'];
	}

	/* ---------------------------------------------------------- way back */

	/**
	 * Whether this account may put a part back (as forward):
	 * edit_theme_options; for an instance its widget registered and its
	 * update() keeping the backed-up settings.
	 *
	 * @param string $part    Part.
	 * @param string $live_id The area.
	 * @param array  $backup  current() of the backup.
	 * @param array  $now     current() now.
	 * @return true|string
	 */
	public static function restore_right( $part, $live_id, array $backup, array $now ) {
		unset( $live_id, $now );
		$part = (string) $part;
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return 'change the widgets of this site (edit_theme_options)';
		}
		if ( 0 !== strpos( $part, 'instance:' ) || ! isset( $backup['parts'][ $part ] ) ) {
			return true;
		}
		$live = substr( $part, strlen( 'instance:' ) );
		$base = self::base_of( $live );
		$read = AB_MCP_Sync_Canon::unserialized( (string) $backup['parts'][ $part ] );
		if ( ! $read['ok'] || ! is_array( $read['value'] ) ) {
			return sprintf( 'store the backed-up settings of %s, which do not unpack', $live );
		}
		$clean = AB_MCP_Widget_Writer::sanitize( $base, (int) substr( $live, strlen( $base ) + 1 ), $read['value'], $read['value'] );
		if ( is_wp_error( $clean ) || AB_MCP_Sync_Shared_Row::field( $clean ) !== (string) $backup['parts'][ $part ] ) {
			return sprintf( 'store the backed-up settings of %s (the widget is not registered here, or its update() changes them)', $live );
		}
		return true;
	}

	/**
	 * Put one part back (decision 23): a field raw, byte for byte conditional
	 * in its row, where it holds what the step read back. An instance the run
	 * made, and the list that names one, stay.
	 *
	 * @param string $name    The name of the part.
	 * @param string $live_id The area.
	 * @param array  $backup  current() of the backup.
	 * @param string $hash    The hash the part has to hold.
	 * @param int    $now     Time.
	 * @return array{state:string,note?:string}|WP_Error
	 */
	public static function restore_part( $name, $live_id, array $backup, $hash, $now ) {
		unset( $now );
		$name  = (string) $name;
		$area  = (string) $live_id;
		$state = self::state( $area );
		if ( ! is_array( $state ) ) {
			return is_wp_error( $state ) ? $state : array(
				'state' => 'left',
				'note'  => sprintf( 'The area %s is no longer on this site.', $area ),
			);
		}
		if ( 'row' === $name ) {
			$is  = AB_MCP_Sync_Shared_Row::field( $state['list'] );
			$was = (string) $backup['row']['area'];
			if ( $is === $was ) {
				return array( 'state' => 'unchanged' );
			}
			if ( ! hash_equals( (string) $hash, AB_MCP_Sync_Shared_Row::hash( $is ) ) ) {
				return array(
					'state' => 'left',
					'note'  => sprintf( 'The list of the area %s was changed since the step wrote it, so it is left as it is.', $area ),
				);
			}
			$read = AB_MCP_Sync_Canon::unserialized( $was );
			if ( ! $read['ok'] || ! is_array( $read['value'] ) ) {
				return array(
					'state' => 'left',
					'note'  => 'The backed-up list of the area does not unpack, so it is left as it is.',
				);
			}
			foreach ( $state['list'] as $live ) {
				if ( ! in_array( $live, $read['value'], true ) && ! isset( $backup['parts'][ 'instance:' . $live ] ) ) {
					return array(
						'state' => 'left',
						'note'  => sprintf( 'The list of the area names the instance %s the run made, which the way back does not remove: the list is left as it is, the area a rest.', (string) $live ),
					);
				}
			}
			$map          = $state['areas']['value'];
			$map[ $area ] = $read['value'];
			if ( ! AB_MCP_Sync_Shared_Row::put_back( self::AREAS, (string) $state['areas']['raw'], AB_MCP_Sync_Shared_Row::field( $map ) ) ) {
				return array(
					'state' => 'left',
					'note'  => 'The areas changed while the list was put back, so it is left as it is.',
				);
			}
			$GLOBALS['_wp_sidebars_widgets'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- the request copy of the core.
			$after                           = self::state( $area );
			return array( 'state' => is_array( $after ) && AB_MCP_Sync_Shared_Row::field( $after['list'] ) === $was ? 'restored' : 'unclear' );
		}
		if ( 0 !== strpos( $name, 'instance:' ) ) {
			return array(
				'state' => 'left',
				'note'  => sprintf( 'A part %s of a widget area is of a name this version does not know: left as it is.', $name ),
			);
		}
		$live = substr( $name, strlen( 'instance:' ) );
		$base = self::base_of( $live );
		$was  = isset( $backup['parts'][ $name ] ) ? (string) $backup['parts'][ $name ] : null;
		$is   = isset( $state['parts'][ $name ] ) ? (string) $state['parts'][ $name ] : null;
		if ( null === $was ) {
			return array(
				'state' => 'left',
				'note'  => sprintf( 'The instance %s was made by the run and is not removed by the way back: it stays, and the area with it is a rest.', $live ),
			);
		}
		if ( $was === $is ) {
			return array( 'state' => 'unchanged' );
		}
		if ( null === $is || ! hash_equals( (string) $hash, AB_MCP_Sync_Shared_Row::hash( $is ) ) || ! isset( $state['rows'][ $base ] ) || null === $state['rows'][ $base ]['raw'] ) {
			return array(
				'state' => 'left',
				'note'  => sprintf( 'The instance %s was changed since the step wrote it, so it is left as it is.', $live ),
			);
		}
		$read = AB_MCP_Sync_Canon::unserialized( $was );
		if ( ! $read['ok'] ) {
			return array(
				'state' => 'left',
				'note'  => sprintf( 'The backed-up settings of %s do not unpack, so they are left as they are.', $live ),
			);
		}
		$value                                         = $state['rows'][ $base ]['value'];
		$value[ (int) substr( $live, strlen( $base ) + 1 ) ] = $read['value'];
		if ( ! AB_MCP_Sync_Shared_Row::put_back( 'widget_' . $base, (string) $state['rows'][ $base ]['raw'], AB_MCP_Sync_Shared_Row::field( $value ) ) ) {
			return array(
				'state' => 'left',
				'note'  => sprintf( 'The row widget_%1$s changed while the instance %2$s was put back, so it is left as it is.', $base, $live ),
			);
		}
		$after = self::state( $area );
		return array( 'state' => is_array( $after ) && ( isset( $after['parts'][ $name ] ) ? (string) $after['parts'][ $name ] : null ) === $was ? 'restored' : 'unclear' );
	}

	/**
	 * What a part held before the step, never a credential.
	 *
	 * @param string     $part    Part.
	 * @param array|null $backup  current() of the backup.
	 * @param array|null $now     current() now.
	 * @param mixed      $live_id The area.
	 * @return string
	 */
	public static function before_value( $part, $backup, $now, $live_id ) {
		unset( $now, $live_id );
		if ( ! is_array( $backup ) ) {
			return 'none: the area was not on this site before the run.';
		}
		$part = (string) $part;
		if ( 'row' === $part ) {
			$read = AB_MCP_Sync_Canon::unserialized( (string) $backup['row']['area'] );
			return AB_MCP_Sync_Post_Writer::cut( 'instances: ' . ( $read['ok'] && is_array( $read['value'] ) ? implode( ', ', array_map( 'strval', $read['value'] ) ) : '(unreadable)' ), AB_MCP_Sync_Post_Writer::PART_CHARS );
		}
		if ( ! isset( $backup['parts'][ $part ] ) ) {
			return 'none: the instance was not on this site before the run.';
		}
		$base = self::base_of( substr( $part, strlen( 'instance:' ) ) );
		return AB_MCP_Sync_Post_Writer::cut( 'settings: ' . AB_MCP_Sync_Post_Writer::shown_value( $base, (string) $backup['parts'][ $part ] ), AB_MCP_Sync_Post_Writer::PART_CHARS );
	}
}

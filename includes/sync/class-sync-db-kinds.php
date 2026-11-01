<?php
/**
 * The kinds of the database (Z8, decisions 11, 12, 25 and 27 of the plan of
 * 01.11.2026): post, term, menu (with its items), widget_area, custom_css
 * and option, as far as this branch builds them — the inventory of one side
 * and the canonical form of one object (contract A). Every method that
 * writes, or asks for rights to write, answers that it is not in this
 * version (ab_mcp_sync_not_in_branch); the takeover writes from branch 8 on.
 *
 * What a kind reads it reads from the rows (AB_MCP_Sync_Rows); what it
 * leaves out is AB_MCP_Sync_Exclusions' (the columns and meta WordPress
 * writes by itself, group B) and what the credential check calls B9
 * (AB_MCP_Sync_Credentials). An object of a type the table locks
 * (AB_MCP_Sync_Kinds::OWNERSHIP with a stage: a post type or taxonomy of a
 * plugin, a changeset, a font) is listed with that lock and its hash, so
 * the report can name it with its stage; an object of another kind is left
 * to that kind.
 *
 * The objects of the inventory: kind (the pair kind: post, term, menu,
 * menu_item, widget_area, custom_css, option), ref (the object in the ID
 * space of this site), identity (what names it apart from its id), status
 * (z8; locked when a lock applies; b9, guarded, unreachable for options),
 * hash, size, refs, possible, locks, credentials, complete.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-sync-kind.php';
require_once __DIR__ . '/class-sync-kinds.php';
require_once __DIR__ . '/class-sync-exclusions.php';
require_once __DIR__ . '/class-sync-rows.php';
require_once __DIR__ . '/class-sync-canon.php';
require_once __DIR__ . '/class-sync-refs.php';
require_once __DIR__ . '/class-sync-credentials.php';
require_once __DIR__ . '/class-sync-option-schema.php';
require_once __DIR__ . '/class-sync-builder-lock.php';
require_once __DIR__ . '/class-sync-inventory.php';

/**
 * Class AB_MCP_Sync_Db_Kind: what the kinds of the database share.
 */
abstract class AB_MCP_Sync_Db_Kind implements AB_MCP_Sync_Kind {

	/** Rows per query of an inventory. */
	const PAGE = 100;

	/** @return int Contents (AB_MCP_Sync_Kinds::PHASES). */
	public function phase() {
		return 5;
	}

	/**
	 * The canonical form of one object: the same (kind, ref) the inventory
	 * gives it.
	 *
	 * @param mixed $object array( 'kind' => pair kind (pair_kinds(); the
	 *                      kind itself when left out), 'ref' => ref,
	 *                      'source' => AB_MCP_Sync_Inventory::source() ).
	 * @return array|WP_Error The result of AB_MCP_Sync_Canon::finish(), with the form.
	 */
	public function canonical( $object ) {
		if ( ! is_array( $object ) || ! isset( $object['ref'], $object['source'] ) || ! is_array( $object['source'] ) ) {
			return new WP_Error( 'ab_mcp_sync_object_invalid', 'canonical() takes array( \'kind\' => …, \'ref\' => …, \'source\' => AB_MCP_Sync_Inventory::source() ). Use that form.' );
		}
		$pair = isset( $object['kind'] ) ? (string) $object['kind'] : $this->kind();
		if ( ! in_array( $pair, $this->pair_kinds(), true ) ) {
			return new WP_Error( 'ab_mcp_sync_object_invalid', sprintf( 'The kind %1$s has no objects of the pair kind %2$s. Use one of %3$s.', $this->kind(), $pair, implode( ', ', $this->pair_kinds() ) ) );
		}
		$result = $this->read( (string) $object['ref'], $object['source'], $pair );
		if ( null === $result ) {
			return new WP_Error( 'ab_mcp_sync_object_missing', sprintf( 'The %1$s %2$s is not on this site. Use the inventory to see which objects are.', $this->kind(), (string) $object['ref'] ), array( 'ref' => (string) $object['ref'] ) );
		}
		return $result;
	}

	/**
	 * The pair kinds of this kind's objects: the kind of the inventory
	 * objects, with one form of ref each that inventory(), canonical() and
	 * read() understand alike.
	 *
	 * @return string[]
	 */
	public function pair_kinds() {
		return array( $this->kind() );
	}

	/**
	 * One object, read and canonical.
	 *
	 * @param string $ref    Ref, as the inventory gives it.
	 * @param array  $source Source.
	 * @param string $pair   Pair kind (pair_kinds()).
	 * @return array|WP_Error|null Null when it is not there.
	 */
	abstract protected function read( $ref, array $source, $pair );

	/** {@inheritdoc} */
	public function export( array $refs ) {
		return $this->not_here( 'export' );
	}

	/** {@inheritdoc} */
	public function rights( $op, $ref ) {
		return $this->not_here( 'rights' );
	}

	/** {@inheritdoc} */
	public function core_effects( $op ) {
		return $this->not_here( 'core_effects' );
	}

	/** {@inheritdoc} */
	public function fields() {
		return $this->not_here( 'fields' );
	}

	/** {@inheritdoc} */
	public function parts( array $payload ) {
		return $this->not_here( 'parts' );
	}

	/** {@inheritdoc} */
	public function backup( array $part ) {
		return $this->not_here( 'backup' );
	}

	/** {@inheritdoc} */
	public function write( array $part ) {
		return $this->not_here( 'write' );
	}

	/** {@inheritdoc} */
	public function read_back( array $part ) {
		return $this->not_here( 'read_back' );
	}

	/** {@inheritdoc} */
	public function restore_part( array $part ) {
		return $this->not_here( 'restore_part' );
	}

	/** {@inheritdoc} */
	public function post_write( array $parts ) {
		return $this->not_here( 'post_write' );
	}

	/** {@inheritdoc} */
	public function verify( array $pair ) {
		return $this->not_here( 'verify' );
	}

	/**
	 * @param string $method Method of contract A.
	 * @return WP_Error
	 */
	protected function not_here( $method ) {
		return new WP_Error(
			'ab_mcp_sync_not_in_branch',
			sprintf( 'The kind %1$s has no %2$s() in this version: the takeover writes from branch 8 of Z8 on. Use AB_MCP_Sync_Inventory to read and compare the kind meanwhile.', $this->kind(), $method ),
			array(
				'kind'   => $this->kind(),
				'method' => $method,
				'stage'  => 'Z8',
			)
		);
	}

	/**
	 * The meta rows of an object as the canonical form holds them: by key
	 * (bytes) and meta_id, without the excluded keys and the B9 rows, each
	 * value through $schema or as a stored value with its whole numbers
	 * noted as possible references.
	 *
	 * @param array    $rows   Meta rows (AB_MCP_Sync_Rows::meta()).
	 * @param array    $ctx    Context.
	 * @param array    $acc    Accumulator.
	 * @param string   $kind   Kind.
	 * @param bool     $post   Whether these are post meta (the exclusions of post meta apply).
	 * @param callable $schema function( string $key, string $raw, array &$acc ): mixed|null.
	 * @return array
	 */
	protected static function meta_form( array $rows, array $ctx, array &$acc, $kind, $post, $schema = null ) {
		usort(
			$rows,
			static function ( $a, $b ) {
				return array( strcmp( $a['key'], $b['key'] ), $a['meta_id'] ) <=> array( 0, $b['meta_id'] );
			}
		);
		$out = array();
		foreach ( $rows as $row ) {
			if ( $post && AB_MCP_Sync_Exclusions::post_meta( $row['key'] ) ) {
				continue;
			}
			$at = 'meta:' . $row['key'];
			if ( ! AB_MCP_Sync_Credentials::note( $acc, $kind, $at, AB_MCP_Sync_Credentials::unit( $row['key'], $row['value'] ) ) ) {
				continue;
			}
			$value = null === $schema ? null : call_user_func_array( $schema, array( $row['key'], $row['value'], &$acc ) );
			if ( null === $value ) {
				$value = AB_MCP_Sync_Canon::value( $row['value'], $ctx, $acc, $kind, $at, AB_MCP_Sync_Refs::possible_leaf( $acc, $at ) );
			}
			$out[] = array( $row['key'], $value );
		}
		return $out;
	}

	/**
	 * An id column or value as a reference marker, 0 or empty as it is.
	 *
	 * @param string $space    post or term.
	 * @param string $raw      Value as stored.
	 * @param array  $acc      Accumulator.
	 * @param string $at       Where.
	 * @param string $strength strong or weak (decision 16).
	 * @return mixed
	 */
	protected static function id_ref( $space, $raw, array &$acc, $at, $strength = 'strong' ) {
		if ( ! preg_match( '/^\d+\z/', (string) $raw ) || (int) $raw <= 0 ) {
			return (string) $raw;
		}
		$acc['refs'][] = array(
			'space'    => $space,
			'id'       => (int) $raw,
			'at'       => (string) $at,
			'strength' => $strength,
		);
		return AB_MCP_Sync_Canon::ref( $space, (int) $raw );
	}

	/**
	 * Note the lock of a type the table locks (a stage in OWNERSHIP).
	 *
	 * @param array $acc    Accumulator.
	 * @param array $answer Answer of AB_MCP_Sync_Kinds::post_type() or ::taxonomy().
	 */
	protected static function type_lock( array &$acc, array $answer ) {
		if ( 'locked' === $answer['status'] ) {
			$acc['locks'][] = array(
				'key'    => $answer['key'],
				'stage'  => $answer['stage'],
				'reason' => $answer['reason'],
				'at'     => 'type',
			);
		}
	}

	/**
	 * The terms of an object (term_relationships): a reference for a term
	 * object, the slug for a taxonomy that is part of the object's identity
	 * (post_format, wp_theme, wp_template_part_area); nav_menu left to the
	 * menu kind.
	 *
	 * @param array $terms AB_MCP_Sync_Rows::object_terms().
	 * @param array $acc   Accumulator.
	 * @return array A set.
	 */
	protected static function terms_form( array $terms, array &$acc ) {
		$items = array();
		foreach ( $terms as $term ) {
			$answer = AB_MCP_Sync_Kinds::taxonomy( $term['taxonomy'] );
			if ( 'menu' === $answer['kind'] ) {
				continue;
			}
			if ( 'post' === $answer['kind'] ) {
				$items[] = array(
					't'    => $term['taxonomy'],
					'slug' => $term['slug'],
					'o'    => $term['term_order'],
				);
				continue;
			}
			$items[] = array(
				't' => $term['taxonomy'],
				'r' => self::id_ref( 'term', (string) $term['term_id'], $acc, 'term:' . $term['taxonomy'] ),
				'o' => $term['term_order'],
			);
		}
		return AB_MCP_Sync_Canon::set( $items );
	}

	/**
	 * The stored array of an option, unpacked; array() when there is none.
	 *
	 * @param string $name Option name.
	 * @return array|null Null when it could not be read or unpacked.
	 */
	protected static function option_array( $name ) {
		$row = AB_MCP_Sync_Rows::option( $name );
		if ( null === $row ) {
			return null;
		}
		if ( false === $row ) {
			return array();
		}
		$read = AB_MCP_Sync_Canon::unserialized( $row['value'] );
		if ( ! $read['ok'] ) {
			return null;
		}
		return is_array( $read['value'] ) ? $read['value'] : array();
	}

	/**
	 * The theme mods of the active stylesheet (its option row).
	 *
	 * @return array|null
	 */
	protected static function active_theme_mods() {
		$row = AB_MCP_Sync_Rows::option( 'stylesheet' );
		if ( null === $row ) {
			return null;
		}
		return false === $row || '' === $row['value'] ? array() : self::option_array( 'theme_mods_' . $row['value'] );
	}
}

/**
 * Class AB_MCP_Sync_Kind_Post: posts of the core types (A1, A6, A10).
 */
class AB_MCP_Sync_Kind_Post extends AB_MCP_Sync_Db_Kind {

	/** @return string */
	public function kind() {
		return 'post';
	}

	/** @return string[] */
	public function responsibility() {
		return array( 'post_type:post', 'post_type:page', 'post_type:wp_block', 'post_type:wp_template', 'post_type:wp_template_part', 'post_type:wp_global_styles', 'post_type:wp_navigation', 'taxonomy:post_format', 'taxonomy:wp_theme', 'taxonomy:wp_template_part_area' );
	}

	/**
	 * Every post of a type this kind takes over or the table locks.
	 *
	 * @param mixed      $source AB_MCP_Sync_Inventory::source().
	 * @param mixed|null $cursor array( 'after' => id ).
	 * @return array|WP_Error
	 */
	public function inventory( $source, $cursor ) {
		$after   = is_array( $cursor ) && isset( $cursor['after'] ) ? (int) $cursor['after'] : 0;
		$objects = array();
		// Rows read in this call: the budget is asked after each, whether
		// it gave an object or not, so a long run of rows no object comes
		// from ends in time as well, and the cursor goes on past them.
		$seen    = 0;
		while ( true ) {
			$ids = AB_MCP_Sync_Rows::post_ids( null, $after, self::PAGE );
			if ( null === $ids ) {
				return AB_MCP_Sync_Inventory::unreadable( 'posts' );
			}
			foreach ( $ids as $row ) {
				if ( $seen > 0 && AB_MCP_Sync_Inventory::out_of_time( $source ) ) {
					return array( 'objects' => $objects, 'cursor' => array( 'after' => $after ) );
				}
				$after  = $row['ID'];
				++$seen;
				$answer = AB_MCP_Sync_Kinds::post_type( $row['post_type'] );
				if ( 'post' !== $answer['kind'] && ! ( null === $answer['kind'] && 'locked' === $answer['status'] ) ) {
					continue;
				}
				$result = $this->read( (string) $row['ID'], $source, 'post' );
				if ( is_array( $result ) ) {
					$objects[] = AB_MCP_Sync_Inventory::entry( $result );
				}
			}
			if ( count( $ids ) < self::PAGE ) {
				return array( 'objects' => $objects, 'cursor' => null );
			}
		}
	}

	/** {@inheritdoc} */
	protected function read( $ref, array $source, $pair ) {
		$id  = (int) $ref;
		$row = AB_MCP_Sync_Rows::post( $id );
		if ( false === $row ) {
			return null;
		}
		$acc  = AB_MCP_Sync_Canon::acc();
		$ctx  = $source['ctx'];
		$meta = AB_MCP_Sync_Rows::meta( 'post', $id );
		$tax  = AB_MCP_Sync_Rows::object_terms( $id );
		if ( null === $row || null === $meta || null === $tax ) {
			$acc['unread'][] = null === $row ? 'posts' : ( null === $meta ? 'postmeta' : 'term_relationships' );
			return AB_MCP_Sync_Canon::finish( 'post', (string) $id, array(), array(), $acc );
		}
		self::type_lock( $acc, AB_MCP_Sync_Kinds::post_type( $row['post_type'] ) );
		AB_MCP_Sync_Builder_Lock::note( $acc, $row, $meta );
		$form = array(
			'row'   => self::row_form( $row, $ctx, $acc ),
			'meta'  => self::meta_form(
				$meta,
				$ctx,
				$acc,
				'post',
				true,
				static function ( $key, $raw, &$acc ) {
					return '_thumbnail_id' === $key ? self::id_ref( 'post', $raw, $acc, 'meta:_thumbnail_id' ) : null;
				}
			),
			'terms' => self::terms_form( $tax, $acc ),
		);
		$theme = '';
		foreach ( $tax as $term ) {
			if ( 'wp_theme' === $term['taxonomy'] ) {
				$theme = $term['slug'];
			}
		}
		$identity = array(
			'post_type' => $row['post_type'],
			'post_name' => $row['post_name'],
			'theme'     => $theme,
		);
		return AB_MCP_Sync_Canon::finish( 'post', (string) $id, $identity, $form, $acc );
	}

	/**
	 * The columns of a post row without the excluded ones. Each column of
	 * the form is a unit of the credential check (decision 9: JSON,
	 * serialized, blocks and shortcodes in it).
	 *
	 * @param array $row Row.
	 * @param array $ctx Context.
	 * @param array $acc Accumulator.
	 * @return array
	 */
	public static function row_form( array $row, array $ctx, array &$acc ) {
		$out = array();
		foreach ( AB_MCP_Sync_Rows::POST_COLUMNS as $column ) {
			if ( AB_MCP_Sync_Exclusions::column( 'posts', $column ) ) {
				continue;
			}
			$value = $row[ $column ];
			AB_MCP_Sync_Credentials::note( $acc, 'post', $column, AB_MCP_Sync_Credentials::unit( '', (string) $value ) );
			switch ( $column ) {
				case 'post_content':
					$out[ $column ] = AB_MCP_Sync_Refs::content( $value, $ctx, $acc, 'post', 'post_content' );
					break;
				case 'post_title':
				case 'post_excerpt':
				case 'post_content_filtered':
					$found          = AB_MCP_Sync_Refs::text_refs( $value, '' );
					$out[ $column ] = AB_MCP_Sync_Canon::text( $value, $ctx );
					foreach ( $found['possible'] as $p ) {
						$acc['possible'][] = array(
							'value' => $p['value'],
							'at'    => $column . ':' . $p['at'],
						);
					}
					foreach ( $found['deep'] as $deep ) {
						AB_MCP_Sync_Canon::too_deep( $acc, $column . ':' . $deep, 'post' );
					}
					break;
				case 'post_parent':
					$out[ $column ] = self::id_ref( 'post', $value, $acc, 'post_parent' );
					break;
				case 'post_author':
					$out[ $column ] = AB_MCP_Sync_Canon::user( (int) $value, $ctx, $acc, 'post_author' );
					break;
				default:
					$out[ $column ] = $value;
			}
		}
		return $out;
	}
}

/**
 * Class AB_MCP_Sync_Kind_Term: terms of the core taxonomies (A4, A10).
 */
class AB_MCP_Sync_Kind_Term extends AB_MCP_Sync_Db_Kind {

	/** @return string */
	public function kind() {
		return 'term';
	}

	/** @return string[] */
	public function responsibility() {
		return array( 'taxonomy:category', 'taxonomy:post_tag', 'taxonomy:wp_pattern_category' );
	}

	/**
	 * Every term of a taxonomy this kind takes over or the table locks.
	 *
	 * @param mixed      $source AB_MCP_Sync_Inventory::source().
	 * @param mixed|null $cursor array( 'after' => term_taxonomy_id ).
	 * @return array|WP_Error
	 */
	public function inventory( $source, $cursor ) {
		$after   = is_array( $cursor ) && isset( $cursor['after'] ) ? (int) $cursor['after'] : 0;
		$objects = array();
		// Rows read in this call: the budget is asked after each, whether
		// it gave an object or not, so a long run of rows no object comes
		// from ends in time as well, and the cursor goes on past them.
		$seen    = 0;
		while ( true ) {
			$rows = AB_MCP_Sync_Rows::terms( null, $after, self::PAGE );
			if ( null === $rows ) {
				return AB_MCP_Sync_Inventory::unreadable( 'terms' );
			}
			foreach ( $rows as $row ) {
				if ( $seen > 0 && AB_MCP_Sync_Inventory::out_of_time( $source ) ) {
					return array( 'objects' => $objects, 'cursor' => array( 'after' => $after ) );
				}
				$after  = (int) $row['term_taxonomy_id'];
				++$seen;
				$answer = AB_MCP_Sync_Kinds::taxonomy( $row['taxonomy'] );
				if ( 'term' !== $answer['kind'] && ! ( null === $answer['kind'] && 'locked' === $answer['status'] ) ) {
					continue;
				}
				$objects[] = AB_MCP_Sync_Inventory::entry( $this->form( $row, $source ) );
			}
			if ( count( $rows ) < self::PAGE ) {
				return array( 'objects' => $objects, 'cursor' => null );
			}
		}
	}

	/** {@inheritdoc} */
	protected function read( $ref, array $source, $pair ) {
		$row = self::term_row( (int) $ref );
		if ( false === $row ) {
			return null;
		}
		if ( null === $row ) {
			$acc             = AB_MCP_Sync_Canon::acc();
			$acc['unread'][] = 'terms';
			return AB_MCP_Sync_Canon::finish( $this->kind(), (string) $ref, array(), array(), $acc );
		}
		return $this->form( $row, $source );
	}

	/**
	 * The one row of a term id; null when unreadable or shared by two
	 * taxonomies (a term the core did not split, which no ref names alone).
	 *
	 * @param int $term_id Term id.
	 * @return array|false|null
	 */
	protected static function term_row( $term_id ) {
		$taxonomies = AB_MCP_Sync_Rows::term_taxonomies( (int) $term_id );
		if ( null === $taxonomies ) {
			return null;
		}
		if ( array() === $taxonomies ) {
			return false;
		}
		if ( 1 !== count( $taxonomies ) ) {
			return null;
		}
		return AB_MCP_Sync_Rows::term( (int) $term_id, (string) $taxonomies[0] );
	}

	/**
	 * The canonical form of a term row.
	 *
	 * @param array  $row    Row (AB_MCP_Sync_Rows::TERM_COLUMNS).
	 * @param array  $source Source.
	 * @return array
	 */
	protected function form( array $row, array $source ) {
		$acc  = AB_MCP_Sync_Canon::acc();
		$ctx  = $source['ctx'];
		$meta = AB_MCP_Sync_Rows::meta( 'term', (int) $row['term_id'] );
		if ( null === $meta ) {
			$acc['unread'][] = 'termmeta';
			$meta            = array();
		}
		self::type_lock( $acc, AB_MCP_Sync_Kinds::taxonomy( $row['taxonomy'] ) );
		$form = array(
			'row'  => self::term_form( $row, $ctx, $acc, $this->kind() ),
			'meta' => self::meta_form( $meta, $ctx, $acc, $this->kind(), false ),
		);
		$form = $this->more( $row, $form, $acc );
		return AB_MCP_Sync_Canon::finish(
			$this->kind(),
			(string) $row['term_id'],
			array(
				'taxonomy' => $row['taxonomy'],
				'slug'     => $row['slug'],
			),
			$form,
			$acc
		);
	}

	/**
	 * What a kind of terms adds to the form (the menu its positions).
	 *
	 * @param array $row  Row.
	 * @param array $form Form.
	 * @param array $acc  Accumulator.
	 * @return array
	 */
	protected function more( array $row, array $form, array &$acc ) {
		return $form;
	}

	/**
	 * The columns of a term without the counter; each text column a unit of
	 * the credential check (decision 9).
	 *
	 * @param array  $row  Row.
	 * @param array  $ctx  Context.
	 * @param array  $acc  Accumulator.
	 * @param string $kind Kind (term or menu).
	 * @return array
	 */
	protected static function term_form( array $row, array $ctx, array &$acc, $kind ) {
		foreach ( array( 'name', 'slug', 'description' ) as $column ) {
			AB_MCP_Sync_Credentials::note( $acc, (string) $kind, $column, AB_MCP_Sync_Credentials::unit( '', (string) $row[ $column ] ) );
		}
		return array(
			'taxonomy'    => $row['taxonomy'],
			'name'        => AB_MCP_Sync_Canon::text( $row['name'], $ctx ),
			'slug'        => $row['slug'],
			'term_group'  => $row['term_group'],
			'description' => AB_MCP_Sync_Canon::text( $row['description'], $ctx ),
			'parent'      => self::id_ref( 'term', $row['parent'], $acc, 'parent' ),
		);
	}
}

/**
 * Class AB_MCP_Sync_Kind_Menu: menus (nav_menu terms with their positions)
 * and their items (A5), each item a pair of its own (pair kind menu_item).
 */
class AB_MCP_Sync_Kind_Menu extends AB_MCP_Sync_Kind_Term {

	/** The meta of a menu item the core writes (wp_update_nav_menu_item()), without ids. */
	const ITEM_TEXT_META = array( '_menu_item_type', '_menu_item_object', '_menu_item_target', '_menu_item_classes', '_menu_item_xfn', '_menu_item_url' );

	/** The columns of a menu item the core writes from the item's fields. */
	const ITEM_COLUMNS = array( 'post_title', 'post_excerpt', 'post_content', 'post_status', 'menu_order' );

	/** @return string */
	public function kind() {
		return 'menu';
	}

	/** @return string[] */
	public function responsibility() {
		return array( 'post_type:nav_menu_item', 'taxonomy:nav_menu', 'theme_mod:nav_menu_locations' );
	}

	/** @return string[] A menu (ref: its term id) and a menu item (ref: its post id). */
	public function pair_kinds() {
		return array( 'menu', 'menu_item' );
	}

	/**
	 * The menus, then their items.
	 *
	 * @param mixed      $source AB_MCP_Sync_Inventory::source().
	 * @param mixed|null $cursor array( 'part' => menus|items, 'after' => id ).
	 * @return array|WP_Error
	 */
	public function inventory( $source, $cursor ) {
		$part    = is_array( $cursor ) && isset( $cursor['part'] ) && 'items' === $cursor['part'] ? 'items' : 'menus';
		$after   = is_array( $cursor ) && isset( $cursor['after'] ) ? (int) $cursor['after'] : 0;
		$objects = array();
		// Rows read in this call: the budget is asked after each, whether
		// it gave an object or not, so a long run of rows no object comes
		// from ends in time as well, and the cursor goes on past them.
		$seen    = 0;
		while ( 'menus' === $part ) {
			$rows = AB_MCP_Sync_Rows::terms( array( 'nav_menu' ), $after, self::PAGE );
			if ( null === $rows ) {
				return AB_MCP_Sync_Inventory::unreadable( 'terms' );
			}
			foreach ( $rows as $row ) {
				if ( $seen > 0 && AB_MCP_Sync_Inventory::out_of_time( $source ) ) {
					return array( 'objects' => $objects, 'cursor' => array( 'part' => 'menus', 'after' => $after ) );
				}
				$after     = (int) $row['term_taxonomy_id'];
				++$seen;
				$objects[] = AB_MCP_Sync_Inventory::entry( $this->form( $row, $source ) );
			}
			if ( count( $rows ) < self::PAGE ) {
				$part  = 'items';
				$after = 0;
			}
		}
		while ( true ) {
			$ids = AB_MCP_Sync_Rows::post_ids( array( 'nav_menu_item' ), $after, self::PAGE );
			if ( null === $ids ) {
				return AB_MCP_Sync_Inventory::unreadable( 'posts' );
			}
			foreach ( $ids as $row ) {
				if ( $seen > 0 && AB_MCP_Sync_Inventory::out_of_time( $source ) ) {
					return array( 'objects' => $objects, 'cursor' => array( 'part' => 'items', 'after' => $after ) );
				}
				$after  = $row['ID'];
				++$seen;
				$result = $this->item( $row['ID'], $source );
				if ( is_array( $result ) ) {
					$objects[] = AB_MCP_Sync_Inventory::entry( $result );
				}
			}
			if ( count( $ids ) < self::PAGE ) {
				return array( 'objects' => $objects, 'cursor' => null );
			}
		}
	}

	/**
	 * A menu by its term id, or (pair kind menu_item) an item by its post id.
	 *
	 * @param string $ref    Ref.
	 * @param array  $source Source.
	 * @param string $pair   menu or menu_item.
	 * @return array|null
	 */
	protected function read( $ref, array $source, $pair ) {
		if ( 'menu_item' === $pair ) {
			return $this->item( (int) $ref, $source );
		}
		$row = AB_MCP_Sync_Rows::term( (int) $ref, 'nav_menu' );
		if ( false === $row ) {
			return null;
		}
		if ( null === $row ) {
			$acc             = AB_MCP_Sync_Canon::acc();
			$acc['unread'][] = 'terms';
			return AB_MCP_Sync_Canon::finish( 'menu', (string) $ref, array(), array(), $acc );
		}
		return $this->form( $row, $source );
	}

	/**
	 * The positions of the menu (nav_menu_locations in the theme mods of the
	 * active stylesheet) as a field of the menu.
	 *
	 * @param array $row  Row.
	 * @param array $form Form.
	 * @param array $acc  Accumulator.
	 * @return array
	 */
	protected function more( array $row, array $form, array &$acc ) {
		$mods = self::active_theme_mods();
		if ( null === $mods ) {
			$acc['unread'][] = 'theme_mods';
			$mods            = array();
		}
		$locations = array();
		foreach ( isset( $mods['nav_menu_locations'] ) && is_array( $mods['nav_menu_locations'] ) ? $mods['nav_menu_locations'] : array() as $location => $menu ) {
			if ( (int) $menu === (int) $row['term_id'] && (int) $menu > 0 ) {
				$locations[] = (string) $location;
			}
		}
		$form['locations'] = AB_MCP_Sync_Canon::set( $locations );
		return $form;
	}

	/**
	 * One menu item.
	 *
	 * @param int   $id     Post id.
	 * @param array $source Source.
	 * @return array|null
	 */
	protected function item( $id, array $source ) {
		$row = AB_MCP_Sync_Rows::post( (int) $id );
		if ( false === $row || ( is_array( $row ) && 'nav_menu_item' !== $row['post_type'] ) ) {
			return null;
		}
		$acc  = AB_MCP_Sync_Canon::acc();
		$ctx  = $source['ctx'];
		$meta = AB_MCP_Sync_Rows::meta( 'post', (int) $id );
		$tax  = AB_MCP_Sync_Rows::object_terms( (int) $id );
		$ref  = (string) (int) $id;
		if ( null === $row || null === $meta || null === $tax ) {
			$acc['unread'][] = 'menu_item';
			return AB_MCP_Sync_Canon::finish( 'menu_item', $ref, array(), array(), $acc );
		}
		$type = '';
		foreach ( $meta as $m ) {
			if ( '_menu_item_type' === $m['key'] ) {
				$type = $m['value'];
				break;
			}
		}
		$cols = array();
		foreach ( self::ITEM_COLUMNS as $column ) {
			AB_MCP_Sync_Credentials::note( $acc, 'menu_item', $column, AB_MCP_Sync_Credentials::unit( '', (string) $row[ $column ] ) );
			$cols[ $column ] = in_array( $column, array( 'post_status', 'menu_order' ), true ) ? $row[ $column ] : AB_MCP_Sync_Canon::text( $row[ $column ], $ctx );
		}
		$menus = array();
		foreach ( $tax as $term ) {
			if ( 'nav_menu' === $term['taxonomy'] ) {
				$menus[] = self::id_ref( 'term', (string) $term['term_id'], $acc, 'menu' );
			}
		}
		$form     = array(
			'row'   => $cols,
			'menus' => AB_MCP_Sync_Canon::set( $menus ),
			'meta'  => self::meta_form(
				$meta,
				$ctx,
				$acc,
				'menu',
				true,
				static function ( $key, $raw, &$acc ) use ( $type, $id, $ctx ) {
					return self::item_meta( $key, $raw, $type, (int) $id, $ctx, $acc );
				}
			),
		);
		$object   = '';
		$position = $row['menu_order'];
		foreach ( $meta as $m ) {
			if ( '_menu_item_object' === $m['key'] ) {
				$object = $m['value'];
			}
		}
		$identity = array(
			'menus'    => array_map( 'intval', array_column( array_filter( $tax, static function ( $t ) {
				return 'nav_menu' === $t['taxonomy'];
			} ), 'term_id' ) ),
			'type'     => $type,
			'object'   => $object,
			'position' => $position,
		);
		return AB_MCP_Sync_Canon::finish( 'menu_item', $ref, $identity, $form, $acc );
	}

	/**
	 * The meta of a menu item by _menu_item_type (decision 12): the parent
	 * item, the object (a post for post_type, a term for taxonomy, the item
	 * itself for custom), the texts the core writes; _menu_item_orphaned (the
	 * time the core gives an item without a menu) is left out.
	 *
	 * @param string $key  Meta key.
	 * @param string $raw  Value.
	 * @param string $type _menu_item_type.
	 * @param int    $id   The item.
	 * @param array  $ctx  Context.
	 * @param array  $acc  Accumulator.
	 * @return mixed|null Null where the generic rule applies.
	 */
	public static function item_meta( $key, $raw, $type, $id, array $ctx, array &$acc ) {
		switch ( $key ) {
			case '_menu_item_menu_item_parent':
				return self::id_ref( 'post', $raw, $acc, 'meta:' . $key );
			case '_menu_item_object_id':
				if ( 'post_type' === $type ) {
					return self::id_ref( 'post', $raw, $acc, 'meta:' . $key );
				}
				if ( 'taxonomy' === $type ) {
					return self::id_ref( 'term', $raw, $acc, 'meta:' . $key );
				}
				if ( 'custom' === $type && (string) $id === (string) $raw ) {
					return array( '~ref' => 'self' );
				}
				AB_MCP_Sync_Refs::note_possible( $acc, $raw, 'meta:' . $key );
				return (string) $raw;
			case '_menu_item_orphaned':
				return array( '~left_out' => 'orphaned' );
		}
		if ( in_array( $key, self::ITEM_TEXT_META, true ) ) {
			return AB_MCP_Sync_Canon::value( $raw, $ctx, $acc, 'menu', 'meta:' . $key );
		}
		return null;
	}
}

/**
 * Class AB_MCP_Sync_Kind_Widget_Area: a sidebar with its widget instances
 * as fields (A7, decision 24).
 */
class AB_MCP_Sync_Kind_Widget_Area extends AB_MCP_Sync_Db_Kind {

	/** @return string */
	public function kind() {
		return 'widget_area';
	}

	/** @return string[] */
	public function responsibility() {
		return array( 'option:sidebars_widgets', 'option:widget_*' );
	}

	/**
	 * Every area of sidebars_widgets.
	 *
	 * @param mixed      $source AB_MCP_Sync_Inventory::source().
	 * @param mixed|null $cursor array( 'after' => number of areas done ).
	 * @return array|WP_Error
	 */
	public function inventory( $source, $cursor ) {
		$done  = is_array( $cursor ) && isset( $cursor['after'] ) ? (int) $cursor['after'] : 0;
		$areas = self::option_array( 'sidebars_widgets' );
		if ( null === $areas ) {
			return AB_MCP_Sync_Inventory::unreadable( 'sidebars_widgets' );
		}
		$names   = array_values( array_filter( array_keys( $areas ), static function ( $k ) {
			return 'array_version' !== $k;
		} ) );
		$objects = array();
		// Rows read in this call: the budget is asked after each, whether
		// it gave an object or not, so a long run of rows no object comes
		// from ends in time as well, and the cursor goes on past them.
		$seen    = 0;
		foreach ( $names as $i => $name ) {
			if ( $i < $done ) {
				continue;
			}
			if ( $seen > 0 && AB_MCP_Sync_Inventory::out_of_time( $source ) ) {
				return array( 'objects' => $objects, 'cursor' => array( 'after' => $i ) );
			}
			$objects[] = AB_MCP_Sync_Inventory::entry( $this->read( (string) $name, $source, 'widget_area' ) );
			++$seen;
		}
		return array( 'objects' => $objects, 'cursor' => null );
	}

	/** {@inheritdoc} */
	protected function read( $ref, array $source, $pair ) {
		$acc   = AB_MCP_Sync_Canon::acc();
		$ctx   = $source['ctx'];
		$areas = self::option_array( 'sidebars_widgets' );
		if ( null === $areas ) {
			$acc['unread'][] = 'sidebars_widgets';
			return AB_MCP_Sync_Canon::finish( 'widget_area', $ref, array( 'area' => $ref ), array(), $acc );
		}
		if ( ! array_key_exists( $ref, $areas ) || 'array_version' === $ref ) {
			return null;
		}
		$rows      = array();
		$instances = array();
		foreach ( is_array( $areas[ $ref ] ) ? $areas[ $ref ] : array() as $widget ) {
			$widget = (string) $widget;
			if ( ! preg_match( '/^(.+)-(\d+)\z/', $widget, $m ) ) {
				$instances[] = array(
					'base'     => $widget,
					'settings' => null,
				);
				continue;
			}
			$base = $m[1];
			if ( ! array_key_exists( $base, $rows ) ) {
				$rows[ $base ] = self::option_array( 'widget_' . $base );
			}
			if ( null === $rows[ $base ] ) {
				$acc['unread'][] = 'widget_' . $base;
				continue;
			}
			$settings = isset( $rows[ $base ][ (int) $m[2] ] ) ? $rows[ $base ][ (int) $m[2] ] : null;
			$at       = 'widget:' . $widget;
			if ( ! AB_MCP_Sync_Credentials::note( $acc, 'widget_area', $at, AB_MCP_Sync_Credentials::unit( $base, $settings ) ) ) {
				continue;
			}
			$instances[] = array(
				'base'     => $base,
				'settings' => null === $settings ? array( '~missing' => true ) : AB_MCP_Sync_Canon::structure( $settings, $ctx, $acc, 'widget_area', $at, self::leaf( $base, $ctx, $acc, $at ) ),
			);
		}
		return AB_MCP_Sync_Canon::finish( 'widget_area', $ref, array( 'area' => $ref ), array( 'area' => $ref, 'instances' => $instances ), $acc );
	}

	/**
	 * The schema of a widget's settings (AB_MCP_Sync_Refs::WIDGETS): its
	 * references, its content, plain numbers of the core widgets, possible
	 * references for the others.
	 *
	 * @param string $base Widget base.
	 * @param array  $ctx  Context.
	 * @param array  $acc  Accumulator.
	 * @param string $at   Where.
	 * @return callable
	 */
	public static function leaf( $base, array $ctx, array &$acc, $at ) {
		$schema = isset( AB_MCP_Sync_Refs::WIDGETS[ $base ] ) ? AB_MCP_Sync_Refs::WIDGETS[ $base ] : array();
		$core   = in_array( $base, AB_MCP_Sync_Refs::CORE_WIDGETS, true );
		return static function ( $value, $path ) use ( $schema, $core, $ctx, &$acc, $at ) {
			$field = explode( '.', (string) $path )[0];
			$type  = isset( $schema[ $field ] ) ? $schema[ $field ] : '';
			$where = $at . '.' . $path;
			if ( ( 'post' === $type || 'term' === $type || 'post_list' === $type ) && ( is_int( $value ) || is_string( $value ) ) ) {
				return self::id_ref( 'term' === $type ? 'term' : 'post', (string) $value, $acc, $where );
			}
			if ( 'post_text' === $type && is_string( $value ) ) {
				$cuts = array();
				if ( preg_match_all( '/\d+/', $value, $m, PREG_OFFSET_CAPTURE ) ) {
					foreach ( $m[0] as $hit ) {
						$cuts[] = array(
							'at'     => (int) $hit[1],
							'len'    => strlen( $hit[0] ),
							'marker' => self::id_ref( 'post', $hit[0], $acc, $where ),
						);
					}
				}
				return AB_MCP_Sync_Canon::text( $value, $ctx, $cuts );
			}
			if ( 'content' === $type && is_string( $value ) ) {
				return AB_MCP_Sync_Refs::content( $value, $ctx, $acc, 'widget_area', $where );
			}
			if ( ! $core ) {
				AB_MCP_Sync_Refs::note_possible( $acc, $value, $where );
			}
			return null;
		};
	}
}

/**
 * Class AB_MCP_Sync_Kind_Custom_Css: the Additional CSS of a stylesheet (A9).
 */
class AB_MCP_Sync_Kind_Custom_Css extends AB_MCP_Sync_Db_Kind {

	/** @return string */
	public function kind() {
		return 'custom_css';
	}

	/** @return string[] */
	public function responsibility() {
		return array( 'post_type:custom_css', 'theme_mod:custom_css_post_id' );
	}

	/**
	 * Every custom_css post.
	 *
	 * @param mixed      $source AB_MCP_Sync_Inventory::source().
	 * @param mixed|null $cursor array( 'after' => id ).
	 * @return array|WP_Error
	 */
	public function inventory( $source, $cursor ) {
		$after   = is_array( $cursor ) && isset( $cursor['after'] ) ? (int) $cursor['after'] : 0;
		$objects = array();
		// Rows read in this call: the budget is asked after each, whether
		// it gave an object or not, so a long run of rows no object comes
		// from ends in time as well, and the cursor goes on past them.
		$seen    = 0;
		while ( true ) {
			$ids = AB_MCP_Sync_Rows::post_ids( array( 'custom_css' ), $after, self::PAGE );
			if ( null === $ids ) {
				return AB_MCP_Sync_Inventory::unreadable( 'posts' );
			}
			foreach ( $ids as $row ) {
				if ( $seen > 0 && AB_MCP_Sync_Inventory::out_of_time( $source ) ) {
					return array( 'objects' => $objects, 'cursor' => array( 'after' => $after ) );
				}
				$after  = $row['ID'];
				++$seen;
				$result = $this->post_form( (int) $row['ID'], $source );
				if ( is_array( $result ) ) {
					$objects[] = AB_MCP_Sync_Inventory::entry( $result );
				}
			}
			if ( count( $ids ) < self::PAGE ) {
				return array( 'objects' => $objects, 'cursor' => null );
			}
		}
	}

	/**
	 * The CSS by its ref: the stylesheet, for the post the theme mods of that
	 * stylesheet name (custom_css_post_id); '<stylesheet>@<id>' for a CSS post
	 * of that stylesheet they do not name.
	 *
	 * @param string $ref    Ref.
	 * @param array  $source Source.
	 * @param string $pair   custom_css.
	 * @return array|null
	 */
	protected function read( $ref, array $source, $pair ) {
		$ref = (string) $ref;
		$at  = strrpos( $ref, '@' );
		if ( false !== $at && preg_match( '/^\d+\z/', substr( $ref, $at + 1 ) ) ) {
			$id     = (int) substr( $ref, $at + 1 );
			$result = $this->post_form( $id, $source );
		} else {
			$mods = self::option_array( 'theme_mods_' . $ref );
			if ( null === $mods ) {
				$acc             = AB_MCP_Sync_Canon::acc();
				$acc['unread'][] = 'theme_mods';
				return AB_MCP_Sync_Canon::finish( 'custom_css', $ref, array(), array(), $acc );
			}
			$id     = isset( $mods['custom_css_post_id'] ) ? (int) $mods['custom_css_post_id'] : 0;
			$result = $id > 0 ? $this->post_form( $id, $source ) : null;
		}
		// The ref names the object only as the inventory names it now.
		return is_array( $result ) && $result['ref'] === $ref ? $result : null;
	}

	/**
	 * The CSS of a custom_css post, with its ref.
	 *
	 * @param int   $id     Post id.
	 * @param array $source Source.
	 * @return array|null
	 */
	private function post_form( $id, array $source ) {
		$id  = (int) $id;
		$row = $id > 0 ? AB_MCP_Sync_Rows::post( $id ) : false;
		if ( false === $row || ( is_array( $row ) && 'custom_css' !== $row['post_type'] ) ) {
			return null;
		}
		$acc = AB_MCP_Sync_Canon::acc();
		if ( null === $row ) {
			$acc['unread'][] = 'posts';
			return AB_MCP_Sync_Canon::finish( 'custom_css', '@' . $id, array(), array(), $acc );
		}
		$mods = self::option_array( 'theme_mods_' . $row['post_name'] );
		if ( null === $mods ) {
			$acc['unread'][] = 'theme_mods';
			$mods            = array();
		}
		$linked = isset( $mods['custom_css_post_id'] ) && (int) $mods['custom_css_post_id'] === $id;
		foreach ( array( 'post_name', 'post_content', 'post_status' ) as $column ) {
			AB_MCP_Sync_Credentials::note( $acc, 'custom_css', $column, AB_MCP_Sync_Credentials::unit( '', (string) $row[ $column ] ) );
		}
		$form = array(
			'stylesheet' => $row['post_name'],
			'css'        => AB_MCP_Sync_Canon::text( $row['post_content'], $source['ctx'] ),
			'status'     => $row['post_status'],
		);
		return AB_MCP_Sync_Canon::finish(
			'custom_css',
			$linked ? $row['post_name'] : $row['post_name'] . '@' . $id,
			array(
				'stylesheet' => $row['post_name'],
				'post'       => $id,
				'linked'     => $linked,
			),
			$form,
			$acc
		);
	}
}

/**
 * Class AB_MCP_Sync_Kind_Option: the options of the core with a schema
 * (A8, A19, decision 27); every other row of the option kind is listed with
 * its status (locked, guarded, B9).
 */
class AB_MCP_Sync_Kind_Option extends AB_MCP_Sync_Db_Kind {

	/** @return string */
	public function kind() {
		return 'option';
	}

	/** @return string[] */
	public function responsibility() {
		return array( 'option:*' );
	}

	/**
	 * An option (ref: its name) and a key of theme mods no kind takes over
	 * (ref: '<stylesheet>/<key>'; '<stylesheet>' for a row of theme mods that
	 * does not unpack). A stylesheet is a folder name and holds no "/".
	 *
	 * @return string[]
	 */
	public function pair_kinds() {
		return array( 'option', 'theme_mod' );
	}

	/**
	 * Every option row but group B and those of the widget kind.
	 *
	 * @param mixed      $source AB_MCP_Sync_Inventory::source().
	 * @param mixed|null $cursor array( 'after' => option_id ).
	 * @return array|WP_Error
	 */
	public function inventory( $source, $cursor ) {
		$after   = is_array( $cursor ) && isset( $cursor['after'] ) ? (int) $cursor['after'] : 0;
		$objects = array();
		// Rows read in this call: the budget is asked after each, whether
		// it gave an object or not, so a long run of rows no object comes
		// from ends in time as well, and the cursor goes on past them.
		$seen    = 0;
		while ( true ) {
			$rows = AB_MCP_Sync_Rows::options( $after, self::PAGE );
			if ( null === $rows ) {
				return AB_MCP_Sync_Inventory::unreadable( 'options' );
			}
			foreach ( $rows as $row ) {
				if ( $seen > 0 && AB_MCP_Sync_Inventory::out_of_time( $source ) ) {
					return array( 'objects' => $objects, 'cursor' => array( 'after' => $after ) );
				}
				$after = $row['option_id'];
				++$seen;
				foreach ( $this->entries( $row, $source ) as $entry ) {
					$objects[] = $entry;
				}
			}
			if ( count( $rows ) < self::PAGE ) {
				return array( 'objects' => $objects, 'cursor' => null );
			}
		}
	}

	/** {@inheritdoc} */
	protected function read( $ref, array $source, $pair ) {
		if ( 'theme_mod' === $pair ) {
			return $this->theme_mod( (string) $ref, $source );
		}
		$row = AB_MCP_Sync_Rows::option( (string) $ref );
		if ( false === $row ) {
			return null;
		}
		if ( null === $row ) {
			$acc             = AB_MCP_Sync_Canon::acc();
			$acc['unread'][] = 'options';
			return AB_MCP_Sync_Canon::finish( 'option', (string) $ref, array( 'name' => (string) $ref ), array(), $acc );
		}
		$class = AB_MCP_Sync_Option_Schema::classify( (string) $ref, $row['value'] );
		// A row of another kind (widgets) or of fields (theme mods) is not
		// one object of this kind.
		if ( 'owner' === $class['status'] && ( 'fields' === $class['owner']['status'] || ( null !== $class['owner']['kind'] && 'z8' === $class['owner']['status'] ) ) ) {
			return null;
		}
		return $this->option( (string) $ref, $row['value'], $class, $source );
	}

	/**
	 * The inventory entries of one row: none for group B and the widget
	 * kind's rows, one per locked key of theme mods, one for every other.
	 *
	 * @param array $row    Row (AB_MCP_Sync_Rows::options()).
	 * @param array $source Source.
	 * @return array[]
	 */
	protected function entries( array $row, array $source ) {
		$class = AB_MCP_Sync_Option_Schema::classify( $row['name'], $row['value'] );
		if ( 'excluded' === $class['status'] ) {
			return array();
		}
		if ( 'owner' === $class['status'] ) {
			$owner = $class['owner'];
			if ( 'fields' === $owner['status'] ) {
				$out = array();
				foreach ( $this->theme_mods( $row, $source ) as $result ) {
					$out[] = null === $result['hash'] ? $result : AB_MCP_Sync_Inventory::entry( $result );
				}
				return $out;
			}
			if ( null !== $owner['kind'] && 'z8' === $owner['status'] ) {
				return array();
			}
		}
		$result = $this->option( $row['name'], $row['value'], $class, $source );
		return array( null === $result['hash'] ? $result : AB_MCP_Sync_Inventory::entry( $result ) );
	}

	/**
	 * The canonical form of one option row by its class.
	 *
	 * @param string $name   Name.
	 * @param string $raw    Value as stored.
	 * @param array  $class  AB_MCP_Sync_Option_Schema::classify().
	 * @param array  $source Source.
	 * @return array
	 */
	protected function option( $name, $raw, array $class, array $source ) {
		if ( 'excluded' === $class['status'] ) {
			return $this->bare( $name, $class );
		}
		// The credential check comes before the guard: a name of a
		// credential is B9 and counted as such (decision 9).
		$acc   = AB_MCP_Sync_Canon::acc();
		$check = AB_MCP_Sync_Credentials::unit( $name, in_array( $class['status'], array( 'guarded', 'unreachable', 'unreadable' ), true ) ? '' : $raw );
		if ( ! AB_MCP_Sync_Credentials::note( $acc, 'option', 'option:' . $name, $check ) ) {
			return $this->bare( $name, array( 'status' => 'b9' ) + $class );
		}
		if ( in_array( $class['status'], array( 'guarded', 'unreachable', 'unreadable' ), true ) ) {
			return $this->bare( $name, $class );
		}
		if ( 'core' === $class['status'] ) {
			$value = AB_MCP_Sync_Option_Schema::canonical( $class['schema'], $raw, $source['ctx'], $acc );
		} else {
			$acc['locks'][] = array(
				'key'    => $class['key'],
				'stage'  => $class['stage'],
				'reason' => $class['reason'],
				'at'     => 'option',
			);
			$value          = AB_MCP_Sync_Canon::value( $raw, $source['ctx'], $acc, 'option', 'option_value' );
		}
		$result           = AB_MCP_Sync_Canon::finish( 'option', $name, array( 'name' => $name ), array( 'name' => $name, 'value' => $value ), $acc );
		$result['status'] = $class['status'];
		return $result;
	}

	/**
	 * One result per key of a theme mods row that no kind takes over (locked
	 * Z8d; the positions and the CSS post belong to menu and custom_css), of
	 * the pair kind theme_mod, with its form.
	 *
	 * @param array $row    Row.
	 * @param array $source Source.
	 * @return array[]
	 */
	protected function theme_mods( array $row, array $source ) {
		$stylesheet = substr( (string) $row['name'], strlen( 'theme_mods_' ) );
		$acc        = AB_MCP_Sync_Canon::acc();
		$read       = AB_MCP_Sync_Canon::unserialized( $row['value'] );
		if ( ! $read['ok'] || ! is_array( $read['value'] ) ) {
			AB_MCP_Sync_Canon::lock( $acc, 'option', $read['ok'] ? 'unreadable_value' : $read['why'], 'theme_mods' );
			$result           = AB_MCP_Sync_Canon::finish( 'theme_mod', $stylesheet, array( 'name' => $row['name'] ), array( 'name' => $row['name'], 'value' => AB_MCP_Sync_Canon::text( $row['value'], $source['ctx'] ) ), $acc );
			$result['status'] = 'locked';
			return array( $result );
		}
		$out = array();
		foreach ( $read['value'] as $key => $value ) {
			$answer = AB_MCP_Sync_Kinds::theme_mod( (string) $key );
			if ( 'locked' !== $answer['status'] ) {
				continue;
			}
			$acc            = AB_MCP_Sync_Canon::acc();
			$ref            = $stylesheet . '/' . $key;
			$acc['locks'][] = array(
				'key'    => $answer['key'],
				'stage'  => $answer['stage'],
				'reason' => $answer['reason'],
				'at'     => 'theme_mod',
			);
			if ( ! AB_MCP_Sync_Credentials::note( $acc, 'option', 'theme_mod:' . $key, AB_MCP_Sync_Credentials::unit( (string) $key, $value ) ) ) {
				$out[] = array( 'kind' => 'theme_mod' ) + $this->bare( $ref, array( 'status' => 'b9', 'key' => $answer['key'], 'stage' => null, 'reason' => null ) );
				continue;
			}
			$result           = AB_MCP_Sync_Canon::finish( 'theme_mod', $ref, array( 'name' => $row['name'], 'theme_mod' => (string) $key ), array( 'name' => $row['name'], 'theme_mod' => (string) $key, 'value' => AB_MCP_Sync_Canon::structure( $value, $source['ctx'], $acc, 'option', 'theme_mod:' . $key ) ), $acc );
			$result['status'] = 'locked';
			$out[]            = $result;
		}
		return $out;
	}

	/**
	 * One key of theme mods by its ref, as theme_mods() lists it.
	 *
	 * @param string $ref    '<stylesheet>/<key>' or '<stylesheet>'.
	 * @param array  $source Source.
	 * @return array|null
	 */
	protected function theme_mod( $ref, array $source ) {
		$slash      = strpos( $ref, '/' );
		$stylesheet = false === $slash ? $ref : substr( $ref, 0, $slash );
		$row        = AB_MCP_Sync_Rows::option( 'theme_mods_' . $stylesheet );
		if ( false === $row || '' === $stylesheet ) {
			return null;
		}
		if ( null === $row ) {
			$acc             = AB_MCP_Sync_Canon::acc();
			$acc['unread'][] = 'options';
			return AB_MCP_Sync_Canon::finish( 'theme_mod', $ref, array(), array(), $acc );
		}
		foreach ( $this->theme_mods( array( 'name' => 'theme_mods_' . $stylesheet, 'value' => $row['value'] ), $source ) as $result ) {
			if ( $result['ref'] === $ref ) {
				return $result;
			}
		}
		return null;
	}

	/**
	 * An entry without form or hash: a row that is never compared.
	 *
	 * @param string $name  Name.
	 * @param array  $class Class.
	 * @return array
	 */
	protected function bare( $name, array $class ) {
		return array(
			'kind'        => 'option',
			'ref'         => (string) $name,
			'identity'    => array( 'name' => (string) $name ),
			'status'      => $class['status'],
			'canon'       => AB_MCP_Sync_Canon::VERSION,
			'hash'        => null,
			'size'        => 0,
			'refs'        => array(),
			'possible'    => array(),
			'locks'       => array(),
			'credentials' => array(
				'b9'    => 'b9' === $class['status'] ? array( 'option:' . $name ) : array(),
				'mixed' => array(),
			),
			'complete'    => 'unreadable' !== $class['status'],
			'reason'      => 'b9' === $class['status'] ? 'The name is the name of a credential (B9): never compared, never packed, counted.' : ( isset( $class['reason'] ) ? $class['reason'] : null ),
		);
	}
}

/**
 * Class AB_MCP_Sync_Db_Kinds: the kinds of the database, registered once.
 */
final class AB_MCP_Sync_Db_Kinds {

	/** The kinds in the order of their inventory. */
	const CLASSES = array( 'AB_MCP_Sync_Kind_Post', 'AB_MCP_Sync_Kind_Term', 'AB_MCP_Sync_Kind_Menu', 'AB_MCP_Sync_Kind_Widget_Area', 'AB_MCP_Sync_Kind_Custom_Css', 'AB_MCP_Sync_Kind_Option' );

	/**
	 * Register each kind with AB_MCP_Sync_Kinds that is not there yet.
	 *
	 * @return true|WP_Error
	 */
	public static function register_all() {
		foreach ( self::CLASSES as $class ) {
			$kind = new $class();
			if ( null !== AB_MCP_Sync_Kinds::get( $kind->kind() ) ) {
				continue;
			}
			$done = AB_MCP_Sync_Kinds::register( $kind );
			if ( is_wp_error( $done ) ) {
				return $done;
			}
		}
		return true;
	}

	/**
	 * The kind whose objects are of a pair kind (an object of the inventory
	 * names its pair kind as 'kind').
	 *
	 * @param string $pair Pair kind.
	 * @return AB_MCP_Sync_Db_Kind|null
	 */
	public static function for_pair( $pair ) {
		$kinds = self::all();
		if ( is_wp_error( $kinds ) ) {
			return null;
		}
		foreach ( $kinds as $kind ) {
			if ( in_array( (string) $pair, $kind->pair_kinds(), true ) ) {
				return $kind;
			}
		}
		return null;
	}

	/**
	 * The registered kinds of the database, in the order of CLASSES.
	 *
	 * @return AB_MCP_Sync_Kind[]|WP_Error
	 */
	public static function all() {
		$done = self::register_all();
		if ( is_wp_error( $done ) ) {
			return $done;
		}
		$out = array();
		foreach ( self::CLASSES as $class ) {
			$probe = new $class();
			$out[] = AB_MCP_Sync_Kinds::get( $probe->kind() );
		}
		return $out;
	}
}

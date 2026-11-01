<?php
/**
 * The lock of page builders by their identity (Z8, decision 31 of the plan
 * of 01.11.2026): a post is locked when AB_MCP_Builders::detect_all() names
 * a builder that is not released, whatever its storage class; released in
 * Z8 are only the six block libraries of RELEASED
 * (includes/builders/signatures.php), Z8c widens the list.
 *
 * detect_all() is asked with the post as its row holds it (not as
 * get_post() filters it), and only for a post whose stored meta rows hold
 * no object: its markers in meta go through get_post_meta(), whose
 * maybe_unserialize() makes an object of any class it finds (and runs its
 * __wakeup()). A post with such a row is locked (*:php_object) without
 * asking detect_all(). The markers are asked of the stored meta rows as well
 * (stored_markers(), unpacked without classes), since a filter can change
 * what get_post_meta() reads; either answer locks. Nothing a filter does can
 * take a lock away, only add one.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__ ) . '/builders/class-builders.php';
require_once __DIR__ . '/class-sync-canon.php';

/**
 * Class AB_MCP_Sync_Builder_Lock
 */
final class AB_MCP_Sync_Builder_Lock {

	/** The builders Z8 takes over: the block libraries in post_content. */
	const RELEASED = array( 'kadence', 'spectra', 'generateblocks', 'stackable', 'otter', 'coblocks' );

	/**
	 * The builders of a post that are not released.
	 *
	 * @param array $row  The post's row (AB_MCP_Sync_Rows::post()).
	 * @param array $meta Its meta rows (AB_MCP_Sync_Rows::meta()).
	 * @return array<int,array{id:string,name:string,detected_by:string[]}>
	 */
	public static function locked( array $row, array $meta ) {
		$out = array();
		foreach ( self::checkable( $meta ) ? AB_MCP_Builders::detect_all( self::post( $row ) ) : array() as $found ) {
			if ( ! in_array( (string) $found['id'], self::RELEASED, true ) ) {
				$out[ (string) $found['id'] ] = array(
					'id'          => (string) $found['id'],
					'name'        => (string) $found['name'],
					'detected_by' => (array) $found['detected_by'],
				);
			}
		}
		foreach ( self::stored_markers( $meta ) as $id => $by ) {
			if ( ! in_array( $id, self::RELEASED, true ) && ! isset( $out[ $id ] ) ) {
				$sig        = AB_MCP_Builders::signature( $id );
				$out[ $id ] = array(
					'id'          => $id,
					'name'        => is_array( $sig ) && isset( $sig['name'] ) ? (string) $sig['name'] : $id,
					'detected_by' => $by,
				);
			}
		}
		return array_values( $out );
	}

	/**
	 * Lock the object of an accumulator for each builder of locked().
	 *
	 * @param array $acc  Accumulator.
	 * @param array $row  Row of the post.
	 * @param array $meta Its meta rows.
	 * @return array The builders.
	 */
	public static function note( array &$acc, array $row, array $meta ) {
		if ( ! self::checkable( $meta ) ) {
			AB_MCP_Sync_Canon::lock( $acc, 'post', 'php_object', 'builder' );
		}
		$builders = self::locked( $row, $meta );
		foreach ( $builders as $builder ) {
			AB_MCP_Sync_Canon::lock( $acc, 'post', 'builder', 'builder:' . $builder['id'] );
		}
		return $builders;
	}

	/**
	 * Whether detect_all() may read the meta of a post: no stored row holds
	 * an object, a reference or a record (AB_MCP_Sync_Canon::holds_record()).
	 *
	 * @param array $meta Meta rows.
	 * @return bool
	 */
	public static function checkable( array $meta ) {
		foreach ( $meta as $row ) {
			if ( AB_MCP_Sync_Canon::holds_record( $row['value'] ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * The builders whose meta markers (signatures.php: meta, equals, not_in)
	 * the stored meta rows carry, as AB_MCP_Builders matches them on the
	 * values get_post_meta( …, true ) reads (the row with the lowest id).
	 *
	 * @param array $meta Meta rows, by meta_id.
	 * @return array<string,string[]> Builder id => markers.
	 */
	public static function stored_markers( array $meta ) {
		$first = array();
		foreach ( $meta as $row ) {
			if ( ! isset( $first[ $row['key'] ] ) ) {
				$first[ $row['key'] ] = $row['value'];
			}
		}
		$out = array();
		foreach ( AB_MCP_Builders::signatures() as $id => $sig ) {
			$found = array();
			foreach ( isset( $sig['markers'] ) ? (array) $sig['markers'] : array() as $marker ) {
				if ( ! isset( $marker['meta'] ) || ! isset( $first[ $marker['meta'] ] ) ) {
					continue;
				}
				$read  = AB_MCP_Sync_Canon::unserialized( $first[ $marker['meta'] ] );
				$value = $read['ok'] ? $read['value'] : $first[ $marker['meta'] ];
				if ( isset( $marker['equals'] ) ) {
					$hit = is_scalar( $value ) && (string) $value === (string) $marker['equals'];
				} else {
					$empty = null === $value || false === $value || '' === $value || '0' === $value || array() === $value;
					$hit   = ! $empty && ! ( isset( $marker['not_in'] ) && is_scalar( $value ) && in_array( (string) $value, (array) $marker['not_in'], true ) );
				}
				if ( $hit ) {
					$found[] = 'meta:' . $marker['meta'];
				}
			}
			if ( array() !== $found ) {
				$out[ (string) $id ] = $found;
			}
		}
		return $out;
	}

	/**
	 * A post object from the stored row, for detect_all().
	 *
	 * @param array $row Row.
	 * @return WP_Post|object
	 */
	private static function post( array $row ) {
		$data     = (object) $row;
		$data->ID = (int) $row['ID'];
		return class_exists( 'WP_Post' ) ? new WP_Post( $data ) : $data;
	}
}

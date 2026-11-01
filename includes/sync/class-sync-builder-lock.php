<?php
/**
 * The lock of page builders by their identity (Z8, decision 31 of the plan
 * of 01.11.2026): a post is locked when it carries the markers of a builder
 * that is not released, whatever its storage class; released in Z8 are
 * only the six block libraries of RELEASED (includes/builders/signatures.php),
 * Z8c widens the list.
 *
 * The markers are the signatures of AB_MCP_Builders (signatures.php and the
 * filter ab_mcp_builder_signatures), matched here on the stored rows as
 * AB_MCP_Builders::markers_found() matches them on what WordPress reads:
 * a meta marker on the row with the lowest meta_id (get_post_meta( …, true )),
 * unpacked without classes; a content marker on post_content as stored. No
 * function of WordPress reads a value for it (AB_MCP_Builders::detect_all()
 * reads its markers through get_post_meta(), whose maybe_unserialize()
 * makes an object of any class it finds). The integration suite holds the
 * answer against detect_all() for every signature.
 *
 * A reader a plugin registers (ab_mcp_builder_adapters) for a builder with
 * no signature recognises its pages only with its own code: while one is
 * registered, every post locks (post:builder_unknown).
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
	 * @param array $meta Its meta rows (AB_MCP_Sync_Rows::meta()), by meta_id.
	 * @return array<int,array{id:string,name:string,detected_by:string[]}>
	 */
	public static function locked( array $row, array $meta ) {
		$out = array();
		foreach ( self::detected( $row, $meta ) as $id => $found ) {
			if ( ! in_array( $id, self::RELEASED, true ) ) {
				$out[] = $found;
			}
		}
		return $out;
	}

	/**
	 * Every builder whose markers the stored rows of a post carry, in the
	 * order of the signatures.
	 *
	 * @param array $row  Row of the post.
	 * @param array $meta Its meta rows.
	 * @return array<string,array{id:string,name:string,detected_by:string[]}>
	 */
	public static function detected( array $row, array $meta ) {
		$first = array();
		foreach ( $meta as $item ) {
			if ( ! isset( $first[ $item['key'] ] ) ) {
				$first[ $item['key'] ] = $item['value'];
			}
		}
		$content = isset( $row['post_content'] ) ? (string) $row['post_content'] : '';
		$out     = array();
		foreach ( AB_MCP_Builders::signatures() as $id => $sig ) {
			$found = self::markers( $sig['markers'], $first, $content );
			if ( array() === $found || ( array() !== $sig['unless'] && array() !== self::markers( $sig['unless'], $first, $content ) ) ) {
				continue;
			}
			$out[ (string) $id ] = array(
				'id'          => (string) $id,
				'name'        => (string) $sig['name'],
				'detected_by' => $found,
			);
		}
		return $out;
	}

	/**
	 * The readers a plugin registers for a builder with no signature here.
	 *
	 * @return string[] Their ids.
	 */
	public static function unknown() {
		$out = array();
		foreach ( AB_MCP_Builders::adapters() as $id => $adapter ) {
			if ( 'blocks' !== (string) $id && null === AB_MCP_Builders::signature( (string) $id ) ) {
				$out[] = (string) $id;
			}
		}
		return $out;
	}

	/**
	 * Lock the object of an accumulator for each builder of locked(), and
	 * for each reader of unknown().
	 *
	 * @param array $acc  Accumulator.
	 * @param array $row  Row of the post.
	 * @param array $meta Its meta rows.
	 * @return array The builders.
	 */
	public static function note( array &$acc, array $row, array $meta ) {
		foreach ( self::unknown() as $id ) {
			AB_MCP_Sync_Canon::lock( $acc, 'post', 'builder_unknown', 'builder:' . $id );
		}
		$builders = self::locked( $row, $meta );
		foreach ( $builders as $builder ) {
			AB_MCP_Sync_Canon::lock( $acc, 'post', 'builder', 'builder:' . $builder['id'] );
		}
		return $builders;
	}

	/**
	 * The markers of a list a post carries, as the evidence strings of
	 * AB_MCP_Builders::markers_found().
	 *
	 * @param array  $markers Normalised markers.
	 * @param array  $first   Meta key => value of its first row, as stored.
	 * @param string $content post_content as stored.
	 * @return string[]
	 */
	private static function markers( array $markers, array $first, $content ) {
		$found = array();
		foreach ( $markers as $m ) {
			if ( isset( $m['meta'] ) ) {
				$value = self::meta_value( $first, $m['meta'] );
				if ( isset( $m['equals'] ) ) {
					$hit = is_scalar( $value ) && (string) $value === $m['equals'];
				} else {
					$hit = ! self::is_empty_value( $value )
						&& ! ( isset( $m['not_in'] ) && is_scalar( $value ) && in_array( (string) $value, $m['not_in'], true ) );
				}
				if ( $hit ) {
					$found[] = 'meta:' . $m['meta'];
				}
			} elseif ( isset( $m['content'] ) ) {
				if ( false !== strpos( $content, $m['content'] ) ) {
					$found[] = 'content:' . $m['content'];
				}
			} elseif ( isset( $m['content_prefix'] ) ) {
				if ( 0 === strpos( $content, $m['content_prefix'] ) ) {
					$found[] = 'content:' . $m['content_prefix'];
				}
			} elseif ( isset( $m['content_regex'] ) ) {
				if ( 1 === preg_match( $m['content_regex'], $content ) ) {
					$found[] = 'content:' . ( '' !== $m['like'] ? $m['like'] : $m['content_regex'] );
				}
			}
		}
		return $found;
	}

	/**
	 * A meta value as get_post_meta( …, true ) reads it: '' without a row;
	 * a stored object, reference or record stands as an object (set, no
	 * scalar), a value that does not unpack as false, as maybe_unserialize()
	 * answers for it.
	 *
	 * @param array  $first Meta key => first stored value.
	 * @param string $key   Meta key.
	 * @return mixed
	 */
	private static function meta_value( array $first, $key ) {
		if ( ! isset( $first[ $key ] ) ) {
			return '';
		}
		$read = AB_MCP_Sync_Canon::unserialized( $first[ $key ] );
		if ( $read['ok'] ) {
			return $read['value'];
		}
		return 'php_object' === $read['why'] ? new stdClass() : false;
	}

	/**
	 * AB_MCP_Builders::is_empty_value(): missing, '', '0', 0, false or an
	 * empty array.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private static function is_empty_value( $value ) {
		return null === $value || false === $value || '' === $value || '0' === $value || 0 === $value || array() === $value;
	}
}

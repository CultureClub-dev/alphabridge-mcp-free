<?php
/**
 * Field-type security for writing page-builder fields: what a value may be,
 * per kind, before any writer encodes it for its builder.
 *
 * The typed way needs no unfiltered_html (decision 6 of the plan review):
 * every field has a kind from the reading adapter, and the kind decides.
 * - text and html: as post content may hold it (wp_kses_post). A value that
 *   kses would change beyond normalising entities, quotes and slashes is
 *   refused, never stored filtered: the change must be the one asked for.
 * - heading, and text on a button: plain text only, no markup.
 * - url: http, https, mailto, tel, or a relative link starting with /, # or ?
 *   (a decision of the plan, §8, not a WordPress rule); never quotes, angle
 *   brackets, back-ticks, white space or square brackets ("[" would open a
 *   shortcode where a builder runs links through do_shortcode, measured for
 *   Kadence).
 * - image: the id of an image attachment the account may read.
 * Placeholders the builders fill in ({post_title}, {{…}}, [breakdance_dynamic
 * …], $variable(…)$) are never rewritten: a change keeps the ones the field
 * has, in order, and brings no new ones.
 *
 * Field names come from the outline (wp_get_builder_layout). The B1 name deny
 * list is applied by the reading adapters already — a field it denies never
 * reaches an outline, so it can never be addressed here.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class AB_MCP_Builder_Fields
 */
final class AB_MCP_Builder_Fields {

	/** Keys the tool accepts in place of a field name, and the kind each addresses. */
	const KIND_ALIASES = array(
		'text'     => 'text',
		'heading'  => 'heading',
		'html'     => 'html',
		'url'      => 'url',
		'image_id' => 'image',
	);

	/** Longest value a field takes, in bytes; a page builder field is not a document store. */
	const MAX_BYTES = 262144;

	/** Element types whose text field is a button label, shown as plain text. */
	const BUTTON_TYPE = '~button|btn~i';

	/** Placeholders builders fill in at render time. */
	const PLACEHOLDERS = '~\{\{.*?\}\}|\{[A-Za-z_][A-Za-z0-9_.:\-]*\}|\[breakdance_dynamic\b[^\]]*\]|\$[A-Za-z_][A-Za-z0-9_]*\([^)]*\)\$~s';

	/** Post types whose content shows on many pages: templates, reusable parts, global layouts. */
	const GLOBAL_TYPES = array( 'elementor_library', 'wp_block', 'wp_template', 'wp_template_part', 'fl-builder-template', 'et_pb_layout', 'seedprod', 'ct_template', 'bricks_template', 'breakdance_template', 'tb_layout', 'blocks' );

	/**
	 * The requested fields as changes of one element: names resolved, every
	 * value checked for its kind, placeholders kept.
	 *
	 * @param array $element Element of the outline (id, type, fields, global?).
	 * @param array $input   Field name (or kind alias) => new value.
	 * @return array|WP_Error List of changes { element, type, field, kind, before, after, truncated?, url? }.
	 */
	public static function changes( array $element, array $input ) {
		if ( array() === $input ) {
			return new WP_Error( 'ab_mcp_builder_no_fields', __( 'No fields to change. Pass fields as an object of field name and new value, with the names wp_get_builder_layout lists for this element.', 'alphabridge-mcp' ) );
		}
		$fields = isset( $element['fields'] ) && is_array( $element['fields'] ) ? $element['fields'] : array();
		$type   = isset( $element['type'] ) ? (string) $element['type'] : '';
		$out    = array();
		$seen   = array();
		foreach ( $input as $key => $value ) {
			$name = self::resolve( $fields, (string) $key );
			if ( is_wp_error( $name ) ) {
				return self::with_element( $name, $element );
			}
			if ( isset( $seen[ $name ] ) ) {
				/* translators: %s: field name */
				return new WP_Error( 'ab_mcp_builder_field_twice', sprintf( __( 'The field %s is named twice (once by its name, once by its kind). Name it once.', 'alphabridge-mcp' ), $name ) );
			}
			$seen[ $name ] = true;
			$field         = $fields[ $name ];
			$kind          = isset( $field['kind'] ) ? (string) $field['kind'] : '';
			$checked       = self::check( $kind, $value, $type, $name, isset( $field['value'] ) ? $field['value'] : null );
			if ( is_wp_error( $checked ) ) {
				return $checked;
			}
			$change = array(
				'element' => isset( $element['id'] ) ? (string) $element['id'] : '',
				'type'    => $type,
				'field'   => $name,
				'kind'    => $kind,
				'before'  => isset( $field['value'] ) ? $field['value'] : '',
				'after'   => $checked['value'],
			);
			if ( ! empty( $field['truncated'] ) ) {
				$change['truncated'] = true;
			}
			if ( isset( $checked['url'] ) ) {
				$change['url'] = $checked['url'];
			}
			// A field the outline cut is judged once the writer has read it in
			// full (execute(), step 1): its cut value may lack a placeholder.
			if ( empty( $change['truncated'] ) ) {
				$keep = self::validate_change( $change, true );
				if ( is_wp_error( $keep ) ) {
					return $keep;
				}
			}
			$out[] = $change;
		}
		return $out;
	}

	/**
	 * Check one value for its kind.
	 *
	 * @param string $kind   text, heading, html, url or image.
	 * @param mixed  $value  Value asked for.
	 * @param string $type   Element type (a button's text is plain text).
	 * @param string $name   Field name, for the messages.
	 * @param mixed  $before The field's value now, where known: a plain-text
	 *                       field keeps markup it already has (an Elementor
	 *                       heading may hold a <span>), and gets none new.
	 * @return array|WP_Error { value } and, for an image, { url }.
	 */
	public static function check( $kind, $value, $type = '', $name = '', $before = null ) {
		$name = '' !== $name ? $name : $kind;
		if ( ! in_array( $kind, array( 'text', 'heading', 'html', 'url', 'image' ), true ) ) {
			/* translators: %s: field name */
			return new WP_Error( 'ab_mcp_builder_field_kind', sprintf( __( 'The field %s has no kind this tool writes. Change it in the page builder\'s editor.', 'alphabridge-mcp' ), $name ) );
		}
		if ( 'image' === $kind ) {
			return self::image( $value, $name );
		}
		if ( ! is_string( $value ) && ! is_int( $value ) && ! is_float( $value ) ) {
			/* translators: %s: field name */
			return new WP_Error( 'ab_mcp_builder_field_value', sprintf( __( 'The value for %s must be text. Pass it as a string.', 'alphabridge-mcp' ), $name ) );
		}
		$value = (string) $value;
		if ( strlen( $value ) > self::MAX_BYTES || 1 !== preg_match( '//u', $value ) ) {
			/* translators: 1: field name, 2: size limit */
			return new WP_Error( 'ab_mcp_builder_field_value', sprintf( __( 'The value for %1$s must be valid UTF-8 text of at most %2$s. Pass UTF-8 text within that size.', 'alphabridge-mcp' ), $name, size_format( self::MAX_BYTES ) ) );
		}
		if ( 1 === preg_match( '~[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]~', $value ) ) {
			/* translators: %s: field name */
			return new WP_Error( 'ab_mcp_builder_field_value', sprintf( __( 'The value for %s contains control characters. Send it without them; line breaks and tabs may stay.', 'alphabridge-mcp' ), $name ) );
		}
		if ( 'url' === $kind ) {
			return self::url( $value, $name );
		}
		if ( self::plain_only( $kind, $type ) ) {
			$kept = null !== $before && is_scalar( $before ) && self::tags( $value ) === self::tags( (string) $before );
			if ( 1 === preg_match( '~<[A-Za-z/!?]~', $value ) && ! $kept ) {
				return new WP_Error(
					'ab_mcp_builder_plain_text',
					sprintf(
						/* translators: %s: field name */
						__( 'The field %s takes plain text only, without HTML tags: headings and button labels are shown as text. Send the words without markup.', 'alphabridge-mcp' ),
						$name
					)
				);
			}
			if ( ! $kept || array() === self::tags( $value ) ) {
				return array( 'value' => $value );
			}
			// The markup it had, kept: judged like html below.
		}
		// text and html: what post content may hold.
		if ( false === strpos( $value, '<' ) ) {
			return array( 'value' => $value );
		}
		$clean = wp_kses_post( $value );
		if ( $clean !== $value && self::markup_key( $clean ) !== self::markup_key( $value ) ) {
			return new WP_Error(
				'ab_mcp_builder_kses',
				sprintf(
					/* translators: %s: field name */
					__( 'The value for %s holds markup that post content does not allow (scripts, styles, event attributes, frames or links that run code), and WordPress would store it differently. Nothing was changed. Send it without that markup; to place such code, edit the element in the page builder\'s editor in wp-admin.', 'alphabridge-mcp' ),
					$name
				)
			);
		}
		return array( 'value' => $clean );
	}

	/**
	 * The rules every change has to meet, also one a writer built itself
	 * (replace()): the value checks of its kind again, and the placeholders
	 * of the field stay as they are.
	 *
	 * @param array $change  Change.
	 * @param bool  $checked Whether 'after' has just come out of check().
	 * @return true|WP_Error
	 */
	public static function validate_change( array $change, $checked = false ) {
		foreach ( array( 'element', 'field', 'kind' ) as $key ) {
			if ( ! isset( $change[ $key ] ) || ! is_string( $change[ $key ] ) || '' === $change[ $key ] ) {
				return new WP_Error( 'ab_mcp_builder_bad_change', __( 'A change names no element, field or kind. Change it in the page builder\'s editor.', 'alphabridge-mcp' ) );
			}
		}
		if ( ! array_key_exists( 'after', $change ) || ! array_key_exists( 'before', $change ) ) {
			return new WP_Error( 'ab_mcp_builder_bad_change', __( 'A change carries no value before or after. Change it in the page builder\'s editor.', 'alphabridge-mcp' ) );
		}
		if ( ! $checked ) {
			$again = self::check( $change['kind'], 'image' === $change['kind'] ? $change['after'] : (string) $change['after'], isset( $change['type'] ) ? (string) $change['type'] : '', $change['field'], $change['before'] );
			if ( is_wp_error( $again ) ) {
				return $again;
			}
			// Text and html pass where kses would change nothing but how they
			// are written; a link and an image are taken only in their checked form.
			if ( in_array( $change['kind'], array( 'url', 'image' ), true ) && (string) $again['value'] !== (string) $change['after'] ) {
				return new WP_Error(
					'ab_mcp_builder_bad_change',
					/* translators: %s: field name */
					sprintf( __( 'The new value for %s is not in the form its kind is stored in. Read the element with wp_get_builder_layout and pass the value in the form its field takes, or change it in the page builder\'s editor.', 'alphabridge-mcp' ), $change['field'] )
				);
			}
		}
		if ( 'image' === $change['kind'] ) {
			return true;
		}
		if ( ! empty( $change['truncated'] ) ) {
			return new WP_Error(
				'ab_mcp_builder_field_too_long',
				sprintf(
					/* translators: %s: field name */
					__( 'The field %s is longer than the outline shows, and this page builder\'s writer did not read it in full, so its placeholders cannot be checked. Nothing was changed; change this field in the page builder\'s editor.', 'alphabridge-mcp' ),
					$change['field']
				)
			);
		}
		$before = self::placeholders( (string) $change['before'] );
		$after  = self::placeholders( (string) $change['after'] );
		if ( $before !== $after ) {
			return new WP_Error(
				'ab_mcp_builder_placeholder',
				sprintf(
					/* translators: 1: field name, 2: placeholders before, 3: placeholders after */
					__( 'The field %1$s has placeholders the page builder fills in when the page is shown (%2$s); a change must keep them as they are and add none (asked: %3$s). Nothing was changed. Keep the placeholders in the new text, or change them in the page builder\'s editor.', 'alphabridge-mcp' ),
					$change['field'],
					array() === $before ? '-' : implode( ' ', $before ),
					array() === $after ? '-' : implode( ' ', $after )
				)
			);
		}
		return true;
	}

	/**
	 * Placeholders in a text, in order.
	 *
	 * @param string $text Text.
	 * @return string[]
	 */
	public static function placeholders( $text ) {
		if ( ! preg_match_all( self::PLACEHOLDERS, (string) $text, $m ) ) {
			return array();
		}
		return $m[0];
	}

	/**
	 * Whether a field of this kind on this element takes plain text only.
	 *
	 * @param string $kind Kind.
	 * @param string $type Element type.
	 * @return bool
	 */
	public static function plain_only( $kind, $type ) {
		return 'heading' === $kind || ( 'text' === $kind && 1 === preg_match( self::BUTTON_TYPE, (string) $type ) );
	}

	/**
	 * Whether a post shows on many pages (a template, a reusable part).
	 *
	 * @param WP_Post $post Post.
	 * @return bool
	 */
	public static function is_global_post( $post ) {
		/**
		 * Post types whose content shows on many pages; a change to them comes
		 * with a warning.
		 *
		 * @param string[] $types Post types.
		 */
		$types = (array) apply_filters( 'ab_mcp_pro_builder_global_types', self::GLOBAL_TYPES );
		return is_object( $post ) && in_array( (string) $post->post_type, $types, true );
	}

	/* ------------------------------------------------------------ private */

	/**
	 * The field a key addresses: a field name of the element, or a kind alias
	 * when the element has exactly one field of that kind.
	 *
	 * @param array  $fields Fields of the element.
	 * @param string $key    Key asked for.
	 * @return string|WP_Error
	 */
	private static function resolve( array $fields, $key ) {
		if ( isset( $fields[ $key ] ) ) {
			return $key;
		}
		if ( isset( self::KIND_ALIASES[ $key ] ) ) {
			$kind  = self::KIND_ALIASES[ $key ];
			$names = array();
			foreach ( $fields as $name => $field ) {
				if ( isset( $field['kind'] ) && $kind === $field['kind'] ) {
					$names[] = (string) $name;
				}
			}
			if ( 1 === count( $names ) ) {
				return $names[0];
			}
			if ( count( $names ) > 1 ) {
				return new WP_Error(
					'ab_mcp_builder_field_ambiguous',
					sprintf(
						/* translators: 1: kind, 2: field names */
						__( 'This element has more than one %1$s field (%2$s). Name the field instead of its kind.', 'alphabridge-mcp' ),
						$kind,
						implode( ', ', $names )
					)
				);
			}
		}
		return new WP_Error(
			'ab_mcp_builder_no_field',
			sprintf(
				/* translators: 1: field asked for, 2: field names */
				__( 'This element has no writable field %1$s. Its fields: %2$s. Fields with dynamic values, code or settings are not listed and cannot be changed here; change those in the page builder\'s editor.', 'alphabridge-mcp' ),
				$key,
				array() === $fields ? '-' : implode( ', ', array_map( 'strval', array_keys( $fields ) ) )
			)
		);
	}

	/**
	 * A refusal about a field, with the element's note where it explains why
	 * a field is missing (a dynamic value, say).
	 *
	 * @param WP_Error $error   Refusal.
	 * @param array    $element Element.
	 * @return WP_Error
	 */
	private static function with_element( WP_Error $error, array $element ) {
		if ( isset( $element['note'] ) && is_string( $element['note'] ) && '' !== $element['note'] ) {
			return new WP_Error( $error->get_error_code(), $error->get_error_message() . ' ' . sprintf( /* translators: %s: note of the outline */ __( 'Note on this element: %s', 'alphabridge-mcp' ), $element['note'] ) );
		}
		return $error;
	}

	/**
	 * A link by the protocol list.
	 *
	 * @param string $value Link.
	 * @param string $name  Field name.
	 * @return array|WP_Error
	 */
	private static function url( $value, $name ) {
		$value = trim( $value );
		if ( '' === $value ) {
			return array( 'value' => '' );
		}
		if ( 1 === preg_match( '~[\s"\'<>`\[\]\\\\]~', $value ) ) {
			return new WP_Error(
				'ab_mcp_builder_url',
				sprintf(
					/* translators: %s: field name */
					__( 'The link for %s contains characters a link must not carry here (white space, quotes, angle or square brackets, back-ticks or backslashes). Encode them, e.g. a space as %%20.', 'alphabridge-mcp' ),
					$name
				)
			);
		}
		if ( 1 !== preg_match( '~^(?:https?://|mailto:|tel:|/|#|\?)~i', $value ) ) {
			return new WP_Error(
				'ab_mcp_builder_url',
				sprintf(
					/* translators: %s: field name */
					__( 'The link for %s must start with http://, https://, mailto: or tel:, or be a relative link starting with /, # or ?. Other schemes (javascript:, data: and the like) are refused. Pass a link in one of these forms.', 'alphabridge-mcp' ),
					$name
				)
			);
		}
		return array( 'value' => $value );
	}

	/**
	 * An image by attachment id.
	 *
	 * @param mixed  $value Id.
	 * @param string $name  Field name.
	 * @return array|WP_Error
	 */
	private static function image( $value, $name ) {
		$id = ( is_int( $value ) || ( is_string( $value ) && ctype_digit( $value ) ) ) ? (int) $value : 0;
		if ( $id <= 0 ) {
			return new WP_Error(
				'ab_mcp_builder_image',
				sprintf(
					/* translators: %s: field name */
					__( 'The value for %s must be the id of an image in the media library (an integer). Upload an image with wp_upload_media to get one.', 'alphabridge-mcp' ),
					$name
				)
			);
		}
		$att = get_post( $id );
		if ( ! $att || 'attachment' !== $att->post_type || ! wp_attachment_is_image( $id ) ) {
			/* translators: %d: attachment id */
			return new WP_Error( 'ab_mcp_builder_image', sprintf( __( 'Attachment %d is not an image in the media library. wp_list_media lists the images; upload one with wp_upload_media.', 'alphabridge-mcp' ), $id ) );
		}
		if ( ! current_user_can( 'read_post', $id ) ) {
			/* translators: %d: attachment id */
			return new WP_Error( 'ab_mcp_builder_image', sprintf( __( 'Your account may not use image %d: it cannot read it. Pick an image your account may read (wp_list_media lists them), or ask someone who may read this one.', 'alphabridge-mcp' ), $id ) );
		}
		$url = wp_get_attachment_url( $id );
		return array(
			'value' => $id,
			'url'   => is_string( $url ) ? $url : '',
		);
	}

	/**
	 * The tags of a text, in order, by name: what a plain-text field must not
	 * gain.
	 *
	 * @param string $text Text.
	 * @return string[]
	 */
	private static function tags( $text ) {
		if ( ! preg_match_all( '~<\s*(/?)\s*([A-Za-z][A-Za-z0-9:-]*)~', (string) $text, $m, PREG_SET_ORDER ) ) {
			return array();
		}
		$out = array();
		foreach ( $m as $hit ) {
			$out[] = $hit[1] . strtolower( $hit[2] );
		}
		return $out;
	}

	/**
	 * Markup with what kses normalises taken out (entities, quotes, white
	 * space, slashes, case): two values with the same key differ only in how
	 * they are written, not in what they hold.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	private static function markup_key( $html ) {
		return strtolower( (string) preg_replace( '~[\s"\'/]+~', '', html_entity_decode( (string) $html, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
	}
}

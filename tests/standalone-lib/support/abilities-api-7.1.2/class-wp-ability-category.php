<?php
/*
 * Fixed copy for the test suite of AlphaBridge MCP only: wp-includes/abilities-api/class-wp-ability-category.php from WordPress 7.1.2
 * (wp-includes/version.php: $wp_version = '7.1.2'), taken from the release
 * package that WordPress Playground keeps, ~/.wordpress-playground/7.1.2.zip,
 * on 03.10.2026. Unchanged below this comment (the file's own first line,
 * "<?php", is the line above).
 *
 * WordPress is free software, licensed under the GNU General Public License,
 * version 2 or later (GPL-2.0-or-later), like this plugin. Copyright WordPress
 * contributors, https://wordpress.org/about/license/.
 *
 * Not shipped: tests/ is left out of every release (.distignore, and
 * export-ignore in .gitattributes), and the CI job checks the package for it.
 * Taken over from AlphaBridge MCP Pro's test suite with the abilities tests
 * (4.12.0).
 */
/**
 * Abilities API
 *
 * Defines WP_Ability_Category class.
 *
 * @package WordPress
 * @subpackage Abilities API
 * @since 6.9.0
 */

declare( strict_types = 1 );

/**
 * Encapsulates the properties and methods related to a specific ability category.
 *
 * @since 6.9.0
 *
 * @see WP_Ability_Categories_Registry
 */
final class WP_Ability_Category {

	/**
	 * The unique slug for the ability category.
	 *
	 * @since 6.9.0
	 * @var string
	 */
	protected $slug;

	/**
	 * The human-readable ability category label.
	 *
	 * @since 6.9.0
	 * @var string
	 */
	protected $label;

	/**
	 * The detailed ability category description.
	 *
	 * @since 6.9.0
	 * @var string
	 */
	protected $description;

	/**
	 * The optional ability category metadata.
	 *
	 * @since 6.9.0
	 * @var array<string, mixed>
	 */
	protected $meta = array();

	/**
	 * Constructor.
	 *
	 * Do not use this constructor directly. Instead, use the `wp_register_ability_category()` function.
	 *
	 * @access private
	 *
	 * @since 6.9.0
	 *
	 * @see wp_register_ability_category()
	 *
	 * @param string               $slug The unique slug for the ability category.
	 * @param array<string, mixed> $args {
	 *     An associative array of arguments for the ability category.
	 *
	 *     @type string               $label       The human-readable label for the ability category.
	 *     @type string               $description A description of the ability category.
	 *     @type array<string, mixed> $meta        Optional. Additional metadata for the ability category.
	 * }
	 * @throws InvalidArgumentException If an argument is invalid.
	 */
	public function __construct( string $slug, array $args ) {
		if ( empty( $slug ) ) {
			throw new InvalidArgumentException(
				__( 'The ability category slug cannot be empty.' )
			);
		}

		$this->slug = $slug;

		$properties = $this->prepare_properties( $args );

		foreach ( $properties as $property_name => $property_value ) {
			if ( ! property_exists( $this, $property_name ) ) {
				_doing_it_wrong(
					__METHOD__,
					sprintf(
						/* translators: %s: Property name. */
						__( 'Property "%1$s" is not a valid property for ability category "%2$s". Please check the %3$s class for allowed properties.' ),
						'<code>' . esc_html( $property_name ) . '</code>',
						'<code>' . esc_html( $this->slug ) . '</code>',
						'<code>' . __CLASS__ . '</code>'
					),
					'6.9.0'
				);
				continue;
			}

			$this->$property_name = $property_value;
		}
	}

	/**
	 * Prepares and validates the properties used to instantiate the ability category.
	 *
	 * @since 6.9.0
	 *
	 * @param array<string, mixed> $args {
	 *     An associative array of arguments used to instantiate the ability category class.
	 *
	 *     @type string               $label       The human-readable label for the ability category.
	 *     @type string               $description A description of the ability category.
	 *     @type array<string, mixed> $meta        Optional. Additional metadata for the ability category.
	 * }
	 * @return array<string, mixed> $args {
	 *     An associative array with validated and prepared ability category properties.
	 *
	 *     @type string               $label       The human-readable label for the ability category.
	 *     @type string               $description A description of the ability category.
	 *     @type array<string, mixed> $meta        Optional. Additional metadata for the ability category.
	 * }
	 * @throws InvalidArgumentException If an argument is invalid.
	 */
	protected function prepare_properties( array $args ): array {
		// Required args must be present and of the correct type.
		if ( empty( $args['label'] ) || ! is_string( $args['label'] ) ) {
			throw new InvalidArgumentException(
				__( 'The ability category properties must contain a `label` string.' )
			);
		}

		if ( empty( $args['description'] ) || ! is_string( $args['description'] ) ) {
			throw new InvalidArgumentException(
				__( 'The ability category properties must contain a `description` string.' )
			);
		}

		// Optional args only need to be of the correct type if they are present.
		if ( isset( $args['meta'] ) && ! is_array( $args['meta'] ) ) {
			throw new InvalidArgumentException(
				__( 'The ability category properties should provide a valid `meta` array.' )
			);
		}

		return $args;
	}

	/**
	 * Retrieves the slug of the ability category.
	 *
	 * @since 6.9.0
	 *
	 * @return string The ability category slug.
	 */
	public function get_slug(): string {
		return $this->slug;
	}

	/**
	 * Retrieves the human-readable label for the ability category.
	 *
	 * @since 6.9.0
	 *
	 * @return string The human-readable ability category label.
	 */
	public function get_label(): string {
		return $this->label;
	}

	/**
	 * Retrieves the detailed description for the ability category.
	 *
	 * @since 6.9.0
	 *
	 * @return string The detailed description for the ability category.
	 */
	public function get_description(): string {
		return $this->description;
	}

	/**
	 * Retrieves the metadata for the ability category.
	 *
	 * @since 6.9.0
	 *
	 * @return array<string,mixed> The metadata for the ability category.
	 */
	public function get_meta(): array {
		return $this->meta;
	}

	/**
	 * Wakeup magic method.
	 *
	 * @since 6.9.0
	 * @throws LogicException If the ability category object is unserialized.
	 *                        This is a security hardening measure to prevent unserialization of the ability category.
	 */
	public function __wakeup(): void {
		throw new LogicException( __CLASS__ . ' should never be unserialized.' );
	}

	/**
	 * Sleep magic method.
	 *
	 * @since 6.9.0
	 * @throws LogicException If the ability category object is serialized.
	 *                        This is a security hardening measure to prevent serialization of the ability category.
	 */
	public function __sleep(): array {
		throw new LogicException( __CLASS__ . ' should never be serialized.' );
	}
}

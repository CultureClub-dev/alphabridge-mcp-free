<?php
/**
 * What the account that makes a package may read of each object (Z8,
 * decision 9 of the plan of 01.11.2026): an object it may not read is not
 * in the package; AB_MCP_Sync_Export counts and names it, and its kind and
 * subtype are not complete there.
 *
 * - Content (the kind post): read_post; where it is not publicly viewable
 *   (is_post_publicly_viewable(): a status or a type that is not public),
 *   edit_post as well.
 * - Terms: those of a viewable taxonomy (is_taxonomy_viewable()) as anyone
 *   reads them on the site; the others with edit_term.
 * - Menus, their items, widget areas, theme mods and the additional CSS:
 *   edit_theme_options.
 * - Options: manage_options, which every export asks first
 *   (AB_MCP_Sync_Export::refusal()); so does this class only ever answer
 *   for an account that has it.
 * - A post of a type or a term of a taxonomy WordPress does not know (the
 *   plugin that registered it is off): WordPress has no rule of its own for
 *   it (map_meta_cap() reports a post as doing it wrong, a term as
 *   do_not_allow), so it is read with manage_options, as the options are.
 *   Such objects are locked for the takeover anyway; the inventory lists
 *   them.
 * - An object that is not there any more, or of a kind this class has no
 *   rule for, is withheld (fail closed).
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class AB_MCP_Sync_Export_Rights
 */
final class AB_MCP_Sync_Export_Rights {

	/** The kinds of the theme: menus and their items, widget areas, theme mods, the additional CSS. */
	const THEME = array( 'menu', 'menu_item', 'widget_area', 'theme_mod', 'custom_css' );

	/**
	 * Why the current account, which has manage_options, may not have an
	 * object in a package, or null where it may: the capability it lacks
	 * (read_post, edit_post, edit_term, edit_theme_options), gone (the
	 * object is not there) or kind (a kind without a rule here).
	 *
	 * @param array $object An object of the inventory (kind, ref, identity).
	 * @return string|null
	 */
	public static function withheld( array $object ) {
		$kind = isset( $object['kind'] ) ? (string) $object['kind'] : '';
		$ref  = isset( $object['ref'] ) ? (string) $object['ref'] : '';
		if ( 'post' === $kind ) {
			return self::post( $ref );
		}
		if ( 'term' === $kind ) {
			return self::term( $ref, isset( $object['identity']['taxonomy'] ) ? (string) $object['identity']['taxonomy'] : '' );
		}
		if ( in_array( $kind, self::THEME, true ) ) {
			return current_user_can( 'edit_theme_options' ) ? null : 'edit_theme_options';
		}
		return 'option' === $kind ? null : 'kind';
	}

	/**
	 * @param string $ref The id of the post.
	 * @return string|null
	 */
	private static function post( $ref ) {
		$post = 1 === preg_match( '/^[1-9][0-9]*\z/', $ref ) ? get_post( (int) $ref ) : null;
		if ( ! $post instanceof WP_Post ) {
			return 'gone';
		}
		if ( null === get_post_type_object( $post->post_type ) ) {
			return null;
		}
		if ( ! current_user_can( 'read_post', $post->ID ) ) {
			return 'read_post';
		}
		if ( ! is_post_publicly_viewable( $post ) && ! current_user_can( 'edit_post', $post->ID ) ) {
			return 'edit_post';
		}
		return null;
	}

	/**
	 * @param string $ref      The id of the term.
	 * @param string $taxonomy Its taxonomy, as the inventory read it.
	 * @return string|null
	 */
	private static function term( $ref, $taxonomy ) {
		if ( '' === $taxonomy || 1 !== preg_match( '/^[1-9][0-9]*\z/', $ref ) ) {
			return 'gone';
		}
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return null;
		}
		$term = get_term( (int) $ref, $taxonomy );
		if ( ! $term instanceof WP_Term ) {
			return 'gone';
		}
		if ( ! is_taxonomy_viewable( $taxonomy ) && ! current_user_can( 'edit_term', $term->term_id ) ) {
			return 'edit_term';
		}
		return null;
	}
}

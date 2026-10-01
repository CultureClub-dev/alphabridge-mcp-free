<?php
/**
 * Shortcode profile: Divi 4 (storage A: the page is the [et_pb_…] shortcodes
 * in post_content; the same builder runs in the Extra theme and the Divi
 * Builder plugin).
 *
 * NOT MEASURED. Every entry comes from the vendor documentation collected in
 * alphabridge-docs: docs/recherche/2026-09-30-page-builder-messung/quellen/
 * divi-4.md (Divi 4.27.9). "[n]" cites source n of that file. Where the file
 * calls a name derived or open, the comment says so: a wrong tag name only
 * means that element is read like any other element of the family.
 *
 * Divi 5 pages (blocks) are recognised separately (signatures.php, divi5).
 *
 * Format: see includes/builders/adapters/class-adapter-shortcodes.php.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

return array(
	'id'           => 'divi4',
	'builder'      => 'divi4',
	'verified'     => false,
	'source'       => 'quellen/divi-4.md (Divi 4.27.9, vendor documentation, read 30.09.2026)',
	// Attribute values: %22 %91 %93 %92 for " [ ] \ (d) [15]); Dynamic Content is never shown as text.
	'codec'        => 'divi4',
	'id_attr'      => '', // No documented element id: path ids.
	'family'       => array( 'et_pb_*' ), // a) [3][11]: every module is an et_pb_ shortcode.
	// Global modules: the synced fields come from the Divi Library, not from this copy (b) [20][21]).
	// The linking attribute is prior knowledge in the source ("Vorwissen: global_module"), open.
	'global_attrs' => array(
		'global_module' => 'global module: its synced fields come from Divi Library item %s, not from this page',
	),
	'tags'         => array(

		// Structure: a) [3].
		'et_pb_section'         => array( 'container' => true ),
		'et_pb_row'             => array( 'container' => true ),
		'et_pb_column'          => array( 'container' => true ),

		// Text module: HTML between the tags (c) [4]).
		'et_pb_text'            => array(
			'fields' => array(
				'text' => array( 'kind' => 'html', 'from' => 'content' ),
			),
		),

		// Call to action: title and button_text as attributes, text between the tags (a), c) [3]);
		// the link attribute button_url is derived there.
		'et_pb_cta'             => array(
			'fields' => array(
				'title'       => array( 'kind' => 'heading', 'from' => 'attr', 'attr' => 'title' ),
				'text'        => array( 'kind' => 'html', 'from' => 'content' ),
				'button_text' => array( 'kind' => 'text', 'from' => 'attr', 'attr' => 'button_text' ),
				'button_url'  => array( 'kind' => 'url', 'from' => 'attr', 'attr' => 'button_url' ),
			),
		),

		// Button: button_text, button_url (c) [10][11]).
		'et_pb_button'          => array(
			'fields' => array(
				'text' => array( 'kind' => 'text', 'from' => 'attr', 'attr' => 'button_text' ),
				'url'  => array( 'kind' => 'url', 'from' => 'attr', 'attr' => 'button_url' ),
			),
		),

		// Image: src, alt, title_text derived from the vendor's conversion tutorial (c) [14]); the
		// tag name et_pb_image and its link attributes are open in the source, so no link is read.
		// The image is referenced by address, not by attachment id.
		'et_pb_image'           => array(
			'fields' => array(
				'image' => array( 'kind' => 'image', 'from' => 'attr', 'attr' => 'src' ),
				'alt'   => array( 'kind' => 'text', 'from' => 'attr', 'attr' => 'alt' ),
				'title' => array( 'kind' => 'text', 'from' => 'attr', 'attr' => 'title_text' ),
			),
		),

		// Locked (g)): code modules — HTML, CSS and JavaScript, URL-encoded ([16][34]; the fullwidth
		// name is derived from the naming pattern and [35]) …
		'et_pb_code'            => array( 'locked' => 'code element' ),
		'et_pb_fullwidth_code'  => array( 'locked' => 'code element' ),
		// … forms: recipient, redirects, accounts of mail services ([11][12][13]).
		'et_pb_contact_form'    => array( 'locked' => 'form' ),
		'et_pb_contact_field'   => array( 'locked' => 'form' ),
		'et_pb_signup'          => array( 'locked' => 'form' ),
		'et_pb_login'           => array( 'locked' => 'form' ),
	),
);

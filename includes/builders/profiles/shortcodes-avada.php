<?php
/**
 * Shortcode profile: Avada (Avada Builder, formerly Fusion Builder; storage
 * A: the page is the [fusion_…] shortcodes in post_content).
 *
 * NOT MEASURED. Every entry comes from the vendor documentation and the
 * third-party plugins it names, collected in alphabridge-docs: docs/recherche/
 * 2026-09-30-page-builder-messung/quellen/avada.md (Avada 7.16.2 / Avada
 * Builder 3.16.2). "[n]" cites source n of that file; "Fremd" marks a
 * third-party plugin. Where a tag name is only derived from a vendor file
 * name, the comment says so; such names are used to LOCK elements only — a
 * wrong one locks nothing, and the element is read like any other of the
 * family, without its attributes.
 *
 * Avada keeps much visible text outside the page (layout sections, global
 * elements, page options, off-canvas, b)); this profile reads the page.
 *
 * Format: see includes/builders/adapters/class-adapter-shortcodes.php.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

return array(
	'id'          => 'avada',
	'builder'     => 'avada',
	'verified'    => false,
	'source'      => 'quellen/avada.md (Avada 7.16.2, vendor documentation and third-party plugins, read 30.09.2026)',
	// Attribute values are stored as typed; "{ }" for "[ ]" is documented only for the button's
	// "Additional Attributes" field ([19]), which is never read (see class-shortcode-codecs.php).
	'codec'       => '',
	'id_attr'     => '',
	'family'      => array(
		'fusion_*', // a) [2] 5.0: «All Avada elements are now prefixed with fusion-».
		'awb_*',    // a) Fremd [42] «the awb_* family»; vendor files shortcodes/awb-*.php [8].
	),
	// Inline Dynamic Data: {post_title}, {post_terms,type:category,separator:|} — filled in on page load (d) [17]).
	'placeholder' => '~\{[a-z]+_[a-z_]+(?:,[^{}\[\]]*)?\}~',
	'tags'        => array(

		// Structure: Fremd [42] (checked by its author against Avada 7.16.1); vendor files [8].
		'fusion_builder_container'    => array( 'container' => true ),
		'fusion_builder_row'          => array( 'container' => true ),
		'fusion_builder_column'       => array( 'container' => true ),
		'fusion_builder_row_inner'    => array( 'container' => true ),
		'fusion_builder_column_inner' => array( 'container' => true ),

		// Title: the text between the tags (c), derived from [9][20]; attribute names of the special title types open).
		'fusion_title'                => array(
			'fields' => array(
				'text' => array( 'kind' => 'heading', 'from' => 'content' ),
			),
		),

		// Text Block: HTML between the tags (c) Fremd [42], derived from element_content [9]).
		'fusion_text'                 => array(
			'fields' => array(
				'text' => array( 'kind' => 'html', 'from' => 'content' ),
			),
		),

		// Button: link in "link" (c) [9]); the text between the tags is derived (c) [19], open).
		'fusion_button'               => array(
			'fields' => array(
				'text' => array( 'kind' => 'text', 'from' => 'content' ),
				'url'  => array( 'kind' => 'url', 'from' => 'attr', 'attr' => 'link' ),
			),
		),

		// Image frame: tag name Fremd [39]; where the image and its link are stored is open (c) [21]).
		'fusion_imageframe'           => array( 'note' => 'image element: where it keeps the image is not documented yet, so no field is read' ),

		// Locked (g)): the Code Block, base64 HTML/CSS/JS (tag name from changelog 7.15.3 [2], [6]) …
		'fusion_code'                 => array( 'locked' => 'code element' ),
		// … tag names derived from the vendor files in [8] (not confirmed): syntax highlighter, widgets,
		// forms, payment and login, WooCommerce shortcodes …
		'fusion_syntax_highlighter'   => array( 'locked' => 'code element' ),
		'fusion_widget'               => array( 'locked' => 'widget' ),
		'fusion_widget_area'          => array( 'locked' => 'widget' ),
		'fusion_form'                 => array( 'locked' => 'form' ),
		'fusion_stripe_button'        => array( 'locked' => 'payment' ),
		'fusion_user_login'           => array( 'locked' => 'form' ),
		'fusion_woo_shortcodes'       => array( 'locked' => 'shortcode' ),
		// … and a global element, whose text lives in the Avada Library (b) 3. [10]; tag name a hypothesis there).
		'fusion_global'               => array(
			'locked'   => 'global element',
			'global'   => true,
			'ref_attr' => 'id',
			'ref_note' => 'its content is Avada Library item %s',
		),
	),
);

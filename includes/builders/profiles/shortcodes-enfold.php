<?php
/**
 * Shortcode profile: Enfold (Avia Layout Builder; storage B: the page is
 * [av_…] shortcodes in the meta _aviaLayoutBuilderCleanData, post_content is
 * only a copy that is not kept in step).
 *
 * NOT MEASURED at an installation. Every entry comes from the vendor's
 * documentation and theme files the vendor publishes, collected in
 * alphabridge-docs: docs/recherche/2026-09-30-page-builder-messung/quellen/
 * enfold.md (Enfold 8.1, plus a comparison at the vendor's documentation
 * site, b) 2.). "[n]" cites source n of that file.
 *
 * Attributes are single-quoted; the builder turns an apostrophe in an
 * attribute into "’" (d) [4]), which is shown as stored (see
 * class-shortcode-codecs.php). Every element carries a unique av_uid, which
 * is its element id here (c) [4] l. 2206, [9] l. 1407 ff.); a copied element
 * gets a new one when the page is saved.
 *
 * Format: see includes/builders/adapters/class-adapter-shortcodes.php.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

return array(
	'id'           => 'enfold',
	'builder'      => 'enfold',
	'verified'     => false,
	'source'       => 'quellen/enfold.md (Enfold 8.1, vendor documentation and vendor theme files, read 30.09.2026)',
	// The page: a) [2][4] «field that stores all our content»; the builder reads it first (b) 3. [4] l. 1786).
	'layout_meta'  => '_aviaLayoutBuilderCleanData',
	'codec'        => '',
	'id_attr'      => 'av_uid',
	'family'       => array( 'av_*' ), // a), c) [4]: [av_…] shortcodes.
	// Custom Element Templates: locked values replace the page's on page load (b) [12] [18] l. 655–658).
	// The linking attribute is open; select_element_template is the candidate from the vendor's WPML code ([11]).
	'global_attrs' => array(
		'select_element_template' => 'uses Custom Element Template %s: its locked values replace these when the page is shown',
	),
	// Dynamic content: {wp_post_title}, {wp_custom_field:…}, {av_dynamic_el src="…" key="…"} (d) [16][17]).
	'placeholder'  => '~\{(?:wp_[a-z_]+(?::[^{}]*)?|av_dynamic_el\b[^{}]*)\}~',
	'tags'         => array(

		// Structure: sections and columns (c) [4] l. 1627–1661, [23]).
		'av_section'      => array( 'container' => true ),
		'av_one_full'     => array( 'container' => true ),
		'av_one_half'     => array( 'container' => true ),
		'av_one_third'    => array( 'container' => true ),

		// Special heading: text in "heading", subheading between the tags, link in "link" (c) [18] l. 175–240, [19]).
		'av_heading'      => array(
			'fields' => array(
				'heading'    => array( 'kind' => 'heading', 'from' => 'attr', 'attr' => 'heading' ),
				'subheading' => array( 'kind' => 'html', 'from' => 'content' ),
				'url'        => array( 'kind' => 'url', 'from' => 'attr', 'attr' => 'link', 'format' => 'enfold_link' ),
			),
		),

		// Text block: HTML between the tags (c) [4][22]).
		'av_textblock'    => array(
			'fields' => array(
				'text' => array( 'kind' => 'html', 'from' => 'content' ),
			),
		),

		// Button, no closing tag: label, link (c) [20], link formats [17]).
		'av_button'       => array(
			'fields' => array(
				'text' => array( 'kind' => 'text', 'from' => 'attr', 'attr' => 'label' ),
				'url'  => array( 'kind' => 'url', 'from' => 'attr', 'attr' => 'link', 'format' => 'enfold_link' ),
			),
		),

		// Image: attachment id wins over the address in src when shown (c) [21][23] l. 911–948, for
		// av_image derived); link as for buttons (derived).
		'av_image'        => array(
			'fields' => array(
				'image' => array( 'kind' => 'image', 'from' => 'attr', 'attr' => 'attachment' ),
				'src'   => array( 'kind' => 'url', 'from' => 'attr', 'attr' => 'src' ),
				'link'  => array( 'kind' => 'url', 'from' => 'attr', 'attr' => 'link', 'format' => 'enfold_link' ),
			),
		),

		// Locked (g)): the code block runs HTML/CSS/JS/PHP ([25][28]) …
		'av_codeblock'        => array( 'locked' => 'code element' ),
		// … forms: recipient, auto-responder sender, redirect; newsletter list ([26][27][28][51]) …
		'av_contact'          => array( 'locked' => 'form' ),
		'av_contact_field'    => array( 'locked' => 'form' ),
		'av_mailchimp'        => array( 'locked' => 'form' ),
		'av_mailchimp_field'  => array( 'locked' => 'form' ),
		// … maps and embedded media from other services ([28]).
		'av_google_map'       => array( 'locked' => 'embed code' ),
		'av_gmap_location'    => array( 'locked' => 'embed code' ),
		'av_video'            => array( 'locked' => 'embed code' ),
	),
);

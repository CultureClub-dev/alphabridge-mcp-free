<?php
/**
 * Shortcode profile: WPBakery Page Builder (storage A: the page is the
 * [vc_…] shortcodes in post_content).
 *
 * NOT MEASURED. Every entry comes from the vendor documentation and the
 * third-party plugins it names, collected in the measurement folder of the
 * page-builder plan: alphabridge-docs: docs/recherche/2026-09-30-page-builder-
 * messung/quellen/wpbakery.md (WPBakery 9.0.1). "[n]" cites source n of that
 * file; "Fremd" marks a third-party plugin, not the vendor. The answer says
 * the profile was not measured (verified false) until a real installation
 * confirms it.
 *
 * Themes that ship WPBakery: Salient (its own WPBakery build), The7, Uncode
 * and Impreza write the same [vc_row][vc_column]… shortcodes (quellen/
 * salient-the7.md a); verbreitung.md 1b for Uncode and Impreza), so this
 * profile reads their pages too. Their own elements — Salient's nectar_…,
 * The7's dt_…, Ultimate Addons' ult_… — have attribute formats nobody
 * documents (salient-the7.md c)); they are read as elements of the family
 * (type and visible text, never an attribute) with the note that no profile
 * covers them. Salient does not register vc_btn, vc_cta, vc_message,
 * vc_hoverbox, vc_empty_space and vc_section unless a filter turns them back
 * on (salient-the7.md c) [7]); on such a site those tags show as text.
 *
 * Format: see includes/builders/adapters/class-adapter-shortcodes.php.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

return array(
	'id'       => 'wpbakery',
	'builder'  => 'wpbakery',
	'verified' => false,
	'source'   => 'quellen/wpbakery.md (WPBakery 9.0.1, vendor documentation and third-party plugins, read 30.09.2026); quellen/salient-the7.md',
	// Attribute values: "``" for a double quote, "`{`" / "`}`" for brackets (d) [42]).
	'codec'    => 'wpbakery',
	'id_attr'  => '', // No element id in the shortcodes: path ids.
	'family'   => array(
		'vc_*', // a) [3][6][23]: every WPBakery element is a vc_ shortcode.
		// Theme elements on WPBakery pages (salient-the7.md c)): Salient (Fremd [38]) …
		'nectar*', 'fancy_box', 'image_with_animation', 'divider_line', 'milestone', 'testimonial_slider', 'recent_posts', 'split_line_heading',
		'dt_*', // … The7 (belegt [26]) …
		'ult_*', 'ultimate_*', 'bsf-*', 'info_list', 'stat_counter', 'just_icon', // … and Ultimate Addons (Fremd [38]).
	),
	'tags'     => array(

		// Structure: a) [23] (section, row, column, inner row and column); 9.0 containers Fremd [46].
		'vc_section'                => array( 'container' => true ),
		'vc_row'                    => array( 'container' => true ),
		'vc_row_inner'              => array( 'container' => true ),
		'vc_column'                 => array( 'container' => true ),
		'vc_column_inner'           => array( 'container' => true ),
		'vc_flexbox_container'      => array( 'container' => true ),
		'vc_flexbox_container_item' => array( 'container' => true ),
		'vc_grid_container'         => array( 'container' => true ),
		'vc_grid_container_item'    => array( 'container' => true ),

		// Text Block: HTML between the tags, param "content" (c) [19][23]).
		'vc_column_text'            => array(
			'fields' => array(
				'text' => array( 'kind' => 'html', 'from' => 'content' ),
			),
		),

		// Custom Heading: visible text in "text", link in "link" (link format) (c) [23][42][45][47]).
		// source="post_title" takes the text from the post title instead ([45]).
		'vc_custom_heading'         => array(
			'fields' => array(
				'text' => array(
					'kind'   => 'heading',
					'from'   => 'attr',
					'attr'   => 'text',
					'unless' => array(
						'attr'   => 'source',
						'equals' => 'post_title',
						'note'   => 'the text comes from the post title',
					),
				),
				'url'  => array( 'kind' => 'url', 'from' => 'attr', 'attr' => 'link', 'format' => 'vc_link' ),
			),
		),

		// Button: text in "title", target in "link" (link format) (c) [23][45][48]);
		// click code in custom_onclick_code, never read (g) [45]).
		'vc_btn'                    => array(
			'code_attrs' => array( 'custom_onclick_code' ),
			'fields'     => array(
				'text' => array( 'kind' => 'text', 'from' => 'attr', 'attr' => 'title' ),
				'url'  => array( 'kind' => 'url', 'from' => 'attr', 'attr' => 'link', 'format' => 'vc_link' ),
			),
		),

		// Single Image: attachment id in "image" ([59]); click target "link" as a plain address; widget
		// title "title"; caption "caption"; external image address "custom_src" (c) [23], names Fremd [45][47]).
		'vc_single_image'           => array(
			'fields' => array(
				'image'   => array( 'kind' => 'image', 'from' => 'attr', 'attr' => 'image' ),
				'src'     => array( 'kind' => 'image', 'from' => 'attr', 'attr' => 'custom_src' ),
				'title'   => array( 'kind' => 'heading', 'from' => 'attr', 'attr' => 'title' ),
				'caption' => array( 'kind' => 'text', 'from' => 'attr', 'attr' => 'caption' ),
				'link'    => array( 'kind' => 'url', 'from' => 'attr', 'attr' => 'link' ),
			),
		),

		// Call to action and pricing table: heading h2, subheading h4, text between the tags, button
		// btn_title / btn_link; click code btn_custom_onclick_code, never read (c), g) Fremd [44][45]; [23]).
		'vc_cta'                    => array(
			'code_attrs' => array( 'btn_custom_onclick_code' ),
			'fields'     => array(
				'heading'     => array( 'kind' => 'heading', 'from' => 'attr', 'attr' => 'h2' ),
				'subheading'  => array( 'kind' => 'heading', 'from' => 'attr', 'attr' => 'h4' ),
				'text'        => array( 'kind' => 'html', 'from' => 'content' ),
				'button_text' => array( 'kind' => 'text', 'from' => 'attr', 'attr' => 'btn_title' ),
				'button_url'  => array( 'kind' => 'url', 'from' => 'attr', 'attr' => 'btn_link', 'format' => 'vc_link' ),
			),
		),
		'vc_pricing_table'          => array(
			'code_attrs' => array( 'btn_custom_onclick_code' ),
			'fields'     => array(
				'text'        => array( 'kind' => 'html', 'from' => 'content' ),
				'button_text' => array( 'kind' => 'text', 'from' => 'attr', 'attr' => 'btn_title' ),
				'button_url'  => array( 'kind' => 'url', 'from' => 'attr', 'attr' => 'btn_link', 'format' => 'vc_link' ),
			),
		),

		// Further elements with editor content between the tags (c) Fremd [44], list RAW_CONTENT_TAGS).
		'vc_message'                => array( 'fields' => array( 'text' => array( 'kind' => 'html', 'from' => 'content' ) ) ),
		'vc_toggle'                 => array( 'fields' => array( 'text' => array( 'kind' => 'html', 'from' => 'content' ) ) ),
		'vc_hoverbox'               => array( 'fields' => array( 'text' => array( 'kind' => 'html', 'from' => 'content' ) ) ),
		'vc_wp_text'                => array( 'fields' => array( 'text' => array( 'kind' => 'html', 'from' => 'content' ) ) ),

		// Locked (g)): raw code, base64 ([16][17][18][23]) …
		'vc_raw_html'               => array( 'locked' => 'code element' ),
		'vc_raw_js'                 => array( 'locked' => 'code element' ),
		// … map embeds: iframe code in a "#E-8_" field ([18][23][47]); the 8.3 map element (Fremd [46]) …
		'vc_gmaps'                  => array( 'locked' => 'embed code' ),
		'vc_goo_maps'               => array( 'locked' => 'embed code' ),
		// … grids: settings mirrored in _vc_post_settings, content from a query ([12][13][39]) …
		'vc_basic_grid'             => array( 'locked' => 'post grid' ),
		'vc_masonry_grid'           => array( 'locked' => 'post grid' ),
		'vc_media_grid'             => array( 'locked' => 'post grid' ),
		'vc_masonry_media_grid'     => array( 'locked' => 'post grid' ),
		// … block markup inside the page ([44]) …
		'vc_gutenberg'              => array( 'locked' => 'block markup' ),
		// … and The7's code and login elements (salient-the7.md g) [20][26]; dt_code locked as a precaution there).
		'dt_code'                   => array( 'locked' => 'code element' ),
		'dt_simple_login_form'      => array( 'locked' => 'form' ),
	),
);

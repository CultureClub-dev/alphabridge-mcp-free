<?php
/**
 * Shortcode profile: Flatsome (UX Builder; storage A: the page is readable
 * shortcodes, mixed with HTML, in post_content).
 *
 * NOT MEASURED. Every entry comes from the vendor documentation and the
 * third-party plugins it names, collected in alphabridge-docs: docs/recherche/
 * 2026-09-30-page-builder-messung/quellen/flatsome.md (Flatsome 3.20.11,
 * derived from the vendor demo). "[n]" cites source n of that file; "Fremd"
 * marks a third-party plugin.
 *
 * Flatsome uses plain tag names (section, row, col, title, button …). They
 * count as Flatsome's only on a page recognised as built with Flatsome.
 *
 * Format: see includes/builders/adapters/class-adapter-shortcodes.php.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

return array(
	'id'       => 'flatsome',
	'builder'  => 'flatsome',
	'verified' => false,
	'source'   => 'quellen/flatsome.md (Flatsome 3.20.11, vendor documentation and third-party plugins, read 30.09.2026)',
	// Attributes are plain text in every vendor example (d) [4][5][6][16]); how ", & and ] are escaped is open.
	'codec'    => '',
	'id_attr'  => '', // Element ids are made at render time (b) [34][47]): path ids.
	'family'   => array( 'ux_*' ), // a) [4][46].
	'tags'     => array(

		// Structure: [section] → [row] → [col], inside [row_inner] / [col_inner] (c) [4], section Fremd [46]).
		// A section's "label" is only a name in the builder (Fremd [46]) and is not read.
		'section'        => array(
			'fields' => array(
				'image' => array( 'kind' => 'image', 'from' => 'attr', 'attr' => 'bg' ), // Image field (Fremd [50]).
			),
		),
		'row'            => array( 'container' => true ),
		'row_inner'      => array( 'container' => true ),
		// Text is HTML between the tags of a column (c) [5][15], Fremd [46][49]).
		'col'            => array(
			'fields' => array(
				'text' => array( 'kind' => 'html', 'from' => 'content' ),
			),
		),
		'col_inner'      => array(
			'fields' => array(
				'text' => array( 'kind' => 'html', 'from' => 'content' ),
			),
		),

		// Title: visible text in "text" (c) [6]).
		'title'          => array(
			'fields' => array(
				'text' => array( 'kind' => 'heading', 'from' => 'attr', 'attr' => 'text' ),
			),
		),

		// Button: text in "text", target in "link" (c) [5][6]).
		'button'         => array(
			'fields' => array(
				'text' => array( 'kind' => 'text', 'from' => 'attr', 'attr' => 'text' ),
				'url'  => array( 'kind' => 'url', 'from' => 'attr', 'attr' => 'link' ),
			),
		),

		// Banner, lightbox, map: HTML between the tags (c) [5][15], map Fremd [49]); the banner's
		// background image in "bg" ([5]). Lightbox auto_* attributes and map coordinates are not read.
		'ux_banner'      => array(
			'fields' => array(
				'text'  => array( 'kind' => 'html', 'from' => 'content' ),
				'image' => array( 'kind' => 'image', 'from' => 'attr', 'attr' => 'bg' ),
			),
		),
		'lightbox'       => array(
			'fields' => array(
				'text' => array( 'kind' => 'html', 'from' => 'content' ),
			),
		),
		'map'            => array(
			'fields' => array(
				'text' => array( 'kind' => 'html', 'from' => 'content' ),
			),
		),

		// Accordion item: title attribute and content; follow icons: link attributes (c) [16]).
		'accordion-item' => array(
			'fields' => array(
				'title' => array( 'kind' => 'heading', 'from' => 'attr', 'attr' => 'title' ),
				'text'  => array( 'kind' => 'html', 'from' => 'content' ),
			),
		),
		'follow'         => array(
			'fields' => array(
				'facebook' => array( 'kind' => 'url', 'from' => 'attr', 'attr' => 'facebook' ),
			),
		),

		// Image and gallery: element names Fremd [46][52]; their attributes are open (c)).
		'ux_image'       => array( 'note' => 'image element: its attributes are not documented yet, so no field is read' ),
		'ux_gallery'     => array( 'note' => 'gallery: its attributes are not documented yet, so no field is read' ),

		// UX Block: its text is the content of the block post, shown wherever the block is placed (b) [6][7][8]).
		'block'          => array(
			'locked'   => 'global element',
			'global'   => true,
			'ref_attr' => 'id',
			'ref_note' => 'its content is UX Block %s',
		),

		// Locked (g)): the HTML element (raw HTML/JS; its name is open in the source) and form shortcodes ([5][16]).
		'ux_html'        => array( 'locked' => 'html element' ),
		'ninja_forms_display_form' => array( 'locked' => 'form' ),
	),
);

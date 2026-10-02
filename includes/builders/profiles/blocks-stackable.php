<?php
/**
 * Block profile: Stackable (plugin stackable-ultimate-gutenberg-blocks).
 *
 * Measured ("verified") in the plan's measurement with Stackable 3.20.2 on
 * WordPress 7.1.2 (docs/recherche/2026-09-30-page-builder-messung/bloecke/
 * README.md, section "Stackable 3.20.2"; raw data out/ergebnis-stackable.json),
 * on the plugin's wordpress.org preview page and a design-library template:
 * - heading, text, button text and link: only in the markup; the blocks are
 *   static, the page shows the markup;
 * - image: src in the markup, a copy of the address in imageUrl and the id in
 *   imageId (comment) and as the class wp-image-N. Changing only imageUrl is
 *   invisible.
 * Stackable keeps its generated CSS as <style> inside the saved markup and
 * custom CSS in customCSS (plus <style class="stk-custom-css">): the reader
 * never reads either (style elements are dropped, CSS paths refused).
 *
 * Format: see includes/builders/class-block-reader.php.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

return array(
	'id'         => 'stackable',
	'builder'    => 'stackable',
	'verified'   => true,
	'source'     => 'bloecke/README.md Stackable (3.20.2, WordPress 7.1.2), out/ergebnis-stackable.json',
	'namespaces' => array( 'stackable/' ),
	'id_attr'    => 'uniqueId', // Every Stackable block of the measured samples carries uniqueId.
	'blocks'     => array(

		// stk.js:2: text from the markup. Measured: h2.stk-block-heading__text.
		'stackable/heading'      => array(
			'fields' => array(
				'text' => array( 'kind' => 'heading', 'from' => 'html_text', 'selector' => '.stk-block-heading__text' ),
			),
		),

		// Measured: p.stk-block-text__text, only in the markup.
		'stackable/text'         => array(
			'fields' => array(
				'text' => array( 'kind' => 'html', 'from' => 'html_inner', 'selector' => '.stk-block-text__text' ),
			),
		),

		// stk.js:2: url from href. Measured: a.stk-link with span.stk-button__inner-text,
		// both only in the markup (an empty href stands as "").
		'stackable/button'       => array(
			'fields' => array(
				'text' => array( 'kind' => 'text', 'from' => 'html_text', 'selector' => '.stk-button__inner-text' ),
				'url'  => array( 'kind' => 'url', 'from' => 'html_attr', 'selector' => 'a', 'attr' => 'href' ),
			),
		),

		// Measured: src in the markup with the copy imageUrl, the id in imageId. The measured
		// image has no alt attribute; where one is set it is read from the img (not measured).
		'stackable/image'        => array(
			'fields' => array(
				'image' => array( 'kind' => 'image', 'from' => 'attr', 'path' => 'imageId' ),
				'src'   => array(
					'kind'     => 'url',
					'from'     => 'html_attr',
					'selector' => 'img',
					'attr'     => 'src',
					'also'     => array( array( 'from' => 'attr', 'path' => 'imageUrl' ) ),
				),
				'alt'   => array( 'kind' => 'text', 'from' => 'html_attr', 'selector' => 'img', 'attr' => 'alt', 'verified' => false ),
			),
		),

		// Structure: the button group of the preview page, columns and column of the template.
		'stackable/button-group' => array( 'container' => true ),
		'stackable/columns'      => array( 'container' => true ),
		'stackable/column'       => array( 'container' => true ),
	),
);

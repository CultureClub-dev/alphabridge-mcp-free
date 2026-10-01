<?php
/**
 * Element profile: blocks of a SeedProd page (SeedProd Lite 6.20.10), as
 * stored in the JSON of post_content_filtered (document.sections[].rows[].
 * cols[].blocks[], each block with id, type and settings).
 *
 * Measured ("verified"): header, text, button and image, in the plan's
 * measurement of 30.09.2026 (alphabridge-docs: docs/recherche/
 * 2026-09-30-page-builder-messung/seedprod-pagelayer/README.md, sections a–c,
 * g; raw data out/sp-*.json): each field in the JSON and in the HTML the
 * builder wrote to post_content. The JSON of the fixtures comes from a second
 * run on 01.10.2026 with the same version through the same save path
 * (tests/fixtures/builders/seedprod/README.md).
 *
 * Locked: the code blocks the measurement names in g) — custom-html (shown
 * as it is, measured), shortcode, video and videopopup (embed code) — and the
 * two form blocks of a) (optin-form, contact-form). Page scripts and CSS live
 * outside the blocks (header_scripts …, document.settings.headCss …) and are
 * never read. Block types not listed here are listed as locked "unknown
 * element type".
 *
 * Format: see includes/builders/class-element-profile.php.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

return array(
	'builder'  => 'seedprod',
	'verified' => true,
	'source'   => 'seedprod-pagelayer/README.md (SeedProd Lite 6.20.10, 30.09.2026) and a second run on 01.10.2026 (tests/fixtures/builders/seedprod/README.md)',
	'elements' => array(
		'header'       => array(
			'fields' => array(
				'headerTxt' => array( 'kind' => 'heading' ),
			),
		),
		'text'         => array(
			'fields' => array(
				'txt' => array( 'kind' => 'html' ),
			),
		),
		'button'       => array(
			'fields' => array(
				'btnTxt' => array( 'kind' => 'text' ),
				'link'   => array( 'kind' => 'url' ),
			),
		),
		'image'        => array(
			'fields' => array(
				'src'    => array( 'kind' => 'image' ),
				'altTxt' => array( 'kind' => 'text' ),
			),
		),
		'custom-html'  => array( 'locked' => 'code element' ),
		'shortcode'    => array( 'locked' => 'shortcode' ),
		'video'        => array( 'locked' => 'code element' ),
		'videopopup'   => array( 'locked' => 'code element' ),
		'optin-form'   => array( 'locked' => 'form' ),
		'contact-form' => array( 'locked' => 'form' ),
	),
);

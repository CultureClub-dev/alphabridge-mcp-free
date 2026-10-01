<?php
/**
 * Block profile: Spectra (plugin ultimate-addons-for-gutenberg, blocks uagb/*).
 *
 * Measured ("verified") in the plan's measurement with Spectra 2.20.4 on
 * WordPress 7.1.2 (docs/recherche/2026-09-30-page-builder-messung/bloecke/
 * README.md, section "Spectra 2.20.4"; raw data out/ergebnis-spectra.json), on
 * the vendor templates "About 24" and "About 20":
 * - heading (uagb/advanced-heading) and the info box text: only in the
 *   markup. Neither block is registered on the server, so the page shows the
 *   saved markup;
 * - button (uagb/buttons-child): label and link in BOTH the comment and the
 *   markup. The page shows the markup; changing only the comment is
 *   invisible, changing only the markup leaves label/link stale;
 * - image (uagb/image): url, urlTablet and urlMobile and the id in the
 *   comment, src (and srcset) in the markup. The page shows the markup.
 * Spectra's free version has no raw-HTML or code block (README h)); its SVG
 * icons are in the saved markup and never read (the reader drops svg).
 *
 * Format: see includes/builders/class-block-reader.php.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

return array(
	'id'         => 'spectra',
	'builder'    => 'spectra',
	'verified'   => true,
	'source'     => 'bloecke/README.md Spectra (2.20.4, WordPress 7.1.2), out/ergebnis-spectra.json',
	'namespaces' => array( 'uagb/' ),
	'id_attr'    => 'block_id', // Every Spectra block of the measured templates carries block_id.
	'blocks'     => array(

		// blocks.min.js:3: headingTitle from .uagb-heading-text. Measured: only in the markup.
		// The templates use it for headings (headingTag h3) and for text (headingTag p), so the
		// rich text is read as simple HTML.
		'uagb/advanced-heading' => array(
			'fields' => array(
				'text' => array( 'kind' => 'html', 'from' => 'html_inner', 'selector' => '.uagb-heading-text' ),
			),
		),

		// Measured: the description (.uagb-ifb-desc) only in the markup. Prefix and title are
		// only in the markup of both templates too (no attribute holds them), but were not
		// changed in the measurement.
		'uagb/info-box'         => array(
			'fields' => array(
				'prefix' => array( 'kind' => 'text', 'from' => 'html_text', 'selector' => '.uagb-ifb-title-prefix', 'verified' => false ),
				'title'  => array( 'kind' => 'heading', 'from' => 'html_text', 'selector' => '.uagb-ifb-title', 'verified' => false ),
				'text'   => array( 'kind' => 'html', 'from' => 'html_inner', 'selector' => '.uagb-ifb-desc' ),
			),
		),

		// Measured: label and link in the comment and in the markup; the page shows the markup.
		'uagb/buttons-child'    => array(
			'fields' => array(
				'text' => array(
					'kind'     => 'text',
					'from'     => 'html_text',
					'selector' => '.uagb-button__link',
					'also'     => array( array( 'from' => 'attr', 'path' => 'label' ) ),
				),
				'url'  => array(
					'kind'     => 'url',
					'from'     => 'html_attr',
					'selector' => 'a',
					'attr'     => 'href',
					'also'     => array( array( 'from' => 'attr', 'path' => 'link' ) ),
				),
			),
		),

		// Measured: src in the markup, three copies in the comment, the id only in the comment.
		'uagb/image'            => array(
			'fields' => array(
				'image' => array( 'kind' => 'image', 'from' => 'attr', 'path' => 'id' ),
				'src'   => array(
					'kind'     => 'url',
					'from'     => 'html_attr',
					'selector' => 'img',
					'attr'     => 'src',
					'also'     => array(
						array( 'from' => 'attr', 'path' => 'url' ),
						array( 'from' => 'attr', 'path' => 'urlTablet' ),
						array( 'from' => 'attr', 'path' => 'urlMobile' ),
					),
				),
				'alt'   => array( 'kind' => 'text', 'from' => 'html_attr', 'selector' => 'img', 'attr' => 'alt' ),
			),
		),

		// Structure: 11 containers and 1 button group in the measured templates.
		'uagb/container'        => array( 'container' => true ),
		'uagb/buttons'          => array( 'container' => true ),
	),
);

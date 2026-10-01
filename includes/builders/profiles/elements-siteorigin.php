<?php
/**
 * Element profile: widgets in a SiteOrigin Page Builder layout (Page Builder
 * by SiteOrigin 2.36.1 with SiteOrigin Widgets Bundle 1.74.3).
 *
 * Measured ("verified"): every field listed here.
 * - [M] the plan's measurement of 30.09.2026, alphabridge-docs:
 *   docs/recherche/2026-09-30-page-builder-messung/meta/1-siteorigin/
 *   (nutzlast-A-dekodiert-fundstellen.txt: headline.text, text, text/url,
 *   image/alt);
 * - [N] a second run on 01.10.2026 with the same versions, saved the way the
 *   classic editor saves and rendered with siteorigin_panels_render() — a
 *   field is listed only where its value showed in the rendered page (method
 *   and raw data: tests/fixtures/builders/siteorigin/README.md).
 * - [SVN file:line] plugins.svn.wordpress.org/so-widgets-bundle/tags/1.74.3/,
 *   read 01.10.2026.
 *
 * Widget types are the class names SiteOrigin stores in panels_info.class.
 * Classes not listed here are listed as locked "unknown element type".
 * Styling (panels_info.style, row and cell styles, the button's design and
 * attributes, on_click among them) is never a field.
 *
 * Format: see includes/builders/class-element-profile.php.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

return array(
	'builder'  => 'siteorigin',
	'verified' => true,
	'source'   => 'meta/1-siteorigin (30.09.2026) and a second run on 01.10.2026 (tests/fixtures/builders/siteorigin/README.md); SiteOrigin 2.36.1, Widgets Bundle 1.74.3',
	'elements' => array(

		// [SVN widgets/headline/headline.php:45-54 headline.text, .destination_url, :126-135 sub_headline];
		// [M] headline.text; [N] all four shown.
		'SiteOrigin_Widget_Headline_Widget' => array(
			'fields' => array(
				'headline.text'                => array( 'kind' => 'heading' ),
				'headline.destination_url'     => array( 'kind' => 'url' ),
				'sub_headline.text'            => array( 'kind' => 'heading' ),
				'sub_headline.destination_url' => array( 'kind' => 'url' ),
			),
		),

		// [SVN widgets/editor/editor.php:30 title, :34 text, :85-95 shortcodes run]; [M] text; [N] title
		// as the widget title, text shown, a shortcode in the text ran (the element then locks).
		'SiteOrigin_Widget_Editor_Widget'   => array(
			'fields' => array(
				'title' => array( 'kind' => 'heading' ),
				'text'  => array( 'kind' => 'html' ),
			),
		),

		// [SVN widgets/button/button.php:53 text, :58 url, :242-265 attributes incl. on_click]; [M] text, url;
		// [N] shown.
		'SiteOrigin_Widget_Button_Widget'   => array(
			'fields' => array(
				'text' => array( 'kind' => 'text' ),
				'url'  => array( 'kind' => 'url' ),
			),
		),

		// [SVN widgets/image/image.php:40 image, :98 title, :114 alt, :119 url]; [M] image (attachment id),
		// alt; [N] title (title attribute) and url (link around the image) shown.
		'SiteOrigin_Widget_Image_Widget'    => array(
			'fields' => array(
				'image' => array( 'kind' => 'image' ),
				'alt'   => array( 'kind' => 'text' ),
				'title' => array( 'kind' => 'text' ),
				'url'   => array( 'kind' => 'url' ),
			),
		),

		// WordPress' Text widget (wp-includes/widgets/class-wp-widget-text.php); [N] title and text shown.
		'WP_Widget_Text'                    => array(
			'fields' => array(
				'title' => array( 'kind' => 'heading' ),
				'text'  => array( 'kind' => 'html' ),
			),
		),

		// WordPress' Custom HTML widget; [N] a <script> in it reached the page.
		'WP_Widget_Custom_HTML'             => array( 'locked' => 'html widget' ),

		// Page Builder's own widgets (siteorigin-panels inc/widgets/): a nested layout, the post's
		// content, a post loop.
		'SiteOrigin_Panels_Widgets_Layout'  => array( 'locked' => 'nested layout' ),
		'SiteOrigin_Panels_Widgets_PostContent' => array( 'locked' => 'dynamic value' ),
		'SiteOrigin_Panels_Widgets_PostLoop' => array( 'locked' => 'dynamic value' ),
	),
);

<?php
/**
 * Element profile: Beaver Builder modules (Beaver Builder Lite 2.11.0.6).
 *
 * Measured ("verified"): every field listed here, in a disposable WordPress
 * 7.1.2 with Beaver Builder Lite 2.11.0.6 on 01.10.2026 (method and raw data:
 * tests/fixtures/builders/beaver/README.md): a page with every Lite module,
 * each text, editor and link field filled, then FLBuilder::render_content_by_id()
 * — a field is listed only where its value showed in the rendered page.
 * The same run recorded each module's registered field types; text-typed
 * fields such as "id", "class", "node_label" or "visibility_user_capability"
 * end up in attributes or nowhere, so the list goes by name, not by type.
 *
 * Sources in the comments:
 * - [N] that measurement (fixture "page" and the field "im_html" of it);
 * - [SVN file:line] plugins.svn.wordpress.org/beaver-builder-lite-version/tags/2.11.0.6/,
 *   read 01.10.2026.
 *
 * Row, column-group and column are structure; the adapter lists them itself.
 * Module types not listed here (Beaver Builder's paid modules, third-party
 * modules) are listed as locked "unknown element type".
 *
 * Format: see includes/builders/class-element-profile.php.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

return array(
	'builder'  => 'beaver',
	'verified' => true,
	'source'   => 'Measured 01.10.2026, Beaver Builder Lite 2.11.0.6 (tests/fixtures/builders/beaver/README.md); module code of 2.11.0.6',
	'elements' => array(

		// [SVN modules/heading/heading.php:132 heading (text), :159 link (link)]; [N] both shown.
		'heading'        => array(
			'fields' => array(
				'heading' => array( 'kind' => 'heading' ),
				'link'    => array( 'kind' => 'url' ),
			),
		),

		// [SVN modules/rich-text/rich-text.php:34 text (editor)]; [N] shown.
		'rich-text'      => array(
			'fields' => array(
				'text' => array( 'kind' => 'html' ),
			),
		),

		// [SVN modules/button/button.php:446 text, :487-505 click_action (default "link", the link
		// field only for "link"), :507 link]; [N] text and link shown.
		'button'         => array(
			'fields' => array(
				'text' => array( 'kind' => 'text' ),
				'link' => array(
					'kind' => 'url',
					'when' => array( 'click_action' => array( '', 'link' ) ),
				),
			),
		),

		// [SVN modules/photo/photo.php:609-628 photo_source (default "library"), :629 photo (attachment id),
		// :665-676 show_caption (default "0" = never), :692 caption, :705-716 link_type, :731 link_url];
		// [N] image, caption (show_caption "below") and link (link_type "url") shown. photo_src keeps the
		// address of the same image (photo.php:82-89); Pro changes both together.
		'photo'          => array(
			'fields' => array(
				'photo'    => array(
					'kind' => 'image',
					'when' => array( 'photo_source' => array( '', 'library' ) ),
				),
				'caption'  => array(
					'kind' => 'text',
					'when' => array( 'show_caption' => array( 'hover', 'below' ) ),
				),
				'link_url' => array(
					'kind' => 'url',
					'when' => array( 'link_type' => array( 'url' ) ),
				),
			),
		),

		// [SVN modules/callout/callout.php:379 title, :407 text (editor), :780 link]; [N] shown.
		// cta_text shows only with a call-to-action type, which was not measured.
		'callout'        => array(
			'fields' => array(
				'title' => array( 'kind' => 'heading' ),
				'text'  => array( 'kind' => 'html' ),
				'link'  => array( 'kind' => 'url' ),
			),
		),

		// [SVN modules/cta/cta.php:158 title, :187 text (editor), :342 btn_text, :352 btn_link]; [N] shown.
		'cta'            => array(
			'fields' => array(
				'title'    => array( 'kind' => 'heading' ),
				'text'     => array( 'kind' => 'html' ),
				'btn_text' => array( 'kind' => 'text' ),
				'btn_link' => array( 'kind' => 'url' ),
			),
		),

		// [SVN modules/icon/icon.php:67 link, :85 text (editor)]; [N] shown.
		'icon'           => array(
			'fields' => array(
				'text' => array( 'kind' => 'html' ),
				'link' => array( 'kind' => 'url' ),
			),
		),

		// [SVN modules/numbers/numbers.php:263 before_number_text, :273 after_number_text]; [N] shown.
		// number_prefix and number_suffix did not show in the rendered page.
		'numbers'        => array(
			'fields' => array(
				'before_number_text' => array( 'kind' => 'text' ),
				'after_number_text'  => array( 'kind' => 'text' ),
			),
		),

		// [SVN modules/button-group/button-group.php:245 items (form buttons_form), :498 text, :544-563
		// click_action (default "link"), :568 link];
		// [N] text and link of an item shown.
		'button-group'   => array(
			'fields' => array(
				'items.*.text' => array( 'kind' => 'text' ),
				'items.*.link' => array(
					'kind' => 'url',
					'when' => array( 'items.*.click_action' => array( '', 'link' ) ),
				),
			),
		),

		// [SVN modules/menu/menu.php:703 mobile_title]; [N] shown. The menu items are a WordPress menu.
		'menu'           => array(
			'fields' => array(
				'mobile_title' => array( 'kind' => 'text' ),
			),
		),

		// [SVN modules/box/box.php:6-14] a layout container ("accepts" all); its children are modules.
		'box'            => array( 'container' => true ),

		// No text fields of their own ([N] registration).
		'star-rating'    => array( 'fields' => array() ),
		'audio'          => array( 'fields' => array() ),

		// [SVN modules/video/video.php:396-412, :431] video_type "embed" shows embed_code as it is; [N] shown raw.
		'video'          => array(
			'locked'      => 'code element',
			'locked_when' => array( 'video_type' => array( 'embed' ) ),
		),

		// [SVN modules/html/html.php:34] html (code); [N] a <script> in it reached the page.
		'html'           => array( 'locked' => 'code element' ),

		// [SVN modules/widget/widget.php:75-91] another plugin's widget and its own settings.
		'widget'         => array( 'locked' => 'wordpress widget' ),

		// [SVN modules/sidebar/sidebar.php] shows the widgets of a widget area.
		'sidebar'        => array( 'locked' => 'dynamic value' ),

		// [SVN modules/reusable-block/reusable-block.php:68 block_id, includes/frontend.php] shows a pattern post.
		'reusable-block' => array(
			'locked' => 'global element',
			'global' => true,
			'ref'    => 'block_id',
		),
	),
);

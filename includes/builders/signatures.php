<?php
/**
 * Every page builder AlphaBridge knows, as data: how a post built with it is
 * recognised, whether the builder is loaded, and where it keeps the page.
 *
 * Only what is documented is listed, each line with its source. A builder
 * without a documented marker or active check has none here (detection or the
 * "active" answer then stays empty/unknown) rather than a guessed one.
 *
 * Sources, abbreviated in the comments:
 * - [M] the measurement folder of the page-builder plan,
 *   alphabridge-docs: docs/recherche/2026-09-30-page-builder-messung/
 *   (README.md, bloecke/, meta/, seedprod-pagelayer/, quellen/<builder>.md).
 *   [M quellen/x.md [n]] cites source n of that vendor file.
 * - [SVN slug@version file:line] the plugin's own code on
 *   plugins.svn.wordpress.org/<slug>/tags/<version>/, read 01.10.2026 —
 *   the versions measured in [M].
 * - [AIOSEO File:line] plugins.svn.wordpress.org/all-in-one-seo-pack/trunk/
 *   app/Common/Standalone/PageBuilders/<File>.php, read 01.10.2026 — a
 *   third-party plugin, not the builder's vendor; used only where the vendor
 *   publishes nothing.
 * - [WPVibe] plugins.svn.wordpress.org/vibe-ai/trunk/includes/
 *   class-wpvibe-breakdance.php, read 01.10.2026 — third party as well.
 * - [Plan §n] alphabridge-docs: docs/plans/2026-09-30-page-builder-stufe-1-2.md.
 *
 * Format of one entry (key = builder id):
 * - name     Display name.
 * - family   'blocks' (blocks with HTML, read by the block reader),
 *            'blocks-json' (blocks whose text lives only in JSON attributes),
 *            'shortcodes', 'meta' (own post meta), 'tables' (own tables).
 * - storage  'A', 'A2', 'B' or 'C' (plan §2).
 * - markers  Any one of these marks a post as built with the builder:
 *            [ 'meta' => key ]                     value present and not '' or '0';
 *            [ 'meta' => key, 'equals' => v ]      value equals v;
 *            [ 'meta' => key, 'not_in' => [..] ]   present, not '' or '0', and none of these;
 *            [ 'content' => needle ]               post_content contains needle;
 *            [ 'content_prefix' => needle ]        post_content starts with needle;
 *            [ 'content_regex' => re, 'like' => s ] regex on post_content; s is the
 *                                                  literal the SQL prefilter of
 *                                                  wp_list_posts searches for.
 * - unless   Markers that rule the builder out again (same format).
 * - active   Any one of these means the builder is loaded:
 *            [ 'constant' => C ], [ 'class' => C ], [ 'function' => F ],
 *            [ 'plugin' => 'dir/file.php' ] (active plugin), [ 'theme' => slug ]
 *            (active theme or parent theme). Empty: unknown.
 * - version  Constant holding the plugin version, or ''.
 * - data_version  [ 'meta' => key ] holding the data format version, or [].
 * - copies   Stored copies of a page: [ 'loc', 'shown', 'role' ], see
 *            AB_MCP_Builder_Adapter::copies().
 * - locked_meta  Meta keys with page code that are never read ("*" = prefix).
 * - markup_meta  Optional. Further meta keys with markup or code of the page,
 *            or that decide how it renders, beyond the meta copies and
 *            locked_meta ("*" = prefix). All three together are the keys only
 *            an account with unfiltered_html may write through a meta
 *            argument (AB_MCP_Builders::markup_meta_keys()).
 *
 * Order matters: when a post carries the markers of several builders, the
 * first active one in this list answers for the page. Builders that render
 * from their own data come first, block libraries and shortcode builders after.
 *
 * Extend or correct it with the filter ab_mcp_builder_signatures.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

return array(

	/* ---------------------------------------------- own meta data (B, A2) */

	'elementor'       => array(
		'name'         => 'Elementor',
		'family'       => 'meta',
		'storage'      => 'B', // [M README.md, ergebnis-1]: the page renders from _elementor_data; changing post_content had no effect.
		'markers'      => array(
			array( 'meta' => '_elementor_edit_mode' ), // [SVN elementor@4.3.3 core/base/document.php:46, :943-945] is_built_with_elementor() = (bool) this meta.
		),
		'active'       => array(
			array( 'constant' => 'ELEMENTOR_VERSION' ), // [SVN elementor@4.3.3 elementor.php:31].
		),
		'version'      => 'ELEMENTOR_VERSION',
		'data_version' => array( 'meta' => '_elementor_version' ), // [SVN elementor@4.3.3 core/base/document.php:1446] written on save.
		'copies'       => array(
			array( 'loc' => 'meta:_elementor_data', 'shown' => true, 'role' => 'source' ), // [M ergebnis-1-elementor-klassisch.json] elementor_meta.
			array( 'loc' => 'post_content', 'shown' => false, 'role' => 'copy' ), // [M ergebnis-1] elementor_post_content: text copy.
		),
		'locked_meta'  => array(),
		// [Plan §5.1] the keys besides _elementor_data that Pro's search and
		// replace keeps to accounts with unfiltered_html (META_KEYS_WITH_MARKUP,
		// search-bulk-pro:70): page settings, edit mode, template type
		// ([M ergebnis-1]) and controls usage.
		'markup_meta'  => array( '_elementor_page_settings', '_elementor_edit_mode', '_elementor_template_type', '_elementor_controls_usage' ),
	),

	'beaver'          => array(
		'name'         => 'Beaver Builder',
		'family'       => 'meta',
		'storage'      => 'B', // [M README.md, ergebnis-3/4]: layout in _fl_builder_data.
		'markers'      => array(
			array( 'meta' => '_fl_builder_enabled' ), // [SVN beaver-builder-lite-version@2.11.0.6 classes/class-fl-builder-model.php:760].
		),
		'active'       => array(
			array( 'constant' => 'FL_BUILDER_VERSION' ), // [SVN beaver-builder-lite-version@2.11.0.6 classes/class-fl-builder-loader.php:51].
		),
		'version'      => 'FL_BUILDER_VERSION',
		'data_version' => array(),
		'copies'       => array(
			array( 'loc' => 'meta:_fl_builder_data', 'shown' => true, 'role' => 'source' ), // [M ergebnis-3] beaver_meta_schluessel.
			array( 'loc' => 'meta:_fl_builder_draft', 'shown' => false, 'role' => 'draft' ), // [M ergebnis-3] beaver_meta_schluessel.
			array( 'loc' => 'post_content', 'shown' => false, 'role' => 'copy' ), // [M ergebnis-3] beaver_post_content.
		),
		'locked_meta'  => array(
			'_fl_builder_data_settings', // [M ergebnis-1] beaver_meta: holds the page's "css" and "js".
			'_fl_builder_draft_settings', // [SVN beaver-builder-lite-version@2.11.0.6 classes/class-fl-builder-model.php:6278, :6314] the draft's "css" and "js"; measured 01.10.2026 (tests/fixtures/builders/beaver/README.md).
		),
	),

	'siteorigin'      => array(
		'name'         => 'SiteOrigin Page Builder',
		'family'       => 'meta',
		'storage'      => 'B', // [M meta/README.md §1 d].
		'markers'      => array(
			array( 'meta' => 'panels_data' ), // [M meta/README.md §1 b]; [SVN siteorigin-panels@2.36.1 siteorigin-panels.php:236] is_panel().
		),
		'active'       => array(
			array( 'constant' => 'SITEORIGIN_PANELS_VERSION' ), // [SVN siteorigin-panels@2.36.1 siteorigin-panels.php:14].
		),
		'version'      => 'SITEORIGIN_PANELS_VERSION',
		'data_version' => array(),
		'copies'       => array(
			array( 'loc' => 'meta:panels_data', 'shown' => true, 'role' => 'source' ), // [M meta/README.md §1 b].
			array( 'loc' => 'post_content', 'shown' => false, 'role' => 'copy' ), // [M meta/README.md §1 d].
		),
		'locked_meta'  => array(),
	),

	'brizy'           => array(
		'name'         => 'Brizy',
		'family'       => 'meta',
		'storage'      => 'B', // [M meta/README.md §2 d].
		'markers'      => array(
			array( 'meta' => 'brizy_enabled' ), // [M meta/README.md §2 b].
			array( 'content' => 'brz-root__container' ), // [M meta/README.md §2 b] marker in post_content (public/main.php:382).
		),
		'active'       => array(
			array( 'constant' => 'BRIZY_VERSION' ), // [SVN brizy@2.8.23 brizy.php:21].
		),
		'version'      => 'BRIZY_VERSION',
		'data_version' => array(),
		'copies'       => array(
			array( 'loc' => 'meta:brizy', 'shown' => false, 'role' => 'source' ), // [M meta/README.md §2 b, e] editor_data, loaded by the editor.
			array( 'loc' => 'meta:brizy-compiled-sections', 'shown' => true, 'role' => 'copy' ), // [M meta/README.md §2 b, d] what the page shows.
			array( 'loc' => 'post_content', 'shown' => false, 'role' => 'copy' ), // [M meta/README.md §2 d].
		),
		'locked_meta'  => array(),
	),

	'themify'         => array(
		'name'         => 'Themify Builder',
		'family'       => 'meta',
		'storage'      => 'B', // [M meta/README.md §3 d].
		'markers'      => array(
			array( 'meta' => '_themify_builder_settings_json' ), // [M meta/README.md §3 b].
			array( 'content' => '<!--themify_builder_static-->' ), // [M meta/README.md §3 b] (classes/class-builder-data-manager.php:28, 306).
		),
		'active'       => array(
			array( 'class' => 'Themify_Builder' ), // [SVN themify-builder@7.8.2 themify-builder.php:128] the plugin's own check.
		),
		'version'      => '',
		'data_version' => array(),
		'copies'       => array(
			array( 'loc' => 'meta:_themify_builder_settings_json', 'shown' => true, 'role' => 'source' ), // [M meta/README.md §3 b].
			array( 'loc' => 'post_content', 'shown' => false, 'role' => 'copy' ), // [M meta/README.md §3 d].
		),
		'locked_meta'  => array( 'tbp_custom_js', 'tbp_custom_css' ), // [M meta/README.md §3 g] printed as <script>/<style>.
	),

	'zion'            => array(
		'name'         => 'Zion Builder',
		'family'       => 'meta',
		'storage'      => 'B', // [M meta/README.md §5 d]: post_content stays empty.
		'markers'      => array(
			array( 'meta' => '_zionbuilder_page_status', 'equals' => 'enabled' ), // [M meta/README.md §5 b] (includes/Post/BasePostType.php:24-25).
		),
		'active'       => array(
			array( 'class' => 'ZionBuilder\\Plugin' ), // [SVN zionbuilder@3.6.17 includes/Plugin.php:44, zionbuilder.php:77-80].
		),
		'version'      => '',
		'data_version' => array(),
		'copies'       => array(
			array( 'loc' => 'meta:_zionbuilder_page_elements', 'shown' => true, 'role' => 'source' ), // [M meta/README.md §5 b].
		),
		'locked_meta'  => array(),
	),

	'livecomposer'    => array(
		'name'         => 'Live Composer',
		'family'       => 'meta',
		'storage'      => 'B', // [M meta/README.md §6 d].
		'markers'      => array(
			array( 'meta' => 'dslc_code' ), // [M meta/README.md §6 b].
			array( 'content' => 'dslc-modules-section' ), // [M meta/README.md §6 b].
		),
		'active'       => array(
			array( 'constant' => 'DS_LIVE_COMPOSER_VER' ), // [SVN live-composer-page-builder@2.1.22 ds-live-composer.php:44].
		),
		'version'      => 'DS_LIVE_COMPOSER_VER',
		'data_version' => array(),
		'copies'       => array(
			array( 'loc' => 'meta:dslc_code', 'shown' => true, 'role' => 'source' ), // [M meta/README.md §6 b, d] shown through the lc_cache transient.
			array( 'loc' => 'post_content', 'shown' => false, 'role' => 'copy' ), // [M meta/README.md §6 d].
		),
		'locked_meta'  => array(),
	),

	'enfold'          => array(
		'name'         => 'Enfold (Avia Layout Builder)',
		'family'       => 'meta',
		'storage'      => 'B', // [M quellen/enfold.md a)] shortcodes in meta, post_content a copy.
		'markers'      => array(
			array( 'meta' => '_aviaLayoutBuilder_active', 'equals' => 'active' ), // [M quellen/enfold.md [6] l. 3661] vendor code.
		),
		'active'       => array(
			array( 'function' => 'Avia_Builder' ), // [M quellen/enfold.md [5][7]] Avia_Builder()->get_alb_builder_status(), vendor code.
		),
		'version'      => '',
		'data_version' => array(),
		'copies'       => array(
			array( 'loc' => 'meta:_aviaLayoutBuilderCleanData', 'shown' => true, 'role' => 'source' ), // [M quellen/enfold.md [2][4]].
			array( 'loc' => 'post_content', 'shown' => false, 'role' => 'copy' ), // [M quellen/enfold.md [4] l. 1700-1701].
		),
		'locked_meta'  => array(),
	),

	'cornerstone'     => array(
		'name'         => 'Cornerstone (X, Pro)',
		'family'       => 'meta',
		'storage'      => 'B', // [M quellen/cornerstone.md a)].
		'markers'      => array(
			array( 'meta' => '_cornerstone_data' ), // [M quellen/cornerstone.md [47][55]].
			array( 'content' => '<!-- cs-content -->' ), // [M quellen/cornerstone.md [46]] HTML storage mode.
			array( 'content' => '[cs_content' ), // [M quellen/cornerstone.md [46]] shortcode storage mode.
		),
		'active'       => array(
			array( 'function' => 'cs_uses_cornerstone' ), // [M quellen/cornerstone.md [46]] vendor helper, theme (Pro) or plugin (X).
		),
		'version'      => '',
		'data_version' => array(),
		'copies'       => array(
			array( 'loc' => 'meta:_cornerstone_data', 'shown' => true, 'role' => 'source' ), // [M quellen/cornerstone.md a)].
			array( 'loc' => 'post_content', 'shown' => false, 'role' => 'copy' ), // [M quellen/cornerstone.md a), b)].
		),
		'locked_meta'  => array(),
	),

	'thrive'          => array(
		'name'         => 'Thrive Architect',
		'family'       => 'meta',
		'storage'      => 'B', // [M quellen/thrive-architect.md a)].
		'markers'      => array(
			array( 'meta' => 'tcb_editor_enabled' ), // [M quellen/thrive-architect.md [1][40]] 1 = Architect on, default 0.
		),
		'active'       => array(
			array( 'constant' => 'TVE_VERSION' ), // [AIOSEO ThriveArchitect.php:101]; [M quellen/thrive-architect.md [52]].
			array( 'function' => 'tcb_post' ), // [AIOSEO ThriveArchitect.php:211].
		),
		'version'      => 'TVE_VERSION',
		'data_version' => array(),
		'copies'       => array(
			array( 'loc' => 'meta:tve_updated_post', 'shown' => true, 'role' => 'source' ), // [M quellen/thrive-architect.md [1][40]].
			array( 'loc' => 'post_content', 'shown' => false, 'role' => 'copy' ), // [M quellen/thrive-architect.md a), b)].
		),
		'locked_meta'  => array( 'tve_custom_css', 'tve_user_custom_css' ), // [M quellen/thrive-architect.md [1][40]] CSS.
	),

	'bricks'          => array(
		'name'         => 'Bricks',
		'family'       => 'meta',
		'storage'      => 'B', // [M quellen/bricks-breakdance-betheme.md B1].
		'markers'      => array(
			array( 'meta' => '_bricks_editor_mode', 'equals' => 'bricks' ), // [AIOSEO Bricks.php:102-105].
		),
		'active'       => array(
			array( 'theme' => 'bricks' ), // [AIOSEO Bricks.php:22].
			array( 'class' => 'Bricks\\Database' ), // [AIOSEO Bricks.php:157].
		),
		'version'      => '',
		'data_version' => array(),
		'copies'       => array(
			array( 'loc' => 'meta:_bricks_page_content_2', 'shown' => true, 'role' => 'source' ), // [M quellen/bricks-breakdance-betheme.md B1].
		),
		'locked_meta'  => array( '_bricks_page_settings' ), // [Plan §5.1] page settings with own CSS/JS.
		'markup_meta'  => array( '_bricks_page_header_2', '_bricks_page_footer_2' ), // [M quellen/bricks-breakdance-betheme.md B1] header and footer.
	),

	'breakdance'      => array(
		'name'         => 'Breakdance',
		'family'       => 'meta',
		'storage'      => 'B', // [M quellen/bricks-breakdance-betheme.md K1].
		'markers'      => array(
			array( 'meta' => '_breakdance_data', 'not_in' => array( '{"tree_json_string":""}' ) ), // [M quellen/bricks-breakdance-betheme.md K1]; empty design: [M quellen/oxygen-6.md [44]].
		),
		'active'       => array(
			array( 'function' => 'Breakdance\\Data\\set_meta' ), // [WPVibe :560] gate "Breakdance is not active".
		),
		'version'      => '',
		'data_version' => array(),
		'copies'       => array(
			array( 'loc' => 'meta:_breakdance_data', 'shown' => true, 'role' => 'source' ), // [M quellen/bricks-breakdance-betheme.md K1].
		),
		'locked_meta'  => array(),
	),

	'oxygen'          => array(
		'name'         => 'Oxygen 6',
		'family'       => 'meta',
		'storage'      => 'B', // [M quellen/oxygen-6.md a)].
		'markers'      => array(
			array( 'meta' => '_oxygen_data', 'not_in' => array( '{"tree_json_string":""}' ) ), // [M quellen/oxygen-6.md [43][44]].
		),
		'active'       => array(
			array( 'plugin' => 'oxygen/plugin.php' ), // [AIOSEO Oxygen.php:22-23].
		),
		'version'      => '',
		'data_version' => array(),
		'copies'       => array(
			array( 'loc' => 'meta:_oxygen_data', 'shown' => true, 'role' => 'source' ), // [M quellen/oxygen-6.md a)].
		),
		'locked_meta'  => array(),
	),

	'oxygen-classic'  => array(
		'name'         => 'Oxygen Classic',
		'family'       => 'meta',
		'storage'      => 'B', // [M quellen/oxygen-klassisch.md a)].
		'markers'      => array(
			array( 'meta' => 'ct_builder_json' ), // [M quellen/oxygen-klassisch.md [8][9]] 4.0 to 4.8.2.
			array( 'meta' => 'ct_builder_shortcodes' ), // [M quellen/oxygen-klassisch.md [30][31][40]] up to 3.x.
		),
		'active'       => array(
			array( 'constant' => 'CT_VERSION' ), // [M quellen/oxygen-klassisch.md [30][31]].
		),
		'version'      => 'CT_VERSION',
		'data_version' => array(),
		'copies'       => array(
			array( 'loc' => 'meta:ct_builder_json', 'shown' => true, 'role' => 'source' ), // [M quellen/oxygen-klassisch.md a)].
			array( 'loc' => 'meta:ct_builder_shortcodes', 'shown' => true, 'role' => 'source' ), // [M quellen/oxygen-klassisch.md a)].
		),
		'locked_meta'  => array(),
	),

	'betheme'         => array(
		'name'         => 'BeTheme (BeBuilder)',
		'family'       => 'meta',
		'storage'      => 'B', // [M quellen/bricks-breakdance-betheme.md] own meta.
		'markers'      => array(
			array( 'meta' => 'mfn-page-items' ), // [M verbreitung.md §6] CVE-2024-2694.
		),
		'active'       => array(), // No documented check.
		'version'      => '',
		'data_version' => array(),
		'copies'       => array(
			array( 'loc' => 'meta:mfn-page-items', 'shown' => true, 'role' => 'source' ), // [M verbreitung.md §6].
		),
		'locked_meta'  => array(),
	),

	'seedprod'        => array(
		'name'         => 'SeedProd',
		'family'       => 'meta',
		'storage'      => 'A2', // [M seedprod-pagelayer/README.md d)]: shows post_content, the builder loads post_content_filtered.
		'markers'      => array(
			array( 'meta' => '_seedprod_page' ), // [M seedprod-pagelayer/README.md b)] (app/render-lp.php:14-18).
			array( 'meta' => '_seedprod_edited_with_seedprod' ), // [M seedprod-pagelayer/README.md b)] (app/lpage.php:749-756).
		),
		'active'       => array(
			array( 'constant' => 'SEEDPROD_VERSION' ), // [SVN coming-soon@6.20.10 coming-soon.php:27].
			array( 'constant' => 'SEEDPROD_PRO_VERSION' ), // [AIOSEO SeedProd.php:50].
		),
		'version'      => 'SEEDPROD_VERSION',
		'data_version' => array(),
		'copies'       => array(
			array( 'loc' => 'post_content', 'shown' => true, 'role' => 'copy' ), // [M seedprod-pagelayer/README.md b), d)] HTML made by the builder.
			array( 'loc' => 'post_content_filtered', 'shown' => false, 'role' => 'source' ), // [M seedprod-pagelayer/README.md b), e)] JSON the builder loads.
		),
		'locked_meta'  => array(),
	),

	'visualcomposer'  => array(
		'name'         => 'Visual Composer Website Builder',
		'family'       => 'meta',
		'storage'      => 'A2', // [M meta/README.md §4 d)]: post_content is shown, vcv-pageContent is the editor's copy.
		'markers'      => array(
			array( 'meta' => 'vcv-pageContent' ), // [M meta/README.md §4 b)] (visualcomposer/Helpers/Frontend.php:189).
			array( 'content_prefix' => '<!--vcv no format-->' ), // [M meta/README.md §4 b)].
		),
		'active'       => array(
			array( 'constant' => 'VCV_VERSION' ), // [SVN visualcomposer@45.16.3 plugin-wordpress.php:49].
		),
		'version'      => 'VCV_VERSION',
		'data_version' => array(),
		'copies'       => array(
			array( 'loc' => 'post_content', 'shown' => true, 'role' => 'copy' ), // [M meta/README.md §4 d)].
			array( 'loc' => 'meta:vcv-pageContent', 'shown' => false, 'role' => 'source' ), // [M meta/README.md §4 b), e)].
		),
		'locked_meta'  => array( 'vcv-settingsLocalJs*', 'vcvSettingsSourceCustomCss' ), // [M meta/README.md §4 g)] page JS and CSS.
	),

	/* ------------------------------------------- blocks in post_content (A) */

	'pagelayer'       => array(
		'name'         => 'Pagelayer',
		'family'       => 'blocks',
		'storage'      => 'A', // [M seedprod-pagelayer/README.md d)]: renders from the blocks in post_content.
		'markers'      => array(
			array( 'content' => '<!-- wp:pagelayer/' ), // [M seedprod-pagelayer/README.md b)] (init.php:745-756).
			array( 'meta' => 'pagelayer-data' ), // [M seedprod-pagelayer/README.md b)].
		),
		'active'       => array(
			array( 'constant' => 'PAGELAYER_VERSION' ), // [SVN pagelayer@2.2.2 init.php:8].
		),
		'version'      => 'PAGELAYER_VERSION',
		'data_version' => array(),
		'copies'       => array(
			array( 'loc' => 'post_content', 'shown' => true, 'role' => 'source' ), // [M seedprod-pagelayer/README.md d)].
			array( 'loc' => 'meta:pagelayer-data', 'shown' => false, 'role' => 'copy' ), // [M seedprod-pagelayer/README.md b), d)] the abilities' tree.
		),
		'locked_meta'  => array( 'pagelayer_header_code', 'pagelayer_body_open_code', 'pagelayer_footer_code' ), // [M seedprod-pagelayer/README.md g)].
	),

	'generateblocks'  => array(
		'name'         => 'GenerateBlocks',
		'family'       => 'blocks',
		'storage'      => 'A', // [M bloecke/README.md GenerateBlocks c), d)].
		'markers'      => array(
			array( 'content' => '<!-- wp:generateblocks/' ), // [M bloecke/README.md GenerateBlocks 1.x and 2.x a)].
		),
		'active'       => array(
			array( 'constant' => 'GENERATEBLOCKS_VERSION' ), // [SVN generateblocks@2.4.1 plugin.php:22].
		),
		'version'      => 'GENERATEBLOCKS_VERSION',
		'data_version' => array(),
		'copies'       => array(
			array( 'loc' => 'post_content', 'shown' => true, 'role' => 'source' ), // [M bloecke/README.md GenerateBlocks d)] the page is post_content.
		),
		'locked_meta'  => array(),
	),

	'kadence'         => array(
		'name'         => 'Kadence Blocks',
		'family'       => 'blocks',
		'storage'      => 'A', // [M bloecke/README.md Kadence c), d)].
		'markers'      => array(
			array( 'content' => '<!-- wp:kadence/' ), // [M bloecke/README.md Kadence a)].
		),
		'active'       => array(
			array( 'constant' => 'KADENCE_BLOCKS_VERSION' ), // [SVN kadence-blocks@3.7.12 kadence-blocks.php:23].
		),
		'version'      => 'KADENCE_BLOCKS_VERSION',
		'data_version' => array(),
		'copies'       => array(
			array( 'loc' => 'post_content', 'shown' => true, 'role' => 'source' ), // [M bloecke/README.md Kadence d)] the page is post_content.
		),
		'locked_meta'  => array(),
	),

	'spectra'         => array(
		'name'         => 'Spectra',
		'family'       => 'blocks',
		'storage'      => 'A', // [M bloecke/README.md Spectra c), d)].
		'markers'      => array(
			array( 'content' => '<!-- wp:uagb/' ), // [M bloecke/README.md Spectra a)].
		),
		'active'       => array(
			array( 'constant' => 'UAGB_VER' ), // [SVN ultimate-addons-for-gutenberg@2.20.4 classes/class-uagb-loader.php:143].
		),
		'version'      => 'UAGB_VER',
		'data_version' => array(),
		'copies'       => array(
			array( 'loc' => 'post_content', 'shown' => true, 'role' => 'source' ), // [M bloecke/README.md Spectra d)] the page is post_content.
		),
		'locked_meta'  => array(),
	),

	'stackable'       => array(
		'name'         => 'Stackable',
		'family'       => 'blocks',
		'storage'      => 'A', // [M bloecke/README.md Stackable c), d)].
		'markers'      => array(
			array( 'content' => '<!-- wp:stackable/' ), // [M bloecke/README.md Stackable a)].
		),
		'active'       => array(
			array( 'constant' => 'STACKABLE_VERSION' ), // [SVN stackable-ultimate-gutenberg-blocks@3.20.2 plugin.php:46].
		),
		'version'      => 'STACKABLE_VERSION',
		'data_version' => array(),
		'copies'       => array(
			array( 'loc' => 'post_content', 'shown' => true, 'role' => 'source' ), // [M bloecke/README.md Stackable d)] the page is post_content.
		),
		'locked_meta'  => array(),
	),

	'otter'           => array(
		'name'         => 'Otter Blocks',
		'family'       => 'blocks',
		'storage'      => 'A', // [Plan §1]: block library, read by the general block reader; not measured.
		'markers'      => array(
			array( 'content' => '<!-- wp:themeisle-blocks/' ), // [SVN otter-blocks@3.2.7 build/blocks/button/block.json:4] "themeisle-blocks/button".
		),
		'active'       => array(
			array( 'constant' => 'OTTER_BLOCKS_VERSION' ), // [SVN otter-blocks@3.2.7 otter-blocks.php:30].
		),
		'version'      => 'OTTER_BLOCKS_VERSION',
		'data_version' => array(),
		'copies'       => array(
			array( 'loc' => 'post_content', 'shown' => true, 'role' => 'source' ), // Blocks in post_content, like every block library ([Plan §2]); not measured.
		),
		'locked_meta'  => array(),
	),

	'coblocks'        => array(
		'name'         => 'CoBlocks',
		'family'       => 'blocks',
		'storage'      => 'A', // [Plan §1]: block library, read by the general block reader; not measured.
		'markers'      => array(
			array( 'content' => '<!-- wp:coblocks/' ), // [SVN coblocks@3.1.17 src/blocks/accordion/block.json:2] "coblocks/accordion".
		),
		'active'       => array(
			array( 'constant' => 'COBLOCKS_VERSION' ), // [SVN coblocks@3.1.17 class-coblocks.php:29].
		),
		'version'      => 'COBLOCKS_VERSION',
		'data_version' => array(),
		'copies'       => array(
			array( 'loc' => 'post_content', 'shown' => true, 'role' => 'source' ), // Blocks in post_content, like every block library ([Plan §2]); not measured.
		),
		'locked_meta'  => array(),
	),

	'divi5'           => array(
		'name'         => 'Divi 5',
		'family'       => 'blocks-json',
		'storage'      => 'A', // [M quellen/divi-5.md a)] blocks in post_content, text only in JSON attributes.
		'markers'      => array(
			// [M quellen/divi-5.md [35]] Divi's own check for Divi 5 blocks; divi/layout is the Divi 4 layout block ([34]).
			array( 'content_regex' => '~<!-- wp:divi/(?!layout[\s/])~', 'like' => '<!-- wp:divi/' ),
		),
		'active'       => array(
			array( 'constant' => 'ET_BUILDER_PRODUCT_VERSION' ), // [AIOSEO Divi.php:67].
			array( 'function' => 'et_pb_is_pagebuilder_used' ), // [M quellen/divi-5.md [50]]; [AIOSEO Divi.php:169].
			array( 'theme' => 'Divi' ), // [AIOSEO Divi.php:22].
			array( 'theme' => 'Extra' ), // [AIOSEO Divi.php:22].
			array( 'plugin' => 'divi-builder/divi-builder.php' ), // [AIOSEO Divi.php:31-32].
		),
		'version'      => 'ET_BUILDER_PRODUCT_VERSION',
		'data_version' => array(),
		'copies'       => array(
			array( 'loc' => 'post_content', 'shown' => true, 'role' => 'source' ), // [M quellen/divi-5.md a) [2][19]] saved by wp_update_post().
		),
		'locked_meta'  => array(),
	),

	'etch'            => array(
		'name'         => 'Etch',
		'family'       => 'blocks-json',
		'storage'      => 'A', // [M quellen/etch.md a)] own blocks in post_content, JSON attributes only.
		'markers'      => array(
			array( 'content' => '<!-- wp:etch/' ), // [M quellen/etch.md a) [7][8][63]].
		),
		'active'       => array(), // No documented check: the companion theme is required by the docs but not by the price page ([M quellen/etch.md [13][64]]).
		'version'      => '',
		'data_version' => array(),
		'copies'       => array(
			array( 'loc' => 'post_content', 'shown' => true, 'role' => 'source' ), // [M quellen/etch.md a) [1]].
		),
		'locked_meta'  => array(),
	),

	/* -------------------------------------- shortcodes in post_content (A) */

	'wpbakery'        => array(
		'name'         => 'WPBakery Page Builder',
		'family'       => 'shortcodes',
		'storage'      => 'A', // [M quellen/wpbakery.md a)].
		'markers'      => array(
			array( 'content_regex' => '~\[vc_(?:row|section)\b~', 'like' => '[vc_' ), // [M quellen/wpbakery.md [41][52]].
			array( 'meta' => '_wpb_vc_js_status', 'equals' => 'true' ), // [M quellen/wpbakery.md [41][50][51]] editor mode.
		),
		'active'       => array(
			array( 'constant' => 'WPB_VC_VERSION' ), // [AIOSEO WPBakery.php:45].
			array( 'plugin' => 'js_composer/js_composer.php' ), // [AIOSEO WPBakery.php:23]; [M quellen/wpbakery.md [38]].
			array( 'plugin' => 'js_composer_salient/js_composer.php' ), // [AIOSEO WPBakery.php:24] Salient's build.
		),
		'version'      => 'WPB_VC_VERSION',
		'data_version' => array(),
		'copies'       => array(
			array( 'loc' => 'post_content', 'shown' => true, 'role' => 'source' ), // [M quellen/wpbakery.md a)].
		),
		'locked_meta'  => array( '_wpb_shortcodes_custom_css', '_wpb_post_custom_css' ), // [M quellen/wpbakery.md a)] CSS.
	),

	'divi4'           => array(
		'name'         => 'Divi 4',
		'family'       => 'shortcodes',
		'storage'      => 'A', // [M quellen/divi-4.md a)].
		'markers'      => array(
			array( 'meta' => '_et_pb_use_builder', 'equals' => 'on' ), // [M quellen/divi-4.md [7]].
			array( 'content_regex' => '~\[et_pb_section\b~', 'like' => '[et_pb_section' ), // [M quellen/divi-4.md [3]].
		),
		'unless'       => array(
			array( 'content_regex' => '~<!-- wp:divi/(?!layout[\s/])~', 'like' => '<!-- wp:divi/' ), // A Divi 5 page: [M quellen/divi-5.md [34][35]].
		),
		'active'       => array(
			array( 'constant' => 'ET_BUILDER_PRODUCT_VERSION' ), // [AIOSEO Divi.php:67].
			array( 'function' => 'et_pb_is_pagebuilder_used' ), // [M quellen/divi-4.md [8]]; [AIOSEO Divi.php:169].
			array( 'theme' => 'Divi' ), // [AIOSEO Divi.php:22].
			array( 'theme' => 'Extra' ), // [AIOSEO Divi.php:22]; [M quellen/divi-4.md [2]].
			array( 'plugin' => 'divi-builder/divi-builder.php' ), // [AIOSEO Divi.php:31-32].
		),
		'version'      => 'ET_BUILDER_PRODUCT_VERSION',
		'data_version' => array(),
		'copies'       => array(
			array( 'loc' => 'post_content', 'shown' => true, 'role' => 'source' ), // [M quellen/divi-4.md a)].
		),
		'locked_meta'  => array(),
	),

	'avada'           => array(
		'name'         => 'Avada (Fusion Builder)',
		'family'       => 'shortcodes',
		'storage'      => 'A', // [M quellen/avada.md a)].
		'markers'      => array(
			array( 'content' => '[fusion_builder_container' ), // [M quellen/avada.md a) [42]].
			array( 'meta' => 'fusion_builder_status', 'equals' => 'active' ), // [AIOSEO Avada.php:108-109]; [M quellen/avada.md [38][39][42]].
		),
		'active'       => array(
			array( 'plugin' => 'fusion-builder/fusion-builder.php' ), // [AIOSEO Avada.php:22-23].
		),
		'version'      => '',
		'data_version' => array(),
		'copies'       => array(
			array( 'loc' => 'post_content', 'shown' => true, 'role' => 'source' ), // [M quellen/avada.md a)].
		),
		'locked_meta'  => array( '_fusion_builder_custom_css' ), // [M quellen/avada.md [39]] page CSS.
	),

	'flatsome'        => array(
		'name'         => 'Flatsome (UX Builder)',
		'family'       => 'shortcodes',
		'storage'      => 'A', // [M quellen/flatsome.md a)].
		'markers'      => array(
			array( 'content' => '[ux_' ), // [M quellen/flatsome.md a) [4][46]] its own [ux_…] elements.
			// [M quellen/flatsome.md a) [4][46]] and the documented structure [section][row][col]: "[section" alone is too common a tag.
			array( 'content_regex' => '~\[section\b[^\]]*\]\s*\[row\b~', 'like' => '[section' ),
		),
		'active'       => array(
			array( 'constant' => 'UX_BUILDER_VERSION' ), // [M quellen/flatsome.md [55]].
			array( 'function' => 'add_ux_builder_post_type' ), // [M quellen/flatsome.md [3]].
		),
		'version'      => 'UX_BUILDER_VERSION',
		'data_version' => array(),
		'copies'       => array(
			array( 'loc' => 'post_content', 'shown' => true, 'role' => 'source' ), // [M quellen/flatsome.md a)].
		),
		'locked_meta'  => array(),
	),

	/* ------------------------------------------------- own tables (C) */

	'mosaic'          => array(
		'name'         => 'Mosaic',
		'family'       => 'tables',
		'storage'      => 'C', // [M quellen/mosaic.md a)] own database tables.
		'markers'      => array(), // No per-post marker is documented ([M quellen/mosaic.md a)]).
		'active'       => array(), // No documented check.
		'version'      => '',
		'data_version' => array(),
		'copies'       => array(),
		'locked_meta'  => array(),
	),
);

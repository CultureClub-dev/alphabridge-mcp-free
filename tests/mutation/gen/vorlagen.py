#!/usr/bin/env python3
"""Writes tests/mutation/lists/vorlagen.php: one entry per clause of Z8
branch 9b (heikel/z8-vorlagen): the other core types of the kind post
(templates, template parts, global styles, navigations, reusable blocks;
decision 25), written by AB_MCP_Sync_Post_Writer with their theme, the
names of their terms, the template parts named by theme and slug, the rule
of the custom CSS, the navigation a block without a menu shows, the rule
of meta registered without an auth_callback, the caches, the order of the
plan and the sample of the check of a run.

A list of its own, not an extension of inhalte.py: the clauses are those
of branch 9b, held mostly by its integration class SyncTemplatesTest, so a
run of this list asks those tests and no others; the two entries of
inhalte.py that named the lock of branch 9 (post:engine_type) are moved
over to the code that replaced it there (inhalte/post-type-branch-9b,
inhalte/post-type-other-kind).

Every search is checked to occur exactly once in its file, and the test of
its expect to be a method of the class its run names, before the list is
written; each entry runs that test only.

    python3 tests/mutation/gen/vorlagen.py

Run it again after a change of these classes, then the list
(tests/mutation/run.php tests/mutation/lists/vorlagen.php). Development
only: /tests ships with no release.
"""
import os
import re
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__)))))
PW = 'includes/sync/class-sync-post-writer.php'
EN = 'includes/sync/class-sync-engine.php'
PU = 'includes/sync/class-sync-push.php'
FILEVAR = {PW: '$pw', EN: '$en', PU: '$pu'}

RUNS = {
    'unit': ('unit', 'SyncRunTest'),
    'it': ('it', 'SyncTemplatesTest'),
}

entries = []


def e(id_, clause, file, search, replace, run, expect):
    if '' == replace:
        replace = '/* removed */' + ('\n' if search.endswith('\n') else '')
    entries.append(('vorlagen/' + id_, clause, file, search, replace, run, expect))


# The tests.
NORMAL = 'testATemplateItsPartsAndTheGlobalStylesOfTheActiveThemeGoOverAndBack'
NAV = 'testANavigationAndAReusableBlockGoOverAndBackAndAPageNamesBothByTheirIds'
LOCKS = 'testWhatTheBranchLocksIsFoundBeforeAnythingIsWritten'
PENDING = 'testWhatTheSameRunCreatesFirstIsPendingAndANavigationAsDraftLeavesTheFallback'
BACK = 'testTheWayBackOfANavigationThatWouldMoveTheFallbackIsLeftForAPerson'
CSS_CORE = 'testTheRuleOfTheCustomCssIsTheOneOfTheSiteEditor'
FILTERED = 'testATemplateOfAThemeAFilterShowsInThisRequestOnlyIsLocked'
U_CSS = 'testTheCustomCssOfTheGlobalStylesKeepsTheRuleOfTheSiteEditorAtAnyDepth'
U_META = 'testTheRuleOfThisVersionForMetaKeys'
U_LOCKS = 'testWhatTheEngineOfThePostDoesNotCarryToItsEndIsRefusedWithItsLock'
CSS_BACK = 'testTheWayBackOfTheGlobalStylesAsksTheRuleOfTheCustomCssAsForward'
SEEN = 'testANewNavigationEveryBlockNamesByItsRefGoesAndOneABlockWithoutAMenuWouldShowIsLocked'
CHAIN = 'testATemplateANewPartAndWhatThePartNamesGoInTheOrderOfTheirReferences'

# ------------------------------------------------ what the engine writes
e('types', 'TYPES: the five core types of the kind post are written since branch 9b', PW,
  "\tconst TYPES = array( 'post', 'page', 'wp_block', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation' );",
  "\tconst TYPES = array( 'post', 'page' );", 'it', NORMAL)
e('taxonomies-theme', 'TAXONOMIES: the theme and the area of a template are written', PW,
  "\tconst TAXONOMIES = array( 'category', 'post_tag', 'post_format', 'wp_pattern_category', 'wp_theme', 'wp_template_part_area' );",
  "\tconst TAXONOMIES = array( 'category', 'post_tag', 'post_format', 'wp_pattern_category' );", 'it', NORMAL)
e('taxonomies-pattern', 'TAXONOMIES: the pattern categories of a reusable block are written', PW,
  "\tconst TAXONOMIES = array( 'category', 'post_tag', 'post_format', 'wp_pattern_category', 'wp_theme', 'wp_template_part_area' );",
  "\tconst TAXONOMIES = array( 'category', 'post_tag', 'post_format', 'wp_theme', 'wp_template_part_area' );", 'it', NAV)
e('identity-taxonomies', 'IDENTITY_TAXONOMIES: theme and area by their slugs, as the canonical form names them', PW,
  "\tconst IDENTITY_TAXONOMIES = array( 'post_format', 'wp_theme', 'wp_template_part_area' );",
  "\tconst IDENTITY_TAXONOMIES = array( 'post_format' );", 'it', NORMAL)
e('site-wide', 'post_write(): a type any page may show empties the page cache of the whole site', PW,
  "\t\t\t$whole  = ! is_string( $type ) || in_array( $type, self::SITE_WIDE, true );",
  "\t\t\t$whole  = ! is_string( $type );", 'it', NORMAL)
e('theme-json-cache', 'post_write(): the global styles empty the caches of theme.json (wp_clean_theme_json_cache())', PW,
  "\t\tif ( 'wp_global_styles' === $type && function_exists( 'wp_clean_theme_json_cache' ) ) {",
  "\t\tif ( false ) {", 'it', NORMAL)

# ------------------------------------------------------- the theme
e('theme-call', 'precheck(): a template, a template part and the global styles ask their theme', PW,
  "\t\tif ( in_array( $type, self::THEMED, true ) ) {\n\t\t\t$refusal = self::theme_refusal(",
  "\t\tif ( false ) {\n\t\t\t$refusal = self::theme_refusal(", 'it', LOCKS)
e('theme-plan', 'verdict(): the plan names a template of a theme that is not the active one locked (all leaves it out)', PU,
  "\t\t\tif ( null !== $other ) {\n\t\t\t\t$held = AB_MCP_Sync_Kinds::lock( 'post', 'theme_mismatch' );",
  "\t\t\tif ( false ) {\n\t\t\t\t$held = AB_MCP_Sync_Kinds::lock( 'post', 'theme_mismatch' );", 'it', LOCKS)
e('theme-active', 'inactive_theme(): the theme of the object is the active one here (post:theme_mismatch)', PW,
  "\t\tif ( $active !== $theme || $stored !== $theme ) {",
  "\t\tif ( $stored !== $theme ) {", 'it', FILTERED)
e('theme-stored', 'inactive_theme(): and the one the option row holds (a filter makes no active theme)', PW,
  "\t\tif ( $active !== $theme || $stored !== $theme ) {",
  "\t\tif ( $active !== $theme ) {", 'it', FILTERED)
e('theme-one', 'theme_refusal(): the target names that one theme', PW,
  "\t\tif ( array( $theme ) !== array_values( $targets ) ) {",
  "\t\tif ( false ) {", 'it', LOCKS)

# ---------------------------------------------- the names of the terms
e('named-staging', 'target_of(): a term of the theme or the area whose name is not its slug on staging waits (post:taxonomy_rule)', PW,
  "\t\t\t\tif ( in_array( $taxonomy, self::NAMED_TAXONOMIES, true ) && ( ! isset( $term['name'] ) || self::bytes( $term['name'] ) !== self::bytes( $term['slug'] ) ) ) {",
  "\t\t\t\tif ( false ) {", 'unit', U_LOCKS)
e('named-call', 'precheck(): the terms read by their names are asked here as well', PW,
  "\t\t$refusal = self::named_refusal( $prepared );",
  "\t\t$refusal = null;", 'it', LOCKS)
e('named-here', 'named_refusal(): a term here with that slug and another name waits (post:taxonomy_rule)', PW,
  "\t\t\t\tif ( is_object( $found ) && isset( $found->name ) && (string) $found->name !== (string) $slug ) {",
  "\t\t\t\tif ( false ) {", 'it', LOCKS)

# ---------------------------------------------------- the custom CSS
e('css-call', 'precheck(): the custom CSS of the global styles is asked (post:css_markup)', PW,
  "\t\tif ( 'wp_global_styles' === $type ) {\n\t\t\t$refusal = self::css_refusal(",
  "\t\tif ( false ) {\n\t\t\t$refusal = self::css_refusal(", 'it', LOCKS)
e('css-nested', 'css_values(): every key css at any depth of the JSON', PW,
  "\t\t\t} elseif ( is_array( $value ) && $depth < AB_MCP_Sync_Canon::MAX_DEPTH ) {",
  "\t\t\t} elseif ( false ) {", 'unit', U_CSS)
e('css-no-text', 'css_refusal(): a value of a key css that is no text locks', PW,
  "\t\t\tif ( ! is_string( $css ) ) {\n\t\t\t\treturn self::locked( 'post:css_markup',",
  "\t\t\tif ( false ) {\n\t\t\t\treturn self::locked( 'post:css_markup',", 'unit', U_CSS)
e('css-rule', 'css_refusal(): the rule of the site editor is asked of each value', PW,
  "\t\t\tif ( self::css_markup( $css ) ) {",
  "\t\t\tif ( false ) {", 'unit', U_CSS)
e('css-end', 'css_markup(): a text ending in a part of </style', PW,
  "\t\t\t\tif ( $remaining < 8 ) {\n\t\t\t\t\treturn true;",
  "\t\t\t\tif ( false ) {\n\t\t\t\t\treturn true;", 'it', CSS_CORE)
e('css-close', 'css_markup(): </style followed by a space, a slash or >', PW,
  "\t\t\t\tif ( 1 === strspn( $css, \" \\t\\f\\r\\n/>\", $at + 7, 1 ) ) {",
  "\t\t\t\tif ( 1 === strspn( $css, \">\", $at + 7, 1 ) ) {", 'it', CSS_CORE)
e('css-case', 'css_markup(): in any case', PW,
  "\t\t\tif ( 0 === substr_compare( $css, '</style', $at, min( 7, $remaining ), true ) ) {",
  "\t\t\tif ( 0 === substr_compare( $css, '</style', $at, min( 7, $remaining ), false ) ) {", 'it', CSS_CORE)

# --------------------------------------- the navigation without a menu
e('fallback-call', 'precheck(): a navigation asks whether it moves the one a block without a menu shows', PW,
  "\t\tif ( 'wp_navigation' === $type ) {\n\t\t\t$moved = self::fallback_refusal(",
  "\t\tif ( false ) {\n\t\t\t$moved = self::fallback_refusal(", 'it', LOCKS)
e('fallback-differs', 'fallback_moves(): the one shown before and after the write differ', PW,
  "\t\tif ( $was !== $is ) {",
  "\t\tif ( false ) {", 'it', LOCKS)
e('fallback-tie', 'fallback_moves(): a tie of the latest date with the object is not told (fail closed)', PW,
  "\t\tif ( 'tie' === $was || 'tie' === $is ) {",
  "\t\tif ( false ) {", 'it', BACK)
e('fallback-later', 'fallback_moves(): a later date than the latest of the others makes the object the one shown', PW,
  "\t\t\t\tif ( null === $top || strcmp( (string) $date, (string) $top['post_date'] ) > 0 ) {",
  "\t\t\t\tif ( null === $top ) {", 'it', BACK)
e('fallback-draft-way', 'precheck(): only a published navigation counts after the write (the way «as draft»)', PW,
  "\t\t\t\t'publish' === $status ? (string) $prepared['columns']['post_date'] : null,\n",
  "\t\t\t\t(string) $prepared['columns']['post_date'],\n", 'it', PENDING)
e('fallback-back', 'restore_row(): the way back of a navigation that would move it is left, check needed', PW,
  "\t\tif ( 'wp_navigation' === $backup['post_type'] ) {",
  "\t\tif ( false ) {", 'it', BACK)

e('css-back', 'restore_row(): the custom CSS of the global styles the way back writes keeps the rule as forward (Codex round 1, A)', PW,
  "\t\tif ( 'wp_global_styles' === $backup['post_type'] ) {\n\t\t\t$held = self::css_refusal(",
  "\t\tif ( false ) {\n\t\t\t$held = self::css_refusal(", 'it', CSS_BACK)

# ----------------------- the fallback navigation, where it is seen (round 1, B)
e('seen-gate', 'fallback_refusal(): a move of the fallback locks only where a block without a menu shows it', PW,
  "\t\treturn null === $shown ? null : $moved . '; ' . $shown;",
  "\t\treturn $moved;", 'it', SEEN)
e('seen-block', 'blocks_show_fallback(): a navigation block without ref and blocks of its own shows the fallback', PW,
  "\t\t\tif ( 'core/navigation' === $name && ! array_key_exists( 'ref', $attrs ) && ! array_key_exists( 'navigationMenuId', $attrs ) && array() === $inner ) {",
  "\t\t\tif ( false ) {", 'it', LOCKS)
e('seen-ref', 'blocks_show_fallback(): a navigation block with its ref has a menu of its own', PW,
  "\t\t\tif ( 'core/navigation' === $name && ! array_key_exists( 'ref', $attrs ) && ! array_key_exists( 'navigationMenuId', $attrs ) && array() === $inner ) {",
  "\t\t\tif ( 'core/navigation' === $name && ! array_key_exists( 'navigationMenuId', $attrs ) && array() === $inner ) {", 'it', SEEN)
e('seen-inner', 'blocks_show_fallback(): a navigation block with blocks of its own has a menu of its own', PW,
  "\t\t\tif ( 'core/navigation' === $name && ! array_key_exists( 'ref', $attrs ) && ! array_key_exists( 'navigationMenuId', $attrs ) && array() === $inner ) {",
  "\t\t\tif ( 'core/navigation' === $name && ! array_key_exists( 'ref', $attrs ) && ! array_key_exists( 'navigationMenuId', $attrs ) ) {", 'it', SEEN)
e('seen-part', 'blocks_show_fallback(): the template part a content names, as it shows', PW,
  "\t\t\t\t\tif ( self::shows_fallback( $text, $depth + 1, $state ) ) {",
  "\t\t\t\t\tif ( false ) {", 'it', LOCKS)
e('seen-part-run', 'blocks_show_fallback(): a template part the run writes in the version it writes', PW,
  "\t\t\t\t\tif ( isset( $state['parts'][ $part ] ) ) {",
  "\t\t\t\t\tif ( false ) {", 'it', SEEN)
e('seen-pattern', 'blocks_show_fallback(): the pattern a content names', PW,
  "\t\t\t\tif ( is_array( $pattern ) && isset( $pattern['content'] ) && self::shows_fallback( (string) $pattern['content'], $depth + 1, $state ) ) {",
  "\t\t\t\tif ( false ) {", 'it', LOCKS)
e('seen-inner-blocks', 'blocks_show_fallback(): a navigation block at any depth of inner blocks', PW,
  "\t\t\tif ( array() !== $inner && self::blocks_show_fallback( $inner, $depth, $state ) ) {",
  "\t\t\tif ( false ) {", 'it', SEEN)
e('seen-templates', 'fallback_shown(): the templates of the active theme here', PW,
  "\t\t\tif ( self::shows_fallback( (string) $template->content, 0, $state ) ) {",
  "\t\t\tif ( false ) {", 'it', LOCKS)
e('seen-templates-run', 'fallback_shown(): the templates the run writes', PW,
  "\t\t\tif ( $state['active'] === (string) ( $one['theme'] ?? '' ) && self::shows_fallback( (string) $one['content'], 0, $state ) ) {",
  "\t\t\tif ( false ) {", 'it', SEEN)
e('seen-posts', 'fallback_shown(): the posts here, by a bounded query', PW,
  "\t\t\tif ( self::shows_fallback( (string) $row['post_content'], 0, $state ) ) {",
  "\t\t\tif ( false ) {", 'it', SEEN)
e('seen-posts-run', 'fallback_shown(): the posts the run writes', PW,
  "\t\t\tif ( self::shows_pages( (string) $one['type'], (string) ( $one['status'] ?? '' ) ) && self::shows_fallback( (string) $one['content'], 0, $state ) ) {",
  "\t\t\tif ( false ) {", 'it', SEEN)
e('seen-planned', 'boundary(): a navigation is asked with the objects of the run as it writes them', EN,
  "\t\t$ctx['planned'] = self::planned( isset( $run['plan']['objects'] ) && is_array( $run['plan']['objects'] ) && 'wp_navigation' === (string) ( $item['subtype'] ?? '' ) ? $run['plan']['objects'] : array(), $lines );",
  "\t\t$ctx['planned'] = array();", 'it', SEEN)
e('seen-planned-check', 'run_check(): the check of a navigation knows the objects of the run as it writes them', EN,
  "\t\t$ctx['planned'] = self::planned( $items, $lines );\n", '', 'it', SEEN)

# ---------------------- the order of the references (round 1, B)
e('order-names', 'order(): a template part and a template another object names are edges', PU,
  "\t\t\tforeach ( array( 'parts', 'templates' ) as $list ) {",
  "\t\t\tforeach ( array() as $list ) {", 'it', CHAIN)
e('order-ids', 'order(): a reusable block and a navigation another object names by its ref are edges', PU,
  "\t\t\t\tif ( isset( $posts[ (int) $id ] ) ) {\n\t\t\t\t\t$targets[] = $posts[ (int) $id ];",
  "\t\t\t\tif ( false ) {\n\t\t\t\t\t$targets[] = $posts[ (int) $id ];", 'it', CHAIN)
e('order-create-dependency', 'order(): a target the run creates is a dependency', PU,
  "\t\t\t\tif ( 'create' === $items[ $m ]['op'] ) {\n\t\t\t\t\t$items[ $n ]['depends_on'][] = $m;",
  "\t\t\t\tif ( false ) {\n\t\t\t\t\t$items[ $n ]['depends_on'][] = $m;", 'it', CHAIN)
e('order-change-no-dependency', 'order(): a target the run changes comes first, no dependency', PU,
  "\t\t\t\tif ( 'create' === $items[ $m ]['op'] ) {\n\t\t\t\t\t$items[ $n ]['depends_on'][] = $m;",
  "\t\t\t\tif ( true ) {\n\t\t\t\t\t$items[ $n ]['depends_on'][] = $m;", 'it', CHAIN)
e('order-after', 'order(): an object comes after the targets it changes', PU,
  "\t\t\t\t\tforeach ( $with_after ? $item['after'] : array() as $m ) {",
  "\t\t\t\t\tforeach ( array() as $m ) {", 'it', CHAIN)
e('order-refs-theme', 'order_refs(): a template part without a theme is of the active one', PW,
  "\t\t\t\t$out['parts'][] = 'part:' . ( null === $ref['theme'] ? $active : (string) $ref['theme'] ) . '//' . (string) $ref['slug'];",
  "\t\t\t\t$out['parts'][] = 'part:' . (string) $ref['theme'] . '//' . (string) $ref['slug'];", 'it', CHAIN)
e('order-refs-ids', 'order_refs(): core/block and core/navigation by their ref', PW,
  "\t\t\t\t\t\t$out['posts'][] = $block['attrs']['ref'];\n", '', 'it', CHAIN)
e('order-refs-template', 'order_refs(): the template of a page', PW,
  "\t\t\t\t$out['templates'][] = 'template:' . self::bytes( $pair[1] );\n", '', 'it', PENDING)
e('order-name-part', 'order_name(): a template part by theme//slug', PW,
  "\t\t\treturn 'part:' . ( isset( $line['identity']['theme'] ) && is_string( $line['identity']['theme'] ) ? $line['identity']['theme'] : '' ) . '//' . $slug;",
  "\t\t\treturn null;", 'it', CHAIN)
e('order-name-template', 'order_name(): a template by its slug', PW,
  "\t\treturn 'wp_template' === $type ? 'template:' . $slug : null;",
  "\t\treturn null;", 'it', PENDING)

# ------------------------------------------ template parts by name
e('parts-call', 'target_of(): the template parts a content names are asked', PW,
  "\t\t$held = self::parts_refusal( self::part_refs( $content['content'] ), isset( $ctx['pending_named'] ) && is_array( $ctx['pending_named'] ) ? $ctx['pending_named'] : array() );",
  "\t\t$held = null;", 'it', LOCKS)
e('parts-walk', 'part_refs(): a part named at any depth', PW,
  "\n\t\t\t\tif ( isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) && $depth < AB_MCP_Sync_Canon::MAX_DEPTH ) {\n\t\t\t\t\t$walk( $block['innerBlocks'], $depth + 1 );\n",
  "\n\t\t\t\tif ( false ) {\n\t\t\t\t\t$walk( $block['innerBlocks'], $depth + 1 );\n", 'it', LOCKS)
e('parts-theme-default', 'part_refs(): a part without a theme is of the active theme; one with a theme keeps it', PW,
  "\t\t\t\t\t\t'theme' => isset( $block['attrs']['theme'] ) && is_string( $block['attrs']['theme'] ) ? $block['attrs']['theme'] : null,",
  "\t\t\t\t\t\t'theme' => null,", 'it', LOCKS)
e('parts-theme', 'parts_refusal(): a part of a theme that is not the active one shows nothing', PW,
  "\t\t\tif ( $theme !== $active ) {",
  "\t\t\tif ( false ) {", 'it', LOCKS)
e('parts-pending', 'parts_refusal(): a part the same run creates first is pending', PW,
  "\t\t\tif ( isset( $pending['wp_template_part'][ $name ] ) ) {",
  "\t\t\tif ( false ) {", 'it', PENDING)
e('parts-row', 'parts_refusal(): a published part of the theme and slug here', PW,
  "\t\t\tif ( $row > 0 ) {",
  "\t\t\tif ( false ) {", 'it', PENDING)
e('parts-file', 'parts_refusal(): a part of the active theme as a file', PW,
  "\t\t\tif ( is_object( $file ) && isset( $file->content ) && '' !== (string) $file->content ) {",
  "\t\t\tif ( false ) {", 'it', PENDING)
e('parts-row-theme', 'part_row(): the part of that theme, by the name of its term, as the core asks', PW,
  "AND x.taxonomy = 'wp_theme' AND t.name = %s ORDER BY p.ID ASC LIMIT 1\", (string) $slug, (string) $theme ) );",
  "AND x.taxonomy = 'wp_theme' AND t.name != %s ORDER BY p.ID ASC LIMIT 1\", (string) $slug, (string) $theme ) );", 'it', PENDING)
e('pending-named', 'run_check(): the templates and template parts the run creates are pending by their names', EN,
  "\t\t$ctx['pending_named'] = self::pending_named( $items, $lines );\n", '', 'it', PENDING)
e('pending-named-part', 'pending_named(): a template part by theme//slug', EN,
  "'wp_template' === $one['subtype'] ? $line['identity']['post_name'] : $line['identity']['theme'] . '//' . $line['identity']['post_name'];",
  "$line['identity']['post_name'];", 'it', PENDING)
e('template-pending', 'target_of(): a custom template the same run creates first is pending for page_template', PW,
  "\t\t$template_pending = isset( $postarr['page_template'], $ctx['pending_named']['wp_template'] ) && is_array( $ctx['pending_named']['wp_template'] ) && isset( $ctx['pending_named']['wp_template'][ (string) $postarr['page_template'] ] );",
  "\t\t$template_pending = false;", 'it', PENDING)
e('template-pending-skip', 'precheck(): the offer of a pending template is asked at the boundary', PW,
  " && 'default' !== $prepared['postarr']['page_template'] && empty( $prepared['template_pending'] ) ) {",
  " && 'default' !== $prepared['postarr']['page_template'] ) {", 'it', PENDING)
e('named-first', 'order(): template parts, then templates, before the other objects', PU,
  "\t\t\t\t\t$named = array_search( (string) ( $items[ $n ]['subtype'] ?? '' ), self::NAMED_FIRST, true );",
  "\t\t\t\t\t$named = false;", 'it', PENDING)

# --------------------------------------------- meta of a reusable block
e('auth-follows', 'new_meta_allowed(): a key registered without an auth_callback follows the right at the post', PW,
  "\t\treturn self::auth_follows_post( \"auth_post_meta_{$key}_for_{$type}\" ) && self::auth_follows_post( \"auth_post_meta_{$key}\" );",
  "\t\treturn ! has_filter( \"auth_post_meta_{$key}_for_{$type}\" ) && ! has_filter( \"auth_post_meta_{$key}\" );", 'it', NAV)
e('auth-only-true', 'auth_follows_post(): any callback but __return_true answers at the object only', PW,
  "\t\t\t\tif ( ! is_array( $one ) || ! isset( $one['function'] ) || '__return_true' !== $one['function'] ) {",
  "\t\t\t\tif ( ! is_array( $one ) ) {", 'unit', U_META)
e('auth-unseen', 'auth_follows_post(): a hook this request does not show as WordPress keeps it fails closed', PW,
  "\t\tif ( ! isset( $hooks[ $hook ] ) || ! is_object( $hooks[ $hook ] ) || ! isset( $hooks[ $hook ]->callbacks ) || ! is_array( $hooks[ $hook ]->callbacks ) ) {\n\t\t\treturn false;",
  "\t\tif ( ! isset( $hooks[ $hook ] ) || ! is_object( $hooks[ $hook ] ) || ! isset( $hooks[ $hook ]->callbacks ) || ! is_array( $hooks[ $hook ]->callbacks ) ) {\n\t\t\treturn true;", 'unit', U_META)

# ----------------------------------------------- the sample of a run
e('sample-viewable', 'viewable(): the sample is an object of a type with a page of its own', EN,
  "\t\treturn is_string( $type ) && is_post_type_viewable( $type );",
  "\t\treturn is_string( $type );", 'it', NORMAL)


def test_of(expect):
    return expect.split(' with data set "', 1)[0]


ids = set()
problems = []
for (id_, clause, file, search, replace, run, expect) in entries:
    if id_ in ids:
        problems.append('duplicate id ' + id_)
    ids.add(id_)
    text = open(os.path.join(ROOT, file)).read()
    n = text.count(search)
    if n != 1:
        problems.append('%s: search occurs %d times in %s:\n%s' % (id_, n, file, search))
    if search == replace:
        problems.append(id_ + ': the replacement is the search')
    if run not in RUNS:
        problems.append(id_ + ': no run ' + run)
        continue
    suite, cls = RUNS[run]
    path = os.path.join(ROOT, 'tests', 'integration' if 'it' == suite else '', cls + '.php')
    if not re.search(r'\bfunction\s+' + re.escape(test_of(expect)) + r'\s*\(', open(path).read()):
        problems.append('%s: %s is no test of %s' % (id_, test_of(expect), path))
if problems:
    sys.exit('\n'.join(problems))


def php(s):
    return '"' + s.replace('\\', '\\\\').replace('"', '\\"').replace('$', '\\$').replace('\t', '\\t').replace('\n', '\\n') + '"'


def php_single(s):
    return "'" + s.replace('\\', '\\\\').replace("'", "\\'") + "'"


def regex(name):
    q = lambda t: re.sub(r'([\\/.^$|?*+()\[\]{}#])', r'\\\1', t)
    return '/' + q(name) + '/'


out = []
out.append('''<?php
/**
 * The clauses of Z8 branch 9b (heikel/z8-vorlagen): the other core types of
 * the kind post (decision 25), written by AB_MCP_Sync_Post_Writer: the
 * types and taxonomies it writes, the theme of a template, a template part
 * and the global styles (post:theme_mismatch), the terms read by their
 * names, the custom CSS of the global styles (post:css_markup), the
 * navigation a block without a menu shows (post:navigation_fallback,
 * forward and back), the template parts a content names by theme and slug
 * (post:reference_unmapped, pending), a custom template a page uses, the
 * order of the plan, meta registered without an auth_callback, the caches
 * of phase 7 and the sample of phase 8.
 *
 * Written by tests/mutation/gen/vorlagen.py from the source; change the
 * entries there and run it again.
 *
 * Held by the integration suite (SyncTemplatesTest; the sites running,
 * AB_IT_ROOT set) and the unit suite (SyncRunTest). Not listed: the texts
 * of refusals, and what GRENZEN-INHALTE.md names as not listed.
 *
 * @package AlphaBridge_MCP
 */

$unit = static fn( string $class, string $test ): array => array( 'vendor/bin/phpunit', '--no-progress', '--filter', '/[^A-Za-z0-9_]' . $class . '::' . $test . '( |$)/' );
$it   = static fn( string $class, string $test ): array => array( 'vendor/bin/phpunit', '--no-progress', '-c', 'tests/integration/phpunit.xml.dist', '--filter', '/[^A-Za-z0-9_]' . $class . '::' . $test . '( |$)/' );

$pw = 'includes/sync/class-sync-post-writer.php';
$en = 'includes/sync/class-sync-engine.php';
$pu = 'includes/sync/class-sync-push.php';

return array(''')
for (id_, clause, file, search, replace, run, expect) in entries:
    out.append('\tarray(')
    out.append("\t\t'id'      => %s," % php_single(id_))
    out.append("\t\t'clause'  => %s," % php_single(clause))
    out.append("\t\t'file'    => %s," % FILEVAR[file])
    out.append("\t\t'search'  => %s," % php(search))
    out.append("\t\t'replace' => %s," % php(replace))
    suite, cls = RUNS[run]
    out.append("\t\t'run'     => $%s( %s, %s )," % (suite, php_single(cls), php_single(test_of(expect))))
    out.append("\t\t'expect'  => %s," % php_single(regex(expect)))
    out.append('\t),')
out.append(');')
open(os.path.join(ROOT, 'tests/mutation/lists/vorlagen.php'), 'w').write('\n'.join(out) + '\n')
print(len(entries), 'entries')

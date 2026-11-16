<?php
/**
 * A part of AB IT Aktiv 2.0.0 that PHP runs without the extension .php
 * (Codex D1, finding 1): the swap clears OPcache for it like for every file
 * PHP runs (AB_MCP_Sync_Code_Stage::is_php()), and gives its folder the
 * rights of a folder of the site.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

$GLOBALS['ab_it_teil']['ab-it-aktiv'] = '2.0.0';

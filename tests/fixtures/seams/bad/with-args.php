<?php
// SeamGuardTest: breaks one rule of AB_MCP_Test_Seam_Guard (arguments to the calls class). Never loaded.
final class Seam_Bad_Args { /** @ab-mcp-seam */ private static $calls = null; private static function calls() { self::$calls = new Seam_Calls( get_option( "seam_mode" ) ); return self::$calls; } }

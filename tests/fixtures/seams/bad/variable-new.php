<?php
// SeamGuardTest: breaks one rule of AB_MCP_Test_Seam_Guard (a variable names the class). Never loaded.
final class Seam_Bad_Variable_New { /** @ab-mcp-seam */ private static $calls = null; private static function calls() { $class = defined( "SEAM_CALLS" ) ? SEAM_CALLS : "Seam_Calls"; self::$calls = new $class(); return self::$calls; } }

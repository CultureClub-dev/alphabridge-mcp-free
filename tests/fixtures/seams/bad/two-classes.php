<?php
// SeamGuardTest: breaks one rule of AB_MCP_Test_Seam_Guard (two classes). Never loaded.
final class Seam_Bad_Two { /** @ab-mcp-seam */ private static $calls = null; private static function calls() { self::$calls = new Seam_Calls(); return self::$calls; } public static function other() { static::$calls = new Seam_Other_Calls(); } }

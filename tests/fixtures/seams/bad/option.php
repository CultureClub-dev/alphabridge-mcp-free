<?php
// SeamGuardTest: breaks one rule of AB_MCP_Test_Seam_Guard (an option chooses). Never loaded.
final class Seam_Bad_Option { /** @ab-mcp-seam */ private static $calls = null; private static function calls() { self::$calls ??= get_option( "seam_fake" ) ? new Seam_Fake() : new Seam_Calls(); return self::$calls; } }

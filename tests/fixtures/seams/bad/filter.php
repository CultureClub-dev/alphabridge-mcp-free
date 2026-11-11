<?php
// SeamGuardTest: breaks one rule of AB_MCP_Test_Seam_Guard (a filter chooses). Never loaded.
final class Seam_Bad_Filter { /** @ab-mcp-seam */ private static $calls = null; private static function calls() { if ( null === self::$calls ) { self::$calls = apply_filters( "seam_calls", new Seam_Calls() ); } return self::$calls; } }

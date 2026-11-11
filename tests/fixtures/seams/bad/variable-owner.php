<?php
// SeamGuardTest: breaks one rule of AB_MCP_Test_Seam_Guard (writes through a variable class). Never loaded.
final class Seam_Bad_Variable_Owner { /** @ab-mcp-seam */ private static $calls = null; public static function open( $class ) { $class::$calls = null; } }

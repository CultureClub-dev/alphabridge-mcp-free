<?php
// SeamGuardTest: a variable names the class. Never loaded.
final class Seam_Bad_Variable_New { /** @ab-mcp-seam */ private static $calls = null; private static function calls() { $class = defined( "SEAM_CALLS" ) ? SEAM_CALLS : "Seam_Calls"; return self::$calls ?? new $class(); } }

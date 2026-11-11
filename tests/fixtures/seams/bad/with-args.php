<?php
// SeamGuardTest: arguments to the calls class. Never loaded.
final class Seam_Bad_Args { /** @ab-mcp-seam */ private static $calls = null; private static function calls() { return self::$calls ?? new Seam_Calls( get_option( "seam_mode" ) ); } }

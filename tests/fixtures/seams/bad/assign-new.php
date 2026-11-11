<?php
// SeamGuardTest: set to a new object outside the accessor. Never loaded.
final class Seam_Bad_Assign_New { /** @ab-mcp-seam */ private static $calls = null; private static function calls() { if ( null === self::$calls ) { self::$calls = new Seam_Calls(); } return self::$calls; } }

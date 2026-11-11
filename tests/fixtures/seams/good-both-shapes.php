<?php
// SeamGuardTest: the two allowed statements, nothing else (passes). Never loaded.
final class Seam_Good_Both { /** @ab-mcp-seam */ private static $calls; private static function calls() { return self::$calls ?? new \Seam_Calls(); } public static function forget() { self::$calls = NULL; } }

<?php
// SeamGuardTest: two classes. Never loaded.
final class Seam_Bad_Two { /** @ab-mcp-seam */ private static $calls = null; private static function calls() { return self::$calls ?? new Seam_Calls(); } private static function other() { return self::$calls ?? new Seam_Other_Calls(); } }

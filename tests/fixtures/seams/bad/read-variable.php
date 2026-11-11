<?php
// SeamGuardTest: read into a variable. Never loaded.
final class Seam_Bad_Read { /** @ab-mcp-seam */ private static $calls = null; public static function peek() { $c = self::$calls; return $c; } }

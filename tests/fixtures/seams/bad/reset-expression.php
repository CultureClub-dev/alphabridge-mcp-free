<?php
// SeamGuardTest: the reset inside an expression. Never loaded.
final class Seam_Bad_Reset_Expression { /** @ab-mcp-seam */ private static $calls = null; public static function swap_out() { $old = self::$calls = null; return $old; } }

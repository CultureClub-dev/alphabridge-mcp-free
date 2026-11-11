<?php
// SeamGuardTest: a filter sets the seam. Never loaded.
final class Seam_Bad_Filter_Set { /** @ab-mcp-seam */ private static $calls = null; public static function open() { self::$calls = apply_filters( "seam_calls", null ); } }

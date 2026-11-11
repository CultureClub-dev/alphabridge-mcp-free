<?php
// SeamGuardTest: a transient sets the seam. Never loaded.
final class Seam_Bad_Transient { /** @ab-mcp-seam */ private static $calls = null; public static function open() { self::$calls = get_transient( "seam_calls" ); } }

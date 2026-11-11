<?php
// SeamGuardTest: a site option sets the seam. Never loaded.
final class Seam_Bad_Site_Option { /** @ab-mcp-seam */ private static $calls = null; public static function open() { self::$calls = get_site_option( "seam_calls" ); } }

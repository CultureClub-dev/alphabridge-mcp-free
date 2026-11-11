<?php
// SeamGuardTest: an option chooses around the seam. Never loaded.
final class Seam_Bad_Option { /** @ab-mcp-seam */ private static $calls = null; private static function calls() { return get_option( "seam_fake" ) ? new Seam_Fake() : ( self::$calls ?? new Seam_Calls() ); } }

<?php
// SeamGuardTest: an option chooses around the seam. Never loaded.
final class Seam_Bad_Option { private static $naht_bad_option = null; private static function calls() { return get_option( "seam_fake" ) ? new Seam_Fake() : ( self::$naht_bad_option ?? new Seam_Calls() ); } }

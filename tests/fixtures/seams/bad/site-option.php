<?php
// SeamGuardTest: a site option sets the seam. Never loaded.
final class Seam_Bad_Site_Option { private static $naht_bad_site_option = null; public static function open() { self::$naht_bad_site_option = get_site_option( "seam_calls" ); } }

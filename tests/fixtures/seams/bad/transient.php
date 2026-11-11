<?php
// SeamGuardTest: a transient sets the seam. Never loaded.
final class Seam_Bad_Transient { private static $naht_bad_transient = null; public static function open() { self::$naht_bad_transient = get_transient( "seam_calls" ); } }

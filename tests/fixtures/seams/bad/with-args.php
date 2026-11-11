<?php
// SeamGuardTest: arguments to the calls class. Never loaded.
final class Seam_Bad_Args { private static $naht_bad_args = null; private static function calls() { return self::$naht_bad_args ?? new Seam_Calls( get_option( "seam_mode" ) ); } }

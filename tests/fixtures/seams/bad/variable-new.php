<?php
// SeamGuardTest: a variable names the class. Never loaded.
final class Seam_Bad_Variable_New { private static $naht_bad_variable_new = null; private static function calls() { $class = defined( "SEAM_CALLS" ) ? SEAM_CALLS : "Seam_Calls"; return self::$naht_bad_variable_new ?? new $class(); } }

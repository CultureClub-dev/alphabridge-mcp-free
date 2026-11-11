<?php
// SeamGuardTest: set to a new object outside the accessor. Never loaded.
final class Seam_Bad_Assign_New { private static $naht_bad_assign_new = null; private static function calls() { if ( null === self::$naht_bad_assign_new ) { self::$naht_bad_assign_new = new Seam_Calls(); } return self::$naht_bad_assign_new; } }

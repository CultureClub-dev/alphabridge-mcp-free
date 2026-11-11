<?php
// SeamGuardTest: the two allowed statements, nothing else (passes). Never loaded.
final class Seam_Good_Both { private static $naht_good_both; private static function calls() { return self::$naht_good_both ?? new \Seam_Calls(); } public static function forget() { self::$naht_good_both = NULL; } }

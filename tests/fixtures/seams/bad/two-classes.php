<?php
// SeamGuardTest: two classes. Never loaded.
final class Seam_Bad_Two { private static $naht_bad_two = null; private static function calls() { return self::$naht_bad_two ?? new Seam_Calls(); } private static function other() { return self::$naht_bad_two ?? new Seam_Other_Calls(); } }

<?php
// SeamGuardTest: a second class in the file of the seam uses self:: in an allowed shape. Never loaded.
final class Seam_Bad_Neighbour_Owner { private static $naht_bad_neighbour = null; private static function calls() { return self::$naht_bad_neighbour ?? new Seam_Calls(); } } final class Seam_Bad_Neighbour { public static function forget() { self::$naht_bad_neighbour = null; } }

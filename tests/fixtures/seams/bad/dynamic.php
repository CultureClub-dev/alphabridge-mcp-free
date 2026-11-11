<?php
// SeamGuardTest: a dynamic static property with the name as a string. Never loaded.
final class Seam_Bad_Dynamic { private static $naht_bad_dynamic = null; public static function open( $v ) { self::${"naht_bad_dynamic"} = $v; } }

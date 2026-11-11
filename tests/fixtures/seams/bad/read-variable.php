<?php
// SeamGuardTest: read into a variable. Never loaded.
final class Seam_Bad_Read { private static $naht_bad_read = null; public static function peek() { $c = self::$naht_bad_read; return $c; } }

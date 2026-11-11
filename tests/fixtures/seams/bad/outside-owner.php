<?php
// SeamGuardTest: the owner of a seam reached from outside. Never loaded.
final class Seam_Bad_Outside_Owner { private static $naht_bad_outside = null; private static function calls() { return self::$naht_bad_outside ?? new Seam_Calls(); } }

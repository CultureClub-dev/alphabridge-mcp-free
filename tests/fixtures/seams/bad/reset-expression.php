<?php
// SeamGuardTest: the reset inside an expression. Never loaded.
final class Seam_Bad_Reset_Expression { private static $naht_bad_reset_expression = null; public static function swap_out() { $old = self::$naht_bad_reset_expression = null; return $old; } }

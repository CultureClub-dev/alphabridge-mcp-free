<?php
// SeamGuardTest: a filter sets the seam. Never loaded.
final class Seam_Bad_Filter_Set { private static $naht_bad_filter_set = null; public static function open() { self::$naht_bad_filter_set = apply_filters( "seam_calls", null ); } }

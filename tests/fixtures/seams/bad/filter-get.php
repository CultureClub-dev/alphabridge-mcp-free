<?php
// SeamGuardTest: a filter returns the seam (Codex round 1, A5). Never loaded.
final class Seam_Bad_Filter_Get { private static $naht_bad_filter_get = null; private static function calls() { return apply_filters( "replace_calls", self::$naht_bad_filter_get ?? new Seam_Calls() ); } }

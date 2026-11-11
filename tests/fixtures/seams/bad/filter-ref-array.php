<?php
// SeamGuardTest: apply_filters_ref_array around the seam. Never loaded.
final class Seam_Bad_Filter_Ref { private static $naht_bad_filter_ref = null; private static function calls() { return apply_filters_ref_array( "replace_calls", array( self::$naht_bad_filter_ref ) ); } }

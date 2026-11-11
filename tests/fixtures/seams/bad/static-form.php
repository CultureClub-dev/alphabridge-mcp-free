<?php
// SeamGuardTest: through static::. Never loaded.
final class Seam_Bad_Static_Form { private static $naht_bad_static_form = null; private static function calls() { return static::$naht_bad_static_form ?? new Seam_Calls(); } }

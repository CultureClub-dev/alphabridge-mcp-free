<?php
// SeamGuardTest: lazily set with ??=. Never loaded.
final class Seam_Bad_Lazy { private static $naht_bad_lazy = null; private static function calls() { return self::$naht_bad_lazy ??= new Seam_Calls(); } }

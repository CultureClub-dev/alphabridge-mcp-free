<?php
// SeamGuardTest: the name in a comment. Never loaded.
final class Seam_Bad_Comment { private static $naht_bad_comment = null; /* Tests set naht_bad_comment through Reflection. */ private static function calls() { return self::$naht_bad_comment ?? new Seam_Calls(); } }

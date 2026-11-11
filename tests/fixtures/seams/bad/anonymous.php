<?php
// SeamGuardTest: an anonymous class as the calls (six tokens like new X()). Never loaded.
final class Seam_Bad_Anonymous { private static $naht_bad_anonymous = null; private static function calls() { return self::$naht_bad_anonymous ?? new class {}; } }

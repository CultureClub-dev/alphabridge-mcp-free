<?php
// SeamGuardTest: the owner of a seam a bound closure writes. Never loaded.
final class Seam_Bad_Closure_Owner { /** @ab-mcp-seam */ private static $naht_bad_closure = null; private static function calls() { return self::$naht_bad_closure ?? new Seam_Calls(); } }

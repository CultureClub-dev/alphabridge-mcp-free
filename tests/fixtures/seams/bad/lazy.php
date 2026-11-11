<?php
// SeamGuardTest: lazily set with ??=. Never loaded.
final class Seam_Bad_Lazy { /** @ab-mcp-seam */ private static $calls = null; private static function calls() { return self::$calls ??= new Seam_Calls(); } }

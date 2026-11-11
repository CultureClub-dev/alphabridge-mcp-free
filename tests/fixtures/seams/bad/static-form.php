<?php
// SeamGuardTest: through static::. Never loaded.
final class Seam_Bad_Static_Form { /** @ab-mcp-seam */ private static $calls = null; private static function calls() { return static::$calls ?? new Seam_Calls(); } }

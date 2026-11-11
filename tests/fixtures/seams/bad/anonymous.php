<?php
// SeamGuardTest: an anonymous class as the calls (six tokens like new X()). Never loaded.
final class Seam_Bad_Anonymous { /** @ab-mcp-seam */ private static $calls = null; private static function calls() { return self::$calls ?? new class {}; } }

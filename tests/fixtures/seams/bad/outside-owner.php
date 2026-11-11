<?php
// SeamGuardTest: the owner of a seam reached from outside. Never loaded.
final class Seam_Bad_Outside_Owner { /** @ab-mcp-seam */ private static $calls = null; private static function calls() { return self::$calls ?? new Seam_Calls(); } }

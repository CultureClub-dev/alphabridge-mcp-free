<?php
// SeamGuardTest: a filter returns the seam (Codex round 1, A5). Never loaded.
final class Seam_Bad_Filter_Get { /** @ab-mcp-seam */ private static $calls = null; private static function calls() { return apply_filters( "replace_calls", self::$calls ?? new Seam_Calls() ); } }

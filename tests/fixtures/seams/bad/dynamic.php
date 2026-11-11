<?php
// SeamGuardTest: a dynamic static property. Never loaded.
final class Seam_Bad_Dynamic { /** @ab-mcp-seam */ private static $calls = null; public static function open( $name, $v ) { self::${"calls"} = $v; static::$$name = $v; } }

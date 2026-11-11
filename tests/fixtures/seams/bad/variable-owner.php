<?php
// SeamGuardTest: through a variable class. Never loaded.
final class Seam_Bad_Variable_Owner { /** @ab-mcp-seam */ private static $calls = null; public static function open( $class ) { $class::$calls = null; } }

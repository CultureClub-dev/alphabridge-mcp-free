<?php
// SeamGuardTest: a lower-case reflectionproperty (Behebung 2). Never loaded.
final class Seam_Bad_Lower { /** @ab-mcp-seam */ private static $naht_bad_lower = null; public static function open( $value ) { ( new \reflectionproperty( self::class, "naht_bad_lower" ) )->setvalue( null, $value ); } }

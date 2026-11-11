<?php
// SeamGuardTest: the full name as a string (Behebung 2). Never loaded.
final class Seam_Bad_String { /** @ab-mcp-seam */ private static $naht_bad_string = null; public static function open( $object ) { $a = $object->{"naht_bad_string"}; $b = ( new \reflectionclass( self::class ) )->getstaticpropertyvalue( "NAHT_BAD_STRING" ); $c = property_exists( self::class, "naht_bad_string" ); return array( $a, $b, $c ); } }

<?php
// SeamGuardTest: a Reflection setter (Codex round 1, A5). Never loaded.
final class Seam_Bad_Reflection_Setter { private static $naht_bad_reflection_setter = null; public static function open( $value ) { ( new ReflectionClass( self::class ) )->getProperty( "naht_bad_reflection_setter" )->setValue( null, $value ); } }

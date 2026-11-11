<?php
// SeamGuardTest: a Reflection setter (Codex round 1, A5). Never loaded.
final class Seam_Bad_Reflection_Setter { /** @ab-mcp-seam */ private static $calls = null; public static function open( $value ) { ( new ReflectionClass( self::class ) )->getProperty( "calls" )->setValue( null, $value ); } }

<?php
// SeamGuardTest: breaks one rule of AB_MCP_Test_Seam_Guard (reaches past private). Never loaded.
final class Seam_Bad_Reflection { public static function open() { $p = new ReflectionProperty( "X", "calls" ); ( new ReflectionClass( "X" ) )->setStaticPropertyValue( "calls", null ); $f = Closure::bind( function () {}, null, "X" ); $g = $f->bindTo( null, "X" ); } }

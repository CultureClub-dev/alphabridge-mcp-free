<?php
// SeamGuardTest: every other way past private. Never loaded.
final class Seam_Bad_Reflection { public static function open( $v ) { $p = new ReflectionProperty( "X", "calls" ); $p->setAccessible( true ); ( new ReflectionClass( "X" ) )->setStaticPropertyValue( "calls", $v ); $all = ( new ReflectionClass( "X" ) )->getStaticProperties(); $one = ( new ReflectionClass( "X" ) )->getStaticPropertyValue( "calls" ); $list = ( new ReflectionObject( $v ) )->getProperties(); $f = Closure::bind( function () {}, null, "X" ); $g = $f->bindTo( null, "X" ); $h = $f->call( $v ); } }

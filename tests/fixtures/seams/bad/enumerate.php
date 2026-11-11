<?php
// SeamGuardTest: Reflection that reaches properties without naming them. Never loaded.
final class Seam_Bad_Enumerate { public static function open( $v, $n ) { foreach ( ( new ReflectionClass( "X" ) )->getProperties() as $p ) { $p->setValue( null, $v ); } $all = ( new ReflectionClass( "X" ) )->GetStaticProperties(); ( new ReflectionClass( "X" ) )->setStaticPropertyValue( $n, $v ); $one = ( new ReflectionClass( "X" ) )->getstaticpropertyvalue( $n ); } }

<?php
// SeamGuardTest: a closure called on an object of the class of the seam (Behebung 2). Never loaded.
final class Seam_Bad_Closure_Caller { public static function open( $object, $value ) { ( function () use ( $value ) { self::$naht_bad_closure = $value; } )->Call( $object ); } }

<?php
// SeamGuardTest: a closure bound to the class of the seam (Behebung 2). Never loaded.
final class Seam_Bad_Closure_Binder { public static function open( $value ) { $f = \closure::bind( static function () use ( $value ) { self::$naht_bad_closure = $value; }, null, Seam_Bad_Closure_Owner::class ); $f(); } }

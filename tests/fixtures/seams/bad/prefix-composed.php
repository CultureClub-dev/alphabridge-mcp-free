<?php
// SeamGuardTest: a name put together from the prefix. Never loaded.
final class Seam_Bad_Composed { public static function open( $suffix, $v ) { $name = "Naht_" . $suffix; $other = sprintf( "naht_%s", $suffix ); return array( $name, $other ); } }

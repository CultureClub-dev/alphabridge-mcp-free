<?php
// SeamGuardTest: through a variable class. Never loaded.
final class Seam_Bad_Variable_Owner { private static $naht_bad_variable_owner = null; public static function open( $class ) { $class::$naht_bad_variable_owner = null; } }

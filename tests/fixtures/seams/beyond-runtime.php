<?php
// SeamGuardTest: G9, a name put together at run time without the prefix written out: beyond the guard. Never loaded.
final class Seam_Beyond_Runtime { public static function open( $value ) { $name = "na" . "ht_beyond"; Seam_Beyond_Owner::$$name = $value; } }

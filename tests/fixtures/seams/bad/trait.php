<?php
// SeamGuardTest: a trait the class uses writes the seam (Behebung 2). Never loaded.
trait Seam_Bad_Trait_Writer { public static function open( $value ) { self::$naht_bad_trait = $value; } } final class Seam_Bad_Trait_Owner { use Seam_Bad_Trait_Writer; /** @ab-mcp-seam */ private static $naht_bad_trait = null; private static function calls() { return self::$naht_bad_trait ?? new Seam_Calls(); } }

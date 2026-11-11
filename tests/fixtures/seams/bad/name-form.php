<?php
// SeamGuardTest: through its class name. Never loaded.
final class Seam_Bad_Name_Form { /** @ab-mcp-seam */ private static $calls = null; private static function calls() { return Seam_Bad_Name_Form::$calls ?? new Seam_Calls(); } }

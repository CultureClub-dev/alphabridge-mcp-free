<?php
// SeamGuardTest: breaks one rule of AB_MCP_Test_Seam_Guard (writes the seam of another class). Never loaded.
final class Seam_Bad_Outside_Writer { public static function open() { Seam_Bad_Outside_Owner::$calls = new Seam_Calls(); } }

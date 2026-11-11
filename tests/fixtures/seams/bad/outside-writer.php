<?php
// SeamGuardTest: reaches the seam of another class. Never loaded.
final class Seam_Bad_Outside_Writer { public static function open() { Seam_Bad_Outside_Owner::$calls = null; } }

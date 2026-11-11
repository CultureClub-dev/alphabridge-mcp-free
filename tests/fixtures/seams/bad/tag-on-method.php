<?php
// SeamGuardTest: breaks one rule of AB_MCP_Test_Seam_Guard (tag on no property). Never loaded.
final class Seam_Bad_Tag { /** @ab-mcp-seam */ public static function calls() { return null; } }

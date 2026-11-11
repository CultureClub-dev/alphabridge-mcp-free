<?php
// SeamGuardTest: breaks one rule of AB_MCP_Test_Seam_Guard (owner of a seam written from outside). Never loaded.
final class Seam_Bad_Outside_Owner { /** @ab-mcp-seam */ private static $calls = null; }

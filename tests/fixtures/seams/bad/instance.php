<?php
// SeamGuardTest: breaks one rule of AB_MCP_Test_Seam_Guard (not static). Never loaded.
final class Seam_Bad_Instance { /** @ab-mcp-seam */ private $calls = null; }

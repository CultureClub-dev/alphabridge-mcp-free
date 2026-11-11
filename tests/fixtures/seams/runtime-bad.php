<?php
/**
 * Seams declared against the rules, for AB_MCP_Test_Seam::property()
 * (TestSeamTest): the helper refuses each. Test code only.
 *
 * @package AlphaBridge_MCP
 */

final class AB_MCP_Test_Bad_Public {
	/** @var object|null Public: any code could set it. */
	public static $calls = null;
}

final class AB_MCP_Test_Bad_Instance {
	/** @var object|null Not static. */
	private $calls = null;
}

final class AB_MCP_Test_Bad_Typed {
	/** @var AB_MCP_Test_Example_Calls|null Typed: no stand-in fits. */
	private static ?AB_MCP_Test_Example_Calls $calls = null;
}

<?php
/**
 * An import of a class under a name of the file's own, which the walk of
 * StoreRemoverGuardTest does not follow and stops at. Never loaded.
 *
 * @package AlphaBridge_MCP
 */

use AB_MCP_Payload_File as Datei;

Datei::write( 'a' );

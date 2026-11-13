<?php
/**
 * The clauses of the swap of the code of the site (Z8b branch 5: the kind
 * code with the rights of E10 at Z26 and its place in the registry for Z38).
 * Written by tests/mutation/gen/tausch.py from the source; change the entries
 * there and run it again.
 *
 * Held by the unit suite (SyncCodeKindTest).
 *
 * Not listed, with why (bericht-zweig-5.md), equivalent: in rights() the
 * count of the two halves of a unit beside the kind of unit (a ref without a
 * colon has one half, whose kind is then the whole ref and no kind of UNITS).
 *
 * @package AlphaBridge_MCP
 */

$unit = static fn( string $class, string $test ): array => array( 'vendor/bin/phpunit', '--no-progress', '--filter', '/[^A-Za-z0-9_]' . $class . '::' . $test . '( |$)/' );
$it   = static fn( string $class, string $test ): array => array( 'vendor/bin/phpunit', '--no-progress', '-c', 'tests/integration/phpunit.xml.dist', '--filter', '/[^A-Za-z0-9_]' . $class . '::' . $test . '( |$)/' );

$ck = 'includes/sync/class-sync-code-kind.php';

return array(
	array(
		'id'      => 'tausch/kind-owns-both',
		'clause'  => 'responsibility(): the files of a plugin and of a theme, as the table gives them to the kind code (Z38)',
		'file'    => $ck,
		'search'  => "\t\treturn array( 'part:plugin:files', 'part:theme:files' );",
		'replace' => "\t\treturn array( 'part:plugin:files' );",
		'run'     => $unit( 'SyncCodeKindTest', 'testTheEngineIsTheOneRegisteredUnderTheNameCodeAndMovesTheFilesOfAPluginAndOfATheme' ),
		'expect'  => '/testTheEngineIsTheOneRegisteredUnderTheNameCodeAndMovesTheFilesOfAPluginAndOfATheme/',
	),
	array(
		'id'      => 'tausch/kind-engine-held',
		'clause'  => 'engine(): only the engine of this class is the kind code, never another object registered under its name (Z38)',
		'file'    => $ck,
		'search'  => "\t\treturn \$held instanceof self ? \$held : new WP_Error(",
		'replace' => "\t\treturn true ? \$held : new WP_Error(",
		'run'     => $unit( 'SyncCodeKindTest', 'testAnotherEngineUnderTheNameCodeIsNotTakenForTheKind' ),
		'expect'  => '/testAnotherEngineUnderTheNameCodeIsNotTakenForTheKind/',
	),
	array(
		'id'      => 'tausch/z26-form',
		'clause'  => 'rights(): an operation or a unit not of the form is refused before anything is asked (Z26)',
		'file'    => $ck,
		'search'  => "\t\tif ( ! is_string( \$op ) || ! isset( self::OPS[ \$op ] ) || 2 !== count( \$unit ) || ! in_array( \$unit[0], self::UNITS, true ) || '' === \$unit[1] ) {",
		'replace' => "\t\tif ( ! is_string( \$op ) || ! isset( self::OPS[ \$op ] ) || 2 !== count( \$unit ) || ! in_array( \$unit[0], self::UNITS, true ) ) {",
		'run'     => $unit( 'SyncCodeKindTest', 'testAnOperationOrAUnitNotOfTheFormIsRefusedBeforeAnythingIsAsked' ),
		'expect'  => '/testAnOperationOrAUnitNotOfTheFormIsRefusedBeforeAnythingIsAsked/',
	),
	array(
		'id'      => 'tausch/z26-file-mods',
		'clause'  => 'rights(): a site that lets no files be changed takes over no code (E10, F20)',
		'file'    => $ck,
		'search'  => "\t\tif ( true !== \$calls->mods_allowed() ) {",
		'replace' => "\t\tif ( false ) {",
		'run'     => $unit( 'SyncCodeKindTest', 'testASiteThatLetsNoFilesBeChangedOrWritesThemNotDirectlyRefusesBeforeACapabilityIsAsked' ),
		'expect'  => '/testASiteThatLetsNoFilesBeChangedOrWritesThemNotDirectlyRefusesBeforeACapabilityIsAsked/',
	),
	array(
		'id'      => 'tausch/z26-file-mods-unknown',
		'clause'  => 'rights(): whether files may be changed not known is not allowed (E10)',
		'file'    => $ck,
		'search'  => "\t\tif ( true !== \$calls->mods_allowed() ) {",
		'replace' => "\t\tif ( false === \$calls->mods_allowed() ) {",
		'run'     => $unit( 'SyncCodeKindTest', 'testASiteThatLetsNoFilesBeChangedOrWritesThemNotDirectlyRefusesBeforeACapabilityIsAsked' ),
		'expect'  => '/testASiteThatLetsNoFilesBeChangedOrWritesThemNotDirectlyRefusesBeforeACapabilityIsAsked/',
	),
	array(
		'id'      => 'tausch/z26-direct',
		'clause'  => 'rights(): only the file system method direct, a method not named is not direct (E10)',
		'file'    => $ck,
		'search'  => "\t\tif ( 'direct' !== \$calls->method() ) {",
		'replace' => "\t\tif ( false ) {",
		'run'     => $unit( 'SyncCodeKindTest', 'testASiteThatLetsNoFilesBeChangedOrWritesThemNotDirectlyRefusesBeforeACapabilityIsAsked' ),
		'expect'  => '/testASiteThatLetsNoFilesBeChangedOrWritesThemNotDirectlyRefusesBeforeACapabilityIsAsked/',
	),
	array(
		'id'      => 'tausch/z26-each-cap',
		'clause'  => 'rights(): each capability of the operation is asked, and one missing refuses (E10)',
		'file'    => $ck,
		'search'  => "\t\t\tif ( true !== \$calls->can( \$cap ) ) {",
		'replace' => "\t\t\tif ( false ) {",
		'run'     => $unit( 'SyncCodeKindTest', 'testEachOperationTakesTheCapabilitiesOfE10AndEachOneMissingRefuses' ),
		'expect'  => '/testEachOperationTakesTheCapabilitiesOfE10AndEachOneMissingRefuses/',
	),
	array(
		'id'      => 'tausch/z26-caps-create-plugin',
		'clause'  => 'OPS: the capabilities of create of a plugin (E10)',
		'file'    => $ck,
		'search'  => "\t\t\t'plugin' => array( 'install_plugins' ),",
		'replace' => "\t\t\t'plugin' => array(),",
		'run'     => $unit( 'SyncCodeKindTest', 'testEachOperationTakesTheCapabilitiesOfE10AndEachOneMissingRefuses' ),
		'expect'  => '/testEachOperationTakesTheCapabilitiesOfE10AndEachOneMissingRefuses/',
	),
	array(
		'id'      => 'tausch/z26-caps-create-theme',
		'clause'  => 'OPS: the capabilities of create of a theme (E10)',
		'file'    => $ck,
		'search'  => "\t\t\t'theme'  => array( 'install_themes' ),",
		'replace' => "\t\t\t'theme'  => array(),",
		'run'     => $unit( 'SyncCodeKindTest', 'testEachOperationTakesTheCapabilitiesOfE10AndEachOneMissingRefuses' ),
		'expect'  => '/testEachOperationTakesTheCapabilitiesOfE10AndEachOneMissingRefuses/',
	),
	array(
		'id'      => 'tausch/z26-caps-update-plugin',
		'clause'  => 'OPS: the capabilities of update of a plugin (E10)',
		'file'    => $ck,
		'search'  => "\t\t\t'plugin' => array( 'upload_plugins', 'update_plugins' ),",
		'replace' => "\t\t\t'plugin' => array( 'upload_plugins' ),",
		'run'     => $unit( 'SyncCodeKindTest', 'testEachOperationTakesTheCapabilitiesOfE10AndEachOneMissingRefuses' ),
		'expect'  => '/testEachOperationTakesTheCapabilitiesOfE10AndEachOneMissingRefuses/',
	),
	array(
		'id'      => 'tausch/z26-caps-update-theme',
		'clause'  => 'OPS: the capabilities of update of a theme (E10)',
		'file'    => $ck,
		'search'  => "\t\t\t'theme'  => array( 'upload_themes', 'update_themes' ),",
		'replace' => "\t\t\t'theme'  => array( 'update_themes' ),",
		'run'     => $unit( 'SyncCodeKindTest', 'testEachOperationTakesTheCapabilitiesOfE10AndEachOneMissingRefuses' ),
		'expect'  => '/testEachOperationTakesTheCapabilitiesOfE10AndEachOneMissingRefuses/',
	),
	array(
		'id'      => 'tausch/z26-caps-edit-plugin',
		'clause'  => 'OPS: the capabilities of edit of a plugin (E10)',
		'file'    => $ck,
		'search'  => "\t\t\t'plugin' => array( 'upload_plugins', 'update_plugins', 'edit_plugins' ),",
		'replace' => "\t\t\t'plugin' => array( 'upload_plugins', 'update_plugins' ),",
		'run'     => $unit( 'SyncCodeKindTest', 'testEachOperationTakesTheCapabilitiesOfE10AndEachOneMissingRefuses' ),
		'expect'  => '/testEachOperationTakesTheCapabilitiesOfE10AndEachOneMissingRefuses/',
	),
	array(
		'id'      => 'tausch/z26-caps-edit-theme',
		'clause'  => 'OPS: the capabilities of edit of a theme (E10)',
		'file'    => $ck,
		'search'  => "\t\t\t'theme'  => array( 'upload_themes', 'update_themes', 'edit_themes' ),",
		'replace' => "\t\t\t'theme'  => array( 'upload_themes', 'update_themes' ),",
		'run'     => $unit( 'SyncCodeKindTest', 'testEachOperationTakesTheCapabilitiesOfE10AndEachOneMissingRefuses' ),
		'expect'  => '/testEachOperationTakesTheCapabilitiesOfE10AndEachOneMissingRefuses/',
	),
);

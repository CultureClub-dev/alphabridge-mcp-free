<?php
/**
 * The clauses of the store of Z8b branch 3 (plan 3.1 Z7, Z8, Z9, Z37 at
 * their one places; 9.8): the one remover delete_path() with a red proof per
 * way of removal of the list in plan 8.4 (the same mutation of the guard of
 * the marks, held by the case of F53 of each way; where a way cannot reach
 * a tree, the clause of that way itself), the rule of temp files, the one
 * writer write_file() with the device and the place of its temp file (F54),
 * the first making through a building folder, put_tree() with its device,
 * its place by server configuration and PHP proof, never over another and
 * byte for byte (F23 for branch 3), the retention of a tree (Z8), the
 * uninstall that puts an original back only by its identity and keeps the
 * rest (Z9, F33), the mark and its instruction (E6), and the guards.
 *
 * Held by the integration suite (StoreRemoverTest, StoreTreesTest,
 * ProtectedStoreTest; the sites running, AB_IT_ROOT set) and by the unit
 * suite (StoreRemoverGuardTest). Behebung 1 (Codex round 1 of branch 3):
 * unreadable is never empty, missing or foreign (A1, A2, B2), the
 * instruction of a new unit (A3), the names of trees (B1), the lock of the
 * uninstall (B3), the texts (B4) and the clauses of their own of B5, each
 * with its case (StoreBookingsTest, StoreTextsTest among them).
 *
 * Not listed, with why: the Throwable caught around guard() and the walk in
 * delete_path() (a remover that throws keeps all, the case of each way
 * with a fault sees nothing removed either way); the 'with' of a group
 * whose folder is gone whole (the sidecar of a group removed whole goes,
 * ProtectedStoreTest holds the groups that go); the age of a folder of a
 * mark read through the seam (an lstat() that fails gives no age, and
 * delete_path() removes nothing whose lstat() fails and that its folder
 * still lists, ablage/a2-*).
 *
 * @package AlphaBridge_MCP
 */

$it   = static fn( string $class, string $test ): array => array( 'vendor/bin/phpunit', '--no-progress', '-c', 'tests/integration/phpunit.xml.dist', '--filter', '/[^A-Za-z0-9_]' . $class . '::' . $test . '( |$)/' );
$unit = static fn( string $class, string $test ): array => array( 'vendor/bin/phpunit', '--no-progress', '--filter', '/[^A-Za-z0-9_]' . $class . '::' . $test . '( |$)/' );

$ps = 'includes/class-protected-store.php';
$sb = 'includes/class-store-budget.php';
$sr = 'includes/sync/class-sync-resources.php';

// The guard of the marks in delete_path(), taken out: nothing a mark names
// is kept. One mutation, a red proof per way of removal (F53).
$guard_search  = "\t\t\tforeach ( \$guard['named'] as \$named ) {\n\t\t\t\tif ( \$under( \$p, \$named ) ) {";
$guard_replace = "\t\t\tforeach ( array() as \$named ) {\n\t\t\t\tif ( \$under( \$p, \$named ) ) {";
$f53           = array(
	'sweep-foreign-sidecar' => array( 'sweep(): a sidecar this store did not write, after a day (:1820 of 9be07f7)', 'testAForeignOrUnreadableSidecarDoesNotTakeATreeOfAnOpenMark' ),
	'sweep-orphan-folder'   => array( 'sweep(): a folder without its sidecar, after a day (:1837)', 'testAFolderWithoutItsSidecarKeepsATreeOfAnOpenMark' ),
	'remove-group'          => array( 'remove(): the group (:821, remove_group() :1905–1911, remove_tree() :2026–2037)', 'testRemoveOfTheGroupLeavesATreeOfAnOpenMarkAndTakesTheRest' ),
	'sweep-retention'       => array( 'sweep(): a group the retention lets go (:1833)', 'testAGroupPastItsRetentionKeepsATreeOfAnOpenMark' ),
	'uninstall'             => array( 'uninstall(): the folder of the store (:1042)', 'testTheUninstallKeepsATreeOfAnOpenMarkWhoseOriginalIsNotProvedAndTakesTheRest' ),
	'uninstall-identity'    => array( 'uninstall(): a backup whose identity is not the mark\'s stays', 'testTheUninstallMovesNoBackupWhoseIdentityIsNotTheMarks' ),
);

$z37_ways = array();
foreach ( $f53 as $id => $what ) {
	$z37_ways[] = array(
		'id'      => 'ablage/z37-guard-' . $id,
		'clause'  => 'delete_path(): what an open mark names stays, on the way ' . $what[0],
		'file'    => $ps,
		'search'  => $guard_search,
		'replace' => $guard_replace,
		'run'     => $it( 'StoreRemoverTest', $what[1] ),
		'expect'  => '/' . $what[1] . '/',
	);
}

return array_merge(
	$z37_ways,
	array(
		/* ------------------------------------------- Z37: the remover */
		array(
			'id'      => 'ablage/z37-unreadable-mark',
			'clause'  => 'guard(): a mark that cannot be read keeps stage, backups and marks (fail closed)',
			'file'    => $ps,
			'search'  => "\t\t\tif ( false === \$mark ) {\n\t\t\t\t\$out['named']      = \$keep;",
			'replace' => "\t\t\tif ( false ) {\n\t\t\t\t\$out['named']      = \$keep;",
			'run'     => $it( 'StoreRemoverTest', 'testWhileAMarkCannotBeReadNothingInStageBackupsAndMarksGoes' ),
			'expect'  => '/testWhileAMarkCannotBeReadNothingInStageBackupsAndMarksGoes/',
		),
		array(
			'id'      => 'ablage/z37-unreadable-marks-folder',
			'clause'  => 'guard(): a folder of the store or of the marks that cannot be listed keeps stage, backups and marks',
			'file'    => $ps,
			'search'  => "if ( false === \$top || ( in_array( self::MARKS, \$top, true ) && false === ( \$names = \$calls->scandir( \$root . '/' . self::MARKS ) ) ) ) {",
			'replace' => "if ( false === \$top || ( in_array( self::MARKS, \$top, true ) && false === ( \$names = ( \$calls->scandir( \$root . '/' . self::MARKS ) ?: array() ) ) ) ) {",
			'run'     => $it( 'StoreRemoverTest', 'testAMarkWhoseFileCannotBeReadKeepsStageAndBackups' ),
			'expect'  => '/testAMarkWhoseFileCannotBeReadKeepsStageAndBackups/',
		),
		array(
			'id'      => 'ablage/z37-outside-noted-only',
			'clause'  => 'delete_path(): outside the folder of the store only what this request made and noted',
			'file'    => $ps,
			'search'  => "\t\t\tif ( ! \$noted && ! \$under( \$p, \$root ) ) {",
			'replace' => "\t\t\tif ( false ) {",
			'run'     => $it( 'StoreRemoverTest', 'testTheRemoverTakesNothingOutsideTheStoreNorAForeignTempFile' ),
			'expect'  => '/testTheRemoverTakesNothingOutsideTheStoreNorAForeignTempFile/',
		),
		array(
			'id'      => 'ablage/z37-tmp-prefix',
			'clause'  => 'delete_path(): a temp file only with its prefix, never by its ending (the clean-up of temp files, :1830–1864)',
			'file'    => $ps,
			'search'  => "( dirname( \$p ) !== \$tmp_dir || 0 !== strpos( basename( \$p ), self::TMP_PREFIX ) || ( time() - (int) \$stat['mtime'] ) < self::TMP_AGE )",
			'replace' => "( dirname( \$p ) !== \$tmp_dir || ( time() - (int) \$stat['mtime'] ) < self::TMP_AGE )",
			'run'     => $it( 'StoreRemoverTest', 'testTheRemoverTakesNothingOutsideTheStoreNorAForeignTempFile' ),
			'expect'  => '/testTheRemoverTakesNothingOutsideTheStoreNorAForeignTempFile/',
		),
		array(
			'id'      => 'ablage/z37-tmp-age',
			'clause'  => 'delete_path(): a temp file of another request only after TMP_AGE',
			'file'    => $ps,
			'search'  => "|| 0 !== strpos( basename( \$p ), self::TMP_PREFIX ) || ( time() - (int) \$stat['mtime'] ) < self::TMP_AGE )",
			'replace' => '|| 0 !== strpos( basename( $p ), self::TMP_PREFIX ) )',
			'run'     => $it( 'StoreRemoverTest', 'testTheRemoverTakesNothingOutsideTheStoreNorAForeignTempFile' ),
			'expect'  => '/testTheRemoverTakesNothingOutsideTheStoreNorAForeignTempFile/',
		),
		array(
			'id'      => 'ablage/z37-tmp-swept',
			'clause'  => 'sweep(): the own old temp files of tmp/ go',
			'file'    => $ps,
			'search'  => "\t\t\t\tself::delete_path( \$root, \$tmp . '/' . \$file );",
			'replace' => "\t\t\t\t// (no clean-up of temp files)",
			'run'     => $it( 'ProtectedStoreTest', 'testTheCleanUpTakesOnlyItsOwnOldTempFilesNeverAFileByItsEnding' ),
			'expect'  => '/testTheCleanUpTakesOnlyItsOwnOldTempFilesNeverAFileByItsEnding/',
		),
		array(
			'id'      => 'ablage/z37-open-sidecar',
			'clause'  => 'open(): the sidecar written first goes where the folder of the group is not made (:545)',
			'file'    => $ps,
			'search'  => "\t\t\t\t\tself::delete_path( \$root, \$side );",
			'replace' => "\t\t\t\t\t// (the sidecar stays)",
			'run'     => $it( 'StoreRemoverTest', 'testAGroupThatCannotBeMadeLeavesNoSidecarAndTouchesNoTree' ),
			'expect'  => '/testAGroupThatCannotBeMadeLeavesNoSidecarAndTouchesNoTree/',
		),
		array(
			'id'      => 'ablage/z37-put-only-its-file',
			'clause'  => 'put(): where the sidecar cannot be written only the file just written goes (:607)',
			'file'    => $ps,
			'search'  => "\t\t\t\tif ( true !== self::write_entry( \$root, \$side, \$entry ) ) {\n\t\t\t\t\tself::delete_path( \$root, \$path );",
			'replace' => "\t\t\t\tif ( true !== self::write_entry( \$root, \$side, \$entry ) ) {\n\t\t\t\t\tself::delete_path( \$root, \$dir );",
			'run'     => $it( 'StoreRemoverTest', 'testAWriteThatFailsInTheGroupOfATreeOfAnOpenMarkLeavesTheTree' ),
			'expect'  => '/testAWriteThatFailsInTheGroupOfATreeOfAnOpenMarkLeavesTheTree/',
		),
		array(
			'id'      => 'ablage/z37-self-check-files',
			'clause'  => 'self_check(): its files, the control file beside the store among them, go again (:1444)',
			'file'    => $ps,
			'search'  => "\t\t\t\tself::delete_path( \$dir, \$file );",
			'replace' => "\t\t\t\t// (the files of the check stay)",
			'run'     => $it( 'StoreRemoverTest', 'testTheControlFileOfTheSelfCheckLiesBesideTheStoreAndGoesAgain' ),
			'expect'  => '/testTheControlFileOfTheSelfCheckLiesBesideTheStoreAndGoesAgain/',
		),
		array(
			'id'      => 'ablage/z37-ensure-building',
			'clause'  => 'ensure(): a building folder whose rename fails goes (:1504)',
			'file'    => $ps,
			'search'  => "\t\tself::delete_path( \$tmp, \$tmp, array( 'all_tmp' => true ) );",
			'replace' => "\t\t// (the building folder stays)",
			'run'     => $it( 'StoreRemoverTest', 'testTheFirstMakingOfTheStoreGoesThroughABuildingFolderBesideIt' ),
			'expect'  => '/testTheFirstMakingOfTheStoreGoesThroughABuildingFolderBesideIt/',
		),
		array(
			'id'      => 'ablage/z37-test-write',
			'clause'  => 'test_write(): the test write goes again (:350 of the budget)',
			'file'    => $sb,
			'search'  => "\t\t\tAB_MCP_Protected_Store::delete_path( \$root, \$path );",
			'replace' => "\t\t\t// (the test write stays)",
			'run'     => $it( 'ProtectedStoreTest', 'testABookingChecksTheFreeSpaceAndMakesATestWrite' ),
			'expect'  => '/testABookingChecksTheFreeSpaceAndMakesATestWrite/',
		),
		array(
			'id'      => 'ablage/z37-measure',
			'clause'  => 'measure(): the file of the measurement goes again (:320 of the resources)',
			'file'    => $sr,
			'search'  => "\t\t\t\tAB_MCP_Protected_Store::delete_path( \$root, \$path );",
			'replace' => "\t\t\t\t// (the file of the measurement stays)",
			'run'     => $it( 'StoreRemoverTest', 'testTheMeasurementOfTheResourcesLeavesNoFileInTheStore' ),
			'expect'  => '/testTheMeasurementOfTheResourcesLeavesNoFileInTheStore/',
		),
		array(
			'id'      => 'ablage/z37-sidecar-with-folder',
			'clause'  => 'delete_path(): the sidecar of a group stays while its folder stays',
			'file'    => $ps,
			'search'  => "\t\t\t\t\t\t\$all = ! isset( \$left[ substr( \$entry, 0, -5 ) ] ) && \$remove( \$p . '/' . \$entry, false ) && \$all;",
			'replace' => "\t\t\t\t\t\t\$all = \$remove( \$p . '/' . \$entry, false ) && \$all;",
			'run'     => $it( 'StoreRemoverTest', 'testTheUninstallKeepsATreeOfAnOpenMarkWhoseOriginalIsNotProvedAndTakesTheRest' ),
			'expect'  => '/testTheUninstallKeepsATreeOfAnOpenMarkWhoseOriginalIsNotProvedAndTakesTheRest/',
		),
		array(
			'id'      => 'ablage/z37-guard-test-remover',
			'clause'  => 'the guard: a removal past delete_path() is seen',
			'file'    => $ps,
			'search'  => "\t\treturn self::delete_path( \$root, \$dir . '/' . \$name, array( 'with' => \$dir . '/' . \$name . '.json' ) );",
			'replace' => "\t\treturn @unlink( \$dir . '/' . \$name . '.json' ) && self::delete_path( \$root, \$dir . '/' . \$name ); // phpcs:ignore",
			'run'     => $unit( 'StoreRemoverGuardTest', 'testNothingRemovesInTheStoreButDeletePath' ),
			'expect'  => '/testNothingRemovesInTheStoreButDeletePath/',
		),
		array(
			'id'      => 'ablage/z37-guard-test-payload',
			'clause'  => 'the guard: a file of the store written through AB_MCP_Payload_File::write() is seen',
			'file'    => $ps,
			'search'  => "\t\t\t\tif ( true !== self::write_file( \$dir, \$file, \$token ) ) {",
			'replace' => "\t\t\t\tif ( ! AB_MCP_Payload_File::write( \$file, \$token ) ) {",
			'run'     => $unit( 'StoreRemoverGuardTest', 'testNothingOfTheStoreIsWrittenThroughThePayloadFileButTheControlFileBesideIt' ),
			'expect'  => '/testNothingOfTheStoreIsWrittenThroughThePayloadFileButTheControlFileBesideIt/',
		),

		array(
			'id'      => 'ablage/z37-marks-closed-day',
			'clause'  => 'sweep(): a closed mark goes a day after it closed, an open one never',
			'file'    => $ps,
			'search'  => "\$old  = is_array( \$mark ) ? ( 'closed' === \$mark['state'] && ( \$now - (int) \$mark['closed'] ) >= self::KEEP['marks'] ) :",
			'replace' => "\$old  = is_array( \$mark ) ? false :",
			'run'     => $it( 'StoreRemoverTest', 'testAClosedMarkGoesADayAfterItClosedAndAFolderWithoutItsMarkAfterADay' ),
			'expect'  => '/testAClosedMarkGoesADayAfterItClosedAndAFolderWithoutItsMarkAfterADay/',
		),
		array(
			'id'      => 'ablage/z8-guard-remove-backups',
			'clause'  => 'the guard: no group of backups is removed by remove() (a backup goes by its retention only)',
			'file'    => 'includes/sync/class-sync-report.php',
			'search'  => "\t\t\tAB_MCP_Protected_Store::remove( 'reports', \$gid );",
			'replace' => "\t\t\tAB_MCP_Protected_Store::remove( 'backups', \$gid );",
			'run'     => $unit( 'StoreRemoverGuardTest', 'testTheRetentionOfABackupIsDecidedByKeptOnly' ),
			'expect'  => '/testTheRetentionOfABackupIsDecidedByKeptOnly/',
		),

		/* ---------------------------------------------- Z37, F54: the writer */
		array(
			'id'      => 'ablage/f54-device',
			'clause'  => 'write_file(): renamed only where tmp/ and the folder report one device; else nothing written, the folder named',
			'file'    => $ps,
			'search'  => "\t\t\tif ( false === \$at || false === \$here || (int) \$at['dev'] !== (int) \$here['dev'] ) {",
			'replace' => "\t\t\tif ( false === \$at || false === \$here ) {",
			'run'     => $it( 'StoreRemoverTest', 'testAnotherDeviceOfTmpAndTheFolderWritesNothingAndNamesTheFolder' ),
			'expect'  => '/testAnotherDeviceOfTmpAndTheFolderWritesNothingAndNamesTheFolder/',
		),
		array(
			'id'      => 'ablage/f54-temp-in-tmp',
			'clause'  => 'temp_file(): a temp file in tmp/ with the prefix, never beside the target',
			'file'    => $ps,
			'search'  => "\t\t\t\$path = \$root . '/' . self::TMP . '/' . self::TMP_PREFIX . bin2hex( AB_MCP_Payload_File::random_bytes( 8 ) );",
			'replace' => "\t\t\t\$path = \$root . '/' . self::TMP_PREFIX . bin2hex( AB_MCP_Payload_File::random_bytes( 8 ) );",
			'run'     => $it( 'StoreRemoverTest', 'testAWriteOfTheStoreThatIsCutOffLeavesItsTempFileInTmpOnly' ),
			'expect'  => '/testAWriteOfTheStoreThatIsCutOffLeavesItsTempFileInTmpOnly/',
		),
		array(
			'id'      => 'ablage/f54-building-prefix',
			'clause'  => 'ensure(): the building folder beside the store with the prefix of temp files',
			'file'    => $ps,
			'search'  => "\$parent . '/' . self::TMP_PREFIX . basename( \$path ) . '.'",
			'replace' => "\$parent . '/.' . basename( \$path ) . '.'",
			'run'     => $it( 'StoreRemoverTest', 'testTheFirstMakingOfTheStoreGoesThroughABuildingFolderBesideIt' ),
			'expect'  => '/testTheFirstMakingOfTheStoreGoesThroughABuildingFolderBesideIt/',
		),
		array(
			'id'      => 'ablage/f54-complete-tmp-first',
			'clause'  => 'complete(): the area tmp/ before anything is written into the folder',
			'file'    => $ps,
			'search'  => "foreach ( array_merge( array( self::TMP ), self::AREAS, array( self::MARKS ) ) as \$area ) {",
			'replace' => "foreach ( array_merge( self::AREAS, array( self::MARKS ) ) as \$area ) {",
			'run'     => $it( 'StoreRemoverTest', 'testTheFirstMakingOfTheStoreGoesThroughABuildingFolderBesideIt' ),
			'expect'  => '/testTheFirstMakingOfTheStoreGoesThroughABuildingFolderBesideIt/',
		),

		/* -------------------------------------------- Z7: put_tree(), tree() */
		array(
			'id'      => 'ablage/z7-device',
			'clause'  => 'put_tree(): a tree only where the store and the folder of the unit are on one device (code:store_device)',
			'file'    => $ps,
			'search'  => "\t\t\t\tif ( false === \$here || false === \$there || (int) \$here['dev'] !== (int) \$there['dev'] ) {",
			'replace' => "\t\t\t\tif ( false === \$here || false === \$there ) {",
			'run'     => $it( 'StoreTreesTest', 'testAnotherDeviceOfTheStoreAndTheFolderOfTheUnitPutsNothingAndNamesTheWay' ),
			'expect'  => '/testAnotherDeviceOfTheStoreAndTheFolderOfTheUnitPutsNothingAndNamesTheWay/',
		),
		array(
			'id'      => 'ablage/z7-server-config',
			'clause'  => 'put_tree(): in the uploads no tree with a file that configures the web server or PHP (code:store_web)',
			'file'    => $ps,
			'search'  => "\t\t\t\t\tif ( array() !== \$config ) {",
			'replace' => "\t\t\t\t\tif ( false ) {",
			'run'     => $it( 'StoreTreesTest', 'testInTheUploadsATreeNeedsThePhpProofAndNoFileThatConfiguresTheServer' ),
			'expect'  => '/testInTheUploadsATreeNeedsThePhpProofAndNoFileThatConfiguresTheServer/',
		),
		array(
			'id'      => 'ablage/z7-config-name-case',
			'clause'  => 'server_config(): the name in lower case, a dot or blank at its end left out',
			'file'    => $ps,
			'search'  => "if ( in_array( strtolower( rtrim( basename( str_replace( '\\\\', '/', \$path ) ), \". \\t\" ) ), self::SERVER_CONFIG, true ) ) {",
			'replace' => "if ( in_array( basename( str_replace( '\\\\', '/', \$path ) ), self::SERVER_CONFIG, true ) ) {",
			'run'     => $it( 'StoreTreesTest', 'testInTheUploadsATreeNeedsThePhpProofAndNoFileThatConfiguresTheServer' ),
			'expect'  => '/testInTheUploadsATreeNeedsThePhpProofAndNoFileThatConfiguresTheServer/',
		),
		array(
			'id'      => 'ablage/z7-outside-allows',
			'clause'  => 'put_tree(): beside WordPress a tree with such a file is fine',
			'file'    => $ps,
			'search'  => "\t\t\t\tif ( 'outside' !== \$where ) {",
			'replace' => "\t\t\t\tif ( true ) {",
			'run'     => $it( 'StoreTreesTest', 'testInTheUploadsATreeNeedsThePhpProofAndNoFileThatConfiguresTheServer' ),
			'expect'  => '/testInTheUploadsATreeNeedsThePhpProofAndNoFileThatConfiguresTheServer/',
		),
		array(
			'id'      => 'ablage/z7-php-proof',
			'clause'  => 'put_tree(): in the uploads a tree only with the PHP proof below stage and backups (code:store_web)',
			'file'    => $ps,
			'search'  => "\t\t\t\t\tif ( true !== self::php_proved( \$id ) ) {",
			'replace' => "\t\t\t\t\tif ( false ) {",
			'run'     => $it( 'StoreTreesTest', 'testInTheUploadsWithoutThePhpProofNoTreeGoesIn' ),
			'expect'  => '/testInTheUploadsWithoutThePhpProofNoTreeGoesIn/',
		),
		array(
			'id'      => 'ablage/z7-php-proved-true',
			'clause'  => 'php_proved(): the proof holds php true, not only a proof',
			'file'    => $ps,
			'search'  => "&& isset( \$ok['detail']['php'] ) && true === \$ok['detail']['php'];",
			'replace' => ';',
			'run'     => $it( 'StoreTreesTest', 'testInTheUploadsWithoutThePhpProofNoTreeGoesIn' ),
			'expect'  => '/testInTheUploadsWithoutThePhpProofNoTreeGoesIn/',
		),
		array(
			'id'      => 'ablage/z7-judge-php',
			'clause'  => 'judge(): a PHP file counts as refused only with 403 or 404 and without the content',
			'file'    => $ps,
			'search'  => "\t\t\t\tif ( ! in_array( \$code, array( 403, 404 ), true ) || false !== strpos( (string) wp_remote_retrieve_body( \$answer ), (string) \$token ) ) {\n\t\t\t\t\t\$refused = false;",
			'replace' => "\t\t\t\tif ( false ) {\n\t\t\t\t\t\$refused = false;",
			'run'     => $it( 'StoreTreesTest', 'testAPhpFileAnsweredWithItsContentOrRunIsNoProof' ),
			'expect'  => '/testAPhpFileAnsweredWithItsContentOrRunIsNoProof/',
		),
		array(
			'id'      => 'ablage/z7-php-asked',
			'clause'  => 'self_check(): the PHP files below stage and backups are asked where the test files prove the folder',
			'file'    => $ps,
			'search'  => "\t\t\t\t\$answers[] = self::ask( \$url, \$fetcher );",
			'replace' => "\t\t\t\t\$answers[] = array( 'response' => array( 'code' => 404 ), 'body' => '' );",
			'run'     => $it( 'StoreTreesTest', 'testInTheUploadsWithoutThePhpProofNoTreeGoesIn' ),
			'expect'  => '/testInTheUploadsWithoutThePhpProofNoTreeGoesIn/',
		),
		array(
			'id'      => 'ablage/z7-old-proof-anew',
			'clause'  => 'put_tree(): a proof in the uploads that did not ask PHP is made anew',
			'file'    => $ps,
			'search'  => "if ( 'uploads' === \$proof['where'] && null === \$proof['php'] ) {",
			'replace' => 'if ( false ) {',
			'run'     => $it( 'StoreTreesTest', 'testAStoreInTheUploadsProvedBeforeThePhpFilesWereAskedIsCheckedAnewForATree' ),
			'expect'  => '/testAStoreInTheUploadsProvedBeforeThePhpFilesWereAskedIsCheckedAnewForATree/',
		),
		array(
			'id'      => 'ablage/z7-never-over',
			'clause'  => 'put_tree(): never over a tree or file of that name',
			'file'    => $ps,
			'search'  => "\t\t\t\tif ( false !== \$calls->lstat( \$place ) ) {",
			'replace' => "\t\t\t\tif ( false ) {",
			'run'     => $it( 'StoreTreesTest', 'testATreeIsNeverPutOverAnotherNorChangedInTheStore' ),
			'expect'  => '/testATreeIsNeverPutOverAnotherNorChangedInTheStore/',
		),
		array(
			'id'      => 'ablage/z7-no-write-into-tree',
			'clause'  => 'write_file(): into a tree only while put_tree() copies it, a file of stage and backups once',
			'file'    => $ps,
			'search'  => "&& count( \$parts ) >= 3 && ( ! \$exclusive || ( count( \$parts ) > 3 && ! isset( self::\$building[",
			'replace' => "&& count( \$parts ) >= 3 && ( false || ( count( \$parts ) > 3 && ! isset( self::\$building[",
			'run'     => $it( 'StoreTreesTest', 'testATreeIsNeverPutOverAnotherNorChangedInTheStore' ),
			'expect'  => '/testATreeIsNeverPutOverAnotherNorChangedInTheStore/',
		),
		array(
			'id'      => 'ablage/z7-byte-for-byte',
			'clause'  => 'put_tree(): the copy stays only where its manifest is the one of the source read before',
			'file'    => $ps,
			'search'  => ": hash_equals( \$out['hash'], (string) \$back['hash'] ) );",
			'replace' => ': true );',
			'run'     => $it( 'StoreTreesTest', 'testACopyThatFailsLeavesNothingOfTheTree' ),
			'expect'  => '/testACopyThatFailsLeavesNothingOfTheTree/',
		),
		array(
			'id'      => 'ablage/z7-tree-read-back',
			'clause'  => 'tree(): a tree is used only with the hash put_tree() gave',
			'file'    => $ps,
			'search'  => "\t\tif ( ! \$read['complete'] || null === \$read['hash'] || ! hash_equals( (string) \$hash, (string) \$read['hash'] ) ) {",
			'replace' => "\t\tif ( ! \$read['complete'] || null === \$read['hash'] ) {",
			'run'     => $it( 'StoreTreesTest', 'testATreeIsNeverPutOverAnotherNorChangedInTheStore' ),
			'expect'  => '/testATreeIsNeverPutOverAnotherNorChangedInTheStore/',
		),
		array(
			'id'      => 'ablage/z7-file-name',
			'clause'  => 'put_tree(): a tree of one file is named as the file of its unit',
			'file'    => $ps,
			'search'  => "'file' === \$read['form'] && basename( \$target ) !== \$name ) {",
			'replace' => "'file' === \$read['form'] && false ) {",
			'run'     => $it( 'StoreTreesTest', 'testATreeGoesIntoTheStageAndComesBackByteForByte' ),
			'expect'  => '/testATreeGoesIntoTheStageAndComesBackByteForByte/',
		),
		array(
			'id'      => 'ablage/z7-copy-mode',
			'clause'  => 'copy_tree(): each file with the mode of the source',
			'file'    => $ps,
			'search'  => "\t\t\t\t\t\t\t'mode'      => \$at['mode'] & 0777,",
			'replace' => "\t\t\t\t\t\t\t'mode'      => 0600,",
			'run'     => $it( 'StoreTreesTest', 'testATreeGoesIntoTheStageAndComesBackByteForByte' ),
			'expect'  => '/testATreeGoesIntoTheStageAndComesBackByteForByte/',
		),
		array(
			'id'      => 'ablage/z7-guard-copy',
			'clause'  => 'the guard: a tree is copied by put_tree() only',
			'file'    => $ps,
			'search'  => "\t\t\t\t\t\$made = self::copy_tree( \$root, \$source, \$place );",
			'replace' => "\t\t\t\t\t\$made = self::copy_tree( \$root, \$source, \$place ); self::copy_tree( \$root, \$source, \$place . '-x' );",
			'run'     => $unit( 'StoreRemoverGuardTest', 'testATreeIsCopiedOnlyByPutTreeAndTheStoreRenamesOnlyWhereItMay' ),
			'expect'  => '/testATreeIsCopiedOnlyByPutTreeAndTheStoreRenamesOnlyWhereItMay/',
		),

		/* --------------------------------------------- Z8: the retention */
		array(
			'id'      => 'ablage/z8-tree-row-says-go',
			'clause'  => 'kept(): a backup tree goes once the retention of its run lets it go (the last three qualified closes)',
			'file'    => $ps,
			'search'  => "\t\t\t\treturn (bool) \$backup_runs[ \$run ];",
			'replace' => "\t\t\t\treturn true;",
			'run'     => $it( 'StoreTreesTest', 'testABackupTreeStaysUntilItsRetentionLetsItGo' ),
			'expect'  => '/testABackupTreeStaysUntilItsRetentionLetsItGo/',
		),
		array(
			'id'      => 'ablage/z8-tree-row-says-stay',
			'clause'  => 'kept(): a backup tree of a run the retention keeps stays, whatever its age (30 days and the last three)',
			'file'    => $ps,
			'search'  => "\t\t\t\treturn (bool) \$backup_runs[ \$run ];",
			'replace' => "\t\t\t\treturn false;",
			'run'     => $it( 'StoreTreesTest', 'testWithThirtyDaysABackupTreeStaysAlsoOlderWhileItIsOneOfTheLastThreeAndItsPeriodStaysWithIt' ),
			'expect'  => '/testWithThirtyDaysABackupTreeStaysAlsoOlderWhileItIsOneOfTheLastThreeAndItsPeriodStaysWithIt/',
		),
		array(
			'id'      => 'ablage/z8-stage-day',
			'clause'  => 'KEEP: a tree of the stage a day after its run closed',
			'file'    => $ps,
			'search'  => "\t\t'stage'   => 86400,",
			'replace' => "\t\t'stage'   => 8640000,",
			'run'     => $it( 'StoreTreesTest', 'testATreeGoesIntoTheStageAndComesBackByteForByte' ),
			'expect'  => '/testATreeGoesIntoTheStageAndComesBackByteForByte/',
		),

		/* ---------------------------------------------- Z9: the uninstall */
		array(
			'id'      => 'ablage/z9-target-missing',
			'clause'  => 'put_back(): the original goes back where the target is missing',
			'file'    => $ps,
			'search'  => "\t\t\tif ( ! self::listed( \$target ) ) {",
			'replace' => "\t\t\tif ( false ) {",
			'run'     => $it( 'StoreRemoverTest', 'testTheUninstallPutsTheOriginalBackWhereTheTargetIsMissing' ),
			'expect'  => '/testTheUninstallPutsTheOriginalBackWhereTheTargetIsMissing/',
		),
		array(
			'id'      => 'ablage/z9-new-at-target',
			'clause'  => 'put_back(): swapped back where the target holds the own new version',
			'file'    => $ps,
			'search'  => "} elseif ( null !== \$new && self::is_tree( \$target, \$new ) && false === \$calls->lstat( \$root . '/' . \$new['path'] ) ) {",
			'replace' => '} elseif ( false ) {',
			'run'     => $it( 'StoreRemoverTest', 'testTheUninstallSwapsBackWhereTheTargetHoldsTheNewVersion' ),
			'expect'  => '/testTheUninstallSwapsBackWhereTheTargetHoldsTheNewVersion/',
		),
		array(
			'id'      => 'ablage/z9-identity-inode',
			'clause'  => 'is_tree(): the identity of a tree is its device and inode as the mark says',
			'file'    => $ps,
			'search'  => "|| (int) \$stat['ino'] !== (int) \$tree['ino'] ) {",
			'replace' => ') {',
			'run'     => $it( 'StoreRemoverTest', 'testTheUninstallMovesNoBackupWhoseIdentityIsNotTheMarks' ),
			'expect'  => '/testTheUninstallMovesNoBackupWhoseIdentityIsNotTheMarks/',
		),
		array(
			'id'      => 'ablage/z9-target-listed',
			'clause'  => 'listed(): a target counts as missing only where its folder lists it not',
			'file'    => $ps,
			'search'  => "\t\treturn false === \$names || in_array( basename( \$path ), \$names, true ) || false !== self::calls()->lstat( \$path );",
			'replace' => "\t\treturn false;",
			'run'     => $it( 'StoreRemoverTest', 'testAnEmptyFolderAtTheTargetIsThereSoTheUninstallMovesNothing' ),
			'expect'  => '/testAnEmptyFolderAtTheTargetIsThereSoTheUninstallMovesNothing/',
		),
		array(
			'id'      => 'ablage/z9-close-after',
			'clause'  => 'put_back(): the mark is closed once the original is back, so the rest of the store goes',
			'file'    => $ps,
			'search'  => "\t\t\tif ( true === self::close_mark_in( \$root, \$mid, \$mark ) ) {",
			'replace' => "\t\t\tif ( false ) {",
			'run'     => $it( 'StoreRemoverTest', 'testTheUninstallSwapsBackWhereTheTargetHoldsTheNewVersion' ),
			'expect'  => '/testTheUninstallSwapsBackWhereTheTargetHoldsTheNewVersion/',
		),
		array(
			'id'      => 'ablage/z9-owner-last',
			'clause'  => 'uninstall(): the manifest, the guard files and the lock go last, only where all else went',
			'file'    => $ps,
			'search'  => "\t\t\t\t\t\t'last'    => array_merge( array( self::OWNER, self::LOCK_FILE ), array_keys( self::GUARDS ) ),",
			'replace' => "\t\t\t\t\t\t'last'    => array(),",
			'run'     => $it( 'StoreRemoverTest', 'testTheUninstallKeepsATreeOfAnOpenMarkWhoseOriginalIsNotProvedAndTakesTheRest' ),
			'expect'  => '/testTheUninstallKeepsATreeOfAnOpenMarkWhoseOriginalIsNotProvedAndTakesTheRest/',
		),

		/* ------------------------------------------ E6: mark, instruction */
		array(
			'id'      => 'ablage/e6-tree-in-stage-or-backups',
			'clause'  => 'tree_rel(): a mark names trees of stage and backups only',
			'file'    => $ps,
			'search'  => "if ( 3 !== count( \$parts ) || ! in_array( \$parts[0], self::TREE_AREAS, true ) ||",
			'replace' => 'if ( 3 !== count( $parts ) ||',
			'run'     => $it( 'StoreTreesTest', 'testAMarkIsWrittenWholeReadBackAndClosedAndItsFieldsAreChecked' ),
			'expect'  => '/testAMarkIsWrittenWholeReadBackAndClosedAndItsFieldsAreChecked/',
		),
		array(
			'id'      => 'ablage/e6-read-back',
			'clause'  => 'put_mark(): the mark and its instruction are read back',
			'file'    => $ps,
			'search'  => "\t\t\t\t\tif ( self::calls()->read( \$path, self::MARK_MAX ) !== \$bytes ) {",
			'replace' => "\t\t\t\t\tif ( false ) {",
			'run'     => $it( 'StoreTreesTest', 'testAMarkIsWrittenWholeReadBackAndClosedAndItsFieldsAreChecked' ),
			'expect'  => '/testAMarkIsWrittenWholeReadBackAndClosedAndItsFieldsAreChecked/',
		),
		array(
			'id'      => 'ablage/e6-active',
			'clause'  => 'guide(): for a plugin active at the beginning the way and the sentence of the race',
			'file'    => $ps,
			'search'  => "\t\tforeach ( (array) \$mark['active'] as \$file ) {",
			'replace' => "\t\tforeach ( array() as \$file ) {",
			'run'     => $it( 'StoreTreesTest', 'testTheInstructionNamesWhatMovedAndForAnActivePluginTheWayAndTheRace' ),
			'expect'  => '/testTheInstructionNamesWhatMovedAndForAnActivePluginTheWayAndTheRace/',
		),
		array(
			'id'      => 'ablage/e6-between-put-back',
			'clause'  => 'guide(): between the renames the old version is put back from the backup',
			'file'    => $ps,
			'search'  => "\t\t\t\$put     = array( '\$ test ! -e ' . \$q( \$target ), \$inode( \$back, \$old ), '\$ mv ' . \$q( \$back ) . ' ' . \$q( \$target ), \$inode( \$target, \$old ) );",
			'replace' => "\t\t\t\$put     = array( '\$ test ! -e ' . \$q( \$target ), \$inode( \$back, \$old ) );",
			'run'     => $it( 'StoreTreesTest', 'testTheInstructionPutsTheOldStateBackByHandAtEachPoint' ),
			'expect'  => '/testTheInstructionPutsTheOldStateBackByHandAtEachPoint/',
		),
		array(
			'id'      => 'ablage/e6-check-target-missing',
			'clause'  => 'guide(): between the renames the target is checked missing before anything moves',
			'file'    => $ps,
			'search'  => "\t\t\t\$put     = array( '\$ test ! -e ' . \$q( \$target ), ",
			'replace' => "\t\t\t\$put     = array( ",
			'run'     => $it( 'StoreTreesTest', 'testTheInstructionPutsTheOldStateBackByHandAtEachPoint' ),
			'expect'  => '/testTheInstructionPutsTheOldStateBackByHandAtEachPoint/',
		),

		/* ------------------------- Behebung 1: Codex round 1, A1 to B5 */
		array(
			'id'      => 'ablage/a3-stage-free',
			'clause'  => 'guide(): a new version at the target goes back only into a free place in the store',
			'file'    => $ps,
			'search'  => ' . (int) $new[\'ino\'] . \'" ]; then test ! -e \' . $q( $stage ) . \' && mv \' . $q( $target ) . \' \' . $q( $stage ) . \'; fi\', ',
			'replace' => ' . (int) $new[\'ino\'] . \'" ]; then mv \' . $q( $target ) . \' \' . $q( $stage ) . \'; fi\', ',
			'run'     => $it( 'StoreTreesTest', 'testTheInstructionOfANewUnitTakesItOutAgainByHandAtEachPoint' ),
			'expect'  => '/testTheInstructionOfANewUnitTakesItOutAgainByHandAtEachPoint/',
		),
		array(
			'id'      => 'ablage/a1-unknown-sidecar-stays',
			'clause'  => 'sweep(): a sidecar that cannot be read or holds no entry of the format keeps its group (A1, V2)',
			'file'    => $ps,
			'search'  => '						// nothing says whose it is, so its group stays (A1).
						continue;',
			'replace' => '						$at = $calls->lstat( $path ); if ( false !== $at && ( $now - (int) $at[\'mtime\'] ) >= 86400 ) { self::delete_group( $root, $area, $m[1] ); }
						continue;',
			'run'     => $it( 'StoreRemoverTest', 'testASidecarThatCannotBeReadOrSaysNotWhatTheRetentionNeedsKeepsABackupTheRetentionKeeps' ),
			'expect'  => '/testASidecarThatCannotBeReadOrSaysNotWhatTheRetentionNeedsKeepsABackupTheRetentionKeeps/',
		),
		array(
			'id'      => 'ablage/a1-unknown-read-error',
			'clause'  => 'sweep(): a sidecar whose read fails keeps its group (A1; B5, the read of the seam)',
			'file'    => $ps,
			'search'  => '						// nothing says whose it is, so its group stays (A1).
						continue;',
			'replace' => '						$at = $calls->lstat( $path ); if ( false !== $at && ( $now - (int) $at[\'mtime\'] ) >= 86400 ) { self::delete_group( $root, $area, $m[1] ); }
						continue;',
			'run'     => $it( 'StoreRemoverTest', 'testASidecarWhoseReadFailsKeepsItsGroup' ),
			'expect'  => '/testASidecarWhoseReadFailsKeepsItsGroup/',
		),
		array(
			'id'      => 'ablage/a1-created-belegt',
			'clause'  => 'sidecar(): the time a group was made is a number the entry holds, never made up',
			'file'    => $ps,
			'search'  => '! in_array( $e[\'area\'], self::AREAS, true ) || ! is_int( $e[\'created\'] ) ||',
			'replace' => '! in_array( $e[\'area\'], self::AREAS, true ) ||',
			'run'     => $it( 'StoreRemoverTest', 'testASidecarThatCannotBeReadOrSaysNotWhatTheRetentionNeedsKeepsABackupTheRetentionKeeps' ),
			'expect'  => '/testASidecarThatCannotBeReadOrSaysNotWhatTheRetentionNeedsKeepsABackupTheRetentionKeeps/',
		),
		array(
			'id'      => 'ablage/a1-foreign-area-it-lies-in',
			'clause'  => 'sweep(): a foreign sidecar goes only where the retention of the area it lies in lets it go too',
			'file'    => $ps,
			'search'  => ' && ! self::kept( array( \'area\' => $area ) + $entry, $now, $open, $backups ) ) {',
			'replace' => ' ) {',
			'run'     => $it( 'StoreRemoverTest', 'testASidecarThatCannotBeReadOrSaysNotWhatTheRetentionNeedsKeepsABackupTheRetentionKeeps' ),
			'expect'  => '/testASidecarThatCannotBeReadOrSaysNotWhatTheRetentionNeedsKeepsABackupTheRetentionKeeps/',
		),
		array(
			'id'      => 'ablage/a1-foreign-area-it-names',
			'clause'  => 'sweep(): a foreign sidecar goes only where the retention of the area it names lets it go too',
			'file'    => $ps,
			'search'  => '( $now - (int) $at[\'mtime\'] ) >= 86400 && ! self::kept( $entry, $now, $open, $backups ) && ',
			'replace' => '( $now - (int) $at[\'mtime\'] ) >= 86400 && ',
			'run'     => $it( 'StoreRemoverTest', 'testASidecarThatCannotBeReadOrSaysNotWhatTheRetentionNeedsKeepsABackupTheRetentionKeeps' ),
			'expect'  => '/testASidecarThatCannotBeReadOrSaysNotWhatTheRetentionNeedsKeepsABackupTheRetentionKeeps/',
		),
		array(
			'id'      => 'ablage/a1-orphan-by-listing',
			'clause'  => 'sweep(): a folder is without its sidecar only where the listing of its area lacks it (no lstat())',
			'file'    => $ps,
			'search'  => 'preg_match( \'/^[0-9a-f]{40}\\z/\', $file ) && ! in_array( $file . \'.json\', $names, true ) && self::plain( $path, \'dir\' )',
			'replace' => 'preg_match( \'/^[0-9a-f]{40}\\z/\', $file ) && false === $calls->lstat( $path . \'.json\' ) && self::plain( $path, \'dir\' )',
			'run'     => $it( 'StoreRemoverTest', 'testAFolderWhoseSidecarIsListedIsNoFolderWithoutItsSidecarWhateverItsLstatSays' ),
			'expect'  => '/testAFolderWhoseSidecarIsListedIsNoFolderWithoutItsSidecarWhateverItsLstatSays/',
		),
		array(
			'id'      => 'ablage/a2-group-not-stated',
			'clause'  => 'delete_path(): a path whose lstat() fails is gone only where its folder lists it not (the group, counter-example 1 of Codex round 1)',
			'file'    => $ps,
			'search'  => '				return ! $holds && false !== $names && ! in_array( basename( $p ), $names, true );',
			'replace' => '				return true;',
			'run'     => $it( 'StoreRemoverTest', 'testAGroupWhoseFolderCannotBeStatedIsNotRemovedAndKeepsItsSidecar' ),
			'expect'  => '/testAGroupWhoseFolderCannotBeStatedIsNotRemovedAndKeepsItsSidecar/',
		),
		array(
			'id'      => 'ablage/a2-uninstall-backups-not-stated',
			'clause'  => 'delete_path(): a path whose lstat() fails is gone only where its folder lists it not (the uninstall, counter-example 2)',
			'file'    => $ps,
			'search'  => '				return ! $holds && false !== $names && ! in_array( basename( $p ), $names, true );',
			'replace' => '				return true;',
			'run'     => $it( 'StoreRemoverTest', 'testAnUninstallThatCannotStateTheBackupsKeepsTheManifestAndTheLock' ),
			'expect'  => '/testAnUninstallThatCannotStateTheBackupsKeepsTheManifestAndTheLock/',
		),
		array(
			'id'      => 'ablage/a2-holds-before-gone',
			'clause'  => 'delete_path(): a folder above a tree an open mark names never counts as gone',
			'file'    => $ps,
			'search'  => '				return ! $holds && false !== $names && ! in_array( basename( $p ), $names, true );',
			'replace' => '				return false !== $names && ! in_array( basename( $p ), $names, true );',
			'run'     => $it( 'StoreRemoverTest', 'testAGroupWhoseTreeAnOpenMarkNamesKeepsItsSidecarAlsoWhereItsFolderIsGone' ),
			'expect'  => '/testAGroupWhoseTreeAnOpenMarkNamesKeepsItsSidecarAlsoWhereItsFolderIsGone/',
		),
		array(
			'id'      => 'ablage/a3-new-unit-out',
			'clause'  => 'guide(): a new unit in the site goes back into its place in the store',
			'file'    => $ps,
			'search'  => '$out     = array( $inode( $target, $new ), \'$ test ! -e \' . $q( $stage ), \'$ mv \' . $q( $target ) . \' \' . $q( $stage ), ',
			'replace' => '$out     = array( $inode( $target, $new ), \'$ test ! -e \' . $q( $stage ), ',
			'run'     => $it( 'StoreTreesTest', 'testTheInstructionOfANewUnitTakesItOutAgainByHandAtEachPoint' ),
			'expect'  => '/testTheInstructionOfANewUnitTakesItOutAgainByHandAtEachPoint/',
		),
		array(
			'id'      => 'ablage/a3-new-unit-back',
			'clause'  => 'guide(): between the renames and during the way back a new version at the target goes back into the store',
			'file'    => $ps,
			'search'  => '$back    = array( \'$ if [ -e \' . $q( $target ) . \' ] && [ "$(ls -di \' . $q( $target ) . \' | awk \\\'{print $1}\\\')" = "\' . (int) $new[\'ino\'] . \'" ]; then test ! -e \' . $q( $stage ) . \' && mv \' . $q( $target ) . \' \' . $q( $stage ) . \'; fi\', ',
			'replace' => '$back    = array( ',
			'run'     => $it( 'StoreTreesTest', 'testTheInstructionOfANewUnitTakesItOutAgainByHandAtEachPoint' ),
			'expect'  => '/testTheInstructionOfANewUnitTakesItOutAgainByHandAtEachPoint/',
		),
		array(
			'id'      => 'ablage/b1-name-as-wordpress',
			'clause'  => 'is_tree_name(): a tree keeps the name its unit has (validate_file()), not the rule of the store\'s own names',
			'file'    => $ps,
			'search'  => 'return function_exists( \'validate_file\' ) && 0 === validate_file( $name );',
			'replace' => 'return self::valid_name( $name );',
			'run'     => $it( 'StoreTreesTest', 'testATreeKeepsTheNameItsUnitHasInTheSite' ),
			'expect'  => '/testATreeKeepsTheNameItsUnitHasInTheSite/',
		),
		array(
			'id'      => 'ablage/b1-dangerous-refused',
			'clause'  => 'is_tree_name(): a slash, a backslash, a control character or NUL is refused',
			'file'    => $ps,
			'search'  => ' || strlen( $name ) > 255 || preg_match( \'#[/\\\\\\\\\\x00-\\x1F\\x7F]#\', $name ) ) {',
			'replace' => ' || strlen( $name ) > 255 ) {',
			'run'     => $it( 'StoreTreesTest', 'testOnlyANameThatIsNoNameOfAFileIsRefused' ),
			'expect'  => '/testOnlyANameThatIsNoNameOfAFileIsRefused/',
		),
		array(
			'id'      => 'ablage/b1-length',
			'clause'  => 'is_tree_name(): more than 255 bytes are refused',
			'file'    => $ps,
			'search'  => ' || strlen( $name ) > 255 || ',
			'replace' => ' || ',
			'run'     => $it( 'StoreTreesTest', 'testOnlyANameThatIsNoNameOfAFileIsRefused' ),
			'expect'  => '/testOnlyANameThatIsNoNameOfAFileIsRefused/',
		),
		array(
			'id'      => 'ablage/b1-mark-tree-name',
			'clause'  => 'tree_rel(): a mark names a tree by the name its unit has',
			'file'    => $ps,
			'search'  => '! preg_match( \'/^[0-9a-f]{40}\\z/\', $parts[1] ) || ! self::is_tree_name( $parts[2] ) ) {',
			'replace' => '! preg_match( \'/^[0-9a-f]{40}\\z/\', $parts[1] ) || ! self::valid_name( $parts[2] ) ) {',
			'run'     => $it( 'StoreTreesTest', 'testATreeKeepsTheNameItsUnitHasInTheSite' ),
			'expect'  => '/testATreeKeepsTheNameItsUnitHasInTheSite/',
		),
		array(
			'id'      => 'ablage/b2-unread-not-empty',
			'clause'  => 'read_bookings(): bookings that are no JSON object are a fault, never the empty stock (B2, V1)',
			'file'    => $sb,
			'search'  => '		if ( ! is_array( $data ) ) {
			return self::unread();',
			'replace' => '		if ( ! is_array( $data ) ) {
			return array();',
			'run'     => $it( 'StoreBookingsTest', 'testBookingsThatAreNoBookingsAreNeitherEmptyNorWrittenOver' ),
			'expect'  => '/testBookingsThatAreNoBookingsAreNeitherEmptyNorWrittenOver/',
		),
		array(
			'id'      => 'ablage/b2-entry-checked',
			'clause'  => 'read_bookings(): an entry that is no booking is a fault, never left out',
			'file'    => $sb,
			'search'  => '				return self::unread();
			}
			$out[ $run_id ] = array(',
			'replace' => '				continue;
			}
			$out[ $run_id ] = array(',
			'run'     => $it( 'StoreBookingsTest', 'testBookingsThatAreNoBookingsAreNeitherEmptyNorWrittenOver' ),
			'expect'  => '/testBookingsThatAreNoBookingsAreNeitherEmptyNorWrittenOver/',
		),
		array(
			'id'      => 'ablage/b2-book-writes-nothing',
			'clause'  => 'book(): bookings that cannot be read are not written anew',
			'file'    => $sb,
			'search'  => '				$book = self::read_bookings( $root );
				if ( is_wp_error( $book ) ) {
					return $book;
				}
				$book = self::without_closed( $book );',
			'replace' => '				$book = self::read_bookings( $root );
				if ( is_wp_error( $book ) ) {
					$book = array();
				}
				$book = self::without_closed( $book );',
			'run'     => $it( 'StoreBookingsTest', 'testBookingsThatCannotBeReadAreNeitherEmptyNorWrittenOver' ),
			'expect'  => '/testBookingsThatCannotBeReadAreNeitherEmptyNorWrittenOver/',
		),
		array(
			'id'      => 'ablage/b2-absent-by-listing',
			'clause'  => 'read_small(): absent only where the folder lists the file not (an lstat() that fails is no proof)',
			'file'    => $ps,
			'search'  => '\'state\' => ( false !== $names && ! in_array( basename( $path ), $names, true ) ) ? \'absent\' : \'unreadable\',',
			'replace' => '\'state\' => \'absent\',',
			'run'     => $it( 'StoreBookingsTest', 'testBookingsWhoseReadFailsAreNeitherEmptyNorWrittenOver' ),
			'expect'  => '/testBookingsWhoseReadFailsAreNeitherEmptyNorWrittenOver/',
		),
		array(
			'id'      => 'ablage/b2-read-error',
			'clause'  => 'read_small(): a read that fails is unreadable, never absent (B5, the read of the seam)',
			'file'    => $ps,
			'search'  => '		return false === $bytes || ! is_string( $bytes ) ? array(
			\'state\' => \'unreadable\',',
			'replace' => '		return false === $bytes || ! is_string( $bytes ) ? array(
			\'state\' => \'absent\',',
			'run'     => $it( 'StoreBookingsTest', 'testBookingsWhoseReadFailsAreNeitherEmptyNorWrittenOver' ),
			'expect'  => '/testBookingsWhoseReadFailsAreNeitherEmptyNorWrittenOver/',
		),
		array(
			'id'      => 'ablage/b3-no-work-without-lock',
			'clause'  => 'uninstall(): without the lock of a folder nothing goes back and nothing goes (no $work() without it)',
			'file'    => $ps,
			'search'  => '			self::uninstall_locked( $root, $work, $until );',
			'replace' => '			if ( ! self::uninstall_locked( $root, $work, $until ) ) { $work(); }',
			'run'     => $it( 'StoreRemoverTest', 'testTheUninstallDoesNothingWhereTheLockCannotBeOpened' ),
			'expect'  => '/testTheUninstallDoesNothingWhereTheLockCannotBeOpened/',
		),
		array(
			'id'      => 'ablage/b3-deadline',
			'clause'  => 'uninstall_locked(): it asks for the lock until the time of the uninstall ends, not until the holder lets go',
			'file'    => $ps,
			'search'  => '			if ( ! $busy || microtime( true ) >= $until ) {',
			'replace' => '			if ( ! $busy ) {',
			'run'     => $it( 'StoreRemoverTest', 'testTheUninstallLeavesAFolderWhoseLockIsHeldPastItsTimeAsItIs' ),
			'expect'  => '/testTheUninstallLeavesAFolderWhoseLockIsHeldPastItsTimeAsItIs/',
		),
		array(
			'id'      => 'ablage/b3-no-blocking',
			'clause'  => 'uninstall_locked(): the lock is asked without waiting for the holder (LOCK_NB)',
			'file'    => $ps,
			'search'  => 'while ( ! flock( $handle, LOCK_EX | LOCK_NB, $busy ) ) {',
			'replace' => 'while ( ! flock( $handle, LOCK_EX, $busy ) ) {',
			'run'     => $it( 'StoreRemoverTest', 'testTheUninstallLeavesAFolderWhoseLockIsHeldPastItsTimeAsItIs' ),
			'expect'  => '/testTheUninstallLeavesAFolderWhoseLockIsHeldPastItsTimeAsItIs/',
		),
		array(
			'id'      => 'ablage/b4-gettext',
			'clause'  => 'close_mark(): a refusal that reaches an answer goes through gettext (B4)',
			'file'    => $ps,
			'search'  => '__( \'There is no such mark in the protected store. Pass the id put_mark() returned.\', \'alphabridge-mcp\' )',
			'replace' => '\'There is no such mark in the protected store. Pass the id put_mark() returned.\'',
			'run'     => $unit( 'StoreTextsTest', 'testEveryMessageOfTheStoreIsTranslatedOrAnOldTextUnchanged' ),
			'expect'  => '/testEveryMessageOfTheStoreIsTranslatedOrAnOldTextUnchanged/',
		),
		array(
			'id'      => 'ablage/b5-hash-identity',
			'clause'  => 'is_tree(): where the mark holds the hash of the manifest, the original goes back only with it',
			'file'    => $ps,
			'search'  => '		if ( null === $tree[\'hash\'] ) {
			return true;
		}
		$read = self::read_tree( $path );',
			'replace' => '		if ( true ) {
			return true;
		}
		$read = self::read_tree( $path );',
			'run'     => $it( 'StoreRemoverTest', 'testTheUninstallPutsBackABackupWhoseHashIsTheMarksAndNoneWhoseFilesChanged' ),
			'expect'  => '/testTheUninstallPutsBackABackupWhoseHashIsTheMarksAndNoneWhoseFilesChanged/',
		),
		array(
			'id'      => 'ablage/b5-file-compare',
			'clause'  => 'put_tree(): a tree of one file stays only with the size and SHA-256 of the file read before',
			'file'    => $ps,
			'search'  => '&& hash_equals( (string) $read[\'entries\'][0][2], (string) $back[\'entries\'][0][2] )',
			'replace' => '&& true',
			'run'     => $it( 'StoreTreesTest', 'testAFileTreeWhoseSourceChangesWhileItIsCopiedIsNotKept' ),
			'expect'  => '/testAFileTreeWhoseSourceChangesWhileItIsCopiedIsNotKept/',
		),
		array(
			'id'      => 'ablage/b5-write-lock-built-tree',
			'clause'  => 'write_file(): below a tree that is put whole nothing is written, not even a new file',
			'file'    => $ps,
			'search'  => '( count( $parts ) > 3 && ! isset( self::$building[',
			'replace' => '( count( $parts ) > 3 && false && ! isset( self::$building[',
			'run'     => $it( 'StoreTreesTest', 'testATreeIsNeverPutOverAnotherNorChangedInTheStore' ),
			'expect'  => '/testATreeIsNeverPutOverAnotherNorChangedInTheStore/',
		),
		array(
			'id'      => 'ablage/b5-folder-mode',
			'clause'  => 'copy_tree(): a folder whose mode cannot be set leaves nothing of the tree (apart from the mode of a file)',
			'file'    => $ps,
			'search'  => '			if ( ! $calls->chmod( $dir[0], $dir[1] ) ) {',
			'replace' => '			if ( ! $calls->chmod( $dir[0], $dir[1] ) && false ) {',
			'run'     => $it( 'StoreTreesTest', 'testAFolderOfATreeWhoseModeCannotBeSetLeavesNothingOfTheTree' ),
			'expect'  => '/testAFolderOfATreeWhoseModeCannotBeSetLeavesNothingOfTheTree/',
		),
	)
);

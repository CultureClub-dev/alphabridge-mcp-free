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
 * suite (StoreRemoverGuardTest).
 *
 * Not listed, with why: the Throwable caught around guard() and the walk in
 * delete_path() (a remover that throws keeps all, the case of each way
 * with a fault sees nothing removed either way); the 'with' of a group
 * whose folder is gone whole (the sidecar of a group removed whole goes,
 * ProtectedStoreTest holds the groups that go); the modes of the folders of
 * a tree set at the end (held by the snapshot of the normal case through
 * the mutation of the copy of a file mode, store/z7-copy-mode).
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
	)
);

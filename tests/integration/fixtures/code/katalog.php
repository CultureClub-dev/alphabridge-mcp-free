<?php
/**
 * The test plugins and themes of the integration suite (Z8b branch 1, E21):
 * each unit, its versions (one folder each beside this file:
 * plugins/<slug>/<version>/, themes/<slug>/<version>/) with what they are
 * for, and its state at the start on both sites. AB_MCP_IT_Code reads this
 * list; KatalogTest checks it against the folders.
 *
 * A plugin's 'main' are its main files as WordPress names them, 'aktiv'
 * those of them active at the start, in the order they are activated
 * (ab-it-basis before ab-it-braucht). 'single' is a plugin of one file in
 * the plugins folder. Every plugin sets the mark of its version as it loads
 * ($GLOBALS['ab_it_fassung'][slug], header X-AB-IT-Fassung-<slug>); those
 * with counters count their routines in the option ab_it_zaehler_<slug>
 * (aktiviert, deaktiviert, deinstalliert).
 *
 * @package AlphaBridge_MCP
 */

return array(
	'plugins'    => array(
		'ab-it-aktiv'       => array(
			'main'      => array( 'ab-it-aktiv/ab-it-aktiv.php' ),
			'fassungen' => array(
				'1.0.0' => 'Ausgangsstand, aktiv, Zähler (F2, F27–F29, F32)',
				'2.0.0' => 'neue Fassung, besteht die Probe (F2)',
				'fatal' => 'neue Fassung 2.0.1, Syntax richtig, Fatal beim Laden (Rücktausch)',
				'php'   => 'neue Fassung 2.0.2 mit Requires PHP: 99.0 (F20)',
			),
			'start'     => array(
				'fassung' => '1.0.0',
				'aktiv'   => array( 'ab-it-aktiv/ab-it-aktiv.php' ),
			),
			'zaehler'   => true,
		),
		'ab-it-inaktiv'     => array(
			'main'      => array( 'ab-it-inaktiv/ab-it-inaktiv.php' ),
			'fassungen' => array(
				'1.0.0' => 'Ausgangsstand, inaktiv',
				'1.1.0' => 'neue Fassung, auch als Paket des Stellvertreters für downloads.wordpress.org (F1)',
			),
			'start'     => array(
				'fassung' => '1.0.0',
				'aktiv'   => array(),
			),
		),
		'ab-it-einzeln'     => array(
			'main'      => array( 'ab-it-einzeln.php' ),
			'single'    => true,
			'fassungen' => array(
				'1.0.0' => 'Ausgangsstand, Einzeldatei, aktiv',
				'1.1.0' => 'neue Fassung (F6)',
			),
			'start'     => array(
				'fassung' => '1.0.0',
				'aktiv'   => array( 'ab-it-einzeln.php' ),
			),
			'zaehler'   => true,
		),
		'ab-it-zwei'        => array(
			'main'      => array( 'ab-it-zwei/ab-it-zwei-eins.php', 'ab-it-zwei/ab-it-zwei-zwei.php' ),
			'fassungen' => array(
				'1.0.0' => 'Ausgangsstand, zwei Hauptdateien, eine aktiv (F8)',
				'1.1.0' => 'neue Fassung beider Hauptdateien',
			),
			'start'     => array(
				'fassung' => '1.0.0',
				'aktiv'   => array( 'ab-it-zwei/ab-it-zwei-eins.php' ),
			),
			'zaehler'   => array( 'ab-it-zwei-eins', 'ab-it-zwei-zwei' ),
		),
		'ab-it-basis'       => array(
			'main'      => array( 'ab-it-basis/ab-it-basis.php' ),
			'fassungen' => array(
				'1.0.0' => 'Ausgangsstand, von ab-it-braucht verlangt (F8)',
				'1.1.0' => 'neue Fassung',
			),
			'start'     => array(
				'fassung' => '1.0.0',
				'aktiv'   => array( 'ab-it-basis/ab-it-basis.php' ),
			),
			'zaehler'   => true,
		),
		'ab-it-braucht'     => array(
			'main'      => array( 'ab-it-braucht/ab-it-braucht.php' ),
			'fassungen' => array(
				'1.0.0' => 'Ausgangsstand, Requires Plugins: ab-it-basis (F8)',
				'1.1.0' => 'neue Fassung',
			),
			'start'     => array(
				'fassung' => '1.0.0',
				'aktiv'   => array( 'ab-it-braucht/ab-it-braucht.php' ),
			),
			'zaehler'   => true,
		),
		'ab-it-routinen'    => array(
			'main'      => array( 'ab-it-routinen/ab-it-routinen.php' ),
			'fassungen' => array(
				'1.0.0' => 'Ausgangsstand, aktiv: Aktivierung legt die Tabelle <prefix>ab_it_routinen an, Deaktivierung schreibt die Option ab_it_routinen_deaktiviert, uninstall.php löscht die Tabelle (F18)',
				'1.1.0' => 'neue Fassung mit denselben Routinen',
			),
			'start'     => array(
				'fassung' => '1.0.0',
				'aktiv'   => array( 'ab-it-routinen/ab-it-routinen.php' ),
			),
			'zaehler'   => true,
		),
		'ab-it-migration'   => array(
			'main'      => array( 'ab-it-migration/ab-it-migration.php' ),
			'fassungen' => array(
				'1.0.0'           => 'Ausgangsstand, Schema 1 (Option ab_it_migration_schema); versteht Schema 1 und 2, scheitert bei 3',
				'1.0.0-migration' => 'dieselbe Versionsnummer 1.0.0, migriert auf Schema 2 (Migration ohne neue Versionsnummer)',
				'2.0.0-bruch'     => 'migriert auf Schema 3 und scheitert danach bei jedem Request; 1.0.0 scheitert dann auch (F34)',
			),
			'start'     => array(
				'fassung' => '1.0.0',
				'aktiv'   => array( 'ab-it-migration/ab-it-migration.php' ),
			),
			'zaehler'   => true,
		),
		'ab-it-zeitstempel' => array(
			'main'      => array( 'ab-it-zeitstempel/ab-it-zeitstempel.php' ),
			'fassungen' => array(
				'alt' => 'Ausgangsstand, Version 1.0.0, Marke alt',
				'neu' => 'gleiche Grösse und Version, andere Bytes, Marke neu; jede Kopie mit demselben Zeitstempel (F39)',
			),
			'start'     => array(
				'fassung' => 'alt',
				'aktiv'   => array( 'ab-it-zeitstempel/ab-it-zeitstempel.php' ),
			),
		),
		'ab-it-schreiber'   => array(
			'main'      => array( 'ab-it-schreiber/ab-it-schreiber.php' ),
			'fassungen' => array(
				'1.0.0' => 'Ausgangsstand, schreibt bei jedem Request log/zugriffe.log in seinen Ordner (F46)',
				'1.1.0' => 'neue Fassung, schreibt ebenso',
			),
			'start'     => array(
				'fassung' => '1.0.0',
				'aktiv'   => array( 'ab-it-schreiber/ab-it-schreiber.php' ),
			),
		),
		'ab-it-daten'       => array(
			'main'      => array( 'ab-it-daten/ab-it-daten.php' ),
			'fassungen' => array(
				'1.0.0' => 'Ausgangsstand mit der Datendatei data/export.csv (F47)',
				'1.1.0' => 'neue Fassung ohne data/export.csv',
			),
			'start'     => array(
				'fassung' => '1.0.0',
				'aktiv'   => array( 'ab-it-daten/ab-it-daten.php' ),
			),
		),
		'ab-it-dienst'      => array(
			'main'      => array( 'ab-it-dienst/ab-it-dienst.php' ),
			'fassungen' => array(
				'1.0.0' => 'Ausgangsstand, aktiv, Funktion ab_it_dienst_gruss() für ab-it-aufrufer (F48)',
				'1.1.0' => 'neue Fassung',
			),
			'start'     => array(
				'fassung' => '1.0.0',
				'aktiv'   => array( 'ab-it-dienst/ab-it-dienst.php' ),
			),
			'zaehler'   => true,
		),
		'ab-it-aufrufer'    => array(
			'main'      => array( 'ab-it-aufrufer/ab-it-aufrufer.php' ),
			'fassungen' => array(
				'1.0.0' => 'ruft auf der Startseite ab_it_dienst_gruss() und scheitert dort ohne ab-it-dienst (F48); am Start inaktiv, getrennt aktivierbar',
			),
			'start'     => array(
				'fassung' => '1.0.0',
				'aktiv'   => array(),
			),
			'zaehler'   => true,
		),
		'ab-it-allein'      => array(
			'main'      => array( 'ab-it-allein/ab-it-allein.php' ),
			'fassungen' => array(
				'1.0.0' => 'Ausgangsstand, aktiv, ohne Abhängige; die Probe besteht auch ohne es; Zähler (F51, F52)',
				'1.1.0' => 'neue Fassung',
			),
			'start'     => array(
				'fassung' => '1.0.0',
				'aktiv'   => array( 'ab-it-allein/ab-it-allein.php' ),
			),
			'zaehler'   => true,
		),
	),
	'themes'     => array(
		'ab-it-eltern'         => array(
			'fassungen' => array(
				'1.0.0' => 'Ausgangsstand, klassisches Eltern-Theme mit Menüort haupt',
				'1.1.0' => 'neue Fassung',
			),
			'start'     => '1.0.0',
		),
		'ab-it-kind'           => array(
			'fassungen' => array(
				'1.0.0' => 'Ausgangsstand, Kind von ab-it-eltern, am Start aktiv (F26)',
				'1.1.0' => 'neue Fassung mit geänderter functions.php (F9)',
			),
			'start'     => '1.0.0',
		),
		'ab-it-anderes-eltern' => array(
			'fassungen' => array(
				'1.0.0' => 'Eltern von ab-it-anderes-kind (F10: fehlt live)',
			),
			'start'     => '1.0.0',
		),
		'ab-it-anderes-kind'   => array(
			'fassungen' => array(
				'1.0.0' => 'anderes Kind-Theme, auf Staging zu wählen (F10)',
			),
			'start'     => '1.0.0',
		),
	),
	// Active on both sites after start(); its options are the values F26
	// compares (AB_MCP_IT_Code::theme_options()).
	'theme'      => 'ab-it-kind',
	// Must-use plugins: the observer of switch_theme (F26).
	'mu-plugins' => array( 'ab-it-beobachter.php' ),
);

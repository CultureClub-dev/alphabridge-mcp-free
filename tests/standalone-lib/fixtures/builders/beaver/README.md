# Beaver Builder: Messung für die Fixtures

Nachmessung vom 01.10.2026, weil die Messung des Plans (`alphabridge-docs: docs/recherche/2026-09-30-page-builder-messung/`,
`ergebnis-3-atomar-beaver-ersetzen.json`) von `_fl_builder_data` nur die ersten 1600 Bytes festhält.

- Wegwerf-Installation in WordPress Playground (`@wp-playground/cli` 3.1.56 aus dem npx-Cache), WordPress 7.1.2, PHP 8.3.33,
  Beaver Builder Lite 2.11.0.6 (dieselbe Fassung wie in der Messung des Plans), SQLite.
- Aufbau wie `messen.php`/`messen3.php` der Messung des Plans: Seite über `FLBuilderModel` (`add_row`, `add_module`), dann wie der
  Editor speichert (Entwurf = veröffentlicht, `save_layout()`). Dazu alle übrigen Lite-Module, jedes Text-, Textbereich-, Editor-
  und Link-Feld mit einer Marke, Seiten-CSS und -JS im Entwurf, danach der Entwurf allein geändert.
- `page.json`: `meta` = die Zeilen aus `wp_postmeta`, wie gespeichert; `post_content` wie gespeichert; `draft_changed` = der Entwurf
  nach der Änderung (das Veröffentlichte blieb byte-gleich); `shown` = ob der Wert im Ergebnis von `FLBuilder::render_content_by_id()`
  vorkam.
- Gegenprobe: Die ersten 1558 Bytes der Zeile (ohne die zufälligen Knoten-Ids) sind byte-gleich mit dem Anfang aus der Messung des
  Plans (`measured-start.json`, Test `BeaverAdapterTest::testTheFixtureHasTheFormatOfThePlansMeasurement`).
- Die Werte stammen aus einer Wegwerf-Installation (Testinhalte, Port und Nonces dieser Instanz).

Nachmessen (im Ordner mit diesen beiden Dateien, `out/` muss existieren):

```bash
npx -y @wp-playground/cli@3.1.56 run-blueprint --blueprint=blueprint.json --mount="$PWD:/scripts" --mount="$PWD/out:/out"
```

`blueprint.json`:

```json
{
  "preferredVersions": { "php": "8.3", "wp": "latest" },
  "steps": [
    { "step": "installPlugin", "pluginData": { "resource": "url", "url": "https://downloads.wordpress.org/plugin/beaver-builder-lite-version.2.11.0.6.zip" } },
    { "step": "runPHP", "code": "<?php require '/scripts/messen-bb.php';" }
  ]
}
```

`messen-bb.php`:

```php
<?php
// Nachmessung Beaver Builder Lite 2.11.0.6 (01.10.2026) fuer die Test-Fixtures des Lese-Adapters.
// Aufbau wie messen.php/messen3.php der Messung vom 30.09.2026 (FLBuilderModel, save_layout), dazu:
// alle Lite-Module mit ihren registrierten Feldtypen, eine Seite mit Text in jedem Textfeld,
// was davon im gerenderten HTML erscheint, Seiten-CSS/JS und ein Entwurf, der vom Veroeffentlichten abweicht.
require_once '/wordpress/wp-load.php';
wp_set_current_user( 1 );
$e = array( 'wp' => get_bloginfo( 'version' ), 'php' => PHP_VERSION, 'beaver' => defined( 'FL_BUILDER_VERSION' ) ? FL_BUILDER_VERSION : null );
function roh( $id ) { global $wpdb; $a = array(); foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id=%d ORDER BY meta_key, meta_id", $id ), ARRAY_A ) as $z ) { $a[ $z['meta_key'] ] = $z['meta_value']; } return $a; }
function pc( $id ) { global $wpdb; return (string) $wpdb->get_var( $wpdb->prepare( "SELECT post_content FROM {$wpdb->posts} WHERE ID=%d", $id ) ); }
function felder( $form ) {
	$aus = array();
	foreach ( FLBuilderModel::get_settings_form_fields( $form ) as $name => $f ) {
		$z = array( 'type' => $f['type'] );
		if ( ! empty( $f['multiple'] ) ) { $z['multiple'] = true; }
		if ( ! empty( $f['connections'] ) ) { $z['connections'] = $f['connections']; }
		if ( isset( $f['form'] ) && is_string( $f['form'] ) ) { $z['form'] = $f['form']; }
		$aus[ $name ] = $z;
	}
	return $aus;
}
try {
	// Registrierung: Feldtypen je Modul, Unterformulare, Zeile und Spalte.
	$e['module'] = array();
	foreach ( FLBuilderModel::$modules as $slug => $m ) {
		$e['module'][ $slug ] = array( 'klasse' => get_class( $m ), 'felder' => felder( $m->form ) );
	}
	$e['unterformulare'] = array();
	foreach ( FLBuilderModel::$settings_forms as $fid => $form ) {
		if ( isset( $form['tabs'] ) ) { $e['unterformulare'][ $fid ] = felder( $form['tabs'] ); }
	}

	// Testbild wie in der SeedProd-Messung (Logo aus dem Kern).
	require_once ABSPATH . 'wp-admin/includes/image.php';
	$hoch = wp_upload_bits( 'alpine-testbild.png', null, file_get_contents( ABSPATH . 'wp-admin/images/w-logo-blue.png' ) );
	$bild = wp_insert_attachment( array( 'post_mime_type' => 'image/png', 'post_title' => 'Alpine Testbild', 'post_status' => 'inherit' ), $hoch['file'] );
	wp_update_attachment_metadata( $bild, wp_generate_attachment_metadata( $bild, $hoch['file'] ) );
	update_post_meta( $bild, '_wp_attachment_image_alt', 'Testbild Alpine' );
	$bild_url = wp_get_attachment_url( $bild );
	$e['bild'] = array( 'id' => $bild, 'url' => $bild_url );

	$id = wp_insert_post( array( 'post_title' => 'Beaver Messseite', 'post_type' => 'page', 'post_status' => 'publish', 'post_content' => '' ) );
	$e['seite'] = $id;
	FLBuilderModel::set_post_id( $id );
	FLBuilderModel::enable();
	$spalte = function ( $row ) { foreach ( FLBuilderModel::get_layout_data() as $k ) { if ( 'column' === $k->type && FLBuilderModel::get_node_parent_by_type( $k, 'row' ) && FLBuilderModel::get_node_parent_by_type( $k, 'row' )->node === $row ) { return $k->node; } } return null; };

	// Zeile 1: die vier Module der Positivliste mit dem Testinhalt der Messung, dann die gesperrten.
	$r1 = FLBuilderModel::add_row( '1-col' );
	$c1 = $spalte( $r1->node );
	FLBuilderModel::add_module( 'heading', (object) array( 'heading' => 'Willkommen bei Alpine Bikes', 'tag' => 'h1', 'link' => 'https://example.com/ueber-uns' ), $c1 );
	FLBuilderModel::add_module( 'rich-text', (object) array( 'text' => '<p>Öffnungszeiten: Mo–Fr 9–18 Uhr</p>' ), $c1 );
	FLBuilderModel::add_module( 'button', (object) array( 'text' => 'Jetzt reservieren', 'link' => 'https://example.com/reservieren' ), $c1 );
	FLBuilderModel::add_module( 'photo', (object) array( 'photo_source' => 'library', 'photo' => $bild, 'photo_src' => $bild_url, 'link_type' => 'url', 'link_url' => 'https://example.com/galerie', 'show_caption' => 'below', 'caption' => 'Bildunterschrift Alpine' ), $c1 );
	FLBuilderModel::add_module( 'html', (object) array( 'html' => '<div class="mz-roh">Rohes HTML</div><script>window.mzRoh=1;</script>' ), $c1 );
	FLBuilderModel::add_module( 'widget', (object) array( 'widget' => 'WP_Widget_Text', 'widget-text' => (object) array( 'title' => 'Widget-Titel MZ', 'text' => 'Widget-Text MZ' ) ), $c1 );
	$box = FLBuilderModel::add_module( 'box', (object) array(), $c1 );
	FLBuilderModel::add_module( 'heading', (object) array( 'heading' => 'Ueberschrift in der Box', 'tag' => 'h2' ), $box->node );

	// Zeile 2: alle weiteren Lite-Module, jedes Text-, Textbereich-, Editor- und Link-Feld mit einer Marke.
	$r2 = FLBuilderModel::add_row( '1-col' );
	$c2 = $spalte( $r2->node );
	$marken = array();
	foreach ( array( 'callout', 'cta', 'icon', 'numbers', 'star-rating', 'button-group', 'video', 'audio', 'menu', 'sidebar', 'reusable-block' ) as $slug ) {
		if ( empty( FLBuilderModel::$modules[ $slug ] ) ) { $e['fehlende_module'][] = $slug; continue; }
		$s = new stdClass();
		foreach ( FLBuilderModel::get_settings_form_fields( FLBuilderModel::$modules[ $slug ]->form ) as $name => $f ) {
			if ( in_array( $f['type'], array( 'text', 'textarea', 'editor' ), true ) && empty( $f['multiple'] ) ) {
				$s->$name = 'MZ ' . $slug . ' ' . $name;
				$marken[ $slug . '.' . $name ] = $s->$name;
			} elseif ( 'link' === $f['type'] ) {
				$s->$name = 'https://example.com/mz-' . $slug . '-' . $name;
				$marken[ $slug . '.' . $name ] = $s->$name;
			} elseif ( 'code' === $f['type'] ) {
				$s->$name = '<b class="mz-code">MZ code ' . $slug . ' ' . $name . '</b>';
				$marken[ $slug . '.' . $name ] = 'MZ code ' . $slug . ' ' . $name;
			}
		}
		if ( 'button-group' === $slug ) {
			$s->items = array( (object) array( 'text' => 'MZ button-group item text', 'link' => 'https://example.com/mz-button-group-item' ) );
			$marken['button-group.items.text'] = 'MZ button-group item text';
			$marken['button-group.items.link'] = 'https://example.com/mz-button-group-item';
		}
		if ( 'video' === $slug ) { $s->video_type = 'embed'; }
		FLBuilderModel::add_module( $slug, $s, $c2 );
	}

	// Wie der Editor speichert: Entwurf = veroeffentlicht, Seiten-CSS/JS im Entwurf, dann save_layout().
	FLBuilderModel::update_layout_data( FLBuilderModel::get_layout_data( 'published', $id ), 'draft', $id );
	$ls = FLBuilderModel::get_layout_settings( 'draft', $id );
	$ls->css = '.mz-seiten-css{color:red}';
	$ls->js  = 'window.mzSeitenJs=1;';
	FLBuilderModel::update_layout_settings( $ls, 'draft', $id );
	FLBuilderModel::save_layout();

	$e['zustand_1'] = array( 'meta' => roh( $id ), 'post_content' => pc( $id ), 'post_content_filtered' => get_post_field( 'post_content_filtered', $id ), 'revisionen' => count( wp_get_post_revisions( $id ) ) );

	// Was die Seite zeigt.
	ob_start();
	FLBuilder::render_content_by_id( $id );
	$html = ob_get_clean();
	$e['html_laenge'] = strlen( $html );
	$sichtbar = array();
	foreach ( array_merge( array( 'heading.heading' => 'Willkommen bei Alpine Bikes', 'heading.link' => 'https://example.com/ueber-uns', 'rich-text.text' => 'Mo–Fr 9–18 Uhr', 'button.text' => 'Jetzt reservieren', 'button.link' => 'https://example.com/reservieren', 'photo.photo_src' => $bild_url, 'photo.link_url' => 'https://example.com/galerie', 'photo.caption' => 'Bildunterschrift Alpine', 'photo.alt' => 'Testbild Alpine', 'html.html' => '<script>window.mzRoh=1;</script>', 'widget.title' => 'Widget-Titel MZ', 'widget.text' => 'Widget-Text MZ', 'box.heading' => 'Ueberschrift in der Box' ), $marken ) as $k => $nadel ) {
		$sichtbar[ $k ] = false !== strpos( $html, $nadel ) || false !== strpos( $html, esc_url( $nadel ) ) || false !== strpos( $html, esc_html( $nadel ) );
	}
	$e['im_html'] = $sichtbar;
	$e['html_ausschnitt'] = substr( $html, 0, 6000 );

	// Entwurf aendern, ohne zu veroeffentlichen (wie ein offener, nicht veroeffentlichter Editor).
	$entwurf = FLBuilderModel::get_layout_data( 'draft', $id );
	foreach ( $entwurf as $k ) {
		if ( 'module' === $k->type && isset( $k->settings->type ) && 'rich-text' === $k->settings->type ) {
			$k->settings->text = '<p>Öffnungszeiten: Mo–Sa 9–17 Uhr</p>';
		}
	}
	FLBuilderModel::update_layout_data( $entwurf, 'draft', $id );
	$e['zustand_2_entwurf_geaendert'] = array( 'meta' => roh( $id ), 'post_content' => pc( $id ) );
} catch ( \Throwable $t ) {
	$e['fehler'] = get_class( $t ) . ': ' . $t->getMessage() . ' @ ' . basename( $t->getFile() ) . ':' . $t->getLine();
}
file_put_contents( '/out/beaver-nachmessung.json', wp_json_encode( $e, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
echo "ok\n";
```

# SeedProd: Messung für die Fixtures

- `page.json`, `code-page.json`: Nachmessung vom 01.10.2026, weil die Messung des Plans
  (`alphabridge-docs: docs/recherche/2026-09-30-page-builder-messung/seedprod-pagelayer/`) das JSON in `post_content_filtered` nur in
  Ausschnitten festhält. WordPress 7.1.2, PHP 8.3.33, SeedProd Lite 6.20.10 (`coming-soon`, dieselbe Fassung), WordPress Playground
  mit `@wp-playground/cli` 3.1.56. Dieselben Seiten wie `web/sp.php` (Schritte `anlegen` und `code`), gespeichert über SeedProds
  Ability `seedprod/save-page`; die Codeseite zusätzlich mit einem Video-Block mit Einbettungscode und einer Überschrift.
  Das JSON der ersten Seite hat 1500 Zeichen; in der Messung des Plans waren es 1499 bei einem um eine Ziffer kürzeren Port in der
  Bildadresse (`sp-1-anlegen.json`). Über die Ability gespeichert bleibt `post_content` leer (gemessen, wie in `sp-1`).
- `editor-html.json`: Was SeedProds Editor (im Browser) nach dem Speichern in `post_content` schrieb, aus der Messung des Plans:
  die Ausschnitte um jedes Feld aus `sp-2-nach-editor.json` und `sp-3-alphabridge.json` (nach einer Änderung nur am post_content)
  und das ganze post_content der Codeseite aus `sp-8-code-nach-editor.json`.

Nachmessen (im Ordner mit diesen Dateien, `out/` muss existieren):

```bash
npx -y @wp-playground/cli@3.1.56 run-blueprint --blueprint=blueprint.json --mount="$PWD:/scripts" --mount="$PWD/out:/out"
```

`blueprint.json`:

```json
{
  "preferredVersions": { "php": "8.3", "wp": "latest" },
  "steps": [
    { "step": "installPlugin", "pluginData": { "resource": "url", "url": "https://downloads.wordpress.org/plugin/coming-soon.6.20.10.zip" } },
    { "step": "runPHP", "code": "<?php require '/scripts/messen-sp.php';" }
  ]
}
```

`messen-sp.php`:

```php
<?php
// Nachmessung SeedProd Lite 6.20.10 (01.10.2026): dieselben Seiten wie seedprod-pagelayer/web/sp.php
// (Schritte "anlegen" und "code"), angelegt ueber SeedProds Ability seedprod/save-page; hier wird das
// ganze JSON in post_content_filtered festgehalten. Dazu ein Video-Block mit Einbettungscode.
require_once '/wordpress/wp-load.php';
wp_set_current_user( 1 );
$e = array( 'wp' => get_bloginfo( 'version' ), 'php' => PHP_VERSION, 'seedprod' => defined( 'SEEDPROD_VERSION' ) ? SEEDPROD_VERSION : null );
function zeile( $id ) { global $wpdb; return $wpdb->get_row( $wpdb->prepare( "SELECT post_content, post_content_filtered, post_status FROM {$wpdb->posts} WHERE ID=%d", $id ), ARRAY_A ); }
function meta( $id ) { global $wpdb; $a = array(); foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id=%d ORDER BY meta_key", $id ), ARRAY_A ) as $z ) { $a[ $z['meta_key'] ] = $z['meta_value']; } return $a; }
try {
	require_once ABSPATH . 'wp-admin/includes/image.php';
	$hoch = wp_upload_bits( 'alpine-velo.png', null, file_get_contents( ABSPATH . 'wp-admin/images/w-logo-blue.png' ) );
	$bild = wp_insert_attachment( array( 'post_mime_type' => 'image/png', 'post_title' => 'Alpine Velo', 'post_status' => 'inherit' ), $hoch['file'] );
	wp_update_attachment_metadata( $bild, wp_generate_attachment_metadata( $bild, $hoch['file'] ) );
	$bild_url = wp_get_attachment_url( $bild );
	$fl = array( 'bgStyle' => 's', 'paddingTop' => '10', 'paddingBottom' => '10', 'paddingLeft' => '10', 'paddingRight' => '10' );
	$sections = array( array(
		'id' => 'sec001', 'type' => 'section', 'settings' => $fl + array( 'contentWidth' => 1, 'width' => '1000' ),
		'rows' => array( array(
			'id' => 'row001', 'type' => 'row', 'colType' => '1-col', 'settings' => $fl + array( 'colGutter' => 0, 'contentWidth' => 2, 'width' => '1000' ),
			'cols' => array( array(
				'id' => 'col001', 'type' => 'col', 'settings' => $fl,
				'blocks' => array(
					array( 'id' => 'blk001', 'elType' => 'block', 'type' => 'header', 'settings' => array( 'headerTxt' => 'Willkommen bei Alpine Bikes', 'tag' => 'h1' ) ),
					array( 'id' => 'blk002', 'elType' => 'block', 'type' => 'text', 'settings' => array( 'txt' => '<p>Öffnungszeiten: Mo–Fr 9–18 Uhr</p>' ) ),
					array( 'id' => 'blk003', 'elType' => 'block', 'type' => 'button', 'settings' => array( 'btnTxt' => 'Jetzt reservieren', 'link' => 'https://example.com/reservieren' ) ),
					array( 'id' => 'blk004', 'elType' => 'block', 'type' => 'image', 'settings' => array( 'src' => $bild_url, 'altTxt' => 'Velo vor dem Laden' ) ),
				),
			) ),
		) ),
	) );
	$res = wp_get_ability( 'seedprod/save-page' )->execute( array( 'type' => 'landing-page', 'title' => 'SeedProd Messseite', 'status' => 'publish', 'sections' => $sections ) );
	$e['save_page'] = is_wp_error( $res ) ? $res->get_error_code() . ': ' . $res->get_error_message() : $res;
	$id = is_array( $res ) ? (int) $res['id'] : 0;
	$e['seite'] = array( 'id' => $id, 'zeile' => zeile( $id ), 'meta' => meta( $id ) );

	$sections = array( array( 'id' => 'sec101', 'type' => 'section', 'settings' => array( 'bgStyle' => 's' ), 'rows' => array( array( 'id' => 'row101', 'type' => 'row', 'colType' => '1-col', 'settings' => array( 'bgStyle' => 's' ), 'cols' => array( array( 'id' => 'col101', 'type' => 'col', 'settings' => array( 'bgStyle' => 's' ), 'blocks' => array(
		array( 'id' => 'blk101', 'elType' => 'block', 'type' => 'custom-html', 'settings' => array( 'code' => '<div class="mz-roh">Rohes HTML</div><script>window.mzRoh=1;</script>' ) ),
		array( 'id' => 'blk102', 'elType' => 'block', 'type' => 'shortcode', 'settings' => array( 'shortcode' => '[caption id="mz1" width="100"]Bildunterschrift-MZ[/caption]' ) ),
		array( 'id' => 'blk103', 'elType' => 'block', 'type' => 'video', 'settings' => array( 'code' => '<iframe src="https://example.com/mz-video"></iframe>' ) ),
		array( 'id' => 'blk104', 'elType' => 'block', 'type' => 'header', 'settings' => array( 'headerTxt' => 'MZ Ueberschrift auf der Codeseite', 'tag' => 'h2' ) ),
	) ) ) ) ) ) );
	$res = wp_get_ability( 'seedprod/save-page' )->execute( array( 'type' => 'landing-page', 'title' => 'SeedProd Codeseite', 'status' => 'publish', 'sections' => $sections ) );
	$e['save_code'] = is_wp_error( $res ) ? $res->get_error_code() . ': ' . $res->get_error_message() : $res;
	$cid = is_array( $res ) ? (int) $res['id'] : 0;
	// Seiten-Skript so ablegen wie in sp.php (Schluessel header_scripts im JSON).
	global $wpdb;
	$json = json_decode( zeile( $cid )['post_content_filtered'], true );
	$json['header_scripts'] = '<script>window.mzKopf=1;</script>';
	$wpdb->update( $wpdb->posts, array( 'post_content_filtered' => wp_json_encode( $json ) ), array( 'ID' => $cid ) );
	clean_post_cache( $cid );
	$e['codeseite'] = array( 'id' => $cid, 'zeile' => zeile( $cid ), 'meta' => meta( $cid ) );
	$e['blockvorlagen'] = isset( $GLOBALS['seedprod_lite_block_templates'] ) ? array_keys( $GLOBALS['seedprod_lite_block_templates'] ) : null;
} catch ( \Throwable $t ) {
	$e['fehler'] = get_class( $t ) . ': ' . $t->getMessage() . ' @ ' . basename( $t->getFile() ) . ':' . $t->getLine();
}
file_put_contents( '/out/seedprod-nachmessung.json', wp_json_encode( $e, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
echo "ok\n";
```

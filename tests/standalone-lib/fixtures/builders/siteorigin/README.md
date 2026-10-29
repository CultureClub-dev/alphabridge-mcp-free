# SiteOrigin Page Builder: Messung für die Fixtures

- `page-a.json`: Seite A der Messung des Plans (`alphabridge-docs: docs/recherche/2026-09-30-page-builder-messung/meta/1-siteorigin/`,
  30.09.2026). Die Messung hält `panels_data` dekodiert fest (`nutzlast-A-dekodiert.json`); erneut serialisiert, mit
  `_sow_form_timestamp` als Gleitkommazahl wie im Rohausschnitt (`werkzeug/rohausschnitte-button.txt`: `d:1790792162010`), stimmen
  Länge (3766) und md5-Anfang (`ee3d5a2c05`) mit `ergebnis-2-zustand-nach-aufruf.json` überein; der Test prüft beides.
  `post_content`: die ersten 1500 von 5843 Bytes, wie `ergebnis-2` sie festhält.
- `page-more.json`: Nachmessung vom 01.10.2026 mit denselben Fassungen (WordPress 7.1.2, PHP 8.3.33, Page Builder by SiteOrigin
  2.36.1, SiteOrigin Widgets Bundle 1.74.3, WordPress Playground mit `@wp-playground/cli` 3.1.56). Gespeichert wie der klassische
  Editor (wie `meta/1-siteorigin/messen.php`: `$_POST['panels_data']` mit Nonce, `wp_update_post()` im Admin-Kontext), dann ohne
  Admin-Kontext mit `siteorigin_panels_render()` gerendert. `shown` = ob der Wert im gerenderten HTML vorkam.
  Beobachtet: `attributes.on_click` war nach dem Speichern `null`; ein Shortcode im Editor-Text lief beim Rendern.

Nachmessen (im Ordner mit diesen Dateien, `out/` muss existieren):

```bash
npx -y @wp-playground/cli@3.1.56 run-blueprint --blueprint=blueprint.json --mount="$PWD:/scripts" --mount="$PWD/out:/out"
```

`blueprint.json`:

```json
{
  "preferredVersions": { "php": "8.3", "wp": "latest" },
  "steps": [
    { "step": "installPlugin", "pluginData": { "resource": "url", "url": "https://downloads.wordpress.org/plugin/siteorigin-panels.2.36.1.zip" } },
    { "step": "installPlugin", "pluginData": { "resource": "url", "url": "https://downloads.wordpress.org/plugin/so-widgets-bundle.1.74.3.zip" } },
    { "step": "runPHP", "code": "<?php define( 'WP_ADMIN', true ); require '/scripts/messen-so.php';" },
    { "step": "runPHP", "code": "<?php require '/scripts/zeigen-so.php';" }
  ]
}
```

`messen-so.php`:

```php
<?php
// Nachmessung SiteOrigin 2.36.1 + Widgets Bundle 1.74.3 (01.10.2026): eine Seite mit Text in weiteren Feldern,
// Code-Widgets, Shortcode im Editor, Stilfeldern und zwei Zeilen. Speichern wie der klassische Editor
// (wie meta/1-siteorigin/messen.php): $_POST['panels_data'] + Nonce, wp_update_post() im Admin-Kontext.
require_once '/wordpress/wp-load.php';
wp_set_current_user( 1 );
$e = array( 'wp' => get_bloginfo( 'version' ), 'php' => PHP_VERSION, 'panels' => defined( 'SITEORIGIN_PANELS_VERSION' ) ? SITEORIGIN_PANELS_VERSION : null, 'is_admin' => is_admin(), 'admin_klasse' => class_exists( 'SiteOrigin_Panels_Admin' ) );
try {
	global $wp_widget_factory;
	if ( empty( $wp_widget_factory->widgets['SiteOrigin_Widget_Headline_Widget'] ) ) {
		SiteOrigin_Widgets_Bundle::single()->activate_widget( 'headline', true );
	}
	require_once ABSPATH . 'wp-admin/includes/image.php';
	$hoch = wp_upload_bits( 'alpine-testbild.png', null, file_get_contents( ABSPATH . 'wp-admin/images/w-logo-blue.png' ) );
	$bild = wp_insert_attachment( array( 'post_mime_type' => 'image/png', 'post_title' => 'Alpine Testbild', 'post_status' => 'inherit' ), $hoch['file'] );
	wp_update_attachment_metadata( $bild, wp_generate_attachment_metadata( $bild, $hoch['file'] ) );
	$w = function ( $klasse, $werte, $i, $grid, $cell, $stil = array() ) {
		return array_merge( $werte, array( 'panels_info' => array( 'class' => $klasse, 'raw' => false, 'grid' => $grid, 'cell' => $cell, 'id' => $i, 'widget_id' => wp_generate_uuid4(), 'style' => $stil ) ) );
	};
	$layout = array(
		'widgets'    => array(
			$w( 'SiteOrigin_Widget_Headline_Widget', array( 'headline' => array( 'text' => 'Willkommen bei Alpine Bikes', 'tag' => 'h1', 'destination_url' => 'https://example.com/mz-headline-link' ), 'sub_headline' => array( 'text' => 'MZ Unterzeile', 'tag' => 'p', 'destination_url' => 'https://example.com/mz-sub-link' ), 'divider' => array( 'style' => 'none' ) ), 0, 0, 0 ),
			$w( 'SiteOrigin_Widget_Editor_Widget', array( 'title' => 'MZ Editor-Titel', 'text' => '<p>Öffnungszeiten: Mo–Fr 9–18 Uhr</p>', 'text_selected_editor' => 'tinymce', 'autop' => true ), 1, 0, 0, array( 'class' => 'mz-klasse', 'widget_css' => 'color: red;', 'id' => 'mz-id' ) ),
			$w( 'SiteOrigin_Widget_Editor_Widget', array( 'title' => '', 'text' => '<p>Mit Shortcode: [caption id="mz1" width="100"]MZ Shortcode-Text[/caption]</p>', 'text_selected_editor' => 'tinymce', 'autop' => true ), 2, 0, 1 ),
			$w( 'SiteOrigin_Widget_Button_Widget', array( 'text' => 'Jetzt reservieren', 'url' => 'https://example.com/reservieren', 'new_window' => false, 'attributes' => array( 'title' => 'MZ Button-Titel', 'on_click' => 'window.mzKlick=1', 'id' => 'mz-button-id', 'classes' => 'mz-button-klasse' ) ), 3, 0, 1 ),
			$w( 'SiteOrigin_Widget_Image_Widget', array( 'image' => (int) $bild, 'size' => 'full', 'alt' => 'Testbild Alpine', 'title' => 'MZ Bild-Titel', 'url' => 'https://example.com/mz-bild-link' ), 4, 1, 0 ),
			$w( 'WP_Widget_Custom_HTML', array( 'title' => 'MZ HTML-Titel', 'content' => '<div class="mz-roh">Rohes HTML</div><script>window.mzRoh=1;</script>' ), 5, 1, 0 ),
			$w( 'WP_Widget_Text', array( 'title' => 'MZ Text-Widget-Titel', 'text' => 'MZ Text-Widget-Text', 'filter' => true, 'visual' => true ), 6, 1, 0 ),
		),
		'grids'      => array( array( 'cells' => 2, 'style' => array( 'class' => 'mz-zeile', 'row_css' => 'background: blue;' ) ), array( 'cells' => 1, 'style' => array() ) ),
		'grid_cells' => array( array( 'grid' => 0, 'index' => 0, 'weight' => 0.5, 'style' => array() ), array( 'grid' => 0, 'index' => 1, 'weight' => 0.5, 'style' => array( 'cell_css' => 'padding: 1px;' ) ), array( 'grid' => 1, 'index' => 0, 'weight' => 1, 'style' => array() ) ),
	);
	$id = wp_insert_post( array( 'post_title' => 'SiteOrigin Nachmessung', 'post_type' => 'page', 'post_status' => 'publish', 'post_content' => '' ) );
	$_POST['_sopanels_nonce'] = wp_create_nonce( 'save' );
	$_POST['panels_data']     = wp_slash( wp_json_encode( $layout ) );
	$r = wp_update_post( array( 'ID' => $id ), true );
	unset( $_POST['_sopanels_nonce'], $_POST['panels_data'] );
	$e['speichern'] = is_wp_error( $r ) ? $r->get_error_message() : $r;
	$e['seite'] = $id;
	$e['bild'] = $bild;
	global $wpdb;
	$e['panels_data_roh'] = $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key='panels_data'", $id ) );
	$e['post_content'] = $wpdb->get_var( $wpdb->prepare( "SELECT post_content FROM {$wpdb->posts} WHERE ID=%d", $id ) );
	$e['meta_schluessel'] = $wpdb->get_col( $wpdb->prepare( "SELECT meta_key FROM {$wpdb->postmeta} WHERE post_id=%d ORDER BY meta_key", $id ) );
	$e['einstellungen'] = array_intersect_key( siteorigin_panels_setting(), array_flip( array( 'copy-content', 'copy-styles' ) ) );
} catch ( \Throwable $t ) {
	$e['fehler'] = get_class( $t ) . ': ' . $t->getMessage() . ' @ ' . basename( $t->getFile() ) . ':' . $t->getLine();
}
file_put_contents( '/out/so-nachmessung-1.json', wp_json_encode( $e, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
echo "ok\n";
```

`zeigen-so.php`:

```php
<?php
// Zweiter Schritt ohne Admin-Kontext: was rendert SiteOrigin aus panels_data?
require_once '/wordpress/wp-load.php';
$vor = json_decode( file_get_contents( '/out/so-nachmessung-1.json' ), true );
$e = array();
try {
	$id = (int) $vor['seite'];
	$GLOBALS['post'] = get_post( $id );
	setup_postdata( $GLOBALS['post'] );
	$html = siteorigin_panels_render( $id );
	$e['html_laenge'] = strlen( $html );
	$nadeln = array(
		'headline.text' => 'Willkommen bei Alpine Bikes', 'headline.destination_url' => 'https://example.com/mz-headline-link',
		'sub_headline.text' => 'MZ Unterzeile', 'sub_headline.destination_url' => 'https://example.com/mz-sub-link',
		'editor.title' => 'MZ Editor-Titel', 'editor.text' => 'Mo–Fr 9–18 Uhr', 'editor.shortcode_roh' => '[caption', 'editor.shortcode_text' => 'MZ Shortcode-Text',
		'button.text' => 'Jetzt reservieren', 'button.url' => 'https://example.com/reservieren', 'button.attributes.title' => 'MZ Button-Titel', 'button.attributes.on_click' => 'window.mzKlick=1',
		'image.alt' => 'Testbild Alpine', 'image.title' => 'MZ Bild-Titel', 'image.url' => 'https://example.com/mz-bild-link',
		'custom_html.title' => 'MZ HTML-Titel', 'custom_html.content' => '<script>window.mzRoh=1;</script>',
		'text_widget.title' => 'MZ Text-Widget-Titel', 'text_widget.text' => 'MZ Text-Widget-Text',
		'stil.widget_css' => 'color: red', 'stil.row_css' => 'background: blue', 'stil.cell_css' => 'padding: 1px', 'stil.class' => 'mz-klasse',
	);
	foreach ( $nadeln as $k => $n ) {
		$e['im_html'][ $k ] = false !== strpos( $html, $n ) || false !== strpos( $html, esc_attr( $n ) ) || false !== strpos( $html, esc_html( $n ) );
	}
	$e['html'] = $html;
} catch ( \Throwable $t ) {
	$e['fehler'] = get_class( $t ) . ': ' . $t->getMessage() . ' @ ' . basename( $t->getFile() ) . ':' . $t->getLine();
}
file_put_contents( '/out/so-nachmessung-2.json', wp_json_encode( $e, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
echo "ok\n";
```

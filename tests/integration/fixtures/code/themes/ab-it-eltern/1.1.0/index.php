<?php
/**
 * The only template of ab-it-eltern: head, a mark, footer.
 *
 * @package AlphaBridge_MCP
 */

?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<p class="ab-it-theme"><?php echo esc_html( get_stylesheet() ); ?></p>
<?php wp_footer(); ?>
</body>
</html>

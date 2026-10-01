<?php
/**
 * A stand-in for the one piece of Beaver Builder the reader asks: the post
 * types the builder is enabled for, FLBuilderModel::get_post_types() in
 * Beaver Builder Lite 2.11.0.6 (classes/class-fl-builder-model.php:406-421 on
 * plugins.svn.wordpress.org/beaver-builder-lite-version/tags/2.11.0.6/). No
 * Beaver code is copied. Null — the default — stands for "Beaver gave no
 * list", so a test that does not set it reads as without Beaver loaded.
 *
 * @package AlphaBridge_MCP
 */

/**
 * Beaver's model, reduced to what the reader reads.
 */
class FLBuilderModel {

	/** @var array|null What get_post_types() returns; a test sets it and puts null back. */
	public static $post_types = null;

	/**
	 * The post types Beaver renders.
	 *
	 * @return array|null
	 */
	public static function get_post_types() {
		return self::$post_types;
	}
}

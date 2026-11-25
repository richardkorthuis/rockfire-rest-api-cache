<?php
/**
 * Contract of an admin page of the plugin.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Admin\Pages;

defined( 'ABSPATH' ) || exit;

/**
 * An admin page under the top-level "REST API Cache" menu.
 *
 * {@see \Rockfire\Rest_Api_Cache\Admin\Admin} registers the page as a submenu, checks the
 * capability before {@see self::load()} and {@see self::render()}, prints the `wrap` and the page
 * title around {@see self::render()}, loads the assets and registers {@see self::actions()} as
 * `admin-post.php` handlers. A page never checks the plugin capability. The callbacks of
 * {@see self::actions()} never verify a nonce or redirect: the action handler does both and
 * redirects to the URL they return. The only exception is a list-table bulk action in
 * {@see self::load()} (a GET form of the list itself), which verifies its own bulk nonce and ends with
 * `Redirect::safe_and_exit()`. An action may require an additional capability of its own (the
 * must-use install needs the plugin-management capability) and then ends with `wp_die()` (403).
 *
 * @since 1.0.0
 */
interface Page_Interface {

	/**
	 * Page slug (the `page` query argument), e.g. `rfrac-tools`.
	 *
	 * @return string
	 */
	public function slug(): string;

	/**
	 * Translated page title (the `<h1>` and the document title).
	 *
	 * @return string
	 */
	public function page_title(): string;

	/**
	 * Translated submenu label.
	 *
	 * @return string
	 */
	public function menu_title(): string;

	/**
	 * Order in the menu; the lowest position is the landing page of the top-level menu.
	 *
	 * @return int
	 */
	public function position(): int;

	/**
	 * Runs on `load-{hook}` after the capability check and `rfrac/admin_page_load`,
	 * before any output: screen options, help tabs and list-table bulk actions (which verify their own
	 * nonce and end with `Redirect::safe_and_exit()`).
	 *
	 * @return void
	 */
	public function load(): void;

	/**
	 * Print the page body (inside the `wrap`, after the title). Escape all output.
	 *
	 * @return void
	 */
	public function render(): void;

	/**
	 * The state-changing actions of the page, handled through `admin-post.php?action=rfrac_{name}`.
	 *
	 * Name (a key, unique over all pages) => callback receiving the sanitised `target` (`''` when none)
	 * and returning the redirect URL; queue feedback with `Notices`. An entry may instead be
	 * `array( 'callback' => $callback, 'per_target_nonce' => false )` when the target is chosen in the
	 * form (the nonce is then `rfrac_{name}` for every target).
	 *
	 * @return array<string,callable|array{callback:callable,per_target_nonce?:bool}>
	 */
	public function actions(): array;
}

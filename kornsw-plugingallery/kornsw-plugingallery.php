<?php
/**
 * Plugin Name: KornSW Plugin Gallery
 * Update URI: https://raw.githubusercontent.com/KornSW/WP-PluginGallery/master/doc/kornsw-plugingallery.update.json
 * Plugin URI: https://github.com/KornSW/WP-PluginGallery
 * Description: Schnelle WordPress-Plugin-Galerie auf Basis von GitHub-Repositories, WordPress-kompatiblen *.update.json-Dateien und Favoriten aus dem WordPress.org-Plugin-Verzeichnis. Enthält zusätzlich einen Installer für Plugin-ZIP-URLs.
 * Version: 1.0.6
 * Author: KornSW
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * License: GPL-3.0
 * Text Domain: kornsw-plugingallery
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*************** SELF-UPDATE ***************/
define( 'KSWKORNSWGALLER06FB_SELF_UPDATE_DIAGNOSTICS', false );
require_once __DIR__ . '/self-update.php';
kswkornswgaller06fb_bootstrap( __FILE__ );
/*******************************************/

define('KORNSW_PG_FILE', __FILE__);
define('KORNSW_PG_DIR', plugin_dir_path(__FILE__));
define('KORNSW_PG_URL', plugin_dir_url(__FILE__));
define('KORNSW_PG_VERSION', get_file_data(__FILE__, ['Version' => 'Version'])['Version']);

spl_autoload_register(function (string $className): void {
  $namespacePrefix = 'KornSW\\PluginGallery\\';
  if (strncmp($className, $namespacePrefix, strlen($namespacePrefix)) !== 0) {
    return;
  }
  $relativeClassName = substr($className, strlen($namespacePrefix));
  $fileName = KORNSW_PG_DIR . 'src/' . str_replace('\\', '/', $relativeClassName) . '.php';
  if (is_readable($fileName)) {
    require_once $fileName;
  }
});

add_action('plugins_loaded', [\KornSW\PluginGallery\Plugin::class, 'boot']);

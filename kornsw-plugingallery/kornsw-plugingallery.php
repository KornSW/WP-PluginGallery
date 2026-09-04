<?php
/**
 * Plugin Name: KornSW Plugin Gallery
 * Update URI: https://raw.githubusercontent.com/KornSW/WP-PluginGallery/master/doc/kornsw-plugingallery.update.json
 * Plugin URI: https://github.com/KornSW/WP-PluginGallery
 * Description: Schnelle WordPress-Plugin-Galerie auf Basis von GitHub-Repositories und WordPress-kompatiblen *.update.json-Dateien. Enthält zusätzlich einen Installer für Plugin-ZIP-URLs.
 * Version: 1.0.1
 * Author: KornSW
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: kornsw-plugingallery
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*************** SELF-UPDATE ***************/
define( 'KSWKORNSWGALLER06FB_SELF_UPDATE_DIAGNOSTICS', false );
require_once __DIR__ . '/self-update.php';
kswkornswgaller06fb_bootstrap( __FILE__ );
/*******************************************/


define( 'KORNSW_PLUGIN_GALLERY_VERSION', '0.1.3' );
define( 'KORNSW_PLUGIN_GALLERY_FILE', __FILE__ );
define( 'KORNSW_PLUGIN_GALLERY_DIR', plugin_dir_path( __FILE__ ) );
define( 'KORNSW_PLUGIN_GALLERY_URL', plugin_dir_url( __FILE__ ) );

require_once KORNSW_PLUGIN_GALLERY_DIR . 'includes/class-kornsw-plugin-gallery.php';

KornSW_Plugin_Gallery::instance()->register();

<?php

declare(strict_types=1);

namespace KornSW\PluginGallery;

use KornSW\PluginGallery\Admin\AdminActions;
use KornSW\PluginGallery\Admin\GalleryPage;
use KornSW\PluginGallery\Catalog\CatalogBuilder;
use KornSW\PluginGallery\Catalog\RepositoryScanner;
use KornSW\PluginGallery\Catalog\UpdateJsonReader;
use KornSW\PluginGallery\Catalog\WordPressOrgDirectory;
use KornSW\PluginGallery\GitHub\GitHubClient;
use KornSW\PluginGallery\GitHub\GitHubTokenStore;
use KornSW\PluginGallery\Installer\PluginInstaller;
use KornSW\PluginGallery\Security\SecretCipher;

if (!defined('ABSPATH')) {
  exit;
}

/**
 * Composition root: wires the services and registers the WordPress hooks (admin only).
 */
final class Plugin {

  public const MENU_SLUG = 'kornsw-plugin-gallery';
  public const REQUIRED_CAPABILITY = 'install_plugins';

  private static ?Plugin $instance = null;

  private GalleryPage $galleryPage;
  private AdminActions $adminActions;

  private function __construct() {
    $configuration = new SourceConfiguration();
    $tokenStore = new GitHubTokenStore(new SecretCipher());
    $gitHubClient = new GitHubClient($tokenStore);
    $updateJsonReader = new UpdateJsonReader();
    $wordPressOrgDirectory = new WordPressOrgDirectory();
    $repositoryScanner = new RepositoryScanner($gitHubClient, $updateJsonReader);
    $catalogBuilder = new CatalogBuilder(
      $configuration,
      $gitHubClient,
      $repositoryScanner,
      $updateJsonReader,
      $wordPressOrgDirectory
    );
    $installer = new PluginInstaller();

    $this->galleryPage = new GalleryPage($catalogBuilder, $configuration, $installer, $gitHubClient, $tokenStore);
    $this->adminActions = new AdminActions(
      $catalogBuilder,
      $configuration,
      $repositoryScanner,
      $updateJsonReader,
      $wordPressOrgDirectory,
      $installer,
      $gitHubClient,
      $tokenStore
    );
  }

  /**
   * Entry point, called on "plugins_loaded".
   */
  public static function boot(): void {
    if (self::$instance !== null || !is_admin()) {
      return;
    }
    self::$instance = new self();
    self::$instance->registerHooks();
  }

  private function registerHooks(): void {
    add_action('admin_menu', [$this->galleryPage, 'registerMenu']);
    add_action('admin_enqueue_scripts', [$this->galleryPage, 'enqueueAssets']);
    add_filter('plugin_action_links_' . plugin_basename(KORNSW_PG_FILE), [$this->galleryPage, 'addPluginActionLinks']);
    $this->adminActions->register();
  }
}

<?php

declare(strict_types=1);

namespace KornSW\PluginGallery\Admin;

use KornSW\PluginGallery\Catalog\CatalogBuilder;
use KornSW\PluginGallery\Catalog\RepositoryScanner;
use KornSW\PluginGallery\Catalog\UpdateJsonReader;
use KornSW\PluginGallery\Catalog\WordPressOrgDirectory;
use KornSW\PluginGallery\GitHub\GitHubClient;
use KornSW\PluginGallery\GitHub\GitHubTokenStore;
use KornSW\PluginGallery\Installer\PluginInstaller;
use KornSW\PluginGallery\Plugin;
use KornSW\PluginGallery\Source;
use KornSW\PluginGallery\SourceConfiguration;

if (!defined('ABSPATH')) {
  exit;
}

/**
 * State-changing requests of the gallery page (admin-post.php). Every handler checks capability and nonce.
 */
final class AdminActions {

  private CatalogBuilder $catalogBuilder;
  private SourceConfiguration $configuration;
  private RepositoryScanner $repositoryScanner;
  private UpdateJsonReader $updateJsonReader;
  private WordPressOrgDirectory $wordPressOrgDirectory;
  private PluginInstaller $installer;
  private GitHubClient $gitHubClient;
  private GitHubTokenStore $tokenStore;

  public function __construct(
    CatalogBuilder $catalogBuilder,
    SourceConfiguration $configuration,
    RepositoryScanner $repositoryScanner,
    UpdateJsonReader $updateJsonReader,
    WordPressOrgDirectory $wordPressOrgDirectory,
    PluginInstaller $installer,
    GitHubClient $gitHubClient,
    GitHubTokenStore $tokenStore
  ) {
    $this->catalogBuilder = $catalogBuilder;
    $this->configuration = $configuration;
    $this->repositoryScanner = $repositoryScanner;
    $this->updateJsonReader = $updateJsonReader;
    $this->wordPressOrgDirectory = $wordPressOrgDirectory;
    $this->installer = $installer;
    $this->gitHubClient = $gitHubClient;
    $this->tokenStore = $tokenStore;
  }

  public function register(): void {
    add_action('admin_post_kornsw_pg_refresh', [$this, 'handleRefresh']);
    add_action('admin_post_kornsw_pg_add_source', [$this, 'handleAddSource']);
    add_action('admin_post_kornsw_pg_remove_source', [$this, 'handleRemoveSource']);
    add_action('admin_post_kornsw_pg_install_plugin', [$this, 'handleInstallPlugin']);
    add_action('admin_post_kornsw_pg_install_zip_url', [$this, 'handleInstallZipUrl']);
    add_action('admin_post_kornsw_pg_discover', [$this, 'handleDiscover']);
    add_action('admin_post_kornsw_pg_add_discovered', [$this, 'handleAddDiscovered']);
    add_action('admin_post_kornsw_pg_save_github_token', [$this, 'handleSaveGitHubToken']);
    add_action('admin_post_kornsw_pg_remove_github_token', [$this, 'handleRemoveGitHubToken']);
  }

  public function handleRefresh(): void {
    $this->assertCapability(Plugin::REQUIRED_CAPABILITY);
    check_admin_referer('kornsw_pg_refresh');
    $this->extendTimeLimit();
    $this->catalogBuilder->getCatalog(true);
    $this->redirectWithNotice('success', 'Galerie wurde aktualisiert.');
  }

  public function handleAddSource(): void {
    $this->assertCapability(Plugin::REQUIRED_CAPABILITY);
    check_admin_referer('kornsw_pg_add_source');
    $input = '';
    if (isset($_POST['source_url'])) {
      $input = sanitize_text_field(wp_unslash((string) $_POST['source_url']));
    }
    $source = Source::parse($input);
    if ($source === null) {
      $this->redirectWithNotice('error', 'Ungültige Quelle. Erwartet wird eine URL oder ein WordPress.org-Plugin-Slug.');
    }

    if ($source->getType() === Source::TYPE_GITHUB) {
      $probe = $this->repositoryScanner->scan($source->getOwner(), $source->getRepository());
      $this->repositoryScanner->flush();
      if (count($probe['entries']) === 0) {
        $reason = 'keine lesbare *.update.json unter /doc';
        if (count($probe['errors']) > 0) {
          $reason = (string) $probe['errors'][0]['error'];
        }
        $this->redirectWithNotice('error', 'Repository kann nicht verwendet werden: ' . $reason);
      }
    } elseif ($source->getType() === Source::TYPE_WPORG) {
      $probe = $this->wordPressOrgDirectory->read($source->getSlug());
      if (!$probe['ok']) {
        $this->redirectWithNotice('error', $probe['error']);
      }
    } else {
      if (!wp_http_validate_url($source->getUrl())) {
        $this->redirectWithNotice('error', 'Ungültige URL.');
      }
      $probe = $this->updateJsonReader->read($source->getUrl(), $source->getUrl());
      if (!$probe['ok']) {
        $this->redirectWithNotice('error', 'Update-JSON konnte nicht gelesen werden: ' . $probe['error']);
      }
    }

    if (!$this->configuration->addExtraSource($source)) {
      $this->redirectWithNotice('success', 'Quelle war bereits vorhanden.');
    }
    $this->catalogBuilder->invalidate();
    $this->redirectWithNotice('success', 'Quelle wurde dauerhaft hinzugefügt.');
  }

  public function handleRemoveSource(): void {
    $this->assertCapability(Plugin::REQUIRED_CAPABILITY);
    $index = -1;
    if (isset($_POST['source_index'])) {
      $index = absint($_POST['source_index']);
    }
    check_admin_referer('kornsw_pg_remove_source_' . $index);
    $this->configuration->removeExtraSource($index);
    $this->catalogBuilder->invalidate();
    $this->redirectWithNotice('success', 'Quelle wurde entfernt.');
  }

  public function handleInstallPlugin(): void {
    $this->assertCapability(Plugin::REQUIRED_CAPABILITY);
    $packageUrl = '';
    if (isset($_POST['package'])) {
      $packageUrl = esc_url_raw(wp_unslash((string) $_POST['package']));
    }
    check_admin_referer('kornsw_pg_install_' . md5($packageUrl));
    $this->installAndRedirect($packageUrl);
  }

  public function handleInstallZipUrl(): void {
    $this->assertCapability(Plugin::REQUIRED_CAPABILITY);
    check_admin_referer('kornsw_pg_install_zip_url');
    $zipUrl = '';
    if (isset($_POST['zip_url'])) {
      $zipUrl = esc_url_raw(wp_unslash((string) $_POST['zip_url']));
    }
    if ($zipUrl === '' || !wp_http_validate_url($zipUrl)) {
      $this->redirectWithNotice('error', 'Ungültige ZIP-URL.');
    }
    $path = wp_parse_url($zipUrl, PHP_URL_PATH);
    if (!is_string($path) || preg_match('/\.zip$/i', $path) !== 1) {
      $this->redirectWithNotice('error', 'Die URL muss auf eine ZIP-Datei zeigen.');
    }
    $this->installAndRedirect($zipUrl);
  }

  public function handleDiscover(): void {
    $this->assertCapability(Plugin::REQUIRED_CAPABILITY);
    check_admin_referer('kornsw_pg_discover');
    $this->extendTimeLimit();
    $candidates = $this->catalogBuilder->discoverCandidates();
    set_transient(GalleryPage::TRANSIENT_DISCOVERY, $candidates, 30 * MINUTE_IN_SECONDS);
    $this->redirectWithNotice('success', count($candidates) . ' neue Plugin-Kandidat(en) gefunden.', ['kornsw_pg_discovery' => '1']);
  }

  public function handleAddDiscovered(): void {
    $this->assertCapability(Plugin::REQUIRED_CAPABILITY);
    check_admin_referer('kornsw_pg_add_discovered');
    $selectedUrls = [];
    if (isset($_POST['json_urls']) && is_array($_POST['json_urls'])) {
      $selectedUrls = array_map('esc_url_raw', wp_unslash($_POST['json_urls']));
    }
    $allowedUrls = [];
    $discovery = get_transient(GalleryPage::TRANSIENT_DISCOVERY);
    if (is_array($discovery)) {
      foreach ($discovery as $candidate) {
        $allowedUrls[(string) $candidate['json_url']] = true;
      }
    }

    $addedCount = 0;
    foreach ($selectedUrls as $url) {
      if (!isset($allowedUrls[$url])) {
        continue;
      }
      $source = Source::parse($url);
      if ($source !== null && $this->configuration->addExtraSource($source)) {
        $addedCount++;
      }
    }
    delete_transient(GalleryPage::TRANSIENT_DISCOVERY);
    $this->catalogBuilder->invalidate();
    $this->redirectWithNotice('success', $addedCount . ' Plugin-Quelle(n) hinzugefügt.');
  }

  public function handleSaveGitHubToken(): void {
    $this->assertCapability('manage_options');
    check_admin_referer('kornsw_pg_save_github_token');
    $token = '';
    if (isset($_POST['github_token'])) {
      $token = trim(sanitize_text_field(wp_unslash((string) $_POST['github_token'])));
    }
    if ($token === '') {
      // An empty field keeps the stored token.
      $this->redirectWithNotice('success', 'Kein neuer Token eingegeben, bestehender Token bleibt erhalten.', [], 'kornsw-pg-github');
    }
    if (preg_match('/^[A-Za-z0-9_]+$/', $token) !== 1) {
      $this->redirectWithNotice('error', 'Der Token enthält ungültige Zeichen.', [], 'kornsw-pg-github');
    }
    $validation = $this->gitHubClient->validateToken($token);
    if (!$validation['ok']) {
      $this->redirectWithNotice('error', 'Token wurde nicht gespeichert: ' . $validation['message'], [], 'kornsw-pg-github');
    }
    if (!$this->tokenStore->save($token)) {
      $this->redirectWithNotice('error', 'Token konnte nicht verschlüsselt gespeichert werden (OpenSSL fehlt).', [], 'kornsw-pg-github');
    }
    $this->catalogBuilder->invalidate();
    $this->redirectWithNotice('success', $validation['message'], [], 'kornsw-pg-github');
  }

  public function handleRemoveGitHubToken(): void {
    $this->assertCapability('manage_options');
    check_admin_referer('kornsw_pg_remove_github_token');
    $this->tokenStore->delete();
    $this->gitHubClient->forgetRateLimitStatus();
    $this->redirectWithNotice('success', 'Gespeicherter GitHub-Token wurde entfernt.', [], 'kornsw-pg-github');
  }

  private function installAndRedirect(string $packageUrl): void {
    $result = $this->installer->installPackage($packageUrl);
    if (!$result['ok']) {
      $this->redirectWithNotice('error', 'Installation fehlgeschlagen: ' . $result['error']);
    }
    $this->redirectWithNotice('success', 'Plugin wurde installiert. Es erscheint jetzt normal in der WordPress-Plugin-Liste.');
  }

  private function assertCapability(string $capability): void {
    if (!current_user_can($capability)) {
      wp_die(esc_html__('Keine Berechtigung.', 'kornsw-plugingallery'));
    }
  }

  /**
   * Scanning many repositories can take longer than the default execution time.
   */
  private function extendTimeLimit(): void {
    if (function_exists('set_time_limit')) {
      set_time_limit(300);
    }
  }

  /**
   * @param array<string, string> $extraArguments
   * @return never
   */
  private function redirectWithNotice(string $type, string $message, array $extraArguments = [], string $anchor = ''): void {
    $arguments = array_merge(
      ['page' => Plugin::MENU_SLUG, 'kornsw_pg_notice_type' => $type, 'kornsw_pg_notice' => rawurlencode($message)],
      $extraArguments
    );
    $url = add_query_arg($arguments, admin_url('plugins.php'));
    if ($anchor !== '') {
      $url .= '#' . $anchor;
    }
    wp_safe_redirect($url);
    exit;
  }
}

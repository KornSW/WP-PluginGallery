<?php

declare(strict_types=1);

namespace KornSW\PluginGallery\Installer;

if (!defined('ABSPATH')) {
  exit;
}

/**
 * Installation and installed-state detection.
 * Packages from GitHub / ZIP URLs are installed with the core Plugin_Upgrader; WordPress.org plugins use the
 * regular core installer screen (update.php?action=install-plugin), exactly like "Plugins > Add New".
 */
final class PluginInstaller {

  /** @var array<string, array<string, string>>|null */
  private ?array $installedPlugins = null;

  /**
   * Installs a plugin ZIP from a URL.
   *
   * @return array{ok: bool, error: string}
   */
  public function installPackage(string $packageUrl): array {
    if ($packageUrl === '' || !wp_http_validate_url($packageUrl)) {
      return ['ok' => false, 'error' => 'Ungültige Paket-URL.'];
    }
    require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
    require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/misc.php';

    $skin = new \Automatic_Upgrader_Skin();
    $upgrader = new \Plugin_Upgrader($skin);
    $result = $upgrader->install($packageUrl);
    if (is_wp_error($result)) {
      return ['ok' => false, 'error' => $result->get_error_message()];
    }
    if ($result === false || $result === null) {
      $errors = $skin->get_errors();
      $message = 'Unbekannter Installationsfehler.';
      if (is_wp_error($errors) && $errors->has_errors()) {
        $message = $errors->get_error_message();
      }
      return ['ok' => false, 'error' => $message];
    }
    $this->installedPlugins = null;
    return ['ok' => true, 'error' => ''];
  }

  /**
   * Core installer URL for a WordPress.org plugin (nonce included).
   */
  public function getWordPressOrgInstallUrl(string $slug): string {
    return wp_nonce_url(self_admin_url('update.php?action=install-plugin&plugin=' . rawurlencode($slug)), 'install-plugin_' . $slug);
  }

  /**
   * Core "plugin details" modal URL (thickbox) for a WordPress.org plugin.
   */
  public function getWordPressOrgDetailsUrl(string $slug): string {
    return self_admin_url('plugin-install.php?tab=plugin-information&plugin=' . rawurlencode($slug) . '&TB_iframe=true&width=772&height=700');
  }

  /**
   * Core activation URL for an installed plugin file (nonce included).
   */
  public function getActivationUrl(string $pluginFile): string {
    return wp_nonce_url(self_admin_url('plugins.php?action=activate&plugin=' . rawurlencode($pluginFile)), 'activate-plugin_' . $pluginFile);
  }

  /**
   * Installed state of a catalog entry. Matching order: plugin folder = slug, then Plugin URI, then name.
   *
   * @param array<string, mixed> $entry
   * @return array{installed: bool, file: string, version: string, active: bool}
   */
  public function getInstalledState(array $entry): array {
    $plugins = $this->getInstalledPlugins();
    $slug = strtolower((string) ($entry['slug'] ?? ''));
    $homepage = untrailingslashit(strtolower((string) ($entry['homepage'] ?? '')));
    $name = strtolower(trim((string) ($entry['name'] ?? '')));

    $matchedFile = '';
    if ($slug !== '') {
      foreach (array_keys($plugins) as $file) {
        $lowerFile = strtolower((string) $file);
        if (strpos($lowerFile, $slug . '/') === 0 || $lowerFile === $slug . '.php') {
          $matchedFile = (string) $file;
          break;
        }
      }
    }
    if ($matchedFile === '') {
      foreach ($plugins as $file => $data) {
        $installedHomepage = untrailingslashit(strtolower((string) ($data['PluginURI'] ?? '')));
        $installedName = strtolower(trim((string) ($data['Name'] ?? '')));
        if (($homepage !== '' && $installedHomepage === $homepage) || ($name !== '' && $installedName === $name)) {
          $matchedFile = (string) $file;
          break;
        }
      }
    }

    if ($matchedFile === '') {
      return ['installed' => false, 'file' => '', 'version' => '', 'active' => false];
    }
    return [
      'installed' => true,
      'file' => $matchedFile,
      'version' => (string) ($plugins[$matchedFile]['Version'] ?? ''),
      'active' => is_plugin_active($matchedFile),
    ];
  }

  /**
   * @return array<string, array<string, string>>
   */
  private function getInstalledPlugins(): array {
    if ($this->installedPlugins === null) {
      require_once ABSPATH . 'wp-admin/includes/plugin.php';
      $this->installedPlugins = get_plugins();
    }
    return $this->installedPlugins;
  }
}

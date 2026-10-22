<?php

declare(strict_types=1);

namespace KornSW\PluginGallery\Catalog;

if (!defined('ABSPATH')) {
  exit;
}

/**
 * Reads plugin information from the official WordPress.org plugin directory via the core function plugins_api().
 */
final class WordPressOrgDirectory {

  /**
   * @return array{ok: bool, entry: array<string, mixed>, error: string}
   */
  public function read(string $slug): array {
    require_once ABSPATH . 'wp-admin/includes/plugin-install.php';

    $information = plugins_api('plugin_information', [
      'slug' => $slug,
      'fields' => [
        'sections' => false,
        'short_description' => true,
        'icons' => true,
        'banners' => false,
        'reviews' => false,
        'ratings' => false,
        'rating' => true,
        'active_installs' => true,
        'downloaded' => false,
        'versions' => false,
        'contributors' => false,
        'screenshots' => false,
        'tags' => false,
        'donate_link' => false,
      ],
    ]);

    if (is_wp_error($information)) {
      return ['ok' => false, 'entry' => [], 'error' => 'WordPress.org: ' . $information->get_error_message()];
    }
    $data = (array) $information;
    if (empty($data['name']) || empty($data['version'])) {
      return ['ok' => false, 'entry' => [], 'error' => 'WordPress.org kennt kein Plugin "' . $slug . '".'];
    }

    return ['ok' => true, 'entry' => $this->toEntry($slug, $data), 'error' => ''];
  }

  /**
   * @return array<string, mixed>
   */
  private function toEntry(string $slug, array $data): array {
    $homepage = 'https://wordpress.org/plugins/' . $slug . '/';
    return [
      'key' => 'wporg:' . $slug,
      'kind' => 'wporg',
      'name' => html_entity_decode(wp_strip_all_tags((string) $data['name']), ENT_QUOTES, 'UTF-8'),
      'version' => (string) $data['version'],
      'author' => html_entity_decode(wp_strip_all_tags((string) ($data['author'] ?? '')), ENT_QUOTES, 'UTF-8'),
      'homepage' => $homepage,
      'download_url' => (string) ($data['download_link'] ?? ''),
      'requires' => $this->stringOrEmpty($data['requires'] ?? ''),
      'requires_php' => $this->stringOrEmpty($data['requires_php'] ?? ''),
      'tested' => $this->stringOrEmpty($data['tested'] ?? ''),
      'description' => esc_html(html_entity_decode(wp_strip_all_tags((string) ($data['short_description'] ?? '')), ENT_QUOTES, 'UTF-8')),
      'slug' => $slug,
      'json_url' => '',
      'source' => $homepage,
      'icon_url' => $this->pickIcon($data['icons'] ?? []),
      'active_installs' => (int) ($data['active_installs'] ?? 0),
      'rating' => (int) ($data['rating'] ?? 0),
    ];
  }

  /**
   * The API returns false instead of an empty string for some unknown fields.
   *
   * @param mixed $value
   */
  private function stringOrEmpty($value): string {
    if (is_string($value) || is_int($value) || is_float($value)) {
      return (string) $value;
    }
    return '';
  }

  /**
   * @param mixed $icons
   */
  private function pickIcon($icons): string {
    if (!is_array($icons)) {
      return '';
    }
    foreach (['2x', '1x', 'svg', 'default'] as $size) {
      if (!empty($icons[$size])) {
        return (string) $icons[$size];
      }
    }
    return '';
  }
}

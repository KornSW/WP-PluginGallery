<?php

declare(strict_types=1);

namespace KornSW\PluginGallery\Catalog;

if (!defined('ABSPATH')) {
  exit;
}

/**
 * Loads WordPress-compatible *.update.json files (as written by the KVU release flow) and turns them into
 * gallery entries.
 */
final class UpdateJsonReader {

  /**
   * @return array{ok: bool, entry: array<string, mixed>, error: string}
   */
  public function read(string $jsonUrl, string $sourceUrl): array {
    $response = wp_safe_remote_get($jsonUrl, [
      'timeout' => 12,
      'redirection' => 5,
      'headers' => ['Accept' => 'application/json'],
    ]);
    if (is_wp_error($response)) {
      return ['ok' => false, 'entry' => [], 'error' => $response->get_error_message()];
    }
    $status = (int) wp_remote_retrieve_response_code($response);
    if ($status !== 200) {
      return ['ok' => false, 'entry' => [], 'error' => 'HTTP ' . $status];
    }
    $data = json_decode(wp_remote_retrieve_body($response), true);
    if (!is_array($data)) {
      return ['ok' => false, 'entry' => [], 'error' => 'Ungültiges JSON'];
    }
    if (empty($data['name']) || empty($data['version']) || empty($data['download_url'])) {
      return ['ok' => false, 'entry' => [], 'error' => 'Update-JSON enthält nicht die benötigten Felder.'];
    }
    return ['ok' => true, 'entry' => $this->toEntry($data, $jsonUrl, $sourceUrl), 'error' => ''];
  }

  /**
   * @return array<string, mixed>
   */
  private function toEntry(array $data, string $jsonUrl, string $sourceUrl): array {
    $fileName = basename((string) wp_parse_url($jsonUrl, PHP_URL_PATH));
    $slug = strtolower((string) preg_replace('/\.update\.json$/i', '', $fileName));
    if ($slug === 'update') {
      // Legacy single-plugin layout "doc/update.json" does not carry the slug.
      $slug = '';
    }
    return [
      'key' => 'json:' . strtolower($jsonUrl),
      'kind' => 'github',
      'name' => (string) $data['name'],
      'version' => (string) $data['version'],
      'author' => (string) ($data['author'] ?? ''),
      'homepage' => (string) ($data['homepage'] ?? ''),
      'download_url' => (string) $data['download_url'],
      'requires' => (string) ($data['requires'] ?? ''),
      'requires_php' => (string) ($data['requires_php'] ?? ''),
      'tested' => (string) ($data['tested'] ?? ''),
      'description' => (string) ($data['sections']['description'] ?? ''),
      'slug' => $slug,
      'json_url' => $jsonUrl,
      'source' => $sourceUrl,
      'icon_url' => '',
      'active_installs' => 0,
      'rating' => 0,
    ];
  }
}

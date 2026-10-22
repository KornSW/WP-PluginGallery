<?php

declare(strict_types=1);

namespace KornSW\PluginGallery;

if (!defined('ABSPATH')) {
  exit;
}

/**
 * Access to the fixed favorites (config.php) and the additional sources stored by the administrator.
 */
final class SourceConfiguration {

  public const OPTION_EXTRA_SOURCES = 'kornsw_pg_extra_sources';

  private string $configFile;

  public function __construct(string $configFile = '') {
    if ($configFile === '') {
      $configFile = KORNSW_PG_DIR . 'config.php';
    }
    $this->configFile = $configFile;
  }

  /**
   * Favorites from config.php, de-duplicated, in configuration order.
   *
   * @return Source[]
   */
  public function getFavoriteSources(): array {
    $entries = [];
    if (is_file($this->configFile)) {
      $entries = require $this->configFile;
    }
    if (!is_array($entries)) {
      return [];
    }
    $sources = [];
    foreach ($entries as $entry) {
      $source = Source::parse((string) $entry);
      if ($source !== null && !isset($sources[$source->getKey()])) {
        $sources[$source->getKey()] = $source;
      }
    }
    return array_values($sources);
  }

  /**
   * Distinct GitHub owners of the favorites (case-insensitive, first spelling wins).
   *
   * @return string[]
   */
  public function getFavoriteGitHubOwners(): array {
    $owners = [];
    foreach ($this->getFavoriteSources() as $source) {
      if ($source->getType() === Source::TYPE_GITHUB) {
        $key = strtolower($source->getOwner());
        if (!isset($owners[$key])) {
          $owners[$key] = $source->getOwner();
        }
      }
    }
    return array_values($owners);
  }

  /**
   * Additional sources stored by the administrator, keyed by their storage index.
   *
   * @return array<int, Source>
   */
  public function getExtraSources(): array {
    $sources = [];
    foreach ($this->getStoredExtraSources() as $index => $stored) {
      $source = Source::parse((string) ($stored['url'] ?? ''));
      if ($source !== null) {
        $sources[$index] = $source;
      }
    }
    return $sources;
  }

  /**
   * Adds a source unless it is already stored or a favorite. Returns false for duplicates.
   */
  public function addExtraSource(Source $source): bool {
    foreach ($this->getFavoriteSources() as $favorite) {
      if ($favorite->getKey() === $source->getKey()) {
        return false;
      }
    }
    foreach ($this->getExtraSources() as $existing) {
      if ($existing->getKey() === $source->getKey()) {
        return false;
      }
    }
    $stored = $this->getStoredExtraSources();
    $stored[] = ['type' => $this->storageType($source), 'url' => $source->getUrl()];
    $this->saveStoredExtraSources($stored);
    return true;
  }

  /**
   * Removes the source at the given storage index.
   */
  public function removeExtraSource(int $index): void {
    $stored = $this->getStoredExtraSources();
    if (isset($stored[$index])) {
      array_splice($stored, $index, 1);
      $this->saveStoredExtraSources($stored);
    }
  }

  /**
   * Raw option content. The format ['type' => 'repository'|'json'|'wporg', 'url' => ...] is kept
   * compatible with earlier plugin versions.
   *
   * @return array<int, array<string, string>>
   */
  private function getStoredExtraSources(): array {
    $stored = get_option(self::OPTION_EXTRA_SOURCES, []);
    if (!is_array($stored)) {
      return [];
    }
    $result = [];
    foreach (array_values($stored) as $entry) {
      if (is_array($entry) && !empty($entry['url'])) {
        $result[] = ['type' => (string) ($entry['type'] ?? ''), 'url' => (string) $entry['url']];
      }
    }
    return $result;
  }

  private function saveStoredExtraSources(array $stored): void {
    update_option(self::OPTION_EXTRA_SOURCES, array_values($stored), false);
  }

  private function storageType(Source $source): string {
    if ($source->getType() === Source::TYPE_GITHUB) {
      return 'repository';
    }
    return $source->getType();
  }
}

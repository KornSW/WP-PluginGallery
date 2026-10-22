<?php

declare(strict_types=1);

namespace KornSW\PluginGallery\Catalog;

use KornSW\PluginGallery\GitHub\GitHubClient;

if (!defined('ABSPATH')) {
  exit;
}

/**
 * Finds the plugins of one GitHub repository (all *.update.json files in /doc).
 *
 * Results are cached per repository together with the repository's "pushed_at" timestamp from the owner
 * listing. As long as nothing was pushed, the repository is not requested again (every release pushes a
 * new update.json, so the cache cannot hide a new version). Without a known timestamp the repository is
 * scanned and the cached result only serves as a fallback when GitHub is not reachable.
 */
final class RepositoryScanner {

  public const OPTION_CACHE = 'kornsw_pg_repository_cache';

  private GitHubClient $gitHubClient;
  private UpdateJsonReader $updateJsonReader;
  private ?array $cache = null;
  private bool $cacheChanged = false;

  public function __construct(GitHubClient $gitHubClient, UpdateJsonReader $updateJsonReader) {
    $this->gitHubClient = $gitHubClient;
    $this->updateJsonReader = $updateJsonReader;
  }

  /**
   * @return array{ok: bool, entries: array<int, array<string, mixed>>, errors: array<int, array<string, string>>}
   */
  public function scan(string $owner, string $repository, string $pushedAt = ''): array {
    $repositoryUrl = 'https://github.com/' . $owner . '/' . $repository;
    $cacheKey = strtolower($owner . '/' . $repository);
    $cache = $this->loadCache();
    $cachedEntry = null;
    if (isset($cache[$cacheKey]) && is_array($cache[$cacheKey])) {
      $cachedEntry = $cache[$cacheKey];
    }

    if ($cachedEntry !== null && $pushedAt !== '' && ($cachedEntry['pushed_at'] ?? '') === $pushedAt) {
      return ['ok' => true, 'entries' => $cachedEntry['entries'], 'errors' => []];
    }

    $listing = $this->gitHubClient->listUpdateJsonUrls($owner, $repository);
    if (!$listing['ok']) {
      if ($cachedEntry !== null && count($cachedEntry['entries']) > 0) {
        return ['ok' => true, 'entries' => $cachedEntry['entries'], 'errors' => []];
      }
      return [
        'ok' => false,
        'entries' => [],
        'errors' => [['source' => $repositoryUrl, 'label' => $owner . '/' . $repository, 'error' => $listing['error']]],
      ];
    }

    $entries = [];
    $errors = [];
    foreach ($listing['urls'] as $jsonUrl) {
      $loaded = $this->updateJsonReader->read($jsonUrl, $repositoryUrl);
      if ($loaded['ok']) {
        $entries[] = $loaded['entry'];
      } else {
        $errors[] = ['source' => $jsonUrl, 'label' => basename((string) wp_parse_url($jsonUrl, PHP_URL_PATH)), 'error' => $loaded['error']];
      }
    }

    if (count($errors) > 0 && $cachedEntry !== null && count($entries) === 0) {
      // update.json temporarily unreadable: keep showing the last known state.
      return ['ok' => true, 'entries' => $cachedEntry['entries'], 'errors' => []];
    }

    if (count($errors) === 0) {
      $this->storeCacheEntry($cacheKey, $pushedAt, $entries);
    }
    return ['ok' => true, 'entries' => $entries, 'errors' => $errors];
  }

  /**
   * Persists cache changes made by scan(); call once after a scan run.
   */
  public function flush(): void {
    if ($this->cacheChanged && $this->cache !== null) {
      update_option(self::OPTION_CACHE, $this->cache, false);
      $this->cacheChanged = false;
    }
  }

  /**
   * Wipes the cache (disposable data only).
   */
  public function clear(): void {
    delete_option(self::OPTION_CACHE);
    $this->cache = [];
    $this->cacheChanged = false;
  }

  private function loadCache(): array {
    if ($this->cache === null) {
      $stored = get_option(self::OPTION_CACHE, []);
      if (!is_array($stored)) {
        $stored = [];
      }
      $this->cache = $stored;
    }
    return $this->cache;
  }

  private function storeCacheEntry(string $cacheKey, string $pushedAt, array $entries): void {
    $this->loadCache();
    $this->cache[$cacheKey] = ['pushed_at' => $pushedAt, 'entries' => $entries, 'scanned_at' => time()];
    $this->cacheChanged = true;
  }
}

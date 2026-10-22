<?php

declare(strict_types=1);

namespace KornSW\PluginGallery\Catalog;

use KornSW\PluginGallery\GitHub\GitHubClient;
use KornSW\PluginGallery\Source;
use KornSW\PluginGallery\SourceConfiguration;

if (!defined('ABSPATH')) {
  exit;
}

/**
 * Builds the gallery catalog in three tiers:
 * - favorites: the entries of config.php (highlighted with a star);
 * - additional: sources stored by the administrator;
 * - related: further "WP-*" repositories of the favorites' GitHub owners, found automatically.
 * An entry appears only once; the higher tier wins.
 */
final class CatalogBuilder {

  public const TIER_FAVORITE = 'favorite';
  public const TIER_ADDITIONAL = 'additional';
  public const TIER_RELATED = 'related';

  private const TRANSIENT_CATALOG = 'kornsw_pg_catalog_v3';
  private const CATALOG_TTL = 3600;
  private const RELATED_REPOSITORY_PREFIX = 'wp-';

  private SourceConfiguration $configuration;
  private GitHubClient $gitHubClient;
  private RepositoryScanner $repositoryScanner;
  private UpdateJsonReader $updateJsonReader;
  private WordPressOrgDirectory $wordPressOrgDirectory;

  public function __construct(
    SourceConfiguration $configuration,
    GitHubClient $gitHubClient,
    RepositoryScanner $repositoryScanner,
    UpdateJsonReader $updateJsonReader,
    WordPressOrgDirectory $wordPressOrgDirectory
  ) {
    $this->configuration = $configuration;
    $this->gitHubClient = $gitHubClient;
    $this->repositoryScanner = $repositoryScanner;
    $this->updateJsonReader = $updateJsonReader;
    $this->wordPressOrgDirectory = $wordPressOrgDirectory;
  }

  /**
   * The cached catalog, built on demand.
   *
   * @return array<string, mixed>
   */
  public function getCatalog(bool $forceRebuild = false): array {
    if (!$forceRebuild) {
      $cached = get_transient(self::TRANSIENT_CATALOG);
      if (is_array($cached) && isset($cached['favorites'], $cached['additional'], $cached['related'])) {
        return $cached;
      }
    }
    $catalog = $this->build();
    set_transient(self::TRANSIENT_CATALOG, $catalog, self::CATALOG_TTL);
    return $catalog;
  }

  public function invalidate(): void {
    delete_transient(self::TRANSIENT_CATALOG);
  }

  /**
   * Plugins in non-"WP-*" repositories of the favorites' owners that are not part of the catalog yet
   * (the "WP-*" repositories are listed automatically anyway).
   *
   * @return array<int, array<string, mixed>>
   */
  public function discoverCandidates(): array {
    $catalog = $this->getCatalog();
    $knownKeys = [];
    foreach ([self::TIER_FAVORITE, self::TIER_ADDITIONAL, self::TIER_RELATED] as $tier) {
      foreach ($catalog[$this->tierListName($tier)] as $entry) {
        $knownKeys[(string) $entry['key']] = true;
      }
    }

    $candidates = [];
    foreach ($this->loadOwnerRepositories() as $repository) {
      if ($repository['archived'] || $this->isRelatedRepositoryName($repository['name'])) {
        continue;
      }
      $result = $this->repositoryScanner->scan($repository['owner'], $repository['name'], $repository['pushed_at']);
      foreach ($result['entries'] as $entry) {
        if (!isset($knownKeys[(string) $entry['key']])) {
          $candidates[(string) $entry['key']] = $entry;
        }
      }
    }
    $this->repositoryScanner->flush();

    $candidates = array_values($candidates);
    $this->sortByName($candidates);
    return $candidates;
  }

  /**
   * @return array<string, mixed>
   */
  private function build(): array {
    $ownerRepositories = $this->loadOwnerRepositories();
    $pushedAtByRepository = [];
    foreach ($ownerRepositories as $repository) {
      $pushedAtByRepository[strtolower($repository['owner'] . '/' . $repository['name'])] = $repository['pushed_at'];
    }

    $catalog = [
      'favorites' => [],
      'additional' => [],
      'related' => [],
      'errors' => [self::TIER_FAVORITE => [], self::TIER_ADDITIONAL => []],
      'owners' => $this->configuration->getFavoriteGitHubOwners(),
      'generated_at' => time(),
    ];
    $knownKeys = [];
    $explicitRepositories = [];

    $tiers = [
      self::TIER_FAVORITE => $this->configuration->getFavoriteSources(),
      self::TIER_ADDITIONAL => array_values($this->configuration->getExtraSources()),
    ];
    foreach ($tiers as $tier => $sources) {
      foreach ($sources as $source) {
        if ($source->getType() === Source::TYPE_GITHUB) {
          $explicitRepositories[strtolower($source->getOwner() . '/' . $source->getRepository())] = true;
        }
        $result = $this->resolveSource($source, $pushedAtByRepository);
        $this->addEntries($catalog, $knownKeys, $tier, $result['entries']);
        foreach ($result['errors'] as $error) {
          $catalog['errors'][$tier][] = $error;
        }
      }
    }

    foreach ($ownerRepositories as $repository) {
      $repositoryKey = strtolower($repository['owner'] . '/' . $repository['name']);
      if ($repository['archived'] || isset($explicitRepositories[$repositoryKey]) || !$this->isRelatedRepositoryName($repository['name'])) {
        continue;
      }
      // Errors of automatically found repositories are not shown: not every "WP-*" repository is a released plugin.
      $result = $this->repositoryScanner->scan($repository['owner'], $repository['name'], $repository['pushed_at']);
      $this->addEntries($catalog, $knownKeys, self::TIER_RELATED, $result['entries']);
    }

    $this->repositoryScanner->flush();
    $this->sortByName($catalog['favorites']);
    $this->sortByName($catalog['additional']);
    $this->sortByName($catalog['related']);
    return $catalog;
  }

  /**
   * @param array<string, string> $pushedAtByRepository
   * @return array{entries: array<int, array<string, mixed>>, errors: array<int, array<string, string>>}
   */
  private function resolveSource(Source $source, array $pushedAtByRepository): array {
    if ($source->getType() === Source::TYPE_GITHUB) {
      $pushedAt = '';
      $repositoryKey = strtolower($source->getOwner() . '/' . $source->getRepository());
      if (isset($pushedAtByRepository[$repositoryKey])) {
        $pushedAt = $pushedAtByRepository[$repositoryKey];
      }
      $result = $this->repositoryScanner->scan($source->getOwner(), $source->getRepository(), $pushedAt);
      $errors = $result['errors'];
      if ($result['ok'] && count($result['entries']) === 0 && count($errors) === 0) {
        $errors[] = ['source' => $source->getUrl(), 'label' => $source->getLabel(), 'error' => 'Keine *.update.json unter /doc gefunden.'];
      }
      return ['entries' => $result['entries'], 'errors' => $errors];
    }

    if ($source->getType() === Source::TYPE_WPORG) {
      $result = $this->wordPressOrgDirectory->read($source->getSlug());
    } else {
      $result = $this->updateJsonReader->read($source->getUrl(), $source->getUrl());
    }
    if ($result['ok']) {
      return ['entries' => [$result['entry']], 'errors' => []];
    }
    return ['entries' => [], 'errors' => [['source' => $source->getUrl(), 'label' => $source->getLabel(), 'error' => $result['error']]]];
  }

  /**
   * Repositories of all favorite owners (compact form); owners whose listing fails are skipped.
   *
   * @return array<int, array<string, mixed>>
   */
  private function loadOwnerRepositories(): array {
    $repositories = [];
    foreach ($this->configuration->getFavoriteGitHubOwners() as $owner) {
      $listing = $this->gitHubClient->listOwnerRepositories($owner);
      if (!$listing['ok']) {
        continue;
      }
      foreach ($listing['repositories'] as $repository) {
        if ($repository['owner'] === '') {
          $repository['owner'] = $owner;
        }
        $repositories[] = $repository;
      }
    }
    return $repositories;
  }

  private function isRelatedRepositoryName(string $repositoryName): bool {
    return strncasecmp($repositoryName, self::RELATED_REPOSITORY_PREFIX, strlen(self::RELATED_REPOSITORY_PREFIX)) === 0;
  }

  /**
   * @param array<string, mixed> $catalog
   * @param array<string, bool> $knownKeys
   * @param array<int, array<string, mixed>> $entries
   */
  private function addEntries(array &$catalog, array &$knownKeys, string $tier, array $entries): void {
    foreach ($entries as $entry) {
      $key = (string) $entry['key'];
      if (isset($knownKeys[$key])) {
        continue;
      }
      $knownKeys[$key] = true;
      $entry['tier'] = $tier;
      $catalog[$this->tierListName($tier)][] = $entry;
    }
  }

  private function tierListName(string $tier): string {
    if ($tier === self::TIER_FAVORITE) {
      return 'favorites';
    }
    if ($tier === self::TIER_ADDITIONAL) {
      return 'additional';
    }
    return 'related';
  }

  /**
   * @param array<int, array<string, mixed>> $entries
   */
  private function sortByName(array &$entries): void {
    usort($entries, static function (array $left, array $right): int {
      return strcasecmp((string) $left['name'], (string) $right['name']);
    });
  }
}

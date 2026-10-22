<?php

declare(strict_types=1);

/*
 * Standalone tests of the gallery domain logic. Run: php tests/run-tests.php
 */

require __DIR__ . '/bootstrap.php';

use KornSW\PluginGallery\Catalog\CatalogBuilder;
use KornSW\PluginGallery\Catalog\RepositoryScanner;
use KornSW\PluginGallery\Catalog\UpdateJsonReader;
use KornSW\PluginGallery\Catalog\WordPressOrgDirectory;
use KornSW\PluginGallery\GitHub\GitHubClient;
use KornSW\PluginGallery\GitHub\GitHubTokenStore;
use KornSW\PluginGallery\Security\SecretCipher;
use KornSW\PluginGallery\Source;
use KornSW\PluginGallery\SourceConfiguration;

const API = 'https://api.github.com';
const RAW = 'https://raw.githubusercontent.com/KornSW/';

function testSourceParsing(): void {
  $github = Source::parse(' https://github.com/KornSW/WP-Alpha.git ');
  Assert::same(Source::TYPE_GITHUB, $github->getType(), 'GitHub URL is a GitHub source');
  Assert::same('github:kornsw/wp-alpha', $github->getKey(), 'GitHub key is case-insensitive without .git');

  $wordPressOrgUrl = Source::parse('https://de.wordpress.org/plugins/Akismet/');
  Assert::same(Source::TYPE_WPORG, $wordPressOrgUrl->getType(), 'Localized WordPress.org URL is a wporg source');
  Assert::same('akismet', $wordPressOrgUrl->getSlug(), 'WordPress.org slug is lower case');

  $slug = Source::parse('wordfence');
  Assert::same('https://wordpress.org/plugins/wordfence/', $slug->getUrl(), 'Bare slug becomes a WordPress.org URL');

  $json = Source::parse('https://example.org/x.update.json');
  Assert::same(Source::TYPE_JSON, $json->getType(), 'Other URL is an update.json source');

  Assert::same(null, Source::parse('https://github.com/KornSW'), 'GitHub URL without repository is rejected');
  Assert::same(null, Source::parse('ftp://example.org/x.json'), 'Non-HTTP URL is rejected');
  Assert::same(null, Source::parse('Not a slug!'), 'Garbage is rejected');
  Assert::same(null, Source::parse('https://wordpress.org/news/'), 'WordPress.org URL without plugin is rejected');
}

function testSecretCipher(): void {
  $cipher = new SecretCipher();
  if (!$cipher->isAvailable()) {
    echo "SKIP SecretCipher (OpenSSL not available)\n";
    return;
  }
  $encrypted = $cipher->encrypt('github_pat_secret');
  Assert::true($encrypted !== null && strpos($encrypted, 'github_pat_secret') === false, 'Token is not stored in plain text');
  Assert::same('github_pat_secret', $cipher->decrypt((string) $encrypted), 'Token round-trips');
  $tampered = substr((string) $encrypted, 0, -2) . 'AA';
  Assert::same(null, $cipher->decrypt($tampered), 'Tampered value is rejected');
}

function updateJson(string $name, string $version, string $slug): array {
  return [
    'name' => $name,
    'version' => $version,
    'author' => 'KornSW',
    'homepage' => 'https://github.com/KornSW/' . $slug,
    'download_url' => 'https://github.com/KornSW/x/releases/download/v' . $version . '/' . $slug . '.zip',
    'sections' => ['description' => $name . ' description'],
  ];
}

function repository(string $name, string $pushedAt, bool $archived = false): array {
  return [
    'name' => $name,
    'owner' => ['login' => 'KornSW'],
    'html_url' => 'https://github.com/KornSW/' . $name,
    'pushed_at' => $pushedAt,
    'archived' => $archived,
    'fork' => false,
  ];
}

function docListing(string $repository, string $slug): array {
  return [
    ['type' => 'file', 'name' => 'changelog.md', 'download_url' => RAW . $repository . '/master/doc/changelog.md'],
    ['type' => 'file', 'name' => $slug . '.update.json', 'download_url' => RAW . $repository . '/master/doc/' . $slug . '.update.json'],
  ];
}

/**
 * Registers a fake GitHub landscape of the owner KornSW.
 *
 * @param array<string, string> $pushedAt
 */
function registerLandscape(array $pushedAt, int $rateRemaining = 4999): void {
  $rateHeaders = ['x-ratelimit-remaining' => (string) $rateRemaining, 'x-ratelimit-limit' => '5000', 'x-ratelimit-reset' => (string) (time() + 600)];
  $repositories = [
    repository('WP-Alpha', $pushedAt['WP-Alpha']),
    repository('WP-Beta', $pushedAt['WP-Beta']),
    repository('WP-Delta', $pushedAt['WP-Delta']),
    repository('WP-Gamma', $pushedAt['WP-Gamma']),
    repository('WP-Old', '2020-01-01T00:00:00Z', true),
    repository('Tools', $pushedAt['Tools']),
  ];
  $listingEtag = '"listing-' . md5(json_encode($repositories)) . '"';
  FakeHttp::on(API . '/users/KornSW/repos?type=owner&sort=full_name&per_page=100&page=1', function (array $headers) use ($repositories, $listingEtag, $rateHeaders): array {
    if (($headers['If-None-Match'] ?? '') === $listingEtag) {
      return ['status' => 304, 'body' => '', 'headers' => $rateHeaders];
    }
    return FakeHttp::json(200, $repositories, array_merge($rateHeaders, ['etag' => $listingEtag]));
  });

  $plugins = ['WP-Alpha' => 'kornsw-alpha', 'WP-Beta' => 'kornsw-beta', 'WP-Delta' => 'kornsw-delta', 'Tools' => 'kornsw-tools'];
  foreach ($plugins as $repositoryName => $slug) {
    FakeHttp::on(API . '/repos/KornSW/' . $repositoryName . '/contents/doc', function () use ($repositoryName, $slug, $rateHeaders): array {
      return FakeHttp::json(200, docListing($repositoryName, $slug), $rateHeaders);
    });
    FakeHttp::on(RAW . $repositoryName . '/master/doc/' . $slug . '.update.json', function () use ($repositoryName, $slug): array {
      return FakeHttp::json(200, updateJson(str_replace('WP-', 'KornSW ', $repositoryName), '1.0.0', $slug));
    });
  }
  // WP-Gamma has no doc folder at all (API and website answer 404 by default).

  FakeHttp::on('https://example.org/feeds/external.update.json', function (): array {
    return FakeHttp::json(200, updateJson('External Plugin', '2.0.0', 'external'));
  });
  $GLOBALS['kornsw_pg_test_plugins_api']['akismet'] = [
    'name' => 'Akismet Anti-spam: Spam Protection',
    'version' => '5.3',
    'author' => '<a href="https://automattic.com">Automattic</a>',
    'download_link' => 'https://downloads.wordpress.org/plugin/akismet.5.3.zip',
    'requires' => '5.8',
    'requires_php' => '5.6.20',
    'tested' => false,
    'short_description' => 'The best anti-spam protection.',
    'icons' => ['1x' => 'https://ps.w.org/akismet/assets/icon-128x128.png'],
    'active_installs' => 5000000,
  ];
}

function createCatalogBuilder(): CatalogBuilder {
  $tokenStore = new GitHubTokenStore(new SecretCipher());
  $gitHubClient = new GitHubClient($tokenStore);
  $reader = new UpdateJsonReader();
  return new CatalogBuilder(
    new SourceConfiguration(__DIR__ . '/fixtures/config.php'),
    $gitHubClient,
    new RepositoryScanner($gitHubClient, $reader),
    $reader,
    new WordPressOrgDirectory()
  );
}

/**
 * @param array<int, array<string, mixed>> $entries
 * @return string[]
 */
function names(array $entries): array {
  return array_map(function (array $entry): string {
    return (string) $entry['name'];
  }, $entries);
}

function testCatalogTiers(): void {
  TestState::reset();
  $tokenStore = new GitHubTokenStore(new SecretCipher());
  $tokenStore->save('github_pat_TEST');
  update_option(SourceConfiguration::OPTION_EXTRA_SOURCES, [
    ['type' => 'repository', 'url' => 'https://github.com/KornSW/WP-Beta'],
    ['type' => 'wporg', 'url' => 'https://wordpress.org/plugins/akismet/'],
  ]);
  $pushedAt = ['WP-Alpha' => 'a1', 'WP-Beta' => 'b1', 'WP-Delta' => 'd1', 'WP-Gamma' => 'g1', 'Tools' => 't1'];
  registerLandscape($pushedAt);

  $catalog = createCatalogBuilder()->getCatalog();

  Assert::same(['Akismet Anti-spam: Spam Protection', 'External Plugin', 'KornSW Alpha'], names($catalog['favorites']), 'Favorites: config entries, de-duplicated, sorted');
  Assert::same(['KornSW Beta'], names($catalog['additional']), 'Additional: stored sources, favorites not repeated');
  Assert::same(['KornSW Delta'], names($catalog['related']), 'Related: other WP-* repositories only (no archived, no Gamma without doc, no non-WP)');
  Assert::same([], $catalog['errors']['favorite'], 'No favorite errors');
  Assert::same(['KornSW'], $catalog['owners'], 'Distinct owners');
  Assert::same('Automattic', $catalog['favorites'][0]['author'], 'WordPress.org author is plain text');
  Assert::same('akismet', $catalog['favorites'][0]['slug'], 'WordPress.org slug kept');
  Assert::same('kornsw-alpha', $catalog['favorites'][2]['slug'], 'Slug derived from update.json file name');

  $apiRequestsWithoutToken = 0;
  $rawRequestsWithToken = 0;
  foreach (FakeHttp::$requests as $request) {
    $hasAuthorization = isset($request['headers']['Authorization']);
    if (strpos($request['url'], API) === 0 && ($request['headers']['Authorization'] ?? '') !== 'Bearer github_pat_TEST') {
      $apiRequestsWithoutToken++;
    }
    if (strpos($request['url'], API) !== 0 && $hasAuthorization) {
      $rawRequestsWithToken++;
    }
  }
  Assert::same(0, $apiRequestsWithoutToken, 'Every API request carries the token');
  Assert::same(0, $rawRequestsWithToken, 'The token is never sent outside api.github.com');
  Assert::same(0, FakeHttp::countRequests(API . '/repos/KornSW/WP-Old/'), 'Archived repositories are not scanned');
  Assert::same(0, FakeHttp::countRequests(API . '/repos/KornSW/Tools/'), 'Non-WP repositories are not scanned for the catalog');

  // Rebuild without changes: only the (conditional) owner listing goes to the API.
  FakeHttp::$requests = [];
  $catalog = createCatalogBuilder()->getCatalog(true);
  Assert::same(1, FakeHttp::countRequests(API), 'Unchanged landscape costs exactly one API request');
  Assert::same(0, FakeHttp::countRequests(RAW), 'Unchanged landscape loads no update.json');
  Assert::same(['KornSW Delta'], names($catalog['related']), 'Related entries served from the repository cache');

  // A push to WP-Delta invalidates only that repository.
  TestState::$transients = array_filter(TestState::$transients, function (string $key): bool {
    return strpos($key, 'kornsw_pg_catalog') !== 0;
  }, ARRAY_FILTER_USE_KEY);
  $pushedAt['WP-Delta'] = 'd2';
  FakeHttp::$routes = [];
  FakeHttp::$requests = [];
  registerLandscape($pushedAt);
  createCatalogBuilder()->getCatalog(true);
  Assert::same(1, FakeHttp::countRequests(API . '/repos/KornSW/WP-Delta/contents/doc'), 'Pushed repository is scanned again');
  Assert::same(0, FakeHttp::countRequests(API . '/repos/KornSW/WP-Alpha/'), 'Unchanged repository is not scanned again');
}

function testRateLimitExhausted(): void {
  TestState::reset();
  $pushedAt = ['WP-Alpha' => 'a1', 'WP-Beta' => 'b1', 'WP-Delta' => 'd1', 'WP-Gamma' => 'g1', 'Tools' => 't1'];
  registerLandscape($pushedAt);
  $first = createCatalogBuilder()->getCatalog();
  Assert::same(['KornSW Beta', 'KornSW Delta'], names($first['related']), 'Anonymous build works and finds WP-Beta as related');

  // GitHub reported remaining = 0: no further API calls until the reset, cached data is used.
  set_transient('kornsw_pg_github_rate_limit', ['remaining' => 0, 'limit' => 60, 'reset' => time() + 600, 'authenticated' => false, 'recorded_at' => time()]);
  FakeHttp::$requests = [];
  $second = createCatalogBuilder()->getCatalog(true);
  Assert::same(0, FakeHttp::countRequests(API), 'No API requests while the rate limit is exhausted');
  Assert::same(names($first['favorites']), names($second['favorites']), 'Favorites still available from cache');
  Assert::same(names($first['related']), names($second['related']), 'Related still available from cache');
}

function testDiscovery(): void {
  TestState::reset();
  $pushedAt = ['WP-Alpha' => 'a1', 'WP-Beta' => 'b1', 'WP-Delta' => 'd1', 'WP-Gamma' => 'g1', 'Tools' => 't1'];
  registerLandscape($pushedAt);
  $candidates = createCatalogBuilder()->discoverCandidates();
  Assert::same(['Tools'], names($candidates), 'Discovery offers plugins of non-WP repositories only');
}

testSourceParsing();
testSecretCipher();
testCatalogTiers();
testRateLimitExhausted();
testDiscovery();

foreach (Assert::$failures as $failure) {
  echo 'FAIL ' . $failure . "\n";
}
echo Assert::$passed . ' passed, ' . count(Assert::$failures) . " failed\n";
exit(count(Assert::$failures) === 0 ? 0 : 1);

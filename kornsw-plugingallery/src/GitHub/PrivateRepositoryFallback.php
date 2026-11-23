<?php

declare(strict_types=1);

namespace KornSW\PluginGallery\GitHub;

use KornSW\PluginGallery\SourceConfiguration;

if (!defined('ABSPATH')) {
  exit;
}

/**
 * Makes private GitHub repositories of the well-known authors (the GitHub owners of the favorites in
 * config.php) usable when a personal access token is configured.
 *
 * Purely additive: it only reacts to an HTTP response that already failed with 404 for
 *   - https://raw.githubusercontent.com/<owner>/<repo>/<ref>/<path>   (update.json, read by the gallery and by
 *     the self-update mechanism of every installed plugin) and
 *   - https://github.com/<owner>/<repo>/releases/download/<tag>/<asset> (release ZIP, downloaded by the core
 *     upgrader for installs and updates),
 * and replaces that response with the same content fetched via the REST API using the token. Public
 * repositories answer 200 in the first place and are therefore never touched. The token is sent only to
 * api.github.com; the signed asset download URL GitHub redirects to is requested without it.
 *
 * Hooked into "http_response" on every request (not only in the admin area), because WordPress runs
 * automatic updates from WP-Cron.
 */
final class PrivateRepositoryFallback {

  private const API_BASE = 'https://api.github.com';
  private const DEFAULT_TIMEOUT = 15;

  private GitHubTokenStore $tokenStore;
  private SourceConfiguration $configuration;

  /** @var array<string, bool>|null */
  private ?array $knownOwners = null;
  private bool $isResolving = false;

  /** @var array<string, array<string, mixed>> Successfully resolved raw files of this request. */
  private array $rawFileCache = [];

  public function __construct(GitHubTokenStore $tokenStore, SourceConfiguration $configuration) {
    $this->tokenStore = $tokenStore;
    $this->configuration = $configuration;
  }

  public function register(): void {
    add_filter('http_response', [$this, 'filterHttpResponse'], 10, 3);
  }

  /**
   * Filter "http_response": replaces a 404 of a private repository of a well-known author.
   *
   * @param array|\WP_Error $response
   * @param mixed $arguments
   * @param mixed $url
   * @return array|\WP_Error
   */
  public function filterHttpResponse($response, $arguments, $url) {
    if ($this->isResolving || !is_array($response) || !is_string($url)) {
      return $response;
    }
    if ((int) wp_remote_retrieve_response_code($response) !== 404) {
      return $response;
    }
    if (!is_array($arguments)) {
      $arguments = [];
    }
    if (strtoupper((string) ($arguments['method'] ?? 'GET')) !== 'GET') {
      return $response;
    }
    $target = self::parseGitHubUrl($url);
    if ($target === null || !$this->isKnownOwner($target['owner'])) {
      return $response;
    }
    $token = $this->tokenStore->getToken();
    if ($token === '') {
      return $response;
    }

    $this->isResolving = true;
    try {
      if ($target['kind'] === 'raw') {
        $replacement = $this->fetchRawFile($target, $arguments, $token, $url);
      } else {
        $replacement = $this->downloadReleaseAsset($target, $arguments, $token);
      }
    } finally {
      $this->isResolving = false;
    }

    if ($replacement === null) {
      return $response;
    }
    return $replacement;
  }

  /**
   * Recognizes raw file URLs and release asset URLs of GitHub.
   *
   * @return array{kind: string, owner: string, repository: string, ref: string, path: string, tag: string, asset: string}|null
   */
  public static function parseGitHubUrl(string $url): ?array {
    $parts = wp_parse_url($url);
    if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || empty($parts['path'])) {
      return null;
    }
    $host = strtolower((string) ($parts['host'] ?? ''));
    $segments = array_values(array_filter(explode('/', (string) $parts['path']), static function (string $segment): bool {
      return $segment !== '';
    }));
    $segments = array_map('rawurldecode', $segments);

    if ($host === 'raw.githubusercontent.com' && count($segments) >= 4) {
      $refIndex = 2;
      if ($segments[2] === 'refs' && count($segments) >= 6 && ($segments[3] === 'heads' || $segments[3] === 'tags')) {
        $refIndex = 4;
      }
      $path = implode('/', array_slice($segments, $refIndex + 1));
      if ($path === '') {
        return null;
      }
      return [
        'kind' => 'raw',
        'owner' => $segments[0],
        'repository' => $segments[1],
        'ref' => $segments[$refIndex],
        'path' => $path,
        'tag' => '',
        'asset' => '',
      ];
    }

    if ($host === 'github.com' && count($segments) >= 5 && $segments[2] === 'releases') {
      $tag = '';
      $asset = '';
      if (count($segments) === 6 && $segments[3] === 'download') {
        $tag = $segments[4];
        $asset = $segments[5];
      } elseif (count($segments) === 6 && $segments[3] === 'latest' && $segments[4] === 'download') {
        $asset = $segments[5];
      } else {
        return null;
      }
      return [
        'kind' => 'asset',
        'owner' => $segments[0],
        'repository' => $segments[1],
        'ref' => '',
        'path' => '',
        'tag' => $tag,
        'asset' => $asset,
      ];
    }
    return null;
  }

  private function isKnownOwner(string $owner): bool {
    if ($this->knownOwners === null) {
      $this->knownOwners = [];
      foreach ($this->configuration->getFavoriteGitHubOwners() as $knownOwner) {
        $this->knownOwners[strtolower($knownOwner)] = true;
      }
    }
    return isset($this->knownOwners[strtolower($owner)]);
  }

  /**
   * Same file via the contents API (raw media type).
   *
   * @param array<string, string> $target
   * @param array<string, mixed> $arguments
   * @return array<string, mixed>|null
   */
  private function fetchRawFile(array $target, array $arguments, string $token, string $originalUrl): ?array {
    $isStream = !empty($arguments['stream']);
    if (!$isStream && isset($this->rawFileCache[$originalUrl])) {
      return $this->rawFileCache[$originalUrl];
    }
    $encodedPath = implode('/', array_map('rawurlencode', explode('/', $target['path'])));
    $apiUrl = sprintf(
      '%s/repos/%s/%s/contents/%s?ref=%s',
      self::API_BASE,
      rawurlencode($target['owner']),
      rawurlencode($target['repository']),
      $encodedPath,
      rawurlencode($target['ref'])
    );
    $requestArguments = $this->baseArguments($arguments);
    $requestArguments['headers'] = $this->apiHeaders($token, 'application/vnd.github.raw');
    $response = wp_safe_remote_get($apiUrl, $requestArguments);
    if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
      return null;
    }
    if (!$isStream) {
      $this->rawFileCache[$originalUrl] = $response;
    }
    return $response;
  }

  /**
   * Release asset via the releases API: look up the asset, request it with the token without following the
   * redirect, then download the signed URL without the token.
   *
   * @param array<string, string> $target
   * @param array<string, mixed> $arguments
   * @return array<string, mixed>|null
   */
  private function downloadReleaseAsset(array $target, array $arguments, string $token): ?array {
    $releasePath = '/releases/latest';
    if ($target['tag'] !== '') {
      $releasePath = '/releases/tags/' . rawurlencode($target['tag']);
    }
    $releaseUrl = sprintf('%s/repos/%s/%s%s', self::API_BASE, rawurlencode($target['owner']), rawurlencode($target['repository']), $releasePath);
    $releaseResponse = wp_safe_remote_get($releaseUrl, [
      'timeout' => self::DEFAULT_TIMEOUT,
      'headers' => $this->apiHeaders($token, 'application/vnd.github+json'),
    ]);
    if (is_wp_error($releaseResponse) || (int) wp_remote_retrieve_response_code($releaseResponse) !== 200) {
      return null;
    }
    $release = json_decode(wp_remote_retrieve_body($releaseResponse), true);
    if (!is_array($release) || !isset($release['assets']) || !is_array($release['assets'])) {
      return null;
    }
    $assetApiUrl = '';
    foreach ($release['assets'] as $asset) {
      if (is_array($asset) && (string) ($asset['name'] ?? '') === $target['asset']) {
        $assetApiUrl = (string) ($asset['url'] ?? '');
        break;
      }
    }
    if (strpos($assetApiUrl, self::API_BASE . '/') !== 0) {
      return null;
    }

    $assetArguments = $this->baseArguments($arguments);
    $assetArguments['redirection'] = 0;
    $assetArguments['headers'] = $this->apiHeaders($token, 'application/octet-stream');
    $assetResponse = wp_safe_remote_get($assetApiUrl, $assetArguments);
    if (is_wp_error($assetResponse)) {
      return null;
    }
    $status = (int) wp_remote_retrieve_response_code($assetResponse);
    if ($status === 200) {
      return $assetResponse;
    }
    if ($status < 300 || $status >= 400) {
      return null;
    }
    $location = (string) wp_remote_retrieve_header($assetResponse, 'location');
    if ($location === '' || strtolower((string) wp_parse_url($location, PHP_URL_SCHEME)) !== 'https') {
      return null;
    }

    // Signed, short-lived URL: never send the token there.
    $downloadArguments = $this->baseArguments($arguments);
    $downloadArguments['redirection'] = 5;
    $downloadResponse = wp_safe_remote_get($location, $downloadArguments);
    if (is_wp_error($downloadResponse) || (int) wp_remote_retrieve_response_code($downloadResponse) !== 200) {
      return null;
    }
    return $downloadResponse;
  }

  /**
   * Timeout and streaming target of the original request (download_url() streams into a temp file).
   *
   * @param array<string, mixed> $arguments
   * @return array<string, mixed>
   */
  private function baseArguments(array $arguments): array {
    $timeout = self::DEFAULT_TIMEOUT;
    if (isset($arguments['timeout']) && (float) $arguments['timeout'] > $timeout) {
      $timeout = (float) $arguments['timeout'];
    }
    $result = ['timeout' => $timeout];
    if (!empty($arguments['stream']) && !empty($arguments['filename'])) {
      $result['stream'] = true;
      $result['filename'] = (string) $arguments['filename'];
    }
    return $result;
  }

  /**
   * @return array<string, string>
   */
  private function apiHeaders(string $token, string $accept): array {
    return [
      'Accept' => $accept,
      'Authorization' => 'Bearer ' . $token,
      'User-Agent' => 'KornSW-Plugin-Gallery/' . KORNSW_PG_VERSION,
      'X-GitHub-Api-Version' => '2022-11-28',
    ];
  }
}

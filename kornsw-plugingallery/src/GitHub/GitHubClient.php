<?php

declare(strict_types=1);

namespace KornSW\PluginGallery\GitHub;

if (!defined('ABSPATH')) {
  exit;
}

/**
 * Read-only access to public GitHub repositories.
 *
 * Rate-limit friendly by design:
 * - an optional personal access token raises the API limit from 60 to 5,000 requests per hour;
 * - API responses are cached in a compact form together with their ETag; conditional requests
 *   answered with "304 Not Modified" do not count against the rate limit;
 * - when the limit is exhausted, cached data is used and further API calls are skipped until the reset;
 * - the /doc folder of a repository can still be read via the GitHub website as a last resort.
 */
final class GitHubClient {

  private const API_BASE = 'https://api.github.com';
  private const TRANSIENT_RATE_LIMIT = 'kornsw_pg_github_rate_limit';
  private const CACHE_PREFIX = 'kornsw_pg_gh_';
  private const CACHE_TTL = 7 * 86400;
  private const MAX_REPOSITORY_PAGES = 10;

  private GitHubTokenStore $tokenStore;

  public function __construct(GitHubTokenStore $tokenStore) {
    $this->tokenStore = $tokenStore;
  }

  public function hasToken(): bool {
    return $this->tokenStore->getToken() !== '';
  }

  /**
   * Public repositories of a user or organization in a compact form.
   *
   * @return array{ok: bool, repositories: array<int, array<string, mixed>>, error: string}
   */
  public function listOwnerRepositories(string $owner): array {
    $repositories = [];
    for ($page = 1; $page <= self::MAX_REPOSITORY_PAGES; $page++) {
      $path = sprintf('/users/%s/repos?type=owner&sort=full_name&per_page=100&page=%d', rawurlencode($owner), $page);
      $response = $this->getApiJson($path, function ($data): array {
        $compact = [];
        if (!is_array($data)) {
          return $compact;
        }
        foreach ($data as $repository) {
          if (!is_array($repository) || empty($repository['name']) || empty($repository['html_url'])) {
            continue;
          }
          $compact[] = [
            'name' => (string) $repository['name'],
            'owner' => (string) ($repository['owner']['login'] ?? ''),
            'html_url' => (string) $repository['html_url'],
            'pushed_at' => (string) ($repository['pushed_at'] ?? ''),
            'archived' => !empty($repository['archived']),
            'fork' => !empty($repository['fork']),
          ];
        }
        return $compact;
      });

      if (!$response['ok']) {
        if ($page === 1) {
          return ['ok' => false, 'repositories' => [], 'error' => $response['error']];
        }
        break;
      }
      $repositories = array_merge($repositories, $response['data']);
      // Compact pages contain at most 100 entries; a shorter page is the last one.
      if (count($response['data']) < 100) {
        break;
      }
    }
    return ['ok' => true, 'repositories' => $repositories, 'error' => ''];
  }

  /**
   * Raw URLs of all *.update.json files in the /doc folder of a repository.
   * "ok" with an empty list means the repository definitely contains no such file.
   *
   * @return array{ok: bool, urls: string[], error: string}
   */
  public function listUpdateJsonUrls(string $owner, string $repository): array {
    $path = sprintf('/repos/%s/%s/contents/doc', rawurlencode($owner), rawurlencode($repository));
    $response = $this->getApiJson($path, function ($data): array {
      $urls = [];
      if (!is_array($data)) {
        return $urls;
      }
      foreach ($data as $entry) {
        if (!is_array($entry) || ($entry['type'] ?? '') !== 'file') {
          continue;
        }
        $name = (string) ($entry['name'] ?? '');
        if (preg_match('/\.update\.json$/i', $name) === 1 && !empty($entry['download_url'])) {
          $urls[] = (string) $entry['download_url'];
        }
      }
      return $urls;
    });

    if ($response['ok']) {
      return ['ok' => true, 'urls' => array_values(array_unique($response['data'])), 'error' => ''];
    }
    if ($response['status'] === 404) {
      return ['ok' => true, 'urls' => [], 'error' => ''];
    }

    // Fallback without the REST API, important on shared hosting where the anonymous limit is shared.
    $web = $this->listUpdateJsonUrlsFromWebsite($owner, $repository);
    if ($web['ok']) {
      return $web;
    }
    return ['ok' => false, 'urls' => [], 'error' => $response['error'] . ' · Web-Fallback: ' . $web['error']];
  }

  /**
   * Checks a token against the API without storing it.
   *
   * @return array{ok: bool, message: string}
   */
  public function validateToken(string $token): array {
    $response = wp_safe_remote_get(self::API_BASE . '/rate_limit', [
      'timeout' => 12,
      'headers' => $this->apiHeaders($token),
    ]);
    if (is_wp_error($response)) {
      return ['ok' => false, 'message' => 'GitHub nicht erreichbar: ' . $response->get_error_message()];
    }
    $status = (int) wp_remote_retrieve_response_code($response);
    if ($status === 401) {
      return ['ok' => false, 'message' => 'GitHub hat den Token abgelehnt (HTTP 401).'];
    }
    if ($status !== 200) {
      return ['ok' => false, 'message' => $this->errorDetails($response, $status)];
    }
    $this->recordRateLimit($response, true);
    $limit = (string) wp_remote_retrieve_header($response, 'x-ratelimit-limit');
    return ['ok' => true, 'message' => 'Token gültig, API-Kontingent ' . $limit . ' Anfragen pro Stunde.'];
  }

  /**
   * Last observed rate-limit state, or null if unknown.
   *
   * @return array{remaining: int, limit: int, reset: int, authenticated: bool, recorded_at: int}|null
   */
  public function getRateLimitStatus(): ?array {
    $status = get_transient(self::TRANSIENT_RATE_LIMIT);
    if (!is_array($status) || !isset($status['remaining'], $status['limit'])) {
      return null;
    }
    return $status;
  }

  /**
   * Removes the last observed rate-limit state (e.g. after the token changed).
   */
  public function forgetRateLimitStatus(): void {
    delete_transient(self::TRANSIENT_RATE_LIMIT);
  }

  /**
   * GET request against the REST API with ETag caching. The reducer turns the decoded response into the
   * compact form that is returned and cached.
   *
   * @return array{ok: bool, status: int, data: mixed, error: string}
   */
  private function getApiJson(string $path, callable $reducer): array {
    $url = self::API_BASE . $path;
    $cacheKey = self::CACHE_PREFIX . md5($url);
    $cached = get_transient($cacheKey);
    if (!is_array($cached) || !array_key_exists('data', $cached)) {
      $cached = null;
    }

    if ($this->isRateLimitExhausted()) {
      if ($cached !== null) {
        return ['ok' => true, 'status' => 200, 'data' => $cached['data'], 'error' => ''];
      }
      return ['ok' => false, 'status' => 429, 'data' => null, 'error' => $this->exhaustedMessage()];
    }

    $headers = $this->apiHeaders($this->tokenStore->getToken());
    if ($cached !== null && !empty($cached['etag'])) {
      $headers['If-None-Match'] = (string) $cached['etag'];
    }

    $response = wp_safe_remote_get($url, ['timeout' => 15, 'headers' => $headers]);
    if (is_wp_error($response)) {
      if ($cached !== null) {
        return ['ok' => true, 'status' => 200, 'data' => $cached['data'], 'error' => ''];
      }
      return ['ok' => false, 'status' => 0, 'data' => null, 'error' => 'GitHub API: ' . $response->get_error_message()];
    }

    $status = (int) wp_remote_retrieve_response_code($response);
    $this->recordRateLimit($response, $this->hasToken());

    if ($status === 304 && $cached !== null) {
      return ['ok' => true, 'status' => 200, 'data' => $cached['data'], 'error' => ''];
    }

    if ($status === 200) {
      $decoded = json_decode(wp_remote_retrieve_body($response), true);
      if (!is_array($decoded)) {
        return ['ok' => false, 'status' => $status, 'data' => null, 'error' => 'GitHub API lieferte ungültiges JSON.'];
      }
      $data = $reducer($decoded);
      $etag = (string) wp_remote_retrieve_header($response, 'etag');
      set_transient($cacheKey, ['etag' => $etag, 'data' => $data], self::CACHE_TTL);
      return ['ok' => true, 'status' => $status, 'data' => $data, 'error' => ''];
    }

    if ($status === 404) {
      delete_transient($cacheKey);
      return ['ok' => false, 'status' => $status, 'data' => null, 'error' => 'GitHub API HTTP 404'];
    }

    // Rate limit or temporary failure: stale data is better than no data.
    if ($cached !== null && ($status === 403 || $status === 429 || $status >= 500)) {
      return ['ok' => true, 'status' => 200, 'data' => $cached['data'], 'error' => ''];
    }
    return ['ok' => false, 'status' => $status, 'data' => null, 'error' => $this->errorDetails($response, $status)];
  }

  /**
   * Reads the /doc folder listing from the GitHub website (no API, no token).
   *
   * @return array{ok: bool, urls: string[], error: string}
   */
  private function listUpdateJsonUrlsFromWebsite(string $owner, string $repository): array {
    $lastError = '';
    $notFoundCount = 0;
    $branches = ['master', 'main'];

    foreach ($branches as $branch) {
      $url = sprintf('https://github.com/%s/%s/tree/%s/doc', rawurlencode($owner), rawurlencode($repository), rawurlencode($branch));
      $response = wp_safe_remote_get($url, [
        'timeout' => 15,
        'redirection' => 5,
        'headers' => $this->browserHeaders(),
      ]);

      if (is_wp_error($response)) {
        $lastError = $response->get_error_message();
        continue;
      }
      $status = (int) wp_remote_retrieve_response_code($response);
      if ($status === 404) {
        $notFoundCount++;
        continue;
      }
      if ($status !== 200) {
        $lastError = 'GitHub-Webseite HTTP ' . $status;
        continue;
      }
      $html = wp_remote_retrieve_body($response);
      if (!is_string($html) || $html === '') {
        $lastError = 'Leere GitHub-Webseite';
        continue;
      }

      $found = [];
      $hrefPattern = '~href=["\'](/' . preg_quote($owner, '~') . '/' . preg_quote($repository, '~') . '/blob/'
        . preg_quote($branch, '~') . '/doc/[^"\']+\.update\.json)["\']~i';
      if (preg_match_all($hrefPattern, $html, $matches) > 0) {
        foreach ($matches[1] as $path) {
          $rawPath = preg_replace('~/blob/' . preg_quote($branch, '~') . '/~', '/refs/heads/' . $branch . '/', $path, 1);
          $found[] = 'https://raw.githubusercontent.com' . $rawPath;
        }
      }
      $embeddedPatterns = [
        '~\"path\":\"doc/([^\"]+\.update\.json)\"~i',
        '~&quot;path&quot;:&quot;doc/([^&]+\.update\.json)&quot;~i',
      ];
      foreach ($embeddedPatterns as $pattern) {
        if (preg_match_all($pattern, $html, $matches) > 0) {
          foreach ($matches[1] as $fileName) {
            $fileName = rawurldecode(html_entity_decode((string) $fileName, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $found[] = sprintf(
              'https://raw.githubusercontent.com/%s/%s/refs/heads/%s/doc/%s',
              rawurlencode($owner),
              rawurlencode($repository),
              rawurlencode($branch),
              rawurlencode(basename($fileName))
            );
          }
        }
      }

      $found = array_values(array_unique(array_filter($found)));
      if (count($found) > 0) {
        return ['ok' => true, 'urls' => $found, 'error' => ''];
      }
      $lastError = 'Keine *.update.json über GitHub-Webseite gefunden.';
    }

    if ($notFoundCount === count($branches)) {
      return ['ok' => true, 'urls' => [], 'error' => ''];
    }
    return ['ok' => false, 'urls' => [], 'error' => $lastError];
  }

  /**
   * @return array<string, string>
   */
  private function apiHeaders(string $token): array {
    $headers = [
      'Accept' => 'application/vnd.github+json',
      'User-Agent' => 'KornSW-Plugin-Gallery/' . KORNSW_PG_VERSION,
      'X-GitHub-Api-Version' => '2022-11-28',
    ];
    if ($token !== '') {
      $headers['Authorization'] = 'Bearer ' . $token;
    }
    return $headers;
  }

  /**
   * @return array<string, string>
   */
  private function browserHeaders(): array {
    return [
      'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
      'Accept-Language' => 'en-US,en;q=0.9',
      'Cache-Control' => 'no-cache',
      'Pragma' => 'no-cache',
      'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36 KornSW-Plugin-Gallery/' . KORNSW_PG_VERSION,
    ];
  }

  /**
   * @param array|\WP_Error $response
   */
  private function recordRateLimit($response, bool $authenticated): void {
    $remaining = (string) wp_remote_retrieve_header($response, 'x-ratelimit-remaining');
    $limit = (string) wp_remote_retrieve_header($response, 'x-ratelimit-limit');
    $reset = (string) wp_remote_retrieve_header($response, 'x-ratelimit-reset');
    if (!ctype_digit($remaining) || !ctype_digit($limit)) {
      return;
    }
    $resetTimestamp = 0;
    if (ctype_digit($reset)) {
      $resetTimestamp = (int) $reset;
    }
    $status = [
      'remaining' => (int) $remaining,
      'limit' => (int) $limit,
      'reset' => $resetTimestamp,
      'authenticated' => $authenticated,
      'recorded_at' => time(),
    ];
    set_transient(self::TRANSIENT_RATE_LIMIT, $status, DAY_IN_SECONDS);
  }

  private function isRateLimitExhausted(): bool {
    $status = $this->getRateLimitStatus();
    if ($status === null) {
      return false;
    }
    if ((bool) $status['authenticated'] !== $this->hasToken()) {
      return false;
    }
    return (int) $status['remaining'] <= 0 && (int) $status['reset'] > time();
  }

  private function exhaustedMessage(): string {
    $status = $this->getRateLimitStatus();
    $message = 'GitHub-API-Limit erschöpft';
    if ($status !== null && (int) $status['reset'] > 0) {
      $message .= ' bis ' . wp_date('H:i', (int) $status['reset']);
    }
    if (!$this->hasToken()) {
      $message .= ' (ohne Token nur 60 Anfragen pro Stunde)';
    }
    return $message;
  }

  /**
   * @param array|\WP_Error $response
   */
  private function errorDetails($response, int $status): string {
    $message = 'GitHub API HTTP ' . $status;
    $remaining = (string) wp_remote_retrieve_header($response, 'x-ratelimit-remaining');
    $limit = (string) wp_remote_retrieve_header($response, 'x-ratelimit-limit');
    $reset = (string) wp_remote_retrieve_header($response, 'x-ratelimit-reset');
    if ($remaining !== '' || $limit !== '') {
      if ($remaining === '') {
        $remaining = '?';
      }
      if ($limit === '') {
        $limit = '?';
      }
      $message .= ' · Rate-Limit ' . $remaining . '/' . $limit;
    }
    if ($reset !== '' && ctype_digit($reset)) {
      $message .= ' · Reset ' . wp_date('H:i:s', (int) $reset);
    }
    $body = json_decode(wp_remote_retrieve_body($response), true);
    if (is_array($body) && !empty($body['message'])) {
      $message .= ' · ' . sanitize_text_field((string) $body['message']);
    }
    return $message;
  }
}

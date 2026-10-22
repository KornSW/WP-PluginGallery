<?php

declare(strict_types=1);

namespace KornSW\PluginGallery;

if (!defined('ABSPATH')) {
  exit;
}

/**
 * A gallery source: a GitHub repository, a WordPress.org plugin or a direct *.update.json URL.
 */
final class Source {

  public const TYPE_GITHUB = 'github';
  public const TYPE_WPORG = 'wporg';
  public const TYPE_JSON = 'json';

  private string $type;
  private string $url;
  private string $owner;
  private string $repository;
  private string $slug;

  private function __construct(string $type, string $url, string $owner, string $repository, string $slug) {
    $this->type = $type;
    $this->url = $url;
    $this->owner = $owner;
    $this->repository = $repository;
    $this->slug = $slug;
  }

  /**
   * Parses user or configuration input. Accepted are GitHub repository URLs, WordPress.org plugin URLs,
   * bare WordPress.org plugin slugs and any other http(s) URL (treated as *.update.json).
   */
  public static function parse(string $input): ?Source {
    $input = trim($input);
    if ($input === '') {
      return null;
    }

    if (preg_match('/^[a-z0-9][a-z0-9\-]*$/', $input) === 1) {
      return self::forWordPressOrg($input);
    }

    $parts = parse_url($input);
    if (!is_array($parts) || empty($parts['host']) || empty($parts['scheme'])) {
      return null;
    }
    $scheme = strtolower((string) $parts['scheme']);
    if ($scheme !== 'https' && $scheme !== 'http') {
      return null;
    }
    $host = strtolower((string) $parts['host']);
    $segments = [];
    if (!empty($parts['path'])) {
      $segments = array_values(array_filter(explode('/', trim((string) $parts['path'], '/')), 'strlen'));
    }

    if ($host === 'github.com' || $host === 'www.github.com') {
      if (count($segments) < 2) {
        return null;
      }
      $repository = (string) preg_replace('/\.git$/i', '', $segments[1]);
      if ($repository === '') {
        return null;
      }
      return self::forGitHub($segments[0], $repository);
    }

    if ($host === 'wordpress.org' || substr($host, -14) === '.wordpress.org') {
      if (count($segments) >= 2 && strtolower($segments[0]) === 'plugins') {
        $slug = strtolower($segments[1]);
        if (preg_match('/^[a-z0-9][a-z0-9\-]*$/', $slug) === 1) {
          return self::forWordPressOrg($slug);
        }
      }
      return null;
    }

    return new self(self::TYPE_JSON, $input, '', '', '');
  }

  /**
   * Creates a GitHub repository source.
   */
  public static function forGitHub(string $owner, string $repository): Source {
    $url = 'https://github.com/' . $owner . '/' . $repository;
    return new self(self::TYPE_GITHUB, $url, $owner, $repository, '');
  }

  /**
   * Creates a WordPress.org plugin directory source.
   */
  public static function forWordPressOrg(string $slug): Source {
    $slug = strtolower($slug);
    return new self(self::TYPE_WPORG, 'https://wordpress.org/plugins/' . $slug . '/', '', '', $slug);
  }

  public function getType(): string {
    return $this->type;
  }

  public function getUrl(): string {
    return $this->url;
  }

  public function getOwner(): string {
    return $this->owner;
  }

  public function getRepository(): string {
    return $this->repository;
  }

  public function getSlug(): string {
    return $this->slug;
  }

  /**
   * Case-insensitive identity used for de-duplication.
   */
  public function getKey(): string {
    if ($this->type === self::TYPE_GITHUB) {
      return 'github:' . strtolower($this->owner . '/' . $this->repository);
    }
    if ($this->type === self::TYPE_WPORG) {
      return 'wporg:' . $this->slug;
    }
    return 'json:' . strtolower(rtrim($this->url, '/'));
  }

  /**
   * Human-readable type label (German UI).
   */
  public function getTypeLabel(): string {
    if ($this->type === self::TYPE_GITHUB) {
      return 'GitHub-Repo';
    }
    if ($this->type === self::TYPE_WPORG) {
      return 'WordPress.org';
    }
    return 'Update-JSON';
  }

  /**
   * Short display label.
   */
  public function getLabel(): string {
    if ($this->type === self::TYPE_GITHUB) {
      return $this->owner . '/' . $this->repository;
    }
    if ($this->type === self::TYPE_WPORG) {
      return $this->slug;
    }
    return $this->url;
  }
}

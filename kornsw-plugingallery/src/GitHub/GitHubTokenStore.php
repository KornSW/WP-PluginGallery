<?php

declare(strict_types=1);

namespace KornSW\PluginGallery\GitHub;

use KornSW\PluginGallery\Security\SecretCipher;

if (!defined('ABSPATH')) {
  exit;
}

/**
 * Storage of the GitHub personal access token.
 * A constant KORNSW_PG_GITHUB_TOKEN in wp-config.php takes precedence over the encrypted option.
 */
final class GitHubTokenStore {

  public const OPTION_TOKEN = 'kornsw_pg_github_token';
  public const CONSTANT_NAME = 'KORNSW_PG_GITHUB_TOKEN';

  private SecretCipher $cipher;
  private ?string $cachedToken = null;

  public function __construct(SecretCipher $cipher) {
    $this->cipher = $cipher;
  }

  /**
   * The effective token, or an empty string if none is configured or readable.
   */
  public function getToken(): string {
    if ($this->cachedToken !== null) {
      return $this->cachedToken;
    }
    $token = '';
    if ($this->isDefinedByConstant()) {
      $token = trim((string) constant(self::CONSTANT_NAME));
    } else {
      $stored = get_option(self::OPTION_TOKEN, '');
      if (is_string($stored) && $stored !== '') {
        $decrypted = $this->cipher->decrypt($stored);
        if ($decrypted !== null) {
          $token = $decrypted;
        }
      }
    }
    $this->cachedToken = $token;
    return $token;
  }

  public function isDefinedByConstant(): bool {
    return defined(self::CONSTANT_NAME) && trim((string) constant(self::CONSTANT_NAME)) !== '';
  }

  public function hasStoredToken(): bool {
    $stored = get_option(self::OPTION_TOKEN, '');
    return is_string($stored) && $stored !== '';
  }

  /**
   * True if a token is stored but cannot be decrypted anymore (e.g. the WordPress salts changed).
   */
  public function isStoredTokenUnreadable(): bool {
    if (!$this->hasStoredToken()) {
      return false;
    }
    return $this->cipher->decrypt((string) get_option(self::OPTION_TOKEN, '')) === null;
  }

  public function canStore(): bool {
    return $this->cipher->isAvailable();
  }

  /**
   * Stores the token encrypted. Returns false if encryption is not available.
   */
  public function save(string $token): bool {
    $encrypted = $this->cipher->encrypt($token);
    if ($encrypted === null) {
      return false;
    }
    update_option(self::OPTION_TOKEN, $encrypted, false);
    $this->cachedToken = null;
    return true;
  }

  public function delete(): void {
    delete_option(self::OPTION_TOKEN);
    $this->cachedToken = null;
  }

  /**
   * Masked representation for the UI, e.g. "github_pat_…a1b2".
   */
  public function getMaskedToken(): string {
    $token = $this->getToken();
    if ($token === '') {
      return '';
    }
    $prefix = 'ghp_';
    if (strncmp($token, 'github_pat_', 11) === 0) {
      $prefix = 'github_pat_';
    } elseif (strncmp($token, 'ghp_', 4) !== 0) {
      $prefix = '';
    }
    return $prefix . '…' . substr($token, -4);
  }
}

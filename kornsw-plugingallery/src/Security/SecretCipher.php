<?php

declare(strict_types=1);

namespace KornSW\PluginGallery\Security;

if (!defined('ABSPATH')) {
  exit;
}

/**
 * Symmetric encryption of secrets stored in the database (AES-256-GCM, key derived from the WordPress salts).
 * If the salts of the installation change, stored secrets become unreadable and must be entered again.
 */
final class SecretCipher {

  private const FORMAT_PREFIX = 'v1:';
  private const CIPHER = 'aes-256-gcm';
  private const IV_LENGTH = 12;
  private const TAG_LENGTH = 16;

  /**
   * True if the OpenSSL extension provides the required cipher.
   */
  public function isAvailable(): bool {
    if (!function_exists('openssl_encrypt') || !function_exists('openssl_get_cipher_methods')) {
      return false;
    }
    return in_array(self::CIPHER, openssl_get_cipher_methods(), true);
  }

  /**
   * Encrypts a secret; returns null if encryption is not available.
   */
  public function encrypt(string $plainText): ?string {
    if (!$this->isAvailable()) {
      return null;
    }
    $iv = random_bytes(self::IV_LENGTH);
    $tag = '';
    $cipherText = openssl_encrypt($plainText, self::CIPHER, $this->key(), OPENSSL_RAW_DATA, $iv, $tag, '', self::TAG_LENGTH);
    if ($cipherText === false) {
      return null;
    }
    return self::FORMAT_PREFIX . base64_encode($iv . $tag . $cipherText);
  }

  /**
   * Decrypts a value produced by encrypt(); returns null if it cannot be decrypted (e.g. changed salts).
   */
  public function decrypt(string $storedValue): ?string {
    if (!$this->isAvailable() || strncmp($storedValue, self::FORMAT_PREFIX, strlen(self::FORMAT_PREFIX)) !== 0) {
      return null;
    }
    $binary = base64_decode(substr($storedValue, strlen(self::FORMAT_PREFIX)), true);
    if ($binary === false || strlen($binary) <= self::IV_LENGTH + self::TAG_LENGTH) {
      return null;
    }
    $iv = substr($binary, 0, self::IV_LENGTH);
    $tag = substr($binary, self::IV_LENGTH, self::TAG_LENGTH);
    $cipherText = substr($binary, self::IV_LENGTH + self::TAG_LENGTH);
    $plainText = openssl_decrypt($cipherText, self::CIPHER, $this->key(), OPENSSL_RAW_DATA, $iv, $tag);
    if ($plainText === false) {
      return null;
    }
    return $plainText;
  }

  private function key(): string {
    return hash('sha256', wp_salt('auth') . '|kornsw-plugingallery', true);
  }
}

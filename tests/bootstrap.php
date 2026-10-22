<?php

declare(strict_types=1);

/*
 * Minimal WordPress stubs to run the domain logic of the plugin with plain PHP (no WordPress installation).
 * HTTP requests are answered by FakeHttp; options and transients live in memory.
 */

define('ABSPATH', __DIR__ . '/');
define('DAY_IN_SECONDS', 86400);
define('MINUTE_IN_SECONDS', 60);
define('KORNSW_PG_DIR', dirname(__DIR__) . '/kornsw-plugingallery/');
define('KORNSW_PG_URL', 'https://example.org/wp-content/plugins/kornsw-plugingallery/');
define('KORNSW_PG_VERSION', '0.0.0-test');
define('KORNSW_PG_FILE', KORNSW_PG_DIR . 'kornsw-plugingallery.php');

spl_autoload_register(function (string $className): void {
  $namespacePrefix = 'KornSW\\PluginGallery\\';
  if (strncmp($className, $namespacePrefix, strlen($namespacePrefix)) !== 0) {
    return;
  }
  $fileName = KORNSW_PG_DIR . 'src/' . str_replace('\\', '/', substr($className, strlen($namespacePrefix))) . '.php';
  if (is_readable($fileName)) {
    require_once $fileName;
  }
});

final class WP_Error {
  private string $message;

  public function __construct(string $code = '', string $message = '') {
    $this->message = $message;
  }

  public function get_error_message(): string {
    return $this->message;
  }
}

final class TestState {
  /** @var array<string, mixed> */
  public static array $options = [];
  /** @var array<string, mixed> */
  public static array $transients = [];

  public static function reset(): void {
    self::$options = [];
    self::$transients = [];
    FakeHttp::reset();
  }
}

/**
 * Routes requests to registered handlers and records every request.
 */
final class FakeHttp {
  /** @var array<string, callable> */
  public static array $routes = [];
  /** @var array<int, array{url: string, headers: array<string, string>}> */
  public static array $requests = [];

  public static function reset(): void {
    self::$routes = [];
    self::$requests = [];
  }

  public static function on(string $url, callable $handler): void {
    self::$routes[$url] = $handler;
  }

  public static function json(int $status, $body, array $headers = []): array {
    return ['status' => $status, 'body' => json_encode($body), 'headers' => $headers];
  }

  public static function countRequests(string $urlPrefix): int {
    $count = 0;
    foreach (self::$requests as $request) {
      if (strpos($request['url'], $urlPrefix) === 0) {
        $count++;
      }
    }
    return $count;
  }
}

function wp_safe_remote_get(string $url, array $arguments = []) {
  $headers = $arguments['headers'] ?? [];
  FakeHttp::$requests[] = ['url' => $url, 'headers' => $headers];
  if (!isset(FakeHttp::$routes[$url])) {
    return ['status' => 404, 'body' => '{"message":"Not Found"}', 'headers' => []];
  }
  return (FakeHttp::$routes[$url])($headers);
}

function is_wp_error($value): bool {
  return $value instanceof WP_Error;
}

function wp_remote_retrieve_response_code($response): int {
  return (int) $response['status'];
}

function wp_remote_retrieve_body($response): string {
  return (string) $response['body'];
}

function wp_remote_retrieve_header($response, string $name): string {
  $headers = array_change_key_case($response['headers'] ?? [], CASE_LOWER);
  return (string) ($headers[strtolower($name)] ?? '');
}

function get_option(string $name, $default = false) {
  return array_key_exists($name, TestState::$options) ? TestState::$options[$name] : $default;
}

function update_option(string $name, $value, $autoload = null): bool {
  TestState::$options[$name] = $value;
  return true;
}

function delete_option(string $name): bool {
  unset(TestState::$options[$name]);
  return true;
}

function get_transient(string $name) {
  return array_key_exists($name, TestState::$transients) ? TestState::$transients[$name] : false;
}

function set_transient(string $name, $value, int $expiration = 0): bool {
  TestState::$transients[$name] = $value;
  return true;
}

function delete_transient(string $name): bool {
  unset(TestState::$transients[$name]);
  return true;
}

function wp_parse_url(string $url, int $component = -1) {
  return parse_url($url, $component);
}

function wp_salt(string $scheme = 'auth'): string {
  return 'test-salt-' . $scheme;
}

function wp_date(string $format, int $timestamp): string {
  return gmdate($format, $timestamp);
}

function sanitize_text_field(string $value): string {
  return trim(strip_tags($value));
}

function wp_strip_all_tags(string $value): string {
  return trim(strip_tags($value));
}

function esc_html(string $value): string {
  return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function untrailingslashit(string $value): string {
  return rtrim($value, '/\\');
}

/** @var array<string, mixed> */
$GLOBALS['kornsw_pg_test_plugins_api'] = [];

function plugins_api(string $action, array $arguments) {
  $slug = (string) $arguments['slug'];
  if (!isset($GLOBALS['kornsw_pg_test_plugins_api'][$slug])) {
    return new WP_Error('plugins_api_failed', 'Plugin not found.');
  }
  return (object) $GLOBALS['kornsw_pg_test_plugins_api'][$slug];
}

/**
 * Tiny assertion helper.
 */
final class Assert {
  public static int $passed = 0;
  /** @var string[] */
  public static array $failures = [];

  public static function same($expected, $actual, string $message): void {
    if ($expected === $actual) {
      self::$passed++;
      return;
    }
    self::$failures[] = $message . ' - expected ' . var_export($expected, true) . ', got ' . var_export($actual, true);
  }

  public static function true(bool $condition, string $message): void {
    self::same(true, $condition, $message);
  }
}

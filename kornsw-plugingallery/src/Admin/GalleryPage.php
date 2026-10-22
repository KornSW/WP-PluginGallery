<?php

declare(strict_types=1);

namespace KornSW\PluginGallery\Admin;

use KornSW\PluginGallery\Catalog\CatalogBuilder;
use KornSW\PluginGallery\GitHub\GitHubClient;
use KornSW\PluginGallery\GitHub\GitHubTokenStore;
use KornSW\PluginGallery\Installer\PluginInstaller;
use KornSW\PluginGallery\Plugin;
use KornSW\PluginGallery\SourceConfiguration;

if (!defined('ABSPATH')) {
  exit;
}

/**
 * The admin page "Plugins > Plugin Gallery (KornSW)".
 */
final class GalleryPage {

  public const TRANSIENT_DISCOVERY = 'kornsw_pg_discovery_v3';

  private CatalogBuilder $catalogBuilder;
  private SourceConfiguration $configuration;
  private PluginInstaller $installer;
  private GitHubClient $gitHubClient;
  private GitHubTokenStore $tokenStore;

  public function __construct(
    CatalogBuilder $catalogBuilder,
    SourceConfiguration $configuration,
    PluginInstaller $installer,
    GitHubClient $gitHubClient,
    GitHubTokenStore $tokenStore
  ) {
    $this->catalogBuilder = $catalogBuilder;
    $this->configuration = $configuration;
    $this->installer = $installer;
    $this->gitHubClient = $gitHubClient;
    $this->tokenStore = $tokenStore;
  }

  public function registerMenu(): void {
    add_plugins_page(
      'Plugin Gallery (KornSW)',
      'Plugin Gallery (KornSW)',
      Plugin::REQUIRED_CAPABILITY,
      Plugin::MENU_SLUG,
      [$this, 'render']
    );
  }

  /**
   * @param string[] $links
   * @return string[]
   */
  public function addPluginActionLinks(array $links): array {
    $url = admin_url('plugins.php?page=' . Plugin::MENU_SLUG);
    array_unshift($links, '<a href="' . esc_url($url) . '">Galerie</a>');
    return $links;
  }

  public function enqueueAssets(string $hookSuffix): void {
    if ($hookSuffix !== 'plugins_page_' . Plugin::MENU_SLUG) {
      return;
    }
    // Core modal "plugin details" for WordPress.org entries.
    add_thickbox();
    wp_enqueue_script('plugin-install');
    wp_enqueue_style('kornsw-pg-admin', KORNSW_PG_URL . 'assets/admin.css', [], KORNSW_PG_VERSION);
    wp_enqueue_script('kornsw-pg-admin', KORNSW_PG_URL . 'assets/admin.js', [], KORNSW_PG_VERSION, true);
  }

  public function render(): void {
    if (!current_user_can(Plugin::REQUIRED_CAPABILITY)) {
      wp_die(esc_html__('Keine Berechtigung.', 'kornsw-plugingallery'));
    }
    $catalog = $this->catalogBuilder->getCatalog();
    $discovery = get_transient(self::TRANSIENT_DISCOVERY);
    if (!is_array($discovery)) {
      $discovery = [];
    }
    ?>
    <div class="wrap kornsw-pg-wrap">
      <h1>Plugin Gallery (KornSW)</h1>
      <?php $this->renderNotice(); ?>

      <div class="kornsw-pg-toolbar">
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
          <input type="hidden" name="action" value="kornsw_pg_refresh">
          <?php wp_nonce_field('kornsw_pg_refresh'); ?>
          <button class="button">Galerie aktualisieren</button>
        </form>
        <span class="kornsw-pg-status"><?php echo esc_html($this->describeGitHubAccess()); ?></span>
      </div>

      <h2 class="kornsw-pg-section-title"><span class="kornsw-pg-star" aria-hidden="true">★</span> Favoriten</h2>
      <div class="kornsw-pg-grid">
        <?php
        foreach ($catalog['favorites'] as $entry) {
          $this->renderCard($entry);
        }
        foreach ($catalog['errors'][CatalogBuilder::TIER_FAVORITE] as $error) {
          $this->renderErrorCard($error);
        }
        if (count($catalog['favorites']) === 0 && count($catalog['errors'][CatalogBuilder::TIER_FAVORITE]) === 0) {
          echo '<div class="kornsw-pg-card"><h3>Keine Favoriten</h3><p class="description">config.php enthält keine Einträge.</p></div>';
        }
        ?>
      </div>

      <?php if (count($catalog['additional']) > 0 || count($catalog['related']) > 0 || count($catalog['errors'][CatalogBuilder::TIER_ADDITIONAL]) > 0) { ?>
        <h2 class="kornsw-pg-section-title">Weitere Plugins</h2>
        <?php if (count($catalog['related']) > 0) { ?>
          <p class="description">
            Neben den eigenen Quellen erscheinen hier automatisch alle weiteren <code>WP-*</code>-Repositories von
            <?php echo esc_html(implode(', ', $catalog['owners'])); ?>, die ein veröffentlichtes Plugin enthalten.
          </p>
        <?php } ?>
        <div class="kornsw-pg-grid">
          <?php
          foreach ($catalog['additional'] as $entry) {
            $this->renderCard($entry);
          }
          foreach ($catalog['errors'][CatalogBuilder::TIER_ADDITIONAL] as $error) {
            $this->renderErrorCard($error);
          }
          foreach ($catalog['related'] as $entry) {
            $this->renderCard($entry);
          }
          ?>
        </div>
      <?php } ?>

      <?php
      if (current_user_can('manage_options')) {
        $this->renderTokenPanel();
      }
      $this->renderZipPanel();
      $this->renderSourcesPanel();
      $this->renderDiscoveryModal($discovery);
      ?>
    </div>
    <?php
  }

  /**
   * @param array<string, mixed> $entry
   */
  private function renderCard(array $entry): void {
    $tier = (string) $entry['tier'];
    $isWordPressOrg = $entry['kind'] === 'wporg';
    $state = $this->installer->getInstalledState($entry);
    $cardClasses = 'kornsw-pg-card is-' . $tier;
    if ($isWordPressOrg) {
      $cardClasses .= ' is-wporg';
    }
    ?>
    <div class="<?php echo esc_attr($cardClasses); ?>">
      <div class="kornsw-pg-card-head">
        <?php if ($entry['icon_url'] !== '') { ?>
          <img class="kornsw-pg-icon" src="<?php echo esc_url((string) $entry['icon_url']); ?>" alt="" width="48" height="48" loading="lazy">
        <?php } ?>
        <h3><?php echo esc_html((string) $entry['name']); ?></h3>
        <?php if ($tier === CatalogBuilder::TIER_FAVORITE) { ?>
          <span class="kornsw-pg-badge is-favorite" title="Favorit"><span aria-hidden="true">★</span> Favorit</span>
        <?php } ?>
      </div>
      <div class="description"><?php echo wp_kses_post((string) $entry['description']); ?></div>
      <div class="kornsw-pg-meta">
        <?php echo esc_html($this->describeMeta($entry)); ?>
      </div>
      <div class="kornsw-pg-actions">
        <?php $this->renderActions($entry, $state); ?>
      </div>
      <div class="kornsw-pg-source"><?php echo esc_html($this->describeSource($entry)); ?></div>
    </div>
    <?php
  }

  /**
   * @param array<string, mixed> $entry
   * @param array{installed: bool, file: string, version: string, active: bool} $state
   */
  private function renderActions(array $entry, array $state): void {
    $isWordPressOrg = $entry['kind'] === 'wporg';
    if ($state['installed']) {
      $label = 'Installiert';
      if ($state['version'] !== '') {
        $label .= ' · ' . $state['version'];
      }
      echo '<span class="kornsw-pg-installed">' . esc_html($label) . '</span>';
      if (!$state['active'] && current_user_can('activate_plugins')) {
        echo '<a class="button" href="' . esc_url($this->installer->getActivationUrl($state['file'])) . '">Aktivieren</a>';
      }
      if ($state['version'] !== '' && version_compare($state['version'], (string) $entry['version'], '<')) {
        echo '<a class="kornsw-pg-update" href="' . esc_url(self_admin_url('plugins.php')) . '">Update auf '
          . esc_html((string) $entry['version']) . ' verfügbar</a>';
      }
    } elseif ($isWordPressOrg) {
      echo '<a class="button button-primary" href="' . esc_url($this->installer->getWordPressOrgInstallUrl((string) $entry['slug'])) . '">Installieren</a>';
    } else {
      $packageUrl = (string) $entry['download_url'];
      ?>
      <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <input type="hidden" name="action" value="kornsw_pg_install_plugin">
        <input type="hidden" name="package" value="<?php echo esc_attr($packageUrl); ?>">
        <?php wp_nonce_field('kornsw_pg_install_' . md5($packageUrl)); ?>
        <button class="button button-primary">Installieren</button>
      </form>
      <?php
    }

    if ($isWordPressOrg) {
      $detailsUrl = $this->installer->getWordPressOrgDetailsUrl((string) $entry['slug']);
      echo '<a class="button thickbox open-plugin-details-modal" href="' . esc_url($detailsUrl) . '">Details</a>';
    } elseif ($entry['homepage'] !== '') {
      echo '<a class="button" href="' . esc_url((string) $entry['homepage']) . '" target="_blank" rel="noopener noreferrer">Projektseite</a>';
    }
  }

  /**
   * @param array<string, string> $error
   */
  private function renderErrorCard(array $error): void {
    ?>
    <div class="kornsw-pg-card is-unavailable">
      <h3><?php echo esc_html((string) ($error['label'] ?? 'Quelle')); ?></h3>
      <p class="description"><?php echo esc_html((string) ($error['error'] ?? 'Nicht verfügbar')); ?></p>
      <div class="kornsw-pg-actions"><span class="kornsw-pg-unavailable">Nicht verfügbar</span></div>
      <div class="kornsw-pg-source"><?php echo esc_html((string) ($error['source'] ?? '')); ?></div>
    </div>
    <?php
  }

  private function renderTokenPanel(): void {
    $rateLimit = $this->gitHubClient->getRateLimitStatus();
    ?>
    <div class="kornsw-pg-panel" id="kornsw-pg-github">
      <h2>GitHub-Zugriff (Personal Access Token)</h2>
      <p>
        Ohne Token erlaubt GitHub nur 60 API-Anfragen pro Stunde und IP-Adresse – auf Shared Hosting oft schon
        nach kurzer Zeit erschöpft. Mit Token sind es 5.000 pro Stunde. Es genügt ein
        <a href="https://github.com/settings/personal-access-tokens/new" target="_blank" rel="noopener noreferrer">Fine-grained Token</a>
        mit „Public repositories (read-only)“ und ohne weitere Berechtigungen.
      </p>
      <p><strong>Status:</strong> <?php echo esc_html($this->describeTokenState()); ?></p>
      <?php if ($rateLimit !== null) { ?>
        <p class="description"><?php echo esc_html($this->describeRateLimit($rateLimit)); ?></p>
      <?php } ?>

      <?php if ($this->tokenStore->isDefinedByConstant()) { ?>
        <p class="description">Der Token ist in der <code>wp-config.php</code> als <code><?php echo esc_html(GitHubTokenStore::CONSTANT_NAME); ?></code> definiert und kann hier nicht geändert werden.</p>
      <?php } elseif (!$this->tokenStore->canStore()) { ?>
        <p class="description">
          Die PHP-Erweiterung OpenSSL fehlt, daher kann der Token nicht verschlüsselt gespeichert werden.
          Alternative: <code>define('<?php echo esc_html(GitHubTokenStore::CONSTANT_NAME); ?>', '…');</code> in der <code>wp-config.php</code>.
        </p>
      <?php } else { ?>
        <form class="kornsw-pg-inline" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
          <input type="hidden" name="action" value="kornsw_pg_save_github_token">
          <?php wp_nonce_field('kornsw_pg_save_github_token'); ?>
          <input type="password" name="github_token" autocomplete="off" spellcheck="false" placeholder="github_pat_… / ghp_…" required>
          <button class="button button-primary">Token prüfen und speichern</button>
        </form>
        <?php if ($this->tokenStore->hasStoredToken()) { ?>
          <form class="kornsw-pg-inline kornsw-pg-spaced" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="kornsw_pg_remove_github_token">
            <?php wp_nonce_field('kornsw_pg_remove_github_token'); ?>
            <button class="button-link-delete">Gespeicherten Token entfernen</button>
          </form>
        <?php } ?>
        <p class="description">
          Der Token wird verschlüsselt gespeichert und nur an <code>api.github.com</code> gesendet.
          Alternativ kann er als <code><?php echo esc_html(GitHubTokenStore::CONSTANT_NAME); ?></code> in der <code>wp-config.php</code> definiert werden.
        </p>
      <?php } ?>
    </div>
    <?php
  }

  private function renderZipPanel(): void {
    ?>
    <div class="kornsw-pg-panel">
      <h2>Plugin aus ZIP-Datei installieren</h2>
      <p>Direkte URL auf eine Plugin-ZIP-Datei eingeben. Die Installation erfolgt normal über WordPress und wird nicht als Galerie-Quelle gespeichert.</p>
      <form class="kornsw-pg-inline" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <input type="hidden" name="action" value="kornsw_pg_install_zip_url">
        <?php wp_nonce_field('kornsw_pg_install_zip_url'); ?>
        <input type="url" name="zip_url" placeholder="https://example.org/plugin.zip" required>
        <button class="button button-primary">ZIP-Plugin installieren</button>
      </form>
    </div>
    <?php
  }

  private function renderSourcesPanel(): void {
    $extraSources = $this->configuration->getExtraSources();
    ?>
    <div class="kornsw-pg-panel">
      <div class="kornsw-pg-toolbar">
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
          <input type="hidden" name="action" value="kornsw_pg_discover">
          <?php wp_nonce_field('kornsw_pg_discover'); ?>
          <button class="button button-secondary">Weitere Plugins auf GitHub suchen</button>
        </form>
        <span class="description">Durchsucht auch die übrigen (nicht <code>WP-*</code>) Repositories der Favoriten-Autoren.</span>
      </div>
      <h2>Zusätzliche Galerie-Quelle</h2>
      <p>
        Erlaubt sind: GitHub-Repository-URL (mit <code>*.update.json</code> unter <code>/doc</code>), direkte
        <code>*.update.json</code>-URL, WordPress.org-Plugin-URL (<code>https://wordpress.org/plugins/&lt;slug&gt;/</code>)
        oder einfach der Slug eines Plugins aus dem WordPress.org-Verzeichnis (z. B. <code>wordfence</code>).
      </p>
      <form class="kornsw-pg-inline" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <input type="hidden" name="action" value="kornsw_pg_add_source">
        <?php wp_nonce_field('kornsw_pg_add_source'); ?>
        <input type="text" name="source_url" placeholder="https://github.com/Owner/Repo · https://…/plugin.update.json · wordpress.org-Slug" required>
        <button class="button">Quelle hinzufügen</button>
      </form>
      <div class="kornsw-pg-sources">
        <?php if (count($extraSources) === 0) { ?>
          <em>Keine zusätzlichen Quellen gespeichert.</em>
        <?php } ?>
        <?php foreach ($extraSources as $index => $source) { ?>
          <div class="kornsw-pg-source-row">
            <span><strong><?php echo esc_html($source->getTypeLabel()); ?>:</strong> <?php echo esc_html($source->getUrl()); ?></span>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
              <input type="hidden" name="action" value="kornsw_pg_remove_source">
              <input type="hidden" name="source_index" value="<?php echo esc_attr((string) $index); ?>">
              <?php wp_nonce_field('kornsw_pg_remove_source_' . $index); ?>
              <button class="button-link-delete">Entfernen</button>
            </form>
          </div>
        <?php } ?>
      </div>
    </div>
    <?php
  }

  /**
   * @param array<int, array<string, mixed>> $discovery
   */
  private function renderDiscoveryModal(array $discovery): void {
    $isOpen = isset($_GET['kornsw_pg_discovery']) && $_GET['kornsw_pg_discovery'] === '1';
    $modalClasses = 'kornsw-pg-modal';
    if ($isOpen) {
      $modalClasses .= ' is-open';
    }
    ?>
    <div id="kornsw-pg-modal" class="<?php echo esc_attr($modalClasses); ?>">
      <div class="kornsw-pg-modal-box">
        <div class="kornsw-pg-modal-head">
          <h2>Weitere Plugins auf GitHub</h2>
          <button type="button" id="kornsw-pg-modal-close" class="kornsw-pg-modal-close" aria-label="Schließen">×</button>
        </div>
        <?php if (count($discovery) === 0) { ?>
          <p>Keine neuen Plugins gefunden oder die Suche wurde noch nicht ausgeführt.</p>
        <?php } else { ?>
          <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="kornsw_pg_add_discovered">
            <?php wp_nonce_field('kornsw_pg_add_discovered'); ?>
            <?php foreach ($discovery as $candidate) { ?>
              <div class="kornsw-pg-discovery-row">
                <label>
                  <input type="checkbox" name="json_urls[]" value="<?php echo esc_attr((string) $candidate['json_url']); ?>">
                  <span>
                    <strong><?php echo esc_html((string) $candidate['name']); ?></strong> · Version <?php echo esc_html((string) $candidate['version']); ?><br>
                    <small><?php echo esc_html((string) $candidate['source']); ?></small>
                  </span>
                </label>
              </div>
            <?php } ?>
            <p><button class="button button-primary">Ausgewählte hinzufügen</button></p>
          </form>
        <?php } ?>
      </div>
    </div>
    <?php
  }

  private function renderNotice(): void {
    if (empty($_GET['kornsw_pg_notice'])) {
      return;
    }
    $noticeClass = 'notice-success';
    if (isset($_GET['kornsw_pg_notice_type']) && $_GET['kornsw_pg_notice_type'] === 'error') {
      $noticeClass = 'notice-error';
    }
    $message = sanitize_text_field(rawurldecode(wp_unslash((string) $_GET['kornsw_pg_notice'])));
    echo '<div class="notice ' . esc_attr($noticeClass) . ' is-dismissible"><p>' . esc_html($message) . '</p></div>';
  }

  /**
   * @param array<string, mixed> $entry
   */
  private function describeMeta(array $entry): string {
    $parts = ['Version ' . $entry['version']];
    if ($entry['author'] !== '') {
      $parts[] = (string) $entry['author'];
    }
    if ($entry['requires'] !== '') {
      $parts[] = 'WordPress ab ' . $entry['requires'];
    }
    if ($entry['requires_php'] !== '') {
      $parts[] = 'PHP ab ' . $entry['requires_php'];
    }
    if ((int) $entry['active_installs'] > 0) {
      $parts[] = number_format_i18n((int) $entry['active_installs']) . '+ aktive Installationen';
    }
    return implode(' · ', $parts);
  }

  /**
   * @param array<string, mixed> $entry
   */
  private function describeSource(array $entry): string {
    if ($entry['kind'] === 'wporg') {
      return 'WordPress.org-Plugin-Verzeichnis · ' . $entry['slug'];
    }
    $source = (string) $entry['source'];
    if ($entry['tier'] === CatalogBuilder::TIER_RELATED) {
      return 'Automatisch gefunden · ' . $source;
    }
    return $source;
  }

  private function describeGitHubAccess(): string {
    $text = 'GitHub: ';
    if ($this->tokenStore->getToken() !== '') {
      $text .= 'mit Token';
    } else {
      $text .= 'anonym (60 Anfragen/Stunde)';
    }
    $rateLimit = $this->gitHubClient->getRateLimitStatus();
    if ($rateLimit !== null) {
      $text .= ' · ' . $this->describeRateLimit($rateLimit);
    }
    return $text;
  }

  private function describeTokenState(): string {
    if ($this->tokenStore->isDefinedByConstant()) {
      return 'Token aus wp-config.php aktiv (' . $this->tokenStore->getMaskedToken() . ').';
    }
    if ($this->tokenStore->isStoredTokenUnreadable()) {
      return 'Der gespeicherte Token kann nicht mehr entschlüsselt werden (wurden die WordPress-Salts geändert?). Bitte neu eingeben.';
    }
    if ($this->tokenStore->hasStoredToken()) {
      return 'Token gespeichert (' . $this->tokenStore->getMaskedToken() . ').';
    }
    return 'Kein Token hinterlegt – GitHub wird anonym abgefragt.';
  }

  /**
   * @param array<string, mixed> $rateLimit
   */
  private function describeRateLimit(array $rateLimit): string {
    $text = 'Letztes API-Kontingent: ' . (int) $rateLimit['remaining'] . '/' . (int) $rateLimit['limit'];
    if ((int) $rateLimit['reset'] > time()) {
      $text .= ', Reset ' . wp_date('H:i', (int) $rateLimit['reset']);
    }
    return $text;
  }
}

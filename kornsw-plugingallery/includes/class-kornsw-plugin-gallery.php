<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class KornSW_Plugin_Gallery {
	private const OPTION_EXTRA_SOURCES = 'kornsw_pg_extra_sources';
	private const TRANSIENT_CATALOG = 'kornsw_pg_catalog_v2';
	private const TRANSIENT_DISCOVERY = 'kornsw_pg_discovery_v2';
	private const CACHE_TTL = 600;
	private const MENU_SLUG = 'kornsw-plugin-gallery';

	private static ?KornSW_Plugin_Gallery $instance = null;

	public static function instance(): KornSW_Plugin_Gallery {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
	}

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'register_admin_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_filter( 'plugin_action_links_' . plugin_basename( KORNSW_PLUGIN_GALLERY_FILE ), [ $this, 'plugin_action_links' ] );

		add_action( 'admin_post_kornsw_pg_refresh', [ $this, 'handle_refresh' ] );
		add_action( 'admin_post_kornsw_pg_add_source', [ $this, 'handle_add_source' ] );
		add_action( 'admin_post_kornsw_pg_remove_source', [ $this, 'handle_remove_source' ] );
		add_action( 'admin_post_kornsw_pg_install_plugin', [ $this, 'handle_install_plugin' ] );
		add_action( 'admin_post_kornsw_pg_install_zip_url', [ $this, 'handle_install_zip_url' ] );
		add_action( 'admin_post_kornsw_pg_discover', [ $this, 'handle_discover' ] );
		add_action( 'admin_post_kornsw_pg_add_discovered', [ $this, 'handle_add_discovered' ] );
	}

	public function register_admin_menu(): void {
		add_plugins_page(
			'Plugin Gallery (KornSW)',
			'Plugin Gallery (KornSW)',
			'install_plugins',
			self::MENU_SLUG,
			[ $this, 'render_page' ]
		);
	}

	public function plugin_action_links( array $links ): array {
		$url = admin_url( 'plugins.php?page=' . self::MENU_SLUG );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">Einstellungen</a>' );
		return $links;
	}

	public function enqueue_assets( string $hook ): void {
		if ( 'plugins_page_' . self::MENU_SLUG !== $hook ) {
			return;
		}
		wp_enqueue_style( 'kornsw-pg-admin', KORNSW_PLUGIN_GALLERY_URL . 'assets/admin.css', [], KORNSW_PLUGIN_GALLERY_VERSION );
		wp_enqueue_script( 'kornsw-pg-admin', KORNSW_PLUGIN_GALLERY_URL . 'assets/admin.js', [], KORNSW_PLUGIN_GALLERY_VERSION, true );
	}

	private function fixed_repositories(): array {
		$config_file = KORNSW_PLUGIN_GALLERY_DIR . 'config.php';
		$repos = is_file( $config_file ) ? require $config_file : [];
		if ( ! is_array( $repos ) ) {
			return [];
		}
		return array_values( array_unique( array_filter( array_map( 'trim', $repos ) ) ) );
	}

	private function extra_sources(): array {
		$sources = get_option( self::OPTION_EXTRA_SOURCES, [] );
		return is_array( $sources ) ? array_values( $sources ) : [];
	}

	private function save_extra_sources( array $sources ): void {
		update_option( self::OPTION_EXTRA_SOURCES, array_values( $sources ), false );
		delete_transient( self::TRANSIENT_CATALOG );
	}

	private function github_repo_parts( string $url ): ?array {
		$parts = wp_parse_url( trim( $url ) );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['path'] ) ) {
			return null;
		}
		if ( strtolower( $parts['host'] ) !== 'github.com' && strtolower( $parts['host'] ) !== 'www.github.com' ) {
			return null;
		}
		$segments = array_values( array_filter( explode( '/', trim( $parts['path'], '/' ) ) ) );
		if ( count( $segments ) < 2 ) {
			return null;
		}
		$repo = preg_replace( '/\.git$/i', '', $segments[1] );
		if ( ! $repo ) {
			return null;
		}
		return [ 'owner' => $segments[0], 'repo' => $repo ];
	}

	private function github_headers(): array {
		return [
			'Accept' => 'application/vnd.github+json',
			'User-Agent' => 'KornSW-Plugin-Gallery/' . KORNSW_PLUGIN_GALLERY_VERSION,
		];
	}

	private function remote_json( string $url ): array {
		$response = wp_safe_remote_get( $url, [
			'timeout' => 12,
			'redirection' => 5,
			'headers' => [ 'Accept' => 'application/json' ],
		] );
		if ( is_wp_error( $response ) ) {
			return [ 'ok' => false, 'error' => $response->get_error_message() ];
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return [ 'ok' => false, 'error' => 'HTTP ' . $code ];
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			return [ 'ok' => false, 'error' => 'Ungültiges JSON' ];
		}
		if ( empty( $data['name'] ) || empty( $data['version'] ) || empty( $data['download_url'] ) ) {
			return [ 'ok' => false, 'error' => 'Update-JSON enthält nicht die benötigten Felder.' ];
		}
		return [ 'ok' => true, 'data' => $data ];
	}

	private function scan_github_repository( string $repo_url, string $source_kind = 'repository' ): array {
		$parts = $this->github_repo_parts( $repo_url );
		if ( null === $parts ) {
			return [
				'plugins' => [],
				'errors' => [ [ 'source' => $repo_url, 'label' => $repo_url, 'error' => 'Keine gültige GitHub-Repository-URL.' ] ],
			];
		}

		$api = sprintf( 'https://api.github.com/repos/%s/%s/contents/doc', rawurlencode( $parts['owner'] ), rawurlencode( $parts['repo'] ) );
		$response = wp_safe_remote_get( $api, [ 'timeout' => 12, 'headers' => $this->github_headers() ] );
		if ( is_wp_error( $response ) ) {
			return [ 'plugins' => [], 'errors' => [ [ 'source' => $repo_url, 'label' => $parts['owner'] . '/' . $parts['repo'], 'error' => $response->get_error_message() ] ] ];
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return [ 'plugins' => [], 'errors' => [ [ 'source' => $repo_url, 'label' => $parts['owner'] . '/' . $parts['repo'], 'error' => 'Nicht verfügbar (HTTP ' . $code . ')' ] ] ];
		}
		$entries = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $entries ) ) {
			return [ 'plugins' => [], 'errors' => [ [ 'source' => $repo_url, 'label' => $parts['owner'] . '/' . $parts['repo'], 'error' => 'Nicht verfügbar (ungültige GitHub-Antwort)' ] ] ];
		}

		$plugins = [];
		$errors = [];
		$found_update_json = false;
		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) || ( $entry['type'] ?? '' ) !== 'file' ) {
				continue;
			}
			$name = (string) ( $entry['name'] ?? '' );
			if ( ! preg_match( '/\.update\.json$/i', $name ) ) {
				continue;
			}
			$found_update_json = true;
			$json_url = (string) ( $entry['download_url'] ?? '' );
			if ( '' === $json_url ) {
				$errors[] = [ 'source' => $repo_url, 'label' => $name, 'error' => 'Keine Raw-URL von GitHub erhalten.' ];
				continue;
			}
			$loaded = $this->remote_json( $json_url );
			if ( ! $loaded['ok'] ) {
				$errors[] = [ 'source' => $json_url, 'label' => $name, 'error' => $loaded['error'] ];
				continue;
			}
			$plugins[] = $this->normalize_plugin( $loaded['data'], $json_url, $repo_url, $source_kind );
		}
		if ( ! $found_update_json ) {
			$errors[] = [ 'source' => $repo_url, 'label' => $parts['owner'] . '/' . $parts['repo'], 'error' => 'Nicht verfügbar: keine *.update.json unter /doc gefunden.' ];
		}
		return [ 'plugins' => $plugins, 'errors' => $errors ];
	}

	private function normalize_plugin( array $data, string $json_url, string $source, string $source_kind ): array {
		return [
			'name' => (string) ( $data['name'] ?? 'Unbenanntes Plugin' ),
			'version' => (string) ( $data['version'] ?? '' ),
			'author' => (string) ( $data['author'] ?? '' ),
			'homepage' => (string) ( $data['homepage'] ?? '' ),
			'download_url' => (string) ( $data['download_url'] ?? '' ),
			'requires' => (string) ( $data['requires'] ?? '' ),
			'requires_php' => (string) ( $data['requires_php'] ?? '' ),
			'tested' => (string) ( $data['tested'] ?? '' ),
			'description' => (string) ( $data['sections']['description'] ?? '' ),
			'changelog' => (string) ( $data['sections']['changelog'] ?? '' ),
			'json_url' => $json_url,
			'source' => $source,
			'source_kind' => $source_kind,
		];
	}

	private function load_catalog( bool $force = false ): array {
		if ( ! $force ) {
			$cached = get_transient( self::TRANSIENT_CATALOG );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$catalog = [ 'plugins' => [], 'errors' => [], 'generated_at' => time() ];
		foreach ( $this->fixed_repositories() as $repo ) {
			$result = $this->scan_github_repository( $repo, 'fixed_repository' );
			$catalog['plugins'] = array_merge( $catalog['plugins'], $result['plugins'] );
			$catalog['errors'] = array_merge( $catalog['errors'], $result['errors'] );
		}

		foreach ( $this->extra_sources() as $source ) {
			$type = (string) ( $source['type'] ?? '' );
			$url = (string) ( $source['url'] ?? '' );
			if ( 'repository' === $type ) {
				$result = $this->scan_github_repository( $url, 'dynamic_repository' );
				$catalog['plugins'] = array_merge( $catalog['plugins'], $result['plugins'] );
				$catalog['errors'] = array_merge( $catalog['errors'], $result['errors'] );
			} elseif ( 'json' === $type ) {
				$loaded = $this->remote_json( $url );
				if ( $loaded['ok'] ) {
					$catalog['plugins'][] = $this->normalize_plugin( $loaded['data'], $url, $url, 'dynamic_json' );
				} else {
					$catalog['errors'][] = [ 'source' => $url, 'label' => $url, 'error' => 'Nicht verfügbar: ' . $loaded['error'] ];
				}
			}
		}

		// Deduplizieren anhand der konkreten update.json; feste Quellen gewinnen.
		$dedup = [];
		foreach ( $catalog['plugins'] as $plugin ) {
			$key = strtolower( $plugin['json_url'] ?: ( $plugin['homepage'] . '|' . $plugin['name'] ) );
			if ( ! isset( $dedup[ $key ] ) ) {
				$dedup[ $key ] = $plugin;
			}
		}
		$catalog['plugins'] = array_values( $dedup );
		usort( $catalog['plugins'], static function ( array $a, array $b ): int { return strcasecmp( $a['name'], $b['name'] ); } );
		set_transient( self::TRANSIENT_CATALOG, $catalog, self::CACHE_TTL );
		return $catalog;
	}

	private function installed_plugin_state( array $plugin ): array {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$installed = get_plugins();
		$homepage = untrailingslashit( strtolower( $plugin['homepage'] ) );
		$name = strtolower( trim( $plugin['name'] ) );
		foreach ( $installed as $file => $data ) {
			$installed_homepage = untrailingslashit( strtolower( (string) ( $data['PluginURI'] ?? '' ) ) );
			$installed_name = strtolower( trim( (string) ( $data['Name'] ?? '' ) ) );
			if ( ( $homepage && $installed_homepage === $homepage ) || ( $name && $installed_name === $name ) ) {
				return [ 'installed' => true, 'file' => $file, 'version' => (string) ( $data['Version'] ?? '' ) ];
			}
		}
		return [ 'installed' => false, 'file' => '', 'version' => '' ];
	}

	public function render_page(): void {
		if ( ! current_user_can( 'install_plugins' ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'kornsw-plugingallery' ) );
		}
		$catalog = $this->load_catalog();
		$extra_sources = $this->extra_sources();
		$discovery = get_transient( self::TRANSIENT_DISCOVERY );
		?>
		<div class="wrap kornsw-pg-wrap">
			<h1>Plugin Gallery (KornSW)</h1>
			<?php $this->render_notice(); ?>

			<div class="kornsw-pg-toolbar">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="kornsw_pg_refresh">
					<?php wp_nonce_field( 'kornsw_pg_refresh' ); ?>
					<button class="button">Galerie aktualisieren</button>
				</form>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="kornsw_pg_discover">
					<?php wp_nonce_field( 'kornsw_pg_discover' ); ?>
					<button class="button button-secondary">Weitere Plugins auf GitHub suchen</button>
				</form>
			</div>

			<h2 class="kornsw-pg-section-title">Verfügbare Plugins</h2>
			<div class="kornsw-pg-grid">
				<?php foreach ( $catalog['plugins'] as $plugin ) : $this->render_plugin_card( $plugin ); endforeach; ?>
				<?php foreach ( $catalog['errors'] as $error ) : $this->render_error_card( $error ); endforeach; ?>
				<?php if ( empty( $catalog['plugins'] ) && empty( $catalog['errors'] ) ) : ?>
					<div class="kornsw-pg-card"><h2>Keine Plugins gefunden</h2><p class="description">Die konfigurierten Quellen enthalten derzeit keine Plugins.</p></div>
				<?php endif; ?>
			</div>

			<div class="kornsw-pg-panel">
				<h2>Plugin aus ZIP-Datei installieren</h2>
				<p>Direkte URL auf eine Plugin-ZIP-Datei eingeben. Die Installation erfolgt normal über WordPress und wird nicht als Galerie-Quelle gespeichert.</p>
				<form class="kornsw-pg-inline" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="kornsw_pg_install_zip_url">
					<?php wp_nonce_field( 'kornsw_pg_install_zip_url' ); ?>
					<input type="url" name="zip_url" placeholder="https://example.org/plugin.zip" required>
					<button class="button button-primary">ZIP-Plugin installieren</button>
				</form>
			</div>

			<div class="kornsw-pg-panel">
				<h2>Zusätzliche Galerie-Quelle</h2>
				<p>GitHub-Repository-URL oder direkte, per HTTP-GET erreichbare <code>*.update.json</code>-URL. GitHub-Repositories dürfen mehrere Update-Dateien enthalten.</p>
				<form class="kornsw-pg-inline" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="kornsw_pg_add_source">
					<?php wp_nonce_field( 'kornsw_pg_add_source' ); ?>
					<input type="url" name="source_url" placeholder="https://github.com/Owner/Repo oder https://…/plugin.update.json" required>
					<button class="button">Quelle hinzufügen</button>
				</form>
				<div class="kornsw-pg-sources">
					<?php if ( empty( $extra_sources ) ) : ?>
						<em>Keine zusätzlichen Quellen gespeichert.</em>
					<?php else : foreach ( $extra_sources as $index => $source ) : ?>
						<div class="kornsw-pg-source-row">
							<span><strong><?php echo esc_html( 'repository' === $source['type'] ? 'GitHub-Repo' : 'Update-JSON' ); ?>:</strong> <?php echo esc_html( $source['url'] ); ?></span>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<input type="hidden" name="action" value="kornsw_pg_remove_source">
								<input type="hidden" name="source_index" value="<?php echo esc_attr( (string) $index ); ?>">
								<?php wp_nonce_field( 'kornsw_pg_remove_source_' . $index ); ?>
								<button class="button-link-delete">Entfernen</button>
							</form>
						</div>
					<?php endforeach; endif; ?>
				</div>
			</div>

			<?php $this->render_discovery_modal( is_array( $discovery ) ? $discovery : [] ); ?>
		</div>
		<?php
	}

	private function render_plugin_card( array $plugin ): void {
		$state = $this->installed_plugin_state( $plugin );
		?>
		<div class="kornsw-pg-card">
			<h2><?php echo esc_html( $plugin['name'] ); ?></h2>
			<div class="description"><?php echo wp_kses_post( $plugin['description'] ); ?></div>
			<div class="kornsw-pg-meta">
				Version <?php echo esc_html( $plugin['version'] ); ?><?php if ( $plugin['author'] ) : ?> · <?php echo esc_html( $plugin['author'] ); ?><?php endif; ?>
				<?php if ( $plugin['requires'] ) : ?><br>WordPress ab <?php echo esc_html( $plugin['requires'] ); ?><?php endif; ?>
				<?php if ( $plugin['requires_php'] ) : ?> · PHP ab <?php echo esc_html( $plugin['requires_php'] ); ?><?php endif; ?>
			</div>
			<div class="kornsw-pg-actions">
				<?php if ( $state['installed'] ) : ?>
					<span class="kornsw-pg-installed">Installiert<?php echo $state['version'] ? ' · ' . esc_html( $state['version'] ) : ''; ?></span>
				<?php else : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="kornsw_pg_install_plugin">
						<input type="hidden" name="package" value="<?php echo esc_attr( $plugin['download_url'] ); ?>">
						<?php wp_nonce_field( 'kornsw_pg_install_' . md5( $plugin['download_url'] ) ); ?>
						<button class="button button-primary">Installieren</button>
					</form>
				<?php endif; ?>
				<?php if ( $plugin['homepage'] ) : ?><a class="button" href="<?php echo esc_url( $plugin['homepage'] ); ?>" target="_blank" rel="noopener noreferrer">Projektseite</a><?php endif; ?>
			</div>
			<div class="kornsw-pg-source"><?php echo esc_html( $plugin['source'] ); ?></div>
		</div>
		<?php
	}

	private function render_error_card( array $error ): void {
		?>
		<div class="kornsw-pg-card">
			<h2><?php echo esc_html( (string) ( $error['label'] ?? 'Quelle' ) ); ?></h2>
			<p class="description"><?php echo esc_html( (string) ( $error['error'] ?? 'Nicht verfügbar' ) ); ?></p>
			<div class="kornsw-pg-actions"><span class="kornsw-pg-unavailable">Nicht verfügbar</span></div>
			<div class="kornsw-pg-source"><?php echo esc_html( (string) ( $error['source'] ?? '' ) ); ?></div>
		</div>
		<?php
	}

	private function render_discovery_modal( array $discovery ): void {
		$open = isset( $_GET['kornsw_pg_discovery'] ) && '1' === $_GET['kornsw_pg_discovery'];
		?>
		<div id="kornsw-pg-modal" class="kornsw-pg-modal<?php echo $open ? ' is-open' : ''; ?>">
			<div class="kornsw-pg-modal-box">
				<div class="kornsw-pg-modal-head"><h2>Weitere Plugins auf GitHub</h2><button type="button" id="kornsw-pg-modal-close" class="kornsw-pg-modal-close">×</button></div>
				<?php if ( empty( $discovery ) ) : ?>
					<p>Keine neuen Plugins gefunden oder die Suche wurde noch nicht ausgeführt.</p>
				<?php else : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="kornsw_pg_add_discovered">
						<?php wp_nonce_field( 'kornsw_pg_add_discovered' ); ?>
						<?php foreach ( $discovery as $candidate ) : ?>
							<div class="kornsw-pg-discovery-row"><label>
								<input type="checkbox" name="json_urls[]" value="<?php echo esc_attr( $candidate['json_url'] ); ?>">
								<span><strong><?php echo esc_html( $candidate['name'] ); ?></strong> · Version <?php echo esc_html( $candidate['version'] ); ?><br><small><?php echo esc_html( $candidate['source'] ); ?></small></span>
							</label></div>
						<?php endforeach; ?>
						<p><button class="button button-primary">Ausgewählte hinzufügen</button></p>
					</form>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	private function redirect_with_notice( string $type, string $message, array $extra = [] ): void {
		$args = array_merge( [ 'page' => self::MENU_SLUG, 'kornsw_pg_notice_type' => $type, 'kornsw_pg_notice' => rawurlencode( $message ) ], $extra );
		wp_safe_redirect( add_query_arg( $args, admin_url( 'plugins.php' ) ) );
		exit;
	}

	private function render_notice(): void {
		if ( empty( $_GET['kornsw_pg_notice'] ) ) {
			return;
		}
		$type = isset( $_GET['kornsw_pg_notice_type'] ) && 'error' === $_GET['kornsw_pg_notice_type'] ? 'notice-error' : 'notice-success';
		$message = sanitize_text_field( rawurldecode( (string) $_GET['kornsw_pg_notice'] ) );
		echo '<div class="notice ' . esc_attr( $type ) . ' is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
	}

	public function handle_refresh(): void {
		$this->assert_can_install();
		check_admin_referer( 'kornsw_pg_refresh' );
		delete_transient( self::TRANSIENT_CATALOG );
		$this->load_catalog( true );
		$this->redirect_with_notice( 'success', 'Galerie wurde aktualisiert.' );
	}

	public function handle_add_source(): void {
		$this->assert_can_install();
		check_admin_referer( 'kornsw_pg_add_source' );
		$url = isset( $_POST['source_url'] ) ? esc_url_raw( wp_unslash( $_POST['source_url'] ) ) : '';
		if ( ! $url || ! wp_http_validate_url( $url ) ) {
			$this->redirect_with_notice( 'error', 'Ungültige URL.' );
		}
		$type = null !== $this->github_repo_parts( $url ) ? 'repository' : 'json';
		if ( 'json' === $type ) {
			$loaded = $this->remote_json( $url );
			if ( ! $loaded['ok'] ) {
				$this->redirect_with_notice( 'error', 'Update-JSON konnte nicht gelesen werden: ' . $loaded['error'] );
			}
		} else {
			$probe = $this->scan_github_repository( $url, 'dynamic_repository' );
			if ( empty( $probe['plugins'] ) ) {
				$this->redirect_with_notice( 'error', 'Repository enthält derzeit keine lesbare *.update.json unter /doc.' );
			}
		}
		$sources = $this->extra_sources();
		foreach ( $sources as $existing ) {
			if ( untrailingslashit( strtolower( $existing['url'] ) ) === untrailingslashit( strtolower( $url ) ) ) {
				$this->redirect_with_notice( 'success', 'Quelle war bereits gespeichert.' );
			}
		}
		$sources[] = [ 'type' => $type, 'url' => $url ];
		$this->save_extra_sources( $sources );
		$this->redirect_with_notice( 'success', 'Quelle wurde dauerhaft hinzugefügt.' );
	}

	public function handle_remove_source(): void {
		$this->assert_can_install();
		$index = isset( $_POST['source_index'] ) ? absint( $_POST['source_index'] ) : -1;
		check_admin_referer( 'kornsw_pg_remove_source_' . $index );
		$sources = $this->extra_sources();
		if ( isset( $sources[ $index ] ) ) {
			array_splice( $sources, $index, 1 );
			$this->save_extra_sources( $sources );
		}
		$this->redirect_with_notice( 'success', 'Quelle wurde entfernt.' );
	}

	public function handle_install_plugin(): void {
		$this->assert_can_install();
		$package = isset( $_POST['package'] ) ? esc_url_raw( wp_unslash( $_POST['package'] ) ) : '';
		check_admin_referer( 'kornsw_pg_install_' . md5( $package ) );
		$this->install_package( $package );
	}

	public function handle_install_zip_url(): void {
		$this->assert_can_install();
		check_admin_referer( 'kornsw_pg_install_zip_url' );
		$url = isset( $_POST['zip_url'] ) ? esc_url_raw( wp_unslash( $_POST['zip_url'] ) ) : '';
		if ( ! $url || ! wp_http_validate_url( $url ) ) {
			$this->redirect_with_notice( 'error', 'Ungültige ZIP-URL.' );
		}
		$path = wp_parse_url( $url, PHP_URL_PATH );
		if ( ! is_string( $path ) || ! preg_match( '/\.zip$/i', $path ) ) {
			$this->redirect_with_notice( 'error', 'Die URL muss auf eine ZIP-Datei zeigen.' );
		}
		$this->install_package( $url );
	}

	private function install_package( string $package ): void {
		if ( ! $package || ! wp_http_validate_url( $package ) ) {
			$this->redirect_with_notice( 'error', 'Ungültige Paket-URL.' );
		}
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		$skin = new Automatic_Upgrader_Skin();
		$upgrader = new Plugin_Upgrader( $skin );
		$result = $upgrader->install( $package );
		if ( is_wp_error( $result ) ) {
			$this->redirect_with_notice( 'error', 'Installation fehlgeschlagen: ' . $result->get_error_message() );
		}
		if ( false === $result ) {
			$errors = $skin->get_errors();
			$message = is_wp_error( $errors ) && $errors->has_errors() ? $errors->get_error_message() : 'Unbekannter Installationsfehler.';
			$this->redirect_with_notice( 'error', 'Installation fehlgeschlagen: ' . $message );
		}
		delete_transient( self::TRANSIENT_CATALOG );
		$this->redirect_with_notice( 'success', 'Plugin wurde installiert. Es erscheint jetzt normal in der WordPress-Plugin-Liste.' );
	}

	public function handle_discover(): void {
		$this->assert_can_install();
		check_admin_referer( 'kornsw_pg_discover' );
		$owners = [];
		foreach ( $this->fixed_repositories() as $repo ) {
			$parts = $this->github_repo_parts( $repo );
			if ( $parts ) {
				$owners[ strtolower( $parts['owner'] ) ] = $parts['owner'];
			}
		}
		$known_json = [];
		$catalog = $this->load_catalog();
		foreach ( $catalog['plugins'] as $plugin ) {
			$known_json[ strtolower( $plugin['json_url'] ) ] = true;
		}
		$candidates = [];
		foreach ( $owners as $owner ) {
			$repos = $this->github_user_repositories( $owner );
			foreach ( $repos as $repo_url ) {
				$result = $this->scan_github_repository( $repo_url, 'discovery' );
				foreach ( $result['plugins'] as $plugin ) {
					if ( ! isset( $known_json[ strtolower( $plugin['json_url'] ) ] ) ) {
						$candidates[ strtolower( $plugin['json_url'] ) ] = $plugin;
					}
				}
			}
		}
		$candidates = array_values( $candidates );
		usort( $candidates, static function ( array $a, array $b ): int { return strcasecmp( $a['name'], $b['name'] ); } );
		set_transient( self::TRANSIENT_DISCOVERY, $candidates, 30 * MINUTE_IN_SECONDS );
		$this->redirect_with_notice( 'success', count( $candidates ) . ' neue Plugin-Kandidat(en) gefunden.', [ 'kornsw_pg_discovery' => '1' ] );
	}

	private function github_user_repositories( string $owner ): array {
		$urls = [];
		for ( $page = 1; $page <= 10; $page++ ) {
			$api = sprintf( 'https://api.github.com/users/%s/repos?type=public&per_page=100&page=%d', rawurlencode( $owner ), $page );
			$response = wp_safe_remote_get( $api, [ 'timeout' => 15, 'headers' => $this->github_headers() ] );
			if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
				break;
			}
			$data = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( ! is_array( $data ) || empty( $data ) ) {
				break;
			}
			foreach ( $data as $repo ) {
				if ( is_array( $repo ) && ! empty( $repo['html_url'] ) && empty( $repo['archived'] ) ) {
					$urls[] = (string) $repo['html_url'];
				}
			}
			if ( count( $data ) < 100 ) {
				break;
			}
		}
		return array_values( array_unique( $urls ) );
	}

	public function handle_add_discovered(): void {
		$this->assert_can_install();
		check_admin_referer( 'kornsw_pg_add_discovered' );
		$urls = isset( $_POST['json_urls'] ) && is_array( $_POST['json_urls'] ) ? array_map( 'esc_url_raw', wp_unslash( $_POST['json_urls'] ) ) : [];
		$discovery = get_transient( self::TRANSIENT_DISCOVERY );
		$allowed = [];
		if ( is_array( $discovery ) ) {
			foreach ( $discovery as $candidate ) {
				$allowed[ (string) $candidate['json_url'] ] = true;
			}
		}
		$sources = $this->extra_sources();
		$existing = [];
		foreach ( $sources as $source ) {
			$existing[ strtolower( $source['url'] ) ] = true;
		}
		$added = 0;
		foreach ( $urls as $url ) {
			if ( ! isset( $allowed[ $url ] ) || isset( $existing[ strtolower( $url ) ] ) ) {
				continue;
			}
			$sources[] = [ 'type' => 'json', 'url' => $url ];
			$existing[ strtolower( $url ) ] = true;
			$added++;
		}
		$this->save_extra_sources( $sources );
		delete_transient( self::TRANSIENT_DISCOVERY );
		$this->redirect_with_notice( 'success', $added . ' Plugin-Quelle(n) hinzugefügt.' );
	}

	private function assert_can_install(): void {
		if ( ! current_user_can( 'install_plugins' ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'kornsw-plugingallery' ) );
		}
	}
}

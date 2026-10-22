# Architektur

## Aufbau

Das Plugin liegt im Ordner [`kornsw-plugingallery/`](../kornsw-plugingallery/) (= Slug, = Inhalt des Release-ZIPs). Klassen folgen PSR-4 im Namespace `KornSW\PluginGallery` unter `src/`; die Entry-Datei registriert nur einen Autoloader und startet `Plugin::boot()` auf `plugins_loaded` (nur im Admin-Bereich).

| Klasse | Aufgabe |
| --- | --- |
| `Plugin` | Kompositionswurzel, verdrahtet die Dienste und registriert die Hooks. |
| `Source` | Wert-Objekt einer Quelle (GitHub-Repo, WordPress.org-Slug, `update.json`-URL) inkl. Parser für Eingaben. |
| `SourceConfiguration` | Favoriten aus `config.php` und gespeicherte zusätzliche Quellen (Option `kornsw_pg_extra_sources`). |
| `GitHub\GitHubClient` | REST-API mit Token, ETag-Cache, Rate-Limit-Erkennung; Fallback über die GitHub-Webseite. |
| `GitHub\GitHubTokenStore` | Token aus `wp-config.php` oder verschlüsselt aus Option `kornsw_pg_github_token`. |
| `Security\SecretCipher` | AES-256-GCM mit Schlüssel aus den WordPress-Salts. |
| `Catalog\RepositoryScanner` | Plugins eines Repositories (`doc/*.update.json`), Cache je Repository über `pushed_at`. |
| `Catalog\UpdateJsonReader` | Lädt und normalisiert `*.update.json`. |
| `Catalog\WordPressOrgDirectory` | Plugin-Informationen über `plugins_api()`. |
| `Catalog\CatalogBuilder` | Baut den Katalog in drei Stufen (Favoriten, zusätzliche, automatisch gefundene) und die Suche nach weiteren Plugins. |
| `Installer\PluginInstaller` | Installation per `Plugin_Upgrader`, Core-Installer-/Aktivierungs-URLs, Installationsstatus. |
| `Admin\GalleryPage` | Rendert die Admin-Seite. |
| `Admin\AdminActions` | `admin-post.php`-Handler (Nonce + Berechtigung). |

## Katalogaufbau und GitHub-Zugriffe

1. Für jeden distinkten GitHub-Besitzer der Favoriten wird die Repository-Liste geholt (`/users/<owner>/repos`, bedingt per ETag – ein `304` zählt nicht gegen das Rate-Limit). Sie liefert je Repository `pushed_at`.
2. Favoriten und zusätzliche Quellen werden aufgelöst. Ein GitHub-Repository wird nur dann erneut gelesen (`/contents/doc` + `update.json`), wenn sich sein `pushed_at` seit dem letzten Scan geändert hat. Jedes Release pusht eine neue `update.json`, daher kann der Cache keine neue Version verdecken.
3. Alle übrigen `WP-*`-Repositories derselben Besitzer werden genauso gescannt und als „automatisch gefunden“ eingeordnet.
4. Der fertige Katalog liegt eine Stunde im Transient `kornsw_pg_catalog_v3`; „Galerie aktualisieren“ baut ihn sofort neu.

Bei erschöpftem Rate-Limit (vom letzten Response gemeldet) werden keine API-Anfragen gesendet, bis der Reset erreicht ist; stattdessen werden gecachte Daten verwendet. Fehler, `403`/`429` und `5xx` fallen ebenfalls auf gecachte Daten zurück. Gibt es keinen Cache, wird der `/doc`-Ordner über die GitHub-Webseite gelesen.

Details: [Entscheidung zur Rate-Limit-Strategie](decisions/decision-github-rate-limit-strategy.md).

## Datenhaltung

| Name | Art | Inhalt | Wegwerfbar |
| --- | --- | --- | --- |
| `kornsw_pg_extra_sources` | Option | zusätzliche Quellen (`type`, `url`) | nein |
| `kornsw_pg_github_token` | Option | verschlüsselter Token | nein |
| `kornsw_pg_repository_cache` | Option (nicht autoload) | Scan-Ergebnis je Repository mit `pushed_at` | ja |
| `kornsw_pg_catalog_v3` | Transient | fertiger Katalog (1 h) | ja |
| `kornsw_pg_gh_<md5>` | Transient | ETag + kompakte API-Antwort (7 Tage) | ja |
| `kornsw_pg_github_rate_limit` | Transient | zuletzt gemeldetes Kontingent | ja |
| `kornsw_pg_discovery_v3` | Transient | Treffer der Suche (30 min) | ja |

## Installation

- GitHub-/`update.json`-Plugins: `Plugin_Upgrader` mit `Automatic_Upgrader_Skin` (Download-URL aus der `update.json`).
- WordPress.org-Plugins: Link auf den Core-Installer, siehe [Entscheidung](decisions/decision-wordpress-org-install-via-core-installer.md).

## Release und Self-Update

Versionierung, Self-Update-Block, `doc/*.update.json` und GitHub-Release erzeugt die Release-Pipeline (`.kvu/`, `.github/workflows/release.yml`). Diese Artefakte werden nie von Hand geändert.

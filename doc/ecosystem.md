# Ökosystem

## Ausgehende Abhängigkeiten

| Abhängigkeit | Wofür |
| --- | --- |
| [WP-AutoUpdate-G2](https://github.com/KornSW/WP-AutoUpdate-G2) | Vorlage der Release-Pipeline (`.kvu/`, `.github/workflows/release.yml`); erzeugt Self-Update und `doc/<slug>.update.json` dieses Plugins. |
| Format `doc/<slug>.update.json` | Die Galerie liest genau die Dateien, die der KVU-Release-Flow in jedem Plugin-Repository erzeugt (`name`, `version`, `download_url`, `requires`, `requires_php`, `sections`). |
| GitHub REST API | Repository-Listen und `/doc`-Inhalte (`api.github.com`), Rohdateien über `raw.githubusercontent.com`. |
| WordPress.org Plugin API | `plugins_api()` für Favoriten und Quellen aus dem offiziellen Verzeichnis. |

## Eingehende Abhängigkeiten (Inhalte der Galerie)

Alle öffentlichen `KornSW/WP-*`-Plugin-Repositories mit KVU-Release-Flow, z. B. [WP-AuthTokenHandling](https://github.com/KornSW/WP-AuthTokenHandling). Die Favoritenliste steht in [`config.php`](../kornsw-plugingallery/config.php).

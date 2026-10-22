# KornSW Plugin Gallery

WordPress-Plugin, das unter **Plugins › Plugin Gallery (KornSW)** eine kuratierte Plugin-Galerie anzeigt und Plugins mit einem Klick installiert. Quellen sind GitHub-Repositories mit WordPress-kompatiblen `*.update.json`-Dateien (wie sie der KVU-Release-Flow erzeugt), direkte `*.update.json`-URLs und Plugins aus dem offiziellen WordPress.org-Verzeichnis.

## Motivation

Die eigenen Plugins liegen nicht im WordPress.org-Verzeichnis, sondern als GitHub-Releases. Die Galerie macht sie auf jeder Installation genauso bequem installierbar wie Plugins aus dem offiziellen Verzeichnis – und bündelt sie mit den bevorzugten Plugins von WordPress.org zu einer Favoritenliste.

## Funktionen

- **Favoriten** (mit Stern hervorgehoben) aus der versionierten [`config.php`](kornsw-plugingallery/config.php): GitHub-Repositories, WordPress.org-Plugins, `*.update.json`-URLs.
- **Automatisch gefundene Plugins:** alle weiteren `WP-*`-Repositories der GitHub-Besitzer aus den Favoriten, die ein veröffentlichtes Plugin enthalten – optisch etwas zurückgenommen.
- **Zusätzliche Quellen** pro Installation (GitHub-Repo, `*.update.json`-URL, WordPress.org-URL oder -Slug).
- **WordPress.org-Plugins** werden über den WordPress-eigenen Installer installiert, inklusive Detail-Dialog.
- **GitHub Personal Access Token** (verschlüsselt gespeichert oder per `wp-config.php`) gegen das Rate-Limit; dazu ETag- und Repository-Caching, sodass eine unveränderte Galerie nur eine API-Anfrage pro GitHub-Besitzer kostet.
- Installation beliebiger Plugin-ZIPs per URL, Suche nach weiteren Plugins in den übrigen Repositories der Favoriten-Besitzer.

## Dokumentation

- [Anforderungen](doc/requirements.md)
- [Architektur](doc/architecture.md)
- [Schnellstart](doc/quickstart.md)
- [Ökosystem](doc/ecosystem.md)
- [Changelog](doc/changelog.md)
- [Entscheidungen](doc/decisions/)
- [Arbeit: Backlog und technische Schulden](doc/work/)

## Lizenz

GPL-3.0, siehe [LICENSE](LICENSE).

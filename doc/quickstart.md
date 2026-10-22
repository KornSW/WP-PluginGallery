# Schnellstart

## Installation

1. ZIP des neuesten Releases von https://github.com/KornSW/WP-PluginGallery/releases herunterladen (`kornsw-plugingallery.zip`).
2. In WordPress unter **Plugins › Installieren › Plugin hochladen** installieren und aktivieren.
3. Die Galerie öffnen: **Plugins › Plugin Gallery (KornSW)**.

Updates des Plugins selbst erscheinen danach in der normalen WordPress-Update-Anzeige.

## GitHub-Token hinterlegen (empfohlen)

Ohne Token erlaubt GitHub nur 60 API-Anfragen pro Stunde und IP-Adresse; auf Shared Hosting teilen sich das oft mehrere Kunden.

1. Auf GitHub unter **Settings › Developer settings › Personal access tokens › Fine-grained tokens** einen Token erstellen: Repository access „Public repositories (read-only)“, keine weiteren Berechtigungen. Ein klassischer Token ohne Scopes funktioniert ebenfalls.
2. In der Galerie im Abschnitt **GitHub-Zugriff** einfügen und „Token prüfen und speichern“ klicken. Der Token wird geprüft und verschlüsselt gespeichert.

Alternativ in der `wp-config.php`:

```php
define('KORNSW_PG_GITHUB_TOKEN', 'github_pat_...');
```

Hinweis: Der gespeicherte Token ist mit den WordPress-Salts verschlüsselt. Werden die Salts geändert, muss er neu eingegeben werden.

## Favoriten pflegen (Entwickler)

Favoriten stehen in [`kornsw-plugingallery/config.php`](../kornsw-plugingallery/config.php) und werden mit einem Plugin-Update ausgeliefert:

```php
return [
  'https://github.com/KornSW/WP-AuthTokenHandling',   // GitHub-Repo mit doc/*.update.json
  'https://wordpress.org/plugins/wordfence/',         // WordPress.org-Plugin
  'https://example.org/doc/my-plugin.update.json',    // direkte update.json
];
```

Alle weiteren `WP-*`-Repositories der hier genannten GitHub-Besitzer erscheinen automatisch unter „Weitere Plugins“.

## Zusätzliche Quellen (pro Installation)

Im Abschnitt **Zusätzliche Galerie-Quelle** eine GitHub-Repository-URL, eine `*.update.json`-URL, eine WordPress.org-Plugin-URL oder einfach einen WordPress.org-Slug (z. B. `wordfence`) eintragen.

## Tests (Entwickler)

Die Domänenlogik lässt sich ohne WordPress testen:

```sh
php tests/run-tests.php
```

Die Tests verwenden kleine WordPress-Stubs (`tests/bootstrap.php`) und simulierte HTTP-Antworten; die Oberfläche selbst muss in WordPress geprüft werden.

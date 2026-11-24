# Entscheidung: Private Repositories über einen Token-Fallback

**Datum:** 2026-11-23

## Kontext

Plugins der Favoriten-Autoren sollen auch aus privaten GitHub-Repositories installierbar und aktualisierbar sein, sobald ein Personal Access Token hinterlegt ist. Der Self-Update-Code der Plugins (`self-update.php`) wird von der Release-Pipeline erzeugt und darf nicht von Hand geändert werden; er lädt `update.json` und Release-ZIP ohne Token. Bestehendes Verhalten für öffentliche Repositories darf sich nicht ändern.

## Entscheidung

1. Die Galerie registriert auf jeder Anfrage (auch WP-Cron) einen Filter `http_response`. Er ersetzt nur `404`-Antworten auf `raw.githubusercontent.com`- und `releases/download`-URLs von GitHub-Besitzern aus `config.php` durch dieselben Inhalte, geladen über die REST-API mit Token. Damit funktionieren Galerie, Installation und die automatischen Updates aller installierten Plugins, ohne `self-update.php` zu ändern.
2. Zusätzlich gibt es auf der Galerie-Seite „Jetzt aktualisieren“ (Neuinstallation des Release-ZIPs über das installierte Plugin) als manuellen Weg, falls das automatische Update nicht greift.

## Alternativen

- Token im Self-Update-Template: würde den Token an jedes Plugin verteilen und die Pipeline-Vorlage (`WP-AutoUpdate-G2`) ändern.
- Immer über die API laden (`pre_http_request`): kostet auch für öffentliche Repositories API-Kontingent und ändert bestehendes Verhalten.
- Header per `http_request_args` an `github.com`/`raw.githubusercontent.com` anhängen: der Token würde an weitere Hosts (und über Redirects an signierte Download-URLs) gesendet.

## Konsequenzen

- Private Repositories brauchen einen Token mit Lesezugriff (Fine-grained: „Contents: Read-only“ für die Repositories; klassisch: `repo`).
- Private `WP-*`-Repositories der Favoriten-Besitzer werden automatisch gefunden: `/users/<owner>/repos` listet nur öffentliche, daher wird mit Token zusätzlich `/user/repos` (alle für den Token sichtbaren Repositories) abgefragt und auf die Favoriten-Besitzer gefiltert. Das kostet eine weitere, per ETag bedingte Anfrage pro Galerie-Aufbau (Nachtrag 2026-11-24).
- Der Fallback vertraut auch den Besitzern gespeicherter zusätzlicher GitHub-Quellen und beim Hinzufügen dem Besitzer der neuen Quelle; vorher scheiterte das Hinzufügen privater Repositories fremder Besitzer.
- Jeder Update-Check eines privaten Plugins kostet einen erfolglosen Abruf plus API-Anfragen (innerhalb einer Anfrage gecacht).
- Ohne aktive Galerie (deaktiviert) entfällt der Fallback; die Updates privater Plugins sind dann nicht sichtbar.

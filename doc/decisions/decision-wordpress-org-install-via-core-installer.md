# Entscheidung: WordPress.org-Plugins über den Core-Installer installieren

**Datum:** 2026-10-22

## Kontext

Die Favoriten sollen um Plugins aus dem offiziellen WordPress.org-Verzeichnis erweitert werden, und zwar mit der Installationslogik von WordPress selbst.

## Entscheidung

- Angabe über WordPress.org-URL (`https://wordpress.org/plugins/<slug>/`, auch lokalisierte Subdomains) oder den bloßen Slug.
- Metadaten über die Core-Funktion `plugins_api('plugin_information', …)`.
- „Installieren“ verlinkt auf `update.php?action=install-plugin&plugin=<slug>` mit Core-Nonce – exakt der Ablauf von **Plugins › Installieren** (inkl. Dateisystem-Zugangsdaten, Fortschrittsanzeige, „Aktivieren“-Link).
- „Details“ öffnet den Core-Dialog (`plugin-install.php?tab=plugin-information`, Thickbox).

## Alternativen

- Download-Link per `Plugin_Upgrader` im Hintergrund installieren (wie bei GitHub-Plugins): funktioniert, umgeht aber die Core-Oberfläche (z. B. Abfrage von FTP-Zugangsdaten).

## Konsequenzen

Nach der Installation landet der Benutzer auf der Core-Ergebnisseite statt in der Galerie. Updates dieser Plugins laufen ganz normal über WordPress.org.

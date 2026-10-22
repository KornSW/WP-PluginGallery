# Entscheidung: Strategie gegen das GitHub-Rate-Limit

**Datum:** 2026-10-22

## Kontext

Die Galerie fragte bei jedem Aufbau pro Repository die GitHub-API ab. Anonym sind nur 60 Anfragen pro Stunde und IP erlaubt; auf Shared Hosting teilen sich mehrere Kunden eine IP. Schon nach kurzer Zeit meldete GitHub „rate limit exceeded“, und die Suche nach weiteren Plugins funktionierte kaum. Mit der automatischen Aufnahme aller `WP-*`-Repositories steigt die Zahl der Repositories zusätzlich.

## Entscheidung

1. **Personal Access Token** (optional): verschlüsselt in der Datenbank (AES-256-GCM, Schlüssel aus den WordPress-Salts) oder als Konstante `KORNSW_PG_GITHUB_TOKEN` in der `wp-config.php`; die Konstante hat Vorrang. Er wird nur an `api.github.com` gesendet und vor dem Speichern geprüft. Limit: 5.000 Anfragen pro Stunde.
2. **Repository-Cache über `pushed_at`:** Die (ohnehin benötigte) Repository-Liste je Besitzer liefert `pushed_at`. Ein Repository wird nur neu gelesen, wenn sich dieser Zeitstempel geändert hat. Da jedes Release eine neue `update.json` pusht, kann keine neue Version verdeckt werden.
3. **ETag-Cache:** API-Antworten werden in kompakter Form mit ETag gespeichert; bedingte Anfragen mit `304` zählen nicht gegen das Limit. Eine unveränderte Galerie kostet damit eine Anfrage pro Besitzer.
4. **Respekt vor dem Limit:** Meldet GitHub `remaining = 0`, wird bis zum Reset keine API-Anfrage gesendet; gecachte Daten werden angezeigt. Auch bei `403`/`429`/`5xx` und Netzwerkfehlern gewinnt der Cache.
5. **Web-Fallback** (bestehend): Ohne Cache wird der `/doc`-Ordner über die GitHub-Webseite gelesen.

## Alternativen

- Nur Token: löst das Problem für Installationen mit Token, aber nicht anonym; und die Last wächst mit jedem neuen Repository.
- GraphQL-API (alle Repositories in einer Anfrage): erfordert zwingend einen Token, also kein anonymer Betrieb.
- Längere Cache-Zeiten allein: würden neue Versionen verzögert anzeigen.

## Konsequenzen

- Neue Optionen/Transients (siehe [Architektur](../architecture.md#datenhaltung)); alle Caches sind wegwerfbar.
- Werden die WordPress-Salts geändert, ist der gespeicherte Token nicht mehr lesbar; die Oberfläche weist darauf hin.
- Der Web-Fallback bleibt fragil (HTML-Struktur von GitHub), wird aber nur noch selten gebraucht.

# Anforderungen

## Ziel

Eine Admin-Seite in WordPress, die eine kuratierte Auswahl an Plugins anzeigt und sie mit einem Klick installiert – unabhängig davon, ob ein Plugin als GitHub-Release (KVU-Release-Flow) oder im WordPress.org-Verzeichnis veröffentlicht ist.

## Zielgruppe

Administratoren von WordPress-Installationen, die die Plugins von KornSW (und die von KornSW empfohlenen Plugins) einsetzen.

## Projektsprache

**Deutsch** für Plugin-Beschreibung, alle UI-Texte, `README.md` und `/doc` (siehe [Entscheidung](decisions/decision-project-language-german.md)). Quellcode-Bezeichner, Code-Kommentare und AI-Skills sind Englisch.

## Funktionale Anforderungen

1. **Favoriten:** Die versionierte `config.php` listet die Favoriten. Unterstützt werden GitHub-Repository-URLs (alle `*.update.json` unter `/doc`), WordPress.org-Plugin-URLs und direkte `*.update.json`-URLs. Favoriten werden mit Stern hervorgehoben.
2. **Automatisch gefundene Plugins:** Für jeden (distinkten, Groß-/Kleinschreibung ignoriert) GitHub-Besitzer der Favoriten werden dessen Repositories gelistet. Jedes nicht archivierte Repository, dessen Name mit `WP-` beginnt und das ein veröffentlichtes Plugin enthält, erscheint automatisch – optisch leicht zurückgenommen, aber nicht als „Beta“ wirkend und voll funktionsfähig. Repositories ohne Plugin werden still übergangen.
3. **Zusätzliche Quellen:** Administratoren können pro Installation weitere Quellen speichern: GitHub-Repository, `*.update.json`-URL, WordPress.org-Plugin-URL oder -Slug. Jede Quelle wird vor dem Speichern geprüft.
4. **Eindeutigkeit:** Jedes Plugin erscheint nur einmal; Favorit vor zusätzlicher Quelle vor automatischem Fund.
5. **Installation:**
   - GitHub-/`update.json`-Plugins: Installation des Release-ZIPs über den WordPress-`Plugin_Upgrader`.
   - WordPress.org-Plugins: über den WordPress-eigenen Installer (`update.php?action=install-plugin`), Details über den Core-Dialog.
   - Beliebige Plugin-ZIPs per URL (nicht als Quelle gespeichert).
   - Installierte Plugins zeigen Version, „Aktivieren“ (falls inaktiv) und einen Hinweis auf verfügbare Updates.
6. **GitHub-Token:** Ein Personal Access Token kann hinterlegt werden (verschlüsselt in der Datenbank oder als Konstante `KORNSW_PG_GITHUB_TOKEN` in der `wp-config.php`). Er wird vor dem Speichern gegen GitHub geprüft und ausschließlich an `api.github.com` gesendet. Ein leeres Eingabefeld behält den gespeicherten Token; Entfernen ist eine eigene Aktion.
7. **Rate-Limit-Schonung:** Eine unveränderte Galerie darf beim Aktualisieren nur eine API-Anfrage pro GitHub-Besitzer kosten. Bei erschöpftem Limit wird der letzte bekannte Stand angezeigt und GitHub bis zum Reset nicht weiter abgefragt. Das zuletzt gemeldete Kontingent ist auf der Seite sichtbar.
8. **Suche nach weiteren Plugins:** Auf Knopfdruck werden die übrigen (nicht `WP-*`) Repositories der Favoriten-Besitzer durchsucht; Funde können als zusätzliche Quellen übernommen werden.

## Nicht-funktionale Anforderungen

- Nur im Admin-Bereich aktiv; Galerie und Installation erfordern `install_plugins`, Token-Verwaltung `manage_options`.
- Jede zustandsändernde Aktion prüft Nonce und Berechtigung.
- Läuft ohne Composer; Mindestversionen laut Plugin-Header (WordPress 6.0, PHP 7.4).
- Deinstallation behält die Daten (Optionen bleiben erhalten).

## Nicht-Ziele

- Keine eigene Update-Logik für installierte Plugins (das übernimmt der Self-Update-Mechanismus der Plugins bzw. WordPress selbst).
- Keine privaten GitHub-Repositories (der Self-Update-Mechanismus der Plugins lädt ohne Token).
- Keine Galerie-Anzeige im Frontend.

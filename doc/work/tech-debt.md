# Technische Schulden

| Thema | Abweichung | Warum (noch) nicht behoben | Vorschlag |
| --- | --- | --- | --- |
| Mindestversionen | `Requires PHP: 7.4`, `Requires at least: 6.0` liegen unter den Vorgaben für neue Plugins. | Anheben ist eine Kompatibilitätsänderung für bestehende Installationen und muss bewusst entschieden werden. | Bei Bedarf gemeinsam mit einem Feature-Release anheben (dann PHP-8-Sprachmittel nutzbar). |
| Übersetzbarkeit | UI-Texte sind nicht in `__()` / `esc_html__()` gekapselt. | In der Landschaft noch offen (siehe Plugin-Richtlinie). | Entscheidung abwarten. |
| Web-Fallback | Das Lesen des `/doc`-Ordners über die GitHub-Webseite hängt von deren HTML-Struktur ab. | Einziger Weg ohne API; durch Token und Caches nur noch selten nötig. | Beobachten; ggf. entfernen, wenn Token Standard ist. |
| Entry-Datei-Formatierung | Der Self-Update-Block und die `ABSPATH`-Prüfung in der Entry-Datei behalten die WordPress-Formatierung (Tabs, Leerzeichen in Klammern). | Wird von der Release-Pipeline verwaltet bzw. gesucht. | Belassen. |
| Tests | Nur Domänenlogik standalone getestet; keine automatisierten Tests der Oberfläche in WordPress. | Keine WordPress-Testumgebung im Repository. | Bei Bedarf wp-env/PHPUnit-Integrationstests ergänzen. |

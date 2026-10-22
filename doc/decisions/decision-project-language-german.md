# Entscheidung: Projektsprache Deutsch

**Datum:** 2026-10-22

## Kontext

Die Richtlinie für WordPress-Plugins verlangt eine durchgängige Projektsprache für Plugin-Beschreibung, UI-Texte und Dokumentation. Das Plugin existierte bereits mit deutscher Oberfläche und deutscher Beschreibung.

## Entscheidung

Projektsprache ist **Deutsch** (UI, Plugin-Beschreibung, `README.md`, `/doc`). Code-Bezeichner, Code-Kommentare und AI-Skills bleiben Englisch.

## Alternativen

- Englisch: hätte eine komplette Übersetzung der Oberfläche erfordert, ohne dass eine englischsprachige Zielgruppe bekannt ist.

## Konsequenzen

Neue Texte werden auf Deutsch geschrieben. Eine Übersetzbarkeit über `__()` mit Text-Domain ist nicht umgesetzt (siehe [technische Schulden](../work/tech-debt.md)).

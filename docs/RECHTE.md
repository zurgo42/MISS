# Funktionen und Rechte im Sitzungstool

Die Mitgliederverwaltung führt die **Funktionen mit Zeitraum** (`md_member_functions`) und gleicht daraus die Rechte der
Sitzungsverwaltung ab (`svmembers.aktiv`, `svmembers.funktion`, `svmembers.role`).

## Betriebsart

Nur im **Standalone-Betrieb** der Sitzungsverwaltung (`svmembers` ist führend). Im Adapterbetrieb (`MEMBER_SOURCE = 'berechtigte'`,
Tabelle `berechtigte` führend) bleibt die Mitgliederverwaltung gesperrt: `MD_SV_MODE` muss `'standalone'` sein, sonst antwortet jede Seite mit 503.

**Folge für die Sitzungsverwaltung:** Im Adminbereich der Sitzungsverwaltung (`process_admin.php`) sollten `aktiv` und `funktion` dann nicht mehr
von Hand geändert werden. Der nächste Abgleich würde sie wieder überschreiben (nur bei Mitgliedern mit Funktionszuordnung).

## Regeln

| Regel | Umsetzung |
|---|---|
| Mehrere Funktionen gleichzeitig | Alle sind erfasst und stehen im Dashboard und in der Dokumentation. |
| Höchstes Level gewinnt | Von den am heutigen Tag gültigen Funktionen bestimmt die mit dem höchsten Level `aktiv` und `funktion`. Bei Gleichstand die zuerst angelegte Funktionsart. |
| Level | `md_member_functions.aktiv_level`, sonst `md_functions.sv_aktiv_level` (Standard laut `PERMISSIONS.md`). Individuelle Abweichungen (RL 14/15/17, VA 17) gehören an die Zuordnung. |
| Zeiträume | Beginn in der Zukunft wirkt erst ab dem Tag. Ende ist inklusive; „beenden“ in der Oberfläche setzt „gestern“, damit die Rechte sofort entfallen. |
| Keine gültige Funktion mehr | `funktion` wird leer, `aktiv` = `md_settings.default_aktiv` (Voreinstellung 0). Ehemalige Funktionen (`Vx`, `Rx`, `Xx`) haben Level 0. |
| Rolle | Wie `mapRole()` in `adapters/MemberAdapter.php`: `aktiv` 19 → `vorstand`, 18 → `gf`, sonst nach Funktion (Vo/FVo/FVv, VA/SV/MB/Ka/Orga, RL/PL/JT/TM, sonst `mitglied`). |
| `is_admin`, `is_confidential` | Werden **nie** verändert, bleiben von Hand gepflegt. |
| Nicht verwaltete Mitglieder | Ohne jede Funktionszuordnung in `md_member_functions` bleibt `svmembers` unangetastet (Einführung Schritt für Schritt möglich). |
| Ressortleitung | Ist eine Funktion (`RL`). Das Ressort steht in `md_member_functions.ressort_id` und verweist lose auf `svressorts.ID` (die Ressortliste der Sitzungsverwaltung). Es steuert hier keine Rechte. |
| Protokoll | Jede Änderung steht im `md_changelog` (`rights`), mit Quelle selbst/admin/import/system. |

## Wann wird abgeglichen?

1. Sofort beim Zuordnen oder Beenden einer Funktion (Oberfläche) und beim CSV-Import mit Funktionen.
2. Täglich per Cronjob, damit Beginn und Ende von Ämtern von selbst wirken:

```
10 0 * * *  php /pfad/zur/mitgliederverwaltung/tools/sync_rights.php
```

`php tools/sync_rights.php --dry-run` zeigt nur, was sich ändern würde.

## Prüfung

`php tools/test_rights.php` (nach `php tools/demo_setup.php`) prüft Höchstes-Level-Regel, Zeiträume, nicht verwaltete Mitglieder, unveränderte Flags
und dass der Demo-Stand mit den Werten der Sitzungsverwaltung übereinstimmt. Der Test läuft in einer Transaktion und verändert die Demo nicht.

## Offen

- Die Sitzungsverwaltung kann `aktiv`/`funktion` im Admin schreibgeschützt anzeigen, wenn die Mitgliederverwaltung aktiv ist.
- Ob `default_aktiv` = 0 für Mitglieder ohne Funktion passt, bitte anhand des Ist-Zustands in der Sitzungsverwaltung prüfen.

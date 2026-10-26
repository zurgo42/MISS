<?php
declare(strict_types=1);

/**
 * Veranstaltungen: Jedes Mitglied (mit Zustimmung zu den Nutzungsbedingungen) kann Veranstaltungen anlegen und sich anmelden.
 *  - Veranstalter und Verwaltung (Recht "events") sehen die vollständige Teilnehmerliste.
 *  - Angemeldete sehen nur die Mitglieder, die ihre Sichtbarkeit in der Liste ausdrücklich erlaubt haben (Standard: nein) – und die Zahl der übrigen.
 *  - Bei Teilnahmegebühr entsteht je Anmeldung eine Forderung (Art Eintritt, Quelle "event", wird nicht per Lastschrift eingezogen).
 *  - Ist die Veranstaltung voll, kommt man auf die Warteliste; wird ein Platz frei, rückt der Nächste automatisch nach.
 */

const MD_EVENT_REG_STATUS = ['registered' => 'angemeldet', 'waitlist' => 'Warteliste', 'cancelled' => 'abgemeldet'];

/** "TT.MM.JJJJ HH:MM" oder "JJJJ-MM-TTTHH:MM" (Eingabefeld datetime-local) → "JJJJ-MM-TT HH:MM:SS" oder null. */
function md_event_parse_dt(?string $s): ?string
{
    $s = trim((string)$s);
    if ($s === '') return null;
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})/', $s, $m)) [$y, $mo, $d, $h, $mi] = [$m[1], $m[2], $m[3], $m[4], $m[5]];
    elseif (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})\s+(\d{1,2}):(\d{2})$/', $s, $m)) [$d, $mo, $y, $h, $mi] = [$m[1], $m[2], $m[3], $m[4], $m[5]];
    else return null;
    if (!checkdate((int)$mo, (int)$d, (int)$y) || (int)$h > 23 || (int)$mi > 59) return null;
    return sprintf('%04d-%02d-%02d %02d:%02d:00', $y, $mo, $d, $h, $mi);
}

function md_event_dt(?string $dt): string
{
    return $dt ? date('d.m.Y H:i', strtotime($dt)) . ' Uhr' : '';
}

function md_event_get(int $id): ?array
{
    return md_one('SELECT e.*, s.first_name ofn, s.last_name oln FROM md_events e JOIN svmembers s ON s.member_id = e.organizer_id WHERE e.event_id = ?', [$id]) ?: null;
}

/** Darf der Benutzer diese Veranstaltung verwalten (ändern, absagen, volle Teilnehmerliste)? */
function md_event_can_manage(array $event, array $actor): bool
{
    return (int)$event['organizer_id'] === (int)$actor['member_id'] || md_can($actor, 'events', 'edit');
}

function md_event_counts(int $id): array
{
    $c = ['registered' => 0, 'waitlist' => 0];
    foreach (md_q("SELECT status, COUNT(*) n FROM md_event_regs WHERE event_id = ? AND status IN ('registered','waitlist') GROUP BY status", [$id]) as $r) $c[$r['status']] = (int)$r['n'];
    return $c;
}

/** Freie Plätze (null = unbegrenzt). */
function md_event_free(array $event): ?int
{
    if ($event['capacity'] === null || $event['capacity'] === '') return null;
    return max(0, (int)$event['capacity'] - md_event_counts((int)$event['event_id'])['registered']);
}

/** Ist eine Anmeldung noch möglich? Gibt '' oder den Grund zurück. */
function md_event_closed_reason(array $event, ?string $now = null): string
{
    $now = $now ?? date('Y-m-d H:i:s');
    if ($event['status'] === 'cancelled') return 'Die Veranstaltung ist abgesagt.';
    if ($event['starts_at'] <= $now) return 'Die Veranstaltung hat schon begonnen.';
    if ($event['reg_deadline'] && $event['reg_deadline'] < $now) return 'Der Anmeldeschluss ist vorbei.';
    return '';
}

/** Legt eine Veranstaltung an oder ändert sie. Gibt [event_id|null, Fehlertext] zurück. */
function md_event_save(?int $id, array $d, int $actor, ?string $now = null): array
{
    $now = $now ?? date('Y-m-d H:i:s');
    $title = trim((string)($d['title'] ?? ''));
    if ($title === '' || mb_strlen($title) > 150) return [null, 'Titel fehlt oder ist zu lang (höchstens 150 Zeichen).'];
    $start = md_event_parse_dt($d['starts_at'] ?? '');
    if (!$start) return [null, 'Beginn ungültig (TT.MM.JJJJ HH:MM).'];
    $end = md_event_parse_dt($d['ends_at'] ?? '');
    if (trim((string)($d['ends_at'] ?? '')) !== '' && !$end) return [null, 'Ende ungültig (TT.MM.JJJJ HH:MM).'];
    if ($end && $end < $start) return [null, 'Das Ende liegt vor dem Beginn.'];
    $deadline = md_event_parse_dt($d['reg_deadline'] ?? '');
    if (trim((string)($d['reg_deadline'] ?? '')) !== '' && !$deadline) return [null, 'Anmeldeschluss ungültig (TT.MM.JJJJ HH:MM).'];
    if ($deadline && $deadline > $start) return [null, 'Der Anmeldeschluss liegt nach dem Beginn.'];
    $capRaw = trim((string)($d['capacity'] ?? ''));
    if ($capRaw !== '' && (!ctype_digit($capRaw) || (int)$capRaw < 1 || (int)$capRaw > 100000)) return [null, 'Teilnehmerzahl: ganze Zahl ab 1 oder leer für unbegrenzt.'];
    $cap = $capRaw === '' ? null : (int)$capRaw;
    $priceRaw = trim((string)($d['price'] ?? ''));
    $price = $priceRaw === '' ? 0 : md_parse_amount($priceRaw);
    if ($price === null || $price < 0) return [null, 'Teilnahmegebühr ungültig (z. B. 12,50; leer für kostenlos).'];
    $old = $id ? md_event_get($id) : null;
    if ($id && !$old) return [null, 'Veranstaltung nicht gefunden.'];
    if (!$id && $start <= $now) return [null, 'Der Beginn muss in der Zukunft liegen.'];
    if ($old) {
        if ($old['status'] === 'cancelled') return [null, 'Eine abgesagte Veranstaltung kann nicht mehr geändert werden.'];
        $n = md_event_counts($id);
        if ((int)$old['price_cents'] !== $price && $n['registered'] + $n['waitlist'] > 0) return [null, 'Die Teilnahmegebühr lässt sich nicht mehr ändern, weil es schon Anmeldungen gibt.'];
        if ($cap !== null && $cap < $n['registered']) return [null, "Es sind schon {$n['registered']} Personen angemeldet; die Teilnehmerzahl darf nicht darunter liegen."];
    }
    $vals = [$title, trim((string)($d['description'] ?? '')) ?: null, trim((string)($d['location'] ?? '')) ?: null, $start, $end, $deadline, $cap, $price];
    $pdo = md_db();
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        if ($old) {
            md_exec('UPDATE md_events SET title = ?, description = ?, location = ?, starts_at = ?, ends_at = ?, reg_deadline = ?, capacity = ?, price_cents = ?, updated_at = ? WHERE event_id = ?', array_merge($vals, [$now, $id]));
            md_log_change((int)$old['organizer_id'], 'event', $old['title'], 'Veranstaltung geändert', $actor, $actor === (int)$old['organizer_id'] ? 'self' : 'admin');
            md_event_promote($id, $actor);   // mehr Plätze → Warteliste nachrücken lassen
        } else {
            md_exec('INSERT INTO md_events (title, description, location, starts_at, ends_at, reg_deadline, capacity, price_cents, organizer_id, status, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)', array_merge($vals, [$actor, 'open', $now]));
            $id = (int)$pdo->lastInsertId();
            md_log_change($actor, 'event', $title, 'Veranstaltung angelegt am ' . md_event_dt($start), $actor, 'self');
        }
        if ($own) $pdo->commit();
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        return [null, $e->getMessage()];
    }
    return [$id, ''];
}

function md_event_claim(array $event, int $member, int $regId, int $actor): ?int
{
    if ((int)$event['price_cents'] <= 0) return null;
    $due = $event['reg_deadline'] ? substr($event['reg_deadline'], 0, 10) : substr($event['starts_at'], 0, 10);
    [$eid, $err] = md_ledger_add(['member_id' => $member, 'kind' => 'eintritt', 'amount_cents' => (int)$event['price_cents'], 'entry_date' => date('d.m.Y'), 'due_date' => md_date(max($due, date('Y-m-d'))),
        'description' => 'Teilnahme ' . $event['title'] . ' (' . date('d.m.Y', strtotime($event['starts_at'])) . ')', 'reference' => 'EV' . $event['event_id'] . '-' . $regId], $actor, 'event');
    if (!$eid) throw new RuntimeException($err);
    return $eid;
}

/** Anmeldung (oder erneute Anmeldung). Gibt [Status 'registered'|'waitlist'|null, Fehlertext] zurück. */
function md_event_register(int $eventId, int $member, bool $show, ?string $note, int $actor, ?string $now = null): array
{
    $ev = md_event_get($eventId);
    if (!$ev) return [null, 'Veranstaltung nicht gefunden.'];
    if ($why = md_event_closed_reason($ev, $now)) return [null, $why];
    $note = trim((string)$note) === '' ? null : mb_substr(trim((string)$note), 0, 255);
    $pdo = md_db();
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        $reg = md_one('SELECT * FROM md_event_regs WHERE event_id = ? AND member_id = ?', [$eventId, $member]);
        if ($reg && $reg['status'] !== 'cancelled') throw new RuntimeException('Du bist schon angemeldet' . ($reg['status'] === 'waitlist' ? ' (Warteliste).' : '.'));
        $free = md_event_free($ev);
        $status = ($free === null || $free > 0) ? 'registered' : 'waitlist';
        if ($reg) {
            md_exec('UPDATE md_event_regs SET status = ?, show_in_list = ?, note = ?, entry_id = NULL, status_at = ? WHERE reg_id = ?', [$status, $show ? 1 : 0, $note, md_now(), $reg['reg_id']]);
            $regId = (int)$reg['reg_id'];
        } else {
            md_exec('INSERT INTO md_event_regs (event_id, member_id, status, show_in_list, note, created_at, status_at) VALUES (?,?,?,?,?,?,?)', [$eventId, $member, $status, $show ? 1 : 0, $note, md_now(), md_now()]);
            $regId = (int)$pdo->lastInsertId();
        }
        if ($status === 'registered' && ($eid = md_event_claim($ev, $member, $regId, $actor))) md_exec('UPDATE md_event_regs SET entry_id = ? WHERE reg_id = ?', [$eid, $regId]);
        md_log_change($member, 'event', $ev['title'], $status === 'registered' ? 'angemeldet' : 'auf die Warteliste gesetzt', $actor, $actor === $member ? 'self' : 'admin');
        if ($own) $pdo->commit();
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        return [null, $e->getMessage()];
    }
    return [$status, ''];
}

/** Abmeldung. Offene Forderung wird storniert; bezahlte bleibt (Erstattung klärt der Veranstalter mit der Kasse). Gibt Fehlertext oder '' zurück. */
function md_event_unregister(int $eventId, int $member, int $actor, ?string $now = null): string
{
    $ev = md_event_get($eventId);
    $reg = md_one('SELECT * FROM md_event_regs WHERE event_id = ? AND member_id = ?', [$eventId, $member]);
    if (!$ev || !$reg || $reg['status'] === 'cancelled') return 'Keine Anmeldung gefunden.';
    if ($ev['starts_at'] <= ($now ?? date('Y-m-d H:i:s'))) return 'Die Veranstaltung hat schon begonnen.';
    $pdo = md_db();
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        md_exec("UPDATE md_event_regs SET status = 'cancelled', status_at = ? WHERE reg_id = ?", [md_now(), $reg['reg_id']]);
        $paid = false;
        if ($reg['entry_id']) {
            $e = md_one('SELECT status FROM md_ledger WHERE entry_id = ?', [$reg['entry_id']]);
            if ($e && $e['status'] === 'open') md_ledger_cancel((int)$reg['entry_id'], 'Abmeldung von ' . $ev['title'], $actor);
            elseif ($e && $e['status'] === 'paid') $paid = true;
        }
        md_log_change($member, 'event', $ev['title'], 'abgemeldet' . ($paid ? ' (Teilnahmegebühr war schon bezahlt: Erstattung mit dem Veranstalter klären)' : ''), $actor, $actor === $member ? 'self' : 'admin');
        if ($reg['status'] === 'registered') md_event_promote($eventId, $actor);
        if ($own) $pdo->commit();
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        return $e->getMessage();
    }
    return '';
}

/** Lässt Mitglieder von der Warteliste nachrücken, solange Plätze frei sind (der Reihe nach). Gibt die Zahl der Nachrücker zurück. */
function md_event_promote(int $eventId, int $actor): int
{
    $ev = md_event_get($eventId);
    if (!$ev || $ev['status'] !== 'open') return 0;
    $n = 0;
    foreach (md_q("SELECT * FROM md_event_regs WHERE event_id = ? AND status = 'waitlist' ORDER BY reg_id", [$eventId]) as $r) {
        $free = md_event_free($ev);
        if ($free !== null && $free <= 0) break;
        $eid = md_event_claim($ev, (int)$r['member_id'], (int)$r['reg_id'], $actor);
        md_exec("UPDATE md_event_regs SET status = 'registered', entry_id = ?, status_at = ? WHERE reg_id = ?", [$eid, md_now(), $r['reg_id']]);
        md_log_change((int)$r['member_id'], 'event', $ev['title'], 'von der Warteliste nachgerückt', $actor, 'admin');
        if ($to = md_mail_address_of((int)$r['member_id'])) {
            md_mail_now((int)$r['member_id'], $to, 'Platz frei: ' . $ev['title'], "Gute Nachricht: Für „{$ev['title']}“ am " . md_event_dt($ev['starts_at']) . " ist ein Platz frei geworden, du bist von der Warteliste nachgerückt." . ((int)$ev['price_cents'] > 0 ? "\nDie Teilnahmegebühr von " . md_money((int)$ev['price_cents']) . ' findest du in deinem Dashboard unter Finanzen.' : '') . "\nWenn du nicht teilnehmen kannst, melde dich bitte in MISS ab.\n" . md_mail_footer(), 'other', 'EV' . $eventId, $actor);
        }
        $n++;
    }
    return $n;
}

/** Sichtbarkeit in der Teilnehmerliste ändern (nur eigene Anmeldung). */
function md_event_set_show(int $eventId, int $member, bool $show, int $actor): string
{
    $reg = md_one('SELECT * FROM md_event_regs WHERE event_id = ? AND member_id = ?', [$eventId, $member]);
    if (!$reg || $reg['status'] === 'cancelled') return 'Keine Anmeldung gefunden.';
    if ((int)$reg['show_in_list'] === (int)$show) return '';
    md_exec('UPDATE md_event_regs SET show_in_list = ? WHERE reg_id = ?', [$show ? 1 : 0, $reg['reg_id']]);
    md_log_change($member, 'event', md_event_get($eventId)['title'] ?? '', $show ? 'sichtbar in der Teilnehmerliste' : 'nicht mehr sichtbar in der Teilnehmerliste', $actor, 'self');
    return '';
}

/** Absage: offene Forderungen werden storniert, Angemeldete per E-Mail benachrichtigt. */
function md_event_cancel(int $eventId, string $reason, int $actor): string
{
    $ev = md_event_get($eventId);
    if (!$ev || $ev['status'] !== 'open') return 'Veranstaltung nicht gefunden oder schon abgesagt.';
    if (trim($reason) === '') return 'Bitte einen Grund angeben.';
    $pdo = md_db();
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        md_exec("UPDATE md_events SET status = 'cancelled', cancel_reason = ?, updated_at = ? WHERE event_id = ?", [mb_substr(trim($reason), 0, 255), md_now(), $eventId]);
        foreach (md_q("SELECT * FROM md_event_regs WHERE event_id = ? AND status IN ('registered','waitlist')", [$eventId]) as $r) {
            $paid = false;
            if ($r['entry_id']) {
                $e = md_one('SELECT status FROM md_ledger WHERE entry_id = ?', [$r['entry_id']]);
                if ($e && $e['status'] === 'open') md_ledger_cancel((int)$r['entry_id'], 'Veranstaltung abgesagt: ' . $ev['title'], $actor);
                elseif ($e && $e['status'] === 'paid') $paid = true;
            }
            md_log_change((int)$r['member_id'], 'event', $ev['title'], 'Veranstaltung abgesagt: ' . trim($reason) . ($paid ? ' (bezahlte Teilnahmegebühr muss erstattet werden)' : ''), $actor, 'admin');
            if ($to = md_mail_address_of((int)$r['member_id'])) {
                [$mid] = md_mail_queue((int)$r['member_id'], $to, 'Abgesagt: ' . $ev['title'], "Leider muss „{$ev['title']}“ am " . md_event_dt($ev['starts_at']) . " ausfallen.\nGrund: " . trim($reason) . ($paid ? "\nDeine bezahlte Teilnahmegebühr wird erstattet; der Veranstalter meldet sich dazu." : '') . "\n" . md_mail_footer(), 'other', 'EV' . $eventId, $actor);
                if ($mid) md_mail_send((int)$mid);
            }
        }
        if ($own) $pdo->commit();
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        return $e->getMessage();
    }
    return '';
}

/**
 * Teilnehmerliste für einen Betrachter. $full = Veranstalter/Verwaltung (alle, mit Warteliste und Anmerkung).
 * Sonst nur Angemeldete, die ihre Sichtbarkeit erlaubt haben, plus die eigene Zeile; 'hidden' zählt die übrigen Angemeldeten.
 */
function md_event_participants(int $eventId, int $viewer, bool $full): array
{
    $rows = md_q("SELECT r.*, s.first_name, s.last_name, s.membership_number FROM md_event_regs r JOIN svmembers s ON s.member_id = r.member_id WHERE r.event_id = ? AND r.status IN ('registered','waitlist') ORDER BY (r.status = 'registered') DESC, r.reg_id", [$eventId]);
    if ($full) return ['rows' => $rows, 'hidden' => 0];
    if (!md_event_my_reg($eventId, $viewer)) return ['rows' => [], 'hidden' => count(array_filter($rows, fn($r) => $r['status'] === 'registered')), 'restricted' => true];   // wer nicht selbst angemeldet ist, sieht keine Namen
    $out = [];
    $hidden = 0;
    foreach ($rows as $r) {
        $mine = (int)$r['member_id'] === $viewer;
        if ($r['status'] !== 'registered' && !$mine) continue;
        if ($mine || (int)$r['show_in_list'] === 1) { $out[] = ['member_id' => $r['member_id'], 'first_name' => $r['first_name'], 'last_name' => $r['last_name'], 'status' => $r['status'], 'show_in_list' => $r['show_in_list'], 'mine' => $mine]; }
        else $hidden++;
    }
    return ['rows' => $out, 'hidden' => $hidden];
}

/** Ist der Betrachter selbst angemeldet (oder auf der Warteliste)? */
function md_event_my_reg(int $eventId, int $member): ?array
{
    $r = md_one("SELECT * FROM md_event_regs WHERE event_id = ? AND member_id = ? AND status IN ('registered','waitlist')", [$eventId, $member]);
    return $r ?: null;
}

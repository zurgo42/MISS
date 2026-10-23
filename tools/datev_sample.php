<?php
/**
 * datev_sample.php – erzeugt eine Beispieldatei im DATEV-Format (Buchungsstapel, Windows-1252) zum Ausprobieren des Imports.
 * Aufruf: php tools/datev_sample.php [Ausgabedatei]   (Standard: storage/datev_beispiel.csv; nach demo_setup.php bzw. demo_load.php)
 * Enthält: Zahlung einer offenen Rechnung, eine Zahlung ohne Rechnung (Spende), eine Erstattung an ein Mitglied, eine Zeile mit unbekanntem Konto.
 */
if (PHP_SAPI !== 'cli') exit;
require_once dirname(__DIR__) . '/src/bootstrap.php';
if (!MD_DEMO_MODE) exit("nur mit Demo-Daten\n");
$out = $argv[1] ?? MD_STORAGE_DIR . '/datev_beispiel.csv';
$y = (int)date('Y');
$d = fn(int $days) => date('dm', strtotime("-$days days"));
$open = md_q("SELECT l.*, s.membership_number FROM md_ledger l JOIN svmembers s ON s.member_id = l.member_id WHERE l.status = 'open' AND l.kind = 'beitrag' AND l.direction = 'to_club' ORDER BY l.entry_id LIMIT 1")[0] ?? null;
$acct = fn(string $nr) => 10000 + (int)substr($nr, -4);
$fmt = fn(int $c) => number_format($c / 100, 2, ',', '');
$from = date('Ymd', strtotime("$y-01-01")); $to = date('Ymd', strtotime("$y-12-31"));
$lines = [];
$lines[] = '"EXTF";510;21;"Buchungsstapel";7;' . date('YmdHis') . '000;;"RE";"";"";29098;55003;' . $from . ';4;' . $from . ';' . $to . ';"Beispiel Verein ' . $y . '";"SO";1;0;0;"EUR";;"";;;"03";;;"";""';
$lines[] = 'Umsatz (ohne Soll/Haben-Kz);Soll/Haben-Kennzeichen;WKZ Umsatz;Kurs;Basis-Umsatz;WKZ Basis-Umsatz;Konto;Gegenkonto (ohne BU-Schlüssel);BU-Schlüssel;Belegdatum;Belegfeld 1;Belegfeld 2;Skonto;Buchungstext';
if ($open) $lines[] = $fmt((int)$open['amount_cents']) . ';"H";"EUR";;;;' . $acct($open['membership_number']) . ';1200;;' . $d(3) . ';"' . $open['reference'] . '";;;"Zahlung Mitgliedsbeitrag ' . $open['reference'] . '"';
$lines[] = '50,00;"H";"EUR";;;;10002;1200;;' . $d(2) . ';"";;;"Spende Sommerfest"';
$lines[] = '30,00;"H";"EUR";;;;10002;8200;;' . $d(2) . ';"";;;"Spende (Kasse)"';
$lines[] = '125,00;"S";"EUR";;;;70007;1200;;' . $d(1) . ';"K1";;;"Erstattung Kursmaterial"';
$lines[] = '75,00;"H";"EUR";;;;19999;1200;;' . $d(1) . ';"";;;"Zahlung unbekanntes Konto"';
$lines[] = '1200,00;"S";"EUR";;;;4100;1200;;' . $d(1) . ';"";;;"Sachkonto ohne Mitglied"';
file_put_contents($out, iconv('UTF-8', 'Windows-1252//TRANSLIT', implode("\r\n", $lines) . "\r\n"));
echo "Beispieldatei geschrieben: $out\n";

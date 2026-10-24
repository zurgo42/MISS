// Erzeugt die Demo-Bilder in assets/demo/ (einmalig; die fertigen SVG-Dateien liegen im Repository).
// Aufruf: npm i @dicebear/core @dicebear/collection && node tools/demo_images/generate.mjs
// Porträts: DiceBear-Stil "Lorelei" von Lisa Wischofsky, CC0 1.0 (https://www.figma.com/community/file/1198749693280469639).
// Gruppenbild und Landschaften: eigene einfache Vektorgrafiken, ohne Rechte Dritter.
import { createAvatar } from '@dicebear/core';
import { lorelei } from '@dicebear/collection';
import { mkdirSync, writeFileSync } from 'node:fs';
const out = new URL('../../assets/demo/', import.meta.url).pathname;
mkdirSync(out, { recursive: true });
const people = ['Max', 'Erika', 'Lukas', 'Julia', 'Thomas', 'Sabine', 'Hans', 'Anna', 'Petra', 'Karl', 'Mia', 'Rolf'];
const bg = ['b6e3f4', 'c0aede', 'd1d4f9', 'ffd5dc', 'ffdfbf', 'c1f0d0', 'fbe6a2', 'f3c6e0', 'cde7be', 'b9d7ea', 'f9d5bb', 'd9d9d9'];
const av = (i, size) => createAvatar(lorelei, { seed: people[i] + '-' + i, size, backgroundColor: [bg[i]], radius: 0 }).toString();
for (let i = 0; i < 12; i++) writeFileSync(out + `portrait_${i + 1}.svg`, av(i, 400));
// Porträts der weiteren Demo-Mitglieder 13–52
for (let n = 13; n <= 52; n++) writeFileSync(out + `portrait_${n}.svg`, createAvatar(lorelei, { seed: 'demo-mitglied-' + n, size: 400, backgroundColor: [bg[n % 12]], radius: 0 }).toString());
const b64 = (s) => Buffer.from(s).toString('base64');
const rnd = (seed) => { let x = seed * 9301 + 49297; return () => (x = (x * 9301 + 49297) % 233280) / 233280; };

// Gruppenbild "Beim Jahrestreffen": Saal mit Girlande, vier bis fünf Mitglieder nebeneinander
function group(i) {
  const r = rnd(i + 3);
  const n = 4;
  const ids = [i];
  while (ids.length < n) { const k = Math.floor(r() * 12); if (!ids.includes(k)) ids.push(k); }
  let s = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 400 400"><defs><linearGradient id="w" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#f6e8d0"/><stop offset="1" stop-color="#e3c9a3"/></linearGradient></defs>`
    + `<rect width="400" height="400" fill="url(#w)"/><rect y="300" width="400" height="100" fill="#8a6a45"/><path d="M0 40 Q100 100 200 40 T400 40" fill="none" stroke="#7a5230" stroke-width="3"/>`;
  const flags = ['#e4572e', '#29b6a8', '#f3b700', '#4a6fa5', '#9b5de5'];
  for (let f = 0; f < 11; f++) { const x = 18 + f * 36, y = 40 + (f % 2 ? 0 : 0) + Math.sin(f / 10 * Math.PI * 2) * 0 + (f < 6 ? f * 9 : (10 - f) * 9) * 0.0 + 26 * Math.sin((f / 10) * Math.PI) ; s += `<path d="M${x} ${y + 24} l14 0 l-7 18 z" fill="${flags[f % 5]}"/>`; }
  const pos = [[-6, 120], [92, 150], [190, 125], [290, 150]];
  ids.forEach((k, j) => { const [x, y] = pos[j]; const sz = j === 1 || j === 3 ? 125 : 135; s += `<image x="${x}" y="${y}" width="${sz}" height="${sz}" href="data:image/svg+xml;base64,${b64(av(k, 200))}"/>`; });
  s += `<rect x="40" y="300" width="320" height="14" rx="4" fill="#6b4a2b"/><circle cx="100" cy="290" r="9" fill="#e4572e"/><circle cx="200" cy="288" r="9" fill="#f3b700"/><circle cx="300" cy="290" r="9" fill="#29b6a8"/></svg>`;
  return s;
}

// Landschaften "Im Urlaub": Berge, Meer, Wiese – je nach Nummer
function scenery(i) {
  const r = rnd(i + 11);
  const kind = i % 3;
  let s = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 400 400">';
  const skies = [['#7ec8f2', '#e8f6ff'], ['#ffb27a', '#ffe9c7'], ['#8fd3f4', '#f4fbff']][kind];
  s += `<defs><linearGradient id="s" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="${skies[0]}"/><stop offset="1" stop-color="${skies[1]}"/></linearGradient></defs><rect width="400" height="400" fill="url(#s)"/>`;
  s += `<circle cx="${90 + Math.floor(r() * 220)}" cy="${70 + Math.floor(r() * 40)}" r="${kind === 1 ? 46 : 30}" fill="${kind === 1 ? '#fff3b0' : '#fff9d6'}" opacity=".95"/>`;
  for (let c = 0; c < 3; c++) { const x = Math.floor(r() * 300), y = 40 + Math.floor(r() * 90); s += `<g fill="#fff" opacity=".85"><ellipse cx="${x + 40}" cy="${y}" rx="38" ry="12"/><ellipse cx="${x + 62}" cy="${y - 8}" rx="26" ry="11"/><ellipse cx="${x + 20}" cy="${y - 6}" rx="22" ry="10"/></g>`; }
  const ridge = (base, amp, color, steps) => { let d = `M0 400 L0 ${base}`; for (let k = 1; k <= steps; k++) d += ` L${(400 / steps) * k} ${base - Math.floor(r() * amp)}`; return `<path d="${d} L400 400 Z" fill="${color}"/>`; };
  if (kind === 0) {   // Berge mit Schneekappen
    s += ridge(250, 110, '#7f9bb5', 8) + ridge(290, 80, '#5f7f6a', 9) + ridge(335, 50, '#3f6b4a', 11);
    s += `<path d="M120 190 l22 -36 l22 36 z M280 175 l18 -30 l18 30 z" fill="#fff" opacity=".9"/>`;
  } else if (kind === 1) {   // Meer bei Sonnenuntergang
    s += `<rect y="250" width="400" height="150" fill="#3b82a8"/><rect y="250" width="400" height="12" fill="#ffd9a0" opacity=".7"/>`;
    for (let w = 0; w < 18; w++) s += `<path d="M${Math.floor(r() * 360)} ${270 + w * 7} q10 -6 20 0 t20 0" fill="none" stroke="#bfe6f5" stroke-width="2" opacity=".7"/>`;
    s += `<path d="M0 400 L0 345 Q120 310 230 350 T400 330 L400 400 Z" fill="#e6c98f"/><path d="M300 250 l4 -60 M304 190 q-30 -10 -44 14 M304 190 q30 -10 42 12 M304 190 q-6 -26 -26 -30" stroke="#3f6b4a" stroke-width="4" fill="none" stroke-linecap="round"/>`;
  } else {   // grüne Hügel mit Bäumen
    s += ridge(270, 60, '#9ccf7a', 7) + ridge(310, 40, '#6fb85a', 8) + ridge(350, 25, '#4f9a45', 9);
    for (let t = 0; t < 6; t++) { const x = 30 + t * 62 + Math.floor(r() * 20), y = 330 + Math.floor(r() * 40); s += `<rect x="${x}" y="${y}" width="6" height="22" fill="#6b4a2b"/><circle cx="${x + 3}" cy="${y - 4}" r="${14 + Math.floor(r() * 8)}" fill="#2e7d32"/>`; }
    for (let f = 0; f < 14; f++) s += `<circle cx="${Math.floor(r() * 400)}" cy="${355 + Math.floor(r() * 40)}" r="3" fill="${['#e4572e', '#f3b700', '#fff'][f % 3]}"/>`;
  }
  return s + '</svg>';
}
for (let i = 0; i < 12; i++) { writeFileSync(out + `treffen_${i + 1}.svg`, group(i)); writeFileSync(out + `urlaub_${i + 1}.svg`, scenery(i)); }
console.log('Bilder geschrieben nach', out);

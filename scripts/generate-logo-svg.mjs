// Generates public/logo-mark.svg (tile) and public/logo.svg (tile + wordmark)
// from the real Fraunces Variable glyph outlines, so the logo is a true
// vector identical to the webfont rendering.
//
//   node scripts/generate-logo-svg.mjs

import * as fontkit from 'fontkit';
import { readFileSync, writeFileSync } from 'node:fs';
import wawoff2 from 'wawoff2';

// fontkit's own WOFF2 handling chokes on this file — decompress to TTF first.
const woff2 = readFileSync('node_modules/@fontsource-variable/fraunces/files/fraunces-latin-wght-normal.woff2');
const ttf = Buffer.from(await wawoff2.decompress(woff2));
const font = fontkit.create(ttf);

/** Return combined SVG path + total advance for a string at a given weight/size. */
function textPath(text, weight, size) {
    const inst = font.getVariation({ wght: weight });
    const run = inst.layout(text);
    const scale = size / inst.unitsPerEm;
    let x = 0;
    let d = '';
    run.glyphs.forEach((glyph, i) => {
        const pos = run.positions[i];
        // Glyph path is in font units, y-up. Scale + flip y; translate by advance.
        const p = glyph.path.scale(scale, -scale).translate(x + pos.xOffset * scale, -pos.yOffset * scale);
        d += p.toSVG(); // fontkit returns raw path data — concatenated into one d attribute
        x += pos.xAdvance * scale;
    });
    const ascent = inst.ascent * scale;
    const descent = inst.descent * scale; // negative
    const capHeight = inst.capHeight * scale;
    return { d: d.trim(), advance: x, ascent, descent, capHeight };
}

const TILE = 64;              // tile size in viewBox units
const RADIUS = TILE * 0.375;  // rounded-xl proportion of the logo mark

/* ── logo-mark.svg: tile + centred S (weight 600, like the mark) ───── */
{
    const size = TILE * 0.66;
    const s = textPath('S', 600, size);
    // centre the S optically: x by advance, y by cap height
    const tx = (TILE - s.advance) / 2;
    const ty = TILE / 2 + s.capHeight / 2;

    const svg = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${TILE} ${TILE}">
  <defs>
    <linearGradient id="g" x1="0" y1="0" x2="1" y2="1">
      <stop offset="0" stop-color="#14b8a6"/>
      <stop offset="1" stop-color="#0f766e"/>
    </linearGradient>
  </defs>
  <rect width="${TILE}" height="${TILE}" rx="${RADIUS}" fill="url(#g)"/>
  <path transform="translate(${tx.toFixed(2)} ${ty.toFixed(2)})" fill="#ffffff" d="${s.d}"/>
</svg>
`;
    writeFileSync('public/logo-mark.svg', svg);
    console.log('logo-mark.svg written,', svg.length, 'bytes');
}

/* ── logo.svg: tile + "Swayy" wordmark (weight 500, like the header) ── */
{
    const size = TILE * 0.66;
    const s = textPath('S', 600, size);
    const tx = (TILE - s.advance) / 2;
    const ty = TILE / 2 + s.capHeight / 2;

    const wordSize = TILE * 0.75; // wordmark visually matches header proportions
    const w = textPath('Swayy', 500, wordSize);
    const gap = TILE * 0.31;
    const wx = TILE + gap;
    const wy = TILE / 2 + w.capHeight / 2;
    const width = wx + w.advance + 2;

    const svg = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${width.toFixed(2)} ${TILE}">
  <defs>
    <linearGradient id="g" x1="0" y1="0" x2="1" y2="1">
      <stop offset="0" stop-color="#14b8a6"/>
      <stop offset="1" stop-color="#0f766e"/>
    </linearGradient>
  </defs>
  <rect width="${TILE}" height="${TILE}" rx="${RADIUS}" fill="url(#g)"/>
  <path transform="translate(${tx.toFixed(2)} ${ty.toFixed(2)})" fill="#ffffff" d="${s.d}"/>
  <path transform="translate(${wx.toFixed(2)} ${wy.toFixed(2)})" fill="#1c1917" d="${w.d}"/>
</svg>
`;
    writeFileSync('public/logo.svg', svg);
    console.log('logo.svg written,', svg.length, 'bytes');
}

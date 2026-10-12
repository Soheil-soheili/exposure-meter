#!/usr/bin/env node
// Builds world-map.json: the world map, already drawn.
//
// The plugin used to download country outlines in the browser and project them with a
// few lines of its own, which smeared Russia, Fiji and Antarctica across the map and left
// about half of all countries without a country code. This script does the drawing once,
// with a proper map library, so the plugin only has to colour the finished shapes.
//
// Not needed to run the plugin. Run it only to rebuild the map:
//   npm install --no-save world-atlas@2 topojson-client@3 d3-geo@3 i18n-iso-countries
//   node tools/build-world-map.mjs        (from the folder where the libraries were installed)
//
// Shapes: Natural Earth (public domain) via the world-atlas package.

import fs from 'node:fs';
import path from 'node:path';
import { createRequire } from 'node:module';
import { pathToFileURL } from 'node:url';

// The libraries are looked up from the folder the script is started in.
const require = createRequire(path.join(process.cwd(), 'x.js'));
const load = async (name) => { const m = await import(pathToFileURL(require.resolve(name))); return m.default && !m.geoPath ? { ...m.default, ...m } : m; };
const { feature } = await load('topojson-client');
const { geoNaturalEarth1, geoPath } = await load('d3-geo');
const iso = require('i18n-iso-countries');
const world = require(`world-atlas/countries-${process.env.MAP_DETAIL || '50m'}.json`);
const OUT = path.join(path.dirname(new URL(import.meta.url).pathname), '..', 'world-map.json');
const WIDTH = 1000;

// Places the source lists without an ISO number, and the code the plugin's country lookup uses.
const BY_NAME = { Kosovo: 'XK' };
// Shown on the map but never counted separately.
const SKIP = new Set(['Antarctica']);

const all = feature(world, world.objects.countries).features.filter((f) => !SKIP.has(f.properties.name));
const projection = geoNaturalEarth1().fitWidth(WIDTH, { type: 'FeatureCollection', features: all });
const draw = geoPath(projection);
const [[, top], [, bottom]] = draw.bounds({ type: 'FeatureCollection', features: all });
const HEIGHT = Math.ceil(bottom - top) + 2;
projection.translate([projection.translate()[0], projection.translate()[1] - top + 1]);

// A country's outline as rings of projected points, cut cleanly at the map's edges.
function rings(f) {
  const out = [];
  let cur;
  const collect = { beginPath() {}, moveTo(x, y) { cur = [[x, y]]; out.push(cur); }, lineTo(x, y) { cur.push([x, y]); }, closePath() {}, arc() {} };
  geoPath(projection, collect)(f);
  return out.filter((r) => r.length > 2);
}
const ringArea = (r) => Math.abs(r.reduce((sum, [x, y], i) => { const [nx, ny] = r[(i + 1) % r.length]; return sum + x * ny - nx * y; }, 0)) / 2;
// Drops points that move the line by less than `tolerance` pixels (Douglas-Peucker).
function simplify(r, tolerance) {
  const keep = new Uint8Array(r.length); keep[0] = keep[r.length - 1] = 1;
  const stack = [[0, r.length - 1]];
  while (stack.length) {
    const [a, b] = stack.pop();
    const [ax, ay] = r[a]; const [bx, by] = r[b];
    const dx = bx - ax; const dy = by - ay; const len = Math.hypot(dx, dy) || 1e-9;
    let worst = 0; let at = -1;
    for (let i = a + 1; i < b; i++) {
      const dist = Math.abs((r[i][0] - ax) * dy - (r[i][1] - ay) * dx) / len;
      if (dist > worst) { worst = dist; at = i; }
    }
    if (worst > tolerance) { keep[at] = 1; stack.push([a, at], [at, b]); }
  }
  return r.filter((_, i) => keep[i]);
}
// For a closed ring: the first and last point may be the same, which would leave the
// line-simplifier nothing to measure against. Split it at the point farthest from the start.
function simplifyRing(r, tolerance) {
  let pts = r;
  const [fx, fy] = pts[0]; const [lx, ly] = pts[pts.length - 1];
  if (fx === lx && fy === ly) pts = pts.slice(0, -1);
  if (pts.length < 4) return pts;
  let far = 1; let best = -1;
  pts.forEach(([x, y], i) => { const d = Math.hypot(x - fx, y - fy); if (d > best) { best = d; far = i; } });
  const first = simplify(pts.slice(0, far + 1), tolerance);
  const second = simplify([...pts.slice(far), pts[0]], tolerance);
  return first.concat(second.slice(1, -1));
}
const num = (v) => String(Math.round(v * 10) / 10);
const TOLERANCE = 0.25;  // pixels, at a map width of 1000
const MIN_ISLAND = 0.6;  // square pixels; smaller islands are left out, except a country's largest

// One entry per country code. A few codes come as several shapes (Australia and its
// island territories, for example); those are joined, named after the largest one.
const groups = new Map();
let unnamed = 0;
for (const f of all) {
  const shape = rings(f).map((r) => ({ r, area: ringArea(r) }));
  if (!shape.length) continue;
  const code = (f.id && iso.numericToAlpha2(String(f.id).padStart(3, '0'))) || BY_NAME[f.properties.name] || '';
  if (!code) unnamed++;
  const key = code || `?${f.properties.name}`;
  const g = groups.get(key) || { code, parts: [] };
  g.parts.push({ name: f.properties.name, shape, area: shape.reduce((t, x) => t + x.area, 0), centroid: draw.centroid(f) });
  groups.set(key, g);
}
const countries = [...groups.values()].map((g) => {
  g.parts.sort((a, b) => b.area - a.area);
  const all = g.parts.flatMap((p) => p.shape).sort((a, b) => b.area - a.area);
  const kept = all.filter((x, i) => i === 0 || x.area >= MIN_ISLAND);
  let before = 0; let after = 0;
  const d = kept.map(({ r, area }) => {
    const simple = simplifyRing(r, TOLERANCE);
    before += area; after += ringArea(simple);
    const pts = simple.map(([x, y]) => `${num(x)} ${num(y)}`).filter((p, i, a) => p !== a[i - 1]);
    return pts.length > 2 ? `M${pts.join(' ')}Z` : '';
  }).join('');
  // Simplifying must not lose a country: the drawn area has to stay close to the original.
  if (before > 20 && Math.abs(after - before) / before > 0.05) throw new Error(`${g.parts[0].name}: area changed from ${before.toFixed(0)} to ${after.toFixed(0)} square pixels`);
  const entry = { c: g.code, n: g.parts[0].name, d };
  const xs = all[0].r.map((p) => p[0]); const ys = all[0].r.map((p) => p[1]);
  // Too small to see or point at: the plugin adds a dot there when the country has visits.
  if (g.code && Math.max(Math.max(...xs) - Math.min(...xs), Math.max(...ys) - Math.min(...ys)) < 7) entry.m = g.parts[0].centroid.map((v) => Math.round(v * 10) / 10);
  return entry;
}).filter((c) => c.d || c.m); // nothing left to draw or point at
countries.sort((a, b) => (a.c || 'ZZZ').localeCompare(b.c || 'ZZZ') || a.n.localeCompare(b.n));
const codes = countries.map((c) => c.c).filter(Boolean);

fs.writeFileSync(OUT, JSON.stringify({ w: WIDTH, h: HEIGHT, source: 'Natural Earth, public domain', countries }) + '\n');
console.log(`${countries.length} shapes (${codes.length} with a country code, ${unnamed} without, ${countries.filter((c) => c.m).length} with a dot), ${WIDTH}x${HEIGHT}, ${(fs.statSync(OUT).size / 1024).toFixed(0)} KB`);

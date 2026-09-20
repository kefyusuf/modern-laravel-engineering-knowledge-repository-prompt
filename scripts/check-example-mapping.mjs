#!/usr/bin/env node
// Enforces the chapter <-> harness mapping contract (docs/examples.md):
//  - `<!-- example: topic/slug -->` in chapters/**.md must have a matching
//    `@example-id topic/slug` docblock in examples/tests/*.php (and vice versa).
// Also validates that every chapter with a `testbed:` frontmatter points at an
// existing file, and that tagged example comments sit directly above a code fence.

import { readFileSync, readdirSync, statSync, existsSync } from 'node:fs';
import { join, dirname, resolve, relative, sep } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
let errors = 0;
const fail = (msg) => { errors++; console.error(msg); };

function walk(dir, ext, acc = []) {
  for (const entry of readdirSync(dir)) {
    const p = join(dir, entry);
    if (statSync(p).isDirectory()) {
      if (entry === 'node_modules' || entry === 'vendor' || entry === '.git') continue;
      walk(p, ext, acc);
    } else if (entry.endsWith(ext)) acc.push(p);
  }
  return acc;
}

const chapterTags = new Map(); // "topic/slug" -> chapter path
for (const f of walk(join(root, 'chapters'), '.md')) {
  const rel = relative(root, f).replaceAll(sep, '/');
  const md = readFileSync(f, 'utf8');
  const lines = md.split(/\r?\n/);
  for (let i = 0; i < lines.length; i++) {
    const m = lines[i].match(/<!--\s*example:\s*([\w/-]+)\s*-->/);
    if (!m) continue;
    const id = m[1];
    const next = lines.slice(i + 1).find((l) => l.trim() !== '');
    if (!next || !/^```/.test(next.trim())) {
      fail(`${rel}:${i + 1}: example tag must sit directly above a code fence`);
    }
    if (chapterTags.has(id)) fail(`${rel}: duplicate example id ${id} (also in ${chapterTags.get(id)})`);
    chapterTags.set(id, rel);
  }
}

const testIds = new Map(); // "topic/slug" -> test file
for (const f of walk(join(root, 'examples', 'tests'), '.php')) {
  const rel = relative(root, f).replaceAll(sep, '/');
  const src = readFileSync(f, 'utf8');
  for (const m of src.matchAll(/@example-id\s+([\w/-]+)/g)) {
    if (testIds.has(m[1])) fail(`${rel}: duplicate @example-id ${m[1]} (also in ${testIds.get(m[1])})`);
    testIds.set(m[1], rel);
  }
}

for (const [id, chapter] of chapterTags) {
  if (!testIds.has(id)) fail(`chapter ${chapter} tags example "${id}" but no harness test declares @example-id ${id}`);
}
for (const [id, test] of testIds) {
  if (!chapterTags.has(id)) fail(`test ${test} declares @example-id "${id}" but no chapter tags it`);
}

for (const f of walk(join(root, 'chapters'), '.md')) {
  const rel = relative(root, f).replaceAll(sep, '/');
  const fm = readFileSync(f, 'utf8').match(/^---\r?\n([\s\S]*?)\r?\n---/);
  if (!fm) { fail(`${rel}: missing frontmatter`); continue; }
  const tb = fm[1].match(/^testbed:\s*(.+)$/m);
  if (tb && !existsSync(join(root, tb[1].trim()))) {
    fail(`${rel}: testbed "${tb[1].trim()}" does not exist`);
  }
}

if (errors) {
  console.error(`\ncheck-example-mapping: ${errors} problem(s)`);
  process.exit(1);
}
console.log(`check-example-mapping: ok (${chapterTags.size} chapter examples <-> ${testIds.size} harness tests)`);

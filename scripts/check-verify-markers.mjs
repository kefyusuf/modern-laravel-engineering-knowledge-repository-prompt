#!/usr/bin/env node
// VERIFY-marker gate (docs/version-policy.md).
// `<!-- VERIFY: ... -->` marks an unverified claim. Allowed on branches and in
// drafts; forbidden on `main`.
//
// Locally:            node scripts/check-verify-markers.mjs [--strict]
// CI on main:         GITHUB_REF_NAME=main → always strict
// CI on a PR branch:  warn only

import { readdirSync, statSync, readFileSync } from 'node:fs';
import { join, dirname, resolve, relative, sep } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const strict =
  process.argv.includes('--strict') ||
  process.env.GITHUB_REF_NAME === 'main' ||
  process.env.GITHUB_REF === 'refs/heads/main';

function walk(dir, acc = []) {
  for (const entry of readdirSync(dir)) {
    const p = join(dir, entry);
    if (statSync(p).isDirectory()) {
      if (entry === 'node_modules' || entry === 'vendor' || entry === '.git') continue;
      walk(p, acc);
    } else if (entry.endsWith('.md')) acc.push(p);
  }
  return acc;
}

function stripNonRendered(md) {
  let out = md.replace(/`{4,}[\s\S]*?`{4,}/g, (m) => '\n'.repeat((m.match(/\n/g) || []).length));
  out = out.replace(/```[\s\S]*?```/g, (m) => '\n'.repeat((m.match(/\n/g) || []).length));
  out = out.replace(/~~~[\s\S]*?~~~/g, (m) => '\n'.repeat((m.match(/\n/g) || []).length));
  out = out.replace(/`[^`\n]*`/g, '');
  return out;
}

const found = [];
for (const f of walk(root)) {
  const lines = stripNonRendered(readFileSync(f, 'utf8')).split(/\r?\n/);
  lines.forEach((line, i) => {
    if (/<!--\s*VERIFY[:\s]/.test(line)) {
      found.push(`${relative(root, f).replaceAll(sep, '/')}:${i + 1}: ${line.trim().slice(0, 120)}`);
    }
  });
}

if (found.length === 0) {
  console.log('check-verify-markers: no unresolved VERIFY markers');
  process.exit(0);
}

console.log(`VERIFY markers present (${found.length}):`);
for (const f of found) console.log(`  ${f}`);

if (strict) {
  console.error('\ncheck-verify-markers: FAIL — unverified claims cannot reach main. Resolve each marker by citing a primary source, then delete it.');
  process.exit(1);
}
console.log('\ncheck-verify-markers: warn-only (not on main) — markers must be resolved before merge to main.');

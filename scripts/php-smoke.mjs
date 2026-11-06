#!/usr/bin/env node
// Static smoke test for harness PHP (no PHP runtime available locally):
//  - PSR-4: namespace + declared class name must match the file's location
//    under the composer autoload root.
//  - Crude tokenizer: braces/parens balanced outside strings & comments.
// Not a parser: it cannot catch type errors — CI's Pest run is the real
// gate. This catches structural authoring mistakes before push.

import { readFileSync, readdirSync, statSync } from 'node:fs';
import { join, basename, dirname, relative } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..', 'examples');
const autoloadRoot = 'tests'; // composer autoload-dev: MLE\Examples\Tests\ => tests/

function walk(dir, acc = []) {
  for (const e of readdirSync(dir)) {
    const p = join(dir, e);
    if (statSync(p).isDirectory()) {
      if (['vendor', 'node_modules', '.phpunit.cache', '.git'].includes(e)) continue;
      walk(p, acc);
    } else if (e.endsWith('.php')) acc.push(p);
  }
  return acc;
}

let problems = 0;
const files = walk(root);

for (const f of files) {
  const src = readFileSync(f, 'utf8');
  const ns = src.match(/namespace\s+([\w\\]+);/)?.[1];
  const cls = src.match(/(?:final\s+|abstract\s+)?(?:class|interface|enum|trait)\s+(\w+)/);
  const isFixture = Boolean(ns && ns.startsWith('MLE\\Examples\\Tests\\Fixtures'));
  const isScript = basename(f).startsWith('2026_'); // migrations are anonymous classes
  // PSR-4 matters only for autoloaded fixtures; Pest test files are
  // function-based and discovered by glob, and migrations are anonymous.
  if (isFixture) {
    if (!cls) {
      console.log(`NO-CLASS: ${f}`);
      problems++;
    } else {
      if (basename(f, '.php') !== cls[1]) {
        console.log(`PSR-4 MISMATCH: ${f} declares ${cls[1]}`);
        problems++;
      }
      // namespace path must match the file path under the autoload root
      const expectedDir = ns
        .replace('MLE\\Examples\\Tests\\', '')
        .replace(/\\/g, '/');
      const relDir = dirname(relative(join(root, 'tests'), f)).replaceAll('\\', '/');
      if (relDir !== expectedDir) {
        console.log(`NAMESPACE PATH MISMATCH: ${f} declares ${ns} (expected under tests/${expectedDir})`);
        problems++;
      }
    }
  }
  void isScript;

  let depth = 0, parens = 0, i = 0, state = 'code';
  while (i < src.length) {
    const c = src[i]; const n = src[i + 1];
    if (state === 'code') {
      if (c === '/' && n === '/') { state = 'line'; i += 2; continue; }
      if (c === '#') { state = 'line'; i++; continue; }
      if (c === '/' && n === '*') { state = 'block'; i += 2; continue; }
      if (c === "'") { state = 'sq'; i++; continue; }
      if (c === '"') { state = 'dq'; i++; continue; }
      if (c === '{') depth++;
      if (c === '}') depth--;
      if (c === '(') parens++;
      if (c === ')') parens--;
    } else if (state === 'line') {
      if (c === '\n') state = 'code';
    } else if (state === 'block') {
      if (c === '*' && n === '/') { state = 'code'; i += 2; continue; }
    } else if (state === 'sq') {
      if (c === '\\') i++; else if (c === "'") state = 'code';
    } else if (state === 'dq') {
      if (c === '\\') i++; else if (c === '"') state = 'code';
    }
    i++;
  }
  if (depth !== 0 || parens !== 0 || state !== 'code') {
    console.log(`BALANCE: ${f} braces=${depth} parens=${parens} state=${state}`);
    problems++;
  }
}

console.log(problems === 0
  ? `php-smoke: ok (${files.length} files)`
  : `php-smoke: ${problems} problem(s)`);
process.exit(problems === 0 ? 0 : 1);

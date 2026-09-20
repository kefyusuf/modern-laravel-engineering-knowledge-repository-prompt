#!/usr/bin/env node
// Internal link + anchor checker for the handbook.
// - Every relative Markdown link must resolve to a file.
// - Every `file.md#anchor` / `#anchor` anchor must match a GitHub-slugger-style
//   slug of some heading in the target file (or the file itself).
// - Links inside fenced code blocks, inline code, frontmatter, and HTML comments
//   are ignored (they are not rendered as links).

import { readFileSync, readdirSync, statSync } from 'node:fs';
import { join, dirname, resolve, relative, sep } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

export function slugify(heading) {
  return heading
    .replace(/`/g, '')
    .trim()
    .toLowerCase()
    .replace(/[^\p{L}\p{N}\s_-]/gu, '')
    .replace(/\s/g, '-');
}

// Strip frontmatter, fenced code blocks, inline code, and HTML comments.
export function stripNonRendered(md) {
  let out = md.replace(/^---\r?\n[\s\S]*?\r?\n---/, '');
  // 4+ backtick fences (which may contain 3-backtick fences) first.
  out = out.replace(/`{4,}[\s\S]*?`{4,}/g, (m) => '\n'.repeat((m.match(/\n/g) || []).length));
  out = out.replace(/```[\s\S]*?```/g, (m) => '\n'.repeat((m.match(/\n/g) || []).length));
  out = out.replace(/~~~[\s\S]*?~~~/g, (m) => '\n'.repeat((m.match(/\n/g) || []).length));
  out = out.replace(/<!--[\s\S]*?-->/g, (m) => '\n'.repeat((m.match(/\n/g) || []).length));
  out = out.replace(/`[^`\n]*`/g, '');
  return out;
}

export function anchorsOf(md) {
  const counts = new Map();
  const anchors = new Set();
  for (const raw of stripNonRendered(md).match(/^#{1,6} .*$/gm) || []) {
    const text = raw.replace(/^#{1,6} /, '').replace(/ [#*]+$/, '');
    let slug = slugify(text);
    const n = counts.get(slug) ?? 0;
    counts.set(slug, n + 1);
    if (n > 0) slug = `${slug}-${n}`;
    anchors.add(slug);
  }
  return anchors;
}

function walk(dir, acc = []) {
  for (const entry of readdirSync(dir)) {
    const p = join(dir, entry);
    if (statSync(p).isDirectory()) {
      if (entry === 'node_modules' || entry === 'vendor' || entry === '.git') continue;
      walk(p, acc);
    } else if (entry.endsWith('.md')) {
      acc.push(p);
    }
  }
  return acc;
}

const LINK_RE = /\[[^\]]*\]\(\s*(<[^>]*>|[^)\s]+)(?:\s+"[^"]*")?\s*\)/g;

export function run(root) {
  const files = walk(root);
  const mdByPath = new Map(files.map((f) => [f, readFileSync(f, 'utf8')]));
  const anchorCache = new Map();

  let errors = 0;
  const report = (file, msg) => {
    errors++;
    console.error(`${relative(root, file).replaceAll(sep, '/')}: ${msg}`);
  };

  for (const [file, md] of mdByPath) {
    const relFile = relative(root, file).replaceAll(sep, '/');
    for (const m of stripNonRendered(md).matchAll(LINK_RE)) {
      let target = m[1].replace(/^<|>$/g, '');
      if (!target || target.startsWith('#')) continue;
      if (/^(https?:|mailto:|tel:)/i.test(target)) continue;

      const [pathPart, anchor] = target.split('#');
      const resolved = resolve(dirname(file), pathPart);
      if (!resolved.startsWith(root)) {
        report(file, `link escapes repository: ${target}`);
        continue;
      }
      let content;
      try {
        content = statSync(resolved).isDirectory() ? '' : readFileSync(resolved, 'utf8');
      } catch {
        report(file, `broken link [${m[0]}] -> ${target}`);
        continue;
      }
      if (anchor) {
        let anchors = anchorCache.get(resolved);
        if (!anchors) {
          anchors = /\.md$/.test(resolved) ? anchorsOf(content) : null;
          anchorCache.set(resolved, anchors);
        }
        if (anchors && !anchors.has(slugify(anchor))) {
          report(file, `broken anchor [${m[0]}] -> ${target} (no heading "${anchor}")`);
        }
      }
    }
    // Self-file heading anchors: [text](#anchor)
    for (const m of stripNonRendered(md).matchAll(/\[[^\]]*\]\(#([^)\s]+)\)/g)) {
      const anchors = anchorCache.get(file) ?? (() => {
        const a = anchorsOf(md);
        anchorCache.set(file, a);
        return a;
      })();
      if (!anchors.has(slugify(m[1]))) {
        report(file, `broken self-anchor #${m[1]} (${relFile})`);
      }
    }
  }

  if (errors) {
    console.error(`\ncheck-links: ${errors} broken link(s)/anchor(s)`);
    process.exit(1);
  }
  console.log(`check-links: ok (${files.length} markdown files)`);
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  run(resolve(dirname(fileURLToPath(import.meta.url)), '..'));
}

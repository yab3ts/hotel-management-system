#!/usr/bin/env node
import fs from 'node:fs';
import path from 'node:path';

const root = path.resolve(path.dirname(new URL(import.meta.url).pathname), '../..');
const yamlPath = path.join(root, 'security-controls.yaml');

if (!fs.existsSync(yamlPath)) {
  console.error('FAIL: security-controls.yaml not found');
  process.exit(1);
}

const raw = fs.readFileSync(yamlPath, 'utf8');
const lines = raw.split(/\r?\n/);

const controls = [];
let current = null;
for (const line of lines) {
  const trimmed = line.trim();
  if (!trimmed || trimmed.startsWith('#') || trimmed === 'controls:') continue;
  if (trimmed.startsWith('- id:')) {
    if (current) controls.push(current);
    current = { id: trimmed.replace('- id:', '').trim() };
  } else if (current && trimmed.includes(':')) {
    const idx = trimmed.indexOf(':');
    const k = trimmed.slice(0, idx).trim();
    const v = trimmed.slice(idx + 1).trim();
    current[k] = v;
  }
}
if (current) controls.push(current);

if (controls.length === 0) {
  console.error('FAIL: No controls parsed from security-controls.yaml');
  process.exit(1);
}

const today = process.env.MOCK_TODAY ? new Date(process.env.MOCK_TODAY) : new Date();
let failures = 0;

for (const c of controls) {
  if (!c.owner || !c.last_verified || !c.frequency_days || !c.evidence) {
    console.error(`FAIL [#${c.id}]: Missing required field (owner, last_verified, frequency_days, or evidence)`);
    failures++;
    continue;
  }

  const evidenceFile = path.join(root, c.evidence);
  if (!fs.existsSync(evidenceFile)) {
    console.error(`FAIL [#${c.id}]: Evidence file missing at ${c.evidence}`);
    failures++;
  }

  const verifiedDate = new Date(c.last_verified);
  if (Number.isNaN(verifiedDate.getTime())) {
    console.error(`FAIL [#${c.id}]: Invalid last_verified date '${c.last_verified}'`);
    failures++;
    continue;
  }

  const ageDays = Math.floor((today.getTime() - verifiedDate.getTime()) / (1000 * 60 * 60 * 24));
  const maxDays = Number(c.frequency_days);
  if (ageDays > maxDays) {
    console.error(`FAIL [#${c.id}]: '${c.control}' is overdue (${ageDays}d > ${maxDays}d, owner: ${c.owner})`);
    failures++;
  }
}

const codeownersPath = path.join(root, '.github/CODEOWNERS');
if (!fs.existsSync(codeownersPath)) {
  console.error('FAIL: .github/CODEOWNERS missing — mandatory control owner reviews are not configured');
  process.exit(1);
}
const codeownersContent = fs.readFileSync(codeownersPath, 'utf8');
if (!codeownersContent.includes('security-controls.yaml') || !codeownersContent.includes('docs/evidence/')) {
  console.error('FAIL: .github/CODEOWNERS must explicitly protect security-controls.yaml and docs/evidence/');
  process.exit(1);
}

if (failures > 0) {
  console.error(`\nManual control audit FAILED (${failures} issue(s) across ${controls.length} controls).`);
  process.exit(1);
}

console.log(`PASS: All ${controls.length} manual controls in security-controls.yaml have owners, evidence files, CODEOWNERS protection, and are within review cadence.`);

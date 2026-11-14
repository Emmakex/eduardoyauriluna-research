#!/usr/bin/env node
/**
 * #475.24: pure GitHub commit-status projection for the protected Eduardo MCP readback.
 * Never forwards private response bodies, paths, connection identifiers or token data.
 */
import fs from 'node:fs';
import { validate } from './site-architect-47523-validate-mcp.mjs';

const CONTEXT = 'site-architect/eduardo-mcp-readback';
const DEFAULT = Object.freeze({
  context: CONTEXT,
  state: 'pending',
  description: 'Protected Eduardo MCP read-only observation awaiting result',
});

export function deriveStatus(mode, jobResult, report) {
  if (mode === 'pending') return { ...DEFAULT };
  if (mode !== 'outcome') throw new Error('Unsupported Site Architect status mode');
  if (!['success','failure','cancelled','skipped','timed_out','unknown'].includes(jobResult)) {
    throw new Error('Unexpected protected job result');
  }
  if (jobResult !== 'success') return {
    context: CONTEXT,
    state: 'error',
    description: 'Protected MCP readback not completed; inspect the linked Actions run',
  };
  if (!report || typeof report !== 'object') return {
    context: CONTEXT,
    state: 'error',
    description: 'Protected MCP readback has no sanitized evidence',
  };
  try { validate(report); }
  catch { return {
    context: CONTEXT,
    state: 'error',
    description: 'MCP evidence did not pass redacted schema validation',
  }; }
  switch (report.outcome) {
    case 'MCP_PRIVATE_READBACK_OBSERVED':
      return {
        context: CONTEXT,
        state: 'success',
        description: 'MCP versions + Pages/Media observed; native DB and cutover still open',
      };
    case 'MCP_VERSION_CAPABILITIES_ONLY':
      return {
        context: CONTEXT,
        state: 'failure',
        description: 'MCP versions/capabilities seen; Pages/Media inventory incomplete',
      };
    case 'VERSION_DRIFT_REVIEW':
      return {
        context: CONTEXT,
        state: 'failure',
        description: 'Site Architect version drift; no production changes made',
      };
    case 'READBACK_INCOMPLETE':
      return {
        context: CONTEXT,
        state: 'failure',
        description: 'MCP readback incomplete; no production certification',
      };
    default: throw new Error('Unknown sanitized readback outcome');
  }
}

function main() {
  const mode = process.argv[2];
  const jobResult = process.env.SA47524_PROTECTED_JOB_RESULT || 'unknown';
  const targetFile = process.env.SA47524_STATUS_PAYLOAD;
  if (!targetFile || !/^\/tmp\/sa-47524-status-[a-z0-9-]+\.json$/.test(targetFile)) {
    throw new Error('Status payload must be written only to a private /tmp file');
  }
  const evidence = process.env.SA47524_REDACTED_REPORT;
  let report = null;
  if (evidence && evidence.endsWith('/live-mcp-readback.json')) {
    try { report = JSON.parse(fs.readFileSync(evidence, 'utf8')); }
    catch { /* lack of private readback is not success */ }
  }
  const status = deriveStatus(mode, jobResult, report);
  fs.writeFileSync(targetFile, JSON.stringify(status) + '\n', {mode: 0o600});
  // Only a single known coarse state string is printed.
  console.log('SITE_ARCHITECT_GITHUB_COMMIT_STATUS=' + status.state);
}
if (process.argv[1]?.endsWith('site-architect-47524-status-contract.mjs')) main();

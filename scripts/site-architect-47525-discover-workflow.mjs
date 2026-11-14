#!/usr/bin/env node
/**
 * #475.25 One read-only GitHub Actions metadata survey.
 * No secrets beyond the current short-lived GITHUB_TOKEN; never accesses
 * the MCP or WordPress or prints response bodies from unexpected APIs.
 */
import fs from 'node:fs';

const REPO = 'Emmakex/eduardoyauriluna-research';
const WORKFLOW = 'site-architect-47523-live-readonly.yml';
const STATUS_CONTEXT = 'site-architect/eduardo-mcp-readback';
const SAFE_RUN_STATUSES = ['queued','requested','waiting','pending','in_progress','completed'];
const SAFE_CONCLUSIONS = ['success','failure','cancelled','skipped','timed_out','neutral','action_required',null];
const SAFE_EVENTS = ['push','workflow_dispatch','pull_request'];

export function reduceActions(mainSha, runs, rawStatuses) {
  if (!/^[0-9a-f]{40}$/.test(mainSha)) throw new Error('Invalid exact main SHA');
  if (!Array.isArray(runs) || !Array.isArray(rawStatuses)) throw new Error('Invalid GitHub API collection');
  const matching = runs.filter(x => x && x.head_sha === mainSha).map(x => {
    if (!Number.isSafeInteger(x.id) || x.id < 1 ||
        !SAFE_RUN_STATUSES.includes(x.status) ||
        !SAFE_CONCLUSIONS.includes(x.conclusion) ||
        !SAFE_EVENTS.includes(x.event)) throw new Error('Unexpected run metadata');
    const run_url = 'https://github.com/' + REPO + '/actions/runs/' + x.id;
    return {run_id:x.id,event:x.event,status:x.status,
      conclusion:x.conclusion,run_url};
  }).slice(0,10);
  const exact = rawStatuses.filter(s => s && s.context === STATUS_CONTEXT)
    .map(s => {
      if (!['success','failure','error','pending'].includes(s.state)) {
        throw new Error('Unexpected status state');
      }
      return {state:s.state,description:typeof s.description === 'string' ?
          s.description.slice(0,140) : null,
        run_url:typeof s.target_url === 'string' &&
          /^https:\/\/github\.com\/[Ee]mmakex\/eduardoyauriluna-research\/actions\/runs\/[0-9]+$/.test(s.target_url) ?
          s.target_url : null};
    }).slice(0,5);
  const outcome = matching.length === 0 ? 'NO_MAIN_WORKFLOW_RUN_VISIBLE'
    : matching.some(x => x.status !== 'completed') ? 'PROTECTED_WORKFLOW_NOT_COMPLETED'
      : exact.length === 0 ? 'COMPLETED_WITHOUT_COMMIT_STATUS'
        : 'GITHUB_RUN_AND_STATUS_OBSERVED';
  return {schema_version:1,source:'GITHUB_ACTIONS_API_READ_ONLY',
    workflow:WORKFLOW,main_sha:mainSha,
    outcome, matching_runs:matching,
    exact_commit_statuses:exact,
    mcp_payloads_accessed:false,
    wordpress_mutations_performed:false,
    release_certification:'NOT_RELEASE_CERTIFIED'};
}

async function api(path, token) {
  if (!/^\/repos\/Emmakex\/eduardoyauriluna-research\/(git\/ref\/heads\/main|actions\/workflows\/site-architect-47523-live-readonly\.yml\/runs\?branch=main&per_page=20|commits\/[0-9a-f]{40}\/statuses\?per_page=30)$/.test(path)) {
    throw new Error('GitHub endpoint outside bounded read-only API contract');
  }
  const result = await fetch('https://api.github.com' + path, {
    method:'GET',headers:{
      'Authorization':'Bearer ' + token,
      'Accept':'application/vnd.github+json',
      'X-GitHub-Api-Version':'2022-11-28',
      'User-Agent':'site-architect-eduardo-47525-readonly'
    },signal:AbortSignal.timeout(20000)
  });
  if (!result.ok) throw new Error('GitHub Actions metadata unavailable: HTTP ' + result.status);
  return await result.json();
}

async function main() {
  if ((process.env.GITHUB_REPOSITORY || '').toLowerCase() !== REPO.toLowerCase() ||
    !process.env.GITHUB_TOKEN || process.env.GITHUB_EVENT_NAME !== 'pull_request') {
    throw new Error('Refusing request outside authorized repo pull-request read-only diagnostic');
  }
  const ref = await api('/repos/' + REPO + '/git/ref/heads/main',process.env.GITHUB_TOKEN);
  const sha=ref?.object?.sha;
  if (!/^[0-9a-f]{40}$/.test(sha || '')) throw new Error('Main ref unavailable');
  const [runs,statuses]=await Promise.all([
    api('/repos/'+REPO+'/actions/workflows/'+WORKFLOW+'/runs?branch=main&per_page=20',process.env.GITHUB_TOKEN),
    api('/repos/'+REPO+'/commits/'+sha+'/statuses?per_page=30',process.env.GITHUB_TOKEN),
  ]);
  const report=reduceActions(sha,runs.workflow_runs,statuses);
  const out=process.env.SA47525_OUTPUT;
  if (typeof out !== 'string' || !/^\/tmp\/sa-47525-actions-[a-z0-9-]+\.json$/.test(out))
    throw new Error('Unsafe output path');
  fs.writeFileSync(out,JSON.stringify(report,null,2)+'\n',{mode:0o600});
  console.log('ACTIONS_READ_ONLY_DISCOVERY=' + report.outcome +
    ' matching_runs=' + report.matching_runs.length +
    ' exact_statuses=' + report.exact_commit_statuses.length);
  for(const run of report.matching_runs) {
    console.log('KNOWN_RUN_ID=' + run.run_id + ' event=' + run.event +
      ' status=' + run.status + ' conclusion=' + run.conclusion);
  }
}

if(process.argv[1]?.endsWith('site-architect-47525-discover-workflow.mjs'))main().catch(error=>{
  console.error('READ_ONLY_DISCOVERY_FAILED: ' + (error instanceof Error ? error.message : 'unknown'));
  process.exitCode=1;
});

/** Data-minimized Preview of the existing WordPress greenfield-canonical Plan.
 * This does not authorize Apply or read a custom Blueprint from an operator.
 */
const PHASE_NAMES = ['bootstrap', 'pairing', 'hydration', 'relations'] as const;
const PLAN_ID = /^erm-remote-plan-[a-f0-9]{20}-[a-f0-9]{8}$/;
const FINGERPRINT = /^[a-f0-9]{64}$/;

function record(value: unknown): Record<string, unknown> | null {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
    ? value as Record<string, unknown> : null;
}

function boundedText(value: unknown, max = 64): string {
  if (typeof value !== 'string' || !/^[a-z][a-z0-9-]*$/.test(value) || value.length > max) {
    throw new Error('Canonical Greenfield Preview has an invalid bounded status');
  }
  return value;
}

function safeCount(value: unknown): number | null {
  if (value === undefined) return null;
  if (!Number.isSafeInteger(value) || (value as number) < 0 || (value as number) > 100000) {
    throw new Error('Canonical Greenfield Preview has an invalid operation count');
  }
  return value as number;
}

export function summarizeCanonicalGreenfieldPlan(input: unknown) {
  const plan = record(input);
  if (!plan || plan.operation !== 'greenfield-canonical' || plan.status !== 'planned'
    || plan.confirmation_required !== true
    || typeof plan.apply_allowed !== 'boolean'
    || typeof plan.plan_id !== 'string' || !PLAN_ID.test(plan.plan_id)
    || typeof plan.source_revision !== 'string' || !FINGERPRINT.test(plan.source_revision)
    || typeof plan.blueprint_sha256 !== 'string' || !FINGERPRINT.test(plan.blueprint_sha256)
    || typeof plan.contract_fingerprint !== 'string' || !FINGERPRINT.test(plan.contract_fingerprint)
    || typeof plan.expires_at !== 'string'
    || !/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\+00:00|Z)$/.test(plan.expires_at)) {
    throw new Error('Canonical Greenfield Plan authority returned an incomplete or unexpected operation');
  }
  const preview = record(plan.preview);
  const phases = record(preview?.phases);
  if (!phases || PHASE_NAMES.some(name => !record(phases[name]))
    || Object.keys(phases).some(name => !PHASE_NAMES.includes(name as typeof PHASE_NAMES[number]))) {
    throw new Error('Canonical Greenfield Preview phases do not match the existing pipeline');
  }
  const summary: Record<string, {status:string; apply_allowed:boolean|null; operation_count:number|null; change_count:number|null}> = {};
  for (const name of PHASE_NAMES) {
    const phase = record(phases[name])!;
    summary[name] = {
      status: phase.status === undefined ? 'unspecified' : boundedText(phase.status),
      apply_allowed: typeof phase.apply_allowed === 'boolean' ? phase.apply_allowed : null,
      operation_count: safeCount(phase.operation_count),
      change_count: safeCount(phase.change_count),
    };
  }
  return {
    product: 'Site Architect',
    track: 'Greenfield',
    operation: 'greenfield-canonical',
    plan_id: plan.plan_id,
    status: 'planned',
    apply_allowed: plan.apply_allowed,
    confirmation_required: true,
    blueprint_sha256: plan.blueprint_sha256,
    source_revision: plan.source_revision,
    contract_fingerprint: plan.contract_fingerprint,
    expires_at: plan.expires_at,
    phases: summary,
    stored_plan_created: true,
    public_site_apply: 'not_executed',
    rollback: 'not_applicable_until_apply',
  } as const;
}

/**
 * Hard limits per plan — must stay in sync with the backend controller checks
 * (LeadController, DealController, PipelineController).
 */
export const PLAN_LIMITS = {
  free:       { leads: 100,      deals: 50,      pipelines: 1        },
  business:   { leads: 5_000,    deals: 2_000,   pipelines: 30       },
  enterprise: { leads: Infinity, deals: Infinity, pipelines: Infinity },
} as const;

export type PlanKey = keyof typeof PLAN_LIMITS;

/** Returns the limit set for the given plan string (defaults to free). */
export function getLimits(plan: string | undefined) {
  if (plan === 'business')   return PLAN_LIMITS.business;
  if (plan === 'enterprise') return PLAN_LIMITS.enterprise;
  return PLAN_LIMITS.free;
}

/**
 * Hard limits per plan — must stay in sync with the backend controller checks
 * (LeadController, DealController, PipelineController).
 */
export const PLAN_LIMITS = {
  free:       { leads: 100,      deals: 50,      pipelines: 1        },
  growth:     { leads: 5_000,    deals: Infinity, pipelines: 5        },
  business:   { leads: 25_000,   deals: Infinity, pipelines: Infinity },
  enterprise: { leads: Infinity, deals: Infinity, pipelines: Infinity },
} as const;

export type PlanKey = keyof typeof PLAN_LIMITS;

/** Returns the limit set for the given plan string (defaults to free). */
export function getLimits(plan: string | undefined) {
  if (plan === 'business')   return PLAN_LIMITS.business;
  if (plan === 'growth')     return PLAN_LIMITS.growth;
  if (plan === 'enterprise') return PLAN_LIMITS.enterprise;
  return PLAN_LIMITS.free;
}

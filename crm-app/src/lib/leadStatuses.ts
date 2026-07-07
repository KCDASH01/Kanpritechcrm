export const LEAD_STATUSES = [
  'new',
  'contacted',
  'qualified',
  'unqualified',
  'followup',
  'meeting',
  'not_interested',
  'converted',
  'lost',
] as const;

export type LeadStatus = (typeof LEAD_STATUSES)[number];

export const LEAD_STATUS_LABELS: Record<LeadStatus, string> = {
  new:            'New',
  contacted:      'Contacted',
  qualified:      'Qualified',
  unqualified:    'Unqualified',
  followup:       'Follow-up',
  meeting:        'Meeting',
  not_interested: 'Not Interested',
  converted:      'Converted',
  lost:           'Lost',
};

/** Statuses shown in the ⋮ menu "Change Status" submenu */
export const LEAD_STATUS_MENU: LeadStatus[] = [
  'new',
  'contacted',
  'qualified',
  'unqualified',
  'followup',
  'meeting',
  'not_interested',
  'lost',
];

/** Statuses that require a date/time before updating */
export const SCHEDULED_LEAD_STATUSES = ['followup', 'meeting'] as const;
export type ScheduledLeadStatus = (typeof SCHEDULED_LEAD_STATUSES)[number];

export function isScheduledLeadStatus(s: string): s is ScheduledLeadStatus {
  return (SCHEDULED_LEAD_STATUSES as readonly string[]).includes(s);
}

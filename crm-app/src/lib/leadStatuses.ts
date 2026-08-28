export const LEAD_STATUSES = [
  'new',
  'contacted',
  'ringing',
  'important',
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
  ringing:        'Ringing',
  important:      'Important',
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
  'ringing',
  'important',
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

/** Statuses that require a remark (saved to activities.description) */
export const REMARK_LEAD_STATUSES = ['ringing', 'important'] as const;
export type RemarkLeadStatus = (typeof REMARK_LEAD_STATUSES)[number];

export function isRemarkLeadStatus(s: string): s is RemarkLeadStatus {
  return (REMARK_LEAD_STATUSES as readonly string[]).includes(s);
}

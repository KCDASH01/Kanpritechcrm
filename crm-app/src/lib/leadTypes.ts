export const LEAD_TYPES = [
  'webapp_development',
  'mobile_app_development',
  'website_development',
  'digital_marketing',
  'others',
] as const;

export type LeadType = (typeof LEAD_TYPES)[number];

export const LEAD_TYPE_LABELS: Record<LeadType, string> = {
  webapp_development:      'Webapp Development',
  mobile_app_development:  'Mobile App Development',
  website_development:     'Website Development',
  digital_marketing:       'Digital Marketing',
  others:                  'Others',
};

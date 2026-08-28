// Reusable status / type badge

import { LEAD_STATUS_LABELS } from '@/lib/leadStatuses';

const COLORS: Record<string, string> = {
  // Lead status
  new:          'bg-blue-100 text-blue-700',
  contacted:    'bg-yellow-100 text-yellow-700',
  ringing:      'bg-green-100 text-green-700',
  important:    'bg-amber-100 text-amber-800',
  converted:    'bg-indigo-100 text-indigo-700',
  followup:     'bg-orange-100 text-orange-700',
  meeting:      'bg-violet-100 text-violet-700',
  not_interested: 'bg-gray-100 text-gray-600',
  // legacy aliases (if any cached responses)
  qualified:    'bg-green-100 text-green-700',
  unqualified:  'bg-amber-100 text-amber-800',

  // Deal status
  open:  'bg-blue-100 text-blue-700',
  won:   'bg-green-100 text-green-700',
  lost:  'bg-red-100 text-red-700',

  // Activity type
  call:     'bg-green-100 text-green-700',
  email:    'bg-violet-100 text-violet-700',
  task:     'bg-orange-100 text-orange-700',
  note:     'bg-yellow-100 text-yellow-700',
  deadline: 'bg-red-100 text-red-700',

  // Priority
  low:    'bg-gray-100 text-gray-600',
  medium: 'bg-orange-100 text-orange-700',
  high:   'bg-red-100 text-red-700',

  // Generic
  active:   'bg-green-100 text-green-700',
  inactive: 'bg-gray-100 text-gray-500',
  business: 'bg-indigo-100 text-indigo-700',
  free:     'bg-gray-100 text-gray-600',
};

interface BadgeProps {
  value: string;
  className?: string;
  capitalize?: boolean;
}

export function Badge({ value, className = '', capitalize = true }: BadgeProps) {
  const color = COLORS[value] ?? 'bg-gray-100 text-gray-600';
  const label = capitalize
    ? (LEAD_STATUS_LABELS[value as keyof typeof LEAD_STATUS_LABELS]
        ?? value.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase()))
    : value;
  return (
    <span className={`inline-flex items-center text-xs px-2.5 py-0.5 rounded-full font-medium ${color} ${className}`}>
      {label}
    </span>
  );
}

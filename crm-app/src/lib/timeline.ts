// Shared timeline action config — used by LeadPanel (list page) and LeadActivityFeed (detail page)

export const TIMELINE_CONFIG: Record<string, { icon: string; color: string; bg: string }> = {
  created:            { icon: '✦', color: 'text-indigo-600', bg: 'bg-indigo-100'  },
  updated:            { icon: '✎', color: 'text-blue-600',   bg: 'bg-blue-100'    },
  status_changed:     { icon: '⟳', color: 'text-amber-600',  bg: 'bg-amber-100'   },
  assigned:           { icon: '👤', color: 'text-violet-600', bg: 'bg-violet-100'  },
  deal_created:       { icon: '💰', color: 'text-emerald-600',bg: 'bg-emerald-100' },
  followup_created:   { icon: '📅', color: 'text-sky-600',    bg: 'bg-sky-100'     },
  activity_scheduled: { icon: '🗓', color: 'text-cyan-600',   bg: 'bg-cyan-100'    },
  note_added:         { icon: '📝', color: 'text-yellow-600', bg: 'bg-yellow-100'  },
  status_remark:      { icon: '💬', color: 'text-teal-600',   bg: 'bg-teal-100'    },
  deal_won:           { icon: '🏆', color: 'text-emerald-600',bg: 'bg-emerald-100' },
  converted:          { icon: '🎉', color: 'text-green-600',  bg: 'bg-green-100'   },
  deleted:            { icon: '🗑', color: 'text-red-600',    bg: 'bg-red-100'     },
  whatsapp:           { icon: '💬', color: 'text-green-600',  bg: 'bg-green-100'   },
};

export function relativeTime(iso: string): string {
  const diff = Date.now() - new Date(iso).getTime();
  const mins  = Math.floor(diff / 60_000);
  const hours = Math.floor(diff / 3_600_000);
  const days  = Math.floor(diff / 86_400_000);
  if (mins  < 1)  return 'just now';
  if (mins  < 60) return `${mins}m ago`;
  if (hours < 24) return `${hours}h ago`;
  if (days  < 7)  return `${days}d ago`;
  return new Date(iso).toLocaleDateString('en-IN', { day: 'numeric', month: 'short' });
}

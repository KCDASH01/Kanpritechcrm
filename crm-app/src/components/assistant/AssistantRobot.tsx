'use client';

import { motion, useReducedMotion } from 'framer-motion';

export type RobotState = 'idle' | 'important' | 'urgent' | 'talking' | 'success' | 'risk' | 'minimized' | 'snoozed';

export function AssistantRobot({ variant, state = 'idle', className = '', muted = false }: {
  variant: 'buddy' | 'coach'; state?: RobotState; className?: string; muted?: boolean;
}) {
  const reducedMotion = useReducedMotion();
  const still = reducedMotion || muted || state === 'snoozed' || state === 'minimized';
  const urgent = state === 'urgent' || state === 'important';
  const face = state === 'success' ? 'M33 47 Q40 53 47 47' : state === 'risk' ? 'M34 51 Q40 46 46 51' : 'M34 48 Q40 51 46 48';
  const accent = variant === 'buddy' ? '#8B5CF6' : '#2563EB';
  const accent2 = variant === 'buddy' ? '#4F46E5' : '#10B981';

  return <motion.svg viewBox="0 0 80 92" role="img" aria-label={variant === 'buddy' ? 'Follow-up Buddy assistant' : 'Target Coach assistant'} className={className}
    animate={still ? undefined : urgent ? { y: [0, -7, 0], rotate: [0, -3, 3, 0] } : { y: [0, -3, 0] }}
    transition={still ? undefined : { duration: urgent ? .65 : 3.2, repeat: urgent ? 1 : Infinity, ease: 'easeInOut' }}>
    <defs><linearGradient id={`body-${variant}`} x1="0" y1="0" x2="1" y2="1"><stop stopColor={accent}/><stop offset="1" stopColor={accent2}/></linearGradient><filter id={`shadow-${variant}`} x="-30%" y="-30%" width="160%" height="180%"><feDropShadow dx="0" dy="4" stdDeviation="3" floodOpacity=".2"/></filter></defs>
    <ellipse cx="40" cy="86" rx="22" ry="4" fill="#111827" opacity=".12"/><g filter={`url(#shadow-${variant})`}>
      <rect x="18" y="28" width="44" height="42" rx="17" fill={`url(#body-${variant})`}/><rect x="22" y="31" width="36" height="27" rx="12" fill="#F8FAFC"/>
      <motion.ellipse cx="33" cy="42" rx="3" ry={state === 'success' ? 1 : 4} fill="#111827" animate={still ? undefined : { scaleY: [1, 1, .15, 1] }} transition={{ duration: 4, repeat: Infinity }}/><motion.ellipse cx="47" cy="42" rx="3" ry={state === 'success' ? 1 : 4} fill="#111827" animate={still ? undefined : { scaleY: [1, 1, .15, 1] }} transition={{ duration: 4, repeat: Infinity }}/>
      <path d={face} stroke="#334155" strokeWidth="2" strokeLinecap="round" fill="none"/><rect x="34" y="63" width="12" height="4" rx="2" fill="#fff" opacity=".75"/><path d="M40 28V20" stroke={accent2} strokeWidth="3" strokeLinecap="round"/><circle cx="40" cy="17" r="4" fill={state === 'risk' ? '#F97316' : state === 'success' ? '#22C55E' : accent}/>
      <motion.path d="M18 48L10 56" stroke={accent} strokeWidth="6" strokeLinecap="round" animate={!still && state === 'talking' ? { rotate: [0, 18, 0] } : undefined} style={{ transformOrigin: '18px 48px' }} transition={{ duration: .8, repeat: Infinity }}/><motion.path d="M62 48L70 56" stroke={accent2} strokeWidth="6" strokeLinecap="round" animate={!still && state === 'success' ? { rotate: [0, -35, 0] } : undefined} style={{ transformOrigin: '62px 48px' }} transition={{ duration: .7, repeat: 2 }}/><path d="M30 69L27 79M50 69L53 79" stroke="#475569" strokeWidth="6" strokeLinecap="round"/>
      {variant === 'coach' && <g><rect x="53" y="16" width="18" height="14" rx="5" fill="#fff" stroke="#BFDBFE"/><path d="M57 26l4-5 3 2 4-5" stroke="#10B981" strokeWidth="2" fill="none" strokeLinecap="round" strokeLinejoin="round"/></g>}
    </g>
  </motion.svg>;
}

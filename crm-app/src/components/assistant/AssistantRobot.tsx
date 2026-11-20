'use client';

import { useId } from 'react';
import { motion, useReducedMotion, type TargetAndTransition } from 'framer-motion';
import { useDashboardVisibility } from './DashboardAssistantAudio';

export type RobotState = 'idle' | 'wave' | 'happy' | 'sad' | 'dance' | 'celebrate' | 'alert' | 'sleep' | 'thoughtful';

const bodyMotion: Record<RobotState, TargetAndTransition> = {
  idle: { y: [0, -2, 0], scaleY: [1, 1.025, 1], transition: { duration: 4, repeat: Infinity, ease: 'easeInOut' } },
  wave: { rotate: [0, -5, 5, 0], y: [0, -3, 0, 0], transition: { duration: 1.25, ease: 'easeInOut' } },
  happy: { y: [0, -4, 0], transition: { duration: 0.85, repeat: 1, ease: 'easeInOut' } },
  sad: { y: 3, rotate: -4, scaleY: 0.96, transition: { duration: 0.55 } },
  dance: { x: [0, -6, 0, 6, 0], y: [0, -4, 0, -4, 0], rotate: [0, -8, 0, 8, 0], transition: { duration: 1.15, repeat: 2, ease: 'easeInOut' } },
  celebrate: { y: [0, -15, 0, -7, 0], scaleY: [1, 1.09, 0.94, 1.05, 1], transition: { duration: 1.35, ease: 'easeInOut' } },
  alert: { y: [0, -3, 0], scale: [1, 1.08, 1], transition: { duration: 0.65, repeat: 1, ease: 'easeInOut' } },
  sleep: { y: [2, 3, 2], scaleY: [0.96, 0.99, 0.96], transition: { duration: 5, repeat: Infinity, ease: 'easeInOut' } },
  thoughtful: { rotate: [0, 5, 2], y: 1, transition: { duration: 0.9 } },
};

const mouths: Record<RobotState, string> = {
  idle: 'M34 48 Q40 52 46 48',
  wave: 'M33 47 Q40 54 47 47',
  happy: 'M33 46 Q40 56 47 46Z',
  sad: 'M34 51 Q40 45 46 51',
  dance: 'M34 46 Q40 56 46 46Z',
  celebrate: 'M34 46 Q40 58 46 46Z',
  alert: 'M37 48 a3 3 0 1 0 6 0 a3 3 0 1 0 -6 0',
  sleep: 'M37 49 Q40 50 43 49',
  thoughtful: 'M36 49 H43',
};

const particles = [
  { x: -30, y: -20, color: '#7C3AED' },
  { x: -17, y: -27, color: '#F59E0B' },
  { x: 2, y: -30, color: '#2563EB' },
  { x: 18, y: -26, color: '#D946EF' },
  { x: 32, y: -16, color: '#10B981' },
];

export function AssistantRobot({ variant, state = 'idle', className = '', muted = false }: {
  variant: 'buddy' | 'coach';
  state?: RobotState;
  className?: string;
  muted?: boolean;
}) {
  const visible = useDashboardVisibility();
  const reducedMotion = useReducedMotion();
  const still = !visible || muted || reducedMotion;
  const id = useId().replace(/:/g, '');
  const accent = variant === 'buddy' ? '#8B5CF6' : '#2563EB';
  const accent2 = variant === 'buddy' ? '#4F46E5' : '#059669';
  const joyful = ['happy', 'dance', 'celebrate'].includes(state);
  const rightArm = state === 'wave' ? [0, -75, -35, -82, -40, 0]
    : state === 'dance' ? [0, -70, -15, 10, 0]
    : state === 'celebrate' ? [0, -120, -75, 0]
    : state === 'alert' ? [0, -65, -65, 0]
    : state === 'happy' ? [0, -60, -60, 0] : 0;
  const leftArm = state === 'dance' ? [0, -10, 15, 70, 0] : state === 'celebrate' ? [0, 120, 75, 0] : 0;
  const pose = { x: 0, y: 0, rotate: 0, scale: 1, scaleY: 1 };

  return (
    <svg viewBox="0 0 80 92" role="img" aria-label={variant === 'buddy' ? 'Follow-up Buddy assistant' : 'Target Coach assistant'} className={className}>
      <defs><linearGradient id={`body-${id}`} x1="0" y1="0" x2="1" y2="1"><stop stopColor={accent} /><stop offset="1" stopColor={accent2} /></linearGradient></defs>
      <ellipse cx="40" cy="86" rx="21" ry="3.5" fill="#111827" opacity=".12" />
      <motion.g animate={still ? { ...pose, transition: { duration: 0 } } : { ...pose, ...bodyMotion[state] }} style={{ transformOrigin: '40px 62px' }}>
        <motion.path d="M19 51L10 58" stroke={accent} strokeWidth="6" strokeLinecap="round"
          animate={{ rotate: still ? 0 : leftArm }} transition={{ duration: still ? 0 : 1.15, repeat: !still && state === 'dance' ? 2 : 0 }} style={{ transformOrigin: '19px 51px' }} />
        <motion.g animate={{ rotate: still ? 0 : rightArm }} transition={{ duration: still ? 0 : 1.15, repeat: !still && state === 'dance' ? 2 : 0 }} style={{ transformOrigin: '61px 51px' }}>
          <path d="M61 51L70 58" stroke={accent2} strokeWidth="6" strokeLinecap="round" />
          {state === 'happy' && <path d="M69 57l1-5" stroke={accent2} strokeWidth="3" strokeLinecap="round" />}
        </motion.g>
        <path d={state === 'dance' ? 'M30 69L24 77M50 69L55 79' : 'M30 69L27 79M50 69L53 79'} stroke="#475569" strokeWidth="6" strokeLinecap="round" />
        <rect x="18" y="29" width="44" height="42" rx="17" fill={`url(#body-${id})`} />
        <motion.g animate={{ rotate: still ? 0 : state === 'wave' ? [0, -10, 6, 0] : state === 'sad' ? -11 : state === 'thoughtful' ? 9 : 0 }}
          transition={{ duration: still ? 0 : 0.9 }} style={{ transformOrigin: '40px 52px' }}>
          <rect x="22" y="31" width="36" height="27" rx="12" fill="#F8FAFC" />
          <path d={state === 'sad' ? 'M40 29Q39 23 34 21' : 'M40 29V20'} stroke={accent2} strokeWidth="3" strokeLinecap="round" fill="none" />
          <circle cx={state === 'sad' ? 33 : 40} cy={state === 'sad' ? 20 : 17} r="4" fill={state === 'alert' ? '#F97316' : joyful ? '#22C55E' : accent} />
          {[33, 47].map((x) => state === 'sleep' || joyful
            ? <path key={x} d={joyful ? `M${x - 3} 43q3 -5 6 0` : `M${x - 3} 42q3 3 6 0`} fill="none" stroke="#111827" strokeWidth="2" strokeLinecap="round" />
            : <motion.ellipse key={x} cx={x} cy={state === 'sad' ? 44 : 42} rx="2.8" ry={state === 'alert' ? 4.5 : 3.6} fill="#111827"
                animate={{ scaleY: !still && state === 'idle' ? [1, 1, 0.12, 1, 1] : 1 }}
                transition={{ duration: still ? 0 : 4.8, repeat: !still && state === 'idle' ? Infinity : 0, times: [0, 0.36, 0.38, 0.41, 1] }} />)}
          {state === 'sad' && <path d="M29 41l6-3m10 0 6 3" stroke="#334155" strokeWidth="1.5" strokeLinecap="round" />}
          <path d={mouths[state]} stroke="#334155" strokeWidth="1.7" strokeLinecap="round" fill={joyful ? '#334155' : 'none'} />
        </motion.g>
        <rect x="34" y="63" width="12" height="4" rx="2" fill="#fff" opacity=".75" />
        {variant === 'coach' && <g><rect x="53" y="16" width="18" height="14" rx="5" fill="#fff" stroke="#BFDBFE" /><path d="M57 26l4-5 3 2 4-5" stroke="#059669" strokeWidth="2" fill="none" strokeLinecap="round" strokeLinejoin="round" /></g>}
      </motion.g>
      {state === 'sleep' && <text x="54" y="22" fontSize="10" fill={accent2}>z</text>}
      {!still && (state === 'celebrate' || state === 'dance') && particles.map(({ x, y, color }) => (
        <motion.rect key={x} x="38" y="34" width="4" height="3" rx="1" fill={color} initial={{ opacity: 0 }}
          animate={{ x: [0, x, x * 1.14], y: [0, y, y + 18], opacity: [0, 1, 0], rotate: [0, 90, 180] }}
          transition={{ duration: 1.15, delay: state === 'dance' ? 1.85 : 0.1 }} />
      ))}
    </svg>
  );
}

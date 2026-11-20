const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '..');
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8');
const assert = (condition, message) => { if (!condition) throw new Error(message); };

const layout = read('src/components/layout/DashboardLayout.tsx');
const schedule = read('src/components/layout/ScheduleAlertFab.tsx');
const buddy = read('src/components/assistant/FollowUpBuddy.tsx');
const coach = read('src/components/dashboard/TargetCoach.tsx');
const robot = read('src/components/assistant/AssistantRobot.tsx');

assert(layout.includes('<ScheduleAlertFab />') && layout.includes('<FollowUpBuddy />'), 'Calendar shortcut and Follow-up Buddy must both render');
assert(schedule.includes("router.push('/follow-ups')") && schedule.includes("router.push('/meetings')"), 'Existing schedule navigation must remain');
assert(schedule.includes('activitiesApi.markDone') && schedule.includes('markDoneMutation'), 'Existing schedule completion behavior must remain');
assert(buddy.includes("pathname !== '/dashboard'") && buddy.includes('Snooze 1 hour') && buddy.includes('Dismiss'), 'Buddy must be dashboard-only with snooze and dismissal');
assert(coach.includes('projection_status') && coach.includes('Required daily pace') && coach.includes('Assigned target'), 'Target Coach analytics are incomplete');
assert(robot.includes('useReducedMotion') && robot.includes('aria-label'), 'Robot must respect reduced motion and accessibility');

console.log('Assistant UI regression checks passed.');

const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const ts = require('typescript');

const root = path.resolve(__dirname, '..');
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8');
const assert = (condition, message) => { if (!condition) throw new Error(message); };

const layout = read('src/components/layout/DashboardLayout.tsx');
const schedule = read('src/components/layout/ScheduleAlertFab.tsx');
const buddy = read('src/components/assistant/FollowUpBuddy.tsx');
const coach = read('src/components/dashboard/TargetCoach.tsx');
const robot = read('src/components/assistant/AssistantRobot.tsx');
const dashboard = read('src/app/(dashboard)/dashboard/page.tsx');
const settings = read('src/app/(dashboard)/settings/page.tsx');

assert(layout.includes("{!isDashboard && <ScheduleAlertFab />}") && layout.includes("{isDashboard && <FollowUpBuddy />}") && layout.includes("usePathname() === '/dashboard'"), 'Dashboard must swap the existing calendar shortcut for Buddy for every role');
assert(schedule.includes("router.push('/follow-ups')") && schedule.includes("router.push('/meetings')"), 'Existing schedule navigation must remain');
assert(schedule.includes('activitiesApi.markDone') && schedule.includes('markDoneMutation'), 'Existing schedule completion behavior must remain');
assert(buddy.includes('Snooze 1 hour') && buddy.includes('Dismiss') && buddy.includes('completed_activity_ids'), 'Buddy must retain reminder controls and use confirmed completions');
assert(dashboard.includes('<TargetCoach selectedUserId='), 'Target Coach must remain in Dashboard performance');
assert(coach.includes('projection_status') && coach.includes('Required daily pace') && coach.includes('Assigned target'), 'Target Coach analytics are incomplete');
assert(robot.includes('useReducedMotion') && robot.includes('useDashboardVisibility') && robot.includes('aria-label'), 'Robot must respect visibility, reduced motion and accessibility');
for (const state of ['idle', 'wave', 'happy', 'sad', 'dance', 'celebrate', 'alert', 'sleep']) {
  assert(robot.includes(`  ${state}: {`), `Missing motion for ${state}`);
}
assert(settings.includes('canManage && <AssistantSoundSettingsCard'), 'Sound settings must be omitted for employees');

const { outputText } = ts.transpileModule(read('src/components/assistant/DashboardAssistantAudio.tsx'), {
  compilerOptions: { module: ts.ModuleKind.CommonJS, jsx: ts.JsxEmit.ReactJSX },
});
const playback = { exports: {} };
vm.runInNewContext(outputText, { exports: playback.exports, require: (name) => name.startsWith('@/') ? {} : require(name) });
const choose = playback.exports.allowedAssistantSound;
const policy = { enabled: true, alert: true, success: true, celebrate: false, play_when_hidden: false, buddy_interval_seconds: 30, coach_interval_seconds: 90 };
const ready = { robot: 'buddy', hidden: false, unlocked: true, busy: false, secondsSinceLast: 30 };
assert(choose(policy, ['alert'], ready) === 'alert', 'Allowed alert should play at the configured interval');
assert(choose(policy, ['celebrate', 'success'], ready) === 'success', 'Disabled celebration should fall back to enabled success');
assert(choose(policy, ['alert'], { ...ready, robot: 'coach' }) === undefined, 'Coach must have an independent cooldown');
assert(choose(policy, ['alert'], { ...ready, secondsSinceLast: 29 }) === undefined, 'Buddy cooldown must suppress early playback');
assert(choose(policy, ['alert'], { ...ready, busy: true }) === undefined, 'Sounds must not overlap');
assert(choose(policy, ['alert'], { ...ready, unlocked: false }) === undefined, 'Browser audio must be unlocked');
assert(choose(policy, ['alert'], { ...ready, hidden: true }) === undefined, 'Background sounds must be off by default');
assert(choose({ ...policy, play_when_hidden: true }, ['alert'], { ...ready, hidden: true }) === 'alert', 'Admins can allow background sounds');
assert(choose({ ...policy, enabled: false }, ['alert'], ready) === undefined, 'The master switch must suppress all playback');

console.log('Assistant UI regression checks passed.');

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
const soundSettings = read('src/components/settings/AssistantSoundSettingsCard.tsx');

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
assert(soundSettings.includes('type="button" onClick={() => void testSound(key, label)}'), 'Test Sound must play directly from a button click');
assert(soundSettings.includes('disabled={!settings.enabled || !settings[key] || !!testing}'), 'Test buttons must follow the master and category switches');

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
assert(choose({ ...policy, alert: false }, ['alert'], ready) === undefined, 'A disabled category must suppress its event sound');

async function testSoundPlayer() {
  class AudioContext {
    constructor() {
      this.state = 'suspended';
      this.currentTime = 1;
      this.destination = {};
      this.gains = [];
      this.oscillators = [];
      AudioContext.instance = this;
    }
    resume() {
      this.resumeRequests = (this.resumeRequests ?? 0) + 1;
      return new Promise((resolve, reject) => {
        this.allow = () => { this.state = 'running'; resolve(); };
        this.reject = reject;
      });
    }
    close() {
      this.state = 'closed';
      return Promise.resolve();
    }
    createGain() {
      const node = { gain: { value: 0, setValueAtTime() {}, exponentialRampToValueAtTime() {} }, connect() {}, disconnect() { this.disconnected = true; } };
      this.gains.push(node);
      return node;
    }
    createOscillator() {
      const oscillator = { frequency: {}, connect() {}, disconnect() {}, start(time) { this.startTime = time; }, stop() {} };
      this.oscillators.push(oscillator);
      return oscillator;
    }
  }
  const { outputText } = ts.transpileModule(read('src/lib/assistantSoundPlayer.ts'), {
    compilerOptions: { module: ts.ModuleKind.CommonJS },
  });
  const exports = {};
  vm.runInNewContext(outputText, { exports, AudioContext, window: { setTimeout, clearTimeout } });
  const audio = new exports.AssistantSoundPlayer();
  assert(audio.play('success', 'low') === false, 'Playback must wait for an unlocked browser');
  const unlocking = audio.unlock();
  const context = AudioContext.instance;
  assert(audio.unlock() === unlocking && context.resumeRequests === 1, 'Concurrent gestures must share the same resume attempt');
  assert(audio.play('alert', 'low') === false, 'Pending resume must not be treated as successful');
  context.allow();
  assert(await unlocking, 'A granted browser audio context must unlock');
  assert(audio.play('success', 'low'), 'Success must schedule its actual tone');
  const success = context.gains[0];
  assert(context.oscillators.map((oscillator) => oscillator.frequency.value).join(',') === '523,659,784', 'Success waveform must contain its three notes');
  assert(audio.busy, 'Scheduled notes must mark the player busy for automatic sounds');
  assert(audio.play('alert', 'medium'), 'An explicit preview can replace a playing clip immediately');
  assert(success.disconnected && context.gains[4].gain.value > success.gain.value, 'Replacing audio must stop the old clip and use the selected volume');
  assert(audio.play('celebrate', 'high'), 'Celebration must schedule its actual tone');
  assert(context.oscillators.slice(-4).map((oscillator) => oscillator.frequency.value).join(',') === '659,784,880,1047', 'Celebration waveform must contain its four notes');
  assert(context.oscillators.every((oscillator) => oscillator.startTime > context.currentTime), 'Notes must be scheduled after the current audio sample');
  audio.close();
  assert(!audio.busy && context.state === 'closed', 'Closing the session must release the audio context');
  const blocked = audio.unlock();
  AudioContext.instance.reject(new Error('Playback blocked'));
  assert(await blocked === false, 'Autoplay rejection must be reported without an unhandled rejection');
  audio.close();
}

testSoundPlayer().then(() => console.log('Assistant UI and audio regression checks passed.'), (error) => {
  console.error(error);
  process.exitCode = 1;
});

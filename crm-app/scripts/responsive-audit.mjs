const endpoint = process.env.CDP_ENDPOINT ?? 'http://127.0.0.1:9223';
const pages = ['dashboard', 'deals', 'team', 'follow-ups', 'meetings', 'important', 'reports', 'geographic-analytics'];
const viewports = [
  { name: 'desktop', width: 1440, height: 900, mobile: false },
  { name: 'mobile', width: 390, height: 844, mobile: true },
];

async function json(path, options) {
  const response = await fetch(`${endpoint}${path}`, options);
  if (!response.ok) throw new Error(`${response.status} ${response.statusText}: ${path}`);
  return response.json();
}

const target = await json('/json/new?about:blank', { method: 'PUT' });
const socket = new WebSocket(target.webSocketDebuggerUrl);
await new Promise((resolve, reject) => {
  socket.addEventListener('open', resolve, { once: true });
  socket.addEventListener('error', reject, { once: true });
});

let nextId = 1;
const pending = new Map();
socket.addEventListener('message', (event) => {
  const message = JSON.parse(event.data);
  if (!message.id || !pending.has(message.id)) return;
  const { resolve, reject } = pending.get(message.id);
  pending.delete(message.id);
  if (message.error) reject(new Error(message.error.message));
  else resolve(message.result);
});

function send(method, params = {}) {
  const id = nextId++;
  socket.send(JSON.stringify({ id, method, params }));
  return new Promise((resolve, reject) => pending.set(id, { resolve, reject }));
}

await send('Page.enable');
await send('Runtime.enable');
await send('Page.addScriptToEvaluateOnNewDocument', {
  source: `
    if (location.origin === 'http://localhost:3002') {
      const expiresAt = Date.now() + 86400000;
      const user = {
        id: 1,
        name: 'Responsive Tester',
        email: 'responsive@example.test',
        role: 'owner',
        organization_id: 1,
        organization: { id: 1, name: 'Responsive Test CRM' },
        subscription: { plan: 'enterprise', status: 'active', end_date: null }
      };
      localStorage.setItem('crm_token', 'responsive-audit-token');
      localStorage.setItem('crm_auth', JSON.stringify({
        state: { user, token: 'responsive-audit-token', isAuthenticated: true, expiresAt },
        version: 0
      }));
    }
  `,
});

const results = [];
for (const viewport of viewports) {
  await send('Emulation.setDeviceMetricsOverride', {
    width: viewport.width,
    height: viewport.height,
    deviceScaleFactor: 1,
    mobile: viewport.mobile,
  });
  for (const page of pages) {
    await send('Page.navigate', { url: `http://localhost:3002/${page}` });
    await new Promise((resolve) => setTimeout(resolve, 1800));
    const evaluated = await send('Runtime.evaluate', {
      returnByValue: true,
      expression: `(() => {
        const width = document.documentElement.clientWidth;
        const overflow = [...document.querySelectorAll('body *')]
          .filter((element) => {
            const style = getComputedStyle(element);
            if (style.display === 'none' || style.visibility === 'hidden') return false;
            const rect = element.getBoundingClientRect();
            return rect.width > 0 && rect.right > width + 2 && style.position !== 'fixed';
          })
          .slice(0, 8)
          .map((element) => ({
            tag: element.tagName.toLowerCase(),
            className: typeof element.className === 'string' ? element.className.slice(0, 100) : '',
            right: Math.round(element.getBoundingClientRect().right),
          }));
        return {
          path: location.pathname,
          title: document.title,
          width,
          documentScrollWidth: document.documentElement.scrollWidth,
          bodyScrollWidth: document.body.scrollWidth,
          horizontalOverflow: document.documentElement.scrollWidth > width + 2 || document.body.scrollWidth > width + 2,
          overflowingElements: overflow,
        };
      })()`,
    });
    results.push({ viewport: viewport.name, page, ...evaluated.result.value });
  }
}

socket.close();
const failures = results.filter((result) => result.horizontalOverflow);
console.log(JSON.stringify({ passed: failures.length === 0, checked: results.length, failures, results }, null, 2));
if (failures.length) process.exitCode = 1;

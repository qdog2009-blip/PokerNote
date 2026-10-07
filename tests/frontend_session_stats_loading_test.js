const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '..', 'public', 'js', 'app.js'), 'utf8');
const template = fs.readFileSync(path.join(__dirname, '..', 'public', 'index.html'), 'utf8');

function createApp() {
  const elements = {};
  for (const [, id] of template.matchAll(/\bid="([^"]+)"/g)) {
    const classes = new Set();
    elements[id] = {
      hidden: false, textContent: '', innerHTML: '',
      classList: {
        add(...names) { names.forEach(name => classes.add(name)); },
        remove(...names) { names.forEach(name => classes.delete(name)); },
        contains(name) { return classes.has(name); },
        toggle(name, enabled) {
          if (enabled) classes.add(name);
          else classes.delete(name);
        },
      },
    };
  }
  const requests = [];
  const alerts = [];
  const context = vm.createContext({
    document: {
      addEventListener() {},
      getElementById(id) { return elements[id]; },
      querySelectorAll(selector) {
        assert.equal(selector, '.page');
        return Object.entries(elements).filter(([id]) => id.startsWith('page-')).map(([, el]) => el);
      },
    },
    localStorage: { getItem() { return null; } },
    fetch(url) {
      return new Promise((resolve, reject) => requests.push({
        url,
        resolve(data) { resolve({ ok: true, status: 200, text: async () => JSON.stringify(data) }); },
        reject() { reject(new TypeError('Failed to fetch')); },
      }));
    },
    alert(message) { alerts.push(message); },
    setTimeout, clearTimeout,
  });
  vm.runInContext(source, context);
  return { elements, requests, alerts, run(code) { return vm.runInContext(code, context); } };
}

function stats(id) {
  return {
    totalBuyins: 200, rakeRate: 10, waterPool: 10,
    totalPoolExpenses: 15, waterPoolBalance: -5,
    waterPoolAdjustment: 0, isFullySettled: true,
    players: [
      { name: 'Winner ' + id, buyin: 100, final: 200, grossProfitLoss: 100, profitLoss: 90, rake: 10 },
      { name: 'Loser ' + id, buyin: 100, final: 0, grossProfitLoss: -100, profitLoss: -100, rake: 0 },
    ],
  };
}

async function main() {
  const app = createApp();
  const el = app.elements;
  const openStats = id => {
    app.run(`currentSession = { id: ${id} }; showPage('page-session')`);
    return app.run('showSessionStats()');
  };

  const first = openStats(1);
  assert.equal(app.requests[0].url, '/api/sessions/1/stats');
  assert.equal(el['page-stats'].classList.contains('active'), true);
  assert.equal(el['session-stats-content'].hidden, true);
  assert.equal(el['session-stats-status'].hidden, false);
  assert.equal(el['session-stats-status'].textContent, '加载中…');
  app.requests[0].resolve(stats(1));
  assert.equal(await first, true);
  assert.equal(el['session-stats-content'].hidden, false);
  assert.equal(el['session-stats-status'].hidden, true);
  assert.equal(el['stat-total-buyin'].textContent, '¥200.00');
  assert.equal(el['stat-water-pool'].textContent, '¥10.00');
  assert.equal(el['stat-pool-expenses'].textContent, '-¥15.00');
  assert.equal(el['stat-rake-balance'].textContent, '-¥5.00');
  assert.equal(el['stat-rake-balance-row'].classList.contains('negative'), true);
  assert.match(el['stats-list'].innerHTML, /amount profit/);
  assert.match(el['stats-list'].innerHTML, /amount loss/);

  const second = openStats(2);
  assert.equal(el['session-stats-content'].hidden, true);
  assert.equal(el['stats-list'].innerHTML, '');
  assert.equal(el['stat-total-buyin'].textContent, '-');
  assert.equal(el['stat-rake-balance'].textContent, '-');
  app.requests[1].resolve(stats(2));
  assert.equal(await second, true);
  assert.match(el['stats-list'].innerHTML, /Winner 2/);
  assert.doesNotMatch(el['stats-list'].innerHTML, /Winner 1/);

  const slow = openStats(1);
  const fast = openStats(2);
  app.requests[3].resolve(stats(2));
  assert.equal(await fast, true);
  app.requests[2].resolve(stats(1));
  assert.equal(await slow, false);
  assert.match(el['stats-list'].innerHTML, /Winner 2/);

  const staleFailure = openStats(1);
  const latest = app.run('showSessionStats()');
  app.requests[4].reject();
  assert.equal(await staleFailure, false);
  assert.equal(el['session-stats-status'].textContent, '加载中…');
  app.requests[5].resolve(stats(1));
  assert.equal(await latest, true);

  const failure = openStats(1);
  app.requests[6].reject();
  assert.equal(await failure, false);
  assert.match(el['session-stats-status'].textContent, /加载失败/);
  assert.equal(el['session-stats-status'].hidden, false);
  assert.equal(el['session-stats-content'].hidden, true);
  assert.equal(el['stats-list'].innerHTML, '');
  assert.equal(app.alerts.length, 0);

  const retry = openStats(1);
  assert.equal(el['session-stats-status'].textContent, '加载中…');
  app.run('goBack()');
  app.requests[7].resolve(stats(1));
  assert.equal(await retry, true);
  assert.equal(el['page-session'].classList.contains('active'), true);
  assert.equal(el['page-stats'].classList.contains('active'), false);

  const abandoned = openStats(1);
  app.run("goBack(); currentSession = { id: 2 }");
  app.requests[8].resolve(stats(1));
  assert.equal(await abandoned, false);
  assert.equal(el['stats-list'].innerHTML, '');
  assert.equal(el['page-session'].classList.contains('active'), true);

  app.run('currentSession = null');
  assert.equal(await app.run('showSessionStats()'), false);
  assert.equal(app.requests.length, 9);

  process.stdout.write('Session stats loading UI tests passed\n');
}

main().catch(error => {
  process.stderr.write(`${error.stack}\n`);
  process.exitCode = 1;
});

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
      hidden: false,
      open: false,
      textContent: '',
      innerHTML: '',
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
  const alerts = [];
  const requests = [];
  const context = vm.createContext({
    document: {
      addEventListener() {},
      getElementById(id) { return elements[id]; },
      querySelectorAll(selector) {
        assert.equal(selector, '.page');
        return Object.entries(elements).filter(([id]) => id.startsWith('page-')).map(([, element]) => element);
      },
    },
    localStorage: { getItem() { return null; } },
    fetch(url) {
      return new Promise((resolve, reject) => {
        requests.push({
          url,
          resolve(data) { resolve({ ok: true, status: 200, text: async () => JSON.stringify(data) }); },
          reject() { reject(new TypeError('Failed to fetch')); },
        });
      });
    },
    alert(message) { alerts.push(message); },
    setTimeout,
    clearTimeout,
  });
  vm.runInContext(source, context);
  vm.runInContext("groups = [{ id: 1, name: 'Group A' }, { id: 2, name: 'Group B' }]", context);
  return {
    elements, alerts, requests,
    run(code) { return vm.runInContext(code, context); },
  };
}

function stats(id, access = 'owner') {
  return {
    group: { id, name: 'Group ' + id, access_level: access, owner_email: 'owner@example.com' },
    sessionCount: 1,
    totalBuyins: 100,
    totalSettled: 100,
    totalRake: 0,
    totalPoolExpenses: 5,
    waterPool: -5,
    waterPoolAdjustment: 0,
    sessions: [{ id, name: 'Session ' + id, playerNames: ['Player ' + id], playerCount: 1, waterPool: 0 }],
    expenses: [{ id, note: 'Expense ' + id, amount: 5, created_at: '2026-10-07 08:00:00', session_name: null }],
    players: [{ name: 'Player ' + id, sessionCount: 1, winningSessionCount: 0, grossProfitLoss: 0, profitLoss: 0, rake: 0 }],
  };
}

async function main() {
  const app = createApp();
  const el = app.elements;

  const first = app.run('showGroupStats(1)');
  assert.equal(el['page-group-stats'].classList.contains('active'), true);
  assert.equal(el['group-stats-title'].textContent, 'Group A');
  assert.equal(el['group-stats-content'].hidden, true);
  assert.equal(el['group-stats-status'].hidden, false);
  assert.equal(el['group-stats-status'].textContent, '\u52a0\u8f7d\u4e2d\u2026');
  app.requests[0].resolve(stats(1));
  assert.equal(await first, true);
  assert.equal(el['group-stats-content'].hidden, false);
  assert.equal(el['group-stats-status'].hidden, true);
  assert.match(el['group-player-list'].innerHTML, /Player 1/);
  assert.equal(el['btn-group-share'].hidden, false);
  assert.equal(el['btn-add-group-expense'].hidden, false);

  el['group-expense-details'].open = true;
  const second = app.run('showGroupStats(2)');
  assert.equal(app.run('currentGroup.id'), 2);
  assert.equal(app.run('currentGroupStats'), null);
  assert.equal(el['group-stats-content'].hidden, true);
  assert.equal(el['group-expense-details'].open, false);
  assert.equal(el['btn-group-share'].hidden, true);
  assert.equal(el['btn-add-group-expense'].hidden, true);
  ['group-player-list', 'group-session-list', 'group-expense-list'].forEach(id => assert.equal(el[id].innerHTML, ''));
  ['group-stat-total-buyin', 'group-stat-water-pool', 'group-stat-error'].forEach(id => assert.equal(el[id].textContent, '-'));
  app.requests[1].resolve(stats(2, 'view'));
  assert.equal(await second, true);
  assert.match(el['group-player-list'].innerHTML, /Player 2/);
  assert.doesNotMatch(el['group-player-list'].innerHTML, /Player 1/);
  assert.equal(el['btn-group-share'].hidden, true);
  assert.equal(el['btn-add-group-expense'].hidden, true);

  const slow = app.run('showGroupStats(1)');
  const fast = app.run('showGroupStats(2)');
  app.requests[3].resolve(stats(2));
  assert.equal(await fast, true);
  app.requests[2].resolve(stats(1));
  assert.equal(await slow, false);
  assert.equal(el['group-stats-title'].textContent, 'Group 2');
  assert.equal(app.run('currentGroupStats.group.id'), 2);

  const staleFailure = app.run('showGroupStats(1)');
  const latest = app.run('showGroupStats(2)');
  app.requests[4].reject();
  assert.equal(await staleFailure, false);
  assert.equal(el['group-stats-status'].textContent, '\u52a0\u8f7d\u4e2d\u2026');
  app.requests[5].resolve(stats(2));
  assert.equal(await latest, true);

  const failure = app.run('showGroupStats(1)');
  app.requests[6].reject();
  assert.equal(await failure, false);
  assert.match(el['group-stats-status'].textContent, /\u52a0\u8f7d\u5931\u8d25/);
  assert.equal(el['group-stats-content'].hidden, true);
  assert.equal(el['group-player-list'].innerHTML, '');
  assert.equal(app.alerts.length, 0);

  const retry = app.run('showGroupStats(1)');
  app.requests[7].resolve({ ...stats(1), players: [], sessions: [], expenses: [] });
  assert.equal(await retry, true);
  assert.equal(el['group-stats-content'].hidden, false);
  assert.match(el['group-player-list'].innerHTML, /empty-state/);
  assert.match(el['group-session-list'].innerHTML, /empty-state/);
  assert.match(el['group-expense-list'].innerHTML, /empty-state/);

  el['group-expense-details'].open = true;
  const historyLength = app.run('pageHistory.length');
  const refresh = app.run('showGroupStats(1, false)');
  assert.equal(el['group-stats-content'].hidden, false);
  app.requests[8].resolve(stats(1, 'input'));
  assert.equal(await refresh, true);
  assert.equal(app.run('pageHistory.length'), historyLength);
  assert.equal(el['group-expense-details'].open, true);
  assert.equal(el['btn-add-group-expense'].hidden, false);
  assert.equal(el['btn-group-share'].hidden, true);

  const background = app.run('showGroupStats(1, false)');
  const navigate = app.run('showGroupStats(2)');
  app.requests[10].resolve(stats(2));
  assert.equal(await navigate, true);
  app.requests[9].resolve(stats(1));
  assert.equal(await background, false);
  assert.equal(app.run('currentGroup.id'), 2);
  assert.equal(await app.run('showGroupStats(1, false)'), false);
  assert.equal(app.requests.length, 11);

  const quietFailure = app.run('showGroupStats(2, false, false)');
  app.requests[11].reject();
  assert.equal(await quietFailure, false);
  assert.equal(el['group-stats-content'].hidden, false);
  assert.equal(app.alerts.length, 0);

  process.stdout.write('Group stats loading UI tests passed\n');
}

main().catch(error => {
  process.stderr.write(`${error.stack}\n`);
  process.exitCode = 1;
});

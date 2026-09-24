const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '..', 'public', 'js', 'app.js'), 'utf8');

function createApp(fetch) {
  const alerts = [];
  const elements = {
    'group-expense-amount': { value: '12.50' },
    'group-expense-note': { value: '场地费' },
    'group-expense-link-session': { checked: false },
    'btn-confirm-group-expense': { disabled: false },
    'modal-group-expense': { closed: false, classList: { remove() { elements['modal-group-expense'].closed = true; } } },
  };
  const context = vm.createContext({
    document: {
      addEventListener() {},
      getElementById(id) { return elements[id]; },
    },
    localStorage: { getItem() { return null; } },
    fetch,
    alert(message) { alerts.push(message); },
    setTimeout,
    clearTimeout,
  });
  vm.runInContext(source, context);
  vm.runInContext("groupExpenseContext = { groupId: 2, source: 'group', sessionId: null }", context);
  return { context, elements, alerts };
}

async function main() {
  {
    const app = createApp(async () => { throw new TypeError('Failed to fetch'); });
    await vm.runInContext('createGroupPoolExpense()', app.context);
    assert.match(app.alerts[0], /未得到服务器确认/);
    assert.equal(app.elements['group-expense-amount'].value, '12.50');
    assert.equal(app.elements['group-expense-note'].value, '场地费');
    assert.equal(app.elements['modal-group-expense'].closed, false);
    assert.equal(app.elements['btn-confirm-group-expense'].disabled, false);
  }

  {
    let requests = 0;
    const app = createApp(async (url) => {
      requests++;
      if (url.endsWith('/expenses')) {
        return { ok: true, status: 200, text: async () => '{"success":true}' };
      }
      throw new TypeError('Failed to fetch');
    });
    await vm.runInContext('createGroupPoolExpense()', app.context);
    assert.equal(requests, 2);
    assert.equal(app.elements['modal-group-expense'].closed, true);
    assert.match(app.alerts[0], /支出已保存，但统计刷新失败/);
    assert.doesNotMatch(app.alerts[0], /Failed to fetch/);
  }

  {
    let resolveRequest;
    let requests = 0;
    const app = createApp(() => {
      requests++;
      return new Promise(resolve => { resolveRequest = resolve; });
    });
    const first = vm.runInContext('createGroupPoolExpense()', app.context);
    await vm.runInContext('createGroupPoolExpense()', app.context);
    assert.equal(requests, 1);
    assert.equal(app.elements['btn-confirm-group-expense'].disabled, true);
    resolveRequest({ ok: false, status: 400, text: async () => '{"error":"测试错误"}' });
    await first;
    assert.equal(app.elements['btn-confirm-group-expense'].disabled, false);
    assert.equal(app.alerts[0], '测试错误');
  }

  process.stdout.write('Expense network UI tests passed\n');
}

main().catch(error => {
  process.stderr.write(`${error.stack}\n`);
  process.exitCode = 1;
});

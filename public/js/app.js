// 全局状态
let currentUser = null;
let sessions = [];
let groups = [];
let currentSession = null;
let currentSessionPlayers = [];
let currentGroup = null;
let currentPlayer = null;
let selectedBuyinPlayerId = null;
let pageHistory = ['page-sessions'];
let accountPlayerHistory = [];
let toastTimer = null;

// API基础URL
const API_BASE = '/api';
const AUTH_TOKEN_STORAGE_KEY = 'pokernoteAuthToken';
const LAST_RAKE_RATE_STORAGE_KEY = 'pokernoteLastRakeRate';

// ==================== 工具函数 ====================

async function api(path, options = {}) {
  const token = getAuthToken();
  const headers = {
    'Content-Type': 'application/json',
    ...options.headers
  };
  if (token) {
    headers['X-PokerNote-Token'] = token;
  }

  const res = await fetch(API_BASE + path, {
    ...options,
    headers
  });
  const data = await res.json();
  if (!res.ok) {
    if (res.status === 401 && path !== '/login') {
      clearAuthToken();
      currentUser = null;
      if (path !== '/me') showPage('page-login');
    }
    const error = new Error(data.error || '请求失败');
    error.status = res.status;
    throw error;
  }
  return data;
}

function getAuthToken() {
  try {
    const token = localStorage.getItem(AUTH_TOKEN_STORAGE_KEY);
    return typeof token === 'string' && /^[a-f0-9]{64}$/i.test(token) ? token : null;
  } catch (error) {
    return null;
  }
}

function setAuthToken(token) {
  if (typeof token !== 'string' || !/^[a-f0-9]{64}$/i.test(token)) {
    throw new Error('服务器未返回有效的登录凭证');
  }
  try {
    localStorage.setItem(AUTH_TOKEN_STORAGE_KEY, token);
  } catch (error) {
    throw new Error('浏览器无法保存登录状态，请允许使用本地存储');
  }
}

function clearAuthToken() {
  try {
    localStorage.removeItem(AUTH_TOKEN_STORAGE_KEY);
  } catch (error) {
    // 无法访问存储时，当前页面仍会退出登录状态。
  }
}

function formatMoney(num) {
  return '¥' + (Math.round(num * 100) / 100).toFixed(2);
}

function roundMoney(num) {
  return Math.round((Number(num) + Number.EPSILON) * 100) / 100;
}

function formatRate(rate) {
  return (Math.round(Number(rate || 0) * 10000) / 10000) + '%';
}

function formatRake(num) {
  return '¥' + Math.ceil(Number(num || 0));
}

function formatPool(num) {
  const value = roundMoney(Number(num || 0));
  return value < 0 ? '-¥' + Math.abs(value).toFixed(2) : formatMoney(value);
}

function formatPoolAdjustment(num) {
  const value = roundMoney(Number(num || 0));
  if (value > 0) return '+¥' + value.toFixed(2);
  if (value < 0) return '-¥' + Math.abs(value).toFixed(2);
  return '¥0.00';
}

function renderPoolAdjustment(prefix, adjustment, isPending = false) {
  const row = document.getElementById(prefix + '-error-row');
  const label = document.getElementById(prefix + '-error-label');
  const amount = document.getElementById(prefix + '-error');
  const value = roundMoney(Number(adjustment || 0));

  row.classList.remove('positive', 'negative', 'pending');
  if (isPending) {
    label.textContent = '水池误差调整';
    amount.textContent = '待全部结算';
    row.classList.add('pending');
    return;
  }

  label.textContent = value > 0
    ? '水池误差调整（计入）'
    : (value < 0 ? '水池误差调整（扣取）' : '水池误差调整');
  amount.textContent = formatPoolAdjustment(value);
  if (value > 0) row.classList.add('positive');
  if (value < 0) row.classList.add('negative');
}

function renderWaterPool(prefix, waterPool) {
  const value = roundMoney(Number(waterPool || 0));
  document.getElementById(prefix + '-water-pool').textContent = formatPool(value);
  document.getElementById(prefix + '-water-pool-row').classList.toggle('negative', value < 0);
}

function escapeHtml(value) {
  return String(value)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}

function canInput(accessLevel) {
  return accessLevel === 'owner' || accessLevel === 'input';
}

function showToast(message) {
  const toast = document.getElementById('app-toast');
  if (!toast) return;

  if (toastTimer !== null) clearTimeout(toastTimer);
  toast.textContent = message;
  toast.hidden = false;
  toast.classList.remove('show');
  void toast.offsetWidth;
  toast.classList.add('show');
  toastTimer = setTimeout(() => {
    toast.classList.remove('show');
    toast.hidden = true;
    toastTimer = null;
  }, 2200);
}

function calculatePlayerResult(totalBuyin, finalBalance, rakeRate) {
  if (finalBalance === null || finalBalance === undefined) {
    return { grossProfitLoss: null, rake: null, profitLoss: null };
  }

  const grossProfitLoss = roundMoney(Number(finalBalance) - Number(totalBuyin));
  const rake = grossProfitLoss > 0
    ? Math.ceil(grossProfitLoss * Number(rakeRate || 0) / 100)
    : 0;

  return {
    grossProfitLoss,
    rake,
    profitLoss: roundMoney(grossProfitLoss - rake)
  };
}

// 历史玩家姓名
function playerHistoryStorageKey() {
  return currentUser && currentUser.userId ? 'playerNames:' + currentUser.userId : 'playerNames';
}

function getLocalPlayerHistory() {
  try {
    const storageKey = playerHistoryStorageKey();
    let storedHistory = localStorage.getItem(storageKey);
    if (storedHistory === null && storageKey !== 'playerNames') {
      storedHistory = localStorage.getItem('playerNames');
      if (storedHistory !== null) {
        localStorage.setItem(storageKey, storedHistory);
        localStorage.removeItem('playerNames');
      }
    }
    const history = JSON.parse(storedHistory || '[]');
    return Array.isArray(history) ? history.filter(name => typeof name === 'string' && name.trim() !== '') : [];
  } catch (error) {
    return [];
  }
}

function getPlayerHistory() {
  const names = [...getLocalPlayerHistory(), ...accountPlayerHistory];
  const seen = new Set();
  return names.filter(name => {
    const key = name.trim().toLocaleLowerCase();
    if (!key || seen.has(key)) return false;
    seen.add(key);
    return true;
  });
}

function savePlayerName(name) {
  const normalizedName = name.trim();
  accountPlayerHistory = [normalizedName, ...accountPlayerHistory].filter(
    (item, index, names) => names.findIndex(
      candidate => candidate.toLocaleLowerCase() === item.toLocaleLowerCase()
    ) === index
  );
  const history = getLocalPlayerHistory().filter(
    item => item.toLocaleLowerCase() !== normalizedName.toLocaleLowerCase()
  );
  history.unshift(normalizedName);
  try {
    localStorage.setItem(playerHistoryStorageKey(), JSON.stringify(history.slice(0, 20)));
  } catch (error) {
    // 部分手机隐私模式会禁用 localStorage，账号历史仍可正常使用。
  }
  updatePlayerHistoryList();
}

function updatePlayerHistoryList() {
  const input = document.getElementById('player-name');
  if (input && document.activeElement === input) {
    showPlayerHistorySuggestions();
  }
}

async function loadAccountPlayerHistory() {
  try {
    accountPlayerHistory = await api('/player-names');
  } catch (error) {
    accountPlayerHistory = [];
  }
  updatePlayerHistoryList();
}

function showPlayerHistorySuggestions() {
  const input = document.getElementById('player-name');
  const suggestions = document.getElementById('player-history-suggestions');
  if (!input || !suggestions) return;

  const query = input.value.trim().toLocaleLowerCase();
  const history = getPlayerHistory();
  const matches = history.filter(name => name.toLocaleLowerCase().includes(query)).slice(0, 10);
  if (matches.length === 0) {
    hidePlayerHistorySuggestions();
    return;
  }

  suggestions.innerHTML = matches.map(name => `
    <button type="button" class="player-history-option" role="option" data-player-name="${escapeHtml(name)}">
      <span class="player-history-icon" aria-hidden="true">↺</span>
      <span class="player-history-name">${escapeHtml(name)}</span>
    </button>
  `).join('');
  suggestions.querySelectorAll('.player-history-option').forEach(option => {
    option.addEventListener('mousedown', event => event.preventDefault());
    option.addEventListener('click', () => selectPlayerHistoryName(option.dataset.playerName || ''));
  });
  suggestions.hidden = false;
  input.setAttribute('aria-expanded', 'true');
}

function hidePlayerHistorySuggestions() {
  const input = document.getElementById('player-name');
  const suggestions = document.getElementById('player-history-suggestions');
  if (suggestions) suggestions.hidden = true;
  if (input) input.setAttribute('aria-expanded', 'false');
}

function selectPlayerHistoryName(name) {
  const input = document.getElementById('player-name');
  if (!input || !name) return;
  input.value = name;
  input.focus();
  hidePlayerHistorySuggestions();
}

function showPage(pageId) {
  document.querySelectorAll('.page').forEach(p => p.classList.remove('active'));
  document.getElementById(pageId).classList.add('active');
  pageHistory.push(pageId);
}

function goBack() {
  pageHistory.pop();
  const prevPage = pageHistory[pageHistory.length - 1] || 'page-sessions';
  document.querySelectorAll('.page').forEach(p => p.classList.remove('active'));
  document.getElementById(prevPage).classList.add('active');
}

function openModal(modalId) {
  document.getElementById(modalId).classList.add('active');
}

function closeModal(modalId) {
  document.getElementById(modalId).classList.remove('active');
}

// ==================== 认证相关 ====================

async function checkAuth() {
  if (!getAuthToken()) {
    currentUser = null;
    showPage('page-login');
    return;
  }

  try {
    currentUser = await api('/me');
    showPage('page-sessions');
    await loadGroups();
    await loadSessions();
    await loadAccountPlayerHistory();
  } catch (err) {
    if (err.status === 401) clearAuthToken();
    currentUser = null;
    showPage('page-login');
    if (err.status !== 401) alert(err.message);
  }
}

async function login(e) {
  e.preventDefault();
  const email = document.getElementById('login-email').value;
  const password = document.getElementById('login-password').value;
  try {
    const result = await api('/login', {
      method: 'POST',
      body: JSON.stringify({ email, password })
    });
    setAuthToken(result.token);
    await checkAuth();
  } catch (err) {
    alert(err.message);
  }
}

async function register(e) {
  e.preventDefault();
  const email = document.getElementById('reg-email').value;
  const password = document.getElementById('reg-password').value;
  try {
    const result = await api('/register', {
      method: 'POST',
      body: JSON.stringify({ email, password })
    });
    setAuthToken(result.token);
    await checkAuth();
  } catch (err) {
    if (err.message.includes('已注册') || err.message.includes('already')) {
      // 邮箱已存在，切换到登录页面
      alert('该邮箱已注册，请直接登录');
      document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
      document.querySelector('.tab[data-tab="login"]').classList.add('active');
      document.getElementById('form-login').style.display = 'block';
      document.getElementById('form-register').style.display = 'none';
      document.getElementById('login-email').value = email;
    } else {
      alert(err.message);
    }
  }
}

async function logout() {
  try {
    await api('/logout', { method: 'POST' });
  } finally {
    clearAuthToken();
    currentUser = null;
    currentSessionPlayers = [];
    accountPlayerHistory = [];
    showPage('page-login');
  }
}

// ==================== 场次相关 ====================

async function loadGroups() {
  groups = await api('/groups');
  updateGroupSelect('session-create-group');
  updateGroupSelect('session-group-select', currentSession ? currentSession.groupId : null);
}

function updateGroupSelect(selectId, selectedId = null) {
  const select = document.getElementById(selectId);
  if (!select) return;

  const selectableGroups = groups.filter(group => canInput(group.access_level));
  const fallback = selectableGroups.find(group => group.is_default && group.access_level === 'owner') || selectableGroups[0];
  const targetId = selectedId || (fallback ? fallback.id : null);
  select.innerHTML = selectableGroups.map(group => `
    <option value="${group.id}" ${Number(group.id) === Number(targetId) ? 'selected' : ''}>
      ${escapeHtml(group.name)}${group.is_default && group.access_level === 'owner' ? '（默认）' : ''}${group.access_level !== 'owner' ? '（共享录入）' : ''}
    </option>
  `).join('');
}

async function loadSessions() {
  sessions = await api('/sessions');
  const list = document.getElementById('sessions-list');

  if (groups.length === 0) {
    list.innerHTML = '<div class="empty-state">暂无分组</div>';
    return;
  }

  list.innerHTML = groups.map(group => {
    const groupedSessions = sessions.filter(session => Number(session.group_id) === Number(group.id));
    const sessionItems = groupedSessions.length > 0
      ? groupedSessions.map(session => `
          <div class="list-item" onclick="openSession(${session.id})">
            <div class="info">
              <div class="name">${escapeHtml(session.name)}</div>
              <div class="meta">${session.player_count}人 · 抽水 ${formatRate(session.rake_rate)} · ${new Date(session.created_at).toLocaleDateString()}</div>
            </div>
            ${session.settled_count > 0 ? '<span class="settled-badge">已结算</span>' : ''}
            ${canInput(session.access_level) ? `<button class="delete-btn" onclick="event.stopPropagation(); deleteSession(${session.id})">🗑️</button>` : ''}
          </div>
        `).join('')
      : '<div class="group-empty">该分组暂无场次</div>';

    return `
      <section class="session-group">
        <div class="session-group-header">
          <div>
            <div class="session-group-name">
              ${escapeHtml(group.name)}
              ${group.is_default && group.access_level === 'owner' ? '<span class="default-group-badge">默认</span>' : ''}
              ${group.access_level !== 'owner' ? `<span class="shared-group-badge">共享${group.access_level === 'input' ? '录入' : '查看'}</span>` : ''}
            </div>
            <div class="session-group-count">${groupedSessions.length} 场${group.access_level !== 'owner' ? ` · 来自 ${escapeHtml(group.owner_email)}` : ''}</div>
          </div>
          <div class="session-group-actions">
            <button class="btn group-stats-btn" onclick="showGroupStats(${group.id})">分组统计 →</button>
            ${group.access_level === 'owner' && !group.is_default && groupedSessions.length === 0
              ? `<button class="btn group-delete-btn" onclick="deleteGroup(${group.id})" aria-label="删除空分组">删除</button>`
              : ''}
          </div>
        </div>
        <div class="list">${sessionItems}</div>
      </section>
    `;
  }).join('');
}

async function deleteGroup(id) {
  const group = groups.find(item => Number(item.id) === Number(id));
  if (!group || group.access_level !== 'owner' || group.is_default || Number(group.session_count) !== 0) {
    return;
  }
  if (!confirm(`确定删除空分组“${group.name}”？`)) return;

  try {
    await api('/groups/' + id, { method: 'DELETE' });
    await loadGroups();
    await loadSessions();
    showToast(`已删除分组“${group.name}”`);
  } catch (err) {
    alert(err.message);
  }
}

async function createGroup() {
  const input = document.getElementById('group-name');
  const name = input.value.trim();
  if (!name) {
    alert('请输入分组名称');
    return;
  }

  try {
    await api('/groups', {
      method: 'POST',
      body: JSON.stringify({ name })
    });
    input.value = '';
    closeModal('modal-group');
    await loadGroups();
    await loadSessions();
  } catch (err) {
    alert(err.message);
  }
}

function defaultSessionName() {
  const now = new Date();
  const month = String(now.getMonth() + 1).padStart(2, '0');
  const day = String(now.getDate()).padStart(2, '0');
  return month + day;
}

function lastRakeRateStorageKey() {
  return currentUser && currentUser.userId
    ? `${LAST_RAKE_RATE_STORAGE_KEY}:${currentUser.userId}`
    : LAST_RAKE_RATE_STORAGE_KEY;
}

function rememberRakeRate(rakeRate) {
  try {
    localStorage.setItem(lastRakeRateStorageKey(), String(rakeRate));
  } catch (error) {
    // 存储不可用时仍可正常创建场次。
  }
}

function getLastRakeRate() {
  try {
    const stored = localStorage.getItem(lastRakeRateStorageKey());
    if (stored !== null) {
      const value = Number(stored);
      if (Number.isFinite(value) && value >= 0 && value <= 100) return value;
    }
  } catch (error) {
    // 回退到最近创建的场次。
  }

  const latestSession = sessions[0];
  const latestRate = latestSession ? Number(latestSession.rake_rate) : 0;
  return Number.isFinite(latestRate) ? latestRate : 0;
}

async function openCreateSessionPage() {
  if (groups.length === 0) await loadGroups();
  document.getElementById('session-name').value = defaultSessionName();
  document.getElementById('session-create-rake-rate').value = getLastRakeRate();
  document.getElementById('session-create-buyin').value = '';
  updateGroupSelect('session-create-group');
  renderCreatePlayerOptions();
  showPage('page-session-create');
  document.getElementById('session-name').focus();
  document.getElementById('session-name').select();
}

function renderCreatePlayerOptions() {
  const list = document.getElementById('session-create-player-list');
  const names = getPlayerHistory();
  if (names.length === 0) {
    list.innerHTML = '<div class="create-player-empty">暂无历史玩家，可先创建空场次后在场次内添加玩家</div>';
    updateCreatePlayerCount();
    return;
  }

  list.innerHTML = names.map(name => `
    <label class="create-player-option">
      <input type="checkbox" value="${escapeHtml(name)}">
      <span class="create-player-check" aria-hidden="true">✓</span>
      <span class="create-player-name">${escapeHtml(name)}</span>
    </label>
  `).join('');
  list.querySelectorAll('input[type="checkbox"]').forEach(checkbox => {
    checkbox.addEventListener('change', () => {
      checkbox.closest('.create-player-option').classList.toggle('selected', checkbox.checked);
      updateCreatePlayerCount();
    });
  });
  updateCreatePlayerCount();
}

function updateCreatePlayerCount() {
  const count = document.querySelectorAll('#session-create-player-list input:checked').length;
  document.getElementById('session-create-player-count').textContent = `已选 ${count} 人`;
}

function setCreatePlayersSelected(selected) {
  document.querySelectorAll('#session-create-player-list input[type="checkbox"]').forEach(checkbox => {
    checkbox.checked = selected;
    checkbox.closest('.create-player-option').classList.toggle('selected', selected);
  });
  updateCreatePlayerCount();
}

function selectAllCreatePlayers() {
  setCreatePlayersSelected(true);
}

function clearCreatePlayers() {
  setCreatePlayersSelected(false);
}

async function createSession() {
  const name = document.getElementById('session-name').value.trim();
  const rakeRate = parseFloat(document.getElementById('session-create-rake-rate').value);
  const groupId = parseInt(document.getElementById('session-create-group').value, 10);
  const buyinValue = document.getElementById('session-create-buyin').value.trim();
  const initialBuyin = buyinValue === '' ? 0 : parseFloat(buyinValue);
  const playerNames = Array.from(
    document.querySelectorAll('#session-create-player-list input:checked')
  ).map(checkbox => checkbox.value);
  if (!name) {
    alert('请输入场次名称');
    return;
  }
  if (isNaN(rakeRate) || rakeRate < 0 || rakeRate > 100) {
    alert('抽水比例必须在0到100之间');
    return;
  }
  if (!groupId) {
    alert('请选择分组');
    return;
  }
  if (!Number.isFinite(initialBuyin) || initialBuyin < 0) {
    alert('请输入有效的统一带入金额');
    return;
  }
  if (playerNames.length > 0 && initialBuyin <= 0) {
    alert('选择玩家后，统一带入金额必须大于0');
    return;
  }

  const submitButton = document.getElementById('session-create-submit');
  submitButton.disabled = true;
  submitButton.textContent = '正在创建…';
  
  try {
    const result = await api('/sessions', {
      method: 'POST',
      body: JSON.stringify({ name, rakeRate, groupId, initialBuyin, playerNames })
    });
    rememberRakeRate(rakeRate);
    await loadAccountPlayerHistory();
    await loadGroups();
    await loadSessions();
    if (pageHistory[pageHistory.length - 1] === 'page-session-create') {
      pageHistory.pop();
    }
    await openSession(result.sessionId);
  } catch (err) {
    alert(err.message);
  } finally {
    submitButton.disabled = false;
    submitButton.textContent = '保存并创建';
  }
}

async function deleteSession(id) {
  if (!confirm('确定删除这个场次？')) return;
  await api('/sessions/' + id, { method: 'DELETE' });
  await loadGroups();
  await loadSessions();
}

async function openSession(id) {
  const session = sessions.find(item => Number(item.id) === Number(id));
  currentSession = session
    ? { ...session, groupId: session.group_id, rakeRate: session.rake_rate }
    : { id };
  document.getElementById('session-title').textContent = session ? session.name : '场次详情';
  updatePlayerHistoryList(); // 加载历史姓名
  showPage('page-session');
  await loadPlayers();
}

async function loadPlayers() {
  const data = await api('/sessions/' + currentSession.id);
  currentSessionPlayers = Array.isArray(data.players) ? data.players : [];
  const list = document.getElementById('players-list');
  const rakeRate = Number(data.rake_rate || 0);
  currentSession.rakeRate = rakeRate;
  currentSession.name = data.name;
  currentSession.groupId = data.group_id;
  currentSession.groupName = data.group_name;
  currentSession.accessLevel = data.access_level;
  currentSession.groupOwnerEmail = data.group_owner_email;
  const editable = canInput(data.access_level);
  document.getElementById('session-group-controls').hidden = data.access_level !== 'owner';
  document.getElementById('session-rake-controls').hidden = !editable;
  document.getElementById('session-player-entry').hidden = !editable;
  document.getElementById('session-buyin-action').hidden = !editable;
  document.getElementById('session-title').textContent = data.name;
  document.getElementById('session-rake-rate').value = rakeRate;
  document.getElementById('session-rake-summary').textContent = formatRate(rakeRate);
  document.getElementById('session-group-summary').textContent = (data.group_name || '默认分组')
    + (data.access_level !== 'owner' ? `（共享${data.access_level === 'input' ? '录入' : '查看'}）` : '');
  updateGroupSelect('session-group-select', data.group_id);

  const settledPlayers = data.players.filter(player => player.final_balance !== null);
  const finalRakeSetting = document.getElementById('session-final-rake-setting');
  const finalPoolInput = document.getElementById('session-final-pool');
  const finalPoolSave = document.getElementById('session-final-pool-save');
  const isFullySettled = data.is_fully_settled === true;
  finalRakeSetting.hidden = !isFullySettled;
  if (isFullySettled) {
    const calculatedRake = Number(data.calculated_rake || 0);
    const poolAdjustment = Number(data.water_pool_adjustment || 0);
    const calculatedPool = Number(data.calculated_water_pool ?? roundMoney(calculatedRake + poolAdjustment));
    const effectivePool = Number(data.water_pool ?? calculatedPool);
    currentSession.finalRake = data.final_rake;
    currentSession.finalPool = effectivePool;
    currentSession.waterPoolAdjustment = poolAdjustment;
    currentSession.calculatedRake = calculatedRake;
    finalPoolInput.value = String(roundMoney(effectivePool));
    finalPoolInput.disabled = !editable;
    finalPoolSave.hidden = !editable;
    document.getElementById('session-final-rake-note').textContent = data.rake_overridden
      ? `系统应入池 ${formatPool(calculatedPool)}（抽水 ${formatRake(calculatedRake)}，误差 ${formatPoolAdjustment(poolAdjustment)}），当前使用已保存金额`
      : `已含抽水 ${formatRake(calculatedRake)} 和误差 ${formatPoolAdjustment(poolAdjustment)}`;
  }
  const errorSummary = document.getElementById('session-error-summary');
  if (settledPlayers.length > 0) {
    const totalBuyin = data.players.reduce(
      (sum, player) => sum + Number(player.total_buyin_recorded ?? player.total_buyin ?? 0),
      0
    );
    const totalSettled = settledPlayers.reduce((sum, player) => sum + Number(player.final_balance || 0), 0);
    const settlementError = roundMoney(totalBuyin - totalSettled);
    document.getElementById('session-error-amount').textContent = formatPool(settlementError);
    errorSummary.classList.toggle('balanced', settlementError === 0);
    errorSummary.hidden = false;
  } else {
    errorSummary.hidden = true;
    errorSummary.classList.remove('balanced');
  }
  
  if (data.players.length === 0) {
    list.innerHTML = `<div class="empty-state">${editable ? '暂无玩家，请添加' : '暂无玩家'}</div>`;
    return;
  }
  
  list.innerHTML = data.players.map(p => {
    const isSettled = p.final_balance !== null;
    let resultHtml = '';
    
    if (isSettled) {
      const recordedBuyin = Number(p.total_buyin_recorded ?? p.total_buyin ?? 0);
      const result = calculatePlayerResult(recordedBuyin, p.final_balance, rakeRate);
      const profit = result.profitLoss;
      const profitClass = profit >= 0 ? 'player-win' : 'player-loss';
      const profitText = profit >= 0 ? `净水上${formatMoney(profit)}` : `水下${formatMoney(Math.abs(profit))}`;
      const grossLabel = result.grossProfitLoss >= 0 ? '赢' : '输';
      resultHtml = `
        <div class="player-result">
          <div class="amount ${profitClass}">${profitText}</div>
          <div class="settlement-breakdown">${grossLabel}：${formatMoney(Math.abs(result.grossProfitLoss))}，抽水：${formatRake(result.rake)}</div>
        </div>
      `;
    } else {
      resultHtml = `<span class="meta">累计: ${formatMoney(p.total_buyin)}</span>`;
    }
    
    return `
      <div class="list-item" onclick="openPlayer(${p.id})">
        <div class="info">
          <div class="name">
            ${escapeHtml(p.name)}
            ${isSettled ? '<span class="settled-badge">已结算</span>' : ''}
          </div>
          <div class="meta">${isSettled ? '' : '累计: ' + formatMoney(p.total_buyin)}</div>
        </div>
        ${resultHtml}
        ${editable ? `<button class="delete-btn" onclick="event.stopPropagation(); deletePlayer(${p.id})">🗑️</button>` : ''}
      </div>
    `;
  }).join('');
}

async function saveSessionGroup() {
  const groupId = parseInt(document.getElementById('session-group-select').value, 10);
  if (!groupId) {
    alert('请选择分组');
    return;
  }

  try {
    const result = await api('/sessions/' + currentSession.id, {
      method: 'PATCH',
      body: JSON.stringify({ groupId })
    });
    currentSession.groupId = result.groupId;
    currentSession.groupName = result.groupName;
    document.getElementById('session-group-summary').textContent = result.groupName;
    await loadGroups();
    await loadSessions();
    showToast(`场次已切换到“${result.groupName}”`);
  } catch (err) {
    alert(err.message);
  }
}

async function saveRakeRate() {
  const input = document.getElementById('session-rake-rate');
  const rakeRate = parseFloat(input.value);
  if (isNaN(rakeRate) || rakeRate < 0 || rakeRate > 100) {
    alert('抽水比例必须在0到100之间');
    return;
  }

  try {
    const result = await api('/sessions/' + currentSession.id, {
      method: 'PATCH',
      body: JSON.stringify({ rakeRate })
    });
    currentSession.rakeRate = Number(result.rakeRate);
    rememberRakeRate(result.rakeRate);
    input.value = result.rakeRate;
    document.getElementById('session-rake-summary').textContent = formatRate(result.rakeRate);
    await loadPlayers();
    await loadSessions();
  } catch (err) {
    alert(err.message);
  }
}

async function saveFinalPool() {
  const input = document.getElementById('session-final-pool');
  const rawFinalPool = Number(input.value);
  const finalPool = roundMoney(rawFinalPool);
  if (!Number.isFinite(rawFinalPool) || Math.abs(rawFinalPool - finalPool) > 0.000001) {
    alert('最终入池金额必须是最多保留2位小数的有效金额');
    return;
  }
  const impliedRake = roundMoney(finalPool - Number(currentSession.waterPoolAdjustment || 0));
  if (impliedRake < 0 || !Number.isInteger(impliedRake)) {
    alert('最终入池金额扣除结算误差后，抽水部分必须是大于等于0的整数');
    return;
  }

  try {
    const result = await api('/sessions/' + currentSession.id, {
      method: 'PATCH',
      body: JSON.stringify({ finalPool })
    });
    currentSession.finalRake = result.finalRake;
    currentSession.finalPool = result.finalPool;
    await loadPlayers();
    await loadSessions();
    showToast(`最终入池金额已保存为 ${formatPool(result.finalPool)}`);
  } catch (err) {
    alert(err.message);
  }
}

async function deletePlayer(id) {
  if (!confirm('确定删除这个玩家？')) return;
  await api('/players/' + id, { method: 'DELETE' });
  loadPlayers();
}

// ==================== 买入相关 ====================

// 打开买入弹窗
async function openBuyinModal() {
  const data = await api('/sessions/' + currentSession.id);
  currentSessionPlayers = Array.isArray(data.players) ? data.players : [];
  const unsettledPlayers = data.players.filter(p => p.final_balance === null);
  
  if (unsettledPlayers.length === 0) {
    alert('暂无未结算的玩家');
    return;
  }
  
  const list = document.getElementById('buyin-player-list');
  list.innerHTML = unsettledPlayers.map(p => `
    <div class="buyin-player-item" onclick="selectBuyinPlayer(${p.id}, this)">
      <div>
        <div class="player-name">${escapeHtml(p.name)}</div>
        <div class="player-buyin">累计: ${formatMoney(p.total_buyin)}</div>
      </div>
      <span class="check" style="display:none;">✓</span>
    </div>
  `).join('');
  
  selectedBuyinPlayerId = null;
  document.getElementById('buyin-amount-input').value = '';
  openModal('modal-buyin');
}

// 选择买入玩家
function selectBuyinPlayer(id, element) {
  document.querySelectorAll('.buyin-player-item').forEach(el => {
    el.classList.remove('selected');
    el.querySelector('.check').style.display = 'none';
  });
  element.classList.add('selected');
  element.querySelector('.check').style.display = 'block';
  selectedBuyinPlayerId = id;
}

// 确认买入
async function confirmBuyin() {
  if (!selectedBuyinPlayerId) {
    alert('请选择玩家');
    return;
  }
  
  const amount = parseFloat(document.getElementById('buyin-amount-input').value);
  if (!amount || amount <= 0) {
    alert('请输入有效金额');
    return;
  }
  
  try {
    await api('/players/' + selectedBuyinPlayerId + '/buyin', {
      method: 'POST',
      body: JSON.stringify({ amount })
    });
    closeModal('modal-buyin');
    loadPlayers(); // 刷新列表
  } catch (err) {
    alert(err.message);
  }
}

// ==================== 玩家相关 ====================

async function addPlayer() {
  const name = document.getElementById('player-name').value.trim();
  const initialBuyin = parseFloat(document.getElementById('player-initial').value) || 0;
  
  if (!name) {
    alert('请输入玩家姓名');
    return;
  }
  
  // 检查同名
  const data = await api('/sessions/' + currentSession.id);
  const exists = data.players.some(p => p.name.toLowerCase() === name.toLowerCase());
  if (exists) {
    alert('该玩家已存在');
    return;
  }
  
  try {
    await api('/sessions/' + currentSession.id + '/players', {
      method: 'POST',
      body: JSON.stringify({ name, initialBuyin })
    });
    savePlayerName(name); // 保存姓名到历史
    document.getElementById('player-name').value = '';
    hidePlayerHistorySuggestions();
    loadPlayers();
  } catch (err) {
    alert(err.message);
  }
}

async function openPlayer(id) {
  const player = currentSessionPlayers.find(item => Number(item.id) === Number(id));
  if (!player) return;
  const name = player.name;
  currentPlayer = { id, name };
  document.getElementById('player-title').textContent = name;
  const editable = currentSession && canInput(currentSession.accessLevel);
  document.getElementById('player-buyin-entry').hidden = !editable;
  document.getElementById('player-settlement-entry').hidden = !editable;
  showPage('page-player');
  loadPlayerDetail();
}

async function loadPlayerDetail() {
  const data = await api('/players/' + currentPlayer.id + '/buyins');
  const buyins = data;
  
  // 计算总买入
  const totalBuyin = buyins.reduce((sum, b) => sum + b.amount, 0);
  
  // 获取玩家信息
  const playerInfo = await api('/sessions/' + currentSession.id);
  const player = playerInfo.players.find(p => p.id === currentPlayer.id);
  const finalBalance = player ? player.final_balance : null;
  const rakeRate = Number(playerInfo.rake_rate || 0);
  const result = calculatePlayerResult(totalBuyin, finalBalance, rakeRate);
  currentSession.rakeRate = rakeRate;
  
  // 更新统计
  document.getElementById('player-total-buyin').textContent = formatMoney(totalBuyin);
  document.getElementById('player-final').textContent = finalBalance !== null ? formatMoney(finalBalance) : '-';
  document.getElementById('player-rake').textContent = result.rake !== null ? formatRake(result.rake) : '-';
  document.getElementById('settlement-rake-note').textContent = `盈利玩家按本场 ${formatRate(rakeRate)} 抽水，亏损玩家不抽水`;
  
  if (finalBalance !== null) {
    const profit = result.profitLoss;
    const profitEl = document.getElementById('player-profit');
    const profitClass = profit >= 0 ? 'profit' : 'loss';
    const profitText = profit >= 0 ? `净水上${formatMoney(profit)}` : `水下${formatMoney(Math.abs(profit))}`;
    profitEl.textContent = profitText;
    profitEl.className = 'value ' + profitClass;
  } else {
    document.getElementById('player-profit').textContent = '-';
    document.getElementById('player-profit').className = 'value';
  }
  
  // 显示买入记录
  const list = document.getElementById('buyins-list');
  list.innerHTML = buyins.map((b, i) => `
    <div class="list-item small">
      <div class="info">
        <div class="name">第 ${i + 1} 次买入</div>
        <div class="meta">${new Date(b.created_at).toLocaleString()}</div>
      </div>
      <span class="amount">${formatMoney(b.amount)}</span>
    </div>
  `).join('') || '<div class="empty-state">暂无买入记录</div>';
}

async function addBuyin() {
  const amount = parseFloat(document.getElementById('buyin-amount').value);
  if (!amount || amount <= 0) {
    alert('请输入有效金额');
    return;
  }
  
  try {
    await api('/players/' + currentPlayer.id + '/buyin', {
      method: 'POST',
      body: JSON.stringify({ amount })
    });
    document.getElementById('buyin-amount').value = '';
    loadPlayerDetail();
    loadPlayers(); // 刷新玩家列表
  } catch (err) {
    alert(err.message);
  }
}

async function settle(type) {
  let finalBalance;
  
  if (type === 'balance') {
    finalBalance = parseFloat(document.getElementById('settle-balance').value);
    if (isNaN(finalBalance)) {
      alert('请输入结余金额');
      return;
    }
  } else if (type === 'profit') {
    const profit = parseFloat(document.getElementById('settle-profit').value);
    if (isNaN(profit)) {
      alert('请输入水上金额');
      return;
    }
    // 盈利为正
    const totalBuyin = parseFloat(document.getElementById('player-total-buyin').textContent.replace('¥', ''));
    finalBalance = totalBuyin + profit;
  } else if (type === 'loss') {
    const loss = parseFloat(document.getElementById('settle-profit').value);
    if (isNaN(loss)) {
      alert('请输入水下金额');
      return;
    }
    // 水下为负
    const totalBuyin = parseFloat(document.getElementById('player-total-buyin').textContent.replace('¥', ''));
    finalBalance = totalBuyin - loss;
  }
  
  try {
    await api('/players/' + currentPlayer.id + '/settle', {
      method: 'POST',
      body: JSON.stringify({ finalBalance })
    });
    document.getElementById('settle-balance').value = '';
    document.getElementById('settle-profit').value = '';
    loadPlayerDetail();
    loadPlayers();
    showPage('page-session'); // 返回列表页
  } catch (err) {
    alert(err.message);
  }
}

// ==================== 统计相关 ====================

async function showSessionStats() {
  try {
    const data = await api('/sessions/' + currentSession.id + '/stats');
    
    document.getElementById('stat-total-buyin').textContent = formatMoney(data.totalBuyins);
    document.getElementById('stat-total-settled').textContent = formatMoney(data.totalSettled);
    document.getElementById('stat-rake-rate').textContent = formatRate(data.rakeRate);
    document.getElementById('stat-total-rake-label').textContent = data.isRakeOverridden
      ? '最终抽水（手动）'
      : '盈利玩家抽水';
    document.getElementById('stat-total-rake').textContent = formatRake(data.totalRake);
    renderWaterPool('stat', data.waterPool);
    document.getElementById('stat-net-settled').textContent = formatMoney(data.totalNetSettled);
    renderPoolAdjustment('stat', data.waterPoolAdjustment, !data.isFullySettled);
    
    const list = document.getElementById('stats-list');
    list.innerHTML = data.players.map(p => {
      let profitText = '-';
      let profitClass = '';
      
      if (p.profitLoss !== null) {
        if (p.profitLoss >= 0) {
          profitText = `净水上${formatMoney(p.profitLoss)}`;
          profitClass = 'profit';
        } else {
          profitText = `水下${formatMoney(Math.abs(p.profitLoss))}`;
          profitClass = 'loss';
        }
      }
      
      return `
        <div class="list-item">
          <div class="info">
            <div class="name">${escapeHtml(p.name)}</div>
            <div class="meta">买入 ${formatMoney(p.buyin)}${p.final !== null ? ` · 结余 ${formatMoney(p.final)}` : ''}</div>
          </div>
          <div class="player-result">
            <div class="amount ${profitClass}">${profitText}</div>
            ${p.rake > 0 ? `<div class="rake-note">毛盈利 ${formatMoney(p.grossProfitLoss)} · 抽水 ${formatRake(p.rake)}</div>` : ''}
          </div>
        </div>
      `;
    }).join('');
    
    showPage('page-stats');
  } catch (err) {
    alert(err.message);
  }
}

async function showGroupStats(groupId, navigate = true) {
  try {
    const data = await api('/groups/' + groupId + '/stats');
    currentGroup = data.group;
    const editable = canInput(data.group.access_level);
    document.getElementById('btn-group-share').hidden = data.group.access_level !== 'owner';
    document.getElementById('btn-add-group-expense').hidden = !editable;
    document.getElementById('group-stats-title').textContent = data.group.name;
    document.getElementById('group-stat-access').textContent = data.group.access_level === 'owner'
      ? '我创建的分组'
      : `来自 ${data.group.owner_email} · 共享${data.group.access_level === 'input' ? '录入' : '查看'}`;
    document.getElementById('group-stat-session-count').textContent = data.sessionCount + ' 场';
    document.getElementById('group-stat-total-buyin').textContent = formatMoney(data.totalBuyins);
    document.getElementById('group-stat-total-settled').textContent = formatMoney(data.totalSettled);
    document.getElementById('group-stat-total-rake').textContent = formatRake(data.totalRake);
    document.getElementById('group-stat-pool-expenses').textContent = formatPool(-Number(data.totalPoolExpenses || 0));
    renderWaterPool('group-stat', data.waterPool);
    document.getElementById('group-stat-net-settled').textContent = formatMoney(data.totalNetSettled);
    renderPoolAdjustment('group-stat', data.waterPoolAdjustment);

    const sessionList = document.getElementById('group-session-list');
    sessionList.innerHTML = data.sessions.length > 0
      ? data.sessions.map(session => `
          <div class="list-item group-session-stat" onclick="openSession(${session.id})">
            <div class="info">
              <div class="name">${escapeHtml(session.name)}</div>
              <div class="meta">${session.playerCount}人 · ${session.settledCount}人已结算 · ${session.isFullySettled ? `最终抽水 ${formatRake(session.totalRake)}` : `抽水 ${formatRate(session.rakeRate)}`}</div>
            </div>
            <div class="player-result">
              <div class="amount">${formatMoney(session.totalNetSettled)}</div>
              <div class="rake-note">水池 ${formatPool(session.waterPool)}</div>
            </div>
          </div>
        `).join('')
      : '<div class="empty-state">该分组暂无场次</div>';

    const expenseList = document.getElementById('group-expense-list');
    document.getElementById('group-expense-count').textContent = data.expenses.length + ' 笔';
    expenseList.innerHTML = data.expenses.length > 0
      ? data.expenses.map(expense => `
          <div class="list-item expense-item">
            <div class="info">
              <div class="name expense-note">${escapeHtml(expense.note)}</div>
              <div class="meta">${new Date(expense.created_at).toLocaleString()}</div>
            </div>
            <div class="amount">${formatPool(-Number(expense.amount))}</div>
            ${editable ? `<button class="delete-btn" onclick="deleteGroupPoolExpense(${expense.id})" aria-label="删除支出">🗑️</button>` : ''}
          </div>
        `).join('')
      : '<div class="empty-state">暂无水池支出</div>';

    const playerList = document.getElementById('group-player-list');
    playerList.innerHTML = data.players.length > 0
      ? data.players.map(player => {
          const grossResult = player.grossProfitLoss === null
            ? '待结算'
            : formatPoolAdjustment(player.grossProfitLoss);
          const grossResultClass = player.grossProfitLoss === null
            ? ''
            : (player.grossProfitLoss >= 0 ? 'player-win' : 'player-loss');
          let profitText = '-';
          let profitClass = '';
          if (player.profitLoss !== null) {
            profitText = player.profitLoss >= 0
              ? `净水上${formatMoney(player.profitLoss)}`
              : `水下${formatMoney(Math.abs(player.profitLoss))}`;
            profitClass = player.profitLoss >= 0 ? 'player-win' : 'player-loss';
          }

          return `
            <div class="list-item">
              <div class="info">
                <div class="name">${escapeHtml(player.name)}</div>
                <div class="meta">参与${player.sessionCount}场，水上${player.winningSessionCount}场，总战绩<span class="${grossResultClass}">${grossResult}</span></div>
              </div>
              <div class="player-result">
                <div class="amount ${profitClass}">${profitText}</div>
                ${player.rake > 0 ? `<div class="rake-note">累计抽水 ${formatRake(player.rake)}</div>` : ''}
              </div>
            </div>
          `;
        }).join('')
      : '<div class="empty-state">暂无玩家统计</div>';

    if (navigate) {
      document.getElementById('group-expense-details').open = false;
      showPage('page-group-stats');
    }
  } catch (err) {
    alert(err.message);
  }
}

function openGroupExpenseModal() {
  if (!currentGroup || !canInput(currentGroup.access_level)) return;
  document.getElementById('group-expense-amount').value = '';
  document.getElementById('group-expense-note').value = '';
  openModal('modal-group-expense');
}

async function createGroupPoolExpense() {
  if (!currentGroup) return;
  const amount = parseFloat(document.getElementById('group-expense-amount').value);
  const note = document.getElementById('group-expense-note').value.trim();
  if (!Number.isFinite(amount) || amount <= 0) {
    alert('请输入有效的支出金额');
    return;
  }
  if (!note) {
    alert('请输入支出备注');
    return;
  }

  try {
    await api('/groups/' + currentGroup.id + '/expenses', {
      method: 'POST',
      body: JSON.stringify({ amount, note })
    });
    closeModal('modal-group-expense');
    await showGroupStats(currentGroup.id, false);
  } catch (err) {
    alert(err.message);
  }
}

async function deleteGroupPoolExpense(expenseId) {
  if (!currentGroup || !confirm('确定删除这笔水池支出？')) return;
  try {
    await api('/group-expenses/' + expenseId, { method: 'DELETE' });
    await showGroupStats(currentGroup.id, false);
  } catch (err) {
    alert(err.message);
  }
}

function openChangePasswordModal() {
  document.getElementById('current-password').value = '';
  document.getElementById('new-password').value = '';
  document.getElementById('confirm-new-password').value = '';
  openModal('modal-change-password');
}

async function changePassword() {
  const currentPassword = document.getElementById('current-password').value;
  const newPassword = document.getElementById('new-password').value;
  const confirmation = document.getElementById('confirm-new-password').value;
  if (!currentPassword) {
    alert('请输入当前密码');
    return;
  }
  if (newPassword.length < 6) {
    alert('新密码至少需要6位');
    return;
  }
  if (newPassword !== confirmation) {
    alert('两次输入的新密码不一致');
    return;
  }

  try {
    const result = await api('/change-password', {
      method: 'POST',
      body: JSON.stringify({ currentPassword, newPassword })
    });
    setAuthToken(result.token);
    closeModal('modal-change-password');
  } catch (err) {
    alert(err.message);
  }
}

async function openGroupShareModal() {
  if (!currentGroup || currentGroup.access_level !== 'owner') return;
  document.getElementById('share-user-email').value = '';
  document.getElementById('share-permission').value = 'view';
  openModal('modal-group-share');
  await loadGroupShares();
}

async function loadGroupShares() {
  if (!currentGroup || currentGroup.access_level !== 'owner') return;
  try {
    const shares = await api('/groups/' + currentGroup.id + '/shares');
    const list = document.getElementById('group-share-list');
    list.innerHTML = shares.length > 0
      ? shares.map(share => `
          <div class="list-item share-item">
            <div class="info">
              <div class="name">${escapeHtml(share.email)}</div>
              <div class="meta">${share.permission === 'input' ? '录入权限' : '查看权限'}</div>
            </div>
            <button class="delete-btn" onclick="deleteGroupShare(${share.id})" aria-label="取消分享">×</button>
          </div>
        `).join('')
      : '<div class="empty-state">暂未分享给其他用户</div>';
  } catch (err) {
    closeModal('modal-group-share');
    alert(err.message);
  }
}

async function saveGroupShare() {
  if (!currentGroup || currentGroup.access_level !== 'owner') return;
  const email = document.getElementById('share-user-email').value.trim();
  const permission = document.getElementById('share-permission').value;
  if (!email) {
    alert('请输入用户邮箱');
    return;
  }

  try {
    await api('/groups/' + currentGroup.id + '/shares', {
      method: 'POST',
      body: JSON.stringify({ email, permission })
    });
    document.getElementById('share-user-email').value = '';
    await loadGroupShares();
  } catch (err) {
    alert(err.message);
  }
}

async function deleteGroupShare(shareId) {
  if (!currentGroup || currentGroup.access_level !== 'owner' || !confirm('确定取消该用户的分组权限？')) return;
  try {
    await api('/group-shares/' + shareId, { method: 'DELETE' });
    await loadGroupShares();
  } catch (err) {
    alert(err.message);
  }
}

// ==================== 初始化 ====================

document.addEventListener('DOMContentLoaded', () => {
  // Tab切换
  document.querySelectorAll('.tab').forEach(tab => {
    tab.addEventListener('click', () => {
      document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
      tab.classList.add('active');
      
      const tabName = tab.dataset.tab;
      document.getElementById('form-login').style.display = tabName === 'login' ? 'block' : 'none';
      document.getElementById('form-register').style.display = tabName === 'register' ? 'block' : 'none';
    });
  });
  
  // 表单提交
  document.getElementById('form-login').addEventListener('submit', login);
  document.getElementById('form-register').addEventListener('submit', register);
  document.getElementById('btn-logout').addEventListener('click', logout);
  document.getElementById('btn-change-password').addEventListener('click', openChangePasswordModal);
  document.getElementById('btn-new-session').addEventListener('click', openCreateSessionPage);
  document.getElementById('btn-new-group').addEventListener('click', () => openModal('modal-group'));

  const playerNameInput = document.getElementById('player-name');
  const playerNamePicker = document.getElementById('player-name-picker');
  playerNameInput.addEventListener('focus', showPlayerHistorySuggestions);
  playerNameInput.addEventListener('input', showPlayerHistorySuggestions);
  playerNameInput.addEventListener('keydown', event => {
    if (event.key === 'Escape') {
      hidePlayerHistorySuggestions();
    }
    if (event.key === 'ArrowDown') {
      const firstOption = document.querySelector('#player-history-suggestions .player-history-option');
      if (firstOption) {
        event.preventDefault();
        firstOption.focus();
      }
    }
  });
  document.addEventListener('pointerdown', event => {
    if (!playerNamePicker.contains(event.target)) {
      hidePlayerHistorySuggestions();
    }
  });
  
  // 检查登录状态
  checkAuth();
});

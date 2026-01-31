// 全局状态
let currentUser = null;
let sessions = [];
let currentSession = null;
let currentPlayer = null;
let selectedBuyinPlayerId = null;
let pageHistory = ['page-sessions'];

// API基础URL
const API_BASE = '/api';

// ==================== 工具函数 ====================

async function api(path, options = {}) {
  const res = await fetch(API_BASE + path, {
    headers: {
      'Content-Type': 'application/json',
      ...options.headers
    },
    ...options
  });
  const data = await res.json();
  if (!res.ok) {
    throw new Error(data.error || '请求失败');
  }
  return data;
}

function formatMoney(num) {
  return '¥' + (Math.round(num * 100) / 100).toFixed(2);
}

// 历史玩家姓名
function getPlayerHistory() {
  return JSON.parse(localStorage.getItem('playerNames') || '[]');
}

function savePlayerName(name) {
  const history = getPlayerHistory();
  if (!history.includes(name)) {
    history.unshift(name);
    if (history.length > 20) history.pop(); // 保留最近20个
    localStorage.setItem('playerNames', JSON.stringify(history));
    updatePlayerHistoryList();
  }
}

function updatePlayerHistoryList() {
  const history = getPlayerHistory();
  const datalist = document.getElementById('player-history');
  datalist.innerHTML = history.map(n => `<option value="${n}">`).join('');
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
  try {
    currentUser = await api('/me');
    showPage('page-sessions');
    loadSessions();
  } catch (err) {
    showPage('page-login');
  }
}

async function login(e) {
  e.preventDefault();
  const email = document.getElementById('login-email').value;
  const password = document.getElementById('login-password').value;
  try {
    await api('/login', {
      method: 'POST',
      body: JSON.stringify({ email, password })
    });
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
    await api('/register', {
      method: 'POST',
      body: JSON.stringify({ email, password })
    });
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
  await api('/logout', { method: 'POST' });
  currentUser = null;
  showPage('page-login');
}

// ==================== 场次相关 ====================

async function loadSessions() {
  sessions = await api('/sessions');
  const list = document.getElementById('sessions-list');
  
  if (sessions.length === 0) {
    list.innerHTML = '<div class="empty-state">暂无场次，点击下方按钮创建</div>';
    return;
  }
  
  list.innerHTML = sessions.map(s => `
    <div class="list-item" onclick="openSession(${s.id}, '${s.name}')">
      <div class="info">
        <div class="name">${s.name}</div>
        <div class="meta">${s.player_count}人 | ${new Date(s.created_at).toLocaleDateString()}</div>
      </div>
      ${s.settled_count > 0 ? '<span class="settled-badge">已结算</span>' : ''}
      <button class="delete-btn" onclick="event.stopPropagation(); deleteSession(${s.id})">🗑️</button>
    </div>
  `).join('');
}

async function createSession() {
  const name = document.getElementById('session-name').value.trim();
  if (!name) {
    alert('请输入场次名称');
    return;
  }
  
  try {
    await api('/sessions', {
      method: 'POST',
      body: JSON.stringify({ name })
    });
    closeModal('modal-session');
    document.getElementById('session-name').value = '';
    loadSessions();
  } catch (err) {
    alert(err.message);
  }
}

async function deleteSession(id) {
  if (!confirm('确定删除这个场次？')) return;
  await api('/sessions/' + id, { method: 'DELETE' });
  loadSessions();
}

async function openSession(id, name) {
  currentSession = { id, name };
  document.getElementById('session-title').textContent = name;
  updatePlayerHistoryList(); // 加载历史姓名
  showPage('page-session');
  loadPlayers();
}

async function loadPlayers() {
  const data = await api('/sessions/' + currentSession.id);
  const list = document.getElementById('players-list');
  
  if (data.players.length === 0) {
    list.innerHTML = '<div class="empty-state">暂无玩家，请添加</div>';
    return;
  }
  
  list.innerHTML = data.players.map(p => {
    const isSettled = p.final_balance !== null;
    let resultHtml = '';
    
    if (isSettled) {
      const profit = p.final_balance - p.total_buyin;
      const profitClass = profit >= 0 ? 'profit' : 'loss';
      const profitText = profit >= 0 ? `水上${formatMoney(profit)}` : `水下${formatMoney(Math.abs(profit))}`;
      resultHtml = `<span class="amount ${profitClass}">${profitText}</span>`;
    } else {
      resultHtml = `<span class="meta">累计: ${formatMoney(p.total_buyin)}</span>`;
    }
    
    return `
      <div class="list-item" onclick="openPlayer(${p.id}, '${p.name}')">
        <div class="info">
          <div class="name">
            ${p.name}
            ${isSettled ? '<span class="settled-badge">已结算</span>' : ''}
          </div>
          <div class="meta">${isSettled ? '' : '累计: ' + formatMoney(p.total_buyin)}</div>
        </div>
        ${resultHtml}
        <button class="delete-btn" onclick="event.stopPropagation(); deletePlayer(${p.id})">🗑️</button>
      </div>
    `;
  }).join('');
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
  const unsettledPlayers = data.players.filter(p => p.final_balance === null);
  
  if (unsettledPlayers.length === 0) {
    alert('暂无未结算的玩家');
    return;
  }
  
  const list = document.getElementById('buyin-player-list');
  list.innerHTML = unsettledPlayers.map(p => `
    <div class="buyin-player-item" onclick="selectBuyinPlayer(${p.id}, '${p.name}', this)">
      <div>
        <div class="player-name">${p.name}</div>
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
function selectBuyinPlayer(id, name, element) {
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
    loadPlayers();
  } catch (err) {
    alert(err.message);
  }
}

async function openPlayer(id, name) {
  currentPlayer = { id, name };
  document.getElementById('player-title').textContent = name;
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
  
  // 更新统计
  document.getElementById('player-total-buyin').textContent = formatMoney(totalBuyin);
  document.getElementById('player-final').textContent = finalBalance !== null ? formatMoney(finalBalance) : '-';
  
  if (finalBalance !== null) {
    const profit = finalBalance - totalBuyin;
    const profitEl = document.getElementById('player-profit');
    const profitClass = profit >= 0 ? 'profit' : 'loss';
    const profitText = profit >= 0 ? `水上${formatMoney(profit)}` : `水下${formatMoney(Math.abs(profit))}`;
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
    alert('结算成功！');
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
    
    const errorEl = document.getElementById('stat-error');
    const errorRow = document.getElementById('stat-error-row');
    errorEl.textContent = formatMoney(data.error);
    
    if (Math.abs(data.error) > 0.1) {
      errorRow.classList.add('error');
    } else {
      errorRow.classList.remove('error');
    }
    
    const list = document.getElementById('stats-list');
    list.innerHTML = data.players.map(p => {
      let profitText = '-';
      let profitClass = '';
      
      if (p.profitLoss !== null) {
        if (p.profitLoss >= 0) {
          profitText = `水上${formatMoney(p.profitLoss)}`;
          profitClass = 'profit';
        } else {
          profitText = `水下${formatMoney(Math.abs(p.profitLoss))}`;
          profitClass = 'loss';
        }
      }
      
      return `
        <div class="list-item">
          <div class="info">
            <div class="name">${p.name}</div>
            <div class="meta">买入: ${formatMoney(p.buyin)}</div>
          </div>
          <div>
            <div class="amount ${profitClass}">${profitText}</div>
            ${p.final !== null ? `<div class="meta">结余: ${formatMoney(p.final)}</div>` : ''}
          </div>
        </div>
      `;
    }).join('');
    
    showPage('page-stats');
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
  document.getElementById('btn-new-session').addEventListener('click', () => openModal('modal-session'));
  
  // 检查登录状态
  checkAuth();
});

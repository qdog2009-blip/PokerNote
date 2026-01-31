const express = require('express');
const session = require('express-session');
const bcrypt = require('bcryptjs');
const db = require('./database');
const path = require('path');

const app = express();
const PORT = 3000;

// 中间件
app.use(express.json());
app.use(express.static('public'));
app.use(session({
  secret: 'toolbox-secret-key-2024',
  resave: false,
  saveUninitialized: false,
  cookie: { maxAge: 7 * 24 * 60 * 60 * 1000 } // 7天
}));

// 认证中间件
const requireAuth = (req, res, next) => {
  if (!req.session.userId) {
    return res.status(401).json({ error: '请先登录' });
  }
  next();
};

// ==================== 认证路由 ====================

// 注册
app.post('/api/register', async (req, res) => {
  const { email, password } = req.body;
  if (!email || !password) {
    return res.status(400).json({ error: '邮箱和密码不能为空' });
  }
  try {
    const hashedPassword = await bcrypt.hash(password, 10);
    const stmt = db.prepare('INSERT INTO users (email, password) VALUES (?, ?)');
    const result = stmt.run(email, hashedPassword);
    req.session.userId = result.lastInsertRowid;
    req.session.email = email;
    res.json({ success: true, userId: result.lastInsertRowid, email });
  } catch (err) {
    if (err.message.includes('UNIQUE constraint')) {
      res.status(400).json({ error: '邮箱已被注册' });
    } else {
      res.status(500).json({ error: '注册失败' });
    }
  }
});

// 登录
app.post('/api/login', async (req, res) => {
  const { email, password } = req.body;
  const stmt = db.prepare('SELECT * FROM users WHERE email = ?');
  const user = stmt.get(email);
  
  if (!user || !(await bcrypt.compare(password, user.password))) {
    return res.status(401).json({ error: '邮箱或密码错误' });
  }
  
  req.session.userId = user.id;
  req.session.email = user.email;
  res.json({ success: true, userId: user.id, email: user.email });
});

// 登出
app.post('/api/logout', (req, res) => {
  req.session.destroy();
  res.json({ success: true });
});

// 获取当前用户
app.get('/api/me', requireAuth, (req, res) => {
  res.json({ userId: req.session.userId, email: req.session.email });
});

// ==================== 场次路由 ====================

// 创建场次
app.post('/api/sessions', requireAuth, (req, res) => {
  const { name } = req.body;
  const stmt = db.prepare('INSERT INTO sessions (user_id, name) VALUES (?, ?)');
  const result = stmt.run(req.session.userId, name);
  res.json({ success: true, sessionId: result.lastInsertRowid, name });
});

// 获取用户所有场次
app.get('/api/sessions', requireAuth, (req, res) => {
  const stmt = db.prepare(`
    SELECT s.*, COUNT(p.id) as player_count,
    (SELECT COUNT(*) FROM players p2 WHERE p2.session_id = s.id AND p2.final_balance IS NOT NULL) as settled_count
    FROM sessions s
    LEFT JOIN players p ON s.id = p.session_id
    WHERE s.user_id = ?
    GROUP BY s.id
    ORDER BY s.created_at DESC
  `);
  const sessions = stmt.all(req.session.userId);
  res.json(sessions);
});

// 获取场次详情
app.get('/api/sessions/:id', requireAuth, (req, res) => {
  const session = db.prepare('SELECT * FROM sessions WHERE id = ? AND user_id = ?')
    .get(req.params.id, req.session.userId);
  if (!session) return res.status(404).json({ error: '场次不存在' });
  
  const players = db.prepare(`
    SELECT p.*, 
      (SELECT SUM(amount) FROM buyins WHERE player_id = p.id) as total_buyin_recorded
    FROM players p WHERE p.session_id = ?
  `).all(req.params.id);
  
  res.json({ ...session, players });
});

// 删除场次
app.delete('/api/sessions/:id', requireAuth, (req, res) => {
  db.prepare('DELETE FROM buyins WHERE player_id IN (SELECT id FROM players WHERE session_id = ?)').run(req.params.id);
  db.prepare('DELETE FROM players WHERE session_id = ?').run(req.params.id);
  db.prepare('DELETE FROM sessions WHERE id = ? AND user_id = ?').run(req.params.id, req.session.userId);
  res.json({ success: true });
});

// ==================== 玩家路由 ====================

// 添加玩家
app.post('/api/sessions/:sessionId/players', requireAuth, (req, res) => {
  const { name, initialBuyin } = req.body;
  const stmt = db.prepare('INSERT INTO players (session_id, name, initial_buyin, total_buyin) VALUES (?, ?, ?, ?)');
  const result = stmt.run(req.params.sessionId, name, initialBuyin || 0, initialBuyin || 0);
  
  // 如果有初始买入，记录到buyins表
  if (initialBuyin > 0) {
    db.prepare('INSERT INTO buyins (player_id, amount) VALUES (?, ?)').run(result.lastInsertRowid, initialBuyin);
  }
  
  res.json({ success: true, playerId: result.lastInsertRowid, name });
});

// 删除玩家
app.delete('/api/players/:id', requireAuth, (req, res) => {
  db.prepare('DELETE FROM buyins WHERE player_id = ?').run(req.params.id);
  db.prepare('DELETE FROM players WHERE id = ?').run(req.params.id);
  res.json({ success: true });
});

// ==================== 买入路由 ====================

// 增加买入
app.post('/api/players/:playerId/buyin', requireAuth, (req, res) => {
  const { amount } = req.body;
  if (!amount || amount <= 0) {
    return res.status(400).json({ error: '买入金额必须大于0' });
  }
  
  db.prepare('INSERT INTO buyins (player_id, amount) VALUES (?, ?)').run(req.params.playerId, amount);
  db.prepare('UPDATE players SET total_buyin = total_buyin + ? WHERE id = ?').run(amount, req.params.playerId);
  res.json({ success: true });
});

// 获取买入记录
app.get('/api/players/:playerId/buyins', requireAuth, (req, res) => {
  const buyins = db.prepare('SELECT * FROM buyins WHERE player_id = ? ORDER BY created_at ASC').all(req.params.playerId);
  res.json(buyins);
});

// ==================== 结算路由 ====================

// 结算玩家
app.post('/api/players/:playerId/settle', requireAuth, (req, res) => {
  const { finalBalance, profitLoss } = req.body;
  
  if (finalBalance !== undefined) {
    db.prepare('UPDATE players SET final_balance = ? WHERE id = ?').run(finalBalance, req.params.playerId);
  } else if (profitLoss !== undefined) {
    // 根据盈利反推结余
    const player = db.prepare('SELECT total_buyin FROM players WHERE id = ?').get(req.params.playerId);
    const finalBalance = player.total_buyin + profitLoss;
    db.prepare('UPDATE players SET final_balance = ? WHERE id = ?').run(finalBalance, req.params.playerId);
  }
  
  res.json({ success: true });
});

// ==================== 统计路由 ====================

// 获取场次统计
app.get('/api/sessions/:id/stats', requireAuth, (req, res) => {
  const players = db.prepare(`
    SELECT p.*,
      COALESCE((SELECT SUM(amount) FROM buyins WHERE player_id = p.id), 0) as total_buyin_recorded
    FROM players p WHERE p.session_id = ?
  `).all(req.params.id);
  
  // 计算总买入（从buyins表）
  const totalBuyins = db.prepare(`
    SELECT SUM(amount) as total FROM buyins 
    WHERE player_id IN (SELECT id FROM players WHERE session_id = ?)
  `).get(req.params.id).total || 0;
  
  // 计算所有结余之和
  const settledPlayers = players.filter(p => p.final_balance !== null);
  const totalSettled = settledPlayers.reduce((sum, p) => sum + p.final_balance, 0);
  
  // 计算误差
  const error = totalBuyins - totalSettled;
  
  const stats = players.map(p => {
    const buyin = p.total_buyin_recorded;
    const final = p.final_balance;
    let profitLoss = null;
    
    if (final !== null) {
      profitLoss = final - buyin;
    }
    
    return {
      id: p.id,
      name: p.name,
      buyin: buyin,
      final: final,
      profitLoss: profitLoss
    };
  });
  
  res.json({
    players: stats,
    totalBuyins,
    totalSettled,
    error: Math.round(error * 100) / 100
  });
});

app.listen(PORT, () => {
  console.log(`🎰 德州扑克记录工具运行在 http://localhost:${PORT}`);
});

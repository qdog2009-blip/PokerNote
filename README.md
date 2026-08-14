# PokerNote - 记分器

<div align="center">

![License](https://img.shields.io/badge/License-MIT-blue.svg)
![PHP](https://img.shields.io/badge/PHP-7.4-777BB4.svg)

一个简单好用的德州扑克玩家买入和结算记分器，支持多人游戏数据管理。

</div>

## ✨ 功能特性

| 功能 | 说明 |
|------|------|
| 📧 用户认证 | 邮箱注册/登录，数据库 Token 验证与数据隔离 |
| 🎰 场次管理 | 创建/删除游戏场次 |
| 🗂️ 分组统计 | 默认分组兜底，可按自定义分组汇总多场游戏与玩家战绩 |
| 👥 玩家管理 | 添加玩家 + 首次带入，支持历史姓名自动补全 |
| 💰 买入记录 | 选择玩家 → 输入金额，自动累计 |
| 🧾 结算方式 | 支持结余金额、水上金额、水下金额三种方式 |
| 🪙 场次抽水 | 每场独立设置比例，仅对最终盈利玩家的盈利部分抽水 |
| 📊 实时统计 | 显示每位玩家输赢、计算总误差 |
| 📱 移动端优化 | H5 页面，手机浏览器友好 |

## 🛠 技术栈

- **前端**：原生 HTML5 + CSS3 + JavaScript（无框架依赖）
- **后端**：PHP 7.4（原生 REST API，无框架依赖）
- **数据库**：SQLite（PDO SQLite）
- **认证**：数据库持久化随机 Token + bcrypt

## 🚀 快速开始

### 环境要求

- PHP 7.4.x
- PHP 扩展：`json`、`PDO`、`pdo_sqlite`

### 安装

```bash
git clone https://github.com/qdog2009-blip/PokerNote.git
cd PokerNote
```

后端没有第三方运行依赖，不需要执行 `composer install`。`composer.json` 仅用于向部署平台声明 PHP 版本及扩展要求。

### 启动

Windows 推荐使用项目自带的启动脚本。它会检查 PHP 版本，并在便携 PHP 未配置 `php.ini` 时自动加载 `pdo_sqlite`：

```bat
start.cmd
```

如果 PHP 7.4 不在 `PATH` 中，可以先指定其完整路径：

```bat
set POKERNOTE_PHP=C:\path\to\php-7.4\php.exe
start.cmd
```

Linux、macOS 或已经正确启用 `pdo_sqlite` 的环境可直接启动：

```bash
php -S 0.0.0.0:3000 -t public router.php
```

服务启动后访问：**http://localhost:3000**

### 后台运行（Linux/macOS）

```bash
nohup php -S 0.0.0.0:3000 -t public router.php > /tmp/PokerNote.log 2>&1 &
```

### Apache 部署

将站点根目录（`DocumentRoot`）指向项目的 `public/` 目录，并启用 `mod_rewrite` 和 `.htaccess`：

```apache
<Directory "/path/to/PokerNote/public">
    AllowOverride All
    Require all granted
</Directory>
```

PHP 进程必须对项目根目录具有写权限，以便创建和更新 `toolbox.db`。

如需把数据库放到其他位置，可在启动 PHP 前设置 `POKERNOTE_DB_PATH` 为 SQLite 文件的绝对路径。

### Nginx + PHP 7.4 部署

如果现有站点通过 `php7.inc` 连接 PHP-FPM，可直接使用 `deploy/nginx/pokernote.conf`。模板已经按以下环境配置：

- 域名：`p.txtcc.com`
- 证书：`ssl/star.txtcc.com.cer`、`ssl/star.txtcc.com.key`
- 网站目录：`/serverdata/wwwroot/nick/PokerNote/public`
- PHP 配置：`php7.inc`
- 公共配置：`common.inc`

如实际项目目录不同，只需修改配置中的 `root`。确认公用的 `php7.inc` 指向 PHP 7.4 FPM即可，不需要修改 `php7.inc`。然后复制配置并重载 Nginx：

```bash
sudo cp deploy/nginx/pokernote.conf /etc/nginx/conf.d/pokernote.conf
sudo nginx -t
sudo systemctl reload nginx
```

该模式不需要运行 `php -S 127.0.0.1:3000`。Nginx 会直接提供 HTML、CSS、JavaScript 和字体文件，`/api` 与 `/api/` 下的请求会重写到 `public/index.php`，再由 `php7.inc` 交给 PHP-FPM。PHP-FPM 的运行用户需要对项目根目录具有写权限，以便创建和更新 `toolbox.db`。

登录或注册成功后，后端会生成随机 Token，仅把 SHA-256 哈希保存到数据库，浏览器将 Token 原文持久化到 `localStorage`，后续请求通过普通的 `X-PokerNote-Token` 请求头验证，无需修改公用 PHP-FPM 配置。后端也兼容 `Authorization: Bearer`。Token 不自动过期；主动退出会删除当前 Token，修改密码会撤销该账号的全部旧 Token并签发新 Token。替换数据库后，原数据库签发的 Token 会自动失效，需要重新登录。

## 📖 使用流程

```
1. 注册账号 → 登录
2. 可选：创建场次分组（未设置则使用“默认分组”）
3. 创建场次（如 "2024年1月周五局"），选择分组与抽水比例
4. 添加玩家（输入姓名 + 可选首次带入金额）
5. 买入操作（💰 买入 → 选玩家 → 输入金额）
6. 结算（点击玩家 → 输入结余/水上/水下）
7. 查看单场统计或分组统计（跨场次总账 + 玩家累计）
```

## 🗂️ 分组统计

- 每个用户自动拥有一个不可缺省的“默认分组”
- 创建场次时未指定分组，会自动归入默认分组
- 可以创建多个自定义分组，并随时修改场次所属分组
- 场次列表按分组分段显示，每个分组可独立查看总买入、结算、抽水、净结算和账目误差
- 同一分组内同名玩家会跨场次合并，显示参与场次、累计买入、累计抽水和净战绩
- 旧版本已有场次在数据库升级时会自动归入默认分组

## 👥 账号与分组分享

- 已登录用户可以在场次列表页修改密码，修改时必须先验证当前密码
- 分组所有者可以按已注册用户的邮箱分享分组，并随时修改或取消权限
- **查看权限**：可查看共享分组、场次、玩家、统计和水池支出，但不能修改数据
- **录入权限**：在查看基础上，可创建场次、维护玩家买入和结算、调整抽水以及维护水池支出
- 只有分组所有者可以管理分享或把场次移动到其他分组
- 取消分享后，对方会立即失去该分组及其场次的访问权限

## 🪙 抽水计算

每个场次可设置 `0%` 到 `100%` 的独立抽水比例。系统保留玩家输入的原始结余，并按玩家分别计算：

```text
毛战绩 = 最终结余 - 总买入
抽水 = 毛战绩 > 0 ? ceil(毛战绩 × 抽水比例) : 0
净战绩 = 毛战绩 - 抽水
```

每位盈利玩家的抽水单独向上取整到整数，例如计算结果为 `1.1` 元时实际抽水为 `2` 元；亏损或持平玩家不抽水。账目误差仍按“总买入 - 抽水前结算总额”计算，因此会与抽水分开显示。

全部玩家结算完成后，系统会自动把账目误差并入水池：

```text
结算误差 = 总买入 - 抽水前结算总额
最终水池 = 盈利玩家抽水 + 结算误差
```

正误差表示有剩余筹码，会计入水池；负误差表示结算超出买入，会从水池扣取。尚有玩家未结算时，暂时差额不会提前计入水池。

分组统计页还可以登记水池支出。每笔支出包含金额和备注，并按以下方式计算分组当前水池余额：

```text
分组水池余额 = 各场最终水池合计 - 分组水池支出合计
```

## 📁 项目结构

```
PokerNote/
├── composer.json      # PHP 7.4 与扩展要求
├── deploy/
│   └── nginx/
│       └── pokernote.conf  # Nginx + PHP-FPM 部署模板
├── router.php         # PHP 内置服务器路由
├── src/
│   ├── Application.php  # API 路由与业务逻辑
│   └── Database.php     # SQLite 连接与初始化
├── README.md          # 项目文档
├── public/            # 静态资源
│   ├── .htaccess      # Apache API 重写规则
│   ├── index.html     # 主页面
│   ├── index.php      # PHP API 入口
│   ├── css/
│   │   └── style.css  # 样式（移动端优化）
│   └── js/
│       └── app.js     # 前端逻辑
└── toolbox.db         # SQLite 数据库文件（运行后自动生成，不提交）
```

## 💾 数据存储

- 所有数据存储在本地 `toolbox.db` 文件
- 登录 Token 的 SHA-256 哈希也保存在数据库中，Token 原文只保留在浏览器 `localStorage`
- 支持数据持久化，重启后数据不丢失
- 数据文件位于项目根目录
- 原 Node.js 版本同样使用 `toolbox.db`，升级后可直接沿用已有数据库

## 🤝 贡献

欢迎提交 Issue 或 Pull Request！

## 📄 License

MIT License

---

**作者**：qdog2009-blip  
**GitHub**：https://github.com/qdog2009-blip/PokerNote

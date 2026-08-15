<?php

declare(strict_types=1);

final class HttpException extends RuntimeException
{
    private $statusCode;

    public function __construct(int $statusCode, string $message)
    {
        parent::__construct($message);
        $this->statusCode = $statusCode;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
}

final class Application
{
    private $pdo;
    private $authenticatedUser;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->authenticatedUser = null;
    }

    public function handle(string $method, string $path): void
    {
        try {
            $this->dispatch(strtoupper($method), rtrim($path, '/') ?: '/');
        } catch (HttpException $exception) {
            $this->json(['error' => $exception->getMessage()], $exception->getStatusCode());
        } catch (Throwable $exception) {
            error_log((string) $exception);
            $this->json(['error' => '服务器内部错误'], 500);
        }
    }

    private function dispatch(string $method, string $path): void
    {
        if ($method === 'POST' && $path === '/api/register') {
            $this->register();
        }

        if ($method === 'POST' && $path === '/api/login') {
            $this->login();
        }

        if ($method === 'POST' && $path === '/api/logout') {
            $this->logout();
        }

        if ($method === 'GET' && $path === '/api/me') {
            $userId = $this->requireUser();
            $this->json([
                'userId' => $userId,
                'email' => (string) $this->authenticatedUser['email'],
            ]);
        }

        if ($method === 'POST' && $path === '/api/change-password') {
            $this->changePassword($this->requireUser());
        }

        if ($method === 'POST' && $path === '/api/groups') {
            $this->createGroup($this->requireUser());
        }

        if ($method === 'GET' && $path === '/api/groups') {
            $this->listGroups($this->requireUser());
        }

        if (preg_match('#^/api/groups/(\d+)$#', $path, $matches) === 1 && $method === 'DELETE') {
            $this->deleteGroup((int) $matches[1], $this->requireUser());
        }

        if (preg_match('#^/api/groups/(\d+)/stats$#', $path, $matches) === 1 && $method === 'GET') {
            $this->groupStats((int) $matches[1], $this->requireUser());
        }

        if (preg_match('#^/api/groups/(\d+)/shares$#', $path, $matches) === 1) {
            if ($method === 'GET') {
                $this->listGroupShares((int) $matches[1], $this->requireUser());
            }
            if ($method === 'POST') {
                $this->saveGroupShare((int) $matches[1], $this->requireUser());
            }
        }

        if (preg_match('#^/api/group-shares/(\d+)$#', $path, $matches) === 1 && $method === 'DELETE') {
            $this->deleteGroupShare((int) $matches[1], $this->requireUser());
        }

        if (preg_match('#^/api/groups/(\d+)/expenses$#', $path, $matches) === 1 && $method === 'POST') {
            $this->createGroupPoolExpense((int) $matches[1], $this->requireUser());
        }

        if (preg_match('#^/api/group-expenses/(\d+)$#', $path, $matches) === 1 && $method === 'DELETE') {
            $this->deleteGroupPoolExpense((int) $matches[1], $this->requireUser());
        }

        if ($method === 'POST' && $path === '/api/sessions') {
            $this->createSession($this->requireUser());
        }

        if ($method === 'GET' && $path === '/api/sessions') {
            $this->listSessions($this->requireUser());
        }

        if ($method === 'GET' && $path === '/api/player-names') {
            $this->listPlayerNames($this->requireUser());
        }

        if (preg_match('#^/api/sessions/(\d+)/stats$#', $path, $matches) === 1 && $method === 'GET') {
            $this->sessionStats((int) $matches[1], $this->requireUser());
        }

        if (preg_match('#^/api/sessions/(\d+)/players$#', $path, $matches) === 1 && $method === 'POST') {
            $this->addPlayer((int) $matches[1], $this->requireUser());
        }

        if (preg_match('#^/api/sessions/(\d+)$#', $path, $matches) === 1) {
            $sessionId = (int) $matches[1];
            if ($method === 'GET') {
                $this->getSession($sessionId, $this->requireUser());
            }
            if ($method === 'PATCH') {
                $this->updateSession($sessionId, $this->requireUser());
            }
            if ($method === 'DELETE') {
                $this->deleteSession($sessionId, $this->requireUser());
            }
        }

        if (preg_match('#^/api/players/(\d+)/buyin$#', $path, $matches) === 1 && $method === 'POST') {
            $this->addBuyin((int) $matches[1], $this->requireUser());
        }

        if (preg_match('#^/api/players/(\d+)/buyins$#', $path, $matches) === 1 && $method === 'GET') {
            $this->listBuyins((int) $matches[1], $this->requireUser());
        }

        if (preg_match('#^/api/players/(\d+)/settle$#', $path, $matches) === 1 && $method === 'POST') {
            $this->settlePlayer((int) $matches[1], $this->requireUser());
        }

        if (preg_match('#^/api/players/(\d+)$#', $path, $matches) === 1 && $method === 'DELETE') {
            $this->deletePlayer((int) $matches[1], $this->requireUser());
        }

        throw new HttpException(404, '接口不存在');
    }

    private function register(): void
    {
        $body = $this->requestBody();
        $email = trim($this->requiredString($body, 'email', '邮箱'));
        $password = $this->requiredString($body, 'password', '密码');

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 254) {
            throw new HttpException(400, '请输入有效的邮箱');
        }
        if (strlen($password) < 6) {
            throw new HttpException(400, '密码至少需要6位');
        }

        $passwordHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);
        if ($passwordHash === false) {
            throw new RuntimeException('无法生成密码哈希');
        }

        try {
            $statement = $this->pdo->prepare('INSERT INTO users (email, password) VALUES (?, ?)');
            $statement->execute([$email, $passwordHash]);
        } catch (PDOException $exception) {
            if (strpos($exception->getMessage(), 'UNIQUE constraint') !== false) {
                throw new HttpException(400, '邮箱已被注册');
            }
            throw $exception;
        }

        $userId = (int) $this->pdo->lastInsertId();
        $this->defaultGroup($userId);
        $token = $this->issueAuthToken($userId);
        $this->json([
            'success' => true,
            'userId' => $userId,
            'email' => $email,
            'token' => $token,
        ]);
    }

    private function login(): void
    {
        $body = $this->requestBody();
        $email = trim($this->requiredString($body, 'email', '邮箱'));
        $password = $this->requiredString($body, 'password', '密码');

        $statement = $this->pdo->prepare('SELECT id, email, password FROM users WHERE email = ?');
        $statement->execute([$email]);
        $user = $statement->fetch();

        if ($user === false || !$this->verifyPassword($password, (string) $user['password'])) {
            throw new HttpException(401, '邮箱或密码错误');
        }

        $userId = (int) $user['id'];
        $storedEmail = (string) $user['email'];
        $this->defaultGroup($userId);
        $token = $this->issueAuthToken($userId);
        $this->json([
            'success' => true,
            'userId' => $userId,
            'email' => $storedEmail,
            'token' => $token,
        ]);
    }

    private function logout(): void
    {
        $token = $this->requestAuthToken();
        if ($token !== null) {
            $statement = $this->pdo->prepare('DELETE FROM auth_tokens WHERE token_hash = ?');
            $statement->execute([hash('sha256', $token)]);
        }
        $this->json(['success' => true]);
    }

    private function changePassword(int $userId): void
    {
        $body = $this->requestBody();
        $currentPassword = $this->requiredString($body, 'currentPassword', '当前密码');
        $newPassword = $this->requiredString($body, 'newPassword', '新密码');
        if (strlen($newPassword) < 6) {
            throw new HttpException(400, '新密码至少需要6位');
        }

        $statement = $this->pdo->prepare('SELECT password FROM users WHERE id = ?');
        $statement->execute([$userId]);
        $user = $statement->fetch();
        if ($user === false || !$this->verifyPassword($currentPassword, (string) $user['password'])) {
            throw new HttpException(400, '当前密码错误');
        }

        $passwordHash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 10]);
        if ($passwordHash === false) {
            throw new RuntimeException('无法生成密码哈希');
        }
        $statement = $this->pdo->prepare('UPDATE users SET password = ? WHERE id = ?');
        $newToken = '';
        $this->transaction(function () use ($statement, $passwordHash, $userId, &$newToken): void {
            $statement->execute([$passwordHash, $userId]);
            $deleteTokens = $this->pdo->prepare('DELETE FROM auth_tokens WHERE user_id = ?');
            $deleteTokens->execute([$userId]);
            $newToken = $this->issueAuthToken($userId);
        });
        $this->json(['success' => true, 'token' => $newToken]);
    }

    private function createGroup(int $userId): void
    {
        $body = $this->requestBody();
        $name = trim($this->requiredString($body, 'name', '分组名称'));
        if (strlen($name) > 50) {
            throw new HttpException(400, '分组名称不能超过50个字符');
        }

        try {
            $statement = $this->pdo->prepare(
                'INSERT INTO session_groups (user_id, name, is_default) VALUES (?, ?, 0)'
            );
            $statement->execute([$userId, $name]);
        } catch (PDOException $exception) {
            if (strpos($exception->getMessage(), 'UNIQUE constraint') !== false) {
                throw new HttpException(400, '分组名称已存在');
            }
            throw $exception;
        }

        $groupId = (int) $this->pdo->lastInsertId();
        $this->json([
            'success' => true,
            'group' => [
                'id' => $groupId,
                'name' => $name,
                'is_default' => false,
                'session_count' => 0,
            ],
        ]);
    }

    private function listGroups(int $userId): void
    {
        $this->defaultGroup($userId);
        $statement = $this->pdo->prepare(
            "SELECT g.*, owner.email AS owner_email,
                CASE WHEN g.user_id = ? THEN 'owner' ELSE gs.permission END AS access_level,
                (SELECT COUNT(*) FROM sessions s WHERE s.group_id = g.id) AS session_count
             FROM session_groups g
             INNER JOIN users owner ON owner.id = g.user_id
             LEFT JOIN group_shares gs ON gs.group_id = g.id AND gs.shared_user_id = ?
             WHERE g.user_id = ? OR gs.id IS NOT NULL
             ORDER BY CASE WHEN g.user_id = ? THEN 0 ELSE 1 END,
                g.is_default DESC, g.created_at ASC, g.id ASC"
        );
        $statement->execute([$userId, $userId, $userId, $userId]);

        $groups = [];
        foreach ($statement->fetchAll() as $row) {
            $row = $this->castGroup($row);
            $row['session_count'] = (int) $row['session_count'];
            $groups[] = $row;
        }
        $this->json($groups);
    }

    private function deleteGroup(int $groupId, int $userId): void
    {
        $group = $this->ownedGroup($groupId, $userId);
        if ((bool) $group['is_default']) {
            throw new HttpException(400, '默认分组不能删除');
        }

        $this->transaction(function () use ($groupId): void {
            $statement = $this->pdo->prepare('SELECT COUNT(*) FROM sessions WHERE group_id = ?');
            $statement->execute([$groupId]);
            if ((int) $statement->fetchColumn() > 0) {
                throw new HttpException(400, '该分组还有场次，无法删除');
            }

            $statement = $this->pdo->prepare('DELETE FROM group_pool_expenses WHERE group_id = ?');
            $statement->execute([$groupId]);
            $statement = $this->pdo->prepare('DELETE FROM group_shares WHERE group_id = ?');
            $statement->execute([$groupId]);
            $statement = $this->pdo->prepare('DELETE FROM session_groups WHERE id = ?');
            $statement->execute([$groupId]);
        });

        $this->json(['success' => true]);
    }

    private function listGroupShares(int $groupId, int $userId): void
    {
        $this->ownedGroup($groupId, $userId);
        $statement = $this->pdo->prepare(
            'SELECT gs.id, gs.shared_user_id, u.email, gs.permission, gs.created_at
             FROM group_shares gs
             INNER JOIN users u ON u.id = gs.shared_user_id
             WHERE gs.group_id = ?
             ORDER BY gs.created_at ASC, gs.id ASC'
        );
        $statement->execute([$groupId]);

        $shares = [];
        foreach ($statement->fetchAll() as $share) {
            $share['id'] = (int) $share['id'];
            $share['shared_user_id'] = (int) $share['shared_user_id'];
            $shares[] = $share;
        }
        $this->json($shares);
    }

    private function saveGroupShare(int $groupId, int $userId): void
    {
        $group = $this->ownedGroup($groupId, $userId);
        $body = $this->requestBody();
        $email = trim($this->requiredString($body, 'email', '用户邮箱'));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new HttpException(400, '请输入有效的用户邮箱');
        }
        $permission = $this->sharePermission($body, 'permission');

        $statement = $this->pdo->prepare(
            'SELECT id, email FROM users WHERE email = ? COLLATE NOCASE ORDER BY id ASC LIMIT 1'
        );
        $statement->execute([$email]);
        $sharedUser = $statement->fetch();
        if ($sharedUser === false) {
            throw new HttpException(404, '用户不存在，请对方先注册');
        }
        $sharedUserId = (int) $sharedUser['id'];
        if ($sharedUserId === (int) $group['user_id']) {
            throw new HttpException(400, '不能把分组分享给自己');
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO group_shares (group_id, shared_user_id, permission)
             VALUES (?, ?, ?)
             ON CONFLICT (group_id, shared_user_id)
             DO UPDATE SET permission = excluded.permission'
        );
        $statement->execute([$groupId, $sharedUserId, $permission]);

        $statement = $this->pdo->prepare(
            'SELECT gs.id, gs.shared_user_id, u.email, gs.permission, gs.created_at
             FROM group_shares gs
             INNER JOIN users u ON u.id = gs.shared_user_id
             WHERE gs.group_id = ? AND gs.shared_user_id = ?'
        );
        $statement->execute([$groupId, $sharedUserId]);
        $share = $statement->fetch();
        if ($share === false) {
            throw new RuntimeException('无法读取分组分享设置');
        }
        $share['id'] = (int) $share['id'];
        $share['shared_user_id'] = (int) $share['shared_user_id'];
        $this->json(['success' => true, 'share' => $share]);
    }

    private function deleteGroupShare(int $shareId, int $userId): void
    {
        $statement = $this->pdo->prepare(
            'SELECT gs.id
             FROM group_shares gs
             INNER JOIN session_groups g ON g.id = gs.group_id
             WHERE gs.id = ? AND g.user_id = ?'
        );
        $statement->execute([$shareId, $userId]);
        if ($statement->fetch() === false) {
            throw new HttpException(404, '分组分享记录不存在');
        }

        $statement = $this->pdo->prepare('DELETE FROM group_shares WHERE id = ?');
        $statement->execute([$shareId]);
        $this->json(['success' => true]);
    }

    private function createSession(int $userId): void
    {
        $body = $this->requestBody();
        $name = trim($this->requiredString($body, 'name', '场次名称'));
        if (strlen($name) > 100) {
            throw new HttpException(400, '场次名称不能超过100个字符');
        }
        $rakeRate = array_key_exists('rakeRate', $body)
            ? $this->rakeRate($body, 'rakeRate')
            : 0.0;
        $group = array_key_exists('groupId', $body)
            ? $this->accessibleGroup(
                $this->positiveInteger($body, 'groupId', '分组'),
                $userId,
                'input'
            )
            : $this->defaultGroup($userId);
        $groupId = (int) $group['id'];
        $playerNames = array_key_exists('playerNames', $body)
            ? $this->playerNames($body, 'playerNames')
            : [];
        $initialBuyin = array_key_exists('initialBuyin', $body)
            ? $this->number($body, 'initialBuyin', '统一带入金额')
            : 0.0;
        if ($initialBuyin < 0) {
            throw new HttpException(400, '统一带入金额不能小于0');
        }

        $sessionId = 0;
        $this->transaction(function () use (
            $userId,
            $name,
            $groupId,
            $rakeRate,
            $playerNames,
            $initialBuyin,
            &$sessionId
        ): void {
            $statement = $this->pdo->prepare(
                'INSERT INTO sessions (user_id, name, group_id, rake_rate) VALUES (?, ?, ?, ?)'
            );
            $statement->execute([$userId, $name, $groupId, $rakeRate]);
            $sessionId = (int) $this->pdo->lastInsertId();

            if (count($playerNames) === 0) {
                return;
            }

            $playerStatement = $this->pdo->prepare(
                'INSERT INTO players (session_id, name, initial_buyin, total_buyin) VALUES (?, ?, ?, ?)'
            );
            $buyinStatement = $this->pdo->prepare(
                'INSERT INTO buyins (player_id, amount) VALUES (?, ?)'
            );
            foreach ($playerNames as $playerName) {
                $playerStatement->execute([$sessionId, $playerName, $initialBuyin, $initialBuyin]);
                if ($initialBuyin > 0) {
                    $buyinStatement->execute([(int) $this->pdo->lastInsertId(), $initialBuyin]);
                }
            }
        });
        $this->json([
            'success' => true,
            'sessionId' => $sessionId,
            'name' => $name,
            'rakeRate' => $rakeRate,
            'groupId' => $groupId,
            'groupName' => (string) $group['name'],
            'accessLevel' => (string) ($group['access_level'] ?? 'owner'),
            'playerCount' => count($playerNames),
            'initialBuyin' => $initialBuyin,
        ]);
    }

    private function listSessions(int $userId): void
    {
        $statement = $this->pdo->prepare(
            "SELECT s.*, g.name AS group_name, g.is_default AS group_is_default,
                owner.email AS group_owner_email,
                CASE WHEN g.user_id = ? THEN 'owner' ELSE gs.permission END AS access_level,
                (SELECT COUNT(*) FROM players p WHERE p.session_id = s.id) AS player_count,
                (SELECT COUNT(*) FROM players p WHERE p.session_id = s.id AND p.final_balance IS NOT NULL) AS settled_count
             FROM sessions s
             INNER JOIN session_groups g ON g.id = s.group_id
             INNER JOIN users owner ON owner.id = g.user_id
             LEFT JOIN group_shares gs ON gs.group_id = g.id AND gs.shared_user_id = ?
             WHERE g.user_id = ? OR gs.id IS NOT NULL
             ORDER BY s.created_at DESC, s.id DESC"
        );
        $statement->execute([$userId, $userId, $userId]);

        $sessions = [];
        foreach ($statement->fetchAll() as $row) {
            $row = $this->castSession($row);
            $row['player_count'] = (int) $row['player_count'];
            $row['settled_count'] = (int) $row['settled_count'];
            $sessions[] = $row;
        }
        $this->json($sessions);
    }

    private function listPlayerNames(int $userId): void
    {
        $statement = $this->pdo->prepare(
            'SELECT p.name
             FROM players p
             INNER JOIN sessions s ON s.id = p.session_id
             INNER JOIN session_groups g ON g.id = s.group_id
             LEFT JOIN group_shares gs ON gs.group_id = g.id AND gs.shared_user_id = ?
             WHERE (g.user_id = ? OR gs.id IS NOT NULL) AND TRIM(p.name) <> \'\'
             GROUP BY p.name COLLATE NOCASE
             ORDER BY MAX(p.id) DESC'
        );
        $statement->execute([$userId, $userId]);
        $names = [];
        foreach ($statement->fetchAll() as $row) {
            $names[] = (string) $row['name'];
        }
        $this->json($names);
    }

    private function getSession(int $sessionId, int $userId): void
    {
        $session = $this->accessibleSession($sessionId, $userId, 'view');
        $statement = $this->pdo->prepare(
            'SELECT p.*,
                (SELECT SUM(amount) FROM buyins WHERE player_id = p.id) AS total_buyin_recorded
             FROM players p
             WHERE p.session_id = ?
             ORDER BY p.created_at ASC, p.id ASC'
        );
        $statement->execute([$sessionId]);

        $players = [];
        foreach ($statement->fetchAll() as $row) {
            $players[] = $this->castPlayer($row);
        }

        $session = $this->castSession($session);
        $stats = $this->calculateSessionStats(
            $sessionId,
            (float) $session['rake_rate'],
            $session['final_rake']
        );
        $session['calculated_rake'] = $stats['calculatedRake'];
        $session['effective_rake'] = $stats['totalRake'];
        $session['settlement_error'] = $stats['error'];
        $session['water_pool_adjustment'] = $stats['waterPoolAdjustment'];
        $session['calculated_water_pool'] = round(
            $stats['calculatedRake'] + $stats['waterPoolAdjustment'],
            2
        );
        $session['water_pool'] = $stats['waterPool'];
        $session['is_fully_settled'] = $stats['isFullySettled'];
        $session['rake_overridden'] = $stats['isRakeOverridden'];
        $session['players'] = $players;
        $this->json($session);
    }

    private function updateSession(int $sessionId, int $userId): void
    {
        $sessionAccess = $this->accessibleSession($sessionId, $userId, 'input');
        $body = $this->requestBody();
        $updates = [];
        $params = [];

        if (array_key_exists('rakeRate', $body)) {
            $updates[] = 'rake_rate = ?';
            $params[] = $this->rakeRate($body, 'rakeRate');
            if (!array_key_exists('finalRake', $body) && !array_key_exists('finalPool', $body)) {
                $updates[] = 'final_rake = NULL';
            }
        }

        if (array_key_exists('finalRake', $body) && array_key_exists('finalPool', $body)) {
            throw new HttpException(400, '最终抽水和最终入池金额不能同时设置');
        }

        if (array_key_exists('finalRake', $body) || array_key_exists('finalPool', $body)) {
            $completion = $this->calculateSessionStats(
                $sessionId,
                (float) $sessionAccess['rake_rate'],
                null
            );
            if (!$completion['isFullySettled']) {
                throw new HttpException(400, '全部玩家结算后才能设置最终入池金额');
            }
            if (array_key_exists('finalPool', $body)) {
                $finalPool = $this->moneyAmount($body, 'finalPool', '最终入池金额');
                $finalRake = round($finalPool - $completion['waterPoolAdjustment'], 2);
                if ($finalRake < 0) {
                    throw new HttpException(400, '最终入池金额扣除误差后不能使抽水小于0');
                }
                if (abs($finalRake - round($finalRake)) > 0.000001) {
                    throw new HttpException(400, '最终入池金额扣除误差后，抽水部分必须是整数');
                }
                $finalRake = round($finalRake);
            } else {
                $finalRake = $this->nonNegativeInteger($body, 'finalRake', '最终抽水');
            }
            $updates[] = 'final_rake = ?';
            $params[] = $finalRake;
        }

        if (array_key_exists('groupId', $body)) {
            if ($sessionAccess['access_level'] !== 'owner') {
                throw new HttpException(403, '只有分组所有者可以调整场次所属分组');
            }
            $groupId = $this->positiveInteger($body, 'groupId', '分组');
            $this->accessibleGroup($groupId, $userId, 'input');
            $updates[] = 'group_id = ?';
            $params[] = $groupId;
        }

        if (count($updates) === 0) {
            throw new HttpException(400, '请提供要修改的场次设置');
        }

        $params[] = $sessionId;
        $statement = $this->pdo->prepare(
            'UPDATE sessions SET ' . implode(', ', $updates) . ' WHERE id = ?'
        );
        $statement->execute($params);

        $session = $this->castSession($this->accessibleSession($sessionId, $userId, 'view'));
        $stats = $this->calculateSessionStats(
            $sessionId,
            (float) $session['rake_rate'],
            $session['final_rake']
        );
        $this->json([
            'success' => true,
            'rakeRate' => $session['rake_rate'],
            'finalRake' => $session['final_rake'],
            'finalPool' => $stats['waterPool'],
            'waterPoolAdjustment' => $stats['waterPoolAdjustment'],
            'groupId' => $session['group_id'],
            'groupName' => $session['group_name'],
        ]);
    }

    private function deleteSession(int $sessionId, int $userId): void
    {
        $this->accessibleSession($sessionId, $userId, 'input');
        $this->transaction(function () use ($sessionId): void {
            $statement = $this->pdo->prepare(
                'DELETE FROM buyins WHERE player_id IN (SELECT id FROM players WHERE session_id = ?)'
            );
            $statement->execute([$sessionId]);

            $statement = $this->pdo->prepare('DELETE FROM players WHERE session_id = ?');
            $statement->execute([$sessionId]);

            $statement = $this->pdo->prepare('DELETE FROM sessions WHERE id = ?');
            $statement->execute([$sessionId]);
        });
        $this->json(['success' => true]);
    }

    private function addPlayer(int $sessionId, int $userId): void
    {
        $this->accessibleSession($sessionId, $userId, 'input');
        $body = $this->requestBody();
        $name = trim($this->requiredString($body, 'name', '玩家姓名'));
        if (strlen($name) > 100) {
            throw new HttpException(400, '玩家姓名不能超过100个字符');
        }
        $initialBuyin = array_key_exists('initialBuyin', $body)
            ? $this->number($body, 'initialBuyin', '首次带入')
            : 0.0;
        if ($initialBuyin < 0) {
            throw new HttpException(400, '首次带入不能小于0');
        }

        $playerId = 0;
        $this->transaction(function () use ($sessionId, $name, $initialBuyin, &$playerId): void {
            $this->clearFinalRake($sessionId);
            $statement = $this->pdo->prepare(
                'INSERT INTO players (session_id, name, initial_buyin, total_buyin) VALUES (?, ?, ?, ?)'
            );
            $statement->execute([$sessionId, $name, $initialBuyin, $initialBuyin]);
            $playerId = (int) $this->pdo->lastInsertId();

            if ($initialBuyin > 0) {
                $statement = $this->pdo->prepare('INSERT INTO buyins (player_id, amount) VALUES (?, ?)');
                $statement->execute([$playerId, $initialBuyin]);
            }
        });

        $this->json(['success' => true, 'playerId' => $playerId, 'name' => $name]);
    }

    private function deletePlayer(int $playerId, int $userId): void
    {
        $player = $this->accessiblePlayer($playerId, $userId, 'input');
        $sessionId = (int) $player['session_id'];
        $this->transaction(function () use ($playerId, $sessionId): void {
            $this->clearFinalRake($sessionId);
            $statement = $this->pdo->prepare('DELETE FROM buyins WHERE player_id = ?');
            $statement->execute([$playerId]);
            $statement = $this->pdo->prepare('DELETE FROM players WHERE id = ?');
            $statement->execute([$playerId]);
        });
        $this->json(['success' => true]);
    }

    private function addBuyin(int $playerId, int $userId): void
    {
        $player = $this->accessiblePlayer($playerId, $userId, 'input');
        $sessionId = (int) $player['session_id'];
        $body = $this->requestBody();
        $amount = $this->number($body, 'amount', '买入金额');
        if ($amount <= 0) {
            throw new HttpException(400, '买入金额必须大于0');
        }

        $this->transaction(function () use ($playerId, $amount, $sessionId): void {
            $this->clearFinalRake($sessionId);
            $statement = $this->pdo->prepare('INSERT INTO buyins (player_id, amount) VALUES (?, ?)');
            $statement->execute([$playerId, $amount]);
            $statement = $this->pdo->prepare('UPDATE players SET total_buyin = total_buyin + ? WHERE id = ?');
            $statement->execute([$amount, $playerId]);
        });
        $this->json(['success' => true]);
    }

    private function listBuyins(int $playerId, int $userId): void
    {
        $this->accessiblePlayer($playerId, $userId, 'view');
        $statement = $this->pdo->prepare(
            'SELECT * FROM buyins WHERE player_id = ? ORDER BY created_at ASC, id ASC'
        );
        $statement->execute([$playerId]);

        $buyins = [];
        foreach ($statement->fetchAll() as $row) {
            $row['id'] = (int) $row['id'];
            $row['player_id'] = (int) $row['player_id'];
            $row['amount'] = (float) $row['amount'];
            $buyins[] = $row;
        }
        $this->json($buyins);
    }

    private function settlePlayer(int $playerId, int $userId): void
    {
        $player = $this->accessiblePlayer($playerId, $userId, 'input');
        $body = $this->requestBody();

        if (array_key_exists('finalBalance', $body)) {
            $finalBalance = $this->number($body, 'finalBalance', '结余金额');
        } elseif (array_key_exists('profitLoss', $body)) {
            $profitLoss = $this->number($body, 'profitLoss', '输赢金额');
            $finalBalance = (float) $player['total_buyin'] + $profitLoss;
        } else {
            throw new HttpException(400, '请提供结余金额或输赢金额');
        }

        $sessionId = (int) $player['session_id'];
        $this->transaction(function () use ($finalBalance, $playerId, $sessionId): void {
            $this->clearFinalRake($sessionId);
            $statement = $this->pdo->prepare('UPDATE players SET final_balance = ? WHERE id = ?');
            $statement->execute([$finalBalance, $playerId]);
        });
        $this->json(['success' => true]);
    }

    private function groupStats(int $groupId, int $userId): void
    {
        $group = $this->castGroup($this->accessibleGroup($groupId, $userId, 'view'));
        $statement = $this->pdo->prepare(
            'SELECT s.*,
                (SELECT COUNT(*) FROM players p WHERE p.session_id = s.id) AS player_count,
                (SELECT COUNT(*) FROM players p WHERE p.session_id = s.id AND p.final_balance IS NOT NULL) AS settled_count
             FROM sessions s
             WHERE s.group_id = ?
             ORDER BY s.created_at DESC, s.id DESC'
        );
        $statement->execute([$groupId]);

        $sessions = [];
        $playerTotals = [];
        $totalBuyins = 0.0;
        $totalSettled = 0.0;
        $totalRake = 0.0;
        $totalError = 0.0;
        $totalWaterPoolAdjustment = 0.0;
        $totalWaterPool = 0.0;

        foreach ($statement->fetchAll() as $sessionRow) {
            $sessionId = (int) $sessionRow['id'];
            $stats = $this->calculateSessionStats(
                $sessionId,
                (float) $sessionRow['rake_rate'],
                $sessionRow['final_rake'] === null ? null : (float) $sessionRow['final_rake']
            );
            $totalBuyins += $stats['totalBuyins'];
            $totalSettled += $stats['totalSettled'];
            $totalRake += $stats['totalRake'];
            $totalError += $stats['error'];
            $totalWaterPoolAdjustment += $stats['waterPoolAdjustment'];
            $totalWaterPool += $stats['waterPool'];

            $sessions[] = [
                'id' => $sessionId,
                'name' => (string) $sessionRow['name'],
                'created_at' => (string) $sessionRow['created_at'],
                'rakeRate' => (float) $sessionRow['rake_rate'],
                'playerCount' => (int) $sessionRow['player_count'],
                'playerNames' => array_map(function (array $player): string {
                    return $player['name'];
                }, $stats['players']),
                'settledCount' => (int) $sessionRow['settled_count'],
                'totalBuyins' => $stats['totalBuyins'],
                'totalSettled' => $stats['totalSettled'],
                'totalRake' => $stats['totalRake'],
                'calculatedRake' => $stats['calculatedRake'],
                'finalRake' => $stats['finalRake'],
                'isRakeOverridden' => $stats['isRakeOverridden'],
                'totalNetSettled' => $stats['totalNetSettled'],
                'error' => $stats['error'],
                'isFullySettled' => $stats['isFullySettled'],
                'waterPoolAdjustment' => $stats['waterPoolAdjustment'],
                'waterPool' => $stats['waterPool'],
            ];

            foreach ($stats['players'] as $player) {
                $name = $player['name'];
                if (!isset($playerTotals[$name])) {
                    $playerTotals[$name] = [
                        'name' => $name,
                        'sessionIds' => [],
                        'settledSessionIds' => [],
                        'winningSessionIds' => [],
                        'sessionResults' => [],
                        'buyin' => 0.0,
                        'final' => 0.0,
                        'netFinal' => 0.0,
                        'grossProfitLoss' => 0.0,
                        'rake' => 0.0,
                        'profitLoss' => 0.0,
                    ];
                }

                $playerTotals[$name]['sessionIds'][$sessionId] = true;
                $playerTotals[$name]['buyin'] += $player['buyin'];
                $playerTotals[$name]['sessionResults'][] = [
                    'sessionId' => $sessionId,
                    'sessionName' => (string) $sessionRow['name'],
                    'createdAt' => (string) $sessionRow['created_at'],
                    'buyin' => round($player['buyin'], 2),
                    'final' => $player['final'] === null ? null : round($player['final'], 2),
                    'grossProfitLoss' => $player['grossProfitLoss'] === null
                        ? null
                        : round($player['grossProfitLoss'], 2),
                    'rake' => $player['rake'] === null ? null : round($player['rake'], 2),
                    'profitLoss' => $player['profitLoss'] === null
                        ? null
                        : round($player['profitLoss'], 2),
                ];
                if ($player['final'] !== null) {
                    $playerTotals[$name]['settledSessionIds'][$sessionId] = true;
                    $playerTotals[$name]['final'] += $player['final'];
                    $playerTotals[$name]['netFinal'] += $player['netFinal'];
                    $playerTotals[$name]['grossProfitLoss'] += $player['grossProfitLoss'];
                    $playerTotals[$name]['rake'] += $player['rake'];
                    $playerTotals[$name]['profitLoss'] += $player['profitLoss'];
                    if ($player['grossProfitLoss'] > 0) {
                        $playerTotals[$name]['winningSessionIds'][$sessionId] = true;
                    }
                }
            }
        }

        $players = [];
        foreach ($playerTotals as $player) {
            $settledSessionCount = count($player['settledSessionIds']);
            $hasSettlement = $settledSessionCount > 0;
            $players[] = [
                'name' => $player['name'],
                'sessionCount' => count($player['sessionIds']),
                'settledSessionCount' => $settledSessionCount,
                'winningSessionCount' => count($player['winningSessionIds']),
                'sessions' => $player['sessionResults'],
                'buyin' => round($player['buyin'], 2),
                'final' => $hasSettlement ? round($player['final'], 2) : null,
                'netFinal' => $hasSettlement ? round($player['netFinal'], 2) : null,
                'grossProfitLoss' => $hasSettlement ? round($player['grossProfitLoss'], 2) : null,
                'rake' => $hasSettlement ? round($player['rake'], 2) : null,
                'profitLoss' => $hasSettlement ? round($player['profitLoss'], 2) : null,
            ];
        }
        usort($players, function (array $left, array $right): int {
            $sessionComparison = $right['sessionCount'] <=> $left['sessionCount'];
            if ($sessionComparison !== 0) {
                return $sessionComparison;
            }
            $leftValue = $left['grossProfitLoss'] === null ? -PHP_FLOAT_MAX : $left['grossProfitLoss'];
            $rightValue = $right['grossProfitLoss'] === null ? -PHP_FLOAT_MAX : $right['grossProfitLoss'];
            $resultComparison = $rightValue <=> $leftValue;
            return $resultComparison !== 0
                ? $resultComparison
                : strcmp($left['name'], $right['name']);
        });

        $expenseStatement = $this->pdo->prepare(
            'SELECT id, group_id, amount, note, created_at
             FROM group_pool_expenses
             WHERE group_id = ?
             ORDER BY created_at DESC, id DESC'
        );
        $expenseStatement->execute([$groupId]);
        $expenses = [];
        $totalPoolExpenses = 0.0;
        foreach ($expenseStatement->fetchAll() as $expense) {
            $expense['id'] = (int) $expense['id'];
            $expense['group_id'] = (int) $expense['group_id'];
            $expense['amount'] = (float) $expense['amount'];
            $totalPoolExpenses += $expense['amount'];
            $expenses[] = $expense;
        }

        $grossWaterPool = round($totalWaterPool, 2);
        $totalPoolExpenses = round($totalPoolExpenses, 2);

        $this->json([
            'group' => $group,
            'sessionCount' => count($sessions),
            'sessions' => $sessions,
            'players' => $players,
            'totalBuyins' => round($totalBuyins, 2),
            'totalSettled' => round($totalSettled, 2),
            'totalRake' => round($totalRake, 2),
            'totalNetSettled' => round($totalSettled - $totalRake, 2),
            'error' => round($totalError, 2),
            'waterPoolAdjustment' => round($totalWaterPoolAdjustment, 2),
            'grossWaterPool' => $grossWaterPool,
            'totalPoolExpenses' => $totalPoolExpenses,
            'waterPool' => round($grossWaterPool - $totalPoolExpenses, 2),
            'expenses' => $expenses,
        ]);
    }

    private function createGroupPoolExpense(int $groupId, int $userId): void
    {
        $this->accessibleGroup($groupId, $userId, 'input');
        $body = $this->requestBody();
        $amount = round($this->number($body, 'amount', '支出金额'), 2);
        if ($amount <= 0) {
            throw new HttpException(400, '支出金额必须大于0');
        }

        $note = trim($this->requiredString($body, 'note', '支出备注'));
        if (strlen($note) > 200) {
            throw new HttpException(400, '支出备注不能超过200个字符');
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO group_pool_expenses (group_id, amount, note) VALUES (?, ?, ?)'
        );
        $statement->execute([$groupId, $amount, $note]);
        $expenseId = (int) $this->pdo->lastInsertId();

        $statement = $this->pdo->prepare(
            'SELECT id, group_id, amount, note, created_at FROM group_pool_expenses WHERE id = ?'
        );
        $statement->execute([$expenseId]);
        $expense = $statement->fetch();
        if ($expense === false) {
            throw new RuntimeException('无法读取新增的水池支出');
        }
        $expense['id'] = (int) $expense['id'];
        $expense['group_id'] = (int) $expense['group_id'];
        $expense['amount'] = (float) $expense['amount'];

        $this->json(['success' => true, 'expense' => $expense]);
    }

    private function deleteGroupPoolExpense(int $expenseId, int $userId): void
    {
        $statement = $this->pdo->prepare(
            'SELECT id, group_id FROM group_pool_expenses WHERE id = ?'
        );
        $statement->execute([$expenseId]);
        $expense = $statement->fetch();
        if ($expense === false) {
            throw new HttpException(404, '水池支出记录不存在');
        }
        $this->accessibleGroup((int) $expense['group_id'], $userId, 'input');

        $statement = $this->pdo->prepare('DELETE FROM group_pool_expenses WHERE id = ?');
        $statement->execute([$expenseId]);
        $this->json(['success' => true]);
    }

    private function sessionStats(int $sessionId, int $userId): void
    {
        $session = $this->accessibleSession($sessionId, $userId, 'view');
        $stats = $this->calculateSessionStats(
            $sessionId,
            (float) $session['rake_rate'],
            $session['final_rake'] === null ? null : (float) $session['final_rake']
        );
        $stats['rakeRate'] = (float) $session['rake_rate'];
        $this->json($stats);
    }

    private function calculateSessionStats(int $sessionId, float $rakeRate, ?float $finalRake = null): array
    {
        $statement = $this->pdo->prepare(
            'SELECT p.*,
                COALESCE((SELECT SUM(amount) FROM buyins WHERE player_id = p.id), 0) AS total_buyin_recorded
             FROM players p
             WHERE p.session_id = ?
             ORDER BY p.created_at ASC, p.id ASC'
        );
        $statement->execute([$sessionId]);

        $players = [];
        $totalBuyins = 0.0;
        $totalSettled = 0.0;
        $calculatedRake = 0.0;
        $settledCount = 0;
        $rows = $statement->fetchAll();
        foreach ($rows as $row) {
            $buyin = (float) $row['total_buyin_recorded'];
            $final = $row['final_balance'] === null ? null : (float) $row['final_balance'];
            $grossProfitLoss = $final === null ? null : round($final - $buyin, 2);
            $rake = $grossProfitLoss !== null && $grossProfitLoss > 0
                ? ceil($grossProfitLoss * $rakeRate / 100)
                : ($grossProfitLoss === null ? null : 0.0);
            $profitLoss = $grossProfitLoss === null ? null : round($grossProfitLoss - $rake, 2);
            $netFinal = $final === null ? null : round($final - $rake, 2);
            $totalBuyins += $buyin;
            if ($final !== null) {
                $totalSettled += $final;
                $settledCount++;
            }
            if ($rake !== null) {
                $calculatedRake += $rake;
            }
            $players[] = [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'buyin' => $buyin,
                'final' => $final,
                'netFinal' => $netFinal,
                'grossProfitLoss' => $grossProfitLoss,
                'rake' => $rake,
                'profitLoss' => $profitLoss,
            ];
        }

        $playerCount = count($rows);
        $isFullySettled = $playerCount > 0 && $settledCount === $playerCount;
        $isRakeOverridden = $isFullySettled && $finalRake !== null;
        $totalRake = $isRakeOverridden ? $finalRake : $calculatedRake;
        $error = round($totalBuyins - $totalSettled, 2);
        $waterPoolAdjustment = $isFullySettled ? $error : 0.0;
        $waterPool = round($totalRake + $waterPoolAdjustment, 2);

        return [
            'players' => $players,
            'playerCount' => $playerCount,
            'settledCount' => $settledCount,
            'isFullySettled' => $isFullySettled,
            'totalBuyins' => round($totalBuyins, 2),
            'totalSettled' => round($totalSettled, 2),
            'calculatedRake' => round($calculatedRake, 2),
            'finalRake' => $finalRake === null ? null : round($finalRake, 2),
            'isRakeOverridden' => $isRakeOverridden,
            'totalRake' => round($totalRake, 2),
            'totalNetSettled' => round($totalSettled - $totalRake, 2),
            'error' => $error,
            'waterPoolAdjustment' => $waterPoolAdjustment,
            'waterPool' => $waterPool,
        ];
    }

    private function clearFinalRake(int $sessionId): void
    {
        $statement = $this->pdo->prepare('UPDATE sessions SET final_rake = NULL WHERE id = ?');
        $statement->execute([$sessionId]);
    }

    private function requireUser(): int
    {
        if ($this->authenticatedUser !== null) {
            return (int) $this->authenticatedUser['id'];
        }

        $token = $this->requestAuthToken();
        if ($token === null) {
            throw new HttpException(401, '请先登录');
        }

        $statement = $this->pdo->prepare(
            'SELECT u.id, u.email
             FROM auth_tokens token
             INNER JOIN users u ON u.id = token.user_id
             WHERE token.token_hash = ?
             LIMIT 1'
        );
        $statement->execute([hash('sha256', $token)]);
        $user = $statement->fetch();
        if ($user === false) {
            throw new HttpException(401, '登录已失效，请重新登录');
        }

        $this->authenticatedUser = [
            'id' => (int) $user['id'],
            'email' => (string) $user['email'],
        ];
        return (int) $this->authenticatedUser['id'];
    }

    private function issueAuthToken(int $userId): string
    {
        $token = bin2hex(random_bytes(32));
        $statement = $this->pdo->prepare(
            'INSERT INTO auth_tokens (user_id, token_hash) VALUES (?, ?)'
        );
        $statement->execute([$userId, hash('sha256', $token)]);
        return $token;
    }

    private function requestAuthToken(): ?string
    {
        if (
            isset($_SERVER['HTTP_X_POKERNOTE_TOKEN'])
            && preg_match('/^[a-f0-9]{64}$/i', trim((string) $_SERVER['HTTP_X_POKERNOTE_TOKEN'])) === 1
        ) {
            return strtolower(trim((string) $_SERVER['HTTP_X_POKERNOTE_TOKEN']));
        }

        $authorization = null;
        if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $authorization = (string) $_SERVER['HTTP_AUTHORIZATION'];
        } elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $authorization = (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        } elseif (function_exists('getallheaders')) {
            foreach (getallheaders() as $name => $value) {
                if (strcasecmp((string) $name, 'Authorization') === 0) {
                    $authorization = (string) $value;
                    break;
                }
            }
        }

        if (
            $authorization === null
            || preg_match('/^Bearer\s+([a-f0-9]{64})$/i', trim($authorization), $matches) !== 1
        ) {
            return null;
        }
        return strtolower($matches[1]);
    }

    private function accessibleSession(int $sessionId, int $userId, string $requiredPermission): array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM sessions WHERE id = ?'
        );
        $statement->execute([$sessionId]);
        $session = $statement->fetch();
        if ($session === false) {
            throw new HttpException(404, '场次不存在');
        }

        $group = $this->accessibleGroup((int) $session['group_id'], $userId, $requiredPermission);
        $session['group_name'] = $group['name'];
        $session['group_is_default'] = $group['is_default'];
        $session['group_owner_email'] = $group['owner_email'];
        $session['access_level'] = $group['access_level'];
        return $session;
    }

    private function accessibleGroup(int $groupId, int $userId, string $requiredPermission): array
    {
        $statement = $this->pdo->prepare(
            "SELECT g.*, owner.email AS owner_email,
                CASE WHEN g.user_id = ? THEN 'owner' ELSE gs.permission END AS access_level
             FROM session_groups g
             INNER JOIN users owner ON owner.id = g.user_id
             LEFT JOIN group_shares gs ON gs.group_id = g.id AND gs.shared_user_id = ?
             WHERE g.id = ? AND (g.user_id = ? OR gs.id IS NOT NULL)"
        );
        $statement->execute([$userId, $userId, $groupId, $userId]);
        $group = $statement->fetch();
        if ($group === false) {
            throw new HttpException(404, '分组不存在');
        }

        $accessLevel = (string) $group['access_level'];
        if ($requiredPermission === 'owner' && $accessLevel !== 'owner') {
            throw new HttpException(403, '只有分组所有者可以执行此操作');
        }
        if ($requiredPermission === 'input' && $accessLevel === 'view') {
            throw new HttpException(403, '该分组仅有查看权限');
        }
        return $group;
    }

    private function ownedGroup(int $groupId, int $userId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM session_groups WHERE id = ? AND user_id = ?'
        );
        $statement->execute([$groupId, $userId]);
        $group = $statement->fetch();
        if ($group === false) {
            throw new HttpException(404, '分组不存在');
        }
        return $group;
    }

    private function defaultGroup(int $userId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM session_groups WHERE user_id = ? AND is_default = 1 LIMIT 1'
        );
        $statement->execute([$userId]);
        $group = $statement->fetch();
        if ($group !== false) {
            return $group;
        }

        try {
            $statement = $this->pdo->prepare(
                "INSERT INTO session_groups (user_id, name, is_default) VALUES (?, '默认分组', 1)"
            );
            $statement->execute([$userId]);
        } catch (PDOException $exception) {
            if (strpos($exception->getMessage(), 'UNIQUE constraint') === false) {
                throw $exception;
            }
        }

        $statement = $this->pdo->prepare(
            'SELECT * FROM session_groups WHERE user_id = ? AND is_default = 1 LIMIT 1'
        );
        $statement->execute([$userId]);
        $group = $statement->fetch();
        if ($group === false) {
            throw new RuntimeException('无法创建默认分组');
        }
        return $group;
    }

    private function accessiblePlayer(int $playerId, int $userId, string $requiredPermission): array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM players WHERE id = ?'
        );
        $statement->execute([$playerId]);
        $player = $statement->fetch();
        if ($player === false) {
            throw new HttpException(404, '玩家不存在');
        }
        $this->accessibleSession((int) $player['session_id'], $userId, $requiredPermission);
        return $player;
    }

    private function requestBody(): array
    {
        $rawBody = file_get_contents('php://input');
        if ($rawBody === false || trim($rawBody) === '') {
            return [];
        }
        $body = json_decode($rawBody, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($body)) {
            throw new HttpException(400, '请求内容必须是有效的 JSON 对象');
        }
        return $body;
    }

    private function requiredString(array $body, string $key, string $label): string
    {
        if (!array_key_exists($key, $body) || !is_string($body[$key]) || trim($body[$key]) === '') {
            throw new HttpException(400, $label . '不能为空');
        }
        return $body[$key];
    }

    private function playerNames(array $body, string $key): array
    {
        if (!is_array($body[$key])) {
            throw new HttpException(400, '玩家列表必须是数组');
        }

        $names = [];
        $seen = [];
        foreach ($body[$key] as $value) {
            if (!is_string($value) || trim($value) === '') {
                throw new HttpException(400, '玩家姓名不能为空');
            }
            $name = trim($value);
            if (strlen($name) > 100) {
                throw new HttpException(400, '玩家姓名不能超过100个字符');
            }
            $normalized = strtolower($name);
            if (isset($seen[$normalized])) {
                throw new HttpException(400, '玩家列表中不能有重复姓名');
            }
            $seen[$normalized] = true;
            $names[] = $name;
        }
        return $names;
    }

    private function number(array $body, string $key, string $label): float
    {
        if (!array_key_exists($key, $body) || is_bool($body[$key]) || !is_numeric($body[$key])) {
            throw new HttpException(400, $label . '必须是有效数字');
        }
        $value = (float) $body[$key];
        if (!is_finite($value)) {
            throw new HttpException(400, $label . '必须是有效数字');
        }
        return $value;
    }

    private function positiveInteger(array $body, string $key, string $label): int
    {
        if (!array_key_exists($key, $body) || is_bool($body[$key]) || !is_numeric($body[$key])) {
            throw new HttpException(400, $label . '必须是有效整数');
        }
        $value = (int) $body[$key];
        if ((float) $body[$key] !== (float) $value || $value <= 0) {
            throw new HttpException(400, $label . '必须是有效整数');
        }
        return $value;
    }

    private function nonNegativeInteger(array $body, string $key, string $label): int
    {
        if (!array_key_exists($key, $body) || is_bool($body[$key]) || !is_numeric($body[$key])) {
            throw new HttpException(400, $label . '必须是有效整数');
        }
        $value = (int) $body[$key];
        if ((float) $body[$key] !== (float) $value || $value < 0) {
            throw new HttpException(400, $label . '必须是大于等于0的整数');
        }
        return $value;
    }

    private function moneyAmount(array $body, string $key, string $label): float
    {
        $value = $this->number($body, $key, $label);
        $rounded = round($value, 2);
        if (abs($value - $rounded) > 0.000001) {
            throw new HttpException(400, $label . '最多保留2位小数');
        }
        return $rounded;
    }

    private function rakeRate(array $body, string $key): float
    {
        $rate = $this->number($body, $key, '抽水比例');
        if ($rate < 0 || $rate > 100) {
            throw new HttpException(400, '抽水比例必须在0到100之间');
        }
        return round($rate, 4);
    }

    private function sharePermission(array $body, string $key): string
    {
        $permission = $this->requiredString($body, $key, '分享权限');
        if ($permission !== 'view' && $permission !== 'input') {
            throw new HttpException(400, '分享权限必须是查看或录入');
        }
        return $permission;
    }

    private function castSession(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['user_id'] = (int) $row['user_id'];
        $row['group_id'] = $row['group_id'] === null ? null : (int) $row['group_id'];
        $row['rake_rate'] = (float) $row['rake_rate'];
        $row['final_rake'] = $row['final_rake'] === null ? null : (float) $row['final_rake'];
        if (array_key_exists('group_is_default', $row)) {
            $row['group_is_default'] = (bool) $row['group_is_default'];
        }
        return $row;
    }

    private function castGroup(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['user_id'] = (int) $row['user_id'];
        $row['is_default'] = (bool) $row['is_default'];
        return $row;
    }

    private function castPlayer(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['session_id'] = (int) $row['session_id'];
        $row['initial_buyin'] = (float) $row['initial_buyin'];
        $row['total_buyin'] = (float) $row['total_buyin'];
        $row['final_balance'] = $row['final_balance'] === null ? null : (float) $row['final_balance'];
        if (array_key_exists('total_buyin_recorded', $row)) {
            $row['total_buyin_recorded'] = $row['total_buyin_recorded'] === null
                ? null
                : (float) $row['total_buyin_recorded'];
        }
        return $row;
    }

    private function verifyPassword(string $password, string $hash): bool
    {
        // bcryptjs 默认写入 $2b$；部分 PHP 7.4 构建只识别等价的 $2y$ 前缀。
        if (strpos($hash, '$2b$') === 0) {
            $hash = '$2y$' . substr($hash, 4);
        }
        return password_verify($password, $hash);
    }

    private function transaction(callable $callback): void
    {
        $this->pdo->beginTransaction();
        try {
            $callback();
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function json(array $payload, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

<?php

declare(strict_types=1);

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    fwrite(STDERR, "SKIP: pdo_sqlite is not enabled\n");
    exit(2);
}

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * @return array{status: int, headers: string[], body: array<string, mixed>}
 */
function request(string $method, string $url, ?array $body = null, ?string $cookie = null): array
{
    $headers = ['Accept: application/json'];
    $content = '';
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        $content = (string) json_encode($body, JSON_UNESCAPED_UNICODE);
    }
    if ($cookie !== null) {
        $headers[] = 'Cookie: ' . $cookie;
    }

    $context = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'content' => $content,
            'ignore_errors' => true,
            'timeout' => 2,
        ],
    ]);
    $response = @file_get_contents($url, false, $context);
    $responseHeaders = isset($http_response_header) ? $http_response_header : [];
    $status = 0;
    if (isset($responseHeaders[0]) && preg_match('/\s(\d{3})\s/', $responseHeaders[0], $matches) === 1) {
        $status = (int) $matches[1];
    }
    $decoded = is_string($response) ? json_decode($response, true) : null;

    return [
        'status' => $status,
        'headers' => $responseHeaders,
        'body' => is_array($decoded) ? $decoded : [],
    ];
}

function cookieFromHeaders(array $headers): ?string
{
    foreach ($headers as $header) {
        if (stripos($header, 'Set-Cookie:') === 0) {
            $cookie = trim(explode(';', substr($header, strlen('Set-Cookie:')), 2)[0]);
            return $cookie !== '' ? $cookie : null;
        }
    }

    return null;
}

function hasPersistentSessionCookie(array $headers): bool
{
    foreach ($headers as $header) {
        if (stripos($header, 'Set-Cookie: pokernote_session=') !== 0) {
            continue;
        }
        if (preg_match('/Max-Age=(\d+)/i', $header, $matches) !== 1) {
            continue;
        }
        if (
            (int) $matches[1] >= 315359900
            && stripos($header, 'HttpOnly') !== false
            && stripos($header, 'SameSite=Lax') !== false
        ) {
            return true;
        }
    }

    return false;
}

function hasExpiredSessionCookie(array $headers): bool
{
    foreach ($headers as $header) {
        if (
            stripos($header, 'Set-Cookie: pokernote_session=') === 0
            && preg_match('/Max-Age=0/i', $header) === 1
        ) {
            return true;
        }
    }

    return false;
}

$databasePath = tempnam(sys_get_temp_dir(), 'pokernote-api-');
if ($databasePath === false) {
    throw new RuntimeException('Unable to create the temporary database');
}

$socket = stream_socket_server('tcp://127.0.0.1:0', $socketErrorNumber, $socketErrorMessage);
if ($socket === false) {
    throw new RuntimeException('Unable to reserve a test port: ' . $socketErrorMessage);
}
$socketName = stream_socket_get_name($socket, false);
fclose($socket);
$port = (int) substr((string) strrchr((string) $socketName, ':'), 1);

$projectRoot = dirname(__DIR__);
$extensionDirectory = (string) ini_get('extension_dir');
$command = [
    PHP_BINARY,
    '-n',
    '-d',
    'extension_dir=' . $extensionDirectory,
    '-d',
    'extension=php_pdo_sqlite.dll',
    '-S',
    '127.0.0.1:' . $port,
    '-t',
    $projectRoot . DIRECTORY_SEPARATOR . 'public',
    $projectRoot . DIRECTORY_SEPARATOR . 'router.php',
];
$serverLogPath = tempnam(sys_get_temp_dir(), 'pokernote-server-');
if ($serverLogPath === false) {
    throw new RuntimeException('Unable to create the temporary server log');
}

$previousDatabasePath = getenv('POKERNOTE_DB_PATH');
putenv('POKERNOTE_DB_PATH=' . $databasePath);
$process = proc_open(
    $command,
    [
        0 => ['pipe', 'r'],
        1 => ['file', $serverLogPath, 'a'],
        2 => ['file', $serverLogPath, 'a'],
    ],
    $pipes,
    $projectRoot,
    null,
    ['bypass_shell' => true]
);

if (!is_resource($process)) {
    throw new RuntimeException('Unable to start the PHP test server');
}

try {
    $baseUrl = 'http://127.0.0.1:' . $port;
    $ready = false;
    $readinessContext = stream_context_create(['http' => ['timeout' => 0.2]]);
    for ($attempt = 0; $attempt < 30; $attempt++) {
        $root = @file_get_contents($baseUrl . '/', false, $readinessContext);
        if (is_string($root)) {
            $ready = true;
            break;
        }
        usleep(100000);
    }
    assertTrue(
        $ready,
        'The PHP test server did not become ready: ' . trim((string) file_get_contents($serverLogPath))
    );

    $email = 'smoke-' . bin2hex(random_bytes(8)) . '@example.com';
    $credentials = ['email' => $email, 'password' => 'test-pass-74'];
    $register = request('POST', $baseUrl . '/api/register', $credentials);
    assertTrue($register['status'] === 200 && ($register['body']['success'] ?? false) === true, 'Registration failed');

    $cookie = cookieFromHeaders($register['headers']);
    assertTrue($cookie !== null, 'Registration did not create a session cookie');
    assertTrue(hasPersistentSessionCookie($register['headers']), 'Registration cookie is not persistent and secure');

    $groups = request('GET', $baseUrl . '/api/groups', null, $cookie);
    assertTrue($groups['status'] === 200, 'Unable to load groups after registration');
    assertTrue(count($groups['body']) === 1, 'Registration did not create exactly one default group');
    assertTrue(($groups['body'][0]['name'] ?? null) === '默认分组', 'The default group has an unexpected name');
    assertTrue(($groups['body'][0]['is_default'] ?? false) === true, 'The default group flag is missing');

    $persistentSession = request('GET', $baseUrl . '/api/me', null, $cookie);
    assertTrue($persistentSession['status'] === 200, 'Persistent session cannot restore the signed-in user');
    assertTrue(hasPersistentSessionCookie($persistentSession['headers']), 'Authenticated activity did not renew the persistent cookie');

    $logout = request('POST', $baseUrl . '/api/logout', null, $cookie);
    assertTrue($logout['status'] === 200 && ($logout['body']['success'] ?? false) === true, 'Logout failed');
    assertTrue(hasExpiredSessionCookie($logout['headers']), 'Logout did not clear the persistent session cookie');
    $loggedOutSession = request('GET', $baseUrl . '/api/me', null, $cookie);
    assertTrue($loggedOutSession['status'] === 401, 'The old persistent session still works after logout');

    $login = request('POST', $baseUrl . '/api/login', $credentials, $cookie);
    assertTrue($login['status'] === 200 && ($login['body']['success'] ?? false) === true, 'Login after registration failed');
    assertTrue(hasPersistentSessionCookie($login['headers']), 'Login cookie is not persistent and secure');

    $authenticatedCookie = cookieFromHeaders($login['headers']);
    assertTrue($authenticatedCookie !== null, 'Login did not create a new session cookie');
    $groupId = (int) $groups['body'][0]['id'];

    $defaultGroupDelete = request('DELETE', $baseUrl . '/api/groups/' . $groupId, null, $authenticatedCookie);
    assertTrue($defaultGroupDelete['status'] === 400, 'The default group was deleted');

    $emptyGroup = request(
        'POST',
        $baseUrl . '/api/groups',
        ['name' => '待删除空分组'],
        $authenticatedCookie
    );
    assertTrue($emptyGroup['status'] === 200, 'Unable to create an empty group for deletion');
    $emptyGroupId = (int) ($emptyGroup['body']['group']['id'] ?? 0);
    $emptyGroupDelete = request(
        'DELETE',
        $baseUrl . '/api/groups/' . $emptyGroupId,
        null,
        $authenticatedCookie
    );
    assertTrue($emptyGroupDelete['status'] === 200, 'Unable to delete an empty group');

    $occupiedGroup = request(
        'POST',
        $baseUrl . '/api/groups',
        ['name' => '有场次分组'],
        $authenticatedCookie
    );
    assertTrue($occupiedGroup['status'] === 200, 'Unable to create an occupied group for deletion testing');
    $occupiedGroupId = (int) ($occupiedGroup['body']['group']['id'] ?? 0);
    $occupiedSession = request(
        'POST',
        $baseUrl . '/api/sessions',
        ['name' => '阻止删除的场次', 'rakeRate' => 0, 'groupId' => $occupiedGroupId],
        $authenticatedCookie
    );
    assertTrue($occupiedSession['status'] === 200, 'Unable to create a session in the occupied group');
    $occupiedSessionId = (int) ($occupiedSession['body']['sessionId'] ?? 0);
    $occupiedGroupDelete = request(
        'DELETE',
        $baseUrl . '/api/groups/' . $occupiedGroupId,
        null,
        $authenticatedCookie
    );
    assertTrue($occupiedGroupDelete['status'] === 400, 'A group containing a session was deleted');
    $occupiedSessionDelete = request(
        'DELETE',
        $baseUrl . '/api/sessions/' . $occupiedSessionId,
        null,
        $authenticatedCookie
    );
    assertTrue($occupiedSessionDelete['status'] === 200, 'Unable to remove the occupied-group test session');
    $nowEmptyGroupDelete = request(
        'DELETE',
        $baseUrl . '/api/groups/' . $occupiedGroupId,
        null,
        $authenticatedCookie
    );
    assertTrue($nowEmptyGroupDelete['status'] === 200, 'A group was not deletable after its sessions were removed');

    $groupsAfterDeletion = request('GET', $baseUrl . '/api/groups', null, $authenticatedCookie);
    $deletedGroups = array_filter($groupsAfterDeletion['body'], function (array $group) use ($emptyGroupId, $occupiedGroupId): bool {
        return in_array((int) $group['id'], [$emptyGroupId, $occupiedGroupId], true);
    });
    assertTrue(count($deletedGroups) === 0, 'Deleted groups remain in the group list');

    $session = request(
        'POST',
        $baseUrl . '/api/sessions',
        ['name' => '水池误差测试', 'rakeRate' => 10, 'groupId' => $groupId],
        $authenticatedCookie
    );
    assertTrue($session['status'] === 200, 'Unable to create the water-pool test session');
    $sessionId = (int) ($session['body']['sessionId'] ?? 0);

    $winner = request(
        'POST',
        $baseUrl . '/api/sessions/' . $sessionId . '/players',
        ['name' => '赢家', 'initialBuyin' => 100],
        $authenticatedCookie
    );
    $loser = request(
        'POST',
        $baseUrl . '/api/sessions/' . $sessionId . '/players',
        ['name' => '输家', 'initialBuyin' => 100],
        $authenticatedCookie
    );
    assertTrue($winner['status'] === 200 && $loser['status'] === 200, 'Unable to create test players');
    $winnerId = (int) ($winner['body']['playerId'] ?? 0);
    $loserId = (int) ($loser['body']['playerId'] ?? 0);

    $ownerPlayerNames = request('GET', $baseUrl . '/api/player-names', null, $authenticatedCookie);
    assertTrue($ownerPlayerNames['status'] === 200, 'Unable to load player-name history');
    assertTrue(in_array('赢家', $ownerPlayerNames['body'], true), 'Winner is missing from player-name history');
    assertTrue(in_array('输家', $ownerPlayerNames['body'], true), 'Loser is missing from player-name history');

    $winnerSettlement = request(
        'POST',
        $baseUrl . '/api/players/' . $winnerId . '/settle',
        ['finalBalance' => 150],
        $authenticatedCookie
    );
    assertTrue($winnerSettlement['status'] === 200, 'Unable to settle the winning player');

    $partialStats = request('GET', $baseUrl . '/api/sessions/' . $sessionId . '/stats', null, $authenticatedCookie);
    assertTrue(($partialStats['body']['isFullySettled'] ?? true) === false, 'A partial settlement was marked complete');
    assertTrue((float) ($partialStats['body']['waterPoolAdjustment'] ?? -1) === 0.0, 'A partial error was applied to the water pool');
    assertTrue((float) ($partialStats['body']['waterPool'] ?? -1) === 5.0, 'Partial water pool should contain only winner rake');

    $positiveErrorSettlement = request(
        'POST',
        $baseUrl . '/api/players/' . $loserId . '/settle',
        ['finalBalance' => 40],
        $authenticatedCookie
    );
    assertTrue($positiveErrorSettlement['status'] === 200, 'Unable to settle the losing player');

    $positiveStats = request('GET', $baseUrl . '/api/sessions/' . $sessionId . '/stats', null, $authenticatedCookie);
    assertTrue((float) ($positiveStats['body']['totalRake'] ?? -1) === 5.0, 'Winner rake is incorrect');
    assertTrue((float) ($positiveStats['body']['error'] ?? -1) === 10.0, 'Positive settlement error is incorrect');
    assertTrue((float) ($positiveStats['body']['waterPoolAdjustment'] ?? -1) === 10.0, 'Positive error was not added to the water pool');
    assertTrue((float) ($positiveStats['body']['waterPool'] ?? -1) === 15.0, 'Positive-error water pool is incorrect');

    $negativeErrorSettlement = request(
        'POST',
        $baseUrl . '/api/players/' . $loserId . '/settle',
        ['finalBalance' => 60],
        $authenticatedCookie
    );
    assertTrue($negativeErrorSettlement['status'] === 200, 'Unable to update the losing player settlement');

    $negativeStats = request('GET', $baseUrl . '/api/sessions/' . $sessionId . '/stats', null, $authenticatedCookie);
    assertTrue((float) ($negativeStats['body']['error'] ?? 1) === -10.0, 'Negative settlement error is incorrect');
    assertTrue((float) ($negativeStats['body']['waterPoolAdjustment'] ?? 1) === -10.0, 'Negative error was not deducted from the water pool');
    assertTrue((float) ($negativeStats['body']['waterPool'] ?? 1) === -5.0, 'Negative-error water pool is incorrect');

    $groupStats = request('GET', $baseUrl . '/api/groups/' . $groupId . '/stats', null, $authenticatedCookie);
    assertTrue((float) ($groupStats['body']['waterPoolAdjustment'] ?? 1) === -10.0, 'Group water-pool adjustment is incorrect');
    assertTrue((float) ($groupStats['body']['grossWaterPool'] ?? 1) === -5.0, 'Gross group water pool is incorrect');
    assertTrue((float) ($groupStats['body']['totalPoolExpenses'] ?? 1) === 0.0, 'A new group unexpectedly has expenses');
    assertTrue((float) ($groupStats['body']['waterPool'] ?? 1) === -5.0, 'Group water pool is incorrect');

    $expense = request(
        'POST',
        $baseUrl . '/api/groups/' . $groupId . '/expenses',
        ['amount' => 3.25, 'note' => '场地费'],
        $authenticatedCookie
    );
    assertTrue($expense['status'] === 200 && ($expense['body']['success'] ?? false) === true, 'Unable to create a pool expense');
    $expenseId = (int) ($expense['body']['expense']['id'] ?? 0);
    assertTrue((float) ($expense['body']['expense']['amount'] ?? 0) === 3.25, 'Pool expense amount was not saved');
    assertTrue(($expense['body']['expense']['note'] ?? null) === '场地费', 'Pool expense note was not saved');

    $statsWithExpense = request('GET', $baseUrl . '/api/groups/' . $groupId . '/stats', null, $authenticatedCookie);
    assertTrue((float) ($statsWithExpense['body']['grossWaterPool'] ?? 1) === -5.0, 'An expense changed the gross water pool');
    assertTrue((float) ($statsWithExpense['body']['totalPoolExpenses'] ?? 0) === 3.25, 'Group expense total is incorrect');
    assertTrue((float) ($statsWithExpense['body']['waterPool'] ?? 1) === -8.25, 'Pool expense was not deducted from the balance');
    assertTrue(($statsWithExpense['body']['expenses'][0]['note'] ?? null) === '场地费', 'Pool expense is missing from group stats');

    $otherCredentials = [
        'email' => 'other-' . bin2hex(random_bytes(8)) . '@example.com',
        'password' => 'test-pass-74',
    ];
    $otherRegister = request('POST', $baseUrl . '/api/register', $otherCredentials);
    $otherCookie = cookieFromHeaders($otherRegister['headers']);
    assertTrue($otherCookie !== null, 'Unable to create the second user session');

    $foreignExpense = request(
        'POST',
        $baseUrl . '/api/groups/' . $groupId . '/expenses',
        ['amount' => 1, 'note' => '越权支出'],
        $otherCookie
    );
    assertTrue($foreignExpense['status'] === 404, 'Another user was allowed to spend from this group pool');
    $foreignDelete = request('DELETE', $baseUrl . '/api/group-expenses/' . $expenseId, null, $otherCookie);
    assertTrue($foreignDelete['status'] === 404, 'Another user was allowed to delete this group expense');

    $viewShare = request(
        'POST',
        $baseUrl . '/api/groups/' . $groupId . '/shares',
        ['email' => $otherCredentials['email'], 'permission' => 'view'],
        $authenticatedCookie
    );
    assertTrue($viewShare['status'] === 200, 'Unable to grant view permission');
    $shareId = (int) ($viewShare['body']['share']['id'] ?? 0);

    $ownerShares = request('GET', $baseUrl . '/api/groups/' . $groupId . '/shares', null, $authenticatedCookie);
    assertTrue(count($ownerShares['body']) === 1, 'The owner cannot list group shares');
    assertTrue(($ownerShares['body'][0]['permission'] ?? null) === 'view', 'View permission was not saved');

    $viewerGroups = request('GET', $baseUrl . '/api/groups', null, $otherCookie);
    $sharedGroups = array_values(array_filter($viewerGroups['body'], function (array $group) use ($groupId): bool {
        return (int) $group['id'] === $groupId;
    }));
    assertTrue(count($sharedGroups) === 1, 'The shared group is missing from the viewer group list');
    assertTrue(($sharedGroups[0]['access_level'] ?? null) === 'view', 'Viewer group access level is incorrect');

    $viewerStats = request('GET', $baseUrl . '/api/groups/' . $groupId . '/stats', null, $otherCookie);
    assertTrue($viewerStats['status'] === 200, 'A viewer cannot read group stats');
    $viewerSession = request('GET', $baseUrl . '/api/sessions/' . $sessionId, null, $otherCookie);
    assertTrue($viewerSession['status'] === 200, 'A viewer cannot read a shared session');
    assertTrue(($viewerSession['body']['access_level'] ?? null) === 'view', 'Session view access level is incorrect');
    $viewerPlayerNames = request('GET', $baseUrl . '/api/player-names', null, $otherCookie);
    assertTrue($viewerPlayerNames['status'] === 200, 'A viewer cannot load shared player-name history');
    assertTrue(in_array('赢家', $viewerPlayerNames['body'], true), 'Shared winner is missing from viewer player-name history');
    assertTrue(in_array('输家', $viewerPlayerNames['body'], true), 'Shared loser is missing from viewer player-name history');

    $viewerWrite = request(
        'POST',
        $baseUrl . '/api/groups/' . $groupId . '/expenses',
        ['amount' => 1, 'note' => '查看用户不应成功'],
        $otherCookie
    );
    assertTrue($viewerWrite['status'] === 403, 'View permission allowed a pool expense');
    $viewerDelete = request('DELETE', $baseUrl . '/api/sessions/' . $sessionId, null, $otherCookie);
    assertTrue($viewerDelete['status'] === 403, 'View permission allowed deleting a session');

    $inputShare = request(
        'POST',
        $baseUrl . '/api/groups/' . $groupId . '/shares',
        ['email' => $otherCredentials['email'], 'permission' => 'input'],
        $authenticatedCookie
    );
    assertTrue($inputShare['status'] === 200, 'Unable to upgrade to input permission');
    assertTrue((int) ($inputShare['body']['share']['id'] ?? 0) === $shareId, 'Updating permission created a duplicate share');

    $inputGroups = request('GET', $baseUrl . '/api/groups', null, $otherCookie);
    $inputSharedGroups = array_values(array_filter($inputGroups['body'], function (array $group) use ($groupId): bool {
        return (int) $group['id'] === $groupId;
    }));
    assertTrue(($inputSharedGroups[0]['access_level'] ?? null) === 'input', 'Input group access level is incorrect');
    $otherDefaultGroups = array_values(array_filter($inputGroups['body'], function (array $group): bool {
        return ($group['access_level'] ?? null) === 'owner' && ($group['is_default'] ?? false) === true;
    }));
    $otherDefaultGroupId = (int) $otherDefaultGroups[0]['id'];

    $inputExpense = request(
        'POST',
        $baseUrl . '/api/groups/' . $groupId . '/expenses',
        ['amount' => 2, 'note' => '录入用户支出'],
        $otherCookie
    );
    assertTrue($inputExpense['status'] === 200, 'Input permission cannot create a pool expense');
    $inputExpenseId = (int) ($inputExpense['body']['expense']['id'] ?? 0);

    $inputPlayer = request(
        'POST',
        $baseUrl . '/api/sessions/' . $sessionId . '/players',
        ['name' => '协作录入', 'initialBuyin' => 20],
        $otherCookie
    );
    assertTrue($inputPlayer['status'] === 200, 'Input permission cannot add a player');

    $inputSession = request(
        'POST',
        $baseUrl . '/api/sessions',
        ['name' => '协作场次', 'rakeRate' => 0, 'groupId' => $groupId],
        $otherCookie
    );
    assertTrue($inputSession['status'] === 200, 'Input permission cannot create a shared-group session');

    $moveSharedSession = request(
        'PATCH',
        $baseUrl . '/api/sessions/' . $sessionId,
        ['groupId' => $otherDefaultGroupId],
        $otherCookie
    );
    assertTrue($moveSharedSession['status'] === 403, 'An input user moved an owner session out of the group');

    $sharedUserShareList = request('GET', $baseUrl . '/api/groups/' . $groupId . '/shares', null, $otherCookie);
    assertTrue($sharedUserShareList['status'] === 404, 'A shared user can manage group sharing');

    $ownerStatsAfterInput = request('GET', $baseUrl . '/api/groups/' . $groupId . '/stats', null, $authenticatedCookie);
    assertTrue((int) ($ownerStatsAfterInput['body']['sessionCount'] ?? 0) === 2, 'Owner stats omit collaborator-created sessions');
    assertTrue((float) ($ownerStatsAfterInput['body']['totalPoolExpenses'] ?? 0) === 5.25, 'Collaborator expense is missing');

    $deleteShare = request('DELETE', $baseUrl . '/api/group-shares/' . $shareId, null, $authenticatedCookie);
    assertTrue($deleteShare['status'] === 200, 'The owner cannot cancel group sharing');
    $revokedStats = request('GET', $baseUrl . '/api/groups/' . $groupId . '/stats', null, $otherCookie);
    assertTrue($revokedStats['status'] === 404, 'Revoked sharing still allows group access');
    $revokedPlayerNames = request('GET', $baseUrl . '/api/player-names', null, $otherCookie);
    assertTrue($revokedPlayerNames['status'] === 200, 'Revoked user cannot load their remaining player-name history');
    assertTrue(!in_array('赢家', $revokedPlayerNames['body'], true), 'Revoked winner remains in player-name history');
    assertTrue(!in_array('输家', $revokedPlayerNames['body'], true), 'Revoked loser remains in player-name history');
    assertTrue(!in_array('协作录入', $revokedPlayerNames['body'], true), 'Revoked collaborator player remains in player-name history');

    $deleteInputExpense = request('DELETE', $baseUrl . '/api/group-expenses/' . $inputExpenseId, null, $authenticatedCookie);
    assertTrue($deleteInputExpense['status'] === 200, 'Owner cannot delete collaborator-created expense');

    $deleteExpense = request('DELETE', $baseUrl . '/api/group-expenses/' . $expenseId, null, $authenticatedCookie);
    assertTrue($deleteExpense['status'] === 200, 'The group owner could not delete a pool expense');
    $statsAfterDelete = request('GET', $baseUrl . '/api/groups/' . $groupId . '/stats', null, $authenticatedCookie);
    assertTrue((float) ($statsAfterDelete['body']['totalPoolExpenses'] ?? 1) === 0.0, 'Deleted expense still affects the total');
    assertTrue(count($statsAfterDelete['body']['expenses']) === 0, 'Deleted expenses remain in group stats');

    $wrongPassword = request(
        'POST',
        $baseUrl . '/api/change-password',
        ['currentPassword' => 'wrong-password', 'newPassword' => 'new-test-pass-74'],
        $authenticatedCookie
    );
    assertTrue($wrongPassword['status'] === 400, 'Password changed without the current password');
    $passwordChange = request(
        'POST',
        $baseUrl . '/api/change-password',
        ['currentPassword' => $credentials['password'], 'newPassword' => 'new-test-pass-74'],
        $authenticatedCookie
    );
    assertTrue($passwordChange['status'] === 200, 'Unable to change password');
    $changedPasswordCookie = cookieFromHeaders($passwordChange['headers']);
    assertTrue($changedPasswordCookie !== null, 'Password change did not rotate the session id');

    $oldPasswordLogin = request('POST', $baseUrl . '/api/login', $credentials);
    assertTrue($oldPasswordLogin['status'] === 401, 'Old password still works after changing it');
    $newPasswordLogin = request(
        'POST',
        $baseUrl . '/api/login',
        ['email' => $credentials['email'], 'password' => 'new-test-pass-74']
    );
    assertTrue($newPasswordLogin['status'] === 200, 'New password cannot log in');

    echo "PASS: password change, view/input sharing, revocation, and existing accounting\n";
} finally {
    proc_terminate($process);
    foreach ($pipes as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }
    proc_close($process);
    if ($previousDatabasePath === false) {
        putenv('POKERNOTE_DB_PATH');
    } else {
        putenv('POKERNOTE_DB_PATH=' . $previousDatabasePath);
    }
    if (is_file($databasePath)) {
        unlink($databasePath);
    }
    if (is_file($serverLogPath)) {
        unlink($serverLogPath);
    }
}

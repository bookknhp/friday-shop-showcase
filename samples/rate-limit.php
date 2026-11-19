<?php
// Sanitized architecture excerpt: Two-tier rate limiting
// 1. SQLite-backed brute-force protection with exponential backoff for auth endpoints.
// 2. Zero-DB-lock limiter (APCu or flock JSON files) for high-frequency endpoints.

// Assumes clientIpBucket() & writeToLog() helpers are loaded by the app bootstrap.

function ensureLoginSecurityTable(PDO $pdo): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }
    $flagFile = dirname(__DIR__) . '/storage/.rate_limit_schema.flag';
    if (file_exists($flagFile)) {
        $ensured = true;
        return;
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS auth_rate_limits (
        key_hash TEXT PRIMARY KEY,
        failures INTEGER NOT NULL DEFAULT 0,
        first_attempt INTEGER NOT NULL,
        last_attempt INTEGER NOT NULL,
        locked_until INTEGER NOT NULL DEFAULT 0
    )");
    @file_put_contents($flagFile, '1');
    $ensured = true;
}

function securityKey(string $type, string $value): string
{
    return hash('sha256', $type . ':' . strtolower(trim($value)));
}

function clientIp(): string
{
    // Visitor address behind Cloudflare: CF-Connecting-IP is only trusted when the
    // connection comes from a verified proxy range, with bucketed client IP handling.
    return clientIpBucket() ?: '0.0.0.0';
}

function loginRateLimit(PDO $pdo, string $email): array
{
    ensureLoginSecurityTable($pdo);
    $now = time();
    $keys = [securityKey('ip', clientIp()), securityKey('email', $email)];
    $lockedUntil = 0;
    foreach ($keys as $key) {
        $stmt = $pdo->prepare('SELECT locked_until FROM auth_rate_limits WHERE key_hash = ?');
        $stmt->execute([$key]);
        $lockedUntil = max($lockedUntil, (int)($stmt->fetchColumn() ?: 0));
    }
    return [$lockedUntil > $now, $lockedUntil];
}

function recordLoginFailure(PDO $pdo, string $email): void
{
    ensureLoginSecurityTable($pdo);
    $now = time();
    foreach ([securityKey('ip', clientIp()), securityKey('email', $email)] as $key) {
        $stmt = $pdo->prepare('SELECT failures, first_attempt FROM auth_rate_limits WHERE key_hash = ?');
        $stmt->execute([$key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $failures = $row ? (int)$row['failures'] : 0;
        $first = $row ? (int)$row['first_attempt'] : $now;
        if ($now - $first >= 900) {
            $failures = 0;
            $first = $now;
        }
        $failures++;
        $lock = $failures >= 5 ? $now + min(900, 30 * (2 ** min($failures - 5, 5))) : 0;
        $upsert = $pdo->prepare(
            'INSERT INTO auth_rate_limits (key_hash, failures, first_attempt, last_attempt, locked_until)
             VALUES (?, ?, ?, ?, ?)
             ON CONFLICT(key_hash) DO UPDATE SET
               failures=excluded.failures,
               first_attempt=excluded.first_attempt,
               last_attempt=excluded.last_attempt,
               locked_until=excluded.locked_until'
        );
        $upsert->execute([$key, $failures, $first, $now, $lock]);
    }
}

function clearLoginFailures(PDO $pdo, string $email): void
{
    if (!$pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='auth_rate_limits'")->fetchColumn()) {
        return;
    }
    $stmt = $pdo->prepare('DELETE FROM auth_rate_limits WHERE key_hash IN (?, ?)');
    $stmt->execute([securityKey('ip', clientIp()), securityKey('email', $email)]);
}

function enforceEndpointRateLimit(PDO $pdo, string $endpoint, int $maxAttempts, int $windowSeconds, int $lockSeconds = 0): void
{
    ensureLoginSecurityTable($pdo);
    $key = securityKey('endpoint:' . $endpoint, clientIp());
    $now = time();
    $stmt = $pdo->prepare('SELECT failures, first_attempt, locked_until FROM auth_rate_limits WHERE key_hash = ?');
    $stmt->execute([$key]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row && (int)$row['locked_until'] > $now) {
        writeToLog("Rate limit blocked endpoint {$endpoint}", 'SECURITY_WARNING');
        http_response_code(429);
        header('Retry-After: ' . max(1, (int)$row['locked_until'] - $now));
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Too many requests. Please try again later.']);
        exit;
    }

    $failures = $row ? (int)$row['failures'] : 0;
    $first = $row ? (int)$row['first_attempt'] : $now;
    if ($now - $first >= $windowSeconds) {
        $failures = 0;
        $first = $now;
    }
    $failures++;
    $lockedUntil = ($failures > $maxAttempts && $lockSeconds > 0) ? $now + $lockSeconds : 0;
    $upsert = $pdo->prepare(
        'INSERT INTO auth_rate_limits (key_hash, failures, first_attempt, last_attempt, locked_until)
         VALUES (?, ?, ?, ?, ?)
         ON CONFLICT(key_hash) DO UPDATE SET
           failures=excluded.failures,
           first_attempt=excluded.first_attempt,
           last_attempt=excluded.last_attempt,
           locked_until=excluded.locked_until'
    );
    $upsert->execute([$key, $failures, $first, $now, $lockedUntil]);
    if ($failures > $maxAttempts) {
        writeToLog("Rate limit exceeded endpoint {$endpoint}", 'SECURITY_WARNING');
        http_response_code(429);
        header('Retry-After: ' . max(1, $lockedUntil ? $lockSeconds : $windowSeconds));
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Too many requests. Please try again later.']);
        exit;
    }
}

/**
 * Zero-DB-lock rate limiter for high-frequency public tracking/chat endpoints.
 * Uses APCu when available or per-bucket lock files so the primary SQLite DB is
 * never write-locked by background telemetry.
 */
function enforceLightweightRateLimit(string $endpoint, int $maxAttempts, int $windowSeconds, int $lockSeconds = 0): void
{
    $key = securityKey('endpoint:' . $endpoint, clientIp());
    $now = time();

    if (function_exists('apcu_fetch') && function_exists('apcu_store')) {
        $apcuKey = 'app_rl_' . $key;
        $row = apcu_fetch($apcuKey);
        if (!is_array($row)) {
            $row = null;
        }
        if ($row && (int)($row['locked_until'] ?? 0) > $now) {
            http_response_code(429);
            header('Retry-After: ' . max(1, (int)$row['locked_until'] - $now));
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Too many requests. Please try again later.']);
            exit;
        }
        $failures = $row ? (int)($row['failures'] ?? 0) : 0;
        $first = $row ? (int)($row['first_attempt'] ?? $now) : $now;
        if ($now - $first >= $windowSeconds) {
            $failures = 0;
            $first = $now;
        }
        $failures++;
        $lockedUntil = ($failures > $maxAttempts && $lockSeconds > 0) ? $now + $lockSeconds : 0;
        apcu_store($apcuKey, [
            'failures' => $failures,
            'first_attempt' => $first,
            'last_attempt' => $now,
            'locked_until' => $lockedUntil,
        ], max($windowSeconds, $lockSeconds) + 60);

        if ($failures > $maxAttempts) {
            http_response_code(429);
            header('Retry-After: ' . max(1, $lockedUntil ? $lockSeconds : $windowSeconds));
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Too many requests. Please try again later.']);
            exit;
        }
        return;
    }

    $dir = dirname(__DIR__) . '/storage/rate_limits';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $file = $dir . '/' . substr($key, 0, 24) . '.json';
    $fp = @fopen($file, 'c+');
    if ($fp === false) {
        return;
    }
    if (!@flock($fp, LOCK_EX)) {
        fclose($fp);
        return;
    }

    $raw = stream_get_contents($fp);
    $row = ($raw !== false && $raw !== '') ? json_decode($raw, true) : null;
    if (!is_array($row)) {
        $row = null;
    }

    if ($row && (int)($row['locked_until'] ?? 0) > $now) {
        flock($fp, LOCK_UN);
        fclose($fp);
        http_response_code(429);
        header('Retry-After: ' . max(1, (int)$row['locked_until'] - $now));
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Too many requests. Please try again later.']);
        exit;
    }

    $failures = $row ? (int)($row['failures'] ?? 0) : 0;
    $first = $row ? (int)($row['first_attempt'] ?? $now) : $now;
    if ($now - $first >= $windowSeconds) {
        $failures = 0;
        $first = $now;
    }
    $failures++;
    $lockedUntil = ($failures > $maxAttempts && $lockSeconds > 0) ? $now + $lockSeconds : 0;

    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode([
        'failures' => $failures,
        'first_attempt' => $first,
        'last_attempt' => $now,
        'locked_until' => $lockedUntil,
    ]));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    if (rand(1, 200) === 1) {
        foreach (@glob($dir . '/*.json') ?: [] as $oldFile) {
            if ($now - (int)@filemtime($oldFile) > 3600) {
                @unlink($oldFile);
            }
        }
    }

    if ($failures > $maxAttempts) {
        writeToLog("Rate limit exceeded endpoint {$endpoint}", 'SECURITY_WARNING');
        http_response_code(429);
        header('Retry-After: ' . max(1, $lockedUntil ? $lockSeconds : $windowSeconds));
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Too many requests. Please try again later.']);
        exit;
    }
}

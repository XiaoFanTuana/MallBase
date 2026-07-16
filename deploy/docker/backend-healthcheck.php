<?php

declare(strict_types=1);

/** @var array<string, mixed> $fileEnv */
$fileEnv = is_file('/app/.env')
    ? (parse_ini_file('/app/.env', false, INI_SCANNER_RAW) ?: [])
    : [];

$value = static function (string $key, string $default = '') use ($fileEnv): string {
    if (array_key_exists($key, $fileEnv)) {
        return trim((string) $fileEnv[$key]);
    }

    $runtime = getenv($key);
    return $runtime === false ? $default : trim((string) $runtime);
};

$enabled = static function (string $key) use ($value): bool {
    return filter_var($value($key, 'false'), FILTER_VALIDATE_BOOLEAN);
};

$checkRedis = static function (
    string $host,
    int $port,
    string $password,
    int $database,
    string $label
): void {
    if (!class_exists(Redis::class)) {
        throw new RuntimeException('Redis extension is unavailable');
    }

    $redis = new Redis();
    if (!$redis->connect($host, $port, 2.0)) {
        throw new RuntimeException("{$label} connection failed");
    }

    try {
        if ($password !== '' && !$redis->auth($password)) {
            throw new RuntimeException("{$label} authentication failed");
        }
        if (!$redis->select($database)) {
            throw new RuntimeException("{$label} database selection failed");
        }
        if ($redis->ping() === false) {
            throw new RuntimeException("{$label} ping failed");
        }
    } finally {
        $redis->close();
    }
};

try {
    $httpPort = (int) $value('SWOOLE_HTTP_PORT', '8080');
    $socket = @fsockopen('127.0.0.1', $httpPort, $errorCode, $errorMessage, 2.0);
    if ($socket === false) {
        throw new RuntimeException("Swoole HTTP check failed: {$errorMessage} ({$errorCode})");
    }
    fclose($socket);

    $installed = is_file('/app/runtime/install/install.lock');
    $dbHost = $value('DB_HOST');
    $dbName = $value('DB_NAME');
    $dbUser = $value('DB_USER');

    if ($dbHost !== '' && $dbName !== '' && $dbUser !== '') {
        $dbPort = (int) $value('DB_PORT', '3306');
        $charset = $value('DB_CHARSET', 'utf8mb4');
        $pdo = new PDO(
            "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset={$charset}",
            $dbUser,
            $value('DB_PASS'),
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 3,
            ]
        );
        $pdo->query('SELECT 1');

        if ($installed) {
            $prefix = $value('DB_PREFIX', 'mb_');
            if (preg_match('/^[A-Za-z0-9_]*$/', $prefix) !== 1) {
                throw new RuntimeException('Database prefix is invalid');
            }
            $pdo->query("SELECT 1 FROM `{$prefix}setting` LIMIT 1");
        }
    } elseif ($installed) {
        throw new RuntimeException('Database configuration is incomplete');
    }

    $redisHost = $value('REDIS_HOST');
    if ($redisHost !== '') {
        $checkRedis(
            $redisHost,
            (int) $value('REDIS_PORT', '6379'),
            $value('REDIS_PASSWORD'),
            (int) $value('REDIS_CACHE_DB', '0'),
            'Cache Redis'
        );
    } elseif ($installed) {
        throw new RuntimeException('Redis configuration is incomplete');
    }

    if ($enabled('SWOOLE_QUEUE_ENABLE') && $value('QUEUE_CONNECTION', 'sync') === 'redis') {
        $checkRedis(
            $value('QUEUE_REDIS_HOST', $redisHost),
            (int) $value('QUEUE_REDIS_PORT', '6379'),
            $value('QUEUE_REDIS_PASSWORD'),
            (int) $value('QUEUE_REDIS_SELECT', '1'),
            'Queue Redis'
        );
    }

    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, '[mallbase-healthcheck] ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

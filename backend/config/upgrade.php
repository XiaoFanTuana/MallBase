<?php

declare(strict_types=1);

$installLockPath = runtime_path() . 'install' . DIRECTORY_SEPARATOR . 'install.lock';
$isInstalled = is_file($installLockPath);
$queueOverride = trim((string) env('MALLBASE_UPGRADE_QUEUE_NAMES', ''));
$queueSource = $queueOverride === '' ? (string) env('QUEUE_REDIS_QUEUE', 'default') : $queueOverride;
$queueNames = array_values(array_unique(array_filter(
    array_map('trim', explode(',', $queueSource)),
    static fn(string $name): bool => $name !== '',
)));
$connectionOverride = trim((string) env('MALLBASE_UPGRADE_QUEUE_CONNECTIONS', ''));
$connectionSource = $connectionOverride === ''
    ? (string) config('queue.default', 'sync')
    : $connectionOverride;
$queueConnections = array_values(array_unique(array_filter(
    array_map('trim', explode(',', $connectionSource)),
    static fn(string $name): bool => $name !== '',
)));
$roleOverride = trim((string) env('MALLBASE_UPGRADE_REQUIRED_RUNTIME_ROLES', ''));
if ($roleOverride !== '') {
    $requiredRuntimeRoles = array_values(array_unique(array_filter(
        array_map('trim', explode(',', $roleOverride)),
        static fn(string $name): bool => $name !== '',
    )));
} else {
    $requiredRuntimeRoles = ['http'];
    if (array_filter(
        $queueConnections,
        static fn(string $connection): bool => strtolower($connection) !== 'sync',
    ) !== []) {
        $requiredRuntimeRoles[] = 'queue';
    }
    if (filter_var(env('CRON_ENABLE', false), FILTER_VALIDATE_BOOLEAN)) {
        $requiredRuntimeRoles[] = 'cron';
    }
}

return [
    // Task 6A bootstrap will enable this only after the Agent binary, shared
    // state directory and immutable runtime markers are mounted in place.
    'enabled' => $isInstalled && filter_var(
        env('MALLBASE_UPGRADE_RUNTIME_ENABLE', false),
        FILTER_VALIDATE_BOOLEAN,
    ),
    // Upgrade cookie mutations use this deployment-controlled origin and never
    // infer authority from client-supplied Forwarded/X-Forwarded-* headers.
    'public_origin' => rtrim(trim((string) env('SITE_URL', '')), '/'),
    'runtime_owner_heartbeat_ttl' => 15,
    'runtime_retirement_window' => 15,
    'worker_heartbeat_ttl' => 15,
    'pause_ack_timeout' => 20,
    'queue_names' => $queueNames,
    'queue_connections' => $queueConnections,
    'required_runtime_roles' => $requiredRuntimeRoles,
    'operation_owner_window' => 15,
    'agent_nonce_lifetime' => 300,
    'migration_lock_timeout' => 2,
    'dump_executable' => (string) env('MALLBASE_UPGRADE_DUMP_EXECUTABLE', '/usr/bin/mariadb-dump'),
    'setpriv_executable' => (string) env('MALLBASE_UPGRADE_SETPRIV_EXECUTABLE', '/usr/bin/setpriv'),
    'persistent_roots' => [
        'install' => runtime_path() . 'install',
        'local_storage' => runtime_path() . 'storage',
        'runtime_backup' => runtime_path() . 'backup',
        'public_storage' => rtrim(public_path(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'storage',
        'uploads' => rtrim(public_path(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'uploads',
        'cert' => root_path() . 'storage' . DIRECTORY_SEPARATOR . 'cert',
        'demo' => rtrim(public_path(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'static'
            . DIRECTORY_SEPARATOR . 'demo',
    ],
];

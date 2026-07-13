<?php

declare(strict_types=1);

namespace app\service\upgrade;

use Closure;
use RuntimeException;
use Throwable;

/** Linux parent-death guarded process runner with a cross-container file lock. */
final readonly class UpgradeProcessSupervisor
{
    /** @var Closure(array<int,string>,array<string,string>,callable):array<string,mixed>|null */
    private ?Closure $executor;

    public function __construct(
        private string $setprivPath = '/usr/bin/setpriv',
        private string $phpPath = PHP_BINARY,
        private ?string $guardPath = null,
        ?Closure $executor = null,
        private int $maximumSeconds = 3600,
        private int $maximumStderrBytes = 65536,
    ) {
        if ($this->maximumSeconds < 1 || $this->maximumSeconds > 86400
            || $this->maximumStderrBytes < 1024 || $this->maximumStderrBytes > 1048576) {
            throw new RuntimeException('UPGRADE_PROCESS_CONFIG_INVALID');
        }
        $this->executor = $executor;
    }

    /** @param list<string> $arguments @return list<string> */
    public function command(string $executable, array $arguments, string $resultFile, int $parentPid): array
    {
        if ($parentPid < 1 || !$this->absolute($executable) || !$this->absolute($resultFile)) {
            throw new RuntimeException('UPGRADE_PROCESS_ARGUMENT_INVALID');
        }
        foreach ($arguments as $argument) {
            if (!is_string($argument) || str_contains($argument, "\0") || strlen($argument) > 4096) {
                throw new RuntimeException('UPGRADE_PROCESS_ARGUMENT_INVALID');
            }
        }
        $guard = $this->guardPath ?? dirname(__DIR__, 3) . '/bin/upgrade-process-guard.php';

        return [
            $this->setprivPath,
            '--pdeathsig',
            'SIGKILL',
            '--',
            $this->phpPath,
            $guard,
            '--expected-parent=' . $parentPid,
            '--executable=' . $executable,
            '--result-file=' . $resultFile,
            '--',
            ...$arguments,
        ];
    }

    /**
     * @param list<string> $arguments
     * @param array<string,string> $environment
     * @param Closure():void $heartbeat
     * @return array{state:string,exit_code:int,stderr:string}
     */
    public function run(
        string $lockPath,
        string $executable,
        array $arguments,
        string $resultFile,
        array $environment,
        Closure $heartbeat,
    ): array {
        if (!$this->absolute($lockPath) || !$this->absolute($resultFile)
            || dirname($lockPath) !== dirname($resultFile)) {
            throw new RuntimeException('UPGRADE_PROCESS_ARGUMENT_INVALID');
        }
        $lock = $this->openLock($lockPath);
        if (!@chmod($lockPath, 0660)) {
            fclose($lock);
            throw new RuntimeException('UPGRADE_PROCESS_LOCK_UNAVAILABLE');
        }
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);

            return ['state' => 'running', 'exit_code' => -1, 'stderr' => ''];
        }
        if (!$this->namedLockMatches($lock, $lockPath)) {
            flock($lock, LOCK_UN);
            fclose($lock);
            throw new RuntimeException('UPGRADE_PROCESS_LOCK_UNAVAILABLE');
        }
        try {
            $command = $this->command($executable, $arguments, $resultFile, getmypid());
            if ($this->executor !== null) {
                $executor = $this->executor;
                $result = $executor($command, $this->minimalEnvironment($environment), $heartbeat);

                return $this->normalizeResult($result);
            }
            if (PHP_OS_FAMILY !== 'Linux' || !function_exists('posix_getppid') || !function_exists('pcntl_exec')) {
                throw new RuntimeException('UPGRADE_PROCESS_GUARD_UNAVAILABLE');
            }
            foreach ([$this->setprivPath, $this->phpPath, $command[5], $executable] as $path) {
                $this->validateExecutable($path);
            }

            return $this->execute($command, $this->minimalEnvironment($environment), $heartbeat);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** Proves no process currently owns the shared operation flock. */
    public function lockReleased(string $lockPath): bool
    {
        if (!$this->absolute($lockPath)) {
            throw new RuntimeException('UPGRADE_PROCESS_ARGUMENT_INVALID');
        }
        $lock = $this->openLock($lockPath);
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                return false;
            }
            if (!$this->namedLockMatches($lock, $lockPath)) {
                throw new RuntimeException('UPGRADE_PROCESS_LOCK_UNAVAILABLE');
            }
            flock($lock, LOCK_UN);

            return true;
        } finally {
            fclose($lock);
        }
    }

    /** @return resource */
    private function openLock(string $path)
    {
        $before = @lstat($path);
        if (is_array($before) && (($before['mode'] & 0170000) !== 0100000
            || ($before['nlink'] ?? 0) !== 1)) {
            throw new RuntimeException('UPGRADE_PROCESS_LOCK_UNAVAILABLE');
        }
        $lock = @fopen($path, 'c+b');
        if (!is_resource($lock)) {
            throw new RuntimeException('UPGRADE_PROCESS_LOCK_UNAVAILABLE');
        }
        $opened = fstat($lock);
        $named = @lstat($path);
        if (!is_array($opened) || !is_array($named)
            || ($opened['mode'] & 0170000) !== 0100000 || ($opened['nlink'] ?? 0) !== 1
            || ($opened['dev'] ?? null) !== ($named['dev'] ?? null)
            || ($opened['ino'] ?? null) !== ($named['ino'] ?? null)) {
            fclose($lock);
            throw new RuntimeException('UPGRADE_PROCESS_LOCK_UNAVAILABLE');
        }

        return $lock;
    }

    /** @param resource $lock */
    private function namedLockMatches($lock, string $path): bool
    {
        $opened = fstat($lock);
        $named = @lstat($path);

        return is_array($opened) && is_array($named)
            && ($opened['mode'] & 0170000) === 0100000
            && ($opened['nlink'] ?? 0) === 1
            && ($opened['dev'] ?? null) === ($named['dev'] ?? null)
            && ($opened['ino'] ?? null) === ($named['ino'] ?? null);
    }

    /** @param list<string> $command @param array<string,string> $environment @return array{state:string,exit_code:int,stderr:string} */
    private function execute(array $command, array $environment, Closure $heartbeat): array
    {
        $pipes = [];
        $process = proc_open($command, [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', '/dev/null', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, null, $environment, ['bypass_shell' => true]);
        if (!is_resource($process) || !isset($pipes[2]) || !is_resource($pipes[2])) {
            throw new RuntimeException('UPGRADE_PROCESS_START_FAILED');
        }
        stream_set_blocking($pipes[2], false);
        $deadline = hrtime(true) + $this->maximumSeconds * 1_000_000_000;
        $nextHeartbeat = hrtime(true);
        $stderr = '';
        $exit = null;
        try {
            while (true) {
                $chunk = stream_get_contents($pipes[2]);
                if (is_string($chunk) && $chunk !== '') {
                    $stderr = substr($stderr . $chunk, -$this->maximumStderrBytes);
                }
                $status = proc_get_status($process);
                if (!is_array($status)) {
                    throw new RuntimeException('UPGRADE_PROCESS_STATUS_FAILED');
                }
                if (($status['running'] ?? false) !== true) {
                    $exit = is_int($status['exitcode'] ?? null) && $status['exitcode'] >= 0
                        ? $status['exitcode']
                        : null;
                    break;
                }
                $now = hrtime(true);
                if ($now >= $nextHeartbeat) {
                    $heartbeat();
                    $nextHeartbeat = $now + 1_000_000_000;
                }
                if ($now >= $deadline) {
                    proc_terminate($process, 15);
                    usleep(100000);
                    proc_terminate($process, 9);
                    throw new RuntimeException('UPGRADE_PROCESS_TIMEOUT');
                }
                usleep(10000);
            }
            $tail = stream_get_contents($pipes[2]);
            if (is_string($tail) && $tail !== '') {
                $stderr = substr($stderr . $tail, -$this->maximumStderrBytes);
            }
        } finally {
            fclose($pipes[2]);
            $closed = proc_close($process);
            if ($exit === null && is_int($closed) && $closed >= 0) {
                $exit = $closed;
            }
        }

        return ['state' => $exit === 0 ? 'completed' : 'failed', 'exit_code' => $exit ?? -1, 'stderr' => $stderr];
    }

    /** @param array<string,mixed> $result @return array{state:string,exit_code:int,stderr:string} */
    private function normalizeResult(array $result): array
    {
        $state = $result['state'] ?? null;
        $exit = $result['exit_code'] ?? null;
        $stderr = $result['stderr'] ?? null;
        if (!in_array($state, ['completed', 'failed', 'running'], true) || !is_int($exit) || !is_string($stderr)) {
            throw new RuntimeException('UPGRADE_PROCESS_RESULT_INVALID');
        }

        return ['state' => $state, 'exit_code' => $exit, 'stderr' => substr($stderr, -$this->maximumStderrBytes)];
    }

    /** @param array<string,string> $environment @return array<string,string> */
    private function minimalEnvironment(array $environment): array
    {
        $result = ['PATH' => '/usr/bin:/bin', 'LANG' => 'C', 'LC_ALL' => 'C'];
        foreach ($environment as $name => $value) {
            if (!in_array($name, ['MYSQL_PWD', 'TZ'], true) || !is_string($value)
                || str_contains($value, "\0") || strlen($value) > 4096) {
                throw new RuntimeException('UPGRADE_PROCESS_ENVIRONMENT_INVALID');
            }
            $result[$name] = $value;
        }

        return $result;
    }

    private function validateExecutable(string $path): void
    {
        if (!$this->absolute($path) || realpath($path) !== $path) {
            throw new RuntimeException('UPGRADE_PROCESS_EXECUTABLE_INVALID');
        }
        $stat = @lstat($path);
        if (!is_array($stat) || ($stat['mode'] & 0170000) !== 0100000 || ($stat['mode'] & 06022) !== 0
            || ($stat['nlink'] ?? 0) !== 1) {
            throw new RuntimeException('UPGRADE_PROCESS_EXECUTABLE_INVALID');
        }
        $directory = dirname($path);
        while ($directory !== '/') {
            $directoryStat = @lstat($directory);
            if (!is_array($directoryStat) || ($directoryStat['mode'] & 0170000) !== 0040000
                || ($directoryStat['mode'] & 0022) !== 0 || realpath($directory) !== $directory) {
                throw new RuntimeException('UPGRADE_PROCESS_EXECUTABLE_INVALID');
            }
            $directory = dirname($directory);
        }
        $this->assertNoCapabilities($path);
    }

    private function assertNoCapabilities(string $path): void
    {
        $getcap = is_file('/usr/sbin/getcap') ? '/usr/sbin/getcap' : (is_file('/sbin/getcap') ? '/sbin/getcap' : null);
        if ($getcap === null) {
            throw new RuntimeException('UPGRADE_PROCESS_GUARD_UNAVAILABLE');
        }
        $pipes = [];
        $process = proc_open([$getcap, $path], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['PATH' => '/usr/bin:/bin'], ['bypass_shell' => true]);
        if (!is_resource($process)) {
            throw new RuntimeException('UPGRADE_PROCESS_GUARD_UNAVAILABLE');
        }
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        if ($exit !== 0 || trim($stderr) !== '' || trim($stdout) !== '') {
            throw new RuntimeException('UPGRADE_PROCESS_EXECUTABLE_INVALID');
        }
    }

    private function absolute(string $path): bool
    {
        return $path !== '' && str_starts_with($path, '/') && !str_contains($path, "\0") && strlen($path) <= 4096;
    }
}

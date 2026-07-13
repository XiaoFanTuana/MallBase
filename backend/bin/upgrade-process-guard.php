<?php

declare(strict_types=1);

if (PHP_OS_FAMILY !== 'Linux' || !function_exists('posix_getppid')
    || !function_exists('posix_setsid') || !function_exists('pcntl_exec')) {
    exit(70);
}

$expectedParent = null;
$executable = null;
$resultFile = null;
$arguments = [];
$afterSeparator = false;
foreach (array_slice($argv, 1) as $argument) {
    if ($afterSeparator) {
        $arguments[] = $argument;
        continue;
    }
    if ($argument === '--') {
        $afterSeparator = true;
    } elseif (str_starts_with($argument, '--expected-parent=')) {
        $raw = substr($argument, strlen('--expected-parent='));
        $expectedParent = preg_match('/^[1-9][0-9]*$/D', $raw) === 1 ? (int) $raw : null;
    } elseif (str_starts_with($argument, '--executable=')) {
        $executable = substr($argument, strlen('--executable='));
    } elseif (str_starts_with($argument, '--result-file=')) {
        $resultFile = substr($argument, strlen('--result-file='));
    } else {
        exit(64);
    }
}

$validAbsolute = static fn(mixed $value): bool => is_string($value) && $value !== ''
    && str_starts_with($value, '/') && !str_contains($value, "\0") && strlen($value) <= 4096;
$validateExecutable = static function (string $path) use ($validAbsolute): bool {
    if (!$validAbsolute($path) || realpath($path) !== $path) {
        return false;
    }
    $stat = @lstat($path);
    if (!is_array($stat) || ($stat['mode'] & 0170000) !== 0100000
        || ($stat['mode'] & 06022) !== 0 || ($stat['nlink'] ?? 0) !== 1) {
        return false;
    }
    for ($directory = dirname($path); $directory !== '/'; $directory = dirname($directory)) {
        $directoryStat = @lstat($directory);
        if (!is_array($directoryStat) || ($directoryStat['mode'] & 0170000) !== 0040000
            || ($directoryStat['mode'] & 0022) !== 0 || realpath($directory) !== $directory) {
            return false;
        }
    }
    $getcap = is_file('/usr/sbin/getcap') ? '/usr/sbin/getcap' : (is_file('/sbin/getcap') ? '/sbin/getcap' : null);
    if ($getcap === null) {
        return false;
    }
    $pipes = [];
    $process = proc_open([$getcap, $path], [
        0 => ['file', '/dev/null', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, null, ['PATH' => '/usr/bin:/bin'], ['bypass_shell' => true]);
    if (!is_resource($process)) {
        return false;
    }
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return proc_close($process) === 0 && trim($stdout) === '' && trim($stderr) === '';
};

if (!is_int($expectedParent) || $expectedParent !== posix_getppid()
    || !$validAbsolute($resultFile) || !is_dir(dirname($resultFile))
    || realpath(dirname($resultFile)) !== dirname($resultFile)
    || !$validateExecutable((string) $executable)) {
    exit(77);
}
foreach ($arguments as $argument) {
    if (!is_string($argument) || str_contains($argument, "\0") || strlen($argument) > 4096) {
        exit(64);
    }
}
if (posix_setsid() < 0 || $expectedParent !== posix_getppid() || !$validateExecutable($executable)) {
    exit(70);
}

$environment = ['PATH' => '/usr/bin:/bin', 'LANG' => 'C', 'LC_ALL' => 'C'];
$password = getenv('MYSQL_PWD');
if (is_string($password)) {
    $environment['MYSQL_PWD'] = $password;
}

pcntl_exec($executable, ['--result-file=' . $resultFile, ...$arguments], $environment);
exit(71);

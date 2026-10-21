<?php
declare(strict_types=1);

namespace Wiki\Process;

/**
 * Runs a command given as an argument ARRAY (never through a shell), with an explicit working
 * directory, a fully specified environment, a hard timeout and capped output capture.
 */
final class ProcessRunner
{
    /**
     * @param list<string> $command
     * @param array<string,string> $env complete environment (nothing is inherited)
     */
    public function run(array $command, ?string $cwd, array $env, int $timeoutSeconds, int $maxOutputBytes = 1048576): ProcessResult
    {
        if (!function_exists('proc_open')) {
            return new ProcessResult(-1, '', 'proc_open is disabled', false, false, false);
        }
        $spec = [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = @proc_open($command, $spec, $pipes, $cwd, $env);
        if (!is_resource($proc)) {
            return new ProcessResult(-1, '', 'Failed to start process', false, false, false);
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $out = '';
        $err = '';
        $truncated = false;
        $timedOut = false;
        $deadline = microtime(true) + $timeoutSeconds;
        $open = [1 => $pipes[1], 2 => $pipes[2]];

        while ($open) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                $timedOut = true;
                break;
            }
            $read = array_values($open);
            $w = $e = null;
            $n = @stream_select($read, $w, $e, 0, (int) min(200000, $remaining * 1e6));
            if ($n === false) {
                break;
            }
            foreach ($read as $stream) {
                $chunk = fread($stream, 65536);
                $idx = $stream === $pipes[1] ? 1 : 2;
                if ($chunk === '' || $chunk === false) {
                    if (feof($stream)) {
                        unset($open[$idx]);
                    }
                    continue;
                }
                if ($idx === 1) {
                    if (strlen($out) < $maxOutputBytes) { $out .= $chunk; } else { $truncated = true; }
                } else {
                    if (strlen($err) < $maxOutputBytes) { $err .= $chunk; } else { $truncated = true; }
                }
            }
        }

        $status = proc_get_status($proc);
        if ($timedOut && $status['running']) {
            self::killTree((int) $status['pid']);
        }
        foreach ($pipes as $p) {
            if (is_resource($p)) {
                fclose($p);
            }
        }
        $exit = proc_close($proc);
        if (!$timedOut && isset($status['exitcode']) && $status['exitcode'] >= 0 && !$status['running']) {
            $exit = $status['exitcode'];
        }
        return new ProcessResult($timedOut ? -1 : $exit, $out, $err, $timedOut, $truncated);
    }

    /** Terminate a process and its descendants (Linux /proc based; falls back to the process only). */
    public static function killTree(int $pid): void
    {
        $pids = self::descendants($pid);
        $pids[] = $pid;
        foreach ($pids as $p) {
            @posix_kill($p, defined('SIGTERM') ? SIGTERM : 15);
        }
        usleep(300000);
        foreach ($pids as $p) {
            if (@posix_kill($p, 0)) {
                @posix_kill($p, defined('SIGKILL') ? SIGKILL : 9);
            }
        }
    }

    /** @return list<int> */
    private static function descendants(int $root): array
    {
        if (!is_dir('/proc')) {
            return [];
        }
        $parents = [];
        foreach (@scandir('/proc') ?: [] as $entry) {
            if (!ctype_digit($entry)) {
                continue;
            }
            $stat = @file_get_contents("/proc/$entry/stat");
            if ($stat !== false && preg_match('/^\d+ \(.*\) \S (\d+)/s', $stat, $m)) {
                $parents[(int) $entry] = (int) $m[1];
            }
        }
        $found = [];
        $queue = [$root];
        while ($queue) {
            $cur = array_shift($queue);
            foreach ($parents as $pid => $ppid) {
                if ($ppid === $cur && !in_array($pid, $found, true)) {
                    $found[] = $pid;
                    $queue[] = $pid;
                }
            }
        }
        return $found;
    }
}

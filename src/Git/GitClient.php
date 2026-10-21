<?php
declare(strict_types=1);

namespace Wiki\Git;

use Wiki\Config\Settings;
use Wiki\Filesystem\AtomicFile;
use Wiki\Filesystem\CorruptFileException;
use Wiki\Filesystem\Fs;
use Wiki\Process\ProcessResult;
use Wiki\Process\ProcessRunner;
use Wiki\Support\Paths;
use Wiki\Support\Redactor;

/**
 * Thin, defensive wrapper around the Git CLI.
 *
 *  - argument arrays only, no shell; repository URLs are passed after "--".
 *  - hooks disabled, only the required transports allowed, prompts disabled.
 *  - HOME and system/global git config are neutralised.
 *  - credentials are injected through environment config (never argv, never persisted in the
 *    repository config) and registered with the log redactor.
 *  - versions are materialised with read-tree/checkout-index from a bare cache: no .git directory,
 *    no hooks and no repository-provided executables ever exist inside a published version.
 */
final class GitClient
{
    public const REF = 'refs/wiki/head';

    public function __construct(
        private Paths $paths,
        private Settings $settings,
        private ProcessRunner $runner = new ProcessRunner(),
    ) {
    }

    public function available(): bool
    {
        $r = $this->exec(['--version'], null, 15);
        return $r->ok() && str_starts_with(trim($r->stdout), 'git version');
    }

    /** Resolve the remote's default branch (also proves reachability and credentials). */
    public function defaultBranch(string $url, int $timeout): string
    {
        $r = $this->exec(['ls-remote', '--symref', '--', $url, 'HEAD'], null, $timeout, $url);
        $this->assertOk($r);
        if (preg_match('#^ref:\s+refs/heads/(\S+)\s+HEAD#m', $r->stdout, $m)) {
            return $m[1];
        }
        throw new GitException('missing_ref', 'The repository has no default branch (is it empty?).');
    }

    public function ensureCache(): void
    {
        $dir = $this->paths->repoCache;
        if (is_dir($dir . '/objects')) {
            return;
        }
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new GitException('permission', GitException::safeMessageFor('permission'));
        }
        $this->assertOk($this->exec(['init', '--bare', '--quiet', $dir], null, 60));
    }

    public function resetCache(): void
    {
        Fs::removeTree($this->paths->repoCache);
    }

    /** Fetch the configured branch into the bare cache and return the resolved commit SHA. */
    public function fetch(string $url, string $branch, int $timeout): string
    {
        $this->ensureCache();
        $args = ['--git-dir=' . $this->paths->repoCache, 'fetch', '--quiet', '--no-tags', '--no-write-fetch-head'];
        $depth = $this->settings->int('fetch_depth');
        if ($depth > 0) {
            $args[] = '--depth=' . $depth;
        }
        $args = array_merge($args, ['--', $url, '+refs/heads/' . $branch . ':' . self::REF]);
        $r = $this->exec($args, null, $timeout, $url);
        $this->assertOk($r, 'missing_ref_check');
        return $this->resolve(self::REF);
    }

    public function resolve(string $ref): string
    {
        $r = $this->exec(['--git-dir=' . $this->paths->repoCache, 'rev-parse', '--verify', '--quiet', $ref . '^{commit}'], null, 30);
        $sha = trim($r->stdout);
        if (!$r->ok() || !preg_match('/^[0-9a-f]{40}([0-9a-f]{24})?$/', $sha)) {
            throw new GitException('invalid_checkout', GitException::safeMessageFor('invalid_checkout'), $r->stderr);
        }
        return $sha;
    }

    /** Materialise commit $sha into $dest (created here) without any .git directory. */
    public function checkout(string $sha, string $dest, int $timeout): void
    {
        if (!preg_match('/^[0-9a-f]{40}([0-9a-f]{24})?$/', $sha)) {
            throw new GitException('invalid_checkout', GitException::safeMessageFor('invalid_checkout'));
        }
        if (!@mkdir($dest, 0750, true) && !is_dir($dest)) {
            throw new GitException('permission', GitException::safeMessageFor('permission'));
        }
        $index = $dest . '.index';
        $env = ['GIT_INDEX_FILE' => $index];
        try {
            // The cache is bare; checkout-index needs an explicit work tree (the destination).
            $git = ['--git-dir=' . $this->paths->repoCache, '--work-tree=' . rtrim($dest, '/')];
            $this->assertOk($this->exec(array_merge($git, ['read-tree', $sha]), null, $timeout, null, $env));
            $this->assertOk($this->exec(array_merge($git, ['checkout-index', '-a', '-f']), null, $timeout, null, $env));
        } finally {
            @unlink($index);
        }
    }

    /**
     * @param list<string> $args
     * @param array<string,string> $extraEnv
     */
    private function exec(array $args, ?string $cwd, int $timeout, ?string $credentialUrl = null, array $extraEnv = []): ProcessResult
    {
        $cmd = [$this->settings->string('git_binary'),
            '-c', 'core.hooksPath=/dev/null',
            '-c', 'core.fsmonitor=false',
            '-c', 'credential.helper=',
            '-c', 'protocol.allow=never',
            '-c', 'protocol.https.allow=always',
            '-c', 'transfer.fsckObjects=true',
            '-c', 'gc.auto=0',
            '-c', 'advice.detachedHead=false',
        ];
        if ($this->settings->bool('allow_ssh')) {
            array_push($cmd, '-c', 'protocol.ssh.allow=always');
        }
        if ($this->settings->bool('allow_local_urls')) {
            array_push($cmd, '-c', 'protocol.file.allow=always');
        }
        $cmd = array_merge($cmd, $args);

        $env = [
            'PATH' => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin',
            'HOME' => $this->paths->home,
            'LANG' => 'C',
            'LC_ALL' => 'C',
            'GIT_TERMINAL_PROMPT' => '0',
            'GIT_CONFIG_NOSYSTEM' => '1',
            'GIT_CONFIG_GLOBAL' => '/dev/null',
            'GIT_ASKPASS' => 'true',
            'SSH_ASKPASS' => 'true',
            'GIT_SSH_COMMAND' => 'ssh -o BatchMode=yes -o StrictHostKeyChecking=yes -o ConnectTimeout=20',
            'GIT_HTTP_LOW_SPEED_LIMIT' => '1000',
            'GIT_HTTP_LOW_SPEED_TIME' => '60',
            'GIT_OPTIONAL_LOCKS' => '0',
        ];
        if (!is_dir($this->paths->home)) {
            @mkdir($this->paths->home, 0750, true);
        }
        if ($credentialUrl !== null) {
            $env += $this->credentialEnv($credentialUrl);
        }
        $r = $this->runner->run($cmd, $cwd, $extraEnv + $env, $timeout);
        if (!$r->started) {
            throw new GitException('git_missing', GitException::safeMessageFor('git_missing'));
        }
        return $r;
    }

    /** @return array<string,string> */
    private function credentialEnv(string $url): array
    {
        $token = getenv('WIKI_GIT_TOKEN') ?: null;
        $user = getenv('WIKI_GIT_USERNAME') ?: 'x-access-token';
        if ($token === null) {
            try {
                $c = AtomicFile::readJson($this->paths->credentialsFile());
            } catch (CorruptFileException) {
                $c = null;
            }
            if (is_array($c) && is_string($c['token'] ?? null) && $c['token'] !== '') {
                $token = $c['token'];
                $user = is_string($c['username'] ?? null) && $c['username'] !== '' ? $c['username'] : $user;
            }
        }
        $parts = parse_url($url);
        if ($token === null || !isset($parts['scheme'], $parts['host']) || $parts['scheme'] !== 'https') {
            return [];
        }
        Redactor::addSecret($token);
        $scope = 'https://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '') . '/';
        return [
            'GIT_CONFIG_COUNT' => '1',
            'GIT_CONFIG_KEY_0' => 'http.' . $scope . '.extraHeader',
            'GIT_CONFIG_VALUE_0' => 'Authorization: Basic ' . base64_encode($user . ':' . $token),
        ];
    }

    private function assertOk(ProcessResult $r, string $mode = ''): void
    {
        if ($r->ok()) {
            return;
        }
        $detail = Redactor::redact(trim($r->stderr));
        $category = $this->classify($r, $detail);
        throw new GitException($category, GitException::safeMessageFor($category), mb_substr($detail, 0, 1500));
    }

    private function classify(ProcessResult $r, string $stderr): string
    {
        if ($r->timedOut) {
            return 'timeout';
        }
        $s = strtolower($stderr);
        return match (true) {
            str_contains($s, 'no space left') || str_contains($s, 'disk quota') => 'disk_space',
            str_contains($s, "couldn't find remote ref") || str_contains($s, 'remote branch') && str_contains($s, 'not found') => 'missing_ref',
            str_contains($s, 'could not resolve host') || str_contains($s, 'name or service not known') || str_contains($s, 'temporary failure in name resolution') => 'dns',
            str_contains($s, 'authentication failed') || str_contains($s, 'could not read username') || str_contains($s, 'permission denied (publickey') || str_contains($s, 'http 401') || str_contains($s, 'error: 401') || str_contains($s, 'returned error: 403') => 'auth',
            str_contains($s, 'repository not found') || str_contains($s, 'does not appear to be a git repository') || str_contains($s, 'returned error: 404') || str_contains($s, 'not found') => 'not_found',
            str_contains($s, 'permission denied') || str_contains($s, 'unable to create') || str_contains($s, 'read-only file system') => 'permission',
            str_contains($s, 'unable to access') || str_contains($s, 'failed to connect') || str_contains($s, 'connection') || str_contains($s, 'timed out') || str_contains($s, 'early eof') || str_contains($s, 'rpc failed') || str_contains($s, 'ssl') || str_contains($s, 'unexpected disconnect') => 'network',
            default => 'unknown',
        };
    }
}

<?php
declare(strict_types=1);

namespace Wiki\Config;

use Wiki\Filesystem\AtomicFile;
use Wiki\Support\Paths;

/**
 * Protected application settings. These are NOT the repository identity.
 *
 * Sources (later wins): built-in defaults, storage/config/settings.json (edited by the
 * server administrator only), environment variables WIKI_<KEY_UPPERCASE>.
 * No HTTP request can change them.
 */
final class Settings
{
    public const DEFAULTS = [
        'sync_mode' => 'detached',            // detached | manual
        'sync_interval_seconds' => 86400,     // 24 h
        'retry_cooldown_seconds' => 300,      // 5 min
        'sync_timeout_seconds' => 900,        // whole child-process runtime
        'git_op_timeout_seconds' => 300,      // single git command
        'git_binary' => 'git',
        'php_cli' => '',                      // path to php CLI; auto-detected when empty
        'fetch_depth' => 1,                   // 0 = full history
        'retain_previous_versions' => 2,
        'min_free_bytes' => 67108864,         // 64 MiB headroom before building a candidate
        'max_markdown_bytes' => 1048576,      // 1 MiB per page
        'max_rendered_bytes' => 4194304,
        'max_files' => 20000,
        'max_directory_depth' => 20,
        'max_index_files' => 5000,
        'max_index_text_chars' => 30000,
        'search_max_query_length' => 100,
        'search_max_results' => 20,
        'log_max_bytes' => 1048576,
        'log_keep_files' => 5,
        'allow_ssh' => false,
        'allowed_hosts' => [],                // empty = any public host
        'allow_private_hosts' => false,       // SSRF guard; leave false on shared networks
        'allow_local_urls' => false,          // file:// and local paths. TESTING ONLY.
        'status_endpoint' => true,
        'health_endpoint' => true,
        'site_title' => '',                   // empty = derived from the repository name
        'broken_link_marker' => true,
        'trusted_proxy_https' => false,       // honour X-Forwarded-Proto
    ];

    /** @var array<string,mixed> */
    private array $values;

    /** @param array<string,mixed> $overrides explicit overrides (tests) */
    public function __construct(Paths $paths, array $overrides = [])
    {
        $values = self::DEFAULTS;
        try {
            $file = AtomicFile::readJson($paths->settingsFile());
        } catch (\Throwable) {
            $file = null; // an unreadable settings file must not take the wiki down; defaults apply
        }
        if (is_array($file)) {
            foreach ($file as $k => $v) {
                if (array_key_exists($k, self::DEFAULTS) && $this->sameType($v, self::DEFAULTS[$k])) {
                    $values[$k] = $v;
                }
            }
        }
        foreach (self::DEFAULTS as $k => $default) {
            $env = getenv('WIKI_' . strtoupper($k));
            if ($env === false || $env === '') {
                continue;
            }
            $values[$k] = match (true) {
                is_bool($default) => filter_var($env, FILTER_VALIDATE_BOOLEAN),
                is_int($default) => (int) $env,
                is_array($default) => array_values(array_filter(array_map('trim', explode(',', $env)))),
                default => $env,
            };
        }
        foreach ($overrides as $k => $v) {
            if (array_key_exists($k, self::DEFAULTS)) {
                $values[$k] = $v;
            }
        }
        // Defensive bounds.
        $values['sync_interval_seconds'] = max(60, (int) $values['sync_interval_seconds']);
        $values['retry_cooldown_seconds'] = max(0, (int) $values['retry_cooldown_seconds']);
        $values['sync_timeout_seconds'] = max(30, (int) $values['sync_timeout_seconds']);
        $values['git_op_timeout_seconds'] = max(5, (int) $values['git_op_timeout_seconds']);
        $values['retain_previous_versions'] = max(0, (int) $values['retain_previous_versions']);
        if (!in_array($values['sync_mode'], ['detached', 'manual'], true)) {
            $values['sync_mode'] = 'detached';
        }
        $this->values = $values;
    }

    public function int(string $key): int { return (int) $this->values[$key]; }
    public function bool(string $key): bool { return (bool) $this->values[$key]; }
    public function string(string $key): string { return (string) $this->values[$key]; }

    /** @return list<string> */
    public function list(string $key): array { return array_values((array) $this->values[$key]); }

    private function sameType(mixed $a, mixed $b): bool
    {
        return gettype($a) === gettype($b);
    }
}

# Git-backed Markdown Wiki

A small, self-hosted, **read-only** documentation website. A Git repository of Markdown files is the single source of truth: folders become navigation, `README.md` becomes the homepage, images and attachments are served safely.

PHP 8.2+ · semantic HTML · CSS · vanilla JavaScript · **no database, no cron, no permanent worker, no frontend build step.**

## How it works (one paragraph)

On first visit you enter a Git URL once (immutable afterwards). The repo is fetched into a bare cache and checked out into an **immutable version directory** (`repositories/versions/<commit-sha>/`). A small pointer file (`repositories/current.json`, replaced atomically) names the active version. When a visitor arrives and the last successful sync is ≥ 24 h old, the request starts a **detached `php bin/sync.php` process** (holding a non-blocking `flock` for its whole life) and returns the page immediately. The child fetches, builds a candidate in a separate directory, validates it, builds navigation and search caches, and only then flips the pointer. Failures never touch the active version.

## Requirements

- PHP **8.2+** with `mbstring`, `json`, `dom`, `libxml`, `fileinfo`, `ctype` (all standard); `pcntl`/`posix` optional (extra watchdog / session detach).
- **Git** CLI on the server.
- A writable `storage/` and `repositories/` directory outside the web root.
- `proc_open` enabled (needed for detached updates; see *Shared hosting*).
- Disk space for at least two versions of the repository during updates (a 64 MiB free-space check runs before building a candidate).

## Install

```bash
composer install --no-dev --optimize-autoloader     # league/commonmark + ezyang/htmlpurifier
```

The tree ships with a vendored copy of both libraries and a small autoloader (`vendor/autoload.php`), so the wiki also runs where Composer is unavailable; `composer install` simply replaces it with Composer's autoloader.

Point the web server's **document root at `public/` only**.

### Apache

`public/.htaccess` is included (needs `mod_rewrite`, `AllowOverride All` or paste it into the vhost):

```apache
<VirtualHost *:443>
    ServerName wiki.example.com
    DocumentRoot /var/www/wiki/public
    <Directory /var/www/wiki/public>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

### Nginx + PHP-FPM

```nginx
server {
    server_name wiki.example.com;
    root /var/www/wiki/public;
    index index.php;

    location / { try_files $uri /index.php$is_args$args; }
    location ~ \.php$ {
        try_files /index.php =404;                  # only the front controller may execute
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root/index.php;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
    }
    location ~ /\. { deny all; }
    location ^~ /_wiki/static/ { try_files $uri =404; expires 7d; add_header Cache-Control "public"; }
}
```

### Development server

```bash
php -S 127.0.0.1:8080 -t public public/index.php
```

### Permissions

The user running PHP (FPM pool user) must own/write `storage/` and `repositories/`; nothing else needs to be writable.

```bash
chown -R www-data:www-data storage repositories
chmod -R u=rwX,g=,o= storage repositories
```

Run the application under an unprivileged account. If you can, keep `storage/config/installation.json` read-only (the app writes it with mode `0440`).

## First run

Open the site. Enter an `https://` repository URL (and optionally a branch; default branch otherwise). The clone happens synchronously **once**, under a setup lock. If it fails you stay on the setup page and nothing is stored. After success the repository and branch are permanent: no HTTP request can change them.

### Private repositories

Credentials never go in the URL and never reach the browser. Provide a token to the **server**, in one of these ways (first match wins):

1. Environment variables `WIKI_GIT_TOKEN` (and optionally `WIKI_GIT_USERNAME`, default `x-access-token`) in the PHP-FPM pool (`env[WIKI_GIT_TOKEN] = …`) — passed on to the sync child automatically.
2. `storage/config/credentials.json`: `{"username":"x-access-token","token":"…"}` with mode `0600`.

The token is sent as an HTTP header scoped to the repository host, redacted from all logs, and never persisted in the installation config. SSH URLs need `"allow_ssh": true` in `storage/config/settings.json` plus keys/known_hosts for the PHP user.

## Settings (administrator only)

`storage/config/settings.json` or environment variables `WIKI_<KEY>` (env wins). Defaults:

| Key | Default | Meaning |
| --- | --- | --- |
| `sync_mode` | `detached` | `detached` or `manual` (no automatic updates; run `php bin/sync.php` yourself) |
| `sync_interval_seconds` | 86400 | 24 h freshness window (min 60) |
| `retry_cooldown_seconds` | 300 | wait after a failed attempt |
| `sync_timeout_seconds` / `git_op_timeout_seconds` | 900 / 300 | whole run / single git command |
| `retain_previous_versions` | 2 | old versions kept besides the active one |
| `fetch_depth` | 1 | shallow fetch; 0 = full history |
| `max_markdown_bytes`, `max_rendered_bytes`, `max_files`, `max_directory_depth`, `max_index_files` | see `Settings.php` | resource limits |
| `search_max_query_length`, `search_max_results` | 100, 20 | search limits |
| `allowed_hosts` | `[]` | restrict repository hosts, e.g. `["github.com","*.example.org"]` |
| `site_title` | derived from repo name | header title |
| `status_endpoint`, `health_endpoint` | true | JSON endpoints |
| `trusted_proxy_https` | false | honour `X-Forwarded-Proto` (set when behind a TLS proxy) |
| `allow_local_urls` | false | **testing only**: `file://`/local paths as repository |

Settings never alter the repository identity.

## Content conventions

| Repository file | URL |
| --- | --- |
| `README.md` | `/` |
| `Getting Started.md` | `/Getting%20Started` |
| `Guides/Installation.md` | `/Guides/Installation` |
| `Guides/README.md` | `/Guides/` (directory landing page) |
| directory without README | `/Guides/` → generated overview listing |

- `/Page.md`, `/Dir/README`, and `/Dir` (for a directory) redirect (301) to the canonical URL. Unknown URLs return a real 404 inside the wiki layout.
- A file `A.md` and a directory `A/` may coexist: `/A` is the file, `/A/` the directory.
- Relative links/images resolve against the Markdown file's directory; a leading `/` means **repository root**. Missing targets render with a visible *broken* marker and never fail the page.
- Rendered with league/commonmark (CommonMark + GFM: tables, task lists, strikethrough, autolinks) then sanitized with HTML Purifier (no scripts, event handlers, `style`, iframes, `javascript:` URLs). Heading ids are generated server-side (duplicates get `-1`, `-2`…); the same ids feed the table of contents.
- Non-Markdown files are **not** pages. Images/PDF/audio/video are served inline from `/_wiki/asset/…`; SVG, JSON, CSV, archives etc. are served as sandboxed downloads with `nosniff`.
- `.git/` is never published. Other dotfiles are published unless excluded by an optional `.wikiignore` in the repository root (gitignore-like globs; `private/`, `drafts/*.md`, `*.draft.md`).
- **Limitations:** no footnotes, math, mermaid rendering, or custom heading-id syntax; relative URLs inside *raw HTML* (`<img src="…">`) are not rewritten (use Markdown syntax); iframes are not allowed.

## Background updates, locking and safety model

- Eligibility: `now − last_success ≥ interval` (exactly 24 h = eligible), evaluated in UTC epoch seconds; failures keep the old success time and add a retry cooldown; a clock that jumped backwards never stalls updates.
- The **OS file lock** (`storage/locks/sync.lock`) is authoritative; `sync-state.json` is informational. Requests only ever try a non-blocking lock, so a busy lock never delays a page.
- A crashed child releases the lock automatically; a stale `"running"` status is detected and recorded as `interrupted`.
- Retention keeps the active version plus `retain_previous_versions` older ones. A version being served holds a shared *lease* lock; cleanup skips leased versions.
- Caches live in `storage/cache/<version-sha>/` and are disposable.

Manual run / force: `php bin/sync.php [--force]` (exit codes: 0 ok, 1 failed, 2 locked, 3 not installed).

### Shared hosting

Some hosts disable `proc_open` or kill children after the request. The setup page shows a warning when `proc_open` is disabled, and the app then records `launch_unsupported` instead of silently blocking visitors. Test it on your host: visit the site after making the sync state old and check `storage/logs/wiki.log` for `sync_launched` and `sync_succeeded`. If it does not work, set `"sync_mode":"manual"` and trigger `php bin/sync.php` from the host's scheduler or an external trigger; the app will not fall back to a blocking pull.

### Docker

Any PHP-FPM/Apache image works as long as `git` and the PHP CLI are installed. Persist `storage/` and `repositories/` as volumes. After a container restart a stale status and abandoned staging directories are cleaned up automatically on the next sync.

## Backups

Back up `storage/config/` (immutable installation config, settings, credentials). The repository content can be re-cloned. Do not place backups under `public/`.

## Troubleshooting

- **Setup fails with "network"/"auth"/"not_found"**: see `storage/logs/wiki.log` (sanitized details). Private repos need a token (see above).
- **Page says "Documentation unavailable"**: no valid version dir. Check `repositories/versions/` and `repositories/history.json`.
- **Updates never run**: check `storage/logs/sync-process.log`, that `php` CLI is found (set `php_cli` in settings), and that `proc_open` is not in `disable_functions`.
- **Status JSON**: `/_wiki/status`; health: `/_health`.

## Upgrading

Replace the application files (`src/`, `public/`, `templates/`, `bin/`, `vendor/`), keep `storage/` and `repositories/`. Installation identity, active version and sync state are preserved; caches are keyed by renderer/sanitizer version and rebuild themselves.

## Recovery

- **Corrupt `sync-state.json`**: nothing to do. The damaged file is renamed `*.corrupt-<time>` and defaults apply; the version pointer decides what is served.
- **Restore a previous version**: `repositories/history.json` lists retained versions (newest first). Stop traffic or hold the lock (`flock storage/locks/sync.lock …`), then write `{"version":"<sha>","commit":"<sha>","activated_at":"…"}` to `repositories/current.json`. The next successful sync moves forward again.
- **Dangling pointer**: handled automatically; the newest retained complete version is re-activated under the sync lock.
- **Missing/corrupt `installation.json`**: the wiki shows a 503 recovery page and **never** offers setup again (so it cannot be re-pointed silently). Restore the file from backup. Only if you deliberately want a fresh installation: stop the site, delete `storage/state/installed.flag` and the remaining state, and run setup again.

## Tests

```bash
php tests/run.php            # all (needs git + php-cli; starts php -S on a random port)
php tests/run.php Freshness  # filter by name
```

Covers freshness rules (24 h boundary, cooldown, timezones, clock skew), path/URL traversal, symlink escapes, SSRF and option-injection validation, Markdown rewriting and sanitization, navigation and search, locks (including a killed holder), version retention and recovery, real-Git setup/sync/failure paths, the detached launcher, and HTTP behavior (CSRF, security headers, assets, corrupt/missing config). Browser-level checks (responsive layout, drawer, theme, keyboard search, no-JS browsing) were verified with Playwright/Chromium; those scripts are not part of the PHP suite. **Not covered by automation:** behavior on your actual production host (detached-process survival, permissions) — test it there.

## Security notes

CSP without inline script/style, `nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy`, CSRF token + Origin check on setup, canonical `realpath` containment (separator-terminated) for every page/asset, symlinks escaping the checkout are removed at validation and rejected at read time, no shell strings (argv arrays only), repository hooks and scripts are never executed (`core.hooksPath=/dev/null`, no working tree git operations on the active version), Git output is redacted before logging. Known limit: the SSRF guard resolves DNS before cloning but cannot prevent a DNS-rebinding race; use `allowed_hosts` where that matters.

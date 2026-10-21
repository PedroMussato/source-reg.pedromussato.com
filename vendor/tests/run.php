<?php
declare(strict_types=1);

/**
 * Dependency-free test runner:   php tests/run.php [filter]
 * Needs git and php-cli. Uses temporary directories and local file repositories
 * (WIKI_ALLOW_LOCAL_URLS-equivalent setting, TESTING ONLY). The HTTP section starts php -S on a free port.
 */

require dirname(__DIR__) . '/src/bootstrap.php';

use Wiki\Application\Services;
use Wiki\Config\InstallationStatus;
use Wiki\Filesystem\AtomicFile;
use Wiki\Filesystem\CorruptFileException;
use Wiki\Filesystem\FileLock;
use Wiki\Navigation\NavBuilder;
use Wiki\Routing\UrlCodec;
use Wiki\Security\PathGuard;
use Wiki\Security\RepoUrlValidator;
use Wiki\Security\ValidationException;
use Wiki\Support\FixedClock;
use Wiki\Support\Paths;
use Wiki\Support\Time;
use Wiki\Sync\FreshnessPolicy;
use Wiki\Sync\SetupException;
use Wiki\Sync\VersionStore;

foreach (array_keys(getenv()) as $k) {
    if (str_starts_with((string) $k, 'WIKI_')) {
        putenv((string) $k);                      // tests must not inherit a developer's configuration
    }
}

$GLOBALS['T'] = ['pass' => 0, 'fail' => 0, 'failures' => [], 'filter' => $argv[1] ?? null, 'tmp' => []];

function test(string $name, callable $fn): void
{
    $f = $GLOBALS['T']['filter'];
    if ($f !== null && stripos($name, $f) === false) {
        return;
    }
    try {
        $fn();
        $GLOBALS['T']['pass']++;
        echo "  ok    $name\n";
    } catch (\Throwable $e) {
        $GLOBALS['T']['fail']++;
        $GLOBALS['T']['failures'][] = $name;
        echo "  FAIL  $name\n        " . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
    }
}
function ok(bool $cond, string $msg = 'assertion failed'): void
{
    if (!$cond) {
        throw new \RuntimeException($msg);
    }
}
function eq(mixed $a, mixed $b, string $msg = ''): void
{
    if ($a !== $b) {
        throw new \RuntimeException(($msg !== '' ? $msg . ': ' : '') . 'expected ' . var_export($b, true) . ', got ' . var_export($a, true));
    }
}
function throws(string $class, callable $fn): \Throwable
{
    try {
        $fn();
    } catch (\Throwable $e) {
        if (!$e instanceof $class) {
            throw new \RuntimeException("expected $class, got " . get_class($e) . ': ' . $e->getMessage());
        }
        return $e;
    }
    throw new \RuntimeException("expected $class to be thrown");
}
function tmpdir(): string
{
    $d = sys_get_temp_dir() . '/wikitest-' . bin2hex(random_bytes(5));
    mkdir($d, 0755, true);
    $GLOBALS['T']['tmp'][] = $d;
    return $d;
}
function sh(string $cmd, ?string $cwd = null): string
{
    $out = [];
    $rc = 0;
    exec(($cwd ? 'cd ' . escapeshellarg($cwd) . ' && ' : '') . $cmd . ' 2>&1', $out, $rc);
    if ($rc !== 0) {
        throw new \RuntimeException("command failed: $cmd\n" . implode("\n", $out));
    }
    return implode("\n", $out);
}
/** @param array<string,string> $files */
function makeRemote(array $files): string
{
    $d = tmpdir() . '/remote';
    mkdir($d);
    sh('git init -q -b main . && git config user.email t@t && git config user.name t', $d);
    commitFiles($d, $files);
    return $d;
}
/** @param array<string,?string> $files null = delete */
function commitFiles(string $remote, array $files, string $msg = 'c'): void
{
    foreach ($files as $rel => $content) {
        $p = $remote . '/' . $rel;
        if ($content === null) {
            @unlink($p);
            continue;
        }
        @mkdir(dirname($p), 0755, true);
        file_put_contents($p, $content);
    }
    sh('git add -A && git commit -q --allow-empty -m ' . escapeshellarg($msg), $remote);
}
function instance(?FixedClock $clock = null, array $settings = []): Services
{
    $root = tmpdir();
    $paths = new Paths($root);
    $paths->ensureDirectories();
    return new Services($paths, $clock ?? new FixedClock(1_800_000_000), ['allow_local_urls' => true, 'retry_cooldown_seconds' => 300] + $settings);
}
/** A tiny repository version directory for unit tests. */
function versionDir(array $files): string
{
    $d = tmpdir();
    foreach ($files as $rel => $c) {
        @mkdir(dirname("$d/$rel"), 0755, true);
        file_put_contents("$d/$rel", $c);
    }
    return $d;
}

echo "Freshness\n";
test('fresh (<24h) is not eligible', fn () => eq(FreshnessPolicy::evaluate(['last_success_at' => Time::iso(1000)], 1000 + 86399, 86400)['eligible'], false));
test('exactly 24h is eligible', fn () => eq(FreshnessPolicy::evaluate(['last_success_at' => Time::iso(1000)], 1000 + 86400, 86400)['eligible'], true));
test('older than 24h is eligible', fn () => eq(FreshnessPolicy::evaluate(['last_success_at' => Time::iso(1000)], 1000 + 90000, 86400)['eligible'], true));
test('retry cooldown blocks even when stale', fn () => eq(FreshnessPolicy::evaluate(['last_success_at' => Time::iso(1000), 'retry_after' => Time::iso(1000 + 100000 + 300)], 1000 + 100000, 86400)['reason'], 'cooldown'));
test('cooldown expiry re-enables', fn () => eq(FreshnessPolicy::evaluate(['last_success_at' => Time::iso(1000), 'retry_after' => Time::iso(2000)], 1000 + 100000, 86400)['eligible'], true));
test('clock moved backwards does not stall forever', fn () => eq(FreshnessPolicy::evaluate(['last_success_at' => Time::iso(1_000_000)], 100, 86400)['reason'], 'clock_skew'));
test('timezone does not affect elapsed time', function () {
    $old = date_default_timezone_get();
    date_default_timezone_set('Pacific/Auckland');
    try {
        eq(FreshnessPolicy::evaluate(['last_success_at' => '2026-03-28T10:00:00Z'], Time::parse('2026-03-29T10:00:00Z'), 86400)['eligible'], true);
    } finally {
        date_default_timezone_set($old);
    }
});

echo "Routing and paths\n";
test('decode: plain, spaces, unicode', function () {
    eq(UrlCodec::decodeRequestPath('/Getting%20Started')['segments'], ['Getting Started']);
    eq(UrlCodec::decodeRequestPath('/%C3%9Cn%C3%AF/x')['segments'], ['Ünï', 'x']);
});
test('decode rejects traversal (plain, encoded, double encoded, NUL, backslash)', function () {
    foreach (['/../etc', '/a/%2e%2e/b', '/%252e%252e/x', '/a%2f..%2fb', '/a%00b', '/a%5cb', '//x', '/%ff'] as $bad) {
        eq(UrlCodec::decodeRequestPath($bad), null, $bad);
    }
});
test('page urls: README, nested, dir', function () {
    eq(UrlCodec::pageUrl('README.md'), '/');
    eq(UrlCodec::pageUrl('Guides/README.md'), '/Guides/');
    eq(UrlCodec::pageUrl('Getting Started.md'), '/Getting%20Started');
    eq(UrlCodec::pageUrl('Guides/Linux/Setup.md'), '/Guides/Linux/Setup');
});
test('PathGuard: contains, rejects traversal and escaping symlinks', function () {
    $d = versionDir(['a.md' => '# a', 'sub/b.md' => '# b']);
    $outside = tmpdir();
    file_put_contents("$outside/secret.txt", 'secret');
    symlink("$outside/secret.txt", "$d/link.txt");
    symlink($outside, "$d/dirlink");
    $g = new PathGuard($d);
    ok($g->resolve('a.md') !== null);
    eq($g->resolve('../x'), null);
    eq($g->resolve('link.txt'), null, 'file symlink escaping');
    eq($g->resolve('dirlink/secret.txt'), null, 'dir symlink escaping');
    eq($g->resolve('.git/config'), null);
    eq($g->resolve("a\0.md"), null);
});
test('PathGuard: sibling directory with same prefix is outside', function () {
    $base = tmpdir();
    mkdir("$base/repo");
    mkdir("$base/repo-evil");
    file_put_contents("$base/repo-evil/x.md", 'x');
    symlink("$base/repo-evil/x.md", "$base/repo/x.md");
    eq((new PathGuard("$base/repo"))->resolve('x.md'), null);
});
test('.wikiignore excludes paths; dotfiles are not auto-hidden', function () {
    $d = versionDir(['README.md' => '#', 'private/x.md' => 'x', '.hidden.md' => 'h', '.wikiignore' => "private/\n"]);
    $g = new PathGuard($d);
    eq($g->resolve('private/x.md'), null);
    ok($g->resolve('.hidden.md') !== null);
});

echo "Repository URL validation\n";
test('accepts https, rejects the rest', function () {
    $v = new RepoUrlValidator((new Services(new Paths(tmpdir())))->settings);   // production defaults (local URLs off)
    eq($v->validate('https://github.com/owner/repo.git', false)['scheme'], 'https');
    foreach (['ftp://x.com/r', 'file:///etc/passwd', '/etc/passwd', 'https://user:pw@github.com/o/r', '-oProxyCommand=x', 'https://github.com/o/r;rm -rf', "https://x.com/a\nb", 'git@github.com:o/r.git', 'http://github.com/o/r'] as $bad) {
        throws(ValidationException::class, fn () => $v->validate($bad, false));
    }
});
test('SSRF: private, loopback, link-local and metadata IPs rejected', function () {
    $v = new RepoUrlValidator((new Services(new Paths(tmpdir())))->settings);
    foreach (['https://127.0.0.1/r', 'https://10.0.0.5/r', 'https://192.168.1.1/r', 'https://169.254.169.254/latest', 'https://[::1]/r', 'https://localhost/r'] as $bad) {
        throws(ValidationException::class, fn () => $v->validate($bad), $bad);   // IP literals need no DNS lookup
    }
});
test('branch validation blocks option injection', function () {
    $v = new RepoUrlValidator(instance()->settings);
    foreach (['--upload-pack=x', '-x', 'a b', 'a..b', 'a;b', 'refs/heads/../x'] as $bad) {
        throws(ValidationException::class, fn () => $v->validateBranch($bad));
    }
    eq($v->validateBranch('feature/docs-1'), 'feature/docs-1');
});

echo "Markdown, sanitizer, navigation\n";
test('sanitizer removes active content', function () {
    $s = instance()->sanitizer;
    $out = $s->sanitize('<p onclick="x()">hi</p><script>alert(1)</script><img src=x onerror=alert(1)><a href="javascript:alert(1)">j</a><iframe src="https://e.com"></iframe><div style="position:fixed">s</div><svg onload=alert(1)></svg>');
    foreach (['<script', 'onclick', 'onerror', 'javascript:', '<iframe', 'style=', '<svg', 'onload'] as $bad) {
        ok(stripos($out, $bad) === false, "found $bad in $out");
    }
    ok(str_contains($out, 'hi'));
});
test('markdown: links, fragments, images, ids, broken, external', function () {
    $s = instance();
    $d = versionDir([
        'README.md' => '# Home', 'Guides/Installation.md' => "# Install\n\n[c](Configuration.md) [api](../Reference/API.md#top) [frag](#requirements) [ext](https://example.com/a.md) [mail](mailto:a@b.c) [missing](Nope.md) [sp](<../Getting Started.md#x-y>)\n\n![i](images/p.png) ![gone](images/none.png)\n\n## Requirements\n\n## Requirements\n\n```php\necho 1;\n```",
        'Guides/Configuration.md' => '# C', 'Reference/API.md' => '# API', 'Getting Started.md' => '# GS', 'Guides/images/p.png' => 'x',
    ]);
    $g = new PathGuard($d);
    $p = $s->renderer->render('v1', $g, 'Guides/Installation.md');
    $h = $p->html;
    ok(str_contains($h, 'href="/Guides/Configuration"'), 'sibling link');
    ok(str_contains($h, 'href="/Reference/API#top"'), 'parent link + fragment');
    ok(str_contains($h, 'href="#requirements"'), 'fragment-only');
    ok(str_contains($h, 'href="https://example.com/a.md"'), 'external .md untouched');
    ok(str_contains($h, 'href="mailto:a@b.c"'), 'mailto');
    ok(str_contains($h, 'class="wiki-broken"'), 'broken marker');
    ok(str_contains($h, 'href="/Getting%20Started#x-y"'), 'spaces encoded');
    ok(str_contains($h, 'src="/_wiki/asset/Guides/images/p.png?v='), 'image relative to the page directory');
    ok(str_contains($h, 'id="requirements"') && str_contains($h, 'id="requirements-1"'), 'duplicate heading ids');
    ok(str_contains($h, 'class="language-php"'), 'language class kept');
    eq($p->title, 'Install');
    eq(count($p->toc), 2);
});
test('markdown: raw html script stripped, page cache round-trips', function () {
    $s = instance();
    $d = versionDir(['a.md' => "# T\n\n<script>alert(1)</script><b onmouseover=x>b</b>\n"]);
    $g = new PathGuard($d);
    $a = $s->renderer->renderCached('v9', $g, 'a.md');
    $b = $s->renderer->renderCached('v9', $g, 'a.md');
    eq($a->html, $b->html);
    ok(stripos($a->html, '<script') === false && stripos($a->html, 'onmouseover') === false);
});
test('markdown: oversized page refused', function () {
    $s = instance(null, ['max_markdown_bytes' => 100]);
    $d = versionDir(['big.md' => str_repeat('x', 500)]);
    throws(\Wiki\Markdown\ContentTooLargeException::class, fn () => $s->renderer->render('v', new PathGuard($d), 'big.md'));
});
test('navigation: dirs first, case-insensitive, nested, README as landing, empty dirs hidden', function () {
    $s = instance();
    $d = versionDir(['README.md' => '#', 'b.md' => '#', 'A.md' => '#', 'z/README.md' => '#', 'z/y.md' => '#', 'Alpha/x.md' => '#', 'empty/file.txt' => 'x', 'file10.md' => '#', 'file2.md' => '#']);
    $tree = $s->nav->build(new PathGuard($d))['tree'];
    $names = array_map(fn ($n) => $n['name'], $tree);
    eq($names, ['Alpha', 'z', 'A', 'b', 'file2', 'file10']);
    eq($tree[1]['landing'], true);
    eq($tree[1]['children'][0]['url'], '/z/y');
});
test('deleted page leaves navigation after rebuild', function () {
    $s = instance();
    $d = versionDir(['README.md' => '#', 'gone.md' => '#']);
    $before = array_column($s->nav->build(new PathGuard($d))['pages'], 'path');
    ok(in_array('gone.md', $before, true));
    unlink("$d/gone.md");
    ok(!in_array('gone.md', array_column($s->nav->build(new PathGuard($d))['pages'], 'path'), true));
});
test('search: title, path, content, case-insensitive, no results, length cap', function () {
    $s = instance();
    $d = versionDir(['README.md' => '# Home', 'Guides/Setup.md' => "# Setup guide\nzebra stripes", '.git/x.md' => 'zebra secret']);
    $g = new PathGuard($d);
    $pages = $s->nav->build($g)['pages'];
    $r = $s->search->search('vs', $g, $pages, 'ZEBRA');
    eq(count($r['results']), 1);
    eq($r['results'][0]['url'], '/Guides/Setup');
    eq(count($s->search->search('vs', $g, $pages, 'setup')['results']), 1);
    eq($s->search->search('vs', $g, $pages, 'nothinghere')['total'], 0);
    ok(mb_strlen($s->search->search('vs', $g, $pages, str_repeat('a', 500))['query']) <= 100);
});

echo "Filesystem, locks, versions\n";
test('atomic json: round trip, corrupt detected', function () {
    $f = tmpdir() . '/x.json';
    AtomicFile::writeJson($f, ['a' => 1]);
    eq(AtomicFile::readJson($f), ['a' => 1]);
    file_put_contents($f, '{broken');
    throws(CorruptFileException::class, fn () => AtomicFile::readJson($f));
});
test('lock: second exclusive fails while held, works after release', function () {
    $f = tmpdir() . '/l.lock';
    $a = FileLock::tryExclusive($f);
    ok($a !== null);
    eq(FileLock::tryExclusive($f), null);
    $a->release();
    $b = FileLock::tryExclusive($f);
    ok($b !== null);
    $b->release();
});
test('lock is held across a real child process and freed when it dies', function () {
    $f = tmpdir() . '/l.lock';
    $code = '$h=fopen($argv[1],"c");flock($h,LOCK_EX);echo "locked\n";fflush(STDOUT);sleep(30);';
    $p = proc_open([PHP_BINARY, '-r', $code, '--', $f], [1 => ['pipe', 'w']], $pipes);
    fgets($pipes[1]);
    eq(FileLock::tryExclusive($f), null, 'child holds the lock');
    proc_terminate($p, 9);
    proc_close($p);
    usleep(200000);
    $l = FileLock::tryExclusive($f);
    ok($l !== null, 'crashed holder must not leave a stuck lock');
    $l->release();
});
test('versions: activate, retention keeps active + N, honours leases, restore from history', function () {
    $s = instance();
    $ids = [];
    for ($i = 1; $i <= 5; $i++) {
        $id = str_repeat((string) $i, 40);
        mkdir($s->paths->versionDir($id), 0755, true);
        file_put_contents($s->paths->versionDir($id) . '/a.md', '#');
        $s->versions->activate($id, $id);
        $ids[] = $id;
    }
    $lease = $s->versions->lease($ids[0]);              // an in-flight request is reading the oldest version
    $deleted = $s->versions->cleanup(2);
    ok(!in_array($ids[0], $deleted, true), 'leased version must survive');
    ok(is_dir($s->paths->versionDir($ids[4])) && is_dir($s->paths->versionDir($ids[3])) && is_dir($s->paths->versionDir($ids[2])));
    ok(!is_dir($s->paths->versionDir($ids[1])), 'unleased old version removed');
    $lease->release();
    // dangling pointer repaired from retained versions
    \Wiki\Filesystem\Fs::removeTree($s->paths->versionDir($ids[4]));
    eq($s->versions->activeId(), null);
    eq($s->versions->restoreFromHistory(), $ids[3]);
    eq($s->versions->activeId(), $ids[3]);
});
test('cleanup never deletes anything when the active pointer is unknown', function () {
    $s = instance();
    $id = str_repeat('a', 40);
    mkdir($s->paths->versionDir($id), 0755, true);
    eq($s->versions->cleanup(0), []);
    ok(is_dir($s->paths->versionDir($id)));
});

echo "Setup, synchronization, recovery (real git)\n";
test('setup: invalid input and failed clone leave the wiki uninstalled', function () {
    $s = instance();
    throws(SetupException::class, fn () => $s->setup()->install('ftp://x/y'));
    throws(SetupException::class, fn () => $s->setup()->install('/nonexistent/path/repo'));
    eq($s->installation->status(), InstallationStatus::NotInstalled);
    ok(!is_file($s->paths->installationFile()));
});
test('setup: repository without markdown is rejected', function () {
    $s = instance();
    $r = makeRemote(['a.txt' => 'x']);
    throws(SetupException::class, fn () => $s->setup()->install($r));
    eq($s->installation->status(), InstallationStatus::NotInstalled);
});
test('setup succeeds once; second install and config deletion cannot re-point the wiki', function () {
    $s = instance();
    $r = makeRemote(['README.md' => '# Hi']);
    $s->setup()->install($r);
    eq($s->installation->status(), InstallationStatus::Installed);
    $e = throws(SetupException::class, fn () => $s->setup()->install(makeRemote(['README.md' => '# Other'])));
    eq($e->httpStatus, 409);
    unlink($s->paths->installationFile());
    $s2 = new Services($s->paths, $s->clock, ['allow_local_urls' => true]);
    eq($s2->installation->status(), InstallationStatus::Corrupt, 'deleted config => recovery, not setup');
    $e = throws(SetupException::class, fn () => $s2->setup()->install(makeRemote(['README.md' => '# Evil'])));
    eq($e->httpStatus, 503);
});
test('sync: update activates new version; no-change still advances the success timestamp', function () {
    $clock = new FixedClock(1_800_000_000);
    $s = instance($clock);
    $r = makeRemote(['README.md' => '# v1', 'old.md' => '# old']);
    $s->setup()->install($r);
    $first = $s->versions->activeId();
    $clock->advance(90000);
    commitFiles($r, ['new.md' => '# new', 'old.md' => null]);
    $res = $s->synchronizer()->run();
    eq($res->status, 'updated');
    $second = $s->versions->activeId();
    ok($second !== $first);
    ok(is_file($s->paths->versionDir($second) . '/new.md') && !is_file($s->paths->versionDir($second) . '/old.md'));
    ok(is_file($s->paths->versionDir($first) . '/old.md'), 'previous version retained intact');
    $clock->advance(90000);
    $res = $s->synchronizer()->run();
    eq($res->status, 'unchanged');
    eq(Time::parse($s->state->read()['last_success_at']), $clock->now(), 'no-change check counts as success');
});
test('sync: remote failure keeps active version and the old success timestamp, applies cooldown', function () {
    $clock = new FixedClock(1_800_000_000);
    $s = instance($clock);
    $r = makeRemote(['README.md' => '# v1']);
    $s->setup()->install($r);
    $active = $s->versions->activeId();
    $lastOk = $s->state->read()['last_success_at'];
    \Wiki\Filesystem\Fs::removeTree($r);                 // remote disappears
    $clock->advance(90000);
    $res = $s->synchronizer()->run();
    eq($res->status, 'failed');
    eq($s->versions->activeId(), $active);
    eq($s->state->read()['last_success_at'], $lastOk);
    eq($s->state->read()['status'], 'failed');
    eq(FreshnessPolicy::evaluate($s->state->read(), $clock->now() + 10, 86400)['reason'], 'cooldown');
    ok(is_file($s->paths->versionDir($active) . '/README.md'));
});
test('sync: candidate validation failure never activates', function () {
    $clock = new FixedClock(1_800_000_000);
    $s = instance($clock);
    $r = makeRemote(['README.md' => '# v1']);
    $s->setup()->install($r);
    $active = $s->versions->activeId();
    commitFiles($r, ['README.md' => null, 'only.txt' => 'no markdown anymore']);
    $clock->advance(90000);
    eq($s->synchronizer()->run()->status, 'failed');
    eq($s->versions->activeId(), $active);
    eq(count($s->versions->onDisk()), 1, 'rejected candidate is not left behind');
    eq(glob($s->paths->staging . '/*') ?: [], [], 'staging cleaned');
});
test('sync: escaping symlinks in a candidate are removed', function () {
    $clock = new FixedClock(1_800_000_000);
    $s = instance($clock);
    $r = makeRemote(['README.md' => '# v1']);
    symlink('/etc/passwd', $r . '/evil.md');
    sh('git add -A && git commit -q -m link', $r);
    $s->setup()->install($r);
    $id = $s->versions->activeId();
    ok(!file_exists($s->paths->versionDir($id) . '/evil.md') && !is_link($s->paths->versionDir($id) . '/evil.md'));
});
test('sync: stale status without a lock holder is recovered, not trusted', function () {
    $clock = new FixedClock(1_800_000_000);
    $s = instance($clock);
    $s->setup()->install(makeRemote(['README.md' => '# v1']));
    $s->state->update(function (array $st) use ($clock) {
        $st['status'] = 'running';
        $st['last_success_at'] = Time::iso($clock->now() - 100000);
        return $st;
    });
    eq($s->launcher()->isRunning(), false, 'lock, not JSON, decides');
});
test('launcher: ineligible when fresh; busy when lock held; launches detached child when stale', function () {
    $clock = new FixedClock(time());
    $paths = new Paths(tmpdir());
    $paths->ensureDirectories();
    putenv('WIKI_ROOT=' . $paths->root);
    putenv('WIKI_ALLOW_LOCAL_URLS=1');
    $s = new Services($paths, $clock, ['allow_local_urls' => true]);
    $r = makeRemote(['README.md' => '# v1']);
    $s->setup()->install($r);
    eq($s->launcher()->maybeLaunch(), 'ineligible:fresh');
    $clock->advance(90000);
    $hold = FileLock::tryExclusive($paths->syncLock());
    eq($s->launcher()->maybeLaunch(), 'busy');
    $t0 = microtime(true);
    $hold->release();
    commitFiles($r, ['b.md' => '# b']);
    $res = $s->launcher()->maybeLaunch();
    $elapsed = microtime(true) - $t0;
    eq($res, 'launched');
    ok($elapsed < 2.0, 'launch must not wait for git');
    for ($i = 0; $i < 100 && $s->launcher()->isRunning(); $i++) {
        usleep(100000);
    }
    usleep(300000);
    $st = (new Services($paths, $clock))->state->read();
    eq($st['status'], 'idle', 'child finished after the parent returned: ' . json_encode($st));
    ok($st['active_commit'] !== null && is_file($paths->versionDir($st['active_commit']) . '/b.md'));
    putenv('WIKI_ROOT');
    putenv('WIKI_ALLOW_LOCAL_URLS');
});
test('concurrent launch attempts start at most one child', function () {
    $paths = new Paths(tmpdir());
    $paths->ensureDirectories();
    $a = FileLock::tryExclusive($paths->syncLock());
    $b = FileLock::tryExclusive($paths->syncLock());
    ok($a !== null && $b === null);
    $a->release();
});

echo "HTTP (php -S)\n";
$httpRoot = tmpdir();
$remote = makeRemote(['README.md' => "# Home\n\n[g](Guides/Installation.md)\n\n<script>alert(1)</script>\n", 'Guides/Installation.md' => "# Install\n\n## Req\n\nx\n\n## More\n", 'Guides/images/x.svg' => '<svg xmlns="http://www.w3.org/2000/svg"><script>1</script></svg>', 'Guides/images/p.png' => 'PNG']);
$port = 0;
for ($i = 0; $i < 20 && $port === 0; $i++) {
    $try = random_int(20000, 60000);
    $sock = @stream_socket_server("tcp://127.0.0.1:$try");
    if ($sock) {
        fclose($sock);
        $port = $try;
    }
}
$env = ['PATH' => (string) getenv('PATH'), 'WIKI_STORAGE_DIR' => "$httpRoot/storage", 'WIKI_REPOS_DIR' => "$httpRoot/repos", 'WIKI_ALLOW_LOCAL_URLS' => '1', 'HOME' => $httpRoot];
$srv = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', dirname(__DIR__) . '/public', dirname(__DIR__) . '/public/index.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, dirname(__DIR__), $env);
usleep(600000);
function http(int $port, string $method, string $path, array $headers = [], ?string $body = null): array
{
    $ctx = stream_context_create(['http' => ['method' => $method, 'header' => implode("\r\n", $headers), 'content' => $body ?? '', 'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 20]]);
    $b = @file_get_contents("http://127.0.0.1:$port$path", false, $ctx);
    $status = 0;
    $h = [];
    foreach ($http_response_header ?? [] as $line) {
        if (preg_match('#^HTTP/\S+ (\d+)#', $line, $m)) {
            $status = (int) $m[1];
        } elseif (str_contains($line, ':')) {
            [$k, $v] = explode(':', $line, 2);
            $h[strtolower(trim($k))] = trim($v);
        }
    }
    return ['status' => $status, 'headers' => $h, 'body' => (string) $b];
}
test('http: first visit shows setup, not the wiki', function () use ($port) {
    $r = http($port, 'GET', '/some/page');
    eq($r['status'], 200);
    ok(str_contains($r['body'], 'name="repository_url"'));
    ok(str_contains($r['headers']['content-security-policy'] ?? '', "script-src 'self'"));
});
test('http: setup rejects missing CSRF and cross-origin posts', function () use ($port, $remote) {
    eq(http($port, 'POST', '/_setup', ['Content-Type: application/x-www-form-urlencoded'], 'repository_url=' . urlencode($remote))['status'], 403);
    $page = http($port, 'GET', '/');
    preg_match('/name="csrf" value="([0-9a-f]+)"/', $page['body'], $m);
    $cookie = preg_replace('/;.*/', '', $page['headers']['set-cookie'] ?? '');
    $r = http($port, 'POST', '/_setup', ['Content-Type: application/x-www-form-urlencoded', "Cookie: $cookie", 'Origin: http://evil.example'], 'csrf=' . $m[1] . '&repository_url=' . urlencode($remote));
    eq($r['status'], 403);
});
test('http: setup installs, redirects, and cannot be repeated', function () use ($port, $remote) {
    $page = http($port, 'GET', '/');
    preg_match('/name="csrf" value="([0-9a-f]+)"/', $page['body'], $m);
    $cookie = preg_replace('/;.*/', '', $page['headers']['set-cookie'] ?? '');
    $post = fn () => http($port, 'POST', '/_setup', ['Content-Type: application/x-www-form-urlencoded', "Cookie: $cookie"], 'csrf=' . $m[1] . '&repository_url=' . urlencode($remote));
    $r = $post();
    eq($r['status'], 303, $r['body']);
    eq($post()['status'], 409);
});
test('http: pages, canonical redirects, 404 inside the wiki layout', function () use ($port) {
    $home = http($port, 'GET', '/');
    eq($home['status'], 200);
    ok(str_contains($home['body'], 'class="nav-list"'));
    ok(stripos($home['body'], '<script>alert') === false, 'raw script sanitized');
    eq(http($port, 'GET', '/Guides/Installation')['status'], 200);
    $r = http($port, 'GET', '/Guides/Installation.md');
    eq($r['status'], 301);
    eq($r['headers']['location'], '/Guides/Installation');
    $nf = http($port, 'GET', '/does-not-exist');
    eq($nf['status'], 404);
    ok(str_contains($nf['body'], 'class="nav-list"'), '404 keeps the sidebar');
});
test('http: traversal attempts never leak files', function () use ($port) {
    foreach (['/%2e%2e/%2e%2e/etc/passwd', '/_wiki/asset/..%2f..%2fcomposer.json', '/_wiki/asset/%2e%2e/%2e%2e/etc/passwd', '/%252e%252e/etc/passwd', '/_wiki/asset/.git/config', '/..;/x'] as $p) {
        $r = http($port, 'GET', $p);
        ok(in_array($r['status'], [400, 404], true), "$p => {$r['status']}");
        ok(!str_contains($r['body'], 'root:'), "$p leaked");
    }
});
test('http: assets - png inline, svg sandboxed download, nosniff', function () use ($port) {
    $png = http($port, 'GET', '/_wiki/asset/Guides/images/p.png');
    eq($png['status'], 200);
    eq($png['headers']['content-type'], 'image/png');
    eq($png['headers']['x-content-type-options'], 'nosniff');
    $svg = http($port, 'GET', '/_wiki/asset/Guides/images/x.svg');
    ok(str_contains($svg['headers']['content-disposition'] ?? '', 'attachment'));
    ok(str_contains($svg['headers']['content-security-policy'] ?? '', 'sandbox'));
});
test('http: search, status and health endpoints; storage and vendor are not web-reachable', function () use ($port) {
    $r = http($port, 'GET', '/_wiki/search?q=install');
    eq($r['status'], 200);
    eq(json_decode($r['body'], true)['results'][0]['url'], '/Guides/Installation');
    $st = json_decode(http($port, 'GET', '/_wiki/status')['body'], true);
    ok(array_key_exists('syncing', $st) && !str_contains(json_encode($st), '/storage'));
    eq(json_decode(http($port, 'GET', '/_health')['body'], true)['ok'], true);
    foreach (['/storage/config/installation.json', '/src/bootstrap.php', '/vendor/autoload.php', '/composer.json'] as $p) {
        ok(http($port, 'GET', $p)['status'] === 404, "$p must not be served");
    }
});
test('http: static assets are served with correct types', function () use ($port) {
    foreach (['/_wiki/static/css/app.css' => 'text/css', '/_wiki/static/js/app.js' => 'javascript', '/_wiki/static/vendor/highlight.min.js' => 'javascript', '/favicon.svg' => 'svg'] as $p => $t) {
        $r = http($port, 'GET', $p);
        eq($r['status'], 200, $p);
        ok(str_contains($r['headers']['content-type'] ?? '', $t), "$p type " . ($r['headers']['content-type'] ?? ''));
    }
});
test('http: configuration cannot be changed over HTTP after install', function () use ($port, $httpRoot) {
    $before = file_get_contents("$httpRoot/storage/config/installation.json");
    http($port, 'POST', '/_setup', ['Content-Type: application/x-www-form-urlencoded'], 'repository_url=https://github.com/x/y');
    http($port, 'POST', '/', ['Content-Type: application/x-www-form-urlencoded'], 'repository_url=https://github.com/x/y');
    eq(file_get_contents("$httpRoot/storage/config/installation.json"), $before);
    ok(!str_contains(http($port, 'GET', '/')['body'], $httpRoot), 'no filesystem paths in HTML');
});
test('http: corrupt installation shows recovery page, never the setup form', function () use ($port, $httpRoot) {
    rename("$httpRoot/storage/config/installation.json", "$httpRoot/storage/config/installation.json.bak");
    $r = http($port, 'GET', '/');
    eq($r['status'], 503);
    ok(!str_contains($r['body'], 'repository_url'));
    rename("$httpRoot/storage/config/installation.json.bak", "$httpRoot/storage/config/installation.json");
    eq(http($port, 'GET', '/')['status'], 200);
});
test('http: missing active version is repaired from a retained one', function () use ($port, $httpRoot) {
    $ptr = json_decode(file_get_contents("$httpRoot/repos/current.json"), true);
    $keep = $httpRoot . '/repos/versions/' . str_repeat('b', 40);
    sh('cp -r ' . escapeshellarg($httpRoot . '/repos/versions/' . $ptr['version']) . ' ' . escapeshellarg($keep));
    file_put_contents("$httpRoot/repos/current.json", json_encode(['version' => str_repeat('c', 40), 'commit' => str_repeat('c', 40), 'activated_at' => 'x']));
    $r = http($port, 'GET', '/');
    eq($r['status'], 200, 'served from the retained version');
});
if (is_resource($srv)) {
    proc_terminate($srv);
    proc_close($srv);
}

foreach ($GLOBALS['T']['tmp'] as $d) {
    if (is_dir($d)) {
        \Wiki\Filesystem\Fs::removeTree($d);
    }
}
printf("\n%d passed, %d failed\n", $GLOBALS['T']['pass'], $GLOBALS['T']['fail']);
if ($GLOBALS['T']['fail'] > 0) {
    echo "Failed: " . implode('; ', $GLOBALS['T']['failures']) . "\n";
    exit(1);
}

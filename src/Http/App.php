<?php
declare(strict_types=1);

namespace Wiki\Http;

use Wiki\Application\Services;
use Wiki\Config\InstallationStatus;
use Wiki\Filesystem\FileLock;
use Wiki\Markdown\ContentTooLargeException;
use Wiki\Navigation\NavBuilder;
use Wiki\Routing\UrlCodec;
use Wiki\Routing\VersionContent;
use Wiki\Security\PathGuard;
use Wiki\Sync\SetupException;
use Wiki\Support\Time;

/**
 * Front controller logic: routing, controllers, response emission.
 * A request resolves the active version ONCE and serves entirely from it.
 */
final class App
{
    private View $view;
    private bool $https;

    public function __construct(private Services $s)
    {
        $this->view = new View($s->paths->root . '/templates');
        $this->https = Security::isHttps($s->settings->bool('trusted_proxy_https'));
    }

    public function handle(): void
    {
        try {
            $this->dispatch();
        } catch (\Throwable $e) {
            $this->s->logger->error('request_failed', ['error' => $e->getMessage(), 'where' => basename($e->getFile()) . ':' . $e->getLine()]);
            $this->emitError(500, 'Something went wrong', 'The page could not be displayed. The problem has been logged.');
        }
    }

    private function dispatch(): void
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $decoded = UrlCodec::decodeRequestPath($uri);
        if ($decoded === null) {
            $this->emitError(400, 'Bad request', 'The requested address is not valid.');
            return;
        }
        $segments = $decoded['segments'];
        $first = $segments[0] ?? '';
        $this->s->paths->ensureDirectories();

        if ($first === '_health' && count($segments) === 1) {
            $this->health();
            return;
        }
        $status = $this->s->installation->status();

        if ($first === '_setup' && $method === 'POST') {
            $this->setupPost($status);
            return;
        }
        if ($status === InstallationStatus::Corrupt) {
            $this->emitError(503, 'Installation needs recovery',
                'The installation configuration is missing or damaged. For safety the wiki will not accept a new repository. The server administrator must restore the configuration (see the README, section "Recovery").');
            return;
        }
        if ($status === InstallationStatus::NotInstalled) {
            $this->setupForm();
            return;
        }
        if ($first === '_setup') {
            $this->redirect('/');
            return;
        }
        if (!in_array($method, ['GET', 'HEAD'], true)) {
            $this->emit(405, 'text/plain; charset=utf-8', "Method not allowed\n", ['Allow' => 'GET, HEAD']);
            return;
        }

        $versionId = $this->activeVersion();
        if ($versionId === null) {
            $this->emitError(503, 'Documentation unavailable', 'No valid documentation version is available right now. The administrator has been notified through the logs.');
            return;
        }
        $lease = $this->s->versions->lease($versionId);   // keeps cleanup away from the version we serve
        $guard = new PathGuard($this->s->paths->versionDir($versionId));

        if ($first === '_wiki') {
            $sub = $segments[1] ?? '';
            if ($sub === 'asset') {
                $this->asset($guard, $versionId, array_slice($segments, 2));
            } elseif ($sub === 'search' && count($segments) === 2) {
                $this->search($guard, $versionId);
            } elseif ($sub === 'status' && count($segments) === 2) {
                $this->statusJson();
            } else {
                $this->emitError(404, 'Page not found', 'There is nothing at this address.', $this->navCtx($guard, $versionId));
            }
            $lease?->release();
            return;
        }

        $this->page($guard, $versionId, $segments, $decoded['trailing']);
        $lease?->release();
        $this->afterResponse();
    }

    /** Resolve the active version; repair a dangling pointer from retained versions. */
    private function activeVersion(): ?string
    {
        $id = $this->s->versions->activeId();
        if ($id !== null) {
            return $id;
        }
        $lock = FileLock::tryExclusive($this->s->paths->syncLock());
        if ($lock === null) {
            return null;                       // a sync may be switching the pointer right now; do not interfere
        }
        try {
            $this->s->logger->warning('active_version_missing');
            $restored = $this->s->versions->restoreFromHistory();
            if ($restored !== null) {
                $this->s->logger->info('active_version_restored', ['version' => $restored]);
            }
            return $restored;
        } finally {
            $lock->release();
        }
    }

    /** Flush the response, then (cheaply) decide whether a detached sync must start. Never waits for Git. */
    private function afterResponse(): void
    {
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } else {
            @ob_flush();
            @flush();
        }
        $this->s->launcher()->maybeLaunch();
    }

    // ---------------------------------------------------------------- pages

    /** @param list<string> $segments */
    private function page(PathGuard $guard, string $versionId, array $segments, bool $trailing): void
    {
        $content = new VersionContent($versionId, $guard);
        $r = $content->resolve($segments, $trailing);
        $ctx = $this->navCtx($guard, $versionId);

        switch ($r['type']) {
            case 'redirect':
                $this->redirect($r['location']);
                return;
            case 'no_home':
                $this->emitError(404, 'No homepage', 'This documentation repository has no README.md in its root, so there is no homepage yet.', $ctx);
                return;
            case 'notfound':
                $this->emitError(404, 'Page not found', 'The page you requested does not exist in this version of the documentation.', $ctx, true);
                return;
            case 'directory':
                $dirRel = $r['path'];
                if ($r['readme'] !== null) {
                    $this->renderMarkdown($guard, $versionId, $r['readme'], $ctx);
                    return;
                }
                $children = NavBuilder::childrenOf($ctx['nav']['tree'], $dirRel) ?? [];
                $this->emitLayout(200, [
                    'title' => basename($dirRel),
                    'currentPath' => $dirRel . '/',
                    'crumbs' => $this->crumbs($dirRel, true),
                    'content' => $this->view->render('directory', ['name' => basename($dirRel), 'children' => $children]),
                    'toc' => [],
                ], $ctx);
                return;
            default:
                $this->renderMarkdown($guard, $versionId, $r['path'], $ctx);
        }
    }

    /** @param array<string,mixed> $ctx */
    private function renderMarkdown(PathGuard $guard, string $versionId, string $rel, array $ctx): void
    {
        try {
            $page = $this->s->renderer->renderCached($versionId, $guard, $rel);
        } catch (ContentTooLargeException $e) {
            $this->emitError(413, 'Page too large', $e->getMessage(), $ctx);
            return;
        } catch (\Throwable $e) {
            $this->s->logger->error('render_failed', ['page' => $rel, 'error' => $e->getMessage()]);
            $this->emitError(500, 'This page could not be rendered', 'The problem has been logged.', $ctx);
            return;
        }
        $isReadme = UrlCodec::isReadme(basename($rel));
        $dir = dirname($rel) === '.' ? '' : dirname($rel);
        $this->emitLayout(200, [
            'title' => $page->title,
            'currentPath' => $rel,
            'crumbs' => $this->crumbs($isReadme ? $dir : $rel, $isReadme),
            'content' => $this->view->render('page', ['html' => $page->html]),
            'toc' => count($page->toc) >= 2 ? $page->toc : [],
        ], $ctx);
    }

    /** @return list<array{label:string,url:?string}> */
    private function crumbs(string $rel, bool $isDir): array
    {
        $crumbs = [['label' => 'Home', 'url' => '/']];
        if ($rel === '') {
            return $crumbs;
        }
        $parts = explode('/', $rel);
        $acc = [];
        foreach ($parts as $i => $p) {
            $acc[] = $p;
            $last = $i === count($parts) - 1;
            $label = $last && !$isDir ? preg_replace('/\.md$/', '', $p) : $p;
            $crumbs[] = ['label' => (string) $label, 'url' => $last ? null : UrlCodec::dirUrl(implode('/', $acc))];
        }
        return $crumbs;
    }

    /** @return array{nav:array<string,mixed>,versionId:string,guard:PathGuard} */
    private function navCtx(PathGuard $guard, string $versionId): array
    {
        return ['nav' => $this->s->nav->forVersion($versionId, $guard), 'versionId' => $versionId, 'guard' => $guard];
    }

    // ---------------------------------------------------------------- assets

    private const INLINE_TYPES = [
        'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp',
        'avif' => 'image/avif', 'bmp' => 'image/bmp', 'ico' => 'image/x-icon', 'pdf' => 'application/pdf',
        'txt' => 'text/plain; charset=utf-8', 'mp4' => 'video/mp4', 'webm' => 'video/webm', 'mp3' => 'audio/mpeg', 'ogg' => 'audio/ogg',
    ];
    private const DOWNLOAD_TYPES = [
        'svg' => 'image/svg+xml', 'zip' => 'application/zip', 'gz' => 'application/gzip', 'tar' => 'application/x-tar',
        'csv' => 'text/csv; charset=utf-8', 'json' => 'application/json', 'md' => 'text/markdown; charset=utf-8',
        'yml' => 'text/plain; charset=utf-8', 'yaml' => 'text/plain; charset=utf-8', 'xml' => 'text/plain; charset=utf-8',
        'docx' => 'application/octet-stream', 'xlsx' => 'application/octet-stream', 'pptx' => 'application/octet-stream',
    ];

    /** @param list<string> $segments */
    private function asset(PathGuard $guard, string $versionId, array $segments): void
    {
        $rel = implode('/', $segments);
        if ($segments === [] || !PathGuard::validRelative($rel)) {
            $this->emitError(404, 'File not found', 'There is no such file.');
            return;
        }
        $abs = $guard->resolve($rel);
        if ($abs === null || !is_file($abs)) {
            $this->emitError(404, 'File not found', 'There is no such file.');
            return;
        }
        $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
        $inline = self::INLINE_TYPES[$ext] ?? null;
        $download = self::DOWNLOAD_TYPES[$ext] ?? null;
        if ($inline === null && $download === null) {
            $this->emitError(404, 'File not found', 'This file type is not published.');
            return;
        }
        $type = $inline ?? $download;
        $headers = ['X-Content-Type-Options' => 'nosniff'];
        if ($inline === null) {
            // Potentially active formats (SVG, ...) never run in the wiki origin: sandboxed + attachment.
            $headers['Content-Disposition'] = 'attachment; filename="' . addcslashes(basename($abs), "\"\\") . '"; filename*=UTF-8\'\'' . rawurlencode(basename($abs));
            $headers['Content-Security-Policy'] = "sandbox; default-src 'none'; style-src 'unsafe-inline'";
        } else {
            $headers['Content-Security-Policy'] = "sandbox; default-src 'none'; img-src 'self'; media-src 'self'";
        }
        $v = $_GET['v'] ?? '';
        $versioned = is_string($v) && $v === substr($versionId, 0, 12);
        $headers['Cache-Control'] = $versioned ? 'public, max-age=31536000, immutable' : 'public, max-age=0, must-revalidate';
        $etag = '"' . substr($versionId, 0, 12) . '-' . sha1($rel) . '"';
        $headers['ETag'] = $etag;
        if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
            $this->emit(304, $type, '', $headers);
            return;
        }
        $headers['Content-Length'] = (string) filesize($abs);
        $this->sendHeaders(200, $type, $headers);
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
            readfile($abs);
        }
    }

    // ---------------------------------------------------------------- JSON endpoints

    private function search(PathGuard $guard, string $versionId): void
    {
        $q = $_GET['q'] ?? '';
        if (!is_string($q)) {
            $this->json(400, ['error' => 'invalid_query']);
            return;
        }
        $nav = $this->s->nav->forVersion($versionId, $guard);
        $res = $this->s->search->search($versionId, $guard, $nav['pages'], $q);
        if ($res === null) {
            $this->json(503, ['error' => 'search_unavailable', 'message' => 'Search is temporarily unavailable.']);
            return;
        }
        $this->json(200, $res, ['Cache-Control' => 'no-store']);
    }

    private function statusJson(): void
    {
        if (!$this->s->settings->bool('status_endpoint')) {
            $this->json(404, ['error' => 'disabled']);
            return;
        }
        $st = $this->s->state->read();
        $this->json(200, [
            'syncing' => $this->s->launcher()->isRunning(),   // the lock is authoritative
            'last_success_at' => $st['last_success_at'],
            'active_commit' => substr((string) ($st['active_commit'] ?? ''), 0, 12),
            'last_attempt_failed' => ($st['status'] ?? '') === 'failed',
        ], ['Cache-Control' => 'no-store']);
    }

    private function health(): void
    {
        if (!$this->s->settings->bool('health_endpoint')) {
            $this->json(404, ['error' => 'disabled']);
            return;
        }
        $installed = $this->s->installation->status() === InstallationStatus::Installed;
        $active = $installed && $this->s->versions->activeId() !== null;
        $this->json($active ? 200 : 503, ['ok' => $active, 'installed' => $installed], ['Cache-Control' => 'no-store']);
    }

    // ---------------------------------------------------------------- setup

    private function setupForm(?string $error = null, string $url = '', string $branch = '', int $status = 200): void
    {
        $token = Security::issueCsrfToken($this->https);
        $this->emit($status, 'text/html; charset=utf-8', $this->view->render('setup', [
            'csrf' => $token, 'error' => $error, 'url' => $url, 'branch' => $branch, 'version' => WIKI_VERSION,
            'processOk' => \Wiki\Sync\Launcher::processLaunchSupported(),
            'gitOk' => $this->s->git->available(),
            'allowSsh' => $this->s->settings->bool('allow_ssh'),
        ]), ['Cache-Control' => 'no-store']);
    }

    private function setupPost(InstallationStatus $status): void
    {
        if ($status === InstallationStatus::Installed) {
            $this->emitError(409, 'Already set up', 'This wiki has already been set up. The repository cannot be changed.');
            return;
        }
        if ($status === InstallationStatus::Corrupt) {
            $this->emitError(503, 'Installation needs recovery', 'The installation needs recovery by the server administrator.');
            return;
        }
        $submitted = is_string($_POST['csrf'] ?? null) ? $_POST['csrf'] : '';
        if (!Security::sameOrigin() || !Security::validCsrf($submitted)) {
            $this->setupForm('The form expired or came from another site. Please try again.', '', '', 403);
            return;
        }
        $url = is_string($_POST['repository_url'] ?? null) ? trim($_POST['repository_url']) : '';
        $branch = is_string($_POST['branch'] ?? null) ? trim($_POST['branch']) : '';
        try {
            $this->s->setup()->install($url, $branch);
        } catch (SetupException $e) {
            $safeUrl = preg_match('#^[a-z]+://[^@/]*@#i', $url) ? '' : mb_substr($url, 0, 500);   // never echo credentials back
            $this->setupForm($e->getMessage(), $safeUrl, mb_substr($branch, 0, 200), $e->httpStatus);
            return;
        }
        $this->redirect('/', 303);
    }

    // ---------------------------------------------------------------- output

    /**
     * @param array<string,mixed> $page
     * @param array<string,mixed>|null $ctx
     */
    private function emitLayout(int $status, array $page, ?array $ctx): void
    {
        $st = $this->s->state->read();
        $siteTitle = $this->siteTitle();
        $vars = $page + [
            'siteTitle' => $siteTitle,
            'navHtml' => $ctx !== null ? View::nav($ctx['nav']['tree'], (string) ($page['currentPath'] ?? '')) : null,
            'navTruncated' => $ctx !== null && !empty($ctx['nav']['truncated']),
            'crumbs' => [], 'toc' => [], 'currentPath' => '',
            'commit' => substr((string) ($st['active_commit'] ?? ''), 0, 8),
            'syncedAt' => (string) ($st['last_success_at'] ?? ''),
            'appVersion' => WIKI_VERSION,
            'repoUrl' => $this->publicRepoUrl(),
            'assetVer' => WIKI_VERSION,
        ];
        $this->emit($status, 'text/html; charset=utf-8', $this->view->render('layout', $vars), ['Cache-Control' => 'no-cache']);
    }

    /** @param array<string,mixed>|null $ctx */
    private function emitError(int $status, string $title, string $message, ?array $ctx = null, bool $searchHint = false): void
    {
        $this->emitLayout($status, [
            'title' => $title,
            'content' => $this->view->render('error', ['heading' => $title, 'message' => $message, 'status' => $status, 'searchHint' => $searchHint]),
        ], $ctx);
    }

    private function siteTitle(): string
    {
        $t = $this->s->settings->string('site_title');
        if ($t !== '') {
            return $t;
        }
        $path = (string) parse_url($this->s->installation->repositoryUrl(), PHP_URL_PATH);
        $name = preg_replace('/\.git$/', '', basename($path)) ?? '';
        $name = trim(str_replace(['-', '_'], ' ', $name));
        return $name === '' ? 'Wiki' : ucwords($name);
    }

    /** Repository link for the footer; credentials are stripped and only http(s) URLs are shown. */
    private function publicRepoUrl(): ?string
    {
        $u = $this->s->installation->repositoryUrl();
        $p = parse_url($u);
        if (!is_array($p) || !in_array($p['scheme'] ?? '', ['http', 'https'], true) || empty($p['host'])) {
            return null;
        }
        return $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '') . ($p['path'] ?? '');
    }

    private function redirect(string $location, int $code = 301): void
    {
        $this->emit($code, 'text/plain; charset=utf-8', '', ['Location' => $location, 'Cache-Control' => 'no-cache']);
    }

    /** @param array<string,mixed> $data @param array<string,string> $headers */
    private function json(int $status, array $data, array $headers = []): void
    {
        $this->emit($status, 'application/json; charset=utf-8', (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), $headers);
    }

    /** @param array<string,string> $headers */
    private function emit(int $status, string $type, string $body, array $headers = []): void
    {
        $this->sendHeaders($status, $type, $headers);
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
            echo $body;
        }
    }

    /** @param array<string,string> $headers */
    private function sendHeaders(int $status, string $type, array $headers): void
    {
        if (headers_sent()) {
            return;
        }
        http_response_code($status);
        header('Content-Type: ' . $type);
        $merged = $headers + Security::headers();
        foreach ($merged as $k => $v) {
            header($k . ': ' . str_replace(["\r", "\n"], '', $v));
        }
        if ($this->https) {
            header('Strict-Transport-Security: max-age=31536000');
        }
    }
}

<?php
declare(strict_types=1);

namespace Wiki\Markdown;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use League\CommonMark\Extension\CommonMark\Node\Inline\Code;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\MarkdownConverter;
use League\CommonMark\Node\Inline\Text;
use Wiki\Config\Settings;
use Wiki\Filesystem\AtomicFile;
use Wiki\Filesystem\CorruptFileException;
use Wiki\Routing\UrlCodec;
use Wiki\Security\PathGuard;
use Wiki\Support\Paths;

/**
 * Markdown -> sanitized HTML for one page of one repository version.
 *
 * Uses league/commonmark (CommonMark + GitHub Flavored Markdown: tables, task lists, strikethrough,
 * autolinks). Documented limitations: no footnotes, no explicit heading-id syntax ({#id}), no math,
 * no embedded iframes/diagrams (mermaid fences render as plain code).
 *
 * Pipeline:  parse -> AST pass (heading ids, relative link/image rewriting) -> HTML -> sanitize.
 * Rendered pages are cached per (version, page path, renderer version, sanitizer version).
 */
final class MarkdownRenderer
{
    public const RENDERER_VERSION = 1;

    private MarkdownConverter $converter;
    /** @var array{guard:PathGuard,rel:string,versionId:string,headings:list<array{level:int,id:string,text:string}>,slugs:array<string,int>}|null */
    private ?array $ctx = null;

    public function __construct(
        private Paths $paths,
        private Settings $settings,
        private HtmlSanitizer $sanitizer,
    ) {
        $env = new Environment([
            'html_input' => 'allow',            // raw HTML is allowed in the parse stage, then sanitized
            'allow_unsafe_links' => false,
            'max_nesting_level' => 60,
        ]);
        $env->addExtension(new CommonMarkCoreExtension());
        $env->addExtension(new GithubFlavoredMarkdownExtension());
        $env->addEventListener(DocumentParsedEvent::class, function (DocumentParsedEvent $e): void {
            $this->astPass($e);
        });
        $this->converter = new MarkdownConverter($env);
    }

    /** Render with the page cache. */
    public function renderCached(string $versionId, PathGuard $guard, string $rel): RenderedPage
    {
        $key = sha1($rel) . '.' . self::RENDERER_VERSION . '.' . HtmlSanitizer::VERSION . '.json';
        $file = $this->paths->versionCache($versionId) . '/pages/' . $key;
        try {
            $c = AtomicFile::readJson($file);
        } catch (CorruptFileException) {
            $c = null;
        }
        if (is_array($c) && ($c['path'] ?? null) === $rel && is_string($c['html'] ?? null) && is_string($c['title'] ?? null) && is_array($c['toc'] ?? null)) {
            return new RenderedPage($c['html'], $c['title'], $c['toc']);
        }
        $page = $this->render($versionId, $guard, $rel);
        try {
            AtomicFile::writeJson($file, ['path' => $rel] + $page->toArray());
        } catch (\Throwable) {
            // cache is optional
        }
        return $page;
    }

    public function render(string $versionId, PathGuard $guard, string $rel): RenderedPage
    {
        $abs = $guard->resolve($rel);
        if ($abs === null || !is_file($abs)) {
            throw new \RuntimeException('Markdown file not found');
        }
        $size = (int) filesize($abs);
        if ($size > $this->settings->int('max_markdown_bytes')) {
            throw new ContentTooLargeException('This page is larger than the configured limit.');
        }
        $markdown = (string) file_get_contents($abs);
        if (!mb_check_encoding($markdown, 'UTF-8')) {
            $markdown = mb_convert_encoding($markdown, 'UTF-8', 'UTF-8');   // replaces invalid byte sequences
        }
        $markdown = preg_replace('/^\xEF\xBB\xBF/', '', $markdown) ?? $markdown;

        $this->ctx = ['guard' => $guard, 'rel' => $rel, 'versionId' => $versionId, 'headings' => [], 'slugs' => []];
        try {
            $raw = $this->converter->convert($markdown)->getContent();
            $headings = $this->ctx['headings'];
        } finally {
            $this->ctx = null;
        }
        $html = $this->sanitizer->sanitize($raw);
        if (strlen($html) > $this->settings->int('max_rendered_bytes')) {
            throw new ContentTooLargeException('This page is too large to display.');
        }
        $title = null;
        $toc = [];
        foreach ($headings as $h) {
            if ($h['level'] === 1 && $title === null && trim($h['text']) !== '') {
                $title = trim($h['text']);
            }
            if ($h['level'] >= 2 && $h['level'] <= 4) {
                $toc[] = $h;
            }
        }
        return new RenderedPage($html, $title ?? self::fallbackTitle($rel), $toc);
    }

    public static function fallbackTitle(string $rel): string
    {
        $base = basename($rel);
        if (UrlCodec::isReadme($base)) {
            $dir = dirname($rel);
            return $dir === '.' ? 'Home' : basename($dir);
        }
        return substr($base, 0, -3);
    }

    public static function slugify(string $text): string
    {
        $t = mb_strtolower(trim($text));
        $t = preg_replace('/[^\p{L}\p{N}\p{M}\s_-]/u', '', $t) ?? '';
        $t = preg_replace('/\s+/u', '-', $t) ?? '';
        $t = trim($t, '-');
        return $t === '' ? 'section' : $t;
    }

    private function astPass(DocumentParsedEvent $event): void
    {
        if ($this->ctx === null) {
            return;
        }
        foreach ($event->getDocument()->iterator() as $node) {
            if ($node instanceof Heading) {
                $text = '';
                foreach ($node->iterator() as $child) {
                    if ($child instanceof Text || $child instanceof Code) {
                        $text .= $child->getLiteral();
                    }
                }
                $base = self::slugify($text);
                $slug = $base;
                $i = 0;
                while (isset($this->ctx['slugs'][$slug])) {
                    $i++;
                    $slug = $base . '-' . $i;
                }
                $this->ctx['slugs'][$slug] = 1;
                $node->data->set('attributes/id', $slug);
                $this->ctx['headings'][] = ['level' => $node->getLevel(), 'id' => $slug, 'text' => $text];
            } elseif ($node instanceof Link) {
                [$url, $status] = $this->rewrite($node->getUrl(), false);
                $node->setUrl($url);
                if ($status === 'broken' && $this->settings->bool('broken_link_marker')) {
                    $node->data->set('attributes/class', 'wiki-broken');
                    $node->data->set('attributes/title', 'Broken link: the target does not exist in this repository');
                }
            } elseif ($node instanceof Image) {
                [$url, $status] = $this->rewrite($node->getUrl(), true);
                $node->setUrl($url);
                if ($status === 'broken') {
                    $node->data->set('attributes/class', 'wiki-broken-image');
                }
            }
        }
    }

    /**
     * Rewrite a link/image target found in the current page.
     *
     * @return array{0:string,1:string} [new url, status: external|fragment|page|dir|asset|broken|unchanged]
     */
    private function rewrite(string $url, bool $isImage): array
    {
        $ctx = $this->ctx;
        if ($url === '' || $ctx === null) {
            return [$url, 'unchanged'];
        }
        if ($url[0] === '#') {
            return [$url, 'fragment'];                      // heading ids are generated by this renderer
        }
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $url) || str_starts_with($url, '//')) {
            return [$url, 'external'];                      // http(s), mailto, ...; never rewritten
        }
        $frag = '';
        $path = $url;
        if (($p = strpos($path, '#')) !== false) {
            $frag = substr($path, $p + 1);
            $path = substr($path, 0, $p);
        }
        if (($q = strpos($path, '?')) !== false) {
            $path = substr($path, 0, $q);                   // queries are meaningless for repository files
        }
        if ($path === '') {
            return [$url, 'unchanged'];
        }
        $decoded = rawurldecode($path);
        $trailingSlash = str_ends_with($decoded, '/');
        if (str_starts_with($decoded, '/')) {
            $combined = ltrim($decoded, '/');               // convention: "/x" is relative to the repository root
        } else {
            $dir = dirname($ctx['rel']);
            $combined = ($dir === '.' ? '' : $dir . '/') . $decoded;
        }
        $parts = [];
        foreach (explode('/', $combined) as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                if ($parts === []) {
                    return [$url, 'broken'];                // would leave the repository
                }
                array_pop($parts);
                continue;
            }
            $parts[] = $seg;
        }
        $rel = implode('/', $parts);
        $fragment = $frag === '' ? '' : '#' . rawurlencode(rawurldecode($frag));
        $guard = $ctx['guard'];
        if ($rel !== '' && !PathGuard::validRelative($rel)) {
            return [$url, 'broken'];
        }
        if ($rel === '' || $guard->isDir($rel)) {
            return [UrlCodec::dirUrl($rel) . $fragment, 'dir'];
        }
        if ($guard->isFile($rel)) {
            if (str_ends_with($rel, '.md')) {
                return [UrlCodec::pageUrl($rel) . $fragment, 'page'];
            }
            return [UrlCodec::assetUrl($rel, $ctx['versionId']), 'asset'];
        }
        if (!$isImage && !$trailingSlash && $guard->isFile($rel . '.md')) {
            return [UrlCodec::pageUrl($rel . '.md') . $fragment, 'page'];   // tolerate extension-less links
        }
        if (!$isImage && str_ends_with($rel, '.md')) {
            return [UrlCodec::pageUrl($rel) . $fragment, 'broken'];         // lands on the in-wiki 404 page
        }
        return [$isImage ? UrlCodec::assetUrl($rel, $ctx['versionId']) : $url, 'broken'];
    }
}

<?php
declare(strict_types=1);

namespace Wiki\Search;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\MarkdownConverter;
use Wiki\Config\Settings;
use Wiki\Filesystem\AtomicFile;
use Wiki\Filesystem\CorruptFileException;
use Wiki\Markdown\MarkdownRenderer;
use Wiki\Security\PathGuard;
use Wiki\Support\Paths;

/**
 * Local full-text search. One JSON index per repository version, generated from the navigation
 * page list (so excluded files, Git internals and binaries can never appear) and published
 * atomically in the version's cache namespace.
 *
 * Matching: case-insensitive, every term (or quoted phrase) must occur in title/path/headings/text.
 * Ranking: title > path > heading > body hits.
 */
final class SearchIndex
{
    public const INDEX_VERSION = 1;

    public function __construct(private Paths $paths, private Settings $settings)
    {
    }

    private function file(string $versionId): string
    {
        return $this->paths->versionCache($versionId) . '/search.json';
    }

    /**
     * @param list<array{path:string,url:string,name:string}> $pages
     * @throws \Throwable on failure (callers treat search as optional)
     */
    public function build(string $versionId, PathGuard $guard, array $pages): void
    {
        $env = new Environment(['html_input' => 'strip', 'allow_unsafe_links' => false]);
        $env->addExtension(new CommonMarkCoreExtension());
        $env->addExtension(new GithubFlavoredMarkdownExtension());
        $conv = new MarkdownConverter($env);

        $entries = [];
        $maxChars = $this->settings->int('max_index_text_chars');
        $limit = $this->settings->int('max_index_files');
        $maxBytes = $this->settings->int('max_markdown_bytes');
        foreach ($pages as $page) {
            if (count($entries) >= $limit) {
                break;
            }
            $abs = $guard->resolve($page['path']);
            if ($abs === null || !is_file($abs) || filesize($abs) > $maxBytes) {
                continue;
            }
            $md = (string) file_get_contents($abs);
            if (!mb_check_encoding($md, 'UTF-8')) {
                $md = mb_convert_encoding($md, 'UTF-8', 'UTF-8');
            }
            $html = $conv->convert($md)->getContent();
            $headings = [];
            $title = null;
            if (preg_match_all('#<h([1-6])[^>]*>(.*?)</h\1>#si', $html, $m, PREG_SET_ORDER)) {
                foreach ($m as $h) {
                    $t = trim(html_entity_decode(strip_tags($h[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                    if ($t === '') {
                        continue;
                    }
                    if ($h[1] === '1' && $title === null) {
                        $title = $t;
                    }
                    $headings[] = $t;
                }
            }
            $text = html_entity_decode(strip_tags(str_replace(['</p>', '</li>', '<br>', '<br />', '</h1>', '</h2>', '</h3>', '</tr>'], ' ', $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
            $entries[] = [
                'p' => $page['path'],
                'u' => $page['url'],
                't' => $title ?? MarkdownRenderer::fallbackTitle($page['path']),
                'h' => array_slice($headings, 0, 100),
                'x' => mb_substr($text, 0, $maxChars),
            ];
        }
        AtomicFile::writeJson($this->file($versionId), [
            'index_version' => self::INDEX_VERSION,
            'version' => $versionId,
            'count' => count($entries),
            'entries' => $entries,
        ], 0640);
    }

    /** @return array<string,mixed>|null */
    private function load(string $versionId): ?array
    {
        try {
            $d = AtomicFile::readJson($this->file($versionId));
        } catch (CorruptFileException) {
            return null;
        }
        if (is_array($d) && ($d['index_version'] ?? null) === self::INDEX_VERSION && ($d['version'] ?? null) === $versionId && is_array($d['entries'] ?? null)) {
            return $d;
        }
        return null;
    }

    /**
     * @param list<array{path:string,url:string,name:string}> $pages used to (re)build a missing index
     * @return array{query:string,results:list<array{title:string,path:string,url:string,excerpt:string}>,total:int}|null null = index unavailable
     */
    public function search(string $versionId, PathGuard $guard, array $pages, string $query): ?array
    {
        $maxLen = $this->settings->int('search_max_query_length');
        $query = trim(mb_substr(preg_replace('/[\x00-\x1f\x7f]/u', ' ', $query) ?? '', 0, $maxLen));
        $index = $this->load($versionId);
        if ($index === null) {
            try {
                $this->build($versionId, $guard, $pages);      // self-heal a missing/corrupt cache
                $index = $this->load($versionId);
            } catch (\Throwable) {
                $index = null;
            }
        }
        if ($index === null) {
            return null;
        }
        $terms = $this->terms($query);
        if ($terms === []) {
            return ['query' => $query, 'results' => [], 'total' => 0];
        }
        $scored = [];
        foreach ($index['entries'] as $e) {
            $title = mb_strtolower((string) $e['t']);
            $path = mb_strtolower((string) $e['p']);
            $heads = mb_strtolower(implode(' | ', (array) $e['h']));
            $body = mb_strtolower((string) $e['x']);
            $score = 0;
            $ok = true;
            foreach ($terms as $t) {
                $inTitle = mb_strpos($title, $t) !== false;
                $inPath = mb_strpos($path, $t) !== false;
                $inHead = mb_strpos($heads, $t) !== false;
                $bodyHits = $body === '' ? 0 : mb_substr_count($body, $t);
                if (!$inTitle && !$inPath && !$inHead && $bodyHits === 0) {
                    $ok = false;
                    break;
                }
                $score += ($inTitle ? 10 : 0) + ($inPath ? 6 : 0) + ($inHead ? 4 : 0) + min($bodyHits, 5);
            }
            if ($ok) {
                $scored[] = ['score' => $score, 'e' => $e, 'body' => $e['x']];
            }
        }
        usort($scored, static fn ($a, $b) => $b['score'] <=> $a['score'] ?: strcmp((string) $a['e']['p'], (string) $b['e']['p']));
        $total = count($scored);
        $results = [];
        foreach (array_slice($scored, 0, $this->settings->int('search_max_results')) as $s) {
            $results[] = [
                'title' => (string) $s['e']['t'],
                'path' => (string) $s['e']['p'],
                'url' => (string) $s['e']['u'],
                'excerpt' => $this->excerpt((string) $s['body'], $terms),
            ];
        }
        return ['query' => $query, 'results' => $results, 'total' => $total];
    }

    /** @return list<string> lowercase terms; "quoted phrases" stay together */
    public function terms(string $query): array
    {
        $terms = [];
        if (preg_match_all('/"([^"]+)"|(\S+)/u', $query, $m, PREG_SET_ORDER)) {
            foreach ($m as $x) {
                $t = mb_strtolower(trim($x[1] !== '' ? $x[1] : ($x[2] ?? '')));
                $t = trim($t, '"');
                if ($t !== '') {
                    $terms[] = $t;
                }
            }
        }
        return array_slice(array_values(array_unique($terms)), 0, 8);
    }

    /** @param list<string> $terms */
    private function excerpt(string $text, array $terms): string
    {
        if ($text === '') {
            return '';
        }
        $lower = mb_strtolower($text);
        $pos = null;
        foreach ($terms as $t) {
            $p = mb_strpos($lower, $t);
            if ($p !== false && ($pos === null || $p < $pos)) {
                $pos = $p;
            }
        }
        $start = max(0, ($pos ?? 0) - 60);
        $snippet = mb_substr($text, $start, 180);
        return ($start > 0 ? '…' : '') . trim($snippet) . (mb_strlen($text) > $start + 180 ? '…' : '');
    }
}

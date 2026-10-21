<?php
declare(strict_types=1);

namespace Wiki\Markdown;

use Wiki\Support\Paths;

/**
 * Allow-list HTML sanitizer (HTML Purifier) applied to ALL rendered Markdown, including raw HTML
 * written in the repository. No scripts, no event handlers, no style attributes, no iframes/objects/
 * forms, only http/https/mailto/tel URLs, and a closed set of class names.
 */
final class HtmlSanitizer
{
    /** Bump when the allow-list changes: it is part of the rendered-page cache key. */
    public const VERSION = 1;

    private const LANGUAGES = [
        'bash', 'sh', 'shell', 'zsh', 'console', 'powershell', 'bat', 'c', 'cpp', 'csharp', 'cs', 'css', 'scss', 'sass', 'less',
        'diff', 'dockerfile', 'go', 'golang', 'graphql', 'html', 'xml', 'svg', 'ini', 'toml', 'java', 'javascript', 'js', 'jsx',
        'json', 'jsonc', 'kotlin', 'latex', 'lua', 'makefile', 'make', 'markdown', 'md', 'nginx', 'apache', 'objectivec',
        'perl', 'php', 'plaintext', 'text', 'txt', 'python', 'py', 'r', 'ruby', 'rb', 'rust', 'rs', 'sql', 'swift', 'typescript',
        'ts', 'tsx', 'vue', 'yaml', 'yml', 'twig', 'blade', 'http', 'csv', 'regex', 'mermaid', 'dart', 'scala', 'haskell',
        'elixir', 'erlang', 'clojure', 'groovy', 'gradle', 'cmake', 'vim', 'asm', 'wasm',
    ];

    private ?\HTMLPurifier $purifier = null;

    public function __construct(private Paths $paths)
    {
    }

    public function sanitize(string $html): string
    {
        return $this->purifier()->purify($html);
    }

    private function purifier(): \HTMLPurifier
    {
        if ($this->purifier !== null) {
            return $this->purifier;
        }
        $cacheDir = $this->paths->cache . '/purifier';
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0750, true);
        }
        $c = \HTMLPurifier_Config::createDefault();
        $c->set('Core.Encoding', 'UTF-8');
        $c->set('HTML.Doctype', 'HTML 4.01 Transitional');
        $c->set('HTML.TidyLevel', 'none');
        if (is_dir($cacheDir) && is_writable($cacheDir)) {
            $c->set('Cache.SerializerPath', $cacheDir);
        } else {
            $c->set('Cache.DefinitionImpl', null);
        }
        $c->set('HTML.Allowed', implode(',', [
            '*[id|class|title]',
            'p[align]', 'br', 'hr', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
            'a[href|name]', 'img[src|alt|width|height]',
            'strong', 'b', 'em', 'i', 'u', 's', 'strike', 'del', 'ins', 'sub', 'sup', 'small', 'mark', 'abbr', 'cite', 'q',
            'code', 'pre', 'kbd', 'samp', 'var', 'blockquote',
            'ul', 'ol[start]', 'li', 'dl', 'dt', 'dd',
            'table', 'caption', 'colgroup', 'col', 'thead', 'tbody', 'tfoot', 'tr',
            'th[align|colspan|rowspan|scope]', 'td[align|colspan|rowspan]',
            'div[align]', 'span', 'details[open]', 'summary', 'figure', 'figcaption',
            'input[type|checked|disabled]',
        ]));
        $c->set('Attr.EnableID', true);
        $c->set('Attr.ID.HTML5', true);
        $c->set('Attr.IDBlacklistRegexp', '/^wiki-/i');      // repository HTML cannot impersonate UI ids
        $classes = ['task-list-item', 'contains-task-list', 'wiki-broken', 'wiki-broken-image'];
        foreach (self::LANGUAGES as $l) {
            $classes[] = 'language-' . $l;
        }
        $c->set('Attr.AllowedClasses', $classes);
        $c->set('CSS.AllowedProperties', []);                // style="" is dropped entirely
        $c->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true, 'tel' => true]);
        $c->set('URI.DisableExternalResources', false);
        $c->set('AutoFormat.RemoveEmpty', false);
        $c->set('Core.RemoveScriptContents', true);
        $c->set('Attr.AllowedFrameTargets', []);

        $def = $c->getHTMLDefinition(true);
        if ($def !== null) {
            $def->addElement('details', 'Block', 'Flow', 'Common', ['open' => 'Bool#open']);
            $def->addElement('summary', 'Block', 'Inline', 'Common');
            $def->addElement('mark', 'Inline', 'Inline', 'Common');
            $def->addElement('figure', 'Block', 'Flow', 'Common');
            $def->addElement('figcaption', 'Inline', 'Flow', 'Common');
            $def->addElement('input', 'Inline', 'Empty', 'Common', [
                'type' => 'Enum#checkbox', 'checked' => 'Bool#checked', 'disabled' => 'Bool#disabled',
            ]);
        }
        return $this->purifier = new \HTMLPurifier($c);
    }
}

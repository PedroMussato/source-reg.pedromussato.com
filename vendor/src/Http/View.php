<?php
declare(strict_types=1);

namespace Wiki\Http;

/** Minimal template renderer + context-aware escaping helpers. */
final class View
{
    public function __construct(private string $templateDir)
    {
    }

    public static function e(?string $s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** @param array<string,mixed> $vars */
    public function render(string $template, array $vars = []): string
    {
        $file = $this->templateDir . '/' . $template . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException('Missing template');
        }
        $e = static fn (?string $s): string => self::e($s);
        extract($vars, EXTR_SKIP);
        ob_start();
        try {
            require $file;
            return (string) ob_get_clean();
        } catch (\Throwable $t) {
            ob_end_clean();
            throw $t;
        }
    }

    /**
     * Sidebar tree as nested <details>: works without JavaScript; ancestors of the current page are open.
     *
     * @param list<array<string,mixed>> $tree
     */
    public static function nav(array $tree, string $currentPath): string
    {
        $out = '<ul class="nav-list">';
        foreach ($tree as $n) {
            $path = (string) $n['path'];
            if ($n['type'] === 'dir') {
                $isAncestor = $currentPath === $path || str_starts_with($currentPath, $path . '/');
                $label = self::e((string) $n['name']);
                $out .= '<li class="nav-dir"><details data-path="' . self::e($path) . '"' . ($isAncestor ? ' open' : '') . '><summary>';
                $out .= '<svg class="ico chev" viewBox="0 0 16 16" aria-hidden="true"><path d="M6 3l5 5-5 5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>';
                $out .= '<svg class="ico" viewBox="0 0 16 16" aria-hidden="true"><path d="M1.5 4a1 1 0 0 1 1-1h3.2l1.5 1.6h6.3a1 1 0 0 1 1 1V12a1 1 0 0 1-1 1h-11a1 1 0 0 1-1-1z" fill="currentColor" opacity=".75"/></svg>';
                if (!empty($n['landing'])) {
                    $cur = $currentPath !== '' && self::isLanding($currentPath, $path);
                    $out .= '<a class="nav-label' . ($cur ? ' current' : '') . '" href="' . self::e((string) $n['url']) . '"' . ($cur ? ' aria-current="page"' : '') . '>' . $label . '</a>';
                } else {
                    $out .= '<span class="nav-label">' . $label . '</span>';
                }
                $out .= '</summary>' . self::nav($n['children'], $currentPath) . '</details></li>';
            } else {
                $cur = $path === $currentPath;
                $out .= '<li class="nav-page"><a class="nav-link' . ($cur ? ' current' : '') . '" href="' . self::e((string) $n['url']) . '"' . ($cur ? ' aria-current="page"' : '') . '>';
                $out .= '<svg class="ico" viewBox="0 0 16 16" aria-hidden="true"><path d="M4 1.5h5l3.5 3.5v9a.5.5 0 0 1-.5.5H4a.5.5 0 0 1-.5-.5v-12a.5.5 0 0 1 .5-.5z" fill="none" stroke="currentColor" stroke-width="1.2"/></svg>';
                $out .= self::e((string) $n['name']) . '</a></li>';
            }
        }
        return $out . '</ul>';
    }

    private static function isLanding(string $currentPath, string $dirPath): bool
    {
        return dirname($currentPath) === $dirPath && in_array(basename($currentPath), \Wiki\Routing\UrlCodec::README_NAMES, true);
    }
}

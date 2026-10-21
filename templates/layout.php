<?php /** @var callable $e */ ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $e($title) ?> · <?= $e($siteTitle) ?></title>
<meta name="color-scheme" content="light dark">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<script src="/_wiki/static/js/theme-init.js?v=<?= $e($assetVer) ?>"></script>
<link rel="stylesheet" href="/_wiki/static/css/app.css?v=<?= $e($assetVer) ?>">
<link rel="stylesheet" href="/_wiki/static/css/markdown.css?v=<?= $e($assetVer) ?>">
</head>
<body class="<?= $navHtml === null ? 'no-nav' : '' ?>">
<a class="skip-link" href="#main">Skip to content</a>
<header class="topbar">
  <?php if ($navHtml !== null): ?>
  <button class="icon-btn menu-btn" type="button" id="menu-toggle" aria-label="Open navigation" aria-controls="sidebar" aria-expanded="false">
    <svg viewBox="0 0 20 20" aria-hidden="true"><path d="M3 5.5h14M3 10h14M3 14.5h14" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" fill="none"/></svg>
  </button>
  <?php endif; ?>
  <a class="brand" href="/"><img src="/favicon.svg" alt="" width="22" height="22"><span><?= $e($siteTitle) ?></span></a>
  <?php if ($navHtml !== null): ?>
  <div class="search" id="search" role="search">
    <label class="sr-only" for="search-input">Search the wiki</label>
    <input id="search-input" type="search" placeholder="Search…  ( / )" autocomplete="off" spellcheck="false" maxlength="100"
           role="combobox" aria-expanded="false" aria-controls="search-results" aria-autocomplete="list" disabled>
    <div id="search-results" class="search-results" role="listbox" hidden></div>
  </div>
  <?php endif; ?>
  <button class="icon-btn" type="button" id="theme-toggle" aria-label="Toggle light/dark theme" title="Toggle theme">
    <svg viewBox="0 0 20 20" aria-hidden="true"><path d="M10 3.2a6.8 6.8 0 1 0 6.8 6.8A5.4 5.4 0 0 1 10 3.2z" fill="currentColor"/></svg>
  </button>
</header>

<div class="shell">
  <?php if ($navHtml !== null): ?>
  <nav class="sidebar" id="sidebar" aria-label="Documentation">
    <?= $navHtml /* built by View::nav with escaping */ ?>
    <?php if (!empty($navTruncated)): ?><p class="muted small">Navigation was truncated: the repository exceeds the configured limits.</p><?php endif; ?>
  </nav>
  <div class="scrim" id="scrim" hidden></div>
  <?php endif; ?>

  <main id="main" class="content" tabindex="-1">
    <?php if (count($crumbs) > 1): ?>
    <nav class="crumbs" aria-label="Breadcrumb"><ol>
      <?php foreach ($crumbs as $c): ?>
        <li><?php if ($c['url'] !== null): ?><a href="<?= $e($c['url']) ?>"><?= $e($c['label']) ?></a><?php else: ?><span aria-current="page"><?= $e($c['label']) ?></span><?php endif; ?></li>
      <?php endforeach; ?>
    </ol></nav>
    <?php endif; ?>
    <?= $content ?>
  </main>

  <?php if ($toc !== []): ?>
  <aside class="toc" id="toc" aria-label="On this page">
    <p class="toc-title">On this page</p>
    <ul>
      <?php foreach ($toc as $t): ?>
        <li class="lvl-<?= (int) $t['level'] ?>"><a href="#<?= $e($t['id']) ?>"><?= $e($t['text']) ?></a></li>
      <?php endforeach; ?>
    </ul>
  </aside>
  <?php endif; ?>
</div>

<footer class="footer">
  <span>Wiki v<?= $e($appVersion) ?></span>
  <?php if ($commit !== ''): ?><span>Docs <code><?= $e($commit) ?></code></span><?php endif; ?>
  <?php if ($syncedAt !== ''): ?><span>Synced <time datetime="<?= $e($syncedAt) ?>" id="synced-at"><?= $e($syncedAt) ?></time></span><?php endif; ?>
  <span id="sync-indicator" class="sync-indicator" hidden>Updating…</span>
  <?php if ($repoUrl !== null): ?><a href="<?= $e($repoUrl) ?>" rel="noopener noreferrer">Source repository</a><?php endif; ?>
</footer>

<script src="/_wiki/static/vendor/highlight.min.js?v=<?= $e($assetVer) ?>" defer></script>
<script src="/_wiki/static/js/app.js?v=<?= $e($assetVer) ?>" defer></script>
<?php if ($navHtml !== null): ?><script src="/_wiki/static/js/search.js?v=<?= $e($assetVer) ?>" defer></script><?php endif; ?>
</body>
</html>

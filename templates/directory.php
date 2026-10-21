<article class="markdown-body">
  <h1><?= $e($name) ?></h1>
  <p class="muted">This folder has no README. Pages in this section:</p>
  <?php if ($children === []): ?>
    <p>This section is empty.</p>
  <?php else: ?>
  <ul class="dir-overview">
    <?php foreach ($children as $c): ?>
      <li class="<?= $c['type'] === 'dir' ? 'is-dir' : 'is-page' ?>"><a href="<?= $e($c['url']) ?>"><?= $e($c['name']) ?><?= $c['type'] === 'dir' ? '/' : '' ?></a></li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</article>

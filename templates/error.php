<section class="notice" role="alert">
  <p class="notice-code"><?= (int) $status ?></p>
  <h1><?= $e($heading) ?></h1>
  <p><?= $e($message) ?></p>
  <p class="notice-actions">
    <a class="btn" href="/">Go to the homepage</a>
    <?php if (!empty($searchHint)): ?><button type="button" class="btn btn-ghost" data-open-search>Search the wiki</button><?php endif; ?>
  </p>
</section>

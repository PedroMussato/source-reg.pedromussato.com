<?php /** @var callable $e */ ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Set up · Git Wiki</title>
<meta name="color-scheme" content="light dark">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<script src="/_wiki/static/js/theme-init.js?v=<?= $e($version) ?>"></script>
<link rel="stylesheet" href="/_wiki/static/css/app.css?v=<?= $e($version) ?>">
</head>
<body class="setup-page">
<main class="setup-card">
  <img src="/favicon.svg" alt="" width="40" height="40">
  <h1>Set up your wiki</h1>
  <p>This is a read-only documentation site. Point it at a Git repository of Markdown files; folders become navigation, <code>README.md</code> becomes the homepage.</p>

  <?php if ($error !== null): ?><p class="form-error" role="alert"><?= $e($error) ?></p><?php endif; ?>
  <?php if (!$gitOk): ?><p class="form-error" role="alert">Git is not installed or not executable on this server, so the repository cannot be cloned.</p><?php endif; ?>
  <?php if (!$processOk): ?><p class="form-warn" role="status">Background processes are disabled on this server (<code>proc_open</code>). Automatic updates cannot run non-blocking here; see the README (&ldquo;Shared hosting&rdquo;).</p><?php endif; ?>

  <form method="post" action="/_setup" id="setup-form" autocomplete="off">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <label for="repository_url">Git repository URL</label>
    <input id="repository_url" name="repository_url" type="url" inputmode="url" required maxlength="500"
           placeholder="https://github.com/owner/docs.git" value="<?= $e($url) ?>">
    <p class="hint">HTTPS<?= $allowSsh ? ' or SSH' : '' ?> URLs. Do not put passwords or tokens in the URL.</p>

    <label for="branch">Branch <span class="muted">(optional)</span></label>
    <input id="branch" name="branch" type="text" maxlength="200" placeholder="default branch" value="<?= $e($branch) ?>">

    <p class="warning-box"><strong>This cannot be changed later.</strong> The repository and branch become permanent once setup succeeds.</p>
    <button type="submit" class="btn btn-primary" id="setup-submit">Clone repository and finish setup</button>
    <p class="hint" id="setup-progress" role="status" hidden>Cloning… this can take a little while for large repositories.</p>
  </form>
</main>
<script src="/_wiki/static/js/setup.js?v=<?= $e($version) ?>" defer></script>
</body>
</html>

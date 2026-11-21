<?php
/** @var League\Plates\Template\Template $this */
/** @var string $title */
/** @var bool $bare */
$bare ??= false;
?><!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= $this->e($title) ?> - Re:無事</title>
<link rel="stylesheet" href="<?= $this->e($this->url('/assets/app.css')) ?>?v=<?= $this->e(ReBuji\Version::CURRENT) ?>">
</head>
<body<?= $bare ? ' class="bare"' : '' ?>>
<header><strong>Re:無事</strong><?= $bare ? '' : $this->section('nav') ?></header>
<h1><?= $this->e($title) ?></h1>
<?php $notice = $this->flash(); ?>
<?php if ($notice !== null): ?>
<p class="alert alert-ok"><?= $this->e($notice) ?></p>
<?php endif ?>
<?= $this->section('content') ?>
</body>
</html>

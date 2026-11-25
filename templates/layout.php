<?php
/** @var League\Plates\Template\Template $this */
/** @var string $title */
/** @var bool $bare */
$bare ??= false;
$nav = $bare ? '' : trim((string) $this->section('nav'));
$classes = array_filter([$bare ? 'bare' : '', $nav !== '' ? 'has-nav' : '']);
// The favicon's drawing, beside the name; large above the title where there is no nav to make room for.
$icon = $this->e($this->url('/assets/favicon.svg')) . '?v=' . $this->e(ReBuji\Version::CURRENT);
?><!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= $this->e($title) ?> - Re:無事</title>
<link rel="icon" href="<?= $this->e($this->url('/assets/favicon-32.png')) ?>?v=<?= $this->e(ReBuji\Version::CURRENT) ?>" sizes="32x32" type="image/png">
<link rel="icon" href="<?= $this->e($this->url('/assets/favicon.svg')) ?>?v=<?= $this->e(ReBuji\Version::CURRENT) ?>" type="image/svg+xml">
<link rel="apple-touch-icon" href="<?= $this->e($this->url('/assets/apple-touch-icon.png')) ?>?v=<?= $this->e(ReBuji\Version::CURRENT) ?>">
<link rel="stylesheet" href="<?= $this->e($this->url('/assets/app.css')) ?>?v=<?= $this->e(ReBuji\Version::CURRENT) ?>">
</head>
<body<?= $classes === [] ? '' : ' class="' . implode(' ', $classes) . '"' ?>>
<?php if ($bare): ?>
<header class="brand-big"><img src="<?= $icon ?>" alt="" width="56" height="56"><strong>Re:無事</strong></header>
<?php else: ?>
<header><span class="brand"><img src="<?= $icon ?>" alt="" width="28" height="28"><strong>Re:無事</strong></span><?= $nav ?></header>
<?php endif ?>
<h1><?= $this->e($title) ?></h1>
<?php $notice = $this->flash(); ?>
<?php if ($notice !== null): ?>
<p class="alert alert-ok"><?= $this->e($notice) ?></p>
<?php endif ?>
<?= $this->section('content') ?>
</body>
</html>

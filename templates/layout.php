<?php
/** @var League\Plates\Template\Template $this */
/** @var string $title */
?><!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= $this->e($title) ?> - Re:無事</title>
<style>
body{font-family:system-ui,sans-serif;color:#1f1f1d;background:#fff;max-width:760px;margin:0 auto;padding:16px;line-height:1.7}
header{display:flex;justify-content:space-between;align-items:baseline;border-bottom:1px solid #ddd;margin-bottom:1.5rem}
h1{font-size:20px;font-weight:500}
.error{color:#a32d2d}.warning{color:#854f0b}.notice{color:#0f6e56}.muted{color:#6b6a66;font-size:14px}
table{border-collapse:collapse;width:100%}th,td{text-align:left;padding:6px 8px;border-bottom:1px solid #eee;vertical-align:top}
fieldset{border:1px solid #ddd;margin-top:1rem}.row{display:flex;gap:8px;flex-wrap:wrap}.row label{flex:1;min-width:140px}
label{display:block;margin-top:1rem}input,select{font:inherit;padding:6px 8px;width:100%;max-width:420px;box-sizing:border-box}
button{font:inherit;padding:6px 14px;margin-top:1rem}
</style>
</head>
<body>
<header><strong>Re:無事</strong><?= $this->section('nav') ?></header>
<h1><?= $this->e($title) ?></h1>
<?php $notice = $this->flash(); ?>
<?php if ($notice !== null): ?>
<p class="notice"><?= $this->e($notice) ?></p>
<?php endif ?>
<?= $this->section('content') ?>
</body>
</html>

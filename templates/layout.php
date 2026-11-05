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
.error{color:#a32d2d}.warning{color:#854f0b}.muted{color:#6b6a66;font-size:14px}
label{display:block;margin-top:1rem}input,select{font:inherit;padding:6px 8px;width:100%;max-width:420px;box-sizing:border-box}
button{font:inherit;padding:6px 14px;margin-top:1rem}
</style>
</head>
<body>
<header><strong>Re:無事</strong><?= $this->section('nav') ?></header>
<h1><?= $this->e($title) ?></h1>
<?= $this->section('content') ?>
</body>
</html>

<?php
/** @var League\Plates\Template\Template $this */
/** @var string $title */
/** @var list<array{key: string, title: string}> $steps */
/** @var string $step */
/** @var list<string> $errors */
/** @var list<string> $problems */
/** @var bool $https */
/** @var string $phpVersion */
$this->layout('layout', ['title' => $title]);
?>
<?= $this->insert('install/progress', ['steps' => $steps, 'step' => $step]) ?>
<?= $this->insert('install/errors', ['errors' => $errors === [] ? $problems : $errors]) ?>
<?php if (!$https): ?>
<p class="warning">HTTPS で開いてください（レンタルサーバーの管理画面で無料 SSL を有効にし、https:// の URL で開き直す）。このまま進むこともできますが、ログインのパスワードが暗号化されずに送られます。</p>
<?php endif ?>
<p>PHP <?= $this->e($phpVersion) ?> で動いています。</p>
<form method="post" action="<?= $this->e($this->url('/install/environment')) ?>">
    <?= $this->csrf() ?>
    <button type="submit">次へ</button>
</form>

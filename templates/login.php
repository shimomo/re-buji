<?php
/** @var League\Plates\Template\Template $this */
/** @var string $title */
/** @var string $email */
/** @var string|null $error */
/** @var bool $needsAdmin */
$this->layout('layout', ['title' => $title, 'bare' => true]);
?>
<?php if ($error !== null): ?>
<p class="alert alert-stop"><?= $this->e($error) ?></p>
<?php endif ?>
<?php if ($needsAdmin): ?>
<p class="alert alert-info">まだ管理者がいません。サーバーで docker compose exec app php bin/console admin:create を流して作ってください。</p>
<?php endif ?>
<div class="card">
<form method="post" action="<?= $this->e($this->url('/login')) ?>">
    <?= $this->csrf() ?>
    <label>メールアドレス <input type="email" name="email" value="<?= $this->e($email) ?>" autocomplete="username" required></label>
    <label>パスワード <input type="password" name="password" autocomplete="current-password" required></label>
    <button type="submit">ログイン</button>
</form>
</div>

<?php
/** @var League\Plates\Template\Template $this */
/** @var string $title */
/** @var string $email */
/** @var string|null $error */
$this->layout('layout', ['title' => $title]);
?>
<?php if ($error !== null): ?>
<p class="error"><?= $this->e($error) ?></p>
<?php endif ?>
<form method="post" action="<?= $this->e($this->url('/login')) ?>">
    <?= $this->csrf() ?>
    <label>メールアドレス <input type="email" name="email" value="<?= $this->e($email) ?>" autocomplete="username" required></label>
    <label>パスワード <input type="password" name="password" autocomplete="current-password" required></label>
    <button type="submit">ログイン</button>
</form>

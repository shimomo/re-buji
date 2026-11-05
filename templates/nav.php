<?php
/** @var League\Plates\Template\Template $this */
/** @var array{id: int, name: string, email: string} $admin */
?>
<form method="post" action="<?= $this->e($this->url('/logout')) ?>">
    <span class="muted"><?= $this->e($admin['name']) ?></span>
    <?= $this->csrf() ?>
    <button type="submit">ログアウト</button>
</form>

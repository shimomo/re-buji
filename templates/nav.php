<?php
/** @var League\Plates\Template\Template $this */
/** @var array{id: int, name: string, email: string} $admin */
?>
<nav>
    <a href="<?= $this->e($this->url('/')) ?>">ホーム</a>
    <a href="<?= $this->e($this->url('/earthquakes')) ?>">地震</a>
    <a href="<?= $this->e($this->url('/drills/new')) ?>">訓練</a>
    <a href="<?= $this->e($this->url('/mail/unmatched')) ?>">照合できなかったメール</a>
    <a href="<?= $this->e($this->url('/employees')) ?>">従業員</a>
    <a href="<?= $this->e($this->url('/admins')) ?>">管理者</a>
    <a href="<?= $this->e($this->url('/settings')) ?>">設定</a>
    <a href="<?= $this->e($this->url('/admin/database')) ?>">DB の更新</a>
</nav>
<form method="post" action="<?= $this->e($this->url('/logout')) ?>">
    <span class="muted"><?= $this->e($admin['name']) ?></span>
    <?= $this->csrf() ?>
    <button type="submit">ログアウト</button>
</form>

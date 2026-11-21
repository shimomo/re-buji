<?php
/** @var League\Plates\Template\Template $this */
/** @var string $title */
/** @var array{id: int, name: string, email: string} $admin */
$this->layout('layout', ['title' => $title]);
?>
<?php $this->start('nav') ?><?= $this->insert('nav', ['admin' => $admin, 'current' => 'more']) ?><?php $this->stop() ?>
<div class="card">
    <a class="row-link" href="<?= $this->e($this->url('/drills/new')) ?>">訓練</a>
    <a class="row-link" href="<?= $this->e($this->url('/admins')) ?>">管理者</a>
    <a class="row-link" href="<?= $this->e($this->url('/settings')) ?>">設定</a>
    <a class="row-link" href="<?= $this->e($this->url('/admin/database')) ?>">DB の更新</a>
</div>
<form method="post" action="<?= $this->e($this->url('/logout')) ?>">
    <?= $this->csrf() ?>
    <button type="submit">ログアウト</button>
</form>

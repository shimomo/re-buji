<?php
/** @var League\Plates\Template\Template $this */
/** @var string $title */
/** @var array{id: int, name: string, email: string} $admin */
/** @var string $version */
/** @var list<array{version: string, description: string}> $pending */
/** @var list<string>|null $applied */
$this->layout('layout', ['title' => $title]);
?>
<?php $this->start('nav') ?><?= $this->insert('nav', ['admin' => $admin]) ?><?php $this->stop() ?>
<p>Re:無事 <?= $this->e($version) ?></p>
<?php if ($applied !== null): ?>
<p><?= $applied === [] ? '当てる変更はありませんでした。' : '当てました：' . $this->e(implode('、', $applied)) ?></p>
<?php endif ?>
<?php if ($pending === []): ?>
<p>DB は最新です。</p>
<?php else: ?>
<p>新しい版をアップロードした後は、DB の変更を当ててください。</p>
<ul>
<?php foreach ($pending as $migration): ?>
    <li><?= $this->e($migration['version']) ?>：<?= $this->e($migration['description']) ?></li>
<?php endforeach ?>
</ul>
<form method="post" action="<?= $this->e($this->url('/admin/database')) ?>">
    <?= $this->csrf() ?>
    <button type="submit">DB を更新する</button>
</form>
<?php endif ?>
<p><a href="<?= $this->e($this->url('/')) ?>">ホームへ</a></p>

<?php
/** @var League\Plates\Template\Template $this */
/** @var string $title */
/** @var array{id: int, name: string, email: string} $admin */
$this->layout('layout', ['title' => $title]);
?>
<?php $this->start('nav') ?><?= $this->insert('nav', ['admin' => $admin]) ?><?php $this->stop() ?>
<p><?= $this->e($admin['name']) ?> さん、ようこそ。</p>

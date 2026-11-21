<?php
/** @var League\Plates\Template\Template $this */
/** @var string $title */
/** @var string $message */
$this->layout('layout', ['title' => $title, 'bare' => true]);
?>
<div class="card">
<p><?= $this->e($message) ?></p>
</div>

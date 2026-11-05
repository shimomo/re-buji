<?php
/** @var League\Plates\Template\Template $this */
/** @var string $title */
/** @var string $message */
$this->layout('layout', ['title' => $title]);
?>
<p><?= $this->e($message) ?></p>

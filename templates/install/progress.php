<?php
/** @var League\Plates\Template\Template $this */
/** @var list<array{key: string, title: string}> $steps */
/** @var string $step */
?>
<ol class="muted">
<?php foreach ($steps as $item): ?>
    <li><?= $item['key'] === $step ? '<strong>' . $this->e($item['title']) . '</strong>' : $this->e($item['title']) ?></li>
<?php endforeach ?>
</ol>

<?php
/** @var League\Plates\Template\Template $this */
/** @var list<array{key: string, title: string}> $steps */
/** @var string $step */
$number = 0;
$name = '';
foreach ($steps as $i => $item) {
    if ($item['key'] === $step) {
        $number = $i + 1;
        $name = $item['title'];
    }
}
?>
<p class="progress">全 <?= count($steps) ?> 段階のうち <?= $number ?> 段階目：<?= $this->e($name) ?></p>
<p class="dots" aria-hidden="true">
<?php foreach ($steps as $i => $item): ?>
    <span class="dot<?= $i + 1 === $number ? ' dot-on' : '' ?>"></span>
<?php endforeach ?>
</p>

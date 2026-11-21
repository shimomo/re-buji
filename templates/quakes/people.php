<?php
/** @var League\Plates\Template\Template $this */
/** @var list<ReBuji\Quakes\Board\BoardRow> $rows */
/** @var int|null $limit */
/** @var 'reason'|'replied' $detail */
/** @var bool $check */
/** @var string $from */
?>
<?php foreach ($rows as $i => $row): ?>
<?php if ($limit !== null && $i === $limit): ?>
<details><summary>ほか <?= count($rows) - $limit ?> 人を表示</summary>
<?php endif ?>
<?= $this->insert('earthquakes/row', ['row' => $row, 'detail' => $detail === 'reason' ? $row->reason : ($row->repliedAt ?? '') . ' 返信', 'check' => $check, 'from' => $from]) ?>
<?php endforeach ?>
<?php if ($limit !== null && count($rows) > $limit): ?>
</details>
<?php endif ?>

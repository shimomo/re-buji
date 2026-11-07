<?php
/** @var League\Plates\Template\Template $this */
/** @var string $title */
/** @var array{id: int, name: string, email: string} $admin */
/** @var ReBuji\Quakes\Earthquake $quake */
/** @var string $heading */
/** @var string|null $basis */
/** @var array{noReply: list<ReBuji\Quakes\Board\BoardRow>, needsCheck: list<ReBuji\Quakes\Board\BoardRow>, checked: list<ReBuji\Quakes\Board\BoardRow>, safe: list<ReBuji\Quakes\Board\BoardRow>, total: int} $board */
$this->layout('layout', ['title' => $title]);
$shown = 20;
?>
<?php $this->start('nav') ?><?= $this->insert('nav', ['admin' => $admin]) ?><?php $this->stop() ?>
<p><strong><?= $this->e($heading) ?></strong></p>
<?php if ($basis !== null): ?>
<p class="muted"><?= $this->e($basis) ?></p>
<?php endif ?>
<p><?= $quake->isDrill() ? '訓練' : '通常（訓練ではない）' ?></p>
<?php if ($quake->isCancelled()): ?>
<p class="error">この地震は気象庁が取り消しました。まだ送っていない連絡は送らず、送った人には取消の知らせを送ります。</p>
<?php elseif ($quake->delivery === ReBuji\Quakes\Earthquake::HELD): ?>
<p class="warning">遅れて届いた地震です。送るかどうかを決めてください。</p>
<form method="post" action="<?= $this->e($this->url('/earthquakes/' . $quake->id . '/release')) ?>"><?= $this->csrf() ?><button type="submit">送る</button></form>
<form method="post" action="<?= $this->e($this->url('/earthquakes/' . $quake->id . '/dismiss')) ?>" data-confirm="この地震の連絡を送らないことにしますか？" onsubmit="return confirm(this.dataset.confirm)"><?= $this->csrf() ?><button type="submit">送らない</button></form>
<?php endif ?>
<p>対象 <?= $board['total'] ?> 人　返信なし <?= count($board['noReply']) ?> 人　要確認（本文あり） <?= count($board['needsCheck']) ?> 人　無事（空の返信） <?= count($board['safe']) ?> 人<?= $board['checked'] === [] ? '' : '　確認済み ' . count($board['checked']) . ' 人' ?></p>

<h2>返信なし（<?= count($board['noReply']) ?> 人）</h2>
<?php foreach ($board['noReply'] as $i => $row): ?>
<?php if ($i === $shown): ?>
<details><summary>ほか <?= count($board['noReply']) - $shown ?> 人を表示</summary>
<?php endif ?>
<?= $this->insert('earthquakes/row', ['row' => $row, 'detail' => $row->reason, 'check' => false]) ?>
<?php endforeach ?>
<?php if (count($board['noReply']) > $shown): ?>
</details>
<?php endif ?>

<h2>要確認（<?= count($board['needsCheck']) ?> 人）</h2>
<?php foreach ($board['needsCheck'] as $row): ?>
<?= $this->insert('earthquakes/row', ['row' => $row, 'detail' => ($row->repliedAt ?? '') . ' 返信', 'check' => true]) ?>
<?php endforeach ?>
<?php if ($board['checked'] !== []): ?>
<details><summary>確認済み（<?= count($board['checked']) ?> 人）</summary>
<?php foreach ($board['checked'] as $row): ?>
<?= $this->insert('earthquakes/row', ['row' => $row, 'detail' => ($row->repliedAt ?? '') . ' 返信', 'check' => false]) ?>
<?php endforeach ?>
</details>
<?php endif ?>

<h2>無事（<?= count($board['safe']) ?> 人）</h2>
<?php if ($board['safe'] !== []): ?>
<details><summary>空の返信があった人。一覧を開く</summary>
<?php foreach ($board['safe'] as $row): ?>
<?= $this->insert('earthquakes/row', ['row' => $row, 'detail' => ($row->repliedAt ?? '') . ' 返信', 'check' => false]) ?>
<?php endforeach ?>
</details>
<?php endif ?>

<?php
/** @var League\Plates\Template\Template $this */
/** @var string $title */
/** @var array{id: int, name: string, email: string} $admin */
/** @var list<array{id: int, detected: string, title: string, drill: bool, cancelled: bool, held: bool, total: int, sent: int, waiting: int, failed: int}> $rows */
$this->layout('layout', ['title' => $title]);
?>
<?php $this->start('nav') ?><?= $this->insert('nav', ['admin' => $admin]) ?><?php $this->stop() ?>
<?php if ($rows === []): ?>
<p>まだ地震の連絡はありません。登録した市町村が基準の震度になると、ここに出ます。</p>
<?php else: ?>
<table>
    <thead><tr><th>発生時刻</th><th>地震</th><th>対象</th><th>送信済み</th><th>送信待ち</th><th>届かなかった</th><th>状態</th></tr></thead>
    <tbody>
<?php foreach ($rows as $row): ?>
        <tr>
            <td><?= $this->e($row['detected']) ?></td>
            <td><?= $this->e($row['title']) ?></td>
            <td><?= $row['total'] ?></td><td><?= $row['sent'] ?></td><td><?= $row['waiting'] ?></td><td><?= $row['failed'] ?></td>
            <td>
<?php if ($row['cancelled']): ?>
                取消（気象庁が取り消しました）
<?php elseif ($row['held']): ?>
                <span class="warning">遅れて届いた地震</span>
                <form method="post" action="<?= $this->e($this->url('/earthquakes/' . $row['id'] . '/release')) ?>">
                    <?= $this->csrf() ?>
                    <button type="submit">送る</button>
                </form>
                <form method="post" action="<?= $this->e($this->url('/earthquakes/' . $row['id'] . '/dismiss')) ?>" data-confirm="この地震の連絡を送らないことにしますか？" onsubmit="return confirm(this.dataset.confirm)">
                    <?= $this->csrf() ?>
                    <button type="submit">送らない</button>
                </form>
<?php elseif ($row['drill']): ?>
                訓練
<?php endif ?>
            </td>
        </tr>
<?php endforeach ?>
    </tbody>
</table>
<?php endif ?>

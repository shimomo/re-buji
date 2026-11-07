<?php
/** @var League\Plates\Template\Template $this */
/** @var string $title */
/** @var array{id: int, name: string, email: string} $admin */
/** @var list<array{id: int, kind: string, from: string, subject: string, excerpt: string, received: string}> $mails */
$this->layout('layout', ['title' => $title]);
?>
<?php $this->start('nav') ?><?= $this->insert('nav', ['admin' => $admin]) ?><?php $this->stop() ?>
<p class="muted">登録していないアドレスからのメールや、番号の無いメールです。本人からの安否の知らせなら、地震の画面の「代理で記録」で記録してください。</p>
<?php if ($mails === []): ?>
<p>照合できなかったメールはありません。</p>
<?php else: ?>
<table>
    <thead><tr><th>受け取った時刻</th><th>種類</th><th>送り主</th><th>件名と本文</th><th></th></tr></thead>
    <tbody>
<?php foreach ($mails as $mail): ?>
        <tr>
            <td><?= $this->e($mail['received']) ?></td>
            <td><?= $this->e($mail['kind']) ?></td>
            <td><?= $this->e($mail['from']) ?></td>
            <td><?= $this->e($mail['subject']) ?><br><span class="muted"><?= $this->e($mail['excerpt']) ?></span></td>
            <td>
                <form method="post" action="<?= $this->e($this->url('/mail/unmatched/' . $mail['id'] . '/delete')) ?>" data-confirm="このメールの記録を消しますか？" onsubmit="return confirm(this.dataset.confirm)">
                    <?= $this->csrf() ?>
                    <button type="submit">消す</button>
                </form>
            </td>
        </tr>
<?php endforeach ?>
    </tbody>
</table>
<?php endif ?>

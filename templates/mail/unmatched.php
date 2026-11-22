<?php
/** @var League\Plates\Template\Template $this */
/** @var string $title */
/** @var array{id: int, name: string, email: string} $admin */
/** @var list<array{id: int, kind: string, from: string, subject: string, excerpt: string, received: string}> $mails */
$this->layout('layout', ['title' => $title]);
?>
<?php $this->start('nav') ?><?= $this->insert('nav', ['admin' => $admin, 'current' => 'unmatched']) ?><?php $this->stop() ?>
<p class="muted">登録していないアドレスからのメールや、番号の無いメールです。本人からの安否の知らせなら、地震の画面の「代理で記録」で記録してください。</p>
<?php if ($mails === []): ?>
<p>照合できなかったメールはありません。</p>
<?php else: ?>
<form id="delete-selected" method="post" action="<?= $this->e($this->url('/mail/unmatched/delete')) ?>" data-confirm="選んだメールの記録を消しますか？" onsubmit="return Array.prototype.some.call(this.elements, function (e) { return e.checked; }) ? confirm(this.dataset.confirm) : true">
    <?= $this->csrf() ?>
    <button type="submit" class="btn-danger">選んだメールを消す</button>
</form>
<table class="stack">
    <thead><tr><th></th><th>受け取った時刻</th><th>種類</th><th>送り主</th><th>件名と本文</th><th></th></tr></thead>
    <tbody>
<?php foreach ($mails as $mail): ?>
        <tr>
            <td><input type="checkbox" name="ids[]" value="<?= $mail['id'] ?>" form="delete-selected" aria-label="<?= trim($mail['from']) === '' ? '送り主の分からないメールを選ぶ' : $this->e($mail['from']) . ' からのメールを選ぶ' ?>"></td>
            <td data-label="受け取った時刻"><?= $this->e($mail['received']) ?></td>
            <td data-label="種類"><?= $this->e($mail['kind']) ?></td>
            <td data-label="送り主"><?= $this->e($mail['from']) ?></td>
            <td data-label="件名と本文"><?= $this->e($mail['subject']) ?><br><span class="muted"><?= $this->e($mail['excerpt']) ?></span></td>
            <td>
                <form method="post" action="<?= $this->e($this->url('/mail/unmatched/' . $mail['id'] . '/delete')) ?>" data-confirm="このメールの記録を消しますか？" onsubmit="return confirm(this.dataset.confirm)">
                    <?= $this->csrf() ?>
                    <button type="submit" class="btn-danger">消す</button>
                </form>
            </td>
        </tr>
<?php endforeach ?>
    </tbody>
</table>
<?php endif ?>

<?php
/** @var League\Plates\Template\Template $this */
/** @var string $title */
/** @var array{id: int, name: string, email: string} $admin */
/** @var ReBuji\Quakes\Earthquake $quake */
/** @var string $heading */
/** @var string|null $basis */
/** @var array{noReply: list<ReBuji\Quakes\Board\BoardRow>, needsCheck: list<ReBuji\Quakes\Board\BoardRow>, checked: list<ReBuji\Quakes\Board\BoardRow>, safe: list<ReBuji\Quakes\Board\BoardRow>, total: int} $board */
/** @var int $unmatchedMails */
$this->layout('layout', ['title' => $title]);
?>
<?php $this->start('nav') ?><?= $this->insert('nav', ['admin' => $admin, 'current' => 'earthquakes']) ?><?php $this->stop() ?>
<?= $this->insert('quakes/summary', ['quake' => $quake, 'heading' => $heading, 'basis' => $basis, 'board' => $board, 'link' => false]) ?>
<?php if ($quake->isCancelled()): ?>
<p class="alert alert-stop">この地震は気象庁が取り消しました。まだ送っていない連絡は送らず、送った人には取消の知らせを送ります。</p>
<?php elseif ($quake->delivery === ReBuji\Quakes\Earthquake::HELD): ?>
<p class="alert alert-act">遅れて届いた地震です。送るかどうかを決めてください。</p>
<form method="post" action="<?= $this->e($this->url('/earthquakes/' . $quake->id . '/release')) ?>"><?= $this->csrf() ?><button type="submit" class="btn-primary">送る</button></form>
<form method="post" action="<?= $this->e($this->url('/earthquakes/' . $quake->id . '/dismiss')) ?>" data-confirm="この地震の連絡を送らないことにしますか？" onsubmit="return confirm(this.dataset.confirm)"><?= $this->csrf() ?><button type="submit" class="btn-danger">送らない</button></form>
<?php endif ?>

<h2>返信なし（<?= count($board['noReply']) ?> 人）</h2>
<?php if ($unmatchedMails > 0 && $board['noReply'] !== []): ?>
<p class="alert alert-act">この地震の連絡を送った後に、照合できなかったメールが <?= $unmatchedMails ?> 通届いています。返信なしの人からのメールかもしれません。<a href="<?= $this->e($this->url('/mail/unmatched')) ?>">一覧</a></p>
<?php endif ?>
<?= $this->insert('quakes/people', ['rows' => $board['noReply'], 'limit' => 20, 'detail' => 'reason', 'check' => false, 'from' => '']) ?>

<h2>要確認（<?= count($board['needsCheck']) ?> 人）</h2>
<?= $this->insert('quakes/people', ['rows' => $board['needsCheck'], 'limit' => null, 'detail' => 'replied', 'check' => true, 'from' => '']) ?>
<?php if ($board['checked'] !== []): ?>
<details><summary>確認済み（<?= count($board['checked']) ?> 人）</summary>
<?= $this->insert('quakes/people', ['rows' => $board['checked'], 'limit' => null, 'detail' => 'replied', 'check' => false, 'from' => '']) ?>
</details>
<?php endif ?>

<h2>無事（<?= count($board['safe']) ?> 人）</h2>
<?php if ($board['safe'] !== []): ?>
<details><summary>空の返信があった人。一覧を開く</summary>
<?= $this->insert('quakes/people', ['rows' => $board['safe'], 'limit' => null, 'detail' => 'replied', 'check' => false, 'from' => '']) ?>
</details>
<?php endif ?>

<h2>記録の削除</h2>
<p class="muted">古い地震の記録は、個人の情報を残しすぎないように消せます（連絡、送ったメール、返信、気象庁の電文）。</p>
<form method="post" action="<?= $this->e($this->url('/earthquakes/' . $quake->id . '/delete')) ?>" data-confirm="この地震の連絡と返信の記録をすべて消します。元に戻せません。消しますか？" onsubmit="return confirm(this.dataset.confirm)">
    <?= $this->csrf() ?>
    <button type="submit" class="btn-danger">この地震の記録を消す</button>
</form>

<?php
/** @var League\Plates\Template\Template $this */
/** @var ReBuji\Quakes\Earthquake $quake */
/** @var string $heading */
/** @var string|null $basis */
/** @var array{noReply: list<ReBuji\Quakes\Board\BoardRow>, needsCheck: list<ReBuji\Quakes\Board\BoardRow>, checked: list<ReBuji\Quakes\Board\BoardRow>, safe: list<ReBuji\Quakes\Board\BoardRow>, total: int} $board */
/** @var bool $link */
?>
<div class="card">
    <div class="card-head"><?php if ($link): ?><a href="<?= $this->e($this->url('/earthquakes/' . $quake->id)) ?>"><?= $this->e($heading) ?></a><?php else: ?><span><?= $this->e($heading) ?></span><?php endif ?></div>
<?php if ($basis !== null): ?>
    <p class="muted"><?= $this->e($basis) ?></p>
<?php endif ?>
    <p class="muted"><?= $quake->isDrill() ? '訓練' : '通常（訓練ではない）' ?>　対象 <?= $board['total'] ?> 人</p>
</div>
<div class="tiles">
    <div class="tile tile-none"><span class="tile-n"><?= count($board['noReply']) ?></span><span class="tile-label">返信なし</span></div>
    <div class="tile tile-check"><span class="tile-n"><?= count($board['needsCheck']) ?></span><span class="tile-label">要確認</span><?php if ($board['checked'] !== []): ?><span class="tile-sub">うち確認済み <?= count($board['checked']) ?> 人</span><?php endif ?></div>
    <div class="tile tile-ok"><span class="tile-n"><?= count($board['safe']) ?></span><span class="tile-label">無事</span></div>
    <div class="tile tile-all"><span class="tile-n"><?= $board['total'] ?></span><span class="tile-label">対象</span></div>
</div>

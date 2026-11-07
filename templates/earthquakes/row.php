<?php
/** @var League\Plates\Template\Template $this */
/** @var ReBuji\Quakes\Board\BoardRow $row */
/** @var string $detail */
/** @var bool $check */
?>
<div class="row">
    <div><?= $this->e($row->name) ?> <span class="muted"><?= $this->e($row->department) ?></span></div>
    <div><?= $this->e($row->places) ?></div>
    <div><?= $this->e($detail) ?><?php if ($row->foldedInto !== null): ?> <span class="muted">（<?= $this->e($row->foldedInto) ?> の連絡に含めた）</span><?php endif ?></div>
</div>
<?php foreach ($row->bodies as $body): ?>
<p>「<?= nl2br($this->e($body)) ?>」</p>
<?php endforeach ?>
<?php if ($row->proxy !== null): ?>
<p class="muted"><?= $this->e($row->proxy) ?></p>
<?php endif ?>
<?php if ($check): ?>
<form method="post" action="<?= $this->e($this->url('/contacts/' . $row->contactId . '/check')) ?>"><?= $this->csrf() ?><button type="submit">確認済みにする</button></form>
<?php endif ?>
<p class="muted"><a href="<?= $this->e($this->url('/contacts/' . $row->contactId . '/proxy')) ?>">代理で記録</a></p>

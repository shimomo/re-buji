<?php
/** @var League\Plates\Template\Template $this */
/** @var ReBuji\Quakes\Board\BoardRow $row */
/** @var string $detail */
/** @var bool $check */
/** @var string $from where to return to after acting: 'home' or '' (this quake's page) */
?>
<div class="person">
    <div class="person-name"><?= $this->e($row->name) ?> <span class="person-meta"><?= $this->e($row->department) ?></span></div>
    <div class="person-meta"><?= $this->e($row->places) ?></div>
    <div class="person-meta"><?= $this->e($detail) ?><?php if ($row->foldedInto !== null): ?> （<?= $this->e($row->foldedInto) ?> の連絡に含めた）<?php endif ?></div>
<?php foreach ($row->autoReplies as $autoReply): ?>
    <p class="person-body muted">自動応答：「<?= nl2br($this->e($autoReply)) ?>」</p>
<?php endforeach ?>
<?php foreach ($row->bodies as $body): ?>
    <p class="person-body">「<?= nl2br($this->e($body)) ?>」</p>
<?php endforeach ?>
<?php if ($row->proxy !== null): ?>
    <p class="person-body muted"><?= $this->e($row->proxy) ?></p>
<?php endif ?>
<?php if ($check): ?>
    <form method="post" action="<?= $this->e($this->url('/contacts/' . $row->contactId . '/check')) ?>"><?= $this->csrf() ?><?php if ($from === 'home'): ?><input type="hidden" name="from" value="home"><?php endif ?><button type="submit">確認済みにする</button></form>
<?php endif ?>
    <p class="person-body muted"><a href="<?= $this->e($this->url('/contacts/' . $row->contactId . '/proxy' . ($from === 'home' ? '?from=home' : ''))) ?>">代理で記録</a></p>
</div>

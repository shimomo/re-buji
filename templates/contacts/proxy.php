<?php
/** @var League\Plates\Template\Template $this */
/** @var string $title */
/** @var array{id: int, name: string, email: string} $admin */
/** @var ReBuji\Quakes\Notification $contact */
/** @var string $record */
/** @var ReBuji\Quakes\Earthquake|null $quake */
/** @var list<string> $errors */
/** @var array{answer: string, note: string} $values */
/** @var string $from 'home' or '' */
$this->layout('layout', ['title' => $title]);
?>
<?php $this->start('nav') ?><?= $this->insert('nav', ['admin' => $admin, 'current' => 'more']) ?><?php $this->stop() ?>
<?php if ($errors !== []): ?>
<ul class="error">
<?php foreach ($errors as $error): ?>
    <li><?= $this->e($error) ?></li>
<?php endforeach ?>
</ul>
<?php endif ?>
<p><?= $this->e($contact->employeeName) ?> さん（<?= $this->e($quake?->title ?? '') ?>）</p>
<p><?= $this->e($record) ?></p>
<p class="muted">電話などで確かめた状態を、本人の代わりに記録します。記録したのが誰かも残ります。記録すると、まだ送っていない連絡と再送は止まります。</p>
<form method="post" action="<?= $this->e($this->url('/contacts/' . $contact->id . '/proxy')) ?>">
    <?= $this->csrf() ?>
<?php if ($from === 'home'): ?>
    <input type="hidden" name="from" value="home">
<?php endif ?>
    <label><input type="radio" name="answer" value="safe" style="width:auto"<?= $values['answer'] === 'safe' ? ' checked' : '' ?>> 無事</label>
    <label><input type="radio" name="answer" value="needs_check" style="width:auto"<?= $values['answer'] === 'needs_check' ? ' checked' : '' ?>> 要確認</label>
    <label>メモ（けが、出社できないなどの事情） <textarea name="note" rows="4" maxlength="2000"><?= $this->e($values['note']) ?></textarea></label>
    <button type="submit">記録する</button>
</form>
<?php if ($from === 'home'): ?>
<p><a href="<?= $this->e($this->url('/')) ?>">ホームに戻る</a></p>
<?php else: ?>
<p><a href="<?= $this->e($this->url('/earthquakes/' . $contact->earthquakeId)) ?>">地震の画面に戻る</a></p>
<?php endif ?>

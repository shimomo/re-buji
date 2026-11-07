<?php
/** @var League\Plates\Template\Template $this */
/** @var string $title */
/** @var array{id: int, name: string, email: string} $admin */
/** @var list<array{id: int, name: string, department: string}> $people */
/** @var list<string> $errors */
$this->layout('layout', ['title' => $title]);
?>
<?php $this->start('nav') ?><?= $this->insert('nav', ['admin' => $admin]) ?><?php $this->stop() ?>
<?php if ($errors !== []): ?>
<ul class="error">
<?php foreach ($errors as $error): ?>
    <li><?= $this->e($error) ?></li>
<?php endforeach ?>
</ul>
<?php endif ?>
<p>訓練の連絡を送ります。件名に【訓練】が付き、本文の先頭に「これは訓練です。」と入ります。返信や再送は本番と同じで、結果は「地震」の一覧に訓練として出ます。停止中の人には送りません。</p>
<?php if ($people === []): ?>
<p>送れる従業員がいません。<a href="<?= $this->e($this->url('/employees/new')) ?>">従業員を追加する</a></p>
<?php else: ?>
<form method="post" action="<?= $this->e($this->url('/drills')) ?>">
    <?= $this->csrf() ?>
    <fieldset>
        <legend>送る人</legend>
        <label><input type="radio" name="target" value="all" checked style="width:auto"> 全員（<?= count($people) ?> 人）</label>
        <label><input type="radio" name="target" value="chosen" style="width:auto"> 下で選んだ人</label>
<?php foreach ($people as $person): ?>
        <label><input type="checkbox" name="employees[]" value="<?= $person['id'] ?>" style="width:auto"> <?= $this->e($person['name']) ?><?= $person['department'] === '' ? '' : '（' . $this->e($person['department']) . '）' ?></label>
<?php endforeach ?>
    </fieldset>
    <button type="submit">訓練の連絡を送る</button>
</form>
<?php endif ?>

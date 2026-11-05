<?php
/** @var League\Plates\Template\Template $this */
/** @var string $title */
/** @var list<array{key: string, title: string}> $steps */
/** @var string $step */
/** @var list<string> $errors */
/** @var string $baseUrl */
/** @var list<string> $paths */
$this->layout('layout', ['title' => $title]);
?>
<?= $this->insert('install/progress', ['steps' => $steps, 'step' => $step]) ?>
<?= $this->insert('install/errors', ['errors' => $errors]) ?>
<p>データベースや設定ファイルがウェブから読めないことを確かめます。このアプリの URL から、次のファイルを開いてみます。</p>
<ul class="muted">
<?php foreach ($paths as $path): ?>
    <li><code><?= $this->e($path) ?></code></li>
<?php endforeach ?>
</ul>
<form method="post" action="<?= $this->e($this->url('/install/exposure')) ?>">
    <?= $this->csrf() ?>
    <label>このアプリの URL（ホスト名は変えられません） <input name="base_url" value="<?= $this->e($baseUrl) ?>" required></label>
    <label><input type="checkbox" name="checked_by_hand" value="1"> ブラウザで確かめた（自分の URL に接続できない、または届かないと言われたときだけ）</label>
    <button type="submit">確かめる</button>
</form>

<?php
/** @var League\Plates\Template\Template $this */
/** @var string $title */
/** @var list<array{key: string, title: string}> $steps */
/** @var string $step */
/** @var list<string> $errors */
$this->layout('layout', ['title' => $title]);
?>
<?= $this->insert('install/progress', ['steps' => $steps, 'step' => $step]) ?>
<?= $this->insert('install/errors', ['errors' => $errors]) ?>
<p>この画面を開いたことで、サーバーに <code>storage/install-key.txt</code> ができました。FTP でそのファイルを開き、中の文字列を貼ってください。ファイルを開けるのは、このサーバーに FTP で入れる人だけです。</p>
<form method="post" action="<?= $this->e($this->url('/install/key')) ?>">
    <?= $this->csrf() ?>
    <label>設置の鍵 <input name="install_key" autocomplete="off" required></label>
    <button type="submit">次へ</button>
</form>

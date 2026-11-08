<?php
/** @var League\Plates\Template\Template $this */
/** @var string $title */
/** @var list<array{key: string, title: string}> $steps */
/** @var string $step */
/** @var list<string> $errors */
/** @var string $to */
$this->layout('layout', ['title' => $title]);
?>
<?= $this->insert('install/progress', ['steps' => $steps, 'step' => $step]) ?>
<?= $this->insert('install/errors', ['errors' => $errors]) ?>
<p>テストメールを <strong><?= $this->e($to) ?></strong> に送りました。届いたメールに、そのまま返信してください（本文は空で構いません）。</p>
<p>返信したら、下のボタンを押してください。返信を読めれば、次へ進みます。ここを通らないと、初期設定を終えられません。</p>
<form method="post" action="<?= $this->e($this->url('/install/reply')) ?>">
    <?= $this->csrf() ?>
    <button type="submit">返信を確かめる</button>
</form>
<p class="muted">テストメールが届かないときは、<a href="<?= $this->e($this->url('/install/mail')) ?>">メールの設定に戻って</a>確かめてください。迷惑メールのフォルダーに入っていることもあります。</p>

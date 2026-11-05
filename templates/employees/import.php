<?php
/** @var League\Plates\Template\Template $this */
/** @var string $title */
/** @var array{id: int, name: string, email: string} $admin */
/** @var list<string> $errors */
/** @var list<string> $header */
$this->layout('layout', ['title' => $title]);
?>
<?php $this->start('nav') ?><?= $this->insert('nav', ['admin' => $admin]) ?><?php $this->stop() ?>
<?php if ($errors !== []): ?>
<div class="error">
    <p>次の点を直してから、もう一度取り込んでください。1 行でも間違いがあると、何も取り込みません。</p>
    <ul>
<?php foreach ($errors as $error): ?>
        <li><?= $this->e($error) ?></li>
<?php endforeach ?>
    </ul>
</div>
<?php endif ?>
<p>1 行目は次の見出しにします。<a href="<?= $this->e($this->url('/employees/export')) ?>">いまの一覧を書き出す</a>と、この形のファイルができます。</p>
<p><code><?= $this->e(implode(',', $header)) ?></code></p>
<ul class="muted">
    <li>ID が空の行は新しい人として登録し、ID がある行はその人の登録を書き換えます。ファイルに無い人は消えません。</li>
    <li>市町村は気象庁の 7 桁のコードで書きます（<a href="<?= $this->e($this->url('/employees/cities.csv')) ?>">市町村コードの一覧</a>）。政令指定都市は区のコードです。</li>
    <li>メールは 1〜4 件、市町村は 1〜5 件。状態は「有効」か「停止」（空なら有効）。</li>
    <li>Excel で保存したファイルをそのまま使えます（Shift_JIS でも UTF-8 でもかまいません。コードの先頭の 0 が消えていても直します）。</li>
</ul>
<form method="post" action="<?= $this->e($this->url('/employees/import')) ?>" enctype="multipart/form-data">
    <?= $this->csrf() ?>
    <label>CSV ファイル <input type="file" name="file" accept=".csv,text/csv"></label>
    <button type="submit">取り込む</button>
</form>
<p><a href="<?= $this->e($this->url('/employees')) ?>">一覧へ戻る</a></p>

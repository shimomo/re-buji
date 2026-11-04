<?php
/** @var League\Plates\Template\Template $this */
/** @var string $title */
/** @var list<array{key: string, title: string}> $steps */
/** @var string $step */
/** @var list<string> $errors */
/** @var bool $exists */
/** @var array<string, mixed> $values */
$this->layout('layout', ['title' => $title]);
$value = fn(string $key): string => is_string($values[$key] ?? null) ? (string) $values[$key] : '';
?>
<?= $this->insert('install/progress', ['steps' => $steps, 'step' => $step]) ?>
<?= $this->insert('install/errors', ['errors' => $errors]) ?>
<form method="post" action="<?= $this->e($this->url('/install/admin')) ?>">
    <?= $this->csrf() ?>
<?php if ($exists): ?>
    <p>管理者はすでに作成されています。</p>
<?php else: ?>
    <label>名前 <input name="name" value="<?= $this->e($value('name')) ?>" required></label>
    <label>メールアドレス（ログインに使います） <input type="email" name="email" value="<?= $this->e($value('email')) ?>" required></label>
    <label>パスワード（12 文字以上） <input type="password" name="password" autocomplete="new-password" required></label>
    <label>パスワード（確認） <input type="password" name="password_confirmation" autocomplete="new-password" required></label>
<?php endif ?>
    <button type="submit">次へ</button>
</form>

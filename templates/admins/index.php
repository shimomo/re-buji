<?php
/** @var League\Plates\Template\Template $this */
/** @var string $title */
/** @var array{id: int, name: string, email: string} $admin */
/** @var int $currentId */
/** @var list<array{id: int, name: string, email: string, created: string}> $admins */
/** @var list<string> $errors */
/** @var array{name: string, email: string} $values */
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
<p class="muted">管理者は全員が同じことをできます（従業員の登録、地震の確認、設定）。</p>
<table>
    <thead><tr><th>名前</th><th>メールアドレス</th><th>追加した日</th><th></th></tr></thead>
    <tbody>
<?php foreach ($admins as $row): ?>
        <tr>
            <td><?= $this->e($row['name']) ?></td>
            <td><?= $this->e($row['email']) ?></td>
            <td><?= $this->e($row['created']) ?></td>
            <td>
<?php if ($row['id'] === $currentId): ?>
                <span class="muted">（あなた）</span>
<?php else: ?>
                <form method="post" action="<?= $this->e($this->url('/admins/' . $row['id'] . '/delete')) ?>" data-confirm="<?= $this->e($row['name'] . ' さんを管理者から外しますか？') ?>" onsubmit="return confirm(this.dataset.confirm)">
                    <?= $this->csrf() ?>
                    <button type="submit">外す</button>
                </form>
<?php endif ?>
            </td>
        </tr>
<?php endforeach ?>
    </tbody>
</table>
<h2>管理者を加える</h2>
<form method="post" action="<?= $this->e($this->url('/admins')) ?>">
    <?= $this->csrf() ?>
    <label>名前 <input name="name" value="<?= $this->e($values['name']) ?>" maxlength="100" required></label>
    <label>メールアドレス（ログインに使います） <input type="email" name="email" value="<?= $this->e($values['email']) ?>" required></label>
    <label>パスワード（12 文字以上。加えた人に別の方法で伝えてください） <input type="password" name="password" autocomplete="new-password" required></label>
    <label>パスワード（確認） <input type="password" name="password_confirmation" autocomplete="new-password" required></label>
    <button type="submit">加える</button>
</form>

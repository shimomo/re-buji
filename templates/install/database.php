<?php
/** @var League\Plates\Template\Template $this */
/** @var string $title */
/** @var list<array{key: string, title: string}> $steps */
/** @var string $step */
/** @var list<string> $errors */
/** @var bool $sqlite */
/** @var bool $mysql */
/** @var array<string, mixed> $values */
$this->layout('layout', ['title' => $title]);
$value = fn(string $key, string $default = ''): string => is_string($values[$key] ?? null) ? (string) $values[$key] : $default;
?>
<?= $this->insert('install/progress', ['steps' => $steps, 'step' => $step]) ?>
<?= $this->insert('install/errors', ['errors' => $errors]) ?>
<form method="post" action="<?= $this->e($this->url('/install/database')) ?>">
    <?= $this->csrf() ?>
    <label><input type="radio" name="driver" value="sqlite" <?= $value('driver', 'sqlite') === 'sqlite' ? 'checked' : '' ?> <?= $sqlite ? '' : 'disabled' ?>> SQLite（MySQL が無いサーバー向け。storage/database.sqlite に作ります）</label>
    <label><input type="radio" name="driver" value="mysql" <?= $value('driver') === 'mysql' ? 'checked' : '' ?> <?= $mysql ? '' : 'disabled' ?>> MySQL</label>
    <fieldset>
        <legend>MySQL を選んだとき</legend>
        <label>ホスト <input name="mysql_host" value="<?= $this->e($value('mysql_host')) ?>"></label>
        <label>ポート <input name="mysql_port" value="<?= $this->e($value('mysql_port', '3306')) ?>"></label>
        <label>データベース名 <input name="mysql_database" value="<?= $this->e($value('mysql_database')) ?>"></label>
        <label>ユーザー名 <input name="mysql_username" value="<?= $this->e($value('mysql_username')) ?>"></label>
        <label>パスワード <input type="password" name="mysql_password" autocomplete="off"></label>
    </fieldset>
    <button type="submit">接続して次へ</button>
</form>

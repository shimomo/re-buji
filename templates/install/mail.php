<?php
/** @var League\Plates\Template\Template $this */
/** @var string $title */
/** @var list<array{key: string, title: string}> $steps */
/** @var string $step */
/** @var list<string> $errors */
/** @var array<string, string> $values */
$this->layout('layout', ['title' => $title]);
$value = fn(string $key): string => $values[$key] ?? '';
$thresholds = ['4' => '震度4', '5-' => '震度5弱', '5+' => '震度5強', '6-' => '震度6弱', '6+' => '震度6強', '7' => '震度7'];
?>
<?= $this->insert('install/progress', ['steps' => $steps, 'step' => $step]) ?>
<?= $this->insert('install/errors', ['errors' => $errors]) ?>
<p>連絡を送るメールのアカウントと、返信を受けるメールボックスを登録します。返信を受けるのは、送信元のアドレスと同じアカウントにしてください。保存すると、最初の管理者にテストメールを送ります。</p>
<form method="post" action="<?= $this->e($this->url('/install/mail')) ?>">
    <?= $this->csrf() ?>
    <fieldset>
        <legend>送信（SMTP）</legend>
        <label>SMTP のサーバー名 <input name="smtp_host" value="<?= $this->e($value('smtp_host')) ?>" required></label>
        <div class="row">
            <label>ポート番号 <input name="smtp_port" inputmode="numeric" value="<?= $this->e($value('smtp_port')) ?>"></label>
            <label>暗号化
                <select name="smtp_encryption">
                    <option value="starttls"<?= $value('smtp_encryption') === 'starttls' ? ' selected' : '' ?>>STARTTLS（ふつうは 587 番）</option>
                    <option value="ssl"<?= $value('smtp_encryption') === 'ssl' ? ' selected' : '' ?>>SSL/TLS（ふつうは 465 番）</option>
                </select>
            </label>
        </div>
        <label>ユーザー名 <input name="smtp_username" value="<?= $this->e($value('smtp_username')) ?>" autocomplete="off"></label>
        <label>パスワード <input type="password" name="smtp_password" value="" autocomplete="new-password"></label>
        <label>送信元のアドレス（返信はここに届きます） <input type="text" inputmode="email" name="mail_from_address" value="<?= $this->e($value('mail_from_address')) ?>" required></label>
        <label>送信元の名前 <input name="mail_from_name" value="<?= $this->e($value('mail_from_name')) ?>" maxlength="50"></label>
    </fieldset>
    <fieldset>
        <legend>受信（IMAP）</legend>
        <?= $this->insert('mail/dedicated') ?>
        <label>IMAP のサーバー名 <input name="imap_host" value="<?= $this->e($value('imap_host')) ?>" required></label>
        <div class="row">
            <label>ポート番号 <input name="imap_port" inputmode="numeric" value="<?= $this->e($value('imap_port')) ?>"></label>
            <label>暗号化
                <select name="imap_encryption">
                    <option value="ssl"<?= $value('imap_encryption') === 'ssl' ? ' selected' : '' ?>>SSL/TLS（ふつうは 993 番）</option>
                    <option value="starttls"<?= $value('imap_encryption') === 'starttls' ? ' selected' : '' ?>>STARTTLS（ふつうは 143 番）</option>
                </select>
            </label>
        </div>
        <label>ユーザー名（空なら送信と同じ） <input name="imap_username" value="<?= $this->e($value('imap_username')) ?>" autocomplete="off"></label>
        <label>パスワード（空なら送信と同じ） <input type="password" name="imap_password" value="" autocomplete="new-password"></label>
    </fieldset>
    <fieldset>
        <legend>送り方</legend>
        <label>1 時間に送ってよい通数（ロリポップ エコノミー 100、ライト 300、スタンダード以上 1,000、さくら 100、エックスサーバー 1,500） <input name="hourly_limit" inputmode="numeric" value="<?= $this->e($value('hourly_limit')) ?>"></label>
        <label>しきい値（登録した市町村がこの震度以上になった人に連絡します）
            <select name="threshold">
<?php foreach ($thresholds as $code => $label): ?>
                <option value="<?= $this->e((string) $code) ?>"<?= (string) $code === $value('threshold') ? ' selected' : '' ?>><?= $this->e($label) ?></option>
<?php endforeach ?>
            </select>
        </label>
    </fieldset>
    <button type="submit">保存してテストメールを送る</button>
</form>

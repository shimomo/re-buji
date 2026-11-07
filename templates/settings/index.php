<?php
/** @var League\Plates\Template\Template $this */
/** @var string $title */
/** @var array{id: int, name: string, email: string} $admin */
/** @var array<string, string> $values */
/** @var list<string> $errors */
/** @var list<array{value: string, label: string}> $thresholds */
/** @var bool $hasPassword */
/** @var list<string> $placeholders */
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
<form method="post" action="<?= $this->e($this->url('/settings')) ?>">
    <?= $this->csrf() ?>
    <fieldset>
        <legend>連絡する条件</legend>
        <label>しきい値（登録した市町村のどれかがこの震度以上になった人に連絡します）
            <select name="threshold">
<?php foreach ($thresholds as $threshold): ?>
                <option value="<?= $this->e($threshold['value']) ?>"<?= $threshold['value'] === $values['threshold'] ? ' selected' : '' ?>><?= $this->e($threshold['label']) ?></option>
<?php endforeach ?>
            </select>
        </label>
        <label>送るまでの待ち時間（分。0 なら、気象庁の情報が届いた cron ですぐ送ります） <input name="delay_minutes" inputmode="numeric" value="<?= $this->e($values['delay_minutes']) ?>"></label>
        <label>遅れて届いた地震とみなす時間（時間。地震からこれより後に届いた情報では自動で送らず、ホームで送るかを決めます） <input name="late_after_hours" inputmode="numeric" value="<?= $this->e($values['late_after_hours']) ?>"></label>
    </fieldset>
    <fieldset>
        <legend>再送（返信の無い人に送り直します。件名に【再送】が付きます）</legend>
        <label>回数 <input name="resend_count" inputmode="numeric" value="<?= $this->e($values['resend_count']) ?>"></label>
        <label>間隔（分） <input name="resend_interval_minutes" inputmode="numeric" value="<?= $this->e($values['resend_interval_minutes']) ?>"></label>
    </fieldset>
    <fieldset>
        <legend>送信の上限</legend>
        <label>1 時間に送ってよい通数（1 人のアドレスが 2 件なら 2 通と数えます） <input name="hourly_limit" inputmode="numeric" value="<?= $this->e($values['hourly_limit']) ?>"></label>
        <p class="muted">サーバーごとの目安（2026 年 11 月の各社の案内から）：</p>
        <table>
            <thead><tr><th>サーバー</th><th>上限の目安</th></tr></thead>
            <tbody>
                <tr><td>ロリポップ エコノミー</td><td>100</td></tr>
                <tr><td>ロリポップ ライト</td><td>300</td></tr>
                <tr><td>ロリポップ スタンダード以上</td><td>1,000</td></tr>
                <tr><td>さくらのレンタルサーバ</td><td>100（15 分に 100 通程度のため）</td></tr>
                <tr><td>エックスサーバー</td><td>1,500</td></tr>
                <tr><td>ConoHa WING</td><td>サーバーの案内を確かめてください</td></tr>
            </tbody>
        </table>
    </fieldset>
    <fieldset>
        <legend>メールの文面</legend>
        <p class="muted">差し込み：<?= $this->e(implode('、', $placeholders)) ?>。件名の先頭には返信を照合する番号（[安否確認 #…]）が、再送と訓練には【再送】【訓練】が自動で付きます。</p>
        <label>件名 <input name="mail_subject" value="<?= $this->e($values['mail_subject']) ?>" maxlength="100"></label>
        <label>本文 <textarea name="mail_body" rows="14"><?= $this->e($values['mail_body']) ?></textarea></label>
    </fieldset>
    <fieldset>
        <legend>メールのアカウント（返信を受けるアドレスと同じアカウントにしてください）</legend>
        <label>SMTP のサーバー名 <input name="smtp_host" value="<?= $this->e($values['smtp_host']) ?>" autocomplete="off"></label>
        <div class="row">
            <label>ポート番号 <input name="smtp_port" inputmode="numeric" value="<?= $this->e($values['smtp_port']) ?>"></label>
            <label>暗号化
                <select name="smtp_encryption">
                    <option value="starttls"<?= $values['smtp_encryption'] === 'starttls' ? ' selected' : '' ?>>STARTTLS（ふつうは 587 番）</option>
                    <option value="ssl"<?= $values['smtp_encryption'] === 'ssl' ? ' selected' : '' ?>>SSL/TLS（ふつうは 465 番）</option>
                </select>
            </label>
        </div>
        <label>ユーザー名 <input name="smtp_username" value="<?= $this->e($values['smtp_username']) ?>" autocomplete="off"></label>
        <label>パスワード<?= $hasPassword ? '（保存してあります。変えるときだけ入れてください）' : '' ?> <input type="password" name="smtp_password" value="" autocomplete="new-password"></label>
        <label>送信元のアドレス <input type="text" inputmode="email" name="mail_from_address" value="<?= $this->e($values['mail_from_address']) ?>" autocomplete="off"></label>
        <label>送信元の名前 <input name="mail_from_name" value="<?= $this->e($values['mail_from_name']) ?>" maxlength="50"></label>
    </fieldset>
    <button type="submit">保存する</button>
</form>
<h2>テストメール</h2>
<p>保存した設定で、あなた（<?= $this->e($admin['email']) ?>）にテストメールを送ります。1 時間の上限に数えます。</p>
<form method="post" action="<?= $this->e($this->url('/settings/test-mail')) ?>">
    <?= $this->csrf() ?>
    <button type="submit">テストメールを送る</button>
</form>

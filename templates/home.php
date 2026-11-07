<?php
/** @var League\Plates\Template\Template $this */
/** @var string $title */
/** @var array{id: int, name: string, email: string} $admin */
/** @var string|null $lastCheck */
/** @var bool $stale */
/** @var string|null $lastError */
/** @var string $statusUrl */
/** @var array{all: int, active: int} $employeeCounts */
/** @var list<array{employee_id: int, name: string, code: string}> $unknownCities */
/** @var int $heldQuakes */
/** @var int $unmatchedMails */
/** @var bool $smtpMissing */
/** @var string $mailError */
/** @var int $waiting */
/** @var string|null $nextSlot */
/** @var string|null $feedGap */
/** @var list<array{title: string, at: string}> $failedTelegrams */
$this->layout('layout', ['title' => $title]);
?>
<?php $this->start('nav') ?><?= $this->insert('nav', ['admin' => $admin]) ?><?php $this->stop() ?>
<?php if ($heldQuakes > 0): ?>
<p class="error">遅れて届いた地震が <?= $heldQuakes ?> 件あります。送るかどうかを決めてください。<a href="<?= $this->e($this->url('/earthquakes')) ?>">地震の一覧</a></p>
<?php endif ?>
<?php if ($unmatchedMails > 0): ?>
<p class="warning">照合できなかったメールが <?= $unmatchedMails ?> 通あります。<a href="<?= $this->e($this->url('/mail/unmatched')) ?>">一覧</a></p>
<?php endif ?>
<?php if ($smtpMissing): ?>
<p class="error">メールの送信の設定がありません。「設定」で SMTP を登録してください。<a href="<?= $this->e($this->url('/settings')) ?>">設定</a></p>
<?php endif ?>
<?php if ($mailError !== ''): ?>
<p class="error">メールを送れていません：<?= $this->e($mailError) ?></p>
<?php endif ?>
<?php if ($waiting > 0 && $nextSlot !== null): ?>
<p class="warning">1 時間の上限のため、<?= $waiting ?> 人への連絡が送信待ちです。次に送れるのは <?= $this->e($nextSlot) ?> ごろです。</p>
<?php elseif ($waiting > 0): ?>
<p class="warning">送信待ちの連絡が <?= $waiting ?> 件あります。次の cron で送ります。</p>
<?php endif ?>
<?php if ($feedGap !== null || $failedTelegrams !== []): ?>
<?php if ($feedGap !== null): ?>
<p class="error">気象庁の情報に抜けがあります（<?= $this->e($feedGap) ?> 以降）。この間の地震は連絡されていない可能性があります。</p>
<?php endif ?>
<?php foreach ($failedTelegrams as $failed): ?>
<p class="warning">取れなかった電文があります：<?= $this->e($failed['title']) ?>（<?= $this->e($failed['at']) ?>）</p>
<?php endforeach ?>
<form method="post" action="<?= $this->e($this->url('/feed/acknowledge')) ?>">
    <?= $this->csrf() ?>
    <button type="submit">確かめた（この警告を消す）</button>
</form>
<?php endif ?>
<?= $this->insert('employees/unknown-cities', ['unknownCities' => $unknownCities]) ?>
<h2>従業員</h2>
<?php if ($employeeCounts['all'] === 0): ?>
<p>従業員がまだ登録されていません。<a href="<?= $this->e($this->url('/employees/new')) ?>">従業員を追加する</a></p>
<?php else: ?>
<p>従業員：<?= $employeeCounts['all'] ?> 人（停止中 <?= $employeeCounts['all'] - $employeeCounts['active'] ?> 人）　<a href="<?= $this->e($this->url('/employees')) ?>">一覧</a></p>
<?php endif ?>
<h2>気象庁の情報</h2>
<?php if ($lastCheck === null): ?>
<p class="error">まだ一度も気象庁の情報を確かめていません。サーバーの管理画面で bin/cron.php を登録したか確かめてください。</p>
<?php elseif ($stale): ?>
<p class="error">15 分以上、気象庁の情報を確かめていません（最後は <?= $this->e($lastCheck) ?>）。地震があっても連絡が送られません。cron の設定と、前回の失敗の理由を確かめてください。</p>
<?php else: ?>
<p>最後に気象庁の情報を確かめたのは <?= $this->e($lastCheck) ?> です。</p>
<?php endif ?>
<?php if ($lastError !== null): ?>
<p class="warning">前回の cron で失敗した処理があります：<?= $this->e($lastError) ?></p>
<?php endif ?>
<h2>見張り（状態の URL）</h2>
<p>外部の監視サービスに、次の URL を登録してください。cron が止まると 503 を返します。</p>
<p><code><?= $this->e($statusUrl) ?></code></p>
<form method="post" action="<?= $this->e($this->url('/settings/status-token')) ?>">
    <?= $this->csrf() ?>
    <button type="submit">URL を作り直す（古い URL は使えなくなります）</button>
</form>

<?php
/** @var League\Plates\Template\Template $this */
/** @var string $title */
/** @var array{id: int, name: string, email: string} $admin */
/** @var string|null $lastRun */
/** @var bool $stale */
/** @var string|null $lastError */
/** @var string $statusUrl */
$this->layout('layout', ['title' => $title]);
?>
<?php $this->start('nav') ?><?= $this->insert('nav', ['admin' => $admin]) ?><?php $this->stop() ?>
<h2>cron</h2>
<?php if ($lastRun === null): ?>
<p class="error">cron が一度も動いていません。サーバーの管理画面で bin/cron.php を登録したか確かめてください。</p>
<?php elseif ($stale): ?>
<p class="error">15 分以上 cron が動いていません（最後に動いたのは <?= $this->e($lastRun) ?>）。地震があっても連絡が送られません。サーバーの cron の設定を確かめてください。</p>
<?php else: ?>
<p>最後に動いたのは <?= $this->e($lastRun) ?> です。</p>
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

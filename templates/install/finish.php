<?php
/** @var League\Plates\Template\Template $this */
/** @var string $title */
/** @var list<array{key: string, title: string}> $steps */
/** @var string $step */
/** @var list<string> $errors */
/** @var string $cronPath */
/** @var string $statusUrl */
$this->layout('layout', ['title' => $title]);
?>
<?= $this->insert('install/progress', ['steps' => $steps, 'step' => $step]) ?>
<?= $this->insert('install/errors', ['errors' => $errors]) ?>
<p>完了する前に、次の 2 つを控えてください。完了すると、この画面は開けなくなります。</p>
<h2>1. cron</h2>
<p>サーバーの管理画面で、次の PHP ファイルを 5 分ごと（できれば 1 分ごと）に動かす cron を登録してください。</p>
<p><code><?= $this->e($cronPath) ?></code></p>
<p class="muted">SSH が使えるサーバーなら <code>*/5 * * * * php <?= $this->e($cronPath) ?></code> のように登録します。</p>
<h2>2. 止まっていないかの見張り</h2>
<p>次の URL を、UptimeRobot などの無料の監視サービスに登録してください。cron が 15 分以上動いていないと、この URL はエラー（503）を返すので、監視サービスからメールが届きます。</p>
<p><code><?= $this->e($statusUrl) ?></code></p>
<p class="muted">この URL は、ログインした後のホーム画面でも確かめられ、作り直すこともできます。</p>
<form method="post" action="<?= $this->e($this->url('/install/finish')) ?>">
    <?= $this->csrf() ?>
    <button type="submit">初期設定を完了する</button>
</form>

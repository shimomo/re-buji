<?php
/** @var League\Plates\Template\Template $this */
/** @var array{id: int, name: string, email: string} $admin */
/** @var string $current home|earthquakes|unmatched|employees|more (the phone tab) */
/** @var string $page finer key for the PC row: drills|admins|settings|database; defaults to $current */
$page ??= $current;
$badges = $this->navBadges();
$tabs = [
    ['home', '/', '⌂', 'ホーム', 0],
    ['earthquakes', '/earthquakes', '◎', '地震', $badges['held']],
    ['unmatched', '/mail/unmatched', '✉', '照合', $badges['unmatched']],
    ['employees', '/employees', '☺', '従業員', 0],
    ['more', '/more', '☰', 'その他', 0],
];
$row = [
    ['home', '/', 'ホーム', 0],
    ['earthquakes', '/earthquakes', '地震', $badges['held']],
    ['drills', '/drills/new', '訓練', 0],
    ['unmatched', '/mail/unmatched', '照合できなかったメール', $badges['unmatched']],
    ['employees', '/employees', '従業員', 0],
    ['admins', '/admins', '管理者', 0],
    ['settings', '/settings', '設定', 0],
    ['database', '/admin/database', 'DB の更新', 0],
];
?>
<span class="who muted"><?= $this->e($admin['name']) ?></span>
<nav class="nav" aria-label="メニュー">
<?php foreach ($tabs as [$key, $path, $mark, $label, $count]): ?>
    <a class="tab" href="<?= $this->e($this->url($path)) ?>"<?= $current === $key ? ' aria-current="page"' : '' ?>><span class="tab-mark" aria-hidden="true"><?= $mark ?></span><span class="tab-name"><?= $label ?></span><?php if ($count > 0): ?><span class="badge"><?= $count ?></span><?php endif ?></a>
<?php endforeach ?>
<?php foreach ($row as [$key, $path, $label, $count]): ?>
    <a class="pc-link" href="<?= $this->e($this->url($path)) ?>"<?= $page === $key ? ' aria-current="page"' : '' ?>><?= $label ?><?php if ($count > 0): ?><span class="badge"><?= $count ?></span><?php endif ?></a>
<?php endforeach ?>
    <form class="pc-only" method="post" action="<?= $this->e($this->url('/logout')) ?>">
        <?= $this->csrf() ?>
        <button type="submit">ログアウト</button>
    </form>
</nav>

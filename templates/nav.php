<?php
/** @var League\Plates\Template\Template $this */
/** @var array{id: int, name: string, email: string} $admin */
/** @var string $current home|earthquakes|unmatched|employees|more */
$badges = $this->navBadges();
$tabs = [
    ['home', '/', '⌂', 'ホーム', 0],
    ['earthquakes', '/earthquakes', '◎', '地震', $badges['held']],
    ['unmatched', '/mail/unmatched', '✉', '照合', $badges['unmatched']],
    ['employees', '/employees', '☺', '従業員', 0],
    ['more', '/more', '☰', 'その他', 0],
];
$extras = [['/drills/new', '訓練'], ['/admins', '管理者'], ['/settings', '設定'], ['/admin/database', 'DB の更新']];
?>
<nav class="nav" aria-label="メニュー">
<?php foreach ($tabs as [$key, $path, $mark, $label, $count]): ?>
    <a class="tab<?= $key === 'more' ? ' tab-more' : '' ?>" href="<?= $this->e($this->url($path)) ?>"<?= $current === $key ? ' aria-current="page"' : '' ?>><span class="tab-mark" aria-hidden="true"><?= $mark ?></span><span class="tab-name"><?= $label ?></span><?php if ($count > 0): ?><span class="badge"><?= $count ?></span><?php endif ?></a>
<?php endforeach ?>
<?php foreach ($extras as [$path, $label]): ?>
    <a class="tab pc-only" href="<?= $this->e($this->url($path)) ?>"><span class="tab-name"><?= $label ?></span></a>
<?php endforeach ?>
    <span class="muted pc-only"><?= $this->e($admin['name']) ?></span>
    <form class="pc-only" method="post" action="<?= $this->e($this->url('/logout')) ?>">
        <?= $this->csrf() ?>
        <button type="submit">ログアウト</button>
    </form>
</nav>

<?php
/** @var League\Plates\Template\Template $this */
/** @var string $title */
/** @var array{id: int, name: string, email: string} $admin */
/** @var list<array{id: int, name: string, department: string, cities: list<string>, emails: int, active: bool}> $employees */
/** @var list<array{employee_id: int, name: string, code: string}> $unknownCities */
$this->layout('layout', ['title' => $title]);
?>
<?php $this->start('nav') ?><?= $this->insert('nav', ['admin' => $admin, 'current' => 'employees']) ?><?php $this->stop() ?>
<?= $this->insert('employees/unknown-cities', ['unknownCities' => $unknownCities]) ?>
<section class="card">
<p class="links">
    <a href="<?= $this->e($this->url('/employees/new')) ?>">従業員を追加する</a>
    <a href="<?= $this->e($this->url('/employees/import')) ?>">CSV を取り込む</a>
    <a href="<?= $this->e($this->url('/employees/export')) ?>">CSV に書き出す</a>
    <a href="<?= $this->e($this->url('/employees/cities.csv')) ?>">市町村コードの一覧</a>
</p>
<?php if ($employees === []): ?>
<p>従業員がまだ登録されていません。</p>
<?php else: ?>
<table class="stack">
    <thead><tr><th>名前</th><th>所属</th><th>市町村</th><th>メール</th><th>状態</th></tr></thead>
    <tbody>
<?php foreach ($employees as $employee): ?>
        <tr>
            <td data-label="名前"><a href="<?= $this->e($this->url('/employees/' . $employee['id'])) ?>"><?= $this->e($employee['name']) ?></a></td>
            <td data-label="所属"><?= $this->e($employee['department']) ?></td>
            <td data-label="市町村"><?= implode('<br>', array_map($this->e(...), $employee['cities'])) ?></td>
            <td data-label="メール"><?= $this->e((string) $employee['emails']) ?> 件</td>
            <td data-label="状態"><?= $employee['active'] ? '有効' : '<span class="muted">停止</span>' ?></td>
        </tr>
<?php endforeach ?>
    </tbody>
</table>
<?php endif ?>
</section>

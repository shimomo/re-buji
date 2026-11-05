<?php
/** @var League\Plates\Template\Template $this */
/** @var string $title */
/** @var array{id: int, name: string, email: string} $admin */
/** @var list<array{id: int, name: string, department: string, cities: list<string>, emails: int, active: bool}> $employees */
/** @var list<array{employee_id: int, name: string, code: string}> $unknownCities */
$this->layout('layout', ['title' => $title]);
?>
<?php $this->start('nav') ?><?= $this->insert('nav', ['admin' => $admin]) ?><?php $this->stop() ?>
<?= $this->insert('employees/unknown-cities', ['unknownCities' => $unknownCities]) ?>
<p><a href="<?= $this->e($this->url('/employees/new')) ?>">従業員を追加する</a></p>
<?php if ($employees === []): ?>
<p>従業員がまだ登録されていません。</p>
<?php else: ?>
<table>
    <thead><tr><th>名前</th><th>所属</th><th>市町村</th><th>メール</th><th>状態</th></tr></thead>
    <tbody>
<?php foreach ($employees as $employee): ?>
        <tr>
            <td><a href="<?= $this->e($this->url('/employees/' . $employee['id'])) ?>"><?= $this->e($employee['name']) ?></a></td>
            <td><?= $this->e($employee['department']) ?></td>
            <td><?= implode('<br>', array_map($this->e(...), $employee['cities'])) ?></td>
            <td><?= $this->e((string) $employee['emails']) ?> 件</td>
            <td><?= $employee['active'] ? '有効' : '<span class="muted">停止</span>' ?></td>
        </tr>
<?php endforeach ?>
    </tbody>
</table>
<?php endif ?>

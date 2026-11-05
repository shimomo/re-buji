<?php
/** @var League\Plates\Template\Template $this */
/** @var list<array{employee_id: int, name: string, code: string}> $unknownCities */
?>
<?php if ($unknownCities !== []): ?>
<div class="error">
    <p>判定に使えない市町村が登録されています。直さないと、地震があってもこの人たちに連絡が届きません。</p>
    <ul>
<?php foreach ($unknownCities as $unknown): ?>
        <li><a href="<?= $this->e($this->url('/employees/' . $unknown['employee_id'])) ?>"><?= $this->e($unknown['name']) ?></a>：市町村コード <?= $this->e($unknown['code']) ?></li>
<?php endforeach ?>
    </ul>
</div>
<?php endif ?>

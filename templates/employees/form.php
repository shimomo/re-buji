<?php
/** @var League\Plates\Template\Template $this */
/** @var string $title */
/** @var array{id: int, name: string, email: string} $admin */
/** @var list<string> $errors */
/** @var string $action */
/** @var ?int $id */
/** @var array{name: string, department: string, emails: list<string>, memo: string, active: bool} $values */
/** @var list<array{prefecture: string, code: string, label: string, cities: list<array{code: string, name: string}>}> $rows */
/** @var list<array{code: string, name: string}> $prefectures */
/** @var string $cityData */
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
<form method="post" action="<?= $this->e($this->url($action)) ?>">
    <?= $this->csrf() ?>
    <label>名前 <input name="name" value="<?= $this->e($values['name']) ?>" maxlength="100" required></label>
    <label>所属 <input name="department" value="<?= $this->e($values['department']) ?>" maxlength="100"></label>
    <fieldset>
        <legend>メールアドレス（1〜<?= count($values['emails']) ?> 件。全部に送ります）</legend>
<?php foreach ($values['emails'] as $i => $email): ?>
        <label>メール <?= $i + 1 ?> <input type="text" inputmode="email" name="emails[<?= $i ?>]" value="<?= $this->e($email) ?>" autocomplete="off"></label>
<?php endforeach ?>
    </fieldset>
    <fieldset>
        <legend>市町村（1〜<?= count($rows) ?> 件。どれかで基準の震度になれば連絡します）</legend>
        <noscript><p class="warning">市町村を選ぶには JavaScript が必要です。使えないときは、CSV の取り込みで登録してください。</p></noscript>
<?php foreach ($rows as $i => $row): ?>
        <div class="row">
            <label>都道府県
                <select name="cities[<?= $i ?>][prefecture]" data-cities="city-<?= $i ?>">
                    <option value="">選んでください</option>
<?php foreach ($prefectures as $prefecture): ?>
                    <option value="<?= $this->e($prefecture['code']) ?>"<?= $prefecture['code'] === $row['prefecture'] ? ' selected' : '' ?>><?= $this->e($prefecture['name']) ?></option>
<?php endforeach ?>
                </select>
            </label>
            <label>市町村
                <select name="cities[<?= $i ?>][code]" id="city-<?= $i ?>">
                    <option value="">選んでください</option>
<?php $known = false; ?>
<?php foreach ($row['cities'] as $city): ?>
<?php $known = $known || $city['code'] === $row['code']; ?>
                    <option value="<?= $this->e($city['code']) ?>"<?= $city['code'] === $row['code'] ? ' selected' : '' ?>><?= $this->e($city['name']) ?></option>
<?php endforeach ?>
<?php if ($row['code'] !== '' && !$known): ?>
                    <option value="<?= $this->e($row['code']) ?>" selected><?= $this->e($row['code']) ?>（判定に使えないコード）</option>
<?php endif ?>
                </select>
            </label>
            <label>呼び名（例：勤務地、自宅） <input name="cities[<?= $i ?>][label]" value="<?= $this->e($row['label']) ?>" maxlength="20"></label>
        </div>
<?php endforeach ?>
    </fieldset>
    <label>メモ <textarea name="memo" rows="3" maxlength="2000"><?= $this->e($values['memo']) ?></textarea></label>
    <label><input type="checkbox" name="active" value="1"<?= $values['active'] ? ' checked' : '' ?>> 有効（外すと連絡を送りません）</label>
    <button type="submit">保存する</button>
</form>
<?php if ($id !== null): ?>
<form method="post" action="<?= $this->e($this->url('/employees/' . $id . '/delete')) ?>" data-confirm="<?= $this->e($values['name'] . ' さんを削除しますか？') ?>" onsubmit="return confirm(this.dataset.confirm)">
    <?= $this->csrf() ?>
    <button type="submit">削除する</button>
</form>
<?php endif ?>
<p><a href="<?= $this->e($this->url('/employees')) ?>">一覧へ戻る</a></p>
<script type="application/json" id="rebuji-cities"><?= $cityData ?></script>
<script>
(function () {
    var byPrefecture = {};
    JSON.parse(document.getElementById('rebuji-cities').textContent).forEach(function (prefecture) {
        byPrefecture[prefecture.code] = prefecture.cities;
    });
    document.querySelectorAll('select[data-cities]').forEach(function (prefecture) {
        prefecture.addEventListener('change', function () {
            var select = document.getElementById(prefecture.getAttribute('data-cities'));
            select.length = 1;
            (byPrefecture[prefecture.value] || []).forEach(function (city) {
                select.add(new Option(city.name, city.code));
            });
        });
    });
})();
</script>

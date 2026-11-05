# Re:無事 計画 2：登録 実装計画

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 管理者が、従業員（名前、メールアドレス、所属、市町村、メモ、有効／停止）を画面と CSV で登録・編集・削除でき、管理者を足したり外したりできるようにする。

**Architecture:** 市町村は jmaxml/codes の表から引き、`EarthquakeCodes::city()` が `null` のコードは登録の時点で拒む。従業員は 3 つの表（本人、メールアドレス、市町村）に分けて持ち、画面と CSV は同じ入力（`EmployeeInput`）と同じ確かめ（`EmployeeValidator`）を通る。DB の更新を当てるまでは、管理画面と cron を止めて待つ。

**Tech Stack:** 計画 1 と同じ（Slim 4、PHP-DI、Plates、slim/csrf、PDO）に、`jmaxml/codes` ^0.1.1 と ext-mbstring を足す。

**Spec:** `docs/superpowers/specs/2026-11-05-re-buji-design.md`（3.1、3.2、6.4、6.5、7 章）

**前の計画：** `docs/superpowers/plans/2026-11-05-re-buji-foundation.md`（PR #1 でマージ済み）。計画 1 の台帳にあった判断 44（FTP で上書きしてから「DB の更新」を押すまでの間）は、最初の新しいマイグレーションを足すこの計画で扱う（Task 2）。

## Global Constraints

- 計画 1 の Global Constraints をすべて引き継ぐ（PHP `^8.3`、SQLite の PRAGMA、UTC の時刻の文字列、名前空間、`.htaccess`、`setUp` / `tearDown` を使わない、コミットメッセージの末尾）
- 市町村コードは気象庁の 7 桁（例 `1310100`）。比べるのは文字列の完全一致。`EarthquakeCodes::city()` が `null` を返すコードは保存しない（設計書 3.1）
- 1 人のメールアドレスは 1〜4 件（設計書 3.1）。市町村は 1〜5 件 **[判断]**：設計書は「複数可」とだけ言う。CSV の列と、JavaScript の無いフォームの行数を決めるために上限を置く。勤務地・自宅・家族の住む市町村などで足りる
- 1 つのメールアドレス（大文字小文字は区別しない）は 1 人だけが持てる **[判断]**：返事の照合（設計書 5.2 の 3）が、送り主のアドレスから人を決めるため
- 文字数：名前 1〜100、所属 100 まで、市町村の呼び名 20 まで、メモ 2,000 まで（`mb_strlen`）。メールアドレスは 254 文字まで
- `fgetcsv` / `fputcsv` には、`escape` に `''` を必ず渡す（PHP 8.4 で既定値に頼ると非推奨）
- 新しい依存を足したら `vendor/` のファイル数を確かめる（アプリ全体で 1,800 以下）

## Review Focus

- Excel で開いて保存した CSV は、市町村コードの先頭の 0 が落ちる（北海道〜福島の `0110100` が `110100` になる）→ 7 桁に戻して受け付ける（Task 5）
- Excel が Shift_JIS（CP932）で保存した CSV、とくに「髙」「﨑」のような機種依存文字 → 文字化けせずに取り込む（Task 5）
- 同じメールアドレスを 2 人に登録する（画面、または同じ CSV の 2 行）→ 誰が使っているか（何行目と同じか）を示して拒む（Task 3、Task 5）
- 登録したコードが、コード表を更新した版で判定に使えなくなる → ホームと従業員の一覧に、その人と市町村コードを出す（Task 4）
- 管理者が自分を削除しようとする → 拒む。管理者が 0 人になって誰もログインできなくなることを防ぐ（Task 6）

## ファイルの構成

```
src/Employees/            # 従業員：Cities、CityChoice、Employee、EmployeeCity、EmployeeInput、EmployeeValidator、
                          #         EmployeeRepository、EmployeeCsv、EmployeeImport、ImportResult、
                          #         EmployeesController、EmployeeCsvController
src/Admin/PendingMigrationsGate.php
src/Auth/AdminsController.php
src/Http/Flash.php
migrations/M0002CreateEmployeeTables.php
templates/employees/      # index.php、form.php、import.php
templates/admins/index.php
```

---

### Task 1：依存、市町村の表、従業員の表

**Files:**
- Modify: `composer.json`（`jmaxml/codes` ^0.1.1、`ext-mbstring` `*`）、`composer.lock`
- Create: `src/Employees/Cities.php`、`src/Employees/CityChoice.php`、`migrations/M0002CreateEmployeeTables.php`
- Modify: `src/Database/Migrations.php`（M0002 を足す）、`tests/Install/ExposureCheckTest.php`（`setUp` / `tearDown` をやめる。計画 1 の最終レビューの修正で入れてしまったもの）
- Test: `tests/Employees/CitiesTest.php`、`tests/Database/EmployeeTablesTest.php`

**Interfaces:**
- Produces: `final readonly class ReBuji\Employees\CityChoice`：`__construct(string $code, string $name, string $prefectureCode, string $prefectureName)`、`label(): string`（`'東京都 東京千代田区'`）
- Produces: `final class ReBuji\Employees\Cities`：
  - `static find(string $code): ?CityChoice`。`EarthquakeCodes::city()` が `null` なら `null`。都道府県は `EarthquakeCodes::area($city->areaCode)->prefectureCode` から引く
  - `static prefectures(): list<array{code: string, name: string}>`（47、コード順）
  - `static byPrefecture(): array<string, list<array{code: string, name: string}>>`（キーは都道府県コード、市町村はコード順）
  - `static normalizeCode(string $value): string`：前後の空白を除く。6 桁の数字なら先頭に `0` を足す。それ以外はそのまま返す
- Produces: 表（M0002、`version()` は `'0002'`、`description()` は `'従業員、メールアドレス、市町村'`。`$id` と `$table` は M0001 と同じ書き方。MySQL では `employee_id` を `INT UNSIGNED`）

```sql
CREATE TABLE employees (id {$id}, name VARCHAR(255) NOT NULL, department VARCHAR(255) NOT NULL, memo TEXT NOT NULL,
  active INTEGER NOT NULL, created_at VARCHAR(20) NOT NULL, updated_at VARCHAR(20) NOT NULL){$table}
CREATE TABLE employee_emails (id {$id}, employee_id {$ref} NOT NULL, position INTEGER NOT NULL,
  address VARCHAR(255) NOT NULL, address_key VARCHAR(255) NOT NULL UNIQUE,
  FOREIGN KEY (employee_id) REFERENCES employees (id) ON DELETE CASCADE){$table}
CREATE TABLE employee_cities (id {$id}, employee_id {$ref} NOT NULL, position INTEGER NOT NULL,
  city_code VARCHAR(7) NOT NULL, label VARCHAR(64) NOT NULL,
  FOREIGN KEY (employee_id) REFERENCES employees (id) ON DELETE CASCADE){$table}
CREATE INDEX employee_cities_city_code ON employee_cities (city_code)
```

- [ ] **Step 1: 依存を足す**

Run: `composer require "jmaxml/codes:^0.1.1" "ext-mbstring:*"`、`composer normalize`
Expected: `composer.lock` に jmaxml/codes 0.1.1。`composer install --no-dev` で数えた `vendor/` のファイル数を控える（1,000 前後なら問題ない）

- [ ] **Step 2: 失敗するテストを書く**

```php
// CitiesTest
#[Test] public function findsACityWithItsPrefecture(): void
// find('1310100') → name '東京千代田区'、prefectureCode '13'、prefectureName '東京都'、label() === '東京都 東京千代田区'
#[Test] public function refusesCodesTheEarthquakeInformationCannotUse(): void
// find('0110000')（札幌市の全体）、find('2213100')（再編前の浜松市）、find('131010')、find('') → どれも null
#[Test] public function groupsEveryCityUnderItsPrefecture(): void
// byPrefecture() のキーが 47 個、'13' に ['code' => '1310100', 'name' => '東京千代田区']、全部を足した数が count(EarthquakeCodes::cities())
#[Test] public function restoresTheLeadingZeroExcelDrops(): void
// normalizeCode('110100') === '0110100'、normalizeCode(' 1310100 ') === '1310100'、normalizeCode('abc') === 'abc'

// EmployeeTablesTest（SystemStateTest と同じく #[DataProvider('drivers')] と drivers(): TestDatabase::each() で両方の DB、移行済み）
#[Test] public function removesAddressesAndCitiesWithTheEmployee(string $driver): void
// employees に 1 行、employee_emails と employee_cities に 1 行ずつ → DELETE FROM employees → 両方 0 行
#[Test] public function refusesTheSameAddressKeyTwice(string $driver): void
// address_key が同じ 2 行目の INSERT は PDOException
```

- [ ] **Step 3: 失敗を確かめる**

Run: `vendor/bin/phpunit tests/Employees tests/Database`
Expected: FAIL（`Cities` が無い、表が無い）

- [ ] **Step 4: 実装する**

`ExposureCheckTest` は、一時ディレクトリを各テストで作り `finally` で消す形に戻す（`check()` の補助に root を渡す）。

- [ ] **Step 5: 通ることを確かめる**

Run: `vendor/bin/phpunit`、`vendor/bin/psalm --no-progress`、`vendor/bin/pint --test`、`composer normalize --dry-run`
Expected: OK、No errors found、passed、already normalized

- [ ] **Step 6: コミット**

```bash
git add -A
git commit -m "feat: add the municipality table from jmaxml/codes and the employee tables"
```

---

### Task 2：DB の更新を当てるまで待つ

設計書 6.4 では、新しい zip を FTP で上書きしてから、管理画面のボタンで DB の更新を当てる。その間は、新しいコードが古い表に触れて失敗する。

**Files:**
- Create: `src/Admin/PendingMigrationsGate.php`
- Modify: `src/Http/AppFactory.php`（ログインが要るグループ）、`bin/cron.php`、`templates/admin/database-update.php`
- Test: `tests/Admin/PendingMigrationsGateTest.php`、`tests/Cron/CronEntryTest.php`

**Interfaces:**
- Consumes: `Migrator::pending()`、`AuthMiddleware`、`SystemState`（計画 1）
- Produces: `final class ReBuji\Admin\PendingMigrationsGate implements MiddlewareInterface`：`__construct(Migrator $migrator, ResponseFactoryInterface $responses, Url $url)`。当てていないマイグレーションがあれば、`/admin/database` 以外は 302 で `/admin/database` へ。グループには、クラス名で `->add(PendingMigrationsGate::class)->add(AuthMiddleware::class)` の順に足す（後に足した AuthMiddleware が先に動く）
- Produces: `/admin/database` の画面に、当てていないものがあれば「DB の更新を当てるまで、ほかの画面は開けません。」
- Produces: `bin/cron.php`：設置済みでも、当てていないマイグレーションがあれば、ジョブを動かさない。`cron.last_error` に `'DB の更新を待っています。管理画面の「DB の更新」でボタンを押してください。'` を書き、ログに 1 行。`cron.last_started_at` と `cron.last_finished_at` は書かない（状態の URL が 15 分後に 503 になり、外部の監視サービスで気づける）

- [ ] **Step 1: 失敗するテストを書く**

```php
// PendingMigrationsGateTest
#[Test] public function sendsEveryPageToTheUpdateWhileMigrationsArePending(): void
// installed（migrations にテスト用の '9999' を足す）、ログイン → GET / は 302 Location /admin/database、
// GET /admin/database は 200 で「DB の更新を当てるまで、ほかの画面は開けません。」、POST /logout は 302 /login
#[Test] public function opensThePagesOnceApplied(): void
// POST /admin/database の後、GET / が 200

// CronEntryTest
#[Test] public function waitsForTheDatabaseUpdate(): void
// copy() に当てるマイグレーションの一覧を渡せるようにし、[new M0001CreateFoundationTables()] だけを当てたコピーで bin/cron.php を実行 → 終了コード 0、
// cron.last_error に「DB の更新を待っています」、cron.last_started_at は無い
```

- [ ] **Step 2: 失敗を確かめる**

Run: `vendor/bin/phpunit tests/Admin tests/Cron`
Expected: FAIL

- [ ] **Step 3: 実装する**

テスト用の '9999' のマイグレーションは、`DatabaseUpdateTest::testMigration()` と同じ形の無名クラス。

- [ ] **Step 4: 通ることを確かめる**

Run: `vendor/bin/phpunit`、`vendor/bin/psalm --no-progress`、`vendor/bin/pint --test`
Expected: OK、No errors found、passed

- [ ] **Step 5: コミット**

```bash
git add -A
git commit -m "feat: hold the admin pages and cron until the database update is applied"
```

---

### Task 3：従業員の入力、確かめ、保存

**Files:**
- Create: `src/Employees/EmployeeCity.php`、`src/Employees/EmployeeInput.php`、`src/Employees/Employee.php`、`src/Employees/EmployeeValidator.php`、`src/Employees/EmployeeRepository.php`
- Test: `tests/Employees/EmployeeInputTest.php`、`tests/Employees/EmployeeValidatorTest.php`、`tests/Employees/EmployeeRepositoryTest.php`

**Interfaces:**
- Consumes: `Cities`（Task 1）、`Database`、`AdminRepository::normalize()`（計画 1）
- Produces: `final readonly class EmployeeCity`：`__construct(string $code, string $label)`
- Produces: `final readonly class EmployeeInput`：`__construct(string $name, string $department, list<string> $emails, list<EmployeeCity> $cities, string $memo, bool $active)`
  - `static from(string $name, string $department, array $emails, array $cities, string $memo, bool $active): self`。`$cities` は `list<array{code: string, label: string}>`。文字列の前後の空白を除き、空のアドレスと、コードが空の市町村の行を落とし、コードに `Cities::normalizeCode()` をかける。画面（Task 4）も CSV（Task 5）もこれで作る
- Produces: `final readonly class Employee`：`int $id`、`string $name`、`string $department`、`list<string> $emails`、`list<EmployeeCity> $cities`、`string $memo`、`bool $active`
- Produces: `final class EmployeeValidator`：`__construct(EmployeeRepository $employees)`
  - `validateFields(EmployeeInput $input): list<string>`：DB を見ない確かめ（Global Constraints の件数と文字数、アドレスの形、同じ人の中での重複、`Cities::find()`）
  - `validate(EmployeeInput $input, ?int $employeeId = null): list<string>`：`validateFields()` に加えて、ほかの人が使っているアドレスを拒む（`$employeeId` の人は除く）
- Produces: `final class EmployeeRepository`：`__construct(Database $db, ClockInterface $clock)`
  - `create(EmployeeInput $input): int`、`update(int $id, EmployeeInput $input): void`（アドレスと市町村は入れ替える）、`delete(int $id): void`。書き込みは `Database::transaction()` の中で
  - `find(int $id): ?Employee`、`all(): list<Employee>`（id 順。クエリは 3 本で、1 人ずつ引かない）
  - `counts(): array{all: int, active: int}`
  - `addressOwner(string $address): ?array{id: int, name: string}`
  - `unknownCities(): list<array{employee_id: int, name: string, code: string}>`（保存されているコードのうち、`Cities::find()` が `null` のもの）
  - `static addressKey(string $address): string`（`AdminRepository::normalize()` と同じ）

アドレスの形は次の正規表現で確かめる **[判断]**。国内の携帯電話で今も使われる、ドットが続く・`@` の直前がドットのアドレス（RFC には合わない）を受け付けるため、`filter_var()` は使わない。

```php
'/^[A-Za-z0-9!#$%&\'*+\/=?^_`{|}~.\-]{1,64}@(?:[A-Za-z0-9](?:[A-Za-z0-9\-]{0,61}[A-Za-z0-9])?\.)+[A-Za-z]{2,63}$/'
```

エラーの文言：

| 場合 | 文言 |
|---|---|
| 名前が空 | `名前を入れてください。` |
| アドレスが 0 件か 5 件以上 | `メールアドレスを 1 件以上入れてください（4 件まで）。` |
| アドレスの形 | `メールアドレスの形が正しくありません：{address}` |
| 同じ人の中で重複 | `同じメールアドレスが 2 回入っています：{address}` |
| ほかの人が使っている | `このメールアドレスは {name} さんが使っています：{address}` |
| 市町村が 0 件か 6 件以上 | `市町村を 1 件以上選んでください（5 件まで）。` |
| 判定に使えないコード | `市町村コード {code} は地震の情報で使えません。政令指定都市は区のコードを、合併や再編で変わった市町村は今のコードを使ってください。` |
| 文字数 | `名前は 100 文字までです。`、`所属は 100 文字までです。`、`呼び名は 20 文字までです：{label}`、`メモは 2,000 文字までです。` |

- [ ] **Step 1: 失敗するテストを書く**

```php
// EmployeeInputTest
#[Test] public function dropsBlankRowsAndTrims(): void
// emails ['', ' taro@example.com '] → ['taro@example.com']、
// cities [['code' => '', 'label' => '自宅'], ['code' => ' 110100 ', 'label' => ' 勤務地 ']] → [new EmployeeCity('0110100', '勤務地')]

// EmployeeValidatorTest（SQLite のメモリー上の DB、移行済み）
#[Test] public function acceptsACompleteEmployee(): void
// 名前「安否 花子」、メール 2 件、市町村 1310100（勤務地）と 0110100（自宅）→ []
#[Test] public function requiresANameAnAddressAndACity(): void
// 名前が空・メール 0 件・市町村 0 件 → 上の表の 3 つの文言
#[Test] public function acceptsOldCarrierStyleAddresses(): void
// 'taro..yamada@docomo.ne.jp'、'hanako.@ezweb.ne.jp' → []
#[Test] public function refusesMalformedAddresses(): void
// 'taro'、'taro@localhost'、'a b@example.com'、'山田@example.com'、'taro@example.com,jiro@example.com' → それぞれ「形が正しくありません」
#[Test] public function refusesCodesTheEarthquakeInformationCannotUse(): void
// 市町村 0110000 → 「市町村コード 0110000 は地震の情報で使えません。」で始まるエラー
#[Test] public function refusesAnAddressAnotherEmployeeUses(): void
// A（Taro@Example.com）を保存 → B に taro@example.com → 「このメールアドレスは A さんが使っています」。validate(A の入力, A の id) は []
#[Test] public function refusesTheSameAddressTwiceInOneEmployee(): void
#[Test] public function refusesMoreThanFourAddressesOrFiveCities(): void

// EmployeeRepositoryTest（#[DataProvider('drivers')] で両方の DB）
#[Test] public function savesAndReadsAnEmployee(string $driver): void
// create → find() がアドレスと市町村の順番、memo、active まで同じ。all() が 1 件、counts() が ['all' => 1, 'active' => 1]
#[Test] public function updateReplacesAddressesAndCities(string $driver): void
#[Test] public function deleteRemovesEverything(string $driver): void
#[Test] public function findsWhoUsesAnAddress(string $driver): void
// addressOwner('TARO@example.com') が [id, 名前]、使われていないアドレスは null
#[Test] public function listsCodesThatCanNoLongerBeJudged(string $driver): void
// create の後、employee_cities に city_code '2213100' の行を直接 INSERT → unknownCities() に [employee_id, 名前, '2213100']
```

- [ ] **Step 2: 失敗を確かめる**

Run: `vendor/bin/phpunit tests/Employees`
Expected: FAIL（クラスが無い）

- [ ] **Step 3: 実装する**

- [ ] **Step 4: 通ることを確かめる**

Run: `vendor/bin/phpunit`、`vendor/bin/psalm --no-progress`、`vendor/bin/pint --test`
Expected: OK、No errors found、passed

- [ ] **Step 5: コミット**

```bash
git add -A
git commit -m "feat: validate and store employees with their addresses and municipalities"
```

---

### Task 4：従業員の画面

**Files:**
- Create: `src/Employees/EmployeesController.php`、`src/Http/Flash.php`、`templates/employees/index.php`、`templates/employees/form.php`
- Modify: `src/Http/AppFactory.php`、`src/Container.php`（Engine に `flash()`）、`templates/layout.php`、`templates/nav.php`、`src/HomeController.php`、`templates/home.php`
- Test: `tests/Employees/EmployeePagesTest.php`

**Interfaces:**
- Consumes: `EmployeeInput::from()`、`EmployeeValidator::validate()`、`EmployeeRepository`、`Cities`（Task 1・3）
- Produces: ルート（ログインが要るグループの中）：`GET /employees`、`GET /employees/new`、`POST /employees`、`GET /employees/{id:[0-9]+}`、`POST /employees/{id:[0-9]+}`、`POST /employees/{id:[0-9]+}/delete`。無い id は 404
- Produces: `final class ReBuji\Http\Flash`：`__construct(Session $session)`、`put(string $message): void`、`take(): ?string`。Engine の関数 `flash()` が `take()` を返し、`layout.php` が `<p class="notice">` で出す
- Produces: フォームの項目名：`name`、`department`、`emails[0]`〜`emails[3]`、`cities[0..4][prefecture]`（画面のためだけ。サーバーは使わない）、`cities[0..4][code]`、`cities[0..4][label]`、`memo`、`active`（`'1'`）
- Produces: ナビに「ホーム」「従業員」「DB の更新」とログアウト（Task 6 で「管理者」を足す）
- Produces: ホームに「従業員：{all} 人（停止中 {all - active} 人）」と一覧への入り口。0 人なら「従業員がまだ登録されていません。」。`unknownCities()` が空でなければ、ホームと一覧の上に「判定に使えない市町村が登録されています。直さないと、地震があってもこの人たちに連絡が届きません。」と、名前と市町村コードの一覧

都道府県から市町村を選ぶ仕組み（設計書 3.1）**[判断]**：`form.php` に `Cities::byPrefecture()` を `<script type="application/json" id="rebuji-cities">` で埋め込む（`json_encode` に `JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE`）。各行の都道府県を選ぶと、短い `<script>` がその行の市町村の `<select>` を作り直す。サーバーは、選ばれている都道府県の市町村だけを最初から出しておく（編集の画面は JavaScript が動く前から正しく見える）。JavaScript が使えないときは `<noscript>` で CSV の取り込みを案内する。外部のファイルは読まない。

フラッシュの文言：`{name} さんを登録しました。`、`{name} さんの登録を直しました。`、`{name} さんを削除しました。`。削除のボタンは `onsubmit` の `confirm()` で確かめる。

- [ ] **Step 1: 失敗するテストを書く**

```php
// EmployeePagesTest（TestApp::installed、ログインしてから）
#[Test] public function registersAnEmployeeFromTheForm(): void
// POST /employees（名前「安否 花子」、所属「総務部」、emails[0]、cities[0][code] 1310100、cities[0][label] 勤務地、active 1）
// → 302 /employees、一覧に「安否 花子」と「東京都 東京千代田区（勤務地）」、「安否 花子 さんを登録しました。」
#[Test] public function showsErrorsAndKeepsWhatWasEntered(): void
// cities[0][code] 0110000 → 200、エラーの文言、名前の input に「安否 花子」が残る、counts()['all'] === 0
#[Test] public function editsAndStopsAnEmployee(): void
// 作成 → POST /employees/{id}（active なし）→ 一覧に「停止」、find()->active === false
#[Test] public function deletesAnEmployee(): void
// POST /employees/{id}/delete → 302 /employees、「安否 花子 さんを削除しました。」、find() === null
#[Test] public function editingAMissingEmployeeIsNotFound(): void
// GET /employees/999 → 404
#[Test] public function formCarriesTheCityList(): void
// GET /employees/new の本文に id="rebuji-cities"、"1310100"、"東京千代田区"、都道府県の option が 47
#[Test] public function warnsAboutCodesThatCanNoLongerBeJudged(): void
// 作成の後、employee_cities に '2213100' を直接 INSERT → GET / と GET /employees に「判定に使えない市町村」、名前、2213100
#[Test] public function homeShowsHowManyEmployeesThereAre(): void
// 0 人で「従業員がまだ登録されていません。」、2 人（1 人停止）で「従業員：2 人（停止中 1 人）」
#[Test] public function requiresLogin(): void
// GET /employees（未ログイン）→ 302 /login
```

- [ ] **Step 2: 失敗を確かめる**

Run: `vendor/bin/phpunit tests/Employees/EmployeePagesTest.php`
Expected: FAIL（ルートが無い）

- [ ] **Step 3: 実装する**

フォームの本文の配列は、`InstallController::input()` のように文字列だけを拾う。`emails` と `cities` の配列の形が違うもの（文字列の代わりに配列など）は空として扱う。

- [ ] **Step 4: 通ることを確かめる**

Run: `vendor/bin/phpunit`、`vendor/bin/psalm --no-progress`、`vendor/bin/pint --test`
Expected: OK、No errors found、passed

- [ ] **Step 5: コミット**

```bash
git add -A
git commit -m "feat: add the employee list and the form to register, edit and delete employees"
```

---

### Task 5：CSV の書き出しと取り込み

**Files:**
- Create: `src/Employees/EmployeeCsv.php`、`src/Employees/EmployeeImport.php`、`src/Employees/ImportResult.php`、`src/Employees/EmployeeCsvController.php`、`templates/employees/import.php`
- Modify: `src/Http/AppFactory.php`、`templates/employees/index.php`（書き出し・取り込み・市町村コードの一覧への入り口）、`README.md`（CSV の節）
- Test: `tests/Employees/EmployeeCsvTest.php`、`tests/Employees/EmployeeImportTest.php`、`tests/Employees/EmployeeCsvPagesTest.php`

**Interfaces:**
- Consumes: `EmployeeInput::from()`、`EmployeeValidator::validateFields()`、`EmployeeRepository`、`Cities`
- Produces: `final class EmployeeCsv`：
  - `const array HEADER = ['ID', '名前', '所属', 'メール1', 'メール2', 'メール3', 'メール4', '市町村1', '呼び名1', '市町村2', '呼び名2', '市町村3', '呼び名3', '市町村4', '呼び名4', '市町村5', '呼び名5', 'メモ', '状態'];`
  - `static export(list<Employee> $employees): string`：UTF-8 の BOM 付き、行末は CRLF、1 行目は `HEADER`。市町村は 7 桁のコード、状態は `有効` か `停止`
  - `static parse(string $contents): array{rows: list<array{line: int, id: ?int, input: EmployeeInput}>, errors: list<string>}`
    - 文字コード：UTF-8 として正しければ UTF-8（BOM は除く）、そうでなければ CP932（`mb_convert_encoding($contents, 'UTF-8', 'SJIS-win')`）**[判断]**：Excel は「CSV（コンマ区切り）」で CP932、「CSV UTF-8」で BOM 付き UTF-8 を書く
    - 1 行目が `HEADER` と一致しなければ、`1 行目の見出しが違います。次の見出しにしてください：` と `HEADER` をつないだもの（ほかの行は読まない）
    - すべてのセルが空の行は飛ばす。`ID` は空か数字、`状態` は空（有効）・`有効`・`停止` のどれか。違えば `{line} 行目：…`
    - 行の番号（`line`）は、見出しを 1 行目として数えたファイルの行。セルの中の改行は 1 行と数えない（`fgetcsv` が返した順の番号に 1 を足す）
- Produces: `final readonly class ImportResult`：`list<string> $errors`、`int $created`、`int $updated`
- Produces: `final class EmployeeImport`：`__construct(Database $db, EmployeeRepository $employees, EmployeeValidator $validator)`、`run(string $contents): ImportResult`
  - すべての行を確かめてから書く。1 つでもエラーがあれば何も書かない（`created` も `updated` も 0）**[判断]**：途中まで取り込まれると、どこまで入ったか分からなくなる
  - 行ごとに `validateFields()`。`ID` がある行は、その人がいなければ `{line} 行目：ID {id} の人はいません。`
  - アドレスの重複は、取り込んだ後の状態で数える。ファイルにある人はファイルの内容で、ファイルに無い人は DB の内容で数え、同じアドレスを 2 人が持つことになれば `{line} 行目：このメールアドレスは {n} 行目と同じです：{address}`、または `{line} 行目：このメールアドレスは {name} さんが使っています：{address}`
  - 書き込みは 1 つのトランザクション。ファイルに無い人は消さない
- Produces: ルート（ログインが要るグループ）：
  - `GET /employees/export`：`re-buji-employees-{Ymd}.csv`（日付は Asia/Tokyo）、`Content-Type: text/csv; charset=UTF-8`、`Content-Disposition: attachment`、`Cache-Control: no-store`
  - `GET /employees/import`、`POST /employees/import`（ファイルの項目名は `file`）。成功したら `{created} 人を登録し、{updated} 人を更新しました。` をフラッシュに入れて `/employees` へ。エラーなら 200 で一覧を出す
  - `GET /employees/cities.csv`：`市町村コード,都道府県,市町村` の見出しと全部の市町村（BOM 付き UTF-8）。CSV を作る人がコードを探すため
- アップロードの失敗：ファイルが無い `ファイルを選んでください。`、`UPLOAD_ERR_INI_SIZE` / `UPLOAD_ERR_FORM_SIZE` は `ファイルが大きすぎます（このサーバーの上限は {upload_max_filesize} です）。`、ほかは `ファイルを受け取れませんでした。もう一度お試しください。`

- [ ] **Step 1: 失敗するテストを書く**

```php
// EmployeeCsvTest
#[Test] public function exportsWithABomAndTheHeader(): void
// 先頭が "\xEF\xBB\xBF"、1 行目が HEADER、行末が "\r\n"、市町村1 が 1310100、状態が 有効
#[Test] public function roundTripsAnExport(): void
// export() → parse() で、同じ id と同じ EmployeeInput（改行を含むメモも）
#[Test] public function importsShiftJisFromExcel(): void
// HEADER と「髙橋 﨑子」の行を mb_convert_encoding(..., 'SJIS-win', 'UTF-8') → rows[0]['input']->name === '髙橋 﨑子'
#[Test] public function importsCodesWhoseLeadingZeroExcelDropped(): void
// 市町村1 が 110100 → cities[0]->code === '0110100'
#[Test] public function refusesAFileWithOtherHeaders(): void
// 1 行目が「名前,メール」→ errors[0] が「1 行目の見出しが違います」で始まり、HEADER を含む。rows は空
#[Test] public function skipsEmptyRowsAndCountsLines(): void
// 2 行目が空のセルだけ、3 行目にデータ → rows[0]['line'] === 3

// EmployeeImportTest（SQLite のメモリー上の DB、移行済み）
#[Test] public function createsAndUpdatesInOneGo(): void
// 既存の 1 人（ID の行で名前を変える）と新しい 1 人（ID が空）→ created 1、updated 1、名前が変わっている
#[Test] public function importsNothingWhenAnyRowIsWrong(): void
// 2 行のうち 3 行目の市町村が 0110000 → errors に「3 行目：市町村コード 0110000」、counts()['all'] は変わらない
#[Test] public function refusesTheSameAddressOnTwoRows(): void
// 2 行目と 3 行目が同じアドレス（大文字小文字だけ違う）→ 「3 行目：このメールアドレスは 2 行目と同じです」
#[Test] public function allowsMovingAnAddressBetweenPeopleInTheFile(): void
// 既存の A と B。ファイルで A のアドレスを B に移し、A には別のアドレス → errors は空
#[Test] public function refusesAnAddressSomeoneOutsideTheFileUses(): void
// 既存の A（ファイルに無い）のアドレスを新しい行に → 「このメールアドレスは A さんが使っています」
#[Test] public function refusesAnUnknownId(): void
// ID 999 → 「2 行目：ID 999 の人はいません。」

// EmployeeCsvPagesTest（ログインしてから）
#[Test] public function downloadsTheEmployees(): void
// GET /employees/export → 200、Content-Disposition に attachment と .csv、Cache-Control に no-store
#[Test] public function uploadsAFile(): void
// POST /employees/import（UploadedFile、1 人分）→ 302 /employees、「1 人を登録し、0 人を更新しました。」
#[Test] public function asksForAFile(): void
// ファイルなし → 200 で「ファイルを選んでください。」
#[Test] public function downloadsTheCityCodes(): void
// GET /employees/cities.csv → 1 行目が「市町村コード,都道府県,市町村」、「1310100,東京都,東京千代田区」を含む
```

- [ ] **Step 2: 失敗を確かめる**

Run: `vendor/bin/phpunit tests/Employees`
Expected: FAIL（クラスとルートが無い）

- [ ] **Step 3: 実装する**

テストのアップロードは `Slim\Psr7\Factory\StreamFactory` と `UploadedFileFactory` で作り、`withUploadedFiles(['file' => …])` で渡す。

- [ ] **Step 4: README に CSV の節を書く**

- 列の一覧（`HEADER` の順）と意味。`ID` は空なら新しい人、番号があればその人を更新。ファイルに無い人は消えない
- 市町村は気象庁の 7 桁のコード。一覧は従業員の画面の「市町村コードの一覧」から。政令指定都市は区のコード
- Excel で保存してよい（Shift_JIS でも UTF-8 でもよい。先頭の 0 が落ちても戻す）
- 1 行でも間違いがあれば、何も取り込まない

- [ ] **Step 5: 通ることを確かめる**

Run: `vendor/bin/phpunit`、`vendor/bin/psalm --no-progress`、`vendor/bin/pint --test`
Expected: OK、No errors found、passed

- [ ] **Step 6: コミット**

```bash
git add -A
git commit -m "feat: export and import employees as CSV, including files saved by Excel"
```

---

### Task 6：管理者の一覧・追加・削除

**Files:**
- Create: `src/Auth/AdminsController.php`、`templates/admins/index.php`
- Modify: `src/Auth/AdminRepository.php`、`src/Http/AppFactory.php`、`templates/nav.php`、`README.md`（できることの一文）
- Test: `tests/Auth/AdminPagesTest.php`

**Interfaces:**
- Consumes: `AdminRepository`、`PasswordPolicy`、`Flash`（計画 1・Task 4）
- Produces: `AdminRepository::all(): list<array{id: int, name: string, email: string, created_at: string}>`（id 順）、`AdminRepository::delete(int $id): void`
- Produces: ルート（ログインが要るグループ）：`GET /admins`、`POST /admins`（`name`、`email`、`password`、`password_confirmation`）、`POST /admins/{id:[0-9]+}/delete`
- 追加の確かめ：名前 1〜100 文字、アドレスの形は Task 3 の正規表現、すでにいるアドレス（大文字小文字を区別しない）は `このメールアドレスの管理者はすでにいます。`、パスワードは `PasswordPolicy::validate()` と確認の一致
- 自分は削除できない：`自分は削除できません。ほかの管理者に頼んでください。` **[判断]**：自分を消せなければ、ログインしている人が必ず 1 人残るので、管理者が 0 人にならない
- フラッシュ：`{name} さんを管理者に加えました。`、`{name} さんを管理者から外しました。`
- 消された管理者は、次のリクエストでログイン画面へ戻る（`AuthMiddleware` が毎回 id で引くため。足すコードは無い）

- [ ] **Step 1: 失敗するテストを書く**

```php
// AdminPagesTest（TestApp::installed、ログインしてから）
#[Test] public function listsAndAddsAdmins(): void
// POST /admins → 302 /admins、一覧に 2 人、新しい管理者のアドレスとパスワードで POST /login が 302 /
#[Test] public function refusesADuplicateEmailOrAShortPassword(): void
// ADMIN_EMAIL を大文字にして追加 → 「すでにいます」、11 文字のパスワード → PasswordPolicy の文言。どちらも管理者は 1 人のまま
#[Test] public function cannotDeleteOneself(): void
// 自分の id で POST /admins/{id}/delete → 「自分は削除できません。」、count() === 1
#[Test] public function deletingAnAdminEndsTheirSession(): void
// B を作り、同じ root の 2 つ目の app（TestApp::build）で B がログイン → 1 つ目の app で A が B を削除 → B の app で GET / が 302 /login
```

- [ ] **Step 2: 失敗を確かめる**

Run: `vendor/bin/phpunit tests/Auth/AdminPagesTest.php`
Expected: FAIL

- [ ] **Step 3: 実装する**

- [ ] **Step 4: 通ることを確かめる**

Run: `vendor/bin/phpunit`、`vendor/bin/psalm --no-progress`、`vendor/bin/pint --test`、`composer normalize --dry-run`
Expected: OK、No errors found、passed、already normalized

- [ ] **Step 5: README の「開発中」の一文を直す**

できること：設置、初期設定、管理者のログインと管理、従業員の登録（画面と CSV）、cron と見張り。

- [ ] **Step 6: コミット**

```bash
git add -A
git commit -m "feat: list, add and remove admins"
```

---

## 計画 2 で作らないもの（後の計画へ渡すこと）

| 内容 | 計画 |
|---|---|
| フィードの確認、地震、送信、上限、再送、訓練、取消の知らせ、設定画面 | 3 |
| ドットが続くアドレス（`taro..yamada@docomo.ne.jp`）にも送れることを、送信の部品で確かめる。送れないなら、登録の時点で知らせる | 3 |
| 送った記録がある従業員を削除したときの扱い（記録には名前を写して残すなど） | 3 |
| 停止中の人に送らない（`Employee::$active`）、市町村から細分区域を引く（`Cities::find()` と `EarthquakeCodes::city()->areaCode`） | 3 |
| 返事の照合で、送り主のアドレスから人を引く（`employee_emails.address_key`） | 4 |
| 管理者のパスワードの変更、パスワードを忘れたとき | 範囲外（設計書 7 章に無い。必要なら作者に確かめる） |

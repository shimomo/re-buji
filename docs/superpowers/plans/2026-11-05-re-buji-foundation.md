# Re:無事 計画 1：基盤 実装計画

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** レンタルサーバーに zip を FTP で置けば、初期設定を経て管理者がログインでき、cron が動き、外部の監視サービスで止まっていないかを見張れる、Re:無事 の土台を作る。

**Architecture:** Slim 4 ＋ PHP-DI ＋ Plates。DB は PDO の薄いラッパーで SQLite と MySQL の両方に対応し、変更は PHP のマイグレーションのクラスで当てる。初期設定・cron・状態の URL・マイグレーションは、後の計画が項目を足せるように一覧（レジストリ）で持つ。地震・送信・返信は計画 2〜4 で足す。

**Tech Stack:** PHP ^8.3、slim/slim ^4.15、slim/psr7、php-di/php-di ^7.1、league/plates ^3.6、slim/csrf ^1.5、symfony/http-client（`^7.4 || ^8.0`）、nyholm/psr7、psr/log、psr/clock。開発用は PHPUnit（`^12.5 || ^13.2`）、Psalm 6（errorLevel 1）、Laravel Pint、ergebnis/composer-normalize。

**Spec:** `docs/superpowers/specs/2026-11-05-re-buji-design.md`（2 章の制約、3.2、6.1〜6.5、7 章）

**計画の分け方（作者が承認、2026-11-05）：** 1 基盤 → 2 登録 → 3 地震と送信 → 4 返事と画面。計画 2〜4 は、前の計画をマージしてから書く。

## Global Constraints

- PHP `^8.3`。PHP 8.4 にしか無い機能は使わない（Psalm は composer.json の `php` から 8.3 として解析する）
- ext-imap に頼らない。PDO のドライバー（pdo_sqlite か pdo_mysql）は、どちらか一方があればよい。初期設定で確かめる
- SQLite は `PRAGMA journal_mode = DELETE`（WAL にしない）、`PRAGMA busy_timeout = 5000`、`PRAGMA foreign_keys = ON` で開く
- 日時は DB に UTC の文字列 `Y-m-d\TH:i:s\Z`（例 `2026-11-05T03:12:00Z`）で入れる。画面では Asia/Tokyo で出す
- 名前空間は `ReBuji\`、テストは `ReBuji\Tests\`。ライセンスは MIT。画面の文言は日本語
- 公開ディレクトリ `public/` 以外のディレクトリ（`src/`、`templates/`、`bin/`、`migrations/`、`storage/`、`vendor/`、`docs/` など）には、すべてを拒否する `.htaccess` を置く。Apache 2.2 と 2.4 の両方の書き方で書く
- `vendor/` のファイル数は、計画 1 の時点で 1,000 以下、アプリ全体で 1,800 以下を目安にする（設計書 2.2）。依存を足すときは増え方を確かめる
- テストクラスでは `setUp` / `tearDown` を使わない（jmaxml-php と同じ慣習。Psalm の PropertyNotSetInConstructor を避ける）。一時ファイルは `finally` で消す
- コミットは作業ブランチで行う（main に直接は積まない）。コミットメッセージの末尾は `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`

## Review Focus

- 作者より先に、ほかの誰かが初期設定の画面を開く → `storage/` のファイルで渡す設置の鍵が無いと、先に進めない（Task 4）
- `.htaccess` が効かないサーバー（nginx だけ、AllowOverride が無効など）→ 公開の確かめで 200 が返り、初期設定を終えられない（Task 5）
- 2 本の cron が重なる（前の回が長引いたところに次の回が来る）→ 後の回はジョブを動かさずに終わる（Task 7）
- cron の PHP ファイルをブラウザから開く → 何も動かさずに 403 を返す（Task 7）
- 状態の URL の文字列を当てずっぽうで試す → 存在しないページと同じ 404 になり、本文に個人情報が出ない（Task 8）

## ディレクトリ構成

```
re-buji/
├── .htaccess                 # public/ へ振り向け、ほかへの直接のアクセスを拒否
├── public/                   # 公開するもの（index.php、.htaccess、assets/）
├── src/                      # ReBuji\（各ディレクトリに .htaccess）
├── templates/                # Plates のテンプレート
├── migrations/               # ReBuji\Migrations\（DB の変更）
├── bin/                      # cron.php、build-release.sh
├── storage/                  # 書き込む場所：config.php、database.sqlite、cron.lock、sessions/、logs/、install-key.txt
├── tests/
└── vendor/
```

`public/index.php` は、`public/rebuji-root.php` があればそれが返すパスを、無ければ `dirname(__DIR__)` をアプリのルートにする。公開ディレクトリの外に本体を置けるサーバーで、`public/` の中身だけを公開ディレクトリに写す使い方のため。

---

### Task 1：リポジトリの土台、パスと設定ファイル

**Files:**
- Create: `composer.json`、`pint.json`（jmaxml-php から写す）、`psalm.xml.dist`、`phpunit.xml.dist`、`.gitignore`、`.gitattributes`、`LICENSE`、`README.md`（概要と「開発中」の一文だけ）
- Create: `.github/workflows/{test,lint,psalm,audit}.yml`、`.github/dependabot.yml`（jmaxml-php から写して直す）
- Create: `src/Version.php`、`src/Paths.php`、`src/Config/Config.php`、`src/Config/ConfigFile.php`、`src/Time.php`
- Test: `tests/PathsTest.php`、`tests/Config/ConfigFileTest.php`、`tests/TimeTest.php`

**Interfaces:**
- Produces: `ReBuji\Version::CURRENT`（`'0.1.0-dev'`）
- Produces: `final readonly class ReBuji\Paths`：`__construct(string $root)`、`root(): string`、`public(): string`、`templates(): string`、`storage(string $relative = ''): string`
- Produces: `final readonly class ReBuji\Config\Config`：`__construct(array<string, mixed> $values)`、`get(string $key, mixed $default = null): mixed`、`all(): array<string, mixed>`。キーはドット区切りの平らな名前（`db.driver`、`db.sqlite.path`、`db.mysql.host`、`db.mysql.port`、`db.mysql.database`、`db.mysql.username`、`db.mysql.password`、`status.token`、`installed_at`）
- Produces: `final class ReBuji\Config\ConfigFile`：`__construct(string $path)`、`exists(): bool`、`load(): Config`、`save(Config $config): void`
- Produces: `final class ReBuji\Time`：`static format(DateTimeImmutable $time): string`（UTC の `Y-m-d\TH:i:s\Z`）、`static parse(string $value): DateTimeImmutable`（形が違えば `InvalidArgumentException`）

- [ ] **Step 1: 土台のファイルを作る**

- `composer.json`：`name` は `shimomo/re-buji`、`type` は `project`、`license` は `MIT`、`require` は `php ^8.3`、`ext-pdo *`、Tech Stack の実行時の依存。`autoload` は `ReBuji\` → `src/` と `ReBuji\Migrations\` → `migrations/`。`autoload-dev` は `ReBuji\Tests\` → `tests/`。`config.platform.php` は `8.3.0`（レンタルサーバーで入る版で固定する）
- CI は jmaxml-php の 4 本を写す。`packages/*/composer.json` の扱いを外す。test.yml には、MySQL 8 のサービスコンテナを使うジョブを 1 つ足し、`REBUJI_TEST_MYSQL_DSN` を渡す
- `.gitignore` は `/vendor/`、`/storage/*`（`.htaccess` と `.gitkeep` は残す）、`/build/`、`.phpunit.cache`

- [ ] **Step 2: 失敗するテストを書く**

```php
// PathsTest
#[Test] public function resolvesDirectoriesFromTheRoot(): void
// new Paths('/srv/app') → public() === '/srv/app/public'、templates() === '/srv/app/templates'、storage('config.php') === '/srv/app/storage/config.php'、storage() === '/srv/app/storage'

// ConfigFileTest
#[Test] public function savesAndLoadsValues(): void
// 一時ディレクトリの config.php に save(new Config(['db.driver' => 'sqlite', 'status.token' => 'abc'])) → exists() が true、load()->get('db.driver') === 'sqlite'、get('missing', 'x') === 'x'
#[Test] public function savedFileIsReadableOnlyByTheOwner(): void
// save の後の fileperms & 0777 === 0600
#[Test] public function saveReplacesTheFileAtomically(): void
// save の後、同じディレクトリに一時ファイル（config.php.* ）が残っていない
#[Test] public function missingFileDoesNotExist(): void
// exists() が false、load() は RuntimeException

// TimeTest
#[Test] public function formatsInUtc(): void
// format(new DateTimeImmutable('2026-11-05T12:12:00+09:00')) === '2026-11-05T03:12:00Z'、parse('2026-11-05T03:12:00Z') は UTC
#[Test] public function rejectsOtherShapes(): void
// parse('2026-11-05 03:12:00') は InvalidArgumentException
```

- [ ] **Step 3: 失敗を確かめる**

Run: `composer install && vendor/bin/phpunit`
Expected: FAIL（クラスが無い）

- [ ] **Step 4: 実装する**

`ConfigFile::save()` は `var_export` で `<?php return [...];` を書き、同じディレクトリの一時ファイルに書いてから `rename` する。権限は 0600。`load()` は `require` して配列でなければ `RuntimeException`。

- [ ] **Step 5: 通ることを確かめる**

Run: `vendor/bin/phpunit`、`vendor/bin/psalm --no-progress`、`vendor/bin/pint --test`、`composer normalize --dry-run`
Expected: OK、No errors found、passed、already normalized

- [ ] **Step 6: コミット**

```bash
git add -A
git commit -m "chore: set up the project, paths and the config file"
```

---

### Task 2：DB とマイグレーション

**Files:**
- Create: `src/Database/Database.php`、`src/Database/Migration.php`、`src/Database/Migrator.php`、`src/Database/Migrations.php`、`migrations/M0001CreateFoundationTables.php`、`src/SystemState.php`
- Test: `tests/Database/DatabaseTest.php`、`tests/Database/MigratorTest.php`、`tests/SystemStateTest.php`、`tests/Support/TestDatabase.php`

**Interfaces:**
- Consumes: `Config`、`Paths`、`Time`（Task 1）
- Produces: `final class ReBuji\Database\Database`
  - `static fromConfig(Config $config, Paths $paths): self`。`db.driver` が `sqlite` なら `Paths::storage(db.sqlite.path)`、`mysql` なら `db.mysql.*` で接続する。MySQL は `charset=utf8mb4`
  - `static sqliteInMemory(): self`（テスト用）
  - `driver(): string`（`'sqlite'` か `'mysql'`）
  - `execute(string $sql, array<string|int, scalar|null> $params = []): int`（変更した行の数）
  - `fetchAll(string $sql, array $params = []): list<array<string, mixed>>`、`fetchOne(...): ?array<string, mixed>`、`fetchValue(...): mixed`
  - `lastInsertId(): int`
  - `transaction(callable(self): T $callback): T`（例外ならロールバックして投げ直す）
- Produces: `interface ReBuji\Database\Migration`：`version(): string`（4 桁、例 `'0001'`）、`description(): string`、`statements(string $driver): list<string>`
- Produces: `final class ReBuji\Database\Migrations`：`static all(): list<Migration>`（版の順。後の計画がここに足す）
- Produces: `final class ReBuji\Database\Migrator`：`__construct(Database $db, list<Migration> $migrations, ClockInterface $clock)`、`pending(): list<Migration>`、`migrate(): list<string>`（当てた版）
- Produces: `final class ReBuji\SystemState`：`__construct(Database $db, ClockInterface $clock)`、`get(string $name): ?string`、`set(string $name, string $value): void`
- Produces: テーブル（0001）
  - `schema_migrations(version VARCHAR(16) 主キー, applied_at VARCHAR(20))`
  - `admins(id 自動採番, name VARCHAR(255), email VARCHAR(255) 一意, password_hash VARCHAR(255), created_at VARCHAR(20))`
  - `login_attempts(id 自動採番, email VARCHAR(255), ip VARCHAR(45), succeeded INTEGER, attempted_at VARCHAR(20))`、`attempted_at` に索引
  - `system_state(name VARCHAR(64) 主キー, value TEXT, updated_at VARCHAR(20))`
- Produces: `ReBuji\Tests\Support\TestDatabase`：`static each(): iterable<string, array{Database}>`。SQLite のメモリー上の DB と、`REBUJI_TEST_MYSQL_DSN` があれば MySQL を返す（MySQL のテストは、テーブルを消してから使う）。DB に触るテストは、この DataProvider で両方を試す

- [ ] **Step 1: 失敗するテストを書く**

```php
// DatabaseTest（TestDatabase::each で SQLite と MySQL）
#[Test] #[DataProvider(...)] public function runsQueriesWithParameters(Database $db): void
// CREATE TABLE → execute(INSERT, [..]) === 1、fetchOne(... WHERE id = ?) が値を返す、fetchValue('SELECT COUNT(*) ...') === 1（MySQL は文字列で返るので (int) にそろえる）
#[Test] #[DataProvider(...)] public function rollsBackAFailedTransaction(Database $db): void
// transaction 内で INSERT のあと例外 → 例外が外に出て、行は残らない
#[Test] public function sqliteFileIsOpenedWithoutWal(): void
// 一時ディレクトリの SQLite ファイルを fromConfig で開く → fetchValue('PRAGMA journal_mode') === 'delete'、'PRAGMA foreign_keys' === 1

// MigratorTest
#[Test] #[DataProvider(...)] public function appliesPendingMigrationsOnce(Database $db): void
// 新しい DB で pending() は Migrations::all() と同じ版、migrate() === ['0001']、2 回目の migrate() === []、admins に INSERT できる
#[Test] #[DataProvider(...)] public function recordsWhenEachMigrationWasApplied(Database $db): void
// schema_migrations の applied_at が FrozenClock の時刻（Time::format）

// SystemStateTest
#[Test] #[DataProvider(...)] public function setsAndOverwritesValues(Database $db): void
// get('x') === null → set('x', 'a') → 'a' → set('x', 'b') → 'b'、updated_at は FrozenClock の時刻
```

`FrozenClock`（`ReBuji\Tests\Support\FrozenClock`、`psr/clock` の実装で `advance(string $modifier)` を持つ）もここで作る。

- [ ] **Step 2: 失敗を確かめる**

Run: `vendor/bin/phpunit tests/Database tests/SystemStateTest.php`
Expected: FAIL（クラスが無い）

- [ ] **Step 3: 実装する**

- 自動採番は、SQLite で `INTEGER PRIMARY KEY AUTOINCREMENT`、MySQL で `INT UNSIGNED AUTO_INCREMENT PRIMARY KEY`。MySQL のテーブルは `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`。違いは `statements($driver)` の中だけに置く
- `migrate()` は、版ごとに文を流してから `schema_migrations` に記録する。MySQL の DDL はトランザクションに入らないので、版ごとに順に当てる
- `SystemState::set()` は、UPDATE で 0 行なら INSERT する（どちらの DB でも同じ書き方）
- PDO は `ERRMODE_EXCEPTION`、`DEFAULT_FETCH_MODE = FETCH_ASSOC`

- [ ] **Step 4: 通ることを確かめる**

Run: `vendor/bin/phpunit`、`vendor/bin/psalm --no-progress`
Expected: OK（MySQL のテストは環境変数が無ければ SQLite だけ）、No errors found

- [ ] **Step 5: コミット**

```bash
git add -A
git commit -m "feat: add the database layer, migrations and system state"
```

---

### Task 3：ウェブの骨組み

**Files:**
- Create: `public/index.php`、`public/.htaccess`、`.htaccess`（ルート）、`src/Container.php`、`src/Http/AppFactory.php`、`src/Http/Session.php`、`src/Http/NativeSession.php`、`src/Http/ArraySession.php`、`src/Http/InstalledGate.php`、`src/Http/CsrfMiddlewareFactory.php`、`src/Log/FileLogger.php`、`templates/layout.php`、`templates/error.php`
- Test: `tests/Http/AppFactoryTest.php`、`tests/Http/InstalledGateTest.php`、`tests/Http/CsrfTest.php`、`tests/Log/FileLoggerTest.php`、`tests/Support/TestApp.php`

**Interfaces:**
- Consumes: `Paths`、`ConfigFile`、`Database`（Task 1・2）
- Produces: `final class ReBuji\Container`：`static build(Paths $paths, array<string, mixed> $overrides = []): ContainerInterface`。少なくとも `Paths`、`ConfigFile`、`ClockInterface`、`LoggerInterface`、`Session`、`Database`（設定があるときだけ作る）、`ClientInterface`（PSR-18）、`RequestFactoryInterface`、`League\Plates\Engine` を持つ。文字列の ID `migrations` に `Migrations::all()`（`list<Migration>`）を持ち、`Migrator` はそれを使う。テストは `$overrides` で差し替える
- Produces: `final class ReBuji\Http\AppFactory`：`static create(ContainerInterface $container): Slim\App`。ルートは `routes(Slim\App $app)` に集め、後の計画が足す
- Produces: `interface ReBuji\Http\Session`：`get(string $key): mixed`、`set(string $key, mixed $value): void`、`remove(string $key): void`、`regenerate(): void`、`clear(): void`。`NativeSession` は `session.save_path` を `storage/sessions`、クッキー名を `rebuji_session`、`httponly`、`samesite=Lax`、HTTPS なら `secure` にする
- Produces: `InstalledGate` ミドルウェア：設定ファイルに `installed_at` が無ければ `/install` 以外を `/install` へ 302、あれば `/install` 以下を 404
- Produces: CSRF：`CsrfMiddlewareFactory` が、`Session` を通した保管先で `slim/csrf` の `Guard` を作る。POST はすべて検査する（`/status/{token}` は GET だけなので対象外）。テンプレートは `$this->csrf()` で隠しフィールドを出す。失敗したら 400 と「もう一度お試しください」
- Produces: `final class ReBuji\Log\FileLogger implements LoggerInterface`：`storage/logs/re-buji.log` に 1 行ずつ追記する（時刻・レベル・メッセージ・context の JSON）
- Produces: `ReBuji\Tests\Support\TestApp`：`static create(array $overrides = [], ?Config $config = null): array{Slim\App, ContainerInterface, string}`（一時ディレクトリをアプリのルートにし、3 つ目でそのパスを返す）と `static request(string $method, string $path, array $body = []): ServerRequestInterface`

- [ ] **Step 1: 失敗するテストを書く**

```php
// InstalledGateTest
#[Test] public function sendsEverythingToTheInstallerBeforeSetup(): void
// 設定ファイルが無い状態で GET / → 302 Location: /install、GET /install → 200
#[Test] public function hidesTheInstallerAfterSetup(): void
// installed_at のある設定で GET /install → 404、GET / → 302 Location: /login

// AppFactoryTest
#[Test] public function showsAGenericPageOnErrors(): void
// 例外を投げるテスト用のルートを足して GET → 500、本文に例外のメッセージが無い、FileLogger に記録される

// CsrfTest
#[Test] public function rejectsAPostWithoutAToken(): void
// テスト用の POST ルートに、トークン無しで POST → 400、トークンを付ければ 200

// FileLoggerTest
#[Test] public function appendsOneLinePerEntry(): void
// error('x', ['a' => 1]) を 2 回 → ログが 2 行、どちらも 'ERROR' と 'x' と '{"a":1}' を含む
```

- [ ] **Step 2: 失敗を確かめる**

Run: `vendor/bin/phpunit tests/Http tests/Log`
Expected: FAIL

- [ ] **Step 3: 実装する**

- ルートの `.htaccess` は、`public/` 以外へのリクエストを `public/` に振り向け、`public/` の外のファイルへの直接のアクセスは拒否する。`public/.htaccess` は、ファイルが無ければ `index.php` へ送る
- Slim の `ErrorMiddleware` は、本番では詳細を出さず、`LoggerInterface` に記録する
- テンプレートの文言は日本語。`templates/layout.php` は見出しとナビゲーションだけの簡素なものでよい

- [ ] **Step 4: 通ることを確かめる**

Run: `vendor/bin/phpunit`、`vendor/bin/psalm --no-progress`、`vendor/bin/pint --test`
Expected: OK、No errors found、passed

- [ ] **Step 5: コミット**

```bash
git add -A
git commit -m "feat: add the web skeleton, sessions and the installed gate"
```

---

### Task 4：初期設定の画面

**Files:**
- Create: `src/Install/InstallStep.php`、`src/Install/InstallSteps.php`、`src/Install/InstallContext.php`、`src/Install/StepResult.php`、`src/Install/InstallKey.php`、`src/Install/Steps/{EnvironmentStep,KeyStep,DatabaseStep,AdminStep,FinishStep}.php`、`src/Install/InstallController.php`、`src/Auth/AdminRepository.php`、`src/Auth/PasswordPolicy.php`、`templates/install/*.php`
- Test: `tests/Install/InstallFlowTest.php`、`tests/Install/InstallKeyTest.php`、`tests/Auth/PasswordPolicyTest.php`

**Interfaces:**
- Consumes: `Container`、`AppFactory`、`Session`、`ConfigFile`、`Database`、`Migrator`（Task 1〜3）
- Produces: `interface ReBuji\Install\InstallStep`：`key(): string`、`title(): string`、`template(): string`、`viewData(InstallContext $context): array<string, mixed>`、`submit(InstallContext $context, array<string, string> $input): StepResult`
- Produces: `final readonly class ReBuji\Install\StepResult`：`static done(): self`、`static failed(list<string> $errors): self`、`list<string> $errors`、`ok(): bool`
- Produces: `final class ReBuji\Install\InstallSteps`：`static all(ContainerInterface $c): list<InstallStep>`。計画 1 の順は `environment`、`key`、`database`、`admin`、`exposure`（Task 5）、`finish`。計画 4 は `finish` の前に `mail` を足す
- Produces: `final class ReBuji\Install\InstallContext`：`Paths`、`Session`（入力の途中の値と、済んだ段階）、`ConfigFile`、`ContainerInterface` を持つ。`draft(): array<string, mixed>`、`putDraft(string $key, mixed $value): void`、`completed(string $stepKey): bool`
- Produces: `final class ReBuji\Install\InstallKey`：`__construct(Paths $paths)`、`ensure(): void`（無ければ 24 文字のランダムな鍵を `storage/install-key.txt` に 0600 で作る）、`matches(string $input): bool`（`hash_equals`）、`forget(): void`
- Produces: `final class ReBuji\Auth\AdminRepository`：`__construct(Database $db, ClockInterface $clock)`、`create(string $name, string $email, string $password): int`（`password_hash` の既定）、`findByEmail(string $email): ?array{id: int, name: string, email: string, password_hash: string}`、`count(): int`
- Produces: `final class ReBuji\Auth\PasswordPolicy`：`static validate(string $password): list<string>`（12 文字未満なら「パスワードは 12 文字以上にしてください」）
- 各段階の中身：
  - `environment`：PHP 8.3 以上、`pdo` と（`pdo_sqlite` か `pdo_mysql`）、`storage/` に書けること。足りなければ、何が足りないかを出して先に進めない。HTTPS でなければ「HTTPS で開いてください（レンタルサーバーの無料 SSL を有効にする）」と警告する。止めはしない
  - `key`：`InstallKey::ensure()` の後、「FTP で `storage/install-key.txt` を開き、中の文字列を貼ってください」と求める。一致しなければ進めない
  - `database`：SQLite か MySQL を選ぶ。SQLite は `storage/database.sqlite`。接続を試し、失敗の理由を出す。通れば設定ファイルに `db.*` を書き、`Migrator::migrate()` を当てる
  - `admin`：名前・メール・パスワード（確認つき）で最初の管理者を作る
  - `finish`：`status.token`（`bin2hex(random_bytes(16))`）と `installed_at` を設定ファイルに書き、`InstallKey::forget()`。cron に登録するファイルのパス（`bin/cron.php` の絶対パス）、状態の URL、外部の監視サービスの設定の仕方を出す

- [ ] **Step 1: 失敗するテストを書く**

```php
// InstallFlowTest（TestApp と ArraySession で、各段階に POST していく）
#[Test] public function completesTheWholeFlowWithSqlite(): void
// environment → key（storage/install-key.txt の中身）→ database（sqlite）→ admin → exposure（Task 5 の偽の結果を差し込む）→ finish
// → 設定ファイルに installed_at と status.token、admins が 1 件、install-key.txt が消えている、GET /install は 404
#[Test] public function refusesAWrongInstallKey(): void
// key の段階で違う文字列 → 200 で「鍵が違います」、次の段階へ進めない（GET /install/database は /install/key へ 302）
#[Test] public function cannotSkipAhead(): void
// key を済ませずに POST /install/admin → /install/key へ 302、admins は 0 件
#[Test] public function reportsAnUnwritableStorage(): void
// storage を読み取り専用にして GET /install → 「storage に書き込めません」
#[Test] public function warnsWithoutHttps(): void
// http:// のリクエストで GET /install → 警告の文言があり、先へ進むボタンもある
#[Test] public function reportsADatabaseConnectionFailure(): void
// MySQL を選び、届かないホスト → 200 で接続できない理由、設定ファイルには db.* が書かれない

// InstallKeyTest
#[Test] public function createsTheKeyOnceAndComparesSafely(): void
// ensure() を 2 回 → 中身は同じ、24 文字、権限 0600、matches(中身) が true、matches('x') が false、forget() で消える

// PasswordPolicyTest
#[Test] public function requiresTwelveCharacters(): void
// validate('short') に 1 件、validate(12 文字) は []
```

- [ ] **Step 2: 失敗を確かめる**

Run: `vendor/bin/phpunit tests/Install tests/Auth`
Expected: FAIL

- [ ] **Step 3: 実装する**

段階の URL は `/install/{key}`。GET で表示、POST で `submit`。前の段階が済んでいなければ、済んでいない最初の段階へ 302。フォームには Task 3 の CSRF の隠しフィールドを入れる。

- [ ] **Step 4: 通ることを確かめる**

Run: `vendor/bin/phpunit`、`vendor/bin/psalm --no-progress`、`vendor/bin/pint --test`
Expected: OK、No errors found、passed

- [ ] **Step 5: コミット**

```bash
git add -A
git commit -m "feat: add the installer with an install key read over FTP"
```

---

### Task 5：公開されていないことの確かめと .htaccess

**Files:**
- Create: `src/Install/ExposureCheck.php`、`src/Install/ExposureResult.php`、`src/Install/Steps/ExposureStep.php`、`templates/install/exposure.php`
- Create: `src/.htaccess`、`templates/.htaccess`、`migrations/.htaccess`、`bin/.htaccess`、`storage/.htaccess`、`tests/.htaccess`、`docs/.htaccess`（`vendor/.htaccess` はリリースの zip に入れる。Task 9）
- Test: `tests/Install/ExposureCheckTest.php`、`tests/HtaccessTest.php`

**Interfaces:**
- Consumes: `InstallStep`、`InstallContext`、`StepResult`（Task 4）、PSR-18 の `ClientInterface` と `RequestFactoryInterface`（Task 3 の Container）
- Produces: `final class ReBuji\Install\ExposureCheck`：`__construct(ClientInterface $http, RequestFactoryInterface $requests)`、`check(string $baseUrl): ExposureResult`。試すパス（`$baseUrl` からの相対）は、`storage/config.php`、`storage/database.sqlite`、`storage/install-key.txt`、`src/Version.php`、`vendor/composer/installed.json`、`bin/cron.php`
- Produces: `final readonly class ReBuji\Install\ExposureResult`：`list<string> $exposed`（200 が返った URL）、`list<string> $unreachable`（通信できなかった URL）、`passed(): bool`（両方が空のとき）
- Produces: `ExposureStep`（`key()` は `exposure`）：アプリの公開 URL（リクエストのスキーム・ホスト・パスから求め、画面で直せる）で `check` を行う。通らなければ、どの URL が見えたかと直し方（`.htaccess` が効いているか、公開ディレクトリの外に置けるか）を出して先に進めない
- 判定：403 と 404 は「守られている」。200 は「見えている」。それ以外の状態（301、500 など）も「見えている」扱いにし、URL と状態を出す（安全側）

- [ ] **Step 1: 失敗するテストを書く**

```php
// ExposureCheckTest（偽の PSR-18 クライアント：URL ごとに状態を返す、または例外）
#[Test] public function passesWhenEveryPathIsDenied(): void
// すべて 403（または 404）→ passed() が true
#[Test] public function failsWhenTheDatabaseIsReadable(): void
// storage/database.sqlite だけ 200 → passed() が false、exposed にその URL
#[Test] public function treatsUnexpectedStatusesAsExposed(): void
// bin/cron.php が 500 → exposed に入る
#[Test] public function failsWhenAPathCannotBeReached(): void
// 通信の例外 → unreachable に入り、passed() が false

// HtaccessTest
#[Test] public function everyNonPublicDirectoryDeniesAccess(): void
// src、templates、migrations、bin、storage、tests、docs の .htaccess が存在し、'Require all denied' と 'Deny from all' の両方を含む
```

InstallFlowTest に 1 件足す：

```php
#[Test] public function cannotFinishWhileFilesAreExposed(): void
// 偽のクライアントが storage/config.php に 200 → exposure の段階で止まり、finish に進めない
```

- [ ] **Step 2: 失敗を確かめる**

Run: `vendor/bin/phpunit tests/Install tests/HtaccessTest.php`
Expected: FAIL

- [ ] **Step 3: 実装する**

`.htaccess` の中身（すべて同じ）：

```apache
<IfModule mod_authz_core.c>
    Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
    Order allow,deny
    Deny from all
</IfModule>
```

- [ ] **Step 4: 通ることを確かめる**

Run: `vendor/bin/phpunit`、`vendor/bin/psalm --no-progress`
Expected: OK、No errors found

- [ ] **Step 5: コミット**

```bash
git add -A
git commit -m "feat: refuse to finish setup while private files are reachable from the web"
```

---

### Task 6：管理者のログイン

**Files:**
- Create: `src/Auth/LoginThrottle.php`、`src/Auth/AuthMiddleware.php`、`src/Auth/LoginController.php`、`templates/login.php`、`templates/home.php`
- Modify: `src/Http/AppFactory.php`（ルートとミドルウェア）
- Test: `tests/Auth/LoginTest.php`、`tests/Auth/LoginThrottleTest.php`

**Interfaces:**
- Consumes: `AdminRepository`（Task 4）、`Session`、`AppFactory`（Task 3）、`login_attempts`（Task 2）
- Produces: `final class ReBuji\Auth\LoginThrottle`：`__construct(Database $db)`、`isBlocked(string $email, string $ip, DateTimeImmutable $now): bool`、`record(string $email, string $ip, bool $succeeded, DateTimeImmutable $now): void`。直近 15 分に、同じメールか同じ IP で失敗が 5 回あれば、ブロックする
- Produces: `AuthMiddleware`：セッションに `admin_id` が無ければ `/login` へ 302。ログインしている管理者を、リクエストの属性 `admin`（`array{id: int, name: string, email: string}`）に入れる
- Produces: ルート `GET|POST /login`、`POST /logout`、`GET /`（ホーム。Task 8 で見張りの表示を足す）
- ログインの失敗とブロックは、どちらも「メールアドレスかパスワードが違います」と同じ文言にする（ブロック中であることを相手に教えない）。ログインに成功したら `Session::regenerate()`

- [ ] **Step 1: 失敗するテストを書く**

```php
// LoginTest
#[Test] public function logsInWithTheRightPassword(): void
// POST /login（正しい値）→ 302 Location: /、セッションに admin_id、regenerate が呼ばれた（ArraySession が回数を数える）
#[Test] public function rejectsAWrongPasswordWithAGenericMessage(): void
// 違うパスワード → 200 で「メールアドレスかパスワードが違います」、admin_id は無い
#[Test] public function requiresLoginForTheHomePage(): void
// GET /（未ログイン）→ 302 Location: /login
#[Test] public function logsOut(): void
// POST /logout → セッションが空、GET / は /login へ

// LoginThrottleTest（FrozenClock）
#[Test] public function blocksAfterFiveFailuresWithinFifteenMinutes(): void
// 同じメールで 5 回失敗 → isBlocked が true、16 分後は false
#[Test] public function blocksByAddressAcrossEmails(): void
// 同じ IP で別々のメール 5 回 → true
#[Test] public function blockedLoginLooksLikeAWrongPassword(): void
// ブロック中に正しいパスワード → 200 で同じ文言、ログインしない
```

- [ ] **Step 2: 失敗を確かめる**

Run: `vendor/bin/phpunit tests/Auth`
Expected: FAIL

- [ ] **Step 3: 実装する**

IP は `REMOTE_ADDR` だけを使う（`X-Forwarded-For` は信用しない）。

- [ ] **Step 4: 通ることを確かめる**

Run: `vendor/bin/phpunit`、`vendor/bin/psalm --no-progress`、`vendor/bin/pint --test`
Expected: OK、No errors found、passed

- [ ] **Step 5: コミット**

```bash
git add -A
git commit -m "feat: add admin login with throttling and CSRF protection"
```

---

### Task 7：cron の入口とロック

**Files:**
- Create: `bin/cron.php`、`src/Cron/CronJob.php`、`src/Cron/CronJobs.php`、`src/Cron/CronRunner.php`、`src/Cron/CronOutcome.php`
- Test: `tests/Cron/CronRunnerTest.php`、`tests/Cron/CronEntryTest.php`

**Interfaces:**
- Consumes: `SystemState`、`FileLogger`、`Container`（Task 2・3）
- Produces: `interface ReBuji\Cron\CronJob`：`name(): string`、`run(DateTimeImmutable $now): void`
- Produces: `final class ReBuji\Cron\CronJobs`：`static all(ContainerInterface $c): list<CronJob>`（計画 1 は空。計画 3・4 が、設計書 6.1 の順に足す）
- Produces: `enum ReBuji\Cron\CronOutcome { case Ran; case Skipped; }`
- Produces: `final class ReBuji\Cron\CronRunner`：`__construct(string $lockPath, list<CronJob> $jobs, SystemState $state, ClockInterface $clock, LoggerInterface $logger)`、`run(): CronOutcome`
  - `flock(LOCK_EX | LOCK_NB)` で `storage/cron.lock` を取れなければ、ジョブを動かさずに `Skipped`（ログに 1 行）
  - `cron.last_started_at` を記録 → ジョブを順に動かす。1 つが例外を投げても次へ進み、`cron.last_error`（ジョブ名とメッセージ）と、ログに記録する
  - 最後に `cron.last_finished_at` を記録する。例外のあったジョブがあっても記録する（cron 自体は動いているため）。ジョブの失敗は `cron.last_error` で別に見る
- Produces: `bin/cron.php`：`$_SERVER['REQUEST_METHOD']` があれば（ウェブから開かれたら）403 を返して終わる。設定ファイルが無ければ（初期設定の前なら）何もせずに終わる。それ以外は `CronRunner` を作って `run()`

- [ ] **Step 1: 失敗するテストを書く**

```php
// CronRunnerTest（SQLite のメモリー上の DB、FrozenClock、一時ディレクトリのロック）
#[Test] public function runsJobsInOrderAndRecordsTimes(): void
// ジョブ A・B が呼ばれた順を記録 → ['A', 'B']、cron.last_started_at と cron.last_finished_at が FrozenClock の時刻
#[Test] public function aFailingJobDoesNotStopTheOthers(): void
// A が例外 → B は動く、cron.last_error に 'A' とメッセージ、cron.last_finished_at も記録、ログに 1 件
#[Test] public function skipsWhileAnotherRunHoldsTheLock(): void
// 同じロックファイルを別のハンドルで flock しておく → run() === Skipped、ジョブは呼ばれない、last_started_at は記録しない

// CronEntryTest
#[Test] public function refusesToRunFromTheWeb(): void
// 環境変数 REQUEST_METHOD=GET を付けて、`php bin/cron.php` を別プロセスで実行（CLI では環境変数が $_SERVER に入る）→ 出力に 403、終了コード 0 以外、cron.last_started_at は記録されない
#[Test] public function doesNothingBeforeSetup(): void
// 設定ファイルの無いルートで実行 → 終了コード 0、DB ファイルは作られない
```

- [ ] **Step 2: 失敗を確かめる**

Run: `vendor/bin/phpunit tests/Cron`
Expected: FAIL

- [ ] **Step 3: 実装する**

`bin/cron.php` のアプリのルートは `dirname(__DIR__)`。ロックのファイルは開いたまま保持し、終わりに `flock(LOCK_UN)` する。

- [ ] **Step 4: 通ることを確かめる**

Run: `vendor/bin/phpunit`、`vendor/bin/psalm --no-progress`
Expected: OK、No errors found

- [ ] **Step 5: コミット**

```bash
git add -A
git commit -m "feat: add the cron entry point with a lock and run records"
```

---

### Task 8：状態の URL とホームの見張り

**Files:**
- Create: `src/Monitor/Health.php`、`src/Monitor/StatusController.php`、`src/Monitor/StatusTokenController.php`
- Modify: `templates/home.php`、`src/Http/AppFactory.php`
- Test: `tests/Monitor/HealthTest.php`、`tests/Monitor/StatusUrlTest.php`、`tests/Monitor/HomeTest.php`

**Interfaces:**
- Consumes: `SystemState`（Task 2）、`CronRunner` の記録の名前（Task 7）、`AuthMiddleware`（Task 6）、`ConfigFile`
- Produces: `final class ReBuji\Monitor\Health`：`__construct(SystemState $state, ClockInterface $clock)`、`lastRunAt(): ?DateTimeImmutable`（`cron.last_finished_at`）、`isStale(): bool`（無いか、15 分より前なら true）、`lastError(): ?string`。計画 3 で「最後にフィードを確認した時刻」に切り替える
- Produces: ルート `GET /status/{token}`：
  - トークンが設定の `status.token` と `hash_equals` で一致しなければ、存在しないページと同じ 404（本文も同じ）
  - 一致して `isStale()` が false なら 200、本文は `{"status":"ok","last_run":"2026-11-05T03:12:00Z"}`
  - stale なら 503、本文は `{"status":"stale","last_run":...}`（一度も動いていなければ `null`）
  - 本文に個人情報は入れない。キャッシュさせない（`Cache-Control: no-store`）
- Produces: ホーム（`GET /`）に、最後に cron が動いた時刻（Asia/Tokyo）、15 分以上空いていれば警告、`cron.last_error`、状態の URL を出す
- Produces: `POST /settings/status-token`（ログインと CSRF が必要）：新しいトークンを作って設定ファイルに書く

- [ ] **Step 1: 失敗するテストを書く**

```php
// HealthTest（FrozenClock）
#[Test] public function isStaleWithoutARunOrAfterFifteenMinutes(): void
// 記録なし → true、14 分前 → false、16 分前 → true

// StatusUrlTest
#[Test] public function reportsOkWithTheRightToken(): void
// 直近の記録あり → 200、Content-Type が application/json、本文の status が 'ok'、Cache-Control に no-store
#[Test] public function reportsStaleAsServiceUnavailable(): void
// 20 分前 → 503、status が 'stale'
#[Test] public function wrongTokenLooksLikeAMissingPage(): void
// 違うトークン → 404、本文が GET /no-such-page の本文と同じ
#[Test] public function bodyContainsNoAdminData(): void
// admins に登録したメールアドレスと名前が、本文に含まれない

// HomeTest
#[Test] public function warnsWhenCronHasNotRunForFifteenMinutes(): void
// ログインした状態で 20 分前 → 本文に「15 分以上 cron が動いていません」
#[Test] public function regeneratesTheStatusToken(): void
// POST /settings/status-token → 設定の status.token が変わり、古いトークンの URL は 404
```

- [ ] **Step 2: 失敗を確かめる**

Run: `vendor/bin/phpunit tests/Monitor`
Expected: FAIL

- [ ] **Step 3: 実装する**

- [ ] **Step 4: 通ることを確かめる**

Run: `vendor/bin/phpunit`、`vendor/bin/psalm --no-progress`、`vendor/bin/pint --test`
Expected: OK、No errors found、passed

- [ ] **Step 5: コミット**

```bash
git add -A
git commit -m "feat: add the status URL for external monitors and the home page warning"
```

---

### Task 9：DB の更新画面、リリースの zip、README

**Files:**
- Create: `src/Admin/DatabaseUpdateController.php`、`templates/admin/database-update.php`、`bin/build-release.sh`、`build-files/vendor.htaccess`（zip の `vendor/.htaccess` になる）
- Modify: `README.md`、`src/Http/AppFactory.php`
- Test: `tests/Admin/DatabaseUpdateTest.php`

**Interfaces:**
- Consumes: `Migrator`、`Migrations`（Task 2）、`AuthMiddleware`、CSRF（Task 6）、`Version`（Task 1）
- Produces: `GET /admin/database`：今の版（`Version::CURRENT`）と、まだ当てていないマイグレーションの一覧。`POST /admin/database`：`Migrator::migrate()` を当て、当てた版を出す
- Produces: ホームに、まだ当てていないマイグレーションがあれば「DB の更新があります」と出す
- Produces: `bin/build-release.sh VERSION`：`build/re-buji-VERSION.zip` を作る
  1. `git archive HEAD` を一時ディレクトリに展開する
  2. その中で `composer install --no-dev --optimize-autoloader --classmap-authoritative --no-interaction`
  3. `tests/`、`docs/`、`.github/`、`build-files/` と開発用の設定（`phpunit.xml.dist`、`psalm.xml.dist`、`pint.json`）を消す
  4. `vendor/.htaccess` を置く
  5. `vendor/` のファイル数を数え、1,800 を超えたら失敗する
  6. zip にする（トップのディレクトリは `re-buji/`）

- [ ] **Step 1: 失敗するテストを書く**

```php
// DatabaseUpdateTest
#[Test] public function listsAndAppliesPendingMigrations(): void
// Container の `migrations` を、0001 とテスト用の '9999' の 2 つに上書き → GET に '9999'、POST で当たり、2 回目の GET には出ない
#[Test] public function requiresLogin(): void
// 未ログインの GET /admin/database → /login へ
```

- [ ] **Step 2: 失敗を確かめる**

Run: `vendor/bin/phpunit tests/Admin`
Expected: FAIL

- [ ] **Step 3: 実装する**

`Migrator` は Container の `migrations` を使う（Task 3）。

- [ ] **Step 4: zip を作って確かめる**

Run: `bin/build-release.sh 0.1.0-dev`
Expected: `build/re-buji-0.1.0-dev.zip` ができ、出力の `vendor/` のファイル数が 1,000 以下

Run: `unzip -l build/re-buji-0.1.0-dev.zip | grep -cE 're-buji/(tests|docs|\.github)/'`
Expected: `0`

Run: `unzip -l build/re-buji-0.1.0-dev.zip | grep -E 're-buji/(vendor|storage)/\.htaccess$'`
Expected: 2 行

- [ ] **Step 5: README を書く**

- できること（計画 1 の時点）と、v0.1 の目標（設計書 1 章）
- 必要なもの：PHP 8.3 以上、pdo_sqlite か pdo_mysql、cron（5 分ごと以上）
- 設置：zip の中身を FTP でアップロードする。置き方は 2 通り（全体を公開ディレクトリに置く、または `public/` の中身だけを公開ディレクトリに置いて `public/rebuji-root.php` で本体の場所を示す）。初期設定の画面の開き方
- 設置の鍵：FTP で `storage/install-key.txt` を開いて貼る
- cron：`bin/cron.php` を 5 分ごと（できれば 1 分ごと）に動かす。ロリポップは PHP ファイルを指定する形、SSH が使えるサーバーは `crontab` の例
- 見張り：状態の URL を UptimeRobot などに登録する手順。503 が返ったら cron が止まっている
- バージョンアップ：zip を上書きし、管理画面の「DB の更新」を押す
- 出典と注意（気象庁の情報を使うこと、非公式であること）は計画 3 で足す

- [ ] **Step 6: 通ることを確かめる**

Run: `vendor/bin/phpunit`、`vendor/bin/psalm --no-progress`、`vendor/bin/pint --test`、`composer normalize --dry-run`
Expected: OK、No errors found、passed、already normalized

- [ ] **Step 7: コミット**

```bash
git add -A
git commit -m "feat: add the database update page, the release zip and the README"
```

---

## 計画 1 で作らないもの（後の計画）

| 内容 | 計画 |
|---|---|
| 管理者の一覧・追加・削除、従業員の登録、CSV | 2 |
| フィードの確認、地震、送信、上限、再送、訓練、遅れた地震、取消の知らせ、設定画面（しきい値など） | 3 |
| IMAP、返事の照合、責任者の画面、照合できなかったメール、見張りのメール、初期設定のテスト送受信 | 4 |
| 状態の URL を「最後にフィードを確認した時刻」で判定する | 3（`Health` を差し替える） |

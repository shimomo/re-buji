# Re:無事 計画 19：Docker で動かす（v0.3.0）実装計画

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 技術者が `docker compose` で、手元では本物のメールなしに訓練の連絡から返信まで試し、VPS では HTTPS 付きの本番として動かせるようにする。レンタルサーバー版は今のまま動く。

**Architecture:** 環境変数 `REBUJI_ENV_CONFIG=1` のときだけ、データベースの接続とメールのアカウントを環境変数から読む（`Environment` を `ConfigFile` と `Settings` に重ねる）。ブラウザの初期設定の代わりに `bin/console`（`boot`・`admin:create`・`check`・`backup`・`status-url`）を足す。イメージは FrankenPHP 1 つで、ウェブ・HTTPS・cron（同じイメージの別サービス）を受け持つ。手元の構成はテスト用メールサーバー（GreenMail）とウェブメール（Roundcube）を足す。

**Tech Stack:** PHP 8.3、FrankenPHP（公式イメージ）、Docker Compose v2、GreenMail standalone、Roundcube、GitHub Actions（buildx、ghcr.io）。PHP の依存は増やさない。

**Spec:** `docs/superpowers/specs/2026-11-22-re-buji-docker-design.md`（以下「設計」）

**前の計画：** `docs/superpowers/plans/2026-11-22-re-buji-v0.2.3.md`（PR #22 でマージ済み）。

### 設計からの読み替え（計画を書くときにコードを見て決めたこと）

| 設計 | この計画 | 理由 |
|---|---|---|
| 7 章 `REBUJI_BASE_URL` | 作らない | 公開する URL は保存されておらず、状態の URL はリクエストのホストから作っている（`HomeController`）。メール本文に URL は無い |
| 7 章 `REBUJI_TIMEZONE` | 作らない | アプリは `Asia/Tokyo` を明示して使っており、PHP の既定のタイムゾーンに頼っていない |
| 4.1 プロキシの後ろでの `X-Forwarded-Proto` | `REBUJI_HTTPS=1` を明示したときだけ HTTPS として扱う | ヘッダーを信じる相手を決めるより狭く、設定を間違えても外から HTTPS のふりをされない |
| 4.1 HTTPS を切る切り替え | `REBUJI_DOMAIN` を空にすると HTTP の 80 番だけで待ち受け、`compose.proxy.yaml` で `127.0.0.1:8080` に出す | Compose の profile より、見るファイルが分かりやすい |
| 4.3 バックアップ | `bin/console backup <出力先>`（SQLite は `VACUUM INTO`。MySQL は「`mysqldump` を使ってください」と出して 1 で終わる） | 止めずに取れ、`sqlite3` コマンドをイメージに入れずに済む |
| 6 章 必要な環境変数がないとき止める | 止めるのは DB（MySQL のときの接続先）と、渡したメールの値の形の誤りだけ。メールの値が無いことは止めない | メールが無いことは、今もホーム画面の「確かめてほしいこと」に出る（設計 5.5 の「起動は止めない」と同じ線） |

## Global Constraints

- 計画 1〜18 の Global Constraints をすべて引き継ぐ。主なもの：
  - PHP `^8.3`、`setUp` / `tearDown` を使わない、コミットメッセージの末尾に `Co-Authored-By`、Psalm errorLevel 1、Pint
  - `src/php-version.php`、`public/index.php`、`bin/cron.php` は触らない
- **`REBUJI_ENV_CONFIG` が無いときの動きは 1 つも変えない。** 既存のテストは手を入れずに通ること
- PHP の依存（`composer.json` の `require`）を増やさない
- DB の形は変えない（マイグレーションを足さない）。新しい状態は `SystemState` のキーで持つ
- 環境変数の名前はすべて `REBUJI_` で始める。秘密の値（`*_PASSWORD`）は `<名前>_FILE` からも読める
- 管理者のパスワードを引数・環境変数・ログに出さない
- TLS を切る抜け道を作らない（手元でも、追加の CA を信頼して TLS で話す）
- zip（`bin/build-release.sh`）に `docker/`・`compose*.yaml`・`.env.example`・`.dockerignore` を入れない
- 版は `0.3.0`
- コマンドの出力と画面の文言は日本語。各タスクに書いた文言はそのとおりにする

## Review Focus

- 管理画面の「設定」で、環境変数で決まっている欄の値を開発者ツールで書き換えて保存する → 保存されず、環境変数の値のまま（Task 2 のテスト）
- `REBUJI_SMTP_PASSWORD_FILE` のファイルが末尾に改行を持つ（`echo` で作ったもの）→ 改行を除いた値を使う（Task 1 のテスト）
- 新しい版のイメージに上げて起動 → `boot` がマイグレーションを流してからウェブが立つ。`boot` 中に動いた cron は「データベースの更新待ち」で何もしない（Task 3 のテスト）
- 管理者がまだいないのに `check` を流す → 「管理者がいません。先に bin/console admin:create を流してください。」で 1 を返す（Task 5 のテスト）
- nginx の後ろ（`compose.proxy.yaml`、`REBUJI_HTTPS=1`）→ セッションのクッキーに `Secure` が付き、ログインの試行制限が利用者ごとの IP で数えられる（Task 6 の手での確認。IP がすべてプロキシのものになるなら、Caddy の `trusted_proxies` を設定する）

---

### Task 1：環境変数を読む `Environment` と、DB の接続を環境変数から読む

**Files:**
- Create: `src/Config/Environment.php`
- Modify: `src/Config/ConfigFile.php`、`src/Container.php`（`ConfigFile::class` と、新しく `Environment::class` の定義、`isHttps()`）
- Test: `tests/Config/EnvironmentTest.php`、`tests/Config/ConfigFileTest.php`、`tests/ContainerTest.php`

**Interfaces:**
- Produces:
  - `final readonly class Environment`
    - `__construct(array<string, string> $vars)`
    - `static fromProcess(): self`（`getenv()` 全体）
    - `enabled(): bool` — `REBUJI_ENV_CONFIG === '1'`
    - `get(string $name): ?string` — `$name` があればその値、無く `$name . '_FILE'` があればそのファイルの中身（末尾の `\r\n`/`\n` を 1 つ除く）。読めなければ `RuntimeException('環境変数 X_FILE のファイルを読めません：パス')`。どちらも無ければ `null`
    - `const array DATABASE = ['db.driver' => 'REBUJI_DB_DRIVER', 'db.mysql.host' => 'REBUJI_DB_HOST', 'db.mysql.port' => 'REBUJI_DB_PORT', 'db.mysql.database' => 'REBUJI_DB_NAME', 'db.mysql.username' => 'REBUJI_DB_USERNAME', 'db.mysql.password' => 'REBUJI_DB_PASSWORD']`
    - `databaseConfig(): array<string, string|int>` — `enabled()` でなければ `[]`。そうなら `db.driver`（既定 `sqlite`）、sqlite なら `db.sqlite.path => 'database.sqlite'`、mysql なら上の 5 つ（`db.mysql.port` は int、既定 3306）
  - `ConfigFile::__construct(string $path, Environment $environment = new Environment([]))`。`load()` は「ファイルの値 + `databaseConfig()` を上に重ねたもの」を返す。`save()` は `databaseConfig()` のキーを書かない（環境変数の値をファイルに写さない）
  - Container：`Environment::class => Environment::fromProcess()`、`ConfigFile` にそれを渡す。`isHttps()` は今の判定に加え、`Environment::get('REBUJI_HTTPS') === '1'` でも true

- [ ] **Step 1: 失敗するテストを書く**
  - `EnvironmentTest::isDisabledWithoutTheSwitch` — `new Environment([])` → `enabled()` false、`databaseConfig()` が `[]`
  - `EnvironmentTest::readsAValueOrItsFile` — 一時ファイルに `"secret\n"`、`['REBUJI_SMTP_PASSWORD_FILE' => パス]` → `get('REBUJI_SMTP_PASSWORD') === 'secret'`。値と `_FILE` の両方があれば値が勝つ
  - `EnvironmentTest::failsOnAnUnreadableFile` — 無いパス → `RuntimeException`、メッセージに `REBUJI_SMTP_PASSWORD_FILE`
  - `EnvironmentTest::buildsSqliteByDefault` — `['REBUJI_ENV_CONFIG' => '1']` → `['db.driver' => 'sqlite', 'db.sqlite.path' => 'database.sqlite']`
  - `EnvironmentTest::buildsMysql` — driver mysql と 5 つ → 期待の配列（port は `3306` の int、`REBUJI_DB_PORT=3307` なら 3307）
  - `ConfigFileTest::environmentWinsOverTheFile` — ファイルに `db.driver => mysql`・`status.token => abc`、環境で sqlite → `load()` は sqlite と `abc`
  - `ConfigFileTest::saveLeavesEnvironmentValuesOut` — 環境あり、`save(new Config(['status.token' => 'x'] + 環境の値))` → ファイルを `require` した配列に `db.driver` が無い
  - `ContainerTest::readsTheDatabaseFromTheEnvironment` — `Environment::class` を上書きして SQLite を指し、`config.php` には `installed_at` だけ → `Database::class` が取れる

- [ ] **Step 2: 走らせて失敗を見る** — `vendor/bin/phpunit tests/Config tests/ContainerTest.php` → クラスが無いので FAIL
- [ ] **Step 3: 上の Interfaces どおりに実装する**
- [ ] **Step 4: 全テスト・Psalm・Pint** — `composer test && composer analyse && composer lint` → すべて通る
- [ ] **Step 5: コミット** — `feat: read the database from REBUJI_* environment variables`

### Task 2：メールのアカウントを環境変数から読み、画面では変えられなくする

**Files:**
- Modify: `src/Settings/Settings.php`、`src/Settings/SettingsController.php`、`templates/settings/index.php`、`src/Mail/SmtpSettings.php`、`src/Mail/Imap/ImapSettings.php`、`src/Mail/SmtpMailSender.php`（`transport()`）、`src/Mail/Imap/ImapClient.php`（`open()`）、`src/Container.php`（`Settings::class` の定義）
- Test: `tests/Settings/SettingsTest.php`、`tests/Settings/SettingsPagesTest.php`、`tests/Mail/SmtpMailSenderTest.php`、`tests/Mail/Imap/` の ImapClient のテスト

**Interfaces:**
- Consumes: `Environment`（Task 1）
- Produces:
  - `Settings::__construct(Database $db, ClockInterface $clock, Environment $environment = new Environment([]))`
  - `const array ENVIRONMENT = ['smtp.host' => 'REBUJI_SMTP_HOST', 'smtp.port' => 'REBUJI_SMTP_PORT', 'smtp.encryption' => 'REBUJI_SMTP_ENCRYPTION', 'smtp.username' => 'REBUJI_SMTP_USERNAME', 'smtp.password' => 'REBUJI_SMTP_PASSWORD', 'mail.from_address' => 'REBUJI_MAIL_FROM_ADDRESS', 'mail.from_name' => 'REBUJI_MAIL_FROM_NAME', 'imap.host' => 'REBUJI_IMAP_HOST', 'imap.port' => 'REBUJI_IMAP_PORT', 'imap.encryption' => 'REBUJI_IMAP_ENCRYPTION', 'imap.username' => 'REBUJI_IMAP_USERNAME', 'imap.password' => 'REBUJI_IMAP_PASSWORD']`
  - `Settings::lockedBy(string $name): ?string` — `enabled()` で、その環境変数（か `_FILE`）があれば環境変数の名前、無ければ null
  - `Settings::get()` は、`lockedBy()` があれば環境変数の値を返す。`save()` は lockedBy のある名前を黙って飛ばす
  - `Settings::environmentErrors(): list<string>` — 環境変数で渡した値だけを確かめる：ポートは 1〜65535 の整数（「REBUJI_SMTP_PORT は 1〜65535 の数にしてください。」）、暗号化は `ssl` か `starttls`（「REBUJI_SMTP_ENCRYPTION は ssl か starttls にしてください。」）、送信元はメールアドレスの形（「REBUJI_MAIL_FROM_ADDRESS がメールアドレスの形ではありません。」）。IMAP も同じ形
  - `SmtpSettings` と `ImapSettings` の最後の引数に `string $caFile = ''`。`Settings::smtp()`/`imap()` が `REBUJI_MAIL_CA_FILE`（`enabled()` のときだけ）を渡す
  - `SmtpMailSender::transport()`：`$caFile !== ''` なら `$transport->getStream()->setStreamOptions(['ssl' => ['cafile' => $caFile]])`
  - `ImapClient::open()`：`$caFile !== ''` なら stream context の `ssl` に `'cafile' => $caFile`
  - Container：`Settings::class` を `new Settings(Database, Clock, Environment)` で定義
  - 画面：環境変数で決まる欄は `readonly`（select は `disabled` と、同じ値の hidden）。欄の下に「環境変数 REBUJI_SMTP_HOST で決まっています。」。パスワード欄は値を出さず「環境変数 REBUJI_SMTP_PASSWORD で決まっています。」
  - `SettingsController::save()`：`SettingsForm::read()` のあと、lockedBy のある欄を今の値（パスワードは `''`）で置き換えてから検証と保存をする

- [ ] **Step 1: 失敗するテストを書く**
  - `SettingsTest::environmentWinsWhenEnabled` — DB に `smtp.host = db.example`、環境 `REBUJI_ENV_CONFIG=1, REBUJI_SMTP_HOST=env.example` → `get('smtp.host') === 'env.example'`、`lockedBy('smtp.host') === 'REBUJI_SMTP_HOST'`、`lockedBy('threshold') === null`
  - `SettingsTest::environmentIsIgnoredWhenDisabled` — 同じ環境から `REBUJI_ENV_CONFIG` を抜く → `db.example`、`lockedBy` は null
  - `SettingsTest::saveSkipsLockedNames` — `save(['smtp.host' => 'x', 'threshold' => '6-'])` → DB の `smtp.host` は元のまま、`threshold` は `6-`
  - `SettingsTest::reportsMalformedEnvironmentValues` — `REBUJI_SMTP_PORT=abc`、`REBUJI_IMAP_ENCRYPTION=none`、`REBUJI_MAIL_FROM_ADDRESS=anpi` → 上の 3 つの文言
  - `SettingsTest::passesTheCaFile` — `REBUJI_MAIL_CA_FILE=/certs/ca.pem` → `smtp()->caFile` と `imap()->caFile` が `/certs/ca.pem`
  - `SettingsPagesTest::lockedFieldsAreReadOnly` — 環境ありの TestApp で `/settings` → `name="smtp_host"` の input に `readonly`、文言「環境変数 REBUJI_SMTP_HOST で決まっています。」、パスワードの値が HTML に出ない
  - `SettingsPagesTest::postedLockedValuesAreIgnored` — `smtp_host=evil.example` を POST → `get('smtp.host')` は環境の値、`imap.since` は変わらない
  - `SmtpMailSenderTest::trustsTheGivenCa` と ImapClient の同様のテスト — `FakeSmtpServer`/`FakeImapServer` を自己署名で立てられるなら、その CA を渡すとつながり、渡さないと失敗する。立てられないなら、ストリームのオプションに `cafile` が入ることを確かめる
- [ ] **Step 2: 走らせて失敗を見る** — `vendor/bin/phpunit tests/Settings tests/Mail` → FAIL
- [ ] **Step 3: 上の Interfaces どおりに実装する**
- [ ] **Step 4: 全テスト・Psalm・Pint**
- [ ] **Step 5: コミット** — `feat: take the mail accounts from the environment and lock them on the settings page`

### Task 3：`bin/console` と、起動時の準備 `boot`・`status-url`

**Files:**
- Create: `bin/console`（実行権限つき。`bin/cron.php` と同じく `REQUEST_METHOD` があれば 403、PHP の版の確かめ、autoload、`Console::run()` の戻り値で `exit`）
- Create: `src/Console/Console.php`、`src/Console/Command.php`、`src/Console/ConsoleIo.php`、`src/Console/BootCommand.php`、`src/Console/StatusUrlCommand.php`
- Modify: `src/Install/Steps/FinishStep.php` と `src/Monitor/StatusTokenController.php`（状態の URL の文字列を作るところを `StatusToken::generate()` に出す）
- Create: `src/Monitor/StatusToken.php`
- Test: `tests/Console/ConsoleTest.php`、`tests/Console/BootCommandTest.php`、`tests/Console/StatusUrlCommandTest.php`

**Interfaces:**
- Consumes: `Environment`、`ConfigFile`（Task 1）、`Settings::environmentErrors()`・`imap()`（Task 2）、`Migrator::pending()/migrate()`、`SystemState`
- Produces:
  - `interface Command { public function name(): string; public function summary(): string; /** @param list<string> $args */ public function run(array $args, ConsoleIo $io): int; }`
  - `final class ConsoleIo { __construct(resource $in, resource $out, resource $err); line(string): void; error(string): void; ask(string $prompt): string; secret(string $prompt): string; interactive(): bool }` — `secret()` は端末なら `stty -echo` で入力を隠す。端末でなければ 1 行読むだけ
  - `final class Console { __construct(Paths $paths, Environment $env); /** @param list<string> $argv */ run(array $argv, ConsoleIo $io): int }` — 引数なし・`help` で、コマンドの一覧（name と summary）。知らないコマンドは「知らないコマンドです：X」で 2
  - `StatusToken::generate(): string`（`bin2hex(random_bytes(16))`。`StatusTokenController` もこれを使う）
  - `boot`（`REBUJI_ENV_CONFIG` が無いと「この命令は Docker の設置でだけ使います。」で 1）。順に：
    1. MySQL なのに `REBUJI_DB_HOST`・`REBUJI_DB_NAME`・`REBUJI_DB_USERNAME` のどれかが無い → 「環境変数 X がありません。」を全部出して 1
    2. `Settings::environmentErrors()` があれば全部出して 1
    3. `storage/`・`storage/logs`・`storage/sessions` を作る
    4. マイグレーションを流す（`Migrator::migrate()`、流したものを 1 行ずつ）
    5. `config.php` に `installed_at` が無ければ `installed_at`（今）と `status.token` を足して保存
    6. IMAP があり、`SystemState` の `imap.account`（`host` と `username` を `"\n"` でつないだもの）が今と違えば、`imap.since` を今に、`imap.account` を今のものに
    7. SMTP か IMAP が無ければ「メールの設定がありません。ホーム画面にも出ます。」を出す（0 で終わる）
  - `status-url <ベースURL>` — `<ベースURL>/status/<token>` を 1 行出す（ルートは `AppFactory` の `/status/{token}`）。ベースURL の末尾の `/` は 1 つ除く。`config.php` に token が無ければ「まだ boot が済んでいません。」で 1

- [ ] **Step 1: 失敗するテストを書く**（どれも一時ディレクトリの `Paths` と SQLite、`ConsoleIo` は `php://memory`）
  - `ConsoleTest::listsCommands` / `rejectsUnknownCommand` — 一覧に `boot`・`admin:create`・`check`・`backup`・`status-url`、知らないものは 2
  - `BootCommandTest::refusesWithoutEnvConfig` — 1、上の文言
  - `BootCommandTest::firstBootPreparesEverything` — 環境に SQLite と SMTP・IMAP → 0、`config.php` に `installed_at` と 32 文字の `status.token`、`Migrator::pending() === []`、`imap.since` と `imap.account` がある
  - `BootCommandTest::secondBootKeepsTokenAndSince` — 時計を進めて 2 回目 → token と `imap.since` が変わらない
  - `BootCommandTest::changedMailboxResetsSince` — 2 回目で `REBUJI_IMAP_USERNAME` を変える → `imap.since` が 2 回目の時刻
  - `BootCommandTest::migratesPendingOnUpgrade` — 1 回目の後にマイグレーションの一覧へテスト用を 1 つ足して 2 回目 → それが流れる
  - `BootCommandTest::stopsOnMissingMysqlValues` / `stopsOnMalformedMail` — 1 と文言
  - `BootCommandTest::warnsWithoutMail` — メールの環境変数なし → 0 と警告の文言
  - `StatusUrlCommandTest::printsTheUrl` — `https://anpi.example.jp` → `https://anpi.example.jp/status/<token>`
- [ ] **Step 2: 走らせて失敗を見る**
- [ ] **Step 3: 実装する**
- [ ] **Step 4: 全テスト・Psalm・Pint**。`php bin/console` を手で流し、一覧が出ることも見る
- [ ] **Step 5: コミット** — `feat: add bin/console with boot and status-url for the Docker setup`

### Task 4：最初の管理者を作る `admin:create` と、ログイン画面の案内

**Files:**
- Create: `src/Console/AdminCreateCommand.php`
- Modify: `src/Auth/LoginController.php`（`show()` の描画に `needsAdmin`）、`templates/login.php`
- Test: `tests/Console/AdminCreateCommandTest.php`、`tests/Auth/` のログイン画面のテスト

**Interfaces:**
- Consumes: `AdminRepository::create()/count()/findByEmail()`、`PasswordPolicy::validate()`、`ConsoleIo::ask()/secret()`
- Produces:
  - `admin:create [--name=名前] [--email=アドレス]` — 足りないものは `ask()` で聞く。パスワードは `secret('パスワード（12 文字以上）：')` と `secret('もう一度：')`。端末でないときは標準入力から 1 行だけ読み、確認は省く。検証は `AdminStep::submit()` と同じ文言（名前が空、メールの形、`PasswordPolicy`、確認の不一致）。同じアドレスの管理者がいれば「このメールアドレスの管理者はもういます。」で 1。成功で「管理者 名前（アドレス）を作りました。」
  - ログイン画面：`Environment::enabled()` で管理者が 0 人なら「まだ管理者がいません。サーバーで docker compose exec app php bin/console admin:create を流して作ってください。」
- [ ] **Step 1: 失敗するテストを書く**
  - `AdminCreateCommandTest::createsFromOptionsAndPipedPassword` — `--name=山田 --email=yamada@example.jp`、標準入力 `"long-enough-pass\n"` → 0、`findByEmail` で見つかり、出力にパスワードが無い
  - `rejectsShortPassword`（`PasswordPolicy` の文言で 1）、`rejectsDuplicateEmail`、`rejectsMalformedEmail`
  - ログイン画面：環境あり・管理者 0 → 案内の文言がある。管理者 1 人 → ない。環境なし → ない
- [ ] **Step 2〜4: 失敗を見る → 実装 → 全テスト・Psalm・Pint**
- [ ] **Step 5: コミット** — `feat: create the first admin from bin/console`

### Task 5：メールを確かめる `check` と、`backup`

**Files:**
- Create: `src/Install/SetupReplyFinder.php`（`ReplyStep::submit()` の返信を探すところを移す）
- Modify: `src/Install/Steps/ReplyStep.php`（それを使う）
- Create: `src/Console/CheckCommand.php`、`src/Console/BackupCommand.php`
- Test: `tests/Install/SetupReplyFinderTest.php`、`tests/Console/CheckCommandTest.php`、`tests/Console/BackupCommandTest.php`。既存の `ReplyStep` のテストは手を入れずに通ること

**Interfaces:**
- Consumes: `Mailbox`、`MailSender`、`NotificationMail::setupMail()`、`MessageRepository::record()`、`AdminRepository::all()`、`Migrator::pending()`、`SystemState`（`imap.since`）
- Produces:
  - `final class SetupReplyFinder { __construct(Mailbox $mailbox); find(DateTimeImmutable $since, string $messageId, string $number): bool }` — 見つけたら既読にして true。`MailboxUnavailable` はそのまま投げる
  - `check [--to=アドレス] [--wait-reply] [--timeout=秒（既定 300）]` — 1 行ずつ `✓ 説明` か `✕ 説明：理由` を出す：
    1. 「データベースにつながり、更新が済んでいる」
    2. 「storage/ に書ける」
    3. 「返信用のメールボックス（IMAP）にログインできる」
    4. 「テストメールを アドレス に送った」（`--to` が無ければ最初の管理者。いなければ「管理者がいません。先に bin/console admin:create を流してください。」で 1）。続けて `message-id: <…>` の行を出す
    5. `--wait-reply` のとき、「アドレス からの返信を待っています（最長 N 秒）…」を出し、5 秒ごとに `find()`。見つかれば「✓ 返信を読めた」、時間切れは「✕ 返信が見つかりませんでした」
    - 1〜3 で失敗しても 4 まで進めるかは、前の結果に依る（DB に失敗したら止める、IMAP に失敗しても送信は試す）。1 つでも ✕ があれば 1
  - `backup <出力先のパス>` — SQLite なら `VACUUM INTO`（出力先が既にあれば「ファイルがもうあります：パス」で 1）、権限 0600、「バックアップを作りました：パス」。MySQL なら「MySQL では mysqldump でバックアップを取ってください。」で 1
- [ ] **Step 1: 失敗するテストを書く**
  - `SetupReplyFinderTest` — `FakeMailbox` に、`References` に Message-ID を含む返信 → true で既読。件名の `#番号` でも true。自動応答だけ → false で既読にしない
  - `CheckCommandTest::passesWithWorkingMail` — `RecordingMailSender` と `FakeMailbox`（返信を仕込む）を Container の上書きで → 0、5 つの ✓、`message-id:` の行
  - `failsWithoutAdmin`、`failsWhenImapIsDown`（`FakeMailbox` が投げる → ✕ と 1、送信は試している）、`timesOutWithoutReply`（`--timeout=0` で ✕ と 1）
  - `BackupCommandTest::copiesTheSqliteDatabase` — 従業員を 1 人入れて backup → 出力のファイルを開くと 1 人いる、権限 0600。`refusesExistingFile`、`refusesMysql`
- [ ] **Step 2〜4: 失敗を見る → 実装 → 全テスト・Psalm・Pint**
- [ ] **Step 5: コミット** — `feat: check the mail and back up the database from bin/console`

### Task 6：本番用のイメージと Compose

**Files:**
- Create: `docker/Dockerfile`、`docker/entrypoint.sh`、`docker/Caddyfile`、`.dockerignore`、`compose.yaml`、`compose.proxy.yaml`、`.env.example`
- Modify: `bin/build-release.sh`（`rm -rf` に `docker`・`compose.yaml`・`compose.proxy.yaml`・`compose.dev.yaml`・`.env.example`・`.dockerignore` を足す）
- Test: `tests/BuildReleaseTest.php`（zip にそれらが無いこと）

**決めたこと：**
- `Dockerfile`：多段。composer の段で `composer install --no-dev --optimize-autoloader --classmap-authoritative`。本体は `dunglas/frankenphp` の PHP 8.3 のタグを**版まで固定**して使う。`install-php-extensions pdo_sqlite pdo_mysql mbstring`。`ENV REBUJI_ENV_CONFIG=1`。アプリは `/app`、ドキュメントルートは `/app/public`。root でないユーザー（`rebuji`、UID 1000）で動かし、`setcap cap_net_bind_service=+ep` で 80/443 を開けるようにする。`/app/storage`・`/data`・`/config` をそのユーザーのものにする。`HEALTHCHECK` は `/login` に 200
- `entrypoint.sh`：引数が `web`（既定）なら `php bin/console boot` が 0 のときだけ `frankenphp run --config /etc/caddy/Caddyfile`。`cron` なら `while :; do php bin/cron.php; sleep 60; done`（重なりは `CronRunner` の `cron.lock` が防ぐ。`boot` 前は `bin/cron.php` が「初期設定が終わるまで」「更新待ち」で何もしない）。それ以外は `exec "$@"`
- `Caddyfile`：`{$SERVER_NAME}` のサイトで `root * /app/public`、`php_server`。`/storage`・`/vendor` などは `public/` の外なので出ない
- `compose.yaml`：`app`（`SERVER_NAME=${REBUJI_DOMAIN:?REBUJI_DOMAIN にドメインを書いてください}`、`80:80`・`443:443`・`443:443/udp`、ボリューム `storage`→`/app/storage`・`caddy_data`→`/data`・`caddy_config`→`/config`、`env_file: .env`、`restart: unless-stopped`）、`cron`（同じイメージ・`command: cron`・`storage` だけ・`depends_on: app: condition: service_healthy`）。イメージは `ghcr.io/shimomo/re-buji:${REBUJI_VERSION:-0.3.0}`、`build: {context: ., dockerfile: docker/Dockerfile}` も書く
- `compose.proxy.yaml`：`app` の `SERVER_NAME=:80`、ポートを `127.0.0.1:8080:80` だけに（`ports: !override`）、`REBUJI_HTTPS=1`
- `.env.example`：Task 1・2 の環境変数を全部、日本語の説明つきで。`REBUJI_VERSION=0.3.0`（`latest` は書かない）。パスワードは `_FILE` でも渡せることを書く

- [ ] **Step 1: `BuildReleaseTest` に、zip に Docker のファイルが無いことの確かめを足し、失敗を見る**
- [ ] **Step 2: `build-release.sh` を直し、通るのを見る**
- [ ] **Step 3: 上のファイルを書く**
- [ ] **Step 4: 手で確かめる** — `docker compose -f compose.yaml -f compose.proxy.yaml up -d --build`（`.env` は見本の写しに SQLite だけ）→ `curl -sI http://127.0.0.1:8080/login` が 200、`docker compose exec app ls -l storage/` で `config.php` が 0600 と `rebuji` の持ち物、`docker compose exec app php bin/console admin:create` が対話で動く、cron のログに 1 分ごとの実行、ブラウザで `/install` が 404。`REBUJI_HTTPS=1` でログインした応答の `Set-Cookie` に `Secure`。ログインに 1 回失敗したあと、ログの IP がプロキシの IP なら Review Focus のとおり直す
- [ ] **Step 5: コミット** — `feat: add the Docker image and Compose files for running on a VPS`

### Task 7：手元で試す構成と、手順書

**Files:**
- Create: `compose.dev.yaml`、`docker/dev/make-certs.sh`、`docker/dev/employees.csv`、`docker/dev/reply.php`、`docker/dev/e2e.sh`、`docs/docker.md`
- Modify: `README.md`

**決めたこと：**
- `compose.dev.yaml`（`compose.yaml` に重ねる）：
  - `certs`：一度だけ動く。`make-certs.sh` が、無ければ CA と `mail` 用の証明書（SAN `DNS:mail`）を作り、`/certs/ca.pem` と `/certs/mail.p12`（パスワード `rebuji-dev`）を置く。ボリューム `dev_certs`。秘密鍵はリポジトリに入れない
  - `mail`：`greenmail/standalone`（版を固定）。`-Dgreenmail.setup.test.all -Dgreenmail.hostname=0.0.0.0 -Dgreenmail.auth.disabled -Dgreenmail.tls.keystore.file=/certs/mail.p12 -Dgreenmail.tls.keystore.password=rebuji-dev`。どの宛先も外へ出さず中のメールボックスに入れることを、builder が確かめる（`auth.disabled` でログインしたアドレスのメールボックスが読めない版なら、`-Dgreenmail.users` で見本の人を作る）
  - `webmail`：`roundcube/roundcubemail`（版を固定）、IMAP `mail:3143`・SMTP `mail:3025`（ウェブメールは手元の確認用なので平文の口でよい。Re:無事 は TLS の口だけを使う）、`8081:80`
  - `app`：`SERVER_NAME=:80`、`ports: !override` で `8080:80`、`REBUJI_SMTP_HOST=mail`・`_PORT=3465`・`_ENCRYPTION=ssl`・`_USERNAME=anpi@example.test`・`_PASSWORD=dev`、`REBUJI_MAIL_FROM_ADDRESS=anpi@example.test`、`REBUJI_IMAP_HOST=mail`・`_PORT=3993`・`_ENCRYPTION=ssl`、`REBUJI_MAIL_CA_FILE=/certs/ca.pem`、`dev_certs` を `/certs` に読み取りだけで。`env_file` は使わない（`.env` が無くても動く）。`cron` も同じメールの環境変数と `/certs`
  - `compose.yaml` の `REBUJI_DOMAIN` の必須を、dev では `SERVER_NAME` の上書きで外す
- `employees.csv`：見出しは README の CSV の形。`山田太郎 taro@example.test 1310100`、`佐藤花子 hanako@example.test 2710000` の 2 人
- `reply.php`：`php docker/dev/reply.php <送り主> <宛先> <Message-ID>` で、`mail:3025` に `In-Reply-To`・`References` を付けた空の返信を SMTP で送る（CI と手での確認用）
- `e2e.sh`：`compose -f compose.yaml -f compose.dev.yaml up -d --build` → `app` の healthy を待つ → `admin:create --name=テスト --email=admin@example.test`（標準入力でパスワード）→ `check --to=admin@example.test --wait-reply --timeout=120` をバックグラウンドで流し、出力から `message-id:` を拾って `reply.php` で返信 → `check` の終了コードで終わる。最後に `docker compose down -v`
- `docs/docker.md`：設計 3 章の手元の流れ（起動 → 管理者 → ログイン → `docker/dev/employees.csv` を取り込む → 訓練 → `http://localhost:8081` に `taro@example.test`（パスワードは何でもよい）で入って返信 → 1 分ほど待つ → 責任者の画面で「無事」）、4 章の VPS（`.env`、DNS、80/443、`admin:create`、`check --wait-reply`、`status-url` を監視サービスへ）、nginx の後ろ（`compose.proxy.yaml` と nginx の設定例）、更新（`REBUJI_VERSION` を上げて `pull` と `up -d`）、バックアップと戻し方（`backup` で取り、`storage` ボリュームの `database.sqlite` を置き換える）、MySQL を使うとき。手元の構成ではメールが外へ出ないことを冒頭に書く
- `README.md`：「設置」を「Docker で動かす（技術者向け、おすすめ）」と「レンタルサーバーに置く」に分ける。前者は 5 行ほどで `docs/docker.md` へ。NOTE の「v0.1.0 が最初の版です…」の段落に、v0.3.0 から Docker で動かせることを足す

- [ ] **Step 1: ファイルを書く**
- [ ] **Step 2: `docker/dev/e2e.sh` を流し、0 で終わるのを見る**
- [ ] **Step 3: `docs/docker.md` の手元の流れを、書いたとおりにブラウザでたどり、責任者の画面に「無事」と出るのを見る**
- [ ] **Step 4: コミット** — `feat: add a local Docker setup with a test mail server, and docs/docker.md`

### Task 8：CI、イメージの配布、リリースの手順、版

**Files:**
- Create: `.github/workflows/docker.yml`、`.github/workflows/image.yml`
- Modify: `.claude/skills/releasing-re-buji/SKILL.md`、`src/Version.php`（`0.3.0`）

**決めたこと：**
- `docker.yml`：`push`（main）と `pull_request` で、ubuntu-24.04 で `docker/dev/e2e.sh` を流す。ほかのワークフローと同じ書き方（名前、`actions/checkout@v7`）
- `image.yml`：タグ `v*` の push で、`docker/setup-qemu-action`・`setup-buildx-action`・`login-action`（`GITHUB_TOKEN`、`packages: write`）・`build-push-action` で `linux/amd64,linux/arm64` を作り、`ghcr.io/shimomo/re-buji:<版>`（タグから `v` を除いたもの）と `:latest` を上げる。作るのはタグのコミット
- スキルの手順 7 の前に「**イメージを確かめる。** `gh run list --workflow image.yml --limit 1` が success になるのを待ち、`docker manifest inspect ghcr.io/shimomo/re-buji:{版}` に amd64 と arm64 があること。失敗なら作者に伝える（タグは付け直さない）」を足す。ノートのひな形の「上げる」に、Docker の人は `REBUJI_VERSION` を上げて `docker compose pull && docker compose up -d` と書く行を足す
- 版を `0.3.0` に

- [ ] **Step 1: ファイルを書き、`composer test && composer analyse && composer lint`**
- [ ] **Step 2: PR で `docker.yml` が通るのを見る**（`image.yml` はリリースのときに確かめる）
- [ ] **Step 3: コミット** — `chore: build and publish the Docker image, and bump to 0.3.0`

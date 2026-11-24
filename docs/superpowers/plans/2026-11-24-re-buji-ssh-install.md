# Re:無事 計画 32：SSH のスクリプトで設置する 実装計画

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** SSH で `install.sh` を 1 回流すと、ファイルを置くところから DB、管理者、メール、返信、公開の確かめ、cron までを済ませた Re:無事 ができるようにし、v0.4.0 を出せる状態にする。

**Architecture:**
- `bin/install.sh`（sh）が php を探し、zip を取って確かめ、`--root` に展開して `bin/console install` に引き渡す。`bin/upgrade.sh` と `bin/console upgrade:install` の分け方と同じ
- `bin/console install`（`InstallCommand`）は、画面の初期設定の段（`DatabaseStep`、`AdminStep`、`MailStep`、`FinishStep`）を、コンソール用に作った `InstallContext` でそのまま呼ぶ。返信の見つけ方は `SetupReplyFinder`、公開の確かめは `ExposureCheck` に足す `reach()` と `files()`
- 手がかり（`ProbeController`）は、`?from=console` でウェブ側の様子を JSON で返し、`?from=browser` で開かれたことを `storage/` に書く。`?from=` が無ければ今と同じ答え
- 新しい部品：`SetupFile`、`WebSide`、`ProbeAnswer`／`ProbeOutcome`、`ProbeVisit`、`PublicDir`、`Crontab`、`ConsoleExposure`、`RebuildingContainer`

**Tech Stack:** 計画 31 と同じ。依存は増やさない。

**Spec:** `docs/superpowers/specs/2026-11-24-re-buji-ssh-install-design.md`（以下「設計」）。元の設計 6.3（設置と初期設定）

**前の計画：** `docs/superpowers/plans/2026-11-24-re-buji-hash-tests-and-icons-script.md`（計画 31、PR #36 でマージ済み。版は 0.3.9）

| タスク | 中身 | 設計 |
|---|---|---|
| 1 | 設定ファイルを読む（`SetupFile`） | 3.3 |
| 2 | 手がかりがウェブ側の様子を返す（`WebSide`、`ProbeAnswer`、`ProbeVisit`、`ProbeController`、`ExposureCheck`、`PackagePath`） | 3.6 の 6 |
| 3 | 公開ディレクトリに置く（`PublicDir`） | 3.4 |
| 4 | crontab に足す（`Crontab`） | 3.8 |
| 5 | コンソールでの公開の確かめ（`ConsoleExposure`） | 3.6 の 6 |
| 6 | `bin/console install`（`InstallCommand`、`RebuildingContainer`、`InstallContext::forConsole()`） | 3.1、3.6〜3.8、4 章 |
| 7 | `bin/install.sh` | 3.2、3.4、3.5、3.6 の 1〜3 |
| 8 | リリースに `install.sh` を載せる | 4 章 |
| 9 | 文書と版（0.4.0） | 5 章 |

## Global Constraints

- 計画 1〜31 の Global Constraints をすべて引き継ぐ。主なもの：
  - PHP `^8.3`、`setUp` / `tearDown` を使わない、コミットメッセージの末尾に `Co-Authored-By`、Psalm errorLevel 1、Pint
- 依存は増やさない。
- **画面の初期設定と Docker の動きを変えない。** 今ある `tests/Install/*`、`tests/Console/CheckCommandTest.php`、`tests/Console/UpgradeInstallCommandTest.php` は、中身を変えずに通る（`tests/Support/FakeHttpClient.php` に足すのは、今の答えを変えない足し算だけ）。
- `installed_at` を書くのは `FinishStep::submit()` だけで、`InstallCommand` では返信を読めた後にだけ呼ぶ（設計 3.6）。
- **`install.sh` から `bin/console install` に渡す引数の形は、次の 4 つで固定し、今後変えない**（設計 4 章）：`--public=<公開ディレクトリ>`、`--url=<公開する URL>`、`--config=<設定ファイル>`、`--php=<cron で使う php>`。どれも `=` で値をつなぐ。秘密は渡さない。
- 利用者に見せる文言は、各タスクに書いたとおりにする（テストが押さえる）。
- 版は `0.4.0`（Task 9）。**[判断]** 機能を足す版なので、2 つめの数を上げる。外れていたら（作者が別の番号を望む）、Task 9 の 4 か所と、Task 7 のメッセージの「0.4.0 以降」を直す。
- **[判断]** 画面の各段は、`InstallContext::forConsole()`（公開する URL から作ったリクエストと、メモリのセッション）を渡して、そのまま呼ぶ。設計 4 章の「写して二つにしない」を、段のクラスに手を入れずに満たせる。外れていたら（段が、コンソールのリクエストに無いもの、たとえば `SCRIPT_FILENAME` を読むようになる）、コンソールで黙って違う動きになる。Task 6 の通しのテストが、ふつうの流れを押さえる。
- **[判断]** メールの段の `Mailbox` と `MailSender` は、取るたびに作り直す（`RebuildingContainer`）。どちらも作ったときのメールの設定を持ち続けるので、聞き直した設定で試すには作り直しが要る。コンテナのほかの部品はそのまま。外れていたら（作り直しが効かない）、聞き直しても前の設定で試し続ける。Task 6 の `asksForTheMailAgainAfterAFailure` が押さえる。
- **[判断]** 手がかりは、`?from=console` と `?from=browser` で答え方を変える。`?from=` が無ければ今と同じ（nonce だけ）。答えるのは今と同じく、保存した nonce と合うときだけ。
- **[判断]** `install.sh` は、`bin/upgrade.sh` の「GitHub に聞いて zip を取り、sha256 を確かめる」部分を写して持つ。`install.sh` は 1 ファイルだけで取られ、sh には読み込みの仕組みが無いため。外れていたら（片方だけ直す）、2 つの確かめがずれる。両方のコメントに、もう一方と同じであることを書く。
- **[判断]** 値を確かめる問い（URL、cron の間隔、管理者、メール、MySQL）は、3 回まで聞き直し、それでも通らなければ「同じコマンドで、もう一度流せます」と出して止まる。入力がパイプやメモリなら、読み終えたあとは空の答えが続くので、回数で終わらせる。
- **[判断]** MySQL を選べるのは、設定ファイルの `REBUJI_DB_DRIVER=mysql` か、前の回に保存した MySQL の値だけ（設計 3.6 の 5 のとおり）。対話だけなら SQLite。
- **[判断]** cron の間隔は 1〜10 分。見張りの URL は、15 分（`Health::STALE_AFTER_MINUTES`）確かめられていないと 503 を返すので、それより短くする。
- **[判断]** 待つ時間：ブラウザで開かれるのを 10 分（2 秒ごとに見る）、返信を 5 分ずつ（5 秒ごと。`CheckCommand` と同じ）。数えるのは眠った時間だけ（`CheckCommand` と同じ）。
- **[判断]** 公開ディレクトリには、`rebuji-root.php` を最初に置き、`index.php` を最後に置く。途中で止まっても、次の回に「この Re:無事 のもの」と分かり、置き直せる。

## Review Focus

1. **dash と FreeBSD の sh。** `bin/install.sh` に bash だけの書き方が無いこと、`set -u` で `HOME` や `PHP` が無いときに分かる理由で止まること。テストは Linux の `sh`（dash）で流す（Task 7）。FreeBSD の sh では流せないので、目で確かめる。
2. **途中で止まる・SSH が切れる。** 各段で止めて流し直すと続きから進むこと（Task 6 の `continuesFromWhereItStoppedWithoutASecondAdmin`）。`installed_at` を書いた後、cron を書く前に切れると、流し直しても「設置を終えています」で止まり、cron の行は足されない（見張りの URL が 15 分で 503 を返すので気づける）。ここは、cron に書けなかったときと同じく案内が出ることを Task 6 の `stillFinishesWhenCrontabCannotBeWritten` が押さえる。
3. **返信を待つあいだにメールボックスが落ちる・遅い。** 待っているあいだの `MailboxUnavailable` は、理由を出して「もう少し待ちますか？」に進む（Task 6 の `reportsAMailboxThatFailsDuringTheWait`）。1 回の問い合わせにかかる時間は 5 分に数えない。
4. **本物のウェブサーバー。** Apache の書き換え（`public/.htaccess` の `[QSA]`、全体を置くときの直下の `.htaccess`）を通っても `?from=console` が残ること、ホストの「常時 SSL」の転送。アプリまで届いた後は Task 2 のテストが押さえる。Apache の部分は、作者のさくらでの通しの確かめ（Task 9）で見る。
5. **Docker 用に書いた `.env` の読み方の違い。** Compose が読むのに、この読み方が違うもの（`${VAR}` の展開、引用符の中の `\"`）。値は書いたとおりに読み、使った値を 1 行で示す。CRLF の行と、`${VAR}` をそのまま読むことを Task 1 の `readsLinesTheWayTheyAreWritten` が押さえる。

---

### Task 1：設定ファイルを読む（`SetupFile`）

**Files:**
- Create: `src/Install/SetupFile.php`
- Test: `tests/Install/SetupFileTest.php`

**Interfaces:**
- Consumes: `Environment`（`_FILE` の読み方）、`Settings::ENVIRONMENT`、`SettingsForm::FIELDS`
- Produces（Task 6 が使う）：
  ```php
  final readonly class SetupFile
  {
      public const array DATABASE;      // DatabaseStep の入力の名前 => 設定ファイルの名前
      public const array DOCKER_ONLY;   // ['REBUJI_VERSION', 'REBUJI_DOMAIN', 'REBUJI_MAIL_CA_FILE', 'REBUJI_HTTPS', 'REBUJI_TRUSTED_PROXIES']
      public static function read(string $path): self;                    // 読めなければ RuntimeException('設定ファイルを読めません：' . $path)
      public static function parse(string $contents, string $path = ''): self;
      public function path(): string;
      /** @return array<string, string> DatabaseStep の入力（driver、mysql_host、mysql_port、mysql_database、mysql_username、mysql_password）のうち、ファイルにあるもの */
      public function database(): array;
      /** @return array<string, string> MailStep のフォームの名前（smtp_host など。SettingsForm::FIELDS のキー）のうち、ファイルにあるもの */
      public function mail(): array;
      /** @return list<string> 使わない名前と読めない行の知らせ。ファイルの順 */
      public function notices(): array;
      /** 使う名前と値を 1 行で。パスワードは ********。使う名前が無ければ '' */
      public function summary(): string;
      /** パスワード（REBUJI_DB_PASSWORD、REBUJI_SMTP_PASSWORD、REBUJI_IMAP_PASSWORD）を、_FILE でなく直接書いているか */
      public function holdsPassword(): bool;
      /** ファイルが、グループかほかの利用者に読める権限か（parse() で作ったものは false） */
      public function readableByOthers(): bool;
  }
  ```

読み方（設計 3.3。細部は設計 7 章で builder に任された）：
- 行は `\n` で分け、行末の `\r` を落とす。前の空白を落とし、空の行と `#` で始まる行は読まない。先頭の `export ` は落とす。
- `名前=値`（名前は `[A-Za-z_][A-Za-z0-9_]*`）。それ以外の行は、`設定ファイルの {N} 行目を読めません。` を知らせに足す。
- 値は前後の空白を落とす。`"` か `'` で始まり同じ引用符で終わるなら、その内側をそのまま使う。引用符が無ければ、空白に続く `#` から後（行の中のコメント）を落とす。`${VAR}` は展開しない。
- 値が空なら、書いていないのと同じ。同じ名前が 2 度あれば、後のもの。
- 読む名前は、`DATABASE` の 6 つと、`Settings::ENVIRONMENT` の 12 個。`名前_FILE` があれば、そのファイルから読む（`new Environment($vars)->get($name)` と同じ読み方。最後の改行を 1 つ落とす）。読めなければ `RuntimeException('設定ファイルの ' . $name . '_FILE が指すファイルを読めません：' . $path)`。`parse()` のときに読む。
- 知らせ：空でない値の名前ごとに、ファイルの順で
  - `DOCKER_ONLY` にある（`_FILE` で終わるかより先に見る）→ `設定ファイルの {名前} は Docker だけで使う設定なので、使いません。`
  - 読む名前か、その `_FILE` → 使う（知らせない）
  - ほかの `REBUJI_` で始まる名前 → `設定ファイルの {名前} は知らない名前なので、使いません。`
  - `REBUJI_` で始まらない名前 → 何も言わない
- `summary()`：`設定ファイルから使います：` に、使う名前をファイルの順に `名前=値` で `、` でつなぐ。`_PASSWORD` で終わる名前の値は `********`（`_FILE` の名前はファイルの場所をそのまま）。

- [ ] **Step 1: テストを書く**

```php
#[Test] public function readsEveryLineOfEnvExample(): void
// SetupFile::parse(file_get_contents('.env.example'))
// database() === ['driver' => 'sqlite']、mail() === []
// notices() === ['設定ファイルの REBUJI_VERSION は Docker だけで使う設定なので、使いません。']（REBUJI_DOMAIN= は空なので言わない）
// summary() === '設定ファイルから使います：REBUJI_DB_DRIVER=sqlite'

#[Test] public function treatsAnEmptyValueAsNotGiven(): void
// "REBUJI_SMTP_HOST=\nREBUJI_SMTP_PORT=465\nREBUJI_SMTP_PORT=587\n" → mail() === ['smtp_port' => '587']

#[Test] public function readsLinesTheWayTheyAreWritten(): void
// "export REBUJI_SMTP_HOST=smtp.example.jp # 送信\r\n"
// . "REBUJI_MAIL_FROM_NAME=\"安否 確認\"\r\n"
// . "REBUJI_IMAP_HOST='imap.example.jp'\n"
// . "REBUJI_SMTP_USERNAME=\${USER}\n"
// mail() === ['smtp_host' => 'smtp.example.jp', 'smtp_username' => '${USER}', 'mail_from_name' => '安否 確認', 'imap_host' => 'imap.example.jp']
//   （キーの順は問わない：assertEqualsCanonicalizing か ksort して比べる）

#[Test] public function mapsTheDatabaseNames(): void
// REBUJI_DB_DRIVER=mysql、HOST=db.example.jp、PORT=3307、NAME=anpi、USERNAME=anpi、PASSWORD=pw
// database() === ['driver' => 'mysql', 'mysql_host' => 'db.example.jp', 'mysql_port' => '3307', 'mysql_database' => 'anpi', 'mysql_username' => 'anpi', 'mysql_password' => 'pw']（順は問わない）

#[Test] public function readsAPasswordFromAFile(): void
// 一時ファイルに "s3cret\n"。"REBUJI_SMTP_PASSWORD_FILE={そのパス}\n"
// mail()['smtp_password'] === 's3cret'、holdsPassword() === false、summary() に '{そのパス}' が入り、's3cret' は入らない
// 無いファイルを指すと RuntimeException、メッセージに 'REBUJI_SMTP_PASSWORD_FILE' を含む

#[Test] public function notesTheNamesItDoesNotUse(): void
// "REBUJI_DOMAIN=anpi.example.jp\nREBUJI_MAIL_CA_FILE=/certs/ca.pem\nREBUJI_SMTP_HOTS=typo\nCOMPOSE_PROJECT_NAME=x\nnot a line\n"
// notices() === [
//   '設定ファイルの REBUJI_DOMAIN は Docker だけで使う設定なので、使いません。',
//   '設定ファイルの REBUJI_MAIL_CA_FILE は Docker だけで使う設定なので、使いません。',
//   '設定ファイルの REBUJI_SMTP_HOTS は知らない名前なので、使いません。',
//   '設定ファイルの 5 行目を読めません。',
// ]

#[Test] public function hidesPasswordsInTheSummary(): void
// "REBUJI_SMTP_HOST=smtp.example.jp\nREBUJI_SMTP_PASSWORD=pw\n"
// summary() === '設定ファイルから使います：REBUJI_SMTP_HOST=smtp.example.jp、REBUJI_SMTP_PASSWORD=********'
// holdsPassword() === true

#[Test] public function saysWhenOthersCanReadIt(): void
// 一時ファイルに "REBUJI_SMTP_PASSWORD=pw\n"。chmod 0644 → read()->readableByOthers() === true。chmod 0600 → false
// 無いパスを read() → RuntimeException('設定ファイルを読めません：…')
```

- [ ] **Step 2: 落ちることを確かめる**

Run: `vendor/bin/phpunit tests/Install/SetupFileTest.php`
Expected: FAIL（`SetupFile` が無い）

- [ ] **Step 3: `src/Install/SetupFile.php` を書く**

メールの名前の対応は、`Settings::ENVIRONMENT`（設定の名前 => 変数の名前）と `SettingsForm::FIELDS`（フォームの名前 => 設定の名前）から作り、ここで名前を書き直さない。

- [ ] **Step 4: 通ることを確かめる**

Run: `vendor/bin/phpunit tests/Install/SetupFileTest.php && composer analyse && composer lint`
Expected: OK、No errors、passed

- [ ] **Step 5: コミット**

```bash
git add src/Install/SetupFile.php tests/Install/SetupFileTest.php
git commit -m "feat: read the SSH installer's settings file in Docker's .env names"
```

---

### Task 2：手がかりがウェブ側の様子を返す

**Files:**
- Create: `src/Install/WebSide.php`、`src/Install/ProbeOutcome.php`、`src/Install/ProbeAnswer.php`、`src/Install/ProbeVisit.php`
- Modify: `src/Install/ProbeController.php`、`src/Install/ExposureCheck.php`、`src/Install/PackagePath.php`、`src/Install/InstallContext.php`（`packageUrl()`）、`src/Install/Steps/ExposureStep.php`（見えていたときの文言を `ExposureCheck::exposedErrors()` から取る。文言は変えない）
- Modify: `tests/Support/FakeHttpClient.php`
- Test: `tests/Install/ProbeTest.php`（新規）、`tests/Install/ExposureCheckTest.php`、`tests/Install/PackagePathTest.php`

**Interfaces:**
- Produces（Task 5、6 が使う）：
  ```php
  final readonly class WebSide
  {
      /** @param list<string> $drivers ウェブの PHP にある PDO のドライバー（'sqlite'、'mysql' のうち） */
      public function __construct(public ?string $packagePath, public array $drivers, public bool $storageWritable);
      public static function detect(array $server, Paths $paths): self;   // PackagePath::detect($server, $paths->root())、extension_loaded('pdo_' . …)、is_dir && is_writable(storage)
      public function answer(string $nonce): string;                     // JSON：{"nonce":…,"package_path":…|null,"drivers":[…],"storage_writable":true|false}
      public static function fromAnswer(string $body, string $nonce): ?self;  // nonce が合い、型がすべて合うときだけ
  }
  enum ProbeOutcome { case Reached; case Unreachable; case Refused; case Redirected; }
  final readonly class ProbeAnswer
  {
      public ProbeOutcome $outcome; public string $detail; public ?WebSide $web;
      public static function reached(WebSide $web): self;          // detail ''
      public static function unreachable(string $why): self;      // 接続そのものの失敗
      public static function refused(string $why): self;          // 「この Re:無事」でない HTTP の答え
      public static function redirected(string $location): self; // detail は Location
  }
  final class ProbeVisit
  {
      public const string FILE = 'install-probe-visit.json';
      public function __construct(Paths $paths);
      public function open(): string;                        // 新しい nonce を手がかりのファイルに書き、前の記録を消して、nonce を返す。書けなければ RuntimeException
      public function record(string $nonce, WebSide $web): bool;  // 一時ファイルに書いて rename。書けなければ false
      public function seen(string $nonce): ?WebSide;         // その nonce の記録、無ければ null
      public function close(): void;                         // 手がかりのファイルと記録を消す
  }
  // ExposureCheck に足す
  public function reach(string $publicUrl): ProbeAnswer;
  public function files(string $base, array $files = self::PATHS): ExposureResult;   // probeFailure は null
  /** @return list<string> */ public static function exposedErrors(ExposureResult $result): array;  // ExposureStep の今の 2 種類の文言
  // PackagePath に足す
  public static function url(?string $path, string $publicUrl): string;  // InstallContext::packageUrl() の今の組み立て
  ```

決めること：
- `ProbeController::show()`：保存した nonce と合わなければ今と同じく 404。合えば `$request->getQueryParams()['from']` で分ける。
  - `console`：200、`Content-Type: application/json`、`Cache-Control: no-store`、本文は `WebSide::detect($request->getServerParams(), $paths)->answer($stored)`
  - `browser`：`ProbeVisit::record()` で書く。200、`text/plain; charset=utf-8`、no-store。書けたら `Re:無事 に届きました。SSH の画面に戻ってください。`、書けなければ `Re:無事 に届きましたが、storage/ に書けないため、SSH の画面に伝えられませんでした。storage/ の権限を確かめてください。`（どちらも末尾に改行）
  - それ以外（無し、ほかの値）：今と同じく nonce だけ
- `ExposureCheck::reach()`：nonce を手がかりのファイルに書き（`check()` と同じ）、`{publicUrl}/install/probe/{nonce}?from=console` を GET し、`finally` でファイルを消す。
  - `ClientExceptionInterface` → `unreachable($e->getMessage())`
  - 301・302・303・307・308 で `Location` がある → `redirected(Location)`
  - 200 以外 → `refused('状態 ' . $status)`。答えが `text/plain` で本文があれば、`'：' .` 本文の 1 行目（前後の空白を落とし、200 文字まで）を足す（ウェブの PHP が古いときの `index.php` の理由が見える）
  - 200 で `WebSide::fromAnswer()` が null → `refused('別の内容（状態 200）')`
  - それ以外 → `reached($web)`
- `check()` は、手がかりが通った後を `files()` に任せる形にする。答えは今と同じ（`ExposureCheckTest` が押さえる）。
- `FakeHttpClient` に 4 つめの引数 `?WebSide $webSide = null` を足す。`echoProbe` が true で、URL が `/install/probe/{hex}?from=console` で終わるなら、200、`application/json`、`($webSide ?? new WebSide(null, ['sqlite', 'mysql'], true))->answer($nonce)` で答える。`?from=` の無い手がかりへの答えは今と同じ。

- [ ] **Step 1: テストを書く**

`tests/Install/ProbeTest.php`（`TestApp::create()` のアプリに `TestApp::request()` で送る）：

```php
#[Test] public function answersTheWebSideToTheConsole(): void
// storage/install-probe.txt に nonce（'ab' × 16）
// GET /install/probe/{nonce}?from=console → 200、Content-Type が application/json で始まる、Cache-Control に no-store
// WebSide::fromAnswer(本文, nonce) が null でない。drivers に 'sqlite' を含む、storageWritable === true、packagePath === null（テストのリクエストに SCRIPT_FILENAME が無い）

#[Test] public function keepsTheInstallersAnswer(): void
// 同じ nonce で GET /install/probe/{nonce}?from=other → 本文 === nonce（`?from=` の無いときは InstallFlowTest::answersItsProbeOnlyWithTheStoredNonce が押さえる）

#[Test] public function recordsABrowsersVisit(): void
// $visit = new ProbeVisit(new Paths($root)); $nonce = $visit->open();
// GET /install/probe/{nonce}?from=browser → 200、本文に 'SSH の画面に戻ってください'
// $visit->seen($nonce) が WebSide、$visit->seen(str_repeat('cd', 16)) === null
// $visit->close() の後、storage/install-probe.txt と storage/install-probe-visit.json が無い

#[Test] public function recordsNothingForAnotherNonce(): void
// $visit->open() の後、GET /install/probe/{別の nonce}?from=browser → 404、storage/install-probe-visit.json が無い

#[Test] public function saysWhenItCannotRecordTheVisit(): void
// open() の後、chmod storage 0500（InstallFlowTest::reportsAnUnwritableStorage と同じやり方。finally で 0700 に戻す）
// GET …?from=browser → 200、本文に 'storage/ に書けないため'

#[Test] public function readsOnlyAnAnswerForItsNonce(): void
// $web = new WebSide('/re-buji', ['mysql'], false); WebSide::fromAnswer($web->answer('n1'), 'n1') == $web
// fromAnswer($web->answer('n1'), 'n2') === null、fromAnswer('n1', 'n1') === null、
// fromAnswer('{"nonce":"n1","package_path":1,"drivers":[],"storage_writable":true}', 'n1') === null、
// fromAnswer('{"nonce":"n1","package_path":null,"drivers":"sqlite","storage_writable":true}', 'n1') === null

#[Test] public function detectsWhereThePackageIsServed(): void
// WebSide::detect(['SCRIPT_FILENAME' => $root . '/public/index.php', 'SCRIPT_NAME' => '/re-buji/public/index.php'], new Paths($root))->packagePath === '/re-buji'
//   （$root に public/index.php を作っておく）
```

`tests/Install/ExposureCheckTest.php` に足す（今のテストは変えない）：

```php
#[Test] public function reachesThisCopyAndReadsItsWebSide(): void
// FakeHttpClient(403, [], true, new WebSide('/re-buji', ['sqlite'], false)) で reach('https://example.jp/anpi')
// outcome === ProbeOutcome::Reached、web->packagePath === '/re-buji'、web->storageWritable === false
// requested[0] が 'https://example.jp/anpi/install/probe/' で始まり '?from=console' で終わる。storage/install-probe.txt は消えている

#[Test] public function refusesAnotherAnswerOfTheUrl(): void
// FakeHttpClient(404, [], false) → Refused、detail === '状態 404'
// FakeHttpClient(200, [], false) → Refused、detail === '別の内容（状態 200）'
// 500・text/plain・本文 "Re:無事 には PHP 8.3 以上が必要です（今は 8.2.0）。サーバーの管理画面で PHP の版を変えてください。\n" を返すクライアント（テストの中の無名クラス）
//   → Refused、detail === '状態 500：Re:無事 には PHP 8.3 以上が必要です（今は 8.2.0）。サーバーの管理画面で PHP の版を変えてください。'

#[Test] public function reportsWhereTheUrlRedirects(): void
// 301、Location: https://example.jp/anpi/install/probe/x?from=console を返す無名クラス → Redirected、detail はその Location

#[Test] public function saysWhenItCannotConnect(): void
// FakeHttpClient(new FakeNetworkException('timed out'), [], false) → Unreachable、detail に 'timed out'

#[Test] public function judgesFilesUnderTheGivenBase(): void
// FakeHttpClient(404, ['https://example.jp/re-buji/storage/config.php' => 200])
// files('https://example.jp/re-buji', ['storage/config.php', 'src/Version.php'])
// exposed === ['https://example.jp/re-buji/storage/config.php']、probeFailure === null、手がかりは送っていない（requested が 2 件）
```

`tests/Install/PackagePathTest.php` に足す：

```php
#[Test] public function buildsThePackageUrlOnThePublicUrlsHost(): void
// PackagePath::url('/re-buji', 'https://example.jp:8443/anpi') === 'https://example.jp:8443/re-buji'
// PackagePath::url('', 'https://example.jp/anpi') === 'https://example.jp'
// PackagePath::url(null, 'https://example.jp/anpi') === 'https://example.jp/anpi'
```

- [ ] **Step 2: 落ちることを確かめる**

Run: `vendor/bin/phpunit tests/Install`
Expected: FAIL（新しいクラスとメソッドが無い）。今あるテストは通る

- [ ] **Step 3: 書く**

上の Interfaces と「決めること」のとおり。`InstallContext::packageUrl()` は `PackagePath::url(PackagePath::detect(…), $publicUrl)` を返す形にし、`ExposureStep::submit()` の見えていたときの文言は `ExposureCheck::exposedErrors($result)` から取る（どちらも答えは変わらない）。

- [ ] **Step 4: 通ることを確かめる**

Run: `vendor/bin/phpunit tests/Install tests/Http && composer analyse && composer lint`
Expected: OK、No errors、passed

- [ ] **Step 5: コミット**

```bash
git add src/Install tests/Install tests/Support/FakeHttpClient.php
git commit -m "feat: let the install probe tell the console what the web side sees, and record a browser's visit"
```

---

### Task 3：公開ディレクトリに置く（`PublicDir`）

**Files:**
- Create: `src/Install/PublicDir.php`
- Test: `tests/Install/PublicDirTest.php`

**Interfaces:**
- Consumes: `Paths`、`ConfigFile`、`Upgrade::PUBLIC_DIR`
- Produces（Task 6 が使う）：
  ```php
  final class PublicDir
  {
      public function __construct(Paths $paths);
      /** この Re:無事 の公開するファイルを置けない理由。置けるなら null */
      public function problem(string $directory): ?string;
      /** 置いて、storage/config.php の upgrade.public_dir に覚える。置いた場所の絶対パスを返す */
      public function place(string $directory, ConfigFile $config): string;
  }
  ```

決めること（設計 3.4）：
- 置けるのは、まだ無い（親はある）、空、または `rebuji-root.php` がこの Re:無事 を指しているディレクトリ。`problem()` の文言：
  - 親が無い：`{dir} の親のディレクトリがありません。`
  - ディレクトリでない：`{dir} はディレクトリではありません。`
  - 本体と重なる（同じ、どちらかがもう一方の中）：`{dir} と本体の場所（{root}）が重なっています。`
  - `rebuji-root.php` がほかを指す：`{dir} は、ほかの Re:無事（{そのパス}）の公開ディレクトリです。`
  - ほかのファイルがある：`{dir} にほかのファイルがあります（{名前}）。まだ無いか、空のディレクトリを渡してください。`。名前は並べ替えて最初の 10 個を `、` でつなぎ、11 個以上なら `、ほか {N} 件` を足す
- `place()`：無ければ 0755 で作る。`rebuji-root.php`（`"<?php\n\nreturn " . var_export($root, true) . ";\n"`、0644）→ `public/assets/`（中身ごと、権限はそのまま）→ `public/.htaccess` → `public/index.php` の順に置く（あれば上書き）。覚えるのは `realpath()` した場所で、`ConfigFile` が無ければ作る、あれば今の値に足す。

- [ ] **Step 1: テストを書く**

本体の `$root` に `public/index.php`、`public/.htaccess`、`public/assets/app.css`（中身はそれぞれ別の文字列、`app.css` は 0644）と `storage/` を作る。公開ディレクトリは `$dir = TemporaryDirectory::create()` の中。

```php
#[Test] public function placesThePublicFilesAndRemembersWhere(): void
// $public = $dir . '/www/anpi'（$dir/www は作る、anpi は無い）
// problem($public) === null。place($public, $config) === realpath($public)
// index.php、.htaccess、assets/app.css の中身が本体と同じ、assets/app.css は 0644
// (require $public . '/rebuji-root.php') === $root
// $config->load()->get('upgrade.public_dir') === realpath($public)

#[Test] public function acceptsAnEmptyDirectory(): void
// mkdir($public) → problem() === null

#[Test] public function refusesADirectoryWithOtherFiles(): void
// $public に index.html と cgi-bin/ → problem() === $public . ' にほかのファイルがあります（cgi-bin、index.html）。まだ無いか、空のディレクトリを渡してください。'
// 12 個のファイル（f01〜f12）→ 末尾が '（f01、…、f10、ほか 2 件）。まだ無いか、空のディレクトリを渡してください。'

#[Test] public function placesAgainWhereItPlacedBefore(): void
// place() の後に $public/index.php を書き換え、ほかに assets だけ残して rebuji-root.php 以外を消した状態も作る
// どちらも problem() === null、place() で index.php が本体と同じに戻る

#[Test] public function refusesAnotherCopysPublicDirectory(): void
// $public/rebuji-root.php が別のディレクトリ $other を返す → problem() === $public . ' は、ほかの Re:無事（' . $other . '）の公開ディレクトリです。'

#[Test] public function refusesAMissingParentAndAnOverlap(): void
// problem($dir . '/no/such') === $dir . '/no/such の親のディレクトリがありません。'
// problem($root . '/public') === $root . '/public と本体の場所（' . $root . '）が重なっています。'
```

- [ ] **Step 2: 落ちることを確かめる**

Run: `vendor/bin/phpunit tests/Install/PublicDirTest.php`
Expected: FAIL（`PublicDir` が無い）

- [ ] **Step 3: `src/Install/PublicDir.php` を書く**

- [ ] **Step 4: 通ることを確かめる**

Run: `vendor/bin/phpunit tests/Install/PublicDirTest.php && composer analyse && composer lint`
Expected: OK、No errors、passed

- [ ] **Step 5: コミット**

```bash
git add src/Install/PublicDir.php tests/Install/PublicDirTest.php
git commit -m "feat: place the public files of a copy into an empty public directory and remember it"
```

---

### Task 4：crontab に足す（`Crontab`）

**Files:**
- Create: `src/Install/Crontab.php`
- Test: `tests/Install/CrontabTest.php`

**Interfaces:**
- Produces（Task 6 が使う）：
  ```php
  final class Crontab
  {
      /** @param (Closure(list<string>, string): array{int, string, string})|null $run コマンドを、その入力で動かし、終わりの状態・出力・エラーを返す。テストで差し替える */
      public function __construct(?Closure $run = null);
      /** $minutes 分ごとに cron.php を動かす行 */
      public static function line(int $minutes, string $php, string $cronPath): string;
      /** crontab を使えない理由。使えるなら null */
      public function problem(): ?string;
      /** $cronPath を含む行が無ければ $line を足して true、あれば何もせず false。書けなければ RuntimeException（理由つき） */
      public function add(string $line, string $cronPath): bool;
  }
  ```

決めること（設計 3.8）：
- 読む：`['crontab', '-l']` を入力なしで動かす。状態 0 なら出力が今の中身。0 以外でエラーに `no crontab` を含む（大文字小文字を問わない）なら、空。それ以外は使えない：状態 127 なら `crontab が見つかりません。`、ほかはエラーの前後の空白を落としたもの（空なら `crontab -l が状態 {N} で終わりました。`）。
- 足す：今の中身の行を 1 つも変えず、末尾の改行をそろえてから `$line . "\n"` を足し、`['crontab', '-']` に入力として渡す。状態 0 以外なら `RuntimeException(エラーの前後の空白を落としたもの、空なら 'crontab - が状態 {N} で終わりました。')`。
- 既定の `$run`：`proc_open()` に配列のコマンドを渡す（シェルを通さない）。開けなければ `[127, '', '']`。
- `line()`：1 分なら `* * * * *`、ほかは `*/{N} * * * *`。php と cron.php の場所は、`[A-Za-z0-9_./+-]` だけなら そのまま、ほかの文字があれば `escapeshellarg()` で囲み、`%` を `\%` にする（crontab では `%` が改行になる）。

- [ ] **Step 1: テストを書く**

`$calls` に `[$command, $input]` を記録し、`crontab -l` と `crontab -` に決めた答えを返す偽の `$run` で作る。

```php
#[Test] public function addsALineAndKeepsTheOthers(): void
// crontab -l → [0, "MAILTO=me@example.jp\n0 3 * * * /home/you/backup.sh", '']（最後の改行なし）
// add('*/5 * * * * /usr/bin/php /home/you/re-buji/bin/cron.php', '/home/you/re-buji/bin/cron.php') === true
// crontab - への入力 === "MAILTO=me@example.jp\n0 3 * * * /home/you/backup.sh\n*/5 * * * * /usr/bin/php /home/you/re-buji/bin/cron.php\n"

#[Test] public function startsACrontabWhenThereIsNone(): void
// crontab -l → [1, '', "no crontab for you\n"] → add() === true、入力 === "{行}\n"、problem() === null

#[Test] public function doesNotAddTheLineTwice(): void
// crontab -l → [0, "* * * * * /usr/local/php/8.3/bin/php /home/you/re-buji/bin/cron.php\n", '']
// add(…) === false、crontab - は呼ばれていない

#[Test] public function saysWhyItCannotBeUsed(): void
// crontab -l → [127, '', ''] → problem() === 'crontab が見つかりません。'
// crontab -l → [1, '', "You (you) are not allowed to use this program (crontab)\n"] → problem() === 'You (you) are not allowed to use this program (crontab)'

#[Test] public function reportsAFailedWrite(): void
// crontab - → [1, '', "\"-\":1: bad minute\nerrors in crontab file, can't install.\n"] → add() が RuntimeException、メッセージに 'errors in crontab file' を含む

#[Test] public function buildsTheLine(): void
// Crontab::line(1, '/usr/bin/php', '/home/you/re-buji/bin/cron.php') === '* * * * * /usr/bin/php /home/you/re-buji/bin/cron.php'
// Crontab::line(5, '/opt/my php/bin/php', '/home/you/re 100%/bin/cron.php') === "*/5 * * * * '/opt/my php/bin/php' '/home/you/re 100\\%/bin/cron.php'"
```

- [ ] **Step 2: 落ちることを確かめる**

Run: `vendor/bin/phpunit tests/Install/CrontabTest.php`
Expected: FAIL（`Crontab` が無い）

- [ ] **Step 3: `src/Install/Crontab.php` を書く**

- [ ] **Step 4: 通ることを確かめる**

Run: `vendor/bin/phpunit tests/Install/CrontabTest.php && composer analyse && composer lint`
Expected: OK、No errors、passed

- [ ] **Step 5: コミット**

```bash
git add src/Install/Crontab.php tests/Install/CrontabTest.php
git commit -m "feat: add the cron line to crontab without touching the other lines or adding it twice"
```

---

### Task 5：コンソールでの公開の確かめ（`ConsoleExposure`）

**Files:**
- Create: `src/Install/ConsoleExposure.php`
- Test: `tests/Install/ConsoleExposureTest.php`

**Interfaces:**
- Consumes: Task 2 の `ExposureCheck::reach()`・`files()`・`exposedErrors()`・`PATHS`、`ProbeVisit`、`WebSide`、`ProbeAnswer`、`PackagePath::url()`、`ConsoleIo`
- Produces（Task 6 が使う）：
  ```php
  final class ConsoleExposure
  {
      public const int VISIT_WAIT_SECONDS = 600;
      /** @param Closure(int): void $sleep */
      public function __construct(ExposureCheck $check, ProbeVisit $visit, Closure $sleep);
      /** 設置を続けてよいとき true。止まるときは理由を $io->error() に出す。$driver は 'sqlite' か 'mysql' */
      public function run(string $publicUrl, string $driver, ConsoleIo $io): bool;
  }
  ```

決めること（設計 3.6 の 6）：

| 手がかりの答え | すること |
|---|---|
| Reached | ウェブ側の様子を使う |
| Redirected | 止まる：`{publicUrl} は {先} に転送されます。--url {先} を付けて、もう一度流してください。`。`{先}` は Location の `/install/probe/` より前（含まなければ Location そのまま） |
| Refused | 止まる（`[y/N]` は出さない）：`サーバーから {publicUrl} を開きましたが、この Re:無事 に届きませんでした（{detail}）。` と `URL が正しいか、その URL で公開ディレクトリが公開されているか、ウェブの PHP が 8.3 以上かを確かめてください。` |
| Unreachable | ブラウザに手伝ってもらう（下）。そのあと、ファイルは人に確かめてもらう |

- ブラウザ：`ProbeVisit::open()` の nonce で、次の 3 行を出し、`VISIT_WAIT_SECONDS` まで 2 秒ごとに `seen()` を見る。`finally` で `close()`。
  - `サーバーから {publicUrl} に接続できませんでした（{detail}）。`
  - `ブラウザで次の URL を開いてください。開かれるのを待っています（10 分まで）…`
  - `  {publicUrl}/install/probe/{nonce}?from=browser`
  - 開かれたら `✓ ブラウザで開かれました`。来なければ止まる：`10 分のあいだに、ブラウザで開かれませんでした。URL が正しいか、ウェブから storage/ に書けるかを確かめて、もう一度流してください。`
- ウェブ側の様子を確かめる（Reached でもブラウザでも）：
  - `$driver` が `drivers` に無い → 止まる：`ウェブの PHP に pdo_{driver} がありません。サーバーの管理画面で、ウェブの PHP の設定を確かめてください。`
  - `storageWritable` が false → 止まる：`ウェブの PHP から storage/ に書けません。storage/ の権限を 705 か 755 にしてください。`
- ファイル：`$base = PackagePath::url($web->packagePath, $publicUrl)`。
  - Reached：`files($base)`。見えていれば止まる（`exposedErrors()` の文言を 1 行ずつ）。接続できない URL があれば、`サーバーから次の URL を確かめられませんでした。ブラウザで開き、どれも表示されない（403 か 404 になる）ことを確かめてください：` と URL を並べて聞く。どれも無ければ `✓ この URL がこの Re:無事 に届き、データベースや設定がウェブから読めないことを確かめました`
  - ブラウザ：`ブラウザで次の URL を開き、どれも表示されない（403 か 404 になる）ことを確かめてください：` と、`PATHS` すべての URL を並べて聞く
  - 聞き方：URL は 1 行に 1 つ、前に空白 2 つ。`どれも 403 か 404 になりましたか？ [y/N] `。`y` か `yes`（大文字小文字を問わない）なら `✓ ブラウザで確かめました`。それ以外は止まる：`確かめが済んでいないので、止めます。表示された URL があれば、本体を公開ディレクトリの外に置く（--public）か、.htaccess が効くようにしてから、もう一度流してください。`

- [ ] **Step 1: テストを書く**

`$root` に `storage/`。`new ConsoleExposure(new ExposureCheck($client, new Psr17Factory(), new Paths($root)), new ProbeVisit(new Paths($root)), $sleep)`。公開する URL は `https://example.jp/anpi`、`$driver` は `'sqlite'`。

```php
#[Test] public function passesWhenTheUrlReachesThisCopyAndNothingIsExposed(): void
// FakeHttpClient() → true。出力に '✓ この URL がこの Re:無事 に届き、データベースや設定がウェブから読めないことを確かめました'
// requested に 'https://example.jp/anpi/storage/config.php' を含む（packagePath が null なら公開する URL の下）

#[Test] public function stopsOnAnotherAnswerWithoutOfferingToCheckByHand(): void
// FakeHttpClient(404, [], false) → false。エラーに '（状態 404）'、出力に '[y/N]' が無い

#[Test] public function stopsOnARedirectAndSaysWhichUrlToUse(): void
// 302、Location: https://example.jp/anpi/install/probe/x?from=console の無名クラス → false
// エラーに '--url https://example.jp/anpi を付けて'

#[Test] public function stopsWhenThePackageCanBeReadFromTheWeb(): void
// FakeHttpClient(404, ['https://example.jp/re-buji/storage/config.php' => 200], true, new WebSide('/re-buji', ['sqlite'], true)) → false
// エラーに 'ウェブから見えています：https://example.jp/re-buji/storage/config.php（状態 200）'

#[Test] public function stopsWithoutTheDatabaseDriverOrStorageOnTheWeb(): void
// WebSide(null, ['mysql'], true) → false、エラーに 'ウェブの PHP に pdo_sqlite がありません'
// WebSide(null, ['sqlite'], false) → false、エラーに 'ウェブの PHP から storage/ に書けません'

#[Test] public function asksTheBrowserWhenTheServerCannotReachItself(): void
// FakeHttpClient(new FakeNetworkException('timed out'), [], false)
// $sleep：1 回目に (new ProbeVisit(new Paths($root)))->record(trim(file_get_contents($root . '/storage/install-probe.txt')), new WebSide(null, ['sqlite'], true))
// 入力 "y\n" → true
// 出力に 'サーバーから https://example.jp/anpi に接続できませんでした（timed out）。'、'?from=browser'、'✓ ブラウザで開かれました'、
//   '  https://example.jp/anpi/storage/config.php'、'✓ ブラウザで確かめました'
// 終わった後、storage/install-probe.txt と storage/install-probe-visit.json が無い

#[Test] public function stopsWhenTheBrowserNeverOpensIt(): void
// 同じクライアント、$sleep は秒を足すだけ → false、眠った合計 === 600、エラーに '10 分のあいだに、ブラウザで開かれませんでした'、2 つのファイルが無い

#[Test] public function stopsWhenTheOwnerDoesNotConfirm(): void
// ブラウザは開く（上と同じ $sleep）、入力 "\n" → false、エラーに '確かめが済んでいないので、止めます'

#[Test] public function asksByHandForFilesItCouldNotReach(): void
// FakeHttpClient(403, ['https://example.jp/anpi/bin/cron.php' => new FakeNetworkException('reset')])、入力 "y\n" → true
// 出力に 'サーバーから次の URL を確かめられませんでした' と '  https://example.jp/anpi/bin/cron.php'
```

- [ ] **Step 2: 落ちることを確かめる**

Run: `vendor/bin/phpunit tests/Install/ConsoleExposureTest.php`
Expected: FAIL（`ConsoleExposure` が無い）

- [ ] **Step 3: `src/Install/ConsoleExposure.php` を書く**

- [ ] **Step 4: 通ることを確かめる**

Run: `vendor/bin/phpunit tests/Install/ConsoleExposureTest.php && composer analyse && composer lint`
Expected: OK、No errors、passed

- [ ] **Step 5: コミット**

```bash
git add src/Install/ConsoleExposure.php tests/Install/ConsoleExposureTest.php
git commit -m "feat: check from the console that the URL reaches this copy and nothing private is served, with no override for a wrong answer"
```

---

### Task 6：`bin/console install`

**Files:**
- Create: `src/Console/InstallCommand.php`、`src/Install/RebuildingContainer.php`
- Modify: `src/Console/Console.php`（`COMMANDS` の最後に `InstallCommand::class`）、`src/Install/InstallContext.php`（`forConsole()`）、`src/Http/ArraySession.php`（docblock：「テストと、ブラウザなしで段を流す bin/console install のための、メモリのセッション」）
- Create: `tests/Support/AnsweringMailbox.php`
- Test: `tests/Console/InstallCommandTest.php`、`tests/Install/RebuildingContainerTest.php`、`tests/Console/ConsoleTest.php`（`listsCommands` に `install` を足す）

**Interfaces:**
- Consumes: Task 1 `SetupFile`、Task 3 `PublicDir`、Task 4 `Crontab`、Task 5 `ConsoleExposure`。今ある `DatabaseStep`、`AdminStep`、`MailStep`、`FinishStep`、`InstallKey`、`SetupReplyFinder`、`SystemState`、`Settings`
- Produces（Task 7 が使う。形は Global Constraints のとおり固定）：
  ```php
  final class InstallCommand implements Command
  {
      /** @param (Closure(int): void)|null $sleep */
      public function __construct(Paths $paths, Environment $environment, ContainerInterface $container, ?Closure $sleep = null, ?Crontab $crontab = null);
      // name() === 'install'
      // summary() === 'install.sh が使う：SSH で設定を済ませ、設置を終える'
  }
  final readonly class RebuildingContainer implements ContainerInterface
  {
      public function __construct(ContainerInterface&\DI\FactoryInterface $container);
      // get(Mailbox::class) と get(MailSender::class) は $container->make()、ほかは $container->get()
  }
  // InstallContext に足す
  public static function forConsole(Paths $paths, ConfigFile $configFile, ContainerInterface $container, string $publicUrl): self;
  //   リクエスト：(new Psr17Factory())->createServerRequest('GET', $publicUrl, ['HTTPS' => https なら 'on'、でなければ ''])
  //   Url：公開する URL のパスの末尾の / を落としたもの。セッション：new ArraySession()
  ```
- `tests/Support/AnsweringMailbox.php`：`CheckCommandTest::answering()` と同じく、`RecordingMailSender` が送ったメールごとに `MailFixtures::reply('admin@example.jp', '', $messageId)` を足すメールボックス。`public bool $answering = true`（false のあいだは足さない）。`CheckCommandTest` は変えない。

**流れと文言**（設計 3.1、3.6〜3.8。順番は変えない）：

1. 引数：`--public=`、`--url=`、`--config=`、`--php=` 以外があれば、エラー `使い方：console install [--public=<公開ディレクトリ>] [--url=<公開する URL>] [--config=<設定ファイル>] [--php=<cron で使う php>]` で 2。`--php` が無ければ `PHP_BINARY`。
2. 止まる（1）：`Environment::enabled()` → `Docker では使いません。Docker では docs/docker.md の手順で設定してください。`。`storage/config.php` に `installed_at` がある → `設置を終えています。上げるときは sh {root}/bin/upgrade.sh を流してください。`
3. 設定ファイル（`--config`）：`SetupFile::read()`（例外ならそのメッセージで 1）。知らせを 1 行ずつ、`summary()` が空でなければそれを出す。`holdsPassword() && readableByOthers()` なら `注意：設定ファイルにパスワードが書いてあり、ほかの利用者にも読めます。chmod 600 {path} で、自分だけが読めるようにしてください。`
4. 公開ディレクトリ：`--public`、無ければ `storage/config.php` の `upgrade.public_dir`。あれば `PublicDir::problem()`（理由で 1）→ `place()` → `✓ {place() の返したパス} に公開するファイルを置きました`
5. URL：`--url`、無ければ `公開する URL：` と聞く（3 回まで）。形を直して使う：`http` か `https`、ホストがある、ユーザー名・クエリ・フラグメントが無い。スキームとホストは小文字、ポートはそのまま、パスの末尾の `/` は落とす。だめなら `URL は https://example.jp/anpi のように、http:// か https:// で始めてください。`（`--url` のときは 1 で止まる）。`http://` なら `注意：http の URL です。ログインのパスワードが暗号化されずに送られます。https が使えるなら、https の URL にしてください。`
6. DB：入力は「前の回に保存した `db.*`」に「設定ファイルの `database()`」を重ねたもの。`driver` が `mysql` でなければ `['driver' => 'sqlite']`。MySQL で無い値は、見出し `データベース（MySQL）` の下に `  ホスト：`、`  データベース名：`、`  ユーザー名：` と聞き、パスワードがどちらにも無ければ `  パスワード：`（`secret()`）と聞く。ポートは聞かない（無ければ `DatabaseStep` が 3306）。`DatabaseStep::submit()` が通れば `✓ データベース（SQLite）を作りました` か `✓ データベース（MySQL）につなぎ、テーブルを作りました`。落ちたら理由を 1 行ずつ出し、聞いた値があれば聞き直す（3 回まで）。聞いた値が無ければ `データベースにつなげません。正しい値を --config の設定ファイル（REBUJI_DB_*）に書いて、もう一度流してください。` で 1。
7. 公開の確かめ：`ConsoleExposure::run($url, $driver, $io)`（`ExposureCheck` はコンテナから、`ProbeVisit` は `new`、`$sleep` は渡されたもの）。false なら 1。
8. 管理者：もういれば `最初の管理者：{名前}（{メールアドレス}）はもういます`。いなければ見出し `最初の管理者` の下に `  名前：`、`  メールアドレス：`、`  パスワード（12 文字以上）：`（`secret()`）、端末なら `  もう一度：`（`secret()`。端末でなければ同じ値。`AdminCreateCommand` と同じ）。`AdminStep::validate()` の理由を 1 行ずつ出して聞き直す（3 回まで）。通れば `AdminStep::submit()`。
9. メール：見出し `送信のメール（SMTP）` と `受信のメール（IMAP）` の下に、設定ファイルに無い項目だけを、次の順で聞く（その見出しの項目を 1 つも聞かないなら、見出しも出さない）。聞き方は `{項目} [{既定}]：`、既定が無ければ `{項目}：`。Enter だけなら既定。

   | フォームの名前 | 項目 | 既定 |
   |---|---|---|
   | `mail_from_address` | `  送信元のアドレス` | 保存した値 |
   | `smtp_host` | `  サーバー名` | 保存した値 |
   | `smtp_port` | `  ポート` | 保存した値（はじめは 587） |
   | `smtp_encryption` | `  暗号化` | 保存した値（はじめは starttls） |
   | `smtp_username` | `  ユーザー名` | 保存した値、空なら送信元のアドレス |
   | `smtp_password` | `  パスワード`（`secret()`） | 保存したパスワードがあれば `前のまま`（Enter で `''` を渡し、`MailStep` が保存したものを残す） |
   | `imap_host` | `  サーバー名` | 保存した値、空なら SMTP のサーバー名 |
   | `imap_port` | `  ポート` | 保存した値（はじめは 993） |
   | `imap_encryption` | `  暗号化` | 保存した値（はじめは ssl） |
   | `imap_username` | `  ユーザー名` | 保存した値、空なら `送信と同じ`（Enter で `''`） |
   | `imap_password` | `  パスワード`（`secret()`） | 受信のユーザー名が空でないときだけ聞く。保存したものがあれば `前のまま` |

   設定ファイルの値と答えを `MailStep::submit()` に渡す（しきい値と上限は渡さず、保存した値か既定のまま）。通れば `✓ 返信用のメールボックスにログインできました` と `✓ テストメールを {mail.test_to} に送りました`。落ちたら理由を 1 行ずつ出す。聞いた項目があれば、前の答えを既定にして聞き直す（3 回まで）。聞いた項目が無ければ `設定ファイルの値を直して、もう一度流してください。` で 1。
10. cron の問い：`Crontab::problem()` が null なら、`cron を何分ごとに動かしますか？ 1 分ごとにできるサーバーなら 1 [5]：`（1〜10。だめなら `1〜10 の数を入れてください。`、3 回まで）。続けて `cron に足す行：` と `  {Crontab::line(分, php, {root}/bin/cron.php)}` を出し、`設置を終えたら、この行を cron に足しますか？ [y/N] ` と聞く。ここではまだ書かない。
11. 返信：`テストメールに返信してください。返信を待っています（5 分まで）…`。`SetupReplyFinder::find()`（since は `imap.since`、メッセージ ID と番号は `mail.test_message_id` と `mail.test_number` の下書き、`Mailbox` は `$context->container` から）を 5 秒ごとに。見つかれば `✓ 返信を読めました`。`MailboxUnavailable` なら `✕ 返信を読めませんでした：{理由}` を出し、5 分たったときと同じく `もう少し待ちますか？ [Y/n] ` と聞く。`n` か `no` なら `返信を読めていないので、設置を終えていません。同じコマンドで、続きから流せます。` で 1。ほかは、また 5 分待つ。これが最後の問い。
12. 仕上げ：`FinishStep::viewData()` で見張りの URL を得てから `FinishStep::submit()`（落ちたら理由で 1）。`✓ 設置を終えました（しきい値は{SeismicIntensity の label()}、1 時間に送る上限は {hourly_limit} 通。設定の画面で変えられます）`
13. cron を書く：10 で `y`（`yes`）なら `Crontab::add()`。足せたら `✓ cron に足しました：{行}` と `  サーバーの管理画面で cron を編集すると、この行が消えることがあります。消えても、見張りの URL が 15 分で 503 を返すので気づけます。`。もうあれば `✓ cron には、もう {root}/bin/cron.php の行があります。足していません。`。例外なら `✕ cron に足せませんでした：{理由}` と、下の案内。10 で `y` でなければ、下の案内。
    - 案内：`cron に次の行を登録してください（サーバーの管理画面か crontab -e で）：` と `  {行}`。crontab を使えず間隔を聞いていないときは 5 分の行にし、`  1 分ごとにできるサーバーなら、1 分ごとにしてください。` を足す。
14. 終わり：`見張りの URL：{URL}`、`  UptimeRobot などの監視サービスに登録してください。cron が止まると、この URL が 503 を返します。`、`ログイン：{InstallContext::baseUrl()}/`。`holdsPassword()` なら `パスワードを書いた設定ファイルは、もう要りません。消してください：{path}`。0 を返す。

聞き直しが 3 回とも通らないときは `3 回とも通らなかったので、止めます。同じコマンドで、もう一度流せます。` で 1。

`InstallContext::forConsole()` に渡すコンテナは、`$container instanceof FactoryInterface` なら `new RebuildingContainer($container)`、でなければそのまま。`Database`・`Settings`・`Mailbox`・`MailSender` は、DB の段より前にコンテナから取らない（設計 4 章）。

- [ ] **Step 1: テストを書く**

`tests/Install/RebuildingContainerTest.php`：

```php
#[Test] public function buildsTheMailServicesAnewEachTime(): void
// Container::build(new Paths($root), [Mailbox::class => static fn(): Mailbox => new FakeMailbox(), MailSender::class => static fn(): MailSender => new RecordingMailSender()])
// new RebuildingContainer($container) の get(Mailbox::class) を 2 回 → 別のもの。MailSender も同じ
// get(ClockInterface::class) を 2 回 → 同じもの。has(Paths::class) === true
```

`tests/Console/InstallCommandTest.php`。共通の作り：
- `$root = TestApp::root()` に `public/index.php`、`public/.htaccess`、`public/assets/app.css` を作る
- コンテナ：`Container::build(new Paths($root), [Environment::class => new Environment([]), ClockInterface::class => new FrozenClock(), ClientInterface::class => new LoopbackHttpClient($root), MailSender::class => $sender, Mailbox::class => $mailbox] + 足すもの)`（`LoopbackHttpClient` は本物のアプリで手がかりに答える。アプリの基準のパスが空なので、URL は `https://example.jp`）
- `new InstallCommand($paths, $env, $container, $sleep（秒を $slept に足す）, new Crontab($run))`。`$run` は `crontab -l` に決めた答え（既定は `[1, '', 'no crontab for you']`）、`crontab -` に `[0, '', '']` を返し、呼ばれ方を記録する
- 引数の既定：`['--public=' . $dir . '/www', '--url=https://example.jp', '--php=/usr/local/php/8.3/bin/php']`（`$dir/www` は作らない）
- `$answers`（設定ファイルなしの最初の回。管理者 3 行、メール 10 個、cron 2 行）：`"管理 太郎\nadmin@example.jp\nlong-enough-pass\nanpi@example.jp\nsmtp.example.jp\n\n\n\nsmtp-pass\n\n\n\n\n\ny\n"`（名前、メールアドレス、パスワード、送信元、SMTP サーバー、ポート・暗号化・ユーザー名は既定、パスワード、IMAP のサーバー・ポート・暗号化・ユーザー名は既定、cron の間隔は既定、足す）

```php
#[Test] public function installsFromAnswersAlone(): void
// AnsweringMailbox、$answers → 0
// config：installed_at と status.token がある、upgrade.public_dir === realpath($dir . '/www')
// 管理者が 1 人、設定の smtp.host === 'smtp.example.jp'、imap.host === 'smtp.example.jp'、smtp.password === 'smtp-pass'
// $dir/www に index.php と rebuji-root.php、storage/install-key.txt は無い
// crontab - の入力 === "*/5 * * * * /usr/local/php/8.3/bin/php {$root}/bin/cron.php\n"
// 出力に '✓ 返信を読めました'、'✓ 設置を終えました（しきい値は震度5弱、1 時間に送る上限は 100 通。設定の画面で変えられます）'、
//   '✓ cron に足しました'、'見張りの URL：https://example.jp/status/' . status.token、'ログイン：https://example.jp/'

#[Test] public function asksOnlyWhatTheSetupFileLeavesOut(): void
// 設定ファイル（0600）："REBUJI_DOMAIN=anpi.example.jp\nREBUJI_SMTP_HOST=smtp.example.jp\nREBUJI_SMTP_PORT=465\nREBUJI_SMTP_ENCRYPTION=ssl\n"
//   . "REBUJI_SMTP_USERNAME=anpi@example.jp\nREBUJI_MAIL_FROM_ADDRESS=anpi@example.jp\nREBUJI_IMAP_HOST=imap.example.jp\n"
//   . "REBUJI_IMAP_PORT=993\nREBUJI_IMAP_ENCRYPTION=ssl\n"
// 引数に --config、入力 "管理 太郎\nadmin@example.jp\nlong-enough-pass\nsmtp-pass\n\n\ny\n"（管理者、SMTP のパスワード、IMAP のユーザー名は既定、cron）→ 0
// 出力に '設定ファイルの REBUJI_DOMAIN は Docker だけで使う設定なので、使いません。'、'設定ファイルから使います：REBUJI_SMTP_HOST=smtp.example.jp'
// 出力に '  サーバー名'、'  ポート'、'  暗号化'、'  送信元のアドレス' が無い（ファイルにある項目は聞かない）。'  パスワード：' と '  ユーザー名 [送信と同じ]：' はある
// 設定の smtp.port === '465'、imap.host === 'imap.example.jp'。出力に「消してください」は無い（パスワードを書いていない）

#[Test] public function asksEveryQuestionBeforeWaitingForTheReply(): void
// installsFromAnswersAlone と同じ
// 出力で 'cron を何分ごとに' と '設置を終えたら、この行を' が '返信を待っています' より前
// '✓ 返信を読めました' より後の出力に、'[y/N]'、'[Y/n]'、'[5]' が無い

#[Test] public function finishesOnlyAfterTheReplyIsRead(): void
// 答えない FakeMailbox、入力 $answers . "n\n" → 1
// エラーに '返信を読めていないので、設置を終えていません。同じコマンドで、続きから流せます。'
// config に installed_at が無い、crontab - は呼ばれていない、眠った合計 === 300

#[Test] public function waitsAnotherFiveMinutesWhenAsked(): void
// AnsweringMailbox（answering = false）。$sleep が合計 300 を超えたら answering = true
// 入力 $answers . "\n"（もう少し待つ）→ 0、出力に 'もう少し待ちますか？' がちょうど 1 回

#[Test] public function reportsAMailboxThatFailsDuringTheWait(): void
// 1 回目の unseen()（メールの段のログイン）だけ答え、2 回目から MailboxUnavailable('Connection reset') を投げる無名クラス
// 入力 $answers . "n\n" → 1。出力に '✕ 返信を読めませんでした：Connection reset' と 'もう少し待ちますか？'。installed_at が無い

#[Test] public function continuesFromWhereItStoppedWithoutASecondAdmin(): void
// 1 回目：答えない FakeMailbox、入力 $answers . "n\n" → 1
// 2 回目：新しいコンテナとコマンド、AnsweringMailbox、--public を渡さない、入力 "\n\n\n\n\n\n\n\n\n\n\ny\n"（メールはすべて既定、パスワードは前のまま、cron）→ 0
// 2 回目の出力に '最初の管理者：管理 太郎（admin@example.jp）はもういます'、'✓ ' . realpath($dir . '/www') . ' に公開するファイルを置きました'、'  パスワード [前のまま]：'
// 管理者は 1 人、installed_at がある、設定の smtp.password === 'smtp-pass'

#[Test] public function asksForTheMailAgainAfterAFailure(): void
// Mailbox::class を工場の関数にする：1 回目に作るのは unavailable = true の FakeMailbox、2 回目からは AnsweringMailbox
// 入力：管理者の 3 行、メールの 10 個（$answers と同じ）、もう一度メールの 10 個（Enter だけで前の答え。パスワードは前のまま）、cron の 2 行 → 0
// エラーに 'メールを受信できませんでした：'。出力に '✓ 返信用のメールボックスにログインできました' がちょうど 1 回

#[Test] public function stopsWhenTheSetupFileHoldsEveryMailValueAndTheyFail(): void
// 設定ファイルにメールの 12 個すべて（REBUJI_SMTP_* 5 つ、REBUJI_MAIL_FROM_* 2 つ、REBUJI_IMAP_* 5 つ。パスワードを含む、0600）、unavailable = true の FakeMailbox、入力は管理者の 3 行だけ → 1
// エラーに '設定ファイルの値を直して、もう一度流してください。'、installed_at が無い

#[Test] public function stopsBeforeAnyQuestionWhenTheUrlDoesNotReachThisCopy(): void
// ClientInterface::class => new FakeHttpClient(404, [], false) → 1
// エラーに '（状態 404）'、出力に '最初の管理者' が無い、installed_at が無い

#[Test] public function refusesAPublicDirectoryWithOtherFiles(): void
// $dir/www に index.html → 1、エラーに 'にほかのファイルがあります（index.html）'
// storage/config.php が無い、$dir/www/index.html はそのまま、$dir/www/rebuji-root.php は無い

#[Test] public function refusesAnInstalledCopyDockerAndUnknownArguments(): void
// config に installed_at → 1、エラーに 'sh ' . $root . '/bin/upgrade.sh'
// Environment(['REBUJI_ENV_CONFIG' => '1']) → 1、エラーに 'Docker では使いません'
// ['--yes'] → 2、エラーに '使い方：console install'

#[Test] public function tellsHowToRegisterCronWithoutCrontab(): void
// crontab -l → [127, '', ''] の $run、入力は $answers の最後の "\ny\n" を除いたもの → 0
// 出力に 'cron を何分ごとに' が無い、'cron に次の行を登録してください（サーバーの管理画面か crontab -e で）：'、
//   '  */5 * * * * /usr/local/php/8.3/bin/php ' . $root . '/bin/cron.php'、'  1 分ごとにできるサーバーなら、1 分ごとにしてください。'

#[Test] public function leavesCrontabAloneWhenAnsweredNo(): void
// 入力の最後を "1\nn\n" にする → 0、crontab - は呼ばれていない、出力に '  * * * * * /usr/local/php/8.3/bin/php '

#[Test] public function stillFinishesWhenCrontabCannotBeWritten(): void
// crontab - → [1, '', 'errors in crontab file'] → 0、installed_at がある
// 出力に '✕ cron に足せませんでした：errors in crontab file' と 'cron に次の行を登録してください'

#[Test] public function hidesTheMysqlPasswordWhenItCannotConnect(): void
// 設定ファイル（0600）："REBUJI_DB_DRIVER=mysql\nREBUJI_DB_HOST=127.0.0.1\nREBUJI_DB_PORT=1\nREBUJI_DB_NAME=anpi\nREBUJI_DB_USERNAME=anpi\nREBUJI_DB_PASSWORD=db-secret-pw\n"
// → 1。エラーに 'データベースに接続できません' と '正しい値を --config の設定ファイル（REBUJI_DB_*）に書いて'、出力にもエラーにも 'db-secret-pw' が無い
//   （pdo_mysql が無い PHP でも、同じ文言で止まる）
```

`tests/Console/ConsoleTest.php::listsCommands` に `$this->assertStringContainsString('install', $io->output());` を足す（`upgrade:install` にも含まれるので、`'  install '` で確かめる）。

- [ ] **Step 2: 落ちることを確かめる**

Run: `vendor/bin/phpunit tests/Console/InstallCommandTest.php tests/Install/RebuildingContainerTest.php tests/Console/ConsoleTest.php`
Expected: FAIL（`InstallCommand`、`RebuildingContainer` が無い）

- [ ] **Step 3: 書く**

`RebuildingContainer`、`InstallContext::forConsole()`、`InstallCommand`（上の流れ）、`Console::COMMANDS`、`ArraySession` の docblock、`AnsweringMailbox`。段ごとの処理は `InstallCommand` の private メソッドに分ける（分け方は任せる。メールの問いが長くなるなら、`src/Install/` の小さなクラスに出してよい）。

- [ ] **Step 4: 通ることを確かめる**

Run: `composer test > .superpowers/sdd/2026-11-24-re-buji-ssh-install/task6.log 2>&1; tail -5 .superpowers/sdd/2026-11-24-re-buji-ssh-install/task6.log && composer analyse && composer lint`
Expected: OK（すべてのテスト）、No errors、passed

- [ ] **Step 5: コミット**

```bash
git add src/Console src/Install src/Http/ArraySession.php tests/Console tests/Install/RebuildingContainerTest.php tests/Support/AnsweringMailbox.php
git commit -m "feat: add bin/console install, which runs the installer's steps from SSH and finishes only after the reply"
```

---

### Task 7：`bin/install.sh`

**Files:**
- Create: `bin/install.sh`（0755）
- Modify: `bin/upgrade.sh`（zip を取るところのコメントに「bin/install.sh も同じ確かめを持つ」を 1 行足す。動きは変えない）
- Test: `tests/InstallScriptTest.php`

**Interfaces:**
- Consumes: Task 6 の `bin/console install` と引数の形（Global Constraints）、`src/Console/InstallCommand.php` があること（古い版の見分け）
- Produces（Task 8、9 が使う）：`sh install.sh [--public DIR] [--root DIR] [--url URL] [--config FILE] [--version X.Y.Z] [--zip FILE]`、環境変数 `PHP`

決めること（設計 3.2、3.4、3.5、3.6 の 1〜3）：
- 先頭のコメントに使い方と各引数（`bin/upgrade.sh` と同じ形）。`set -eu`、`umask 022`。`--name value` と `--name=value` の両方を読む（`bin/upgrade.sh` と同じ）。`--version` の前の `v` は落とす。ほかは `使い方：sh install.sh [--public DIR] [--root DIR] [--url URL] [--config FILE] [--version X.Y.Z] [--zip FILE]` で 2。
- 止まる文言（どれも 1。理由はエラー出力へ）：

  | とき | 文言 |
  |---|---|
  | `REBUJI_ENV_CONFIG=1` | `Docker では使いません。docs/docker.md の手順で動かしてください。` |
  | `PHP` が 8.3 以上のコマンドラインの php でない | `{PHP} は 8.3 以上のコマンドラインの php ではありません。` |
  | php が見つからない | `8.3 以上のコマンドラインの php が見つかりません。env PHP=/path/to/php sh install.sh … のように、php を渡してください。` |
  | `unzip` が無い／`curl` が無い（`--zip` でなく、取るとき） | `unzip が見つかりません。`／`curl が見つかりません。` |
  | `--public` も `--root` も無く、`$HOME/re-buji/src/Version.php` も無い（2 で止まる） | `置き方を選んで、--public か --root を渡してください。` と、次の 2 行：`  sh install.sh --public ~/www/anpi    本体を ~/re-buji に置き、公開ディレクトリには public/ の中身だけを置く（おすすめ）`、`  sh install.sh --root ~/www/re-buji   全体を公開ディレクトリの中に置く（本体を外に置けないサーバー向け）` |
  | 親が無い | `{dir} の親のディレクトリがありません。` |
  | 重なる（同じ、どちらかがもう一方の中） | `--root（{root}）と --public（{public}）が重なっています。別の場所にしてください。` |
  | `--root` が設置を終えた Re:無事（`storage/config.php` に `'installed_at'` がある。`grep -q` で見て、php で読み込まない） | `{root} は設置を終えた Re:無事 です。上げるときは sh {root}/bin/upgrade.sh を流してください。` |
  | `--root` が空でなく、Re:無事 でもない | `{root} は空ではありません（{名前}）。まだ無いか、空のディレクトリを渡してください。` |
  | `--public` にほかのファイル（`rebuji-root.php` がこの `--root` を指すなら通す。php で読む） | `{public} にほかのファイルがあります（{名前}）。まだ無いか、空のディレクトリを渡してください。` |
  | `--config` のファイルが無い | `設定ファイルがありません：{config}` |
  | 展開した版、または続ける Re:無事 に `src/Console/InstallCommand.php` が無い | `Re:無事 {版} は install.sh で入れられません。0.4.0 以降を選んでください。` |

  `{名前}` は `ls -A` の最初の 10 個を `、` でつなぐ。ここまでの確かめは、すべて zip を取る前にする。
- php を探す（設計 3.5）：`PHP` があればそれだけを確かめる。無ければ `PATH` の `php`、次に各社の版ごとの場所の候補（さくらのレンタルサーバ：`/usr/local/php/*/bin/php`。ほかは Step 3 で確かめて足す）のうち、`"$candidate" -r 'exit(PHP_SAPI === "cli" && PHP_VERSION_ID >= 80300 ? 0 : 1);'` が通り、`PHP_VERSION_ID` がいちばん大きいもの（`sort -V` は使わない）。見つけたら `php：{場所}（{PHP_VERSION}）を使います`。
- 場所：`--root` の既定は、`--public` があれば `$HOME/re-buji`。どちらも無ければ、`$HOME/re-buji` に Re:無事 があるときだけ `--root` をそこにして続ける（`--public` は渡さず、`bin/console install` が覚えたものを使う）。場所は親を `cd -P` して絶対パスにする（最後の名前は、まだ無くてよい）。`--config` も絶対パスにして渡す。
- 続き（設計 3.7）：`--root` に設置を終えていない Re:無事 があれば、取らずに `{root} の Re:無事 {版} の設定を続けます。` と出す。
- 取る（設計 3.6 の 2）：`bin/upgrade.sh` の「GitHub に聞いて zip を取り、sha256 を確かめる」部分と同じ確かめ（版の形、この repo の zip の URL、64 桁の sha256）。「新しくなければ止める」は無い。`Re:無事 {版} を {root} に入れます。zip を取っています…`、`✓ zip の sha256 を確かめました：{sha256}`。`--zip` なら `zip を使います：{zip}（sha256 {sha256}）`。
- 展開（設計 3.4）：`{root の親}/.re-buji-install-$$` を 700 で作り、`trap` で必ず消す（`bin/upgrade.sh` と同じく `EXIT` と `HUP INT TERM`）。そこに展開し、`re-buji/src/Console/InstallCommand.php` を確かめてから、`--root` が空のディレクトリなら `rmdir` し、`mv` で `--root` にする。`✓ {root} に展開しました`
- 引き渡す：`exec "$php" "$root/bin/console" install` に、ある分だけ `--public={絶対パス}`、`--url={URL}`、`--config={絶対パス}`、それと必ず `--php={php}`。

- [ ] **Step 1: テストを書く**

`tests/InstallScriptTest.php`。スクリプトは `sh {repo}/bin/install.sh` で、`env -i HOME=… PATH=… [PHP=…] sh …` の形で流し、`2>&1` で出力をまとめる。`$tmp = TemporaryDirectory::create()`、`$home = $tmp . '/my home'`（空白を含む）を作る。`PATH` は `getenv('PATH')`。ことわりが無いものは `PHP=` に `PHP_BINARY`。

zip を作る補助（`ZipArchive`）：`re-buji/bin/console`（`<?php echo json_encode(array_slice($argv, 1), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";`）、`re-buji/src/Version.php`（`CURRENT = '9.9.9'`）、`re-buji/src/Console/InstallCommand.php`（`<?php`。引数で入れないこともできる）、`re-buji/storage/.htaccess`。同じ中身を、展開せずにディレクトリとして作る補助も持つ（続きのテスト用）。

```php
#[Test] public function explainsBothLayoutsWithoutAPlace(): void
// 引数なし → 2、出力に '置き方を選んで、--public か --root を渡してください。' と '  sh install.sh --public ~/www/anpi'
// $home/re-buji は無い

#[Test] public function refusesAnInstalledRoot(): void
// $home/re-buji に src/Version.php と storage/config.php（"<?php\n\nreturn array (\n  'installed_at' => '2026-11-24T00:00:00Z',\n);\n"）
// --root "$home/re-buji" → 1、出力に $home . '/re-buji は設置を終えた Re:無事 です。上げるときは sh ' . $home . '/re-buji/bin/upgrade.sh を流してください。'

#[Test] public function refusesARootWithOtherFiles(): void
// $home/re-buji/notes.txt → --root → 1、出力に $home . '/re-buji は空ではありません（notes.txt）。'

#[Test] public function refusesAPublicDirectoryWithOtherFiles(): void
// $home/www/anpi/index.html → --public "$home/www/anpi" → 1、出力に 'にほかのファイルがあります（index.html）'、$home/re-buji は無い

#[Test] public function refusesOverlappingPlaces(): void
// mkdir $home/www → --root "$home/www/re-buji" --public "$home/www/re-buji/public" → 1、出力に '重なっています'

#[Test] public function refusesAPhpOlderThan83AndDocker(): void
// PHP= に、何をしても 1 で終わる sh のスクリプト → 1、出力に 'は 8.3 以上のコマンドラインの php ではありません。'
// REBUJI_ENV_CONFIG=1 → 1、出力に 'Docker では使いません'

#[Test] public function unpacksAZipAndHandsOverToTheConsole(): void
// PHP を渡さない。$tmp/bin/php を PHP_BINARY へのシンボリックリンクにし、PATH を "$tmp/bin:/usr/bin:/bin" にする
// mkdir $home/www、設定ファイル $tmp/rebuji.env
// --zip $tmp/re-buji-9.9.9.zip --public "$home/www/anpi" --url https://example.jp/anpi --config $tmp/rebuji.env → 0
// 出力に 'php：' . $tmp . '/bin/php（' . PHP_VERSION . '）を使います'、'zip を使います：'、'✓ ' . $home . '/re-buji に展開しました'
// 出力に json_encode(['install', '--public=' . $home . '/www/anpi', '--url=https://example.jp/anpi', '--config=' . $tmp . '/rebuji.env', '--php=' . $tmp . '/bin/php'], 同じフラグ)
//   （$tmp は realpath したもの）
// $home/re-buji/src/Version.php がある、$home に .re-buji-install- で始まるものが無い

#[Test] public function stopsAtAVersionWithoutTheInstallCommand(): void
// InstallCommand.php の無い zip → --zip … --public "$home/www/anpi"（$home/www は作る）→ 1
// 出力に 'Re:無事 9.9.9 は install.sh で入れられません。0.4.0 以降を選んでください。'、$home/re-buji は無い、.re-buji-install- で始まるものが無い

#[Test] public function continuesACopyThatIsNotInstalledWithoutDownloading(): void
// $home/re-buji に展開済みの中身（storage/config.php は無い）→ --root "$home/re-buji" → 0
// 出力に $home . '/re-buji の Re:無事 9.9.9 の設定を続けます。' と json の '["install","--php='、'zip を取っています' は無い

#[Test] public function continuesTheCopyInTheHomeDirectoryWithoutAPlace(): void
// 上と同じ中身、引数なし → 0、出力に '設定を続けます' と '["install","--php='
```

- [ ] **Step 2: 落ちることを確かめる**

Run: `vendor/bin/phpunit tests/InstallScriptTest.php`
Expected: FAIL（`bin/install.sh` が無い）

- [ ] **Step 3: 各社の php の場所を確かめる**

ロリポップ、エックスサーバー、ConoHa WING の公式のマニュアル（元の設計 2.1 と同じ 4 社）で、SSH で使える PHP 8.3 以上のコマンドラインの php の場所を確かめる。書いてあるものだけを、php を探す候補に足し、スクリプトのコメントに会社名と出典の URL を書く。見つからない会社は足さず、台帳に `Ruling:` として書く（`PATH` の php か `env PHP=` で動く）。

- [ ] **Step 4: `bin/install.sh` を書く**

`chmod 755 bin/install.sh`。`bin/upgrade.sh` に、zip を取るところのコメントを 1 行足す。

- [ ] **Step 5: 通ることを確かめる**

Run: `vendor/bin/phpunit tests/InstallScriptTest.php && sh -n bin/install.sh && (command -v dash > /dev/null && dash -n bin/install.sh; true) && composer lint`
Expected: OK、構文のエラーなし、passed

- [ ] **Step 6: コミット**

```bash
git add bin/install.sh bin/upgrade.sh tests/InstallScriptTest.php
git commit -m "feat: add bin/install.sh, which finds php, fetches and checks the zip, unpacks it in one move and hands over to bin/console install"
```

---

### Task 8：リリースに `install.sh` を載せる

**Files:**
- Modify: `bin/release.sh`、`.claude/skills/releasing-re-buji/SKILL.md`、`.claude/skills/releasing-re-buji/release-notes-template.md`
- Test: `tests/ReleaseScriptTest.php`

**Interfaces:**
- Consumes: Task 7 の `bin/install.sh`
- Produces: リリースの資産 `install.sh`（`https://github.com/shimomo/re-buji/releases/latest/download/install.sh` で取れる）

決めること（設計 4 章）：
- `bin/release.sh`：
  - sha256 を出す部分を関数 `sha256()` にまとめ、zip と `bin/install.sh` の両方に使う
  - zip を作る直前に、`bin/install.sh` が無ければ `bin/install.sh not found; it is uploaded with the zip` で止まる（今の確かめの後、build の前）
  - `check` の出力に `install.sh sha256 {sum}` の行を足す
  - `publish`：`gh release create "$tag" "$zip" "$root/bin/install.sh" --verify-tag …`（資産の名前はファイル名の `install.sh`）。zip と同じく、上げた `install.sh` の digest を手元と比べ、違えば `the uploaded install.sh is {digest}, not sha256:{sum}; check the release by hand` で止まる。最後の行に `install.sh sha256 {sum}` も入れる
  - 先頭のコメントの `publish` の説明に、`bin/install.sh` も上げることを足す
- `SKILL.md`：原則の箇条に「zip と `install.sh` を上げ、どちらもハッシュを手元と比べる」。手順 5 で見せるものに `install.sh` の sha256。手順 8 で伝えるものに `install.sh` の sha256
- ひな形：「新しく入れる」の節（`## 新しく入れる`）を足す。SSH で入れるサーバーでは `curl -fsSLO https://github.com/shimomo/re-buji/releases/latest/download/install.sh` と `sh install.sh --public ~/www/anpi` の 2 行で入れられること、詳しくは docs/install.md。「「上げる」の節を変えるとき」に「**機能を足した版**：1 行目を『{前の版} の後に、{足した機能} を足した版です。』にする」を足す

- [ ] **Step 1: テストを書く**

```php
#[Test] public function refusesToReleaseWithoutTheInstallScript(): void
// inClone()（今のクローンには bin/install.sh が無い）で check {Version::CURRENT}
// assertRefused(…, '/^bin\/install\.sh not found; it is uploaded with the zip$/m')（build/ は作られない）
```

- [ ] **Step 2: 落ちることを確かめる**

Run: `vendor/bin/phpunit tests/ReleaseScriptTest.php --filter refusesToReleaseWithoutTheInstallScript`
Expected: FAIL（今は確かめを通って build に進み、クローンに `bin/build-release.sh` が無いので、別の理由と状態で止まる）

- [ ] **Step 3: `bin/release.sh`、スキル、ひな形を直す**

- [ ] **Step 4: 通ることを確かめる**

Run: `vendor/bin/phpunit tests/ReleaseScriptTest.php && sh -n bin/release.sh`
Expected: OK（今の 7 件も通る）、構文のエラーなし

- [ ] **Step 5: コミット**

```bash
git add bin/release.sh tests/ReleaseScriptTest.php .claude/skills/releasing-re-buji
git commit -m "build: upload install.sh with each release and check its digest like the zip's"
```

---

### Task 9：文書と版

**Files:**
- Modify: `docs/install.md`、`README.md`、`docs/upgrade.md`、`docs/superpowers/specs/2026-11-05-re-buji-design.md`（6.3）
- Modify: `src/Version.php`、`.env.example`、`compose.yaml`
- Test: 今の `tests/DocumentLinksTest.php`

**Interfaces:**
- Consumes: Task 7 の引数と動き、Task 1 の読む名前、Task 6 の流れ

- [ ] **Step 1: 各社の SSH の crontab を確かめる**

元の設計 2.1 の 4 社（ロリポップ、さくらのレンタルサーバ、エックスサーバー、ConoHa WING）の公式のマニュアルで、次を確かめる（設計 3.8、7 章）：SSH で `crontab` を書いてよいか、管理画面の cron 設定で編集すると SSH で書いた行が書き換わるか。分かったことと出典の URL を Step 2 の表に書く。書いていない会社は「公式のマニュアルに記載なし。管理画面で登録してください」とし、台帳に書く。

- [ ] **Step 2: `docs/install.md` を書き直す**

冒頭（`[README に戻る]` と「必要なもの」の後）に `## SSH で入れる（SSH が使えるサーバー）` を置き、今の手順は `## FTP で置く` の見出しの下にそのまま残す（「初期設定」「cron」「見張り」の節も残す）。SSH の節に書くこと（設計 5 章）：
- 2 行（`curl -fsSLO …/install.sh` と `sh install.sh --public ~/www/anpi`）と、何をするか（3.6 の流れを 5〜6 行で）。返信を待つ前に問いがすべて終わること
- 引数の表（Task 7 の 6 つと `PHP`）と、2 つの置き方
- php の探し方と、見つからないときの `env PHP=`
- 設定ファイル：読む名前、読まない名前（あれば「使いません」と出る）、パスワードを書かない例（`REBUJI_SMTP_HOST` などだけを書き、パスワードは聞かれて答える）、`chmod 600`。**ファイルは設置のときに一度だけ読み、後から変えるのは設定の画面**（Docker と違い、画面で変えられなくなることはない）
- 途中で止まったら、同じコマンドをもう一度（続きから進む）。設置を終える前は、画面の初期設定も使える
- cron：足すかどうかを聞くこと、Step 1 の 4 社の表、足した行が消えたら見張りの URL が 503 になること
- 終わった後：見張りの URL を監視サービスに登録する（「見張り」の節へのリンク）

- [ ] **Step 3: README、upgrade.md、元の設計を直す**

- `README.md` の「レンタルサーバーに置く」：SSH が使えるなら 2 行で入れられること（コードブロック）、無ければ FTP。文書の表の `docs/install.md` の説明に「SSH で入れる」を足す
- `docs/upgrade.md` の「SSH で上げる」：`install.sh` で入れたものは公開ディレクトリを覚えているので、`--public` は要らないことを 1 行
- 元の設計 6.3 の終わりに：`SSH が使えるサーバーでは、スクリプトで設置と初期設定を済ませる道もある（[2026-11-24-re-buji-ssh-install-design.md](2026-11-24-re-buji-ssh-install-design.md)）。`

- [ ] **Step 4: 版を 0.4.0 にする**

- `src/Version.php` の `CURRENT` を `'0.4.0'`
- `.env.example` の `REBUJI_VERSION=0.4.0`
- `compose.yaml` の 2 か所の `${REBUJI_VERSION:-0.4.0}`
- `README.md` の「開発」の 3 行の `0.3.9` を `0.4.0`

- [ ] **Step 5: 確かめる**

Run: `composer test > .superpowers/sdd/2026-11-24-re-buji-ssh-install/task9.log 2>&1; tail -5 .superpowers/sdd/2026-11-24-re-buji-ssh-install/task9.log && composer analyse && composer lint && grep -rn "0\.3\.9" src .env.example compose.yaml README.md`
Expected: OK、No errors、passed、grep は何も出さない

- [ ] **Step 6: コミット**

```bash
git add docs/install.md docs/upgrade.md README.md docs/superpowers/specs/2026-11-05-re-buji-design.md src/Version.php .env.example compose.yaml
git commit -m "docs: put the SSH install first in install.md, with each host's crontab rules, and bump to 0.4.0"
```

- [ ] **Step 7: 作者に頼むことを、終わりの報告に書く**

設計 6 章のとおり、リリースの前に、作者のさくらのレンタルサーバで、手元で作った zip（`bin/build-release.sh 0.4.0` の後の `build/re-buji-0.4.0.zip` を `scp` で上げ、`sh install.sh --zip …`）を使って最初から最後まで流してもらう。見てほしいこと：php の場所、公開の確かめ（`?from=console` が書き換えを通るか、常時 SSL の転送）、crontab に足した行、見張りの URL。

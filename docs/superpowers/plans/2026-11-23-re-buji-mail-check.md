# Re:無事 計画 23：送信のアカウントの見張り 実装計画

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 地震と地震のあいだに、cron が送信（SMTP）のアカウントにログインだけをして確かめる。ログインできなければすぐホーム画面に出し、続けば見張りの URL を 503 にして、外部の監視サービスから管理者に知らせる。

**Architecture:**
- `SmtpMailSender` は、ログインまでの失敗を `AccountUnavailable`（`MailUnavailable` の子）で返す。どの送信でも、使えた・使えなかったを `SendingAccount` に記録する。数えるのはここ 1 か所。
- `SendingAccount` は、`SystemState` のキーで状態を覚える。DB の形は変えない。
- 新しい cron の仕事 `MailCheckJob` が、`SendingAccount::isDue()` のときに `MailLogin::logIn()` を呼ぶ。
- 状態を読むのは次の 3 つ。
  - `StatusController`（503）
  - `WatchJob`（使えないあいだは送らない）
  - ホーム画面（今の `mail.last_error`）

**Tech Stack:** 計画 22 と同じ。依存は増やさない（Symfony Mailer の `EsmtpTransport::start()` を使う）。

**Spec:** `docs/superpowers/specs/2026-11-23-re-buji-mail-check-design.md`（この計画の設計書）、`docs/superpowers/specs/2026-11-05-re-buji-design.md` の 6.2。

**前の計画：** `docs/superpowers/plans/2026-11-23-re-buji-v0.3.3.md`（計画 22、PR #27 でマージ済み、v0.3.3 はリリース済み）。計画 22 の判断 3・4・7（見張りの URL はメールの失敗で 503 を返さない、管理者への知らせのメールの失敗はどこにも出ない）を、この計画で直す。

## Global Constraints

- 計画 1〜22 の Global Constraints をすべて引き継ぐ。主なもの：
  - PHP `^8.3`、`setUp` / `tearDown` を使わない、コミットメッセージの末尾に `Co-Authored-By`、Psalm errorLevel 1、Pint
  - `src/php-version.php`、`public/index.php`、`bin/cron.php` は触らない
  - `docs/superpowers/` へのリンクは GitHub の絶対 URL。`README.md` と `docs/*.md` のリンクは `DocumentLinksTest` が確かめる
- DB の形は変えない（マイグレーションを足さない）。状態は `SystemState` のキー
- 確かめでメールを送らない。`RateLimit` を使わない、記録もしない（`messages` に行を足さない）
- 数字（設計書の「私が決めたこと」）：
  - 最後に使えてから 60 分で確かめる（`CHECK_EVERY_MINUTES = 60`）
  - 使えなかったら 30 分後（`RECHECK_MINUTES = 30`）
  - 503 は、最初の失敗から 30 分以上たってからの試しも失敗したとき（`LASTING_MINUTES = 30`）
  - 時間切れは cron のコンテナの 60 秒（`Container::forCron()` の `SmtpMailSender::CRON_TIMEOUT_SECONDS`）
- 「使えた」は、ログインまで通ったこと。そのあとで宛先・送り主・本文を断られた、本文の後で時間切れになった、も含む。「使えなかった」は、ログインまでの失敗（つながらない、あいさつが無い、TLS、ログイン）。送信の設定が無い、メールを組み立てられない（`RfcComplianceException`）は、どちらにも数えない
- 画面と文書の文言は、各タスクに書いたとおりにする
- 版は `0.3.4`（Task 6）
- **[判断]** 初期設定の画面（`MailStep`）と `bin/console check` の送信も数える。設計書は「まだ保存していない設定を試すので数えない」としたが、`MailStep` は保存してから、コンテナの `MailSender` で送っている。どちらも保存した同じアカウントを使う。数える場所を `SmtpMailSender` の 1 か所にするには、例外を作らないほうがよい。外れていたら、初期設定のテストメールの失敗がホーム画面に残る。ただし、使えるようになれば次の確かめで消え、初期設定もテストメールが通らないと終わらない

## Review Focus

- 0.3.3 から上げた直後（`mail.account.*` のキーが無い）→ 最初の cron の回で確かめる。503 にはならない（Task 2 `isDueBeforeAnyCheck`、Task 4 の既存のテスト）
- 設定の画面のテストメールが 1 回だけ時間切れ（15 秒）→ 503 にならない。30 分後の確かめで使えれば消える（Task 2 `isNotLastingAfterOneFailure`）
- ログインの後の失敗（`NotificationSender` が「メールを送れていません」に書いたもの）がある状態で、確かめのログインが通る → その表示は消えない（Task 2 `keepsAnotherErrorWhenTheAccountWorks`、`countsARefusedSenderAsAWorkingAccount`）
- ユーザー名の無い送信のアカウント（AUTH をしないサーバー）→ つないで EHLO して切れれば使えた（Task 1 `connectsWithoutAUsername`）
- 同じ cron の回で、前のメールの接続を使い回して確かめると、ログインをしていない → 確かめは接続を開き直す（Task 1 `logsInAgainOnAFreshConnection`）。逆に、2 通のあいだにサーバーが落ちると、Symfony は使い回した接続で送ろうとして、ログインの後の失敗（使えた）として 1 回数える。次の試しはつなぎ直して「使えなかった」になるので、テストは足さない

---

### Task 1：ログインまでの失敗を分け、ログインだけを確かめる口を足す

**Files:**
- Create: `src/Mail/AccountUnavailable.php`、`src/Mail/AccountUnavailableResponse.php`、`src/Mail/MailLogin.php`
- Modify: `src/Mail/MailUnavailable.php`（`final` を外す）、`src/Mail/RecipientAwareSmtpTransport.php`（`start()` を上書き）、`src/Mail/SmtpMailSender.php`
- Modify: `tests/Support/fake-smtp-server.php`、`tests/Support/FakeSmtpServer.php`（オプション `rejectSender`）
- Test: `tests/Mail/SmtpMailSenderTest.php`

**Interfaces:**
- Produces:
  - `class MailUnavailable extends RuntimeException`（`final` を外すだけ）
  - `final class AccountUnavailable extends MailUnavailable`：ログインまでに失敗した（つながらない、あいさつが無い、TLS、ログイン）
  - `/** @internal */ final class AccountUnavailableResponse extends Symfony\Component\Mailer\Exception\TransportException`
  - `interface MailLogin { /** @throws AccountUnavailable @throws MailUnavailable 設定が無い */ public function logIn(): void; }`
  - `final class SmtpMailSender implements MailSender, MailLogin`

- [ ] **Step 1: 偽の SMTP サーバーに `rejectSender` を足す**

- `rejectSender` が `true` なら、`MAIL` に `553 5.7.1 Sender address rejected: not owned by user` を返す
- `FakeSmtpServer::start()` の `@param` の形に `rejectSender?: bool` を足す
- ファイルの頭のコメントの Options にも足す

- [ ] **Step 2: 失敗するテストを書く**

`SmtpMailSenderTest` に次を足す（既存の 2 つは名前と期待する例外を変える）。

```php
#[Test] public function reportsAFailedLogInAsAccountUnavailable(): void   // 旧 reportsAFailedLogInAsUnavailable
// rejectAuth → send() が AccountUnavailable

#[Test] public function reportsAServerNobodyAnswersAsAccountUnavailable(): void   // 旧 reportsAServerNobodyAnswersAsUnavailable
// ポート 9 → AccountUnavailable

#[Test] public function keepsARefusedSenderApartFromTheAccount(): void
// rejectSender → MailUnavailable を捕まえ、assertNotInstanceOf(AccountUnavailable::class, $e)、メッセージに '553'

#[Test] public function logsInWithoutSending(): void
// logIn() → transcript に 'C: AUTH ' と 'C: QUIT'、'C: MAIL FROM' は無い

#[Test] public function logsInAgainOnAFreshConnection(): void
// 同じ sender で send() してから logIn() → transcript の 'S: 220 fake.example ESMTP' が 2 回（接続が 2 本）

#[Test] public function connectsWithoutAUsername(): void
// ユーザー名 '' の SmtpSettings で logIn() → transcript に 'C: EHLO' と 'C: QUIT'、'C: AUTH' は無い

#[Test] public function reportsAFailedLogInCheck(): void
// rejectAuth → logIn() が AccountUnavailable

#[Test] public function reportsMissingSettingsWhenLoggingIn(): void
// new SmtpMailSender(null) の logIn() → MailUnavailable で、AccountUnavailable ではない
```

既存の `refusesToLogInWithoutTls` は、期待する例外を `AccountUnavailable` にする。

- [ ] **Step 3: 失敗を確かめる**

Run: `vendor/bin/phpunit tests/Mail/SmtpMailSenderTest.php`
Expected: FAIL（`AccountUnavailable` が無い）

- [ ] **Step 4: 実装する**

- `RecipientAwareSmtpTransport::start()`
  - `parent::start()` を呼び、`TransportExceptionInterface` を `AccountUnavailableResponse`（メッセージ、コード、元の例外）にして投げる
  - Symfony は送信の中でも `$this->start()` を呼ぶ（つなぎ直しを含む）。なので、どこでつないでもログインまでの失敗になる
  - クラスの docblock に、このことを足す
- `SmtpMailSender::send()`
  - `AccountUnavailableResponse` を、ほかの `TransportExceptionInterface` より先に捕まえ、`AccountUnavailable` にする
- `SmtpMailSender::logIn()`
  - 設定が無ければ、`send()` と同じ `MailUnavailable`
  - `default_socket_timeout` を `send()` と同じように切り替える
  - 開いている接続を `stop()` してから `start()` し、`stop()` する
  - `AccountUnavailableResponse` は `AccountUnavailable` にする
- `MailUnavailable` の docblock は、ログインまでの失敗は子の `AccountUnavailable` になることを書く

- [ ] **Step 5: 通ることを確かめる**

Run: `vendor/bin/phpunit tests/Mail/SmtpMailSenderTest.php && composer test`
Expected: OK

- [ ] **Step 6: コミット**

```bash
git add src/Mail tests/Support/fake-smtp-server.php tests/Support/FakeSmtpServer.php tests/Mail/SmtpMailSenderTest.php
git commit -m "feat: tell a failed log-in apart from other send failures, and log in without sending"
```

---

### Task 2：送信のアカウントの状態を覚え、送信のたびに数える

**Files:**
- Create: `src/Mail/SendingAccount.php`
- Modify: `src/Mail/SmtpMailSender.php`（3 つめの引数 `?SendingAccount $account = null`）、`src/Container.php`（`MailSender::class` に `SendingAccount` を渡す）
- Test: `tests/Mail/SendingAccountTest.php`（新規）、`tests/Mail/SmtpMailSenderTest.php`

**Interfaces:**
- Consumes: Task 1 の `AccountUnavailableResponse`、`SmtpMailSender::logIn()`
- Produces:

```php
final class SendingAccount
{
    public const int CHECK_EVERY_MINUTES = 60;
    public const int RECHECK_MINUTES = 30;
    public const int LASTING_MINUTES = 30;

    public function __construct(SystemState $state, ClockInterface $clock);
    public function worked(): void;                   // ログインまで通った
    public function failed(string $reason): void;     // ログインまでに失敗した
    public function isFailing(): bool;
    public function isLasting(): bool;                // 503 にするか
    public function isDue(DateTimeImmutable $now): bool;
    public function checkSoon(): void;                // 次の cron の回で確かめる
}
```

- `SystemState` のキー（時刻は `Time::format()`）：
  - `mail.account.failing_since`：最初に使えなかった時刻
  - `mail.account.failed_at`：最後に使えなかった時刻
  - `mail.account.error`：最後に使えなかった理由
  - `mail.account.next_check_at`：次に確かめる時刻
- 決まりは次のとおり。
  - `failed($reason)`（時刻は時計の今）
    - `failing_since` が空なら、今を入れる
    - `failed_at` に今、`next_check_at` に今 +`RECHECK_MINUTES` を入れる
    - `mail.account.error` と `mail.last_error` に `$reason` を入れる（ホーム画面の「メールを送れていません：理由」）
  - `worked()`
    - `next_check_at` に今 +`CHECK_EVERY_MINUTES` を入れる
    - `failing_since` が空でなければ、`failing_since`・`failed_at`・`mail.account.error` を空にする
    - そのとき、`mail.last_error` が `mail.account.error` と同じ場合だけ、`mail.last_error` も空にする（ログインの後の失敗の表示を消さない）
  - `isLasting()`：`failing_since` があり、`failed_at` − `failing_since` が `LASTING_MINUTES` 分以上
  - `isDue($now)`：`next_check_at` が空か、`$now` がそれ以降
  - `checkSoon()`：`next_check_at` を空にするだけ。失敗の状態は消さない
- `SmtpMailSender` で記録する場所：
  - ログインまでの失敗（`AccountUnavailableResponse`）：`failed()`
  - 送れた、`RecipientRefusedResponse`、`RecipientDeferredResponse`、そのほかの送信の中の `TransportExceptionInterface`（ログインの後の失敗）：`worked()`
  - 設定が無い、メールを組み立てる段階の `RfcComplianceException`：記録しない
  - `logIn()`：通れば `worked()`、ログインまでの失敗なら `failed()`

- [ ] **Step 1: 失敗するテストを書く**

`tests/Mail/SendingAccountTest.php`。`SystemState` は `SystemStateTest` と同じ作り方、時計は `FrozenClock`。

```php
#[Test] public function isDueBeforeAnyCheck(): void
// 何も記録していない → isDue(now) true、isFailing() false、isLasting() false

#[Test] public function waitsAnHourAfterItWorked(): void
// worked() → isDue(+59 分) false、isDue(+60 分) true

#[Test] public function checksAgainHalfAnHourAfterAFailure(): void
// failed('x') → isDue(+29 分) false、isDue(+30 分) true

#[Test] public function showsTheFailureOnTheHomePage(): void
// failed('535 5.7.8 Authentication failed') → state の mail.last_error が同じ文、isFailing() true

#[Test] public function keepsTheFirstFailureTime(): void
// failed → 10 分進めて failed → mail.account.failing_since は最初の時刻

#[Test] public function isNotLastingAfterOneFailure(): void
// failed → 40 分進める（試さない）→ isLasting() false

#[Test] public function isLastingWhenAFailureHalfAnHourLaterFollows(): void
// failed → 29 分進めて failed → false → 1 分進めて failed → true

#[Test] public function clearsTheFailureWhenTheAccountWorks(): void
// failed('535 …') → worked() → isFailing() false、isLasting() false、mail.last_error ''

#[Test] public function keepsAnotherErrorWhenTheAccountWorks(): void
// failed('535 …') → state の mail.last_error に 'Expected response code "250" but got code "552"' を入れる（連絡を送る処理が書いたログインの後の失敗）→ worked() → mail.last_error はそのまま

#[Test] public function startsCountingAgainAfterItWorked(): void
// failed → 10 分後 worked → 25 分後 failed → isLasting() false

#[Test] public function checkSoonMakesTheNextRunCheck(): void
// worked() → checkSoon() → isDue(+1 分) true。failed() の後の checkSoon() でも isFailing() は true のまま
```

`SmtpMailSenderTest` に足す（`SendingAccount` は上と同じ作り方）。

```php
#[Test] public function recordsAFailedLogInOnTheAccount(): void
// rejectAuth、account 付きの sender で send() → 例外。isFailing() true、mail.last_error に '535'

#[Test] public function recordsASentMailOnTheAccount(): void
// 先に account->failed('x') → send() が通る → isFailing() false

#[Test] public function countsARefusedSenderAsAWorkingAccount(): void
// 先に account->failed('x') → rejectSender で send() → MailUnavailable。isFailing() false

#[Test] public function recordsALogInCheck(): void
// logIn() が通る → isDue(+59 分) false

#[Test] public function leavesTheAccountAloneWithoutSettings(): void
// new SmtpMailSender(null, 15, $account) の send() → MailUnavailable。isDue(now) true のまま、isFailing() false
```

- [ ] **Step 2: 失敗を確かめる**

Run: `vendor/bin/phpunit tests/Mail/SendingAccountTest.php tests/Mail/SmtpMailSenderTest.php`
Expected: FAIL（`SendingAccount` が無い）

- [ ] **Step 3: `SendingAccount` を実装し、`SmtpMailSender` で記録し、コンテナでつなぐ**

`Container` の `MailSender::class` は `new SmtpMailSender($settings->smtp(), $timeout, $c->get(SendingAccount::class))`。

- [ ] **Step 4: 通ることを確かめる**

Run: `vendor/bin/phpunit tests/Mail/SendingAccountTest.php tests/Mail/SmtpMailSenderTest.php && composer test`
Expected: OK

- [ ] **Step 5: コミット**

```bash
git add src/Mail src/Container.php tests/Mail/SendingAccountTest.php tests/Mail/SmtpMailSenderTest.php
git commit -m "feat: remember whether the sending account works, from every send"
```

---

### Task 3：cron で確かめる

**Files:**
- Create: `src/Mail/MailCheckJob.php`
- Modify: `src/Cron/CronJobs.php`（`ResendJob` と `WatchJob` のあいだ。docblock も）、`src/Container.php`（`MailLogin::class`）、`tests/Support/RecordingMailSender.php`（`MailLogin` を実装）
- Test: `tests/Mail/MailCheckJobTest.php`（新規）、`tests/Cron/CronJobsTest.php`（新規）

**Interfaces:**
- Consumes: `SendingAccount::isDue()`（Task 2）、`MailLogin::logIn()`（Task 1）
- Produces:
  - `final class MailCheckJob implements CronJob`
    - `__construct(Settings $settings, SendingAccount $account, MailLogin $login, LoggerInterface $logger)`
    - `name()` は `'mail-check'`
    - `run($now)`：`$settings->smtp() === null` か `!$account->isDue($now)` なら何もしない。そうでなければ `logIn()` する
      - `MailUnavailable` は捕まえてログに warning で書き、投げ直さない。投げ直すと `cron.last_error` にも出て、同じことが 2 度出る
      - 記録は `SmtpMailSender` がする
  - `Container` の `MailLogin::class`：`$c->get(MailSender::class)` を返す（`assert(... instanceof MailLogin)`）。接続を送信と共有する
  - `RecordingMailSender`
    - `public int $logins = 0;`
    - `logIn()` は `$logins++` してから、`$unavailable` なら `AccountUnavailable('Connection to "smtp.example.co.jp:587" timed out.')` を投げる

- [ ] **Step 1: 失敗するテストを書く**

`MailCheckJobTest` では `QuakeWorld` を使う（送信の設定が入っている）。`SendingAccount($world->state, $world->clock)` と `$world->mailer` を渡す。

```php
#[Test] public function logsInWhenDue(): void
// 何も記録していない → run → $world->mailer->logins 1、$world->mailer->sent []

#[Test] public function waitsAnHourAfterTheAccountWorked(): void
// account->worked() → 59 分進めて run → logins 0 → 1 分進めて run → logins 1

#[Test] public function triesAgainHalfAnHourAfterAFailure(): void
// account->failed('x') → 29 分進めて run → 0 → 1 分進めて run → 1

#[Test] public function keepsTheRunGoingWhenItCannotLogIn(): void
// mailer->unavailable = true → run が例外を投げない、logins 1

#[Test] public function skipsWithoutSendingSettings(): void
// $world->settings->save(['smtp.host' => '']) → run → logins 0
```

`CronJobsTest`：

```php
#[Test] public function checksTheAccountAfterTheOtherJobsAndBeforeTheWatch(): void
// TestApp::installed() のコンテナで CronJobs::all() の name() の並び
// === ['feed', 'dispatch', 'send', 'replies', 'resend', 'mail-check', 'watch']
```

- [ ] **Step 2: 失敗を確かめる**

Run: `vendor/bin/phpunit tests/Mail/MailCheckJobTest.php tests/Cron/CronJobsTest.php`
Expected: FAIL（`MailCheckJob` が無い）

- [ ] **Step 3: 実装する**

- [ ] **Step 4: 通ることを確かめる**

Run: `vendor/bin/phpunit tests/Mail/MailCheckJobTest.php tests/Cron/CronJobsTest.php && composer test`
Expected: OK

- [ ] **Step 5: コミット**

```bash
git add src/Mail/MailCheckJob.php src/Cron/CronJobs.php src/Container.php tests/Support/RecordingMailSender.php tests/Mail/MailCheckJobTest.php tests/Cron/CronJobsTest.php
git commit -m "feat: log in to the sending account hourly from cron, without sending"
```

---

### Task 4：見張りの URL が、続いてログインできないときに 503 を返す

**Files:**
- Modify: `src/Monitor/StatusController.php`（`SendingAccount` を受け取る。クラスの docblock も）
- Test: `tests/Monitor/StatusUrlTest.php`

**Interfaces:**
- Consumes: `SendingAccount::isLasting()`（Task 2）
- Produces: `status` は次の順で決める。本文の形（`status`、`last_checked`）は変えない
  - cron が止まっている：`'stale'`（503）
  - そうでなく、`isLasting()`：`'mail'`（503）
  - どちらでもない：`'ok'`（200）

- [ ] **Step 1: 失敗するテストを書く**

`SendingAccount` はコンテナから取る（時計は `FrozenClock`、最初は `2026-11-05T03:12:00Z`）。

```php
#[Test] public function reportsALastingMailFailureAsServiceUnavailable(): void
// failed('535 5.7.8 Authentication failed') → 30 分進めて failed(同じ) → lastChecked('-2 minutes')
// → 503、本文 ['status' => 'mail', 'last_checked' => '2026-11-05T03:40:00Z']、本文に '535' が無い

#[Test] public function waitsForASecondFailureBeforeReportingMail(): void
// failed → 40 分進める → lastChecked('-2 minutes') → 200、'status' => 'ok'

#[Test] public function reportsStaleBeforeMail(): void
// 上の 503 の状態で lastChecked('-20 minutes') → 503、'status' => 'stale'
```

- [ ] **Step 2: 失敗を確かめる**

Run: `vendor/bin/phpunit tests/Monitor/StatusUrlTest.php`
Expected: FAIL（`reportsALastingMailFailureAsServiceUnavailable` と `reportsStaleBeforeMail` が 200 / `ok`）

- [ ] **Step 3: 実装する**

- [ ] **Step 4: 通ることを確かめる**

Run: `vendor/bin/phpunit tests/Monitor && composer test`
Expected: OK

- [ ] **Step 5: コミット**

```bash
git add src/Monitor/StatusController.php tests/Monitor/StatusUrlTest.php
git commit -m "feat: turn the status URL red when the sending account stays unusable"
```

---

### Task 5：管理者への知らせは使えないあいだ待ち、送信の設定を保存したら次の回で確かめる

**Files:**
- Modify: `src/Monitor/WatchJob.php`（最後の引数に `SendingAccount $account`。クラスの docblock も）、`tests/Monitor/WatchJobTest.php`（`job()` で `new SendingAccount($world->state, $world->clock)` を渡す）
- Modify: `src/Settings/SettingsController.php`（`SendingAccount` を受け取る）
- Test: `tests/Monitor/WatchJobTest.php`、`tests/Settings/SettingsPagesTest.php`

**Interfaces:**
- Consumes: `SendingAccount::isFailing()`、`checkSoon()`、`isDue()`（Task 2）
- Produces:
  - `WatchJob::run()`：今の「送らずに戻る」条件（`$new === [] || $admins === [] || isRecent(...)`）に `$this->account->isFailing()` を足す
    - 問題の一覧と、消えた問題の `watch.sent.*` の片づけは、今までどおり先にする
    - 使えないことそのものは、使えるようになった時点で `mail.last_error` から消える（Task 2）。なので、メールで知らせることは無い
  - `SettingsController::save()`：保存の前後で `Settings::smtp()` が変わったら、`checkSoon()` を呼ぶ

- [ ] **Step 1: 失敗するテストを書く**

```php
// WatchJobTest
#[Test] public function waitsWhileTheAccountCannotBeUsed(): void
// feed.gap_since を入れる → account->failed('535 5.7.8 Authentication failed') → run → $world->mailer->attempts []
// → account->worked() → run → 2 通送る（管理者 2 人）。本文に '気象庁の情報に抜けがあります' があり、'535' は無い

// SettingsPagesTest（SendingAccount はコンテナから）
#[Test] public function checksTheAccountOnTheNextRunAfterTheSendingSettingsChange(): void
// account->worked() → isDue(now) false → SMTP のサーバー名を変えて保存 → isDue(now) true

#[Test] public function keepsTheCheckScheduleWhenOnlyTheLimitChanges(): void
// account->worked() → 1 時間の上限だけを変えて保存 → isDue(now) false
```

保存の要求の作り方は、既存の `savesValidSettings` に合わせる。

- [ ] **Step 2: 失敗を確かめる**

Run: `vendor/bin/phpunit --filter 'waitsWhileTheAccountCannotBeUsed|checksTheAccountOnTheNextRunAfterTheSendingSettingsChange'`
Expected: FAIL（知らせを送ろうとする／`isDue` が false のまま）。`keepsTheCheckScheduleWhenOnlyTheLimitChanges` は今でも通る。変更の後も通り続けることを確かめるテスト

- [ ] **Step 3: 実装する**

- [ ] **Step 4: 通ることを確かめる**

Run: `vendor/bin/phpunit tests/Monitor tests/Settings && composer test`
Expected: OK

- [ ] **Step 5: コミット**

```bash
git add src/Monitor/WatchJob.php src/Settings/SettingsController.php tests/Monitor/WatchJobTest.php tests/Settings/SettingsPagesTest.php
git commit -m "feat: hold the admin notices while the sending account fails, and check it after its settings change"
```

---

### Task 6：画面の文、文書、設計書 6.2、版を 0.3.4 にする

**Files:**
- Modify: `templates/home.php`（110 行目あたり）、`templates/install/finish.php`（23 行目あたり）
- Modify: `docs/install.md`（「見張り」）、`docs/usage.md`（「ホーム画面」）、`docs/docker.md`（97 行目あたり）
- Modify: `docs/superpowers/specs/2026-11-05-re-buji-design.md`（6.2）
- Modify: `src/Version.php`、`.env.example`、`compose.yaml`（2 か所）、`README.md`（「開発」の 3 行）

文の変更だけなので、残るテストは足さない。下の `grep` と、`DocumentLinksTest` を含む全体のテストで確かめる。

- [ ] **Step 1: 画面の文を書き換える**

- `templates/home.php`：「外部の監視サービスに、次の URL を登録してください。cron が止まると 503 を返します。」を次にする。

  ```
  外部の監視サービスに、次の URL を登録してください。cron が止まったときと、送信のメールのアカウントにログインできない状態が 30 分続いたときに、503 を返します。
  ```

- `templates/install/finish.php`：「次の URL を、UptimeRobot などの無料の監視サービスに登録してください。cron が 15 分以上動いていないと、この URL はエラー（503）を返すので、監視サービスからメールが届きます。」を次にする。

  ```
  次の URL を、UptimeRobot などの無料の監視サービスに登録してください。cron が 15 分以上動いていないときと、送信のメールのアカウントにログインできない状態が 30 分続いたときは、この URL はエラー（503）を返すので、監視サービスからメールが届きます。
  ```

- [ ] **Step 2: 文書を書き換える**

- `docs/install.md` の「見張り」の 1 段落目：「…監視サービスからメールが届きます。」の後に、次の 2 文を足す。

  ```
  送信のメールのアカウントにログインできない状態が 30 分続いたとき（パスワードが変わった、契約が切れた、など）も、503 を返します。そのあいだは、Re:無事 からのメールが届かないためです。
  ```

- `docs/docker.md`：「cron が止まると 503 を返します。」を次にする。

  ```
  cron が止まったときと、送信のメールのアカウントにログインできない状態が 30 分続いたときに、503 を返します。
  ```

- `docs/usage.md` の「ホーム画面」の箇条書き：「メールを送れていないこと（設定が無い、サーバーにつながらない、ログインできない）」を次にする。

  ```
  メールを送れていないこと（設定が無い、サーバーにつながらない、ログインできない）。送信のアカウントには、地震が無くても 1 時間ごとにログインして確かめます
  ```

- `docs/usage.md` の「ただし、メールを送れていないこと（設定が無い、…）は、このメールでは知らせられません。…」の段落を、次に置き換える。

  ```
  ただし、送信のメールのアカウントが使えないこと（サーバーにつながらない、ログインできない）は、このメールでは知らせられません。このメールも、同じアカウントで送るためです。代わりに、ログインできない状態が 30 分続くと、見張りの URL が 503 を返すので、登録した監視サービスからメールが届きます。ログインはできるのに送信だけ断られる場合（事業者の 1 日の上限など）は、ログインの確かめでは分かりません。メールの設定を変えたときや、メールのアカウントのパスワードを変えたときは、「設定」の「テストメールを送る」で届くことを確かめてください。
  ```

- [ ] **Step 3: 設計書 6.2 を書き換える**

- 「最後にフィードを確認してから 15 分以上たっていれば、エラー（HTTP 503）を返す。そうでなければ 200。返す本文は短い状態だけで、個人情報は含めない。」を次にする。

  ```
  最後にフィードを確認してから 15 分以上たっていれば、エラー（HTTP 503）を返す。送信のメールのアカウントにログインできない状態が続いたとき（下）も 503。どちらでもなければ 200。返す本文は短い状態だけで、個人情報は含めない。
  ```

- その下（同じ深さ）に、次の項目を足す。

  ```
  送信が壊れたことは、管理者へのメールでは知らせられない（同じアカウントで送るため）。そこで cron は、最後に使えてから 60 分ごとに（使えなかったら 30 分後に）、メールを送らずにログインだけを確かめる。最初に失敗してから 30 分以上たってからの試しも失敗したら、503 を返す。詳しくは [送信のアカウントの見張り](2026-11-23-re-buji-mail-check-design.md)。**[決定]**
  ```

- [ ] **Step 4: 版を 0.3.4 にする**

- `src/Version.php` の `CURRENT` を `'0.3.4'`
- `.env.example` の `REBUJI_VERSION=0.3.4`
- `compose.yaml` の 2 か所の `${REBUJI_VERSION:-0.3.4}`
- `README.md` の「開発」の 3 行の `0.3.3` を `0.3.4`

- [ ] **Step 5: 確かめる**

Run: `grep -c 'ログインできない状態が 30 分続いた' templates/home.php templates/install/finish.php docs/install.md docs/docker.md; grep -c '1 時間ごとにログインして確かめます' docs/usage.md; grep -rn "0\.3\.3" src/Version.php .env.example compose.yaml README.md; composer test && composer analyse && composer lint`
Expected:
- 最初の `grep`：4 ファイルとも `1`
- 2 つめ：`1`
- 3 つめ：何も出ない
- テスト、Psalm、Pint：OK

- [ ] **Step 6: コミット**

```bash
git add templates/home.php templates/install/finish.php docs/install.md docs/usage.md docs/docker.md docs/superpowers/specs/2026-11-05-re-buji-design.md src/Version.php .env.example compose.yaml README.md
git commit -m "docs: say the status URL also turns red when the sending account cannot log in, and bump to 0.3.4"
```

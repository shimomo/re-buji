# Re:無事 計画 5：v0.1 の前の修正 実装計画

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 連絡が正しく届くかに関わる、計画 3・4 の minor 5 件を直し、本物のレンタルサーバーで試してから v0.1.0 を出せる状態にする。

**Architecture:** 機能は足さず、5 件をそれぞれ 1 タスクで直す。読めない電文の記録は、フィードの失敗と同じ状態（`feed.failed_telegrams`）に集め、新しい `FailedTelegrams` がその読み書きを受け持つ。消した地震の EventID は新しい表 `deleted_events` に残し、その電文を保存しない。

**Tech Stack:** 計画 4 と同じ。依存は増やさない。

**Spec:** `docs/superpowers/specs/2026-11-05-re-buji-design.md`（4 章の判定、5.3 の上限、5.5 の代理回答、6.2 の見張り、7 章の削除）

**前の計画：** `docs/superpowers/plans/2026-11-08-re-buji-replies-and-screens.md`（PR #5 でマージ済み）。直すのは、PR #4・#5 の本文に後回しとして書いた minor のうち、次の 5 件。作者が選んだ（2026-11-11）。

| minor | 出どころ | タスク |
|---|---|---|
| 読めない電文が 1 通あると、その地震の判定が毎回止まり、誰にも連絡が行かない | 計画 3 | Task 1 |
| 判定と送信の SQL を、テストでは SQLite でしか動かしていない | 計画 3 | Task 2 |
| 最近の地震を電文ごと消すと、同じ EventID の後の電文で作り直され、また送る | 計画 4 | Task 3 |
| 要確認の人に代理で「無事」を入れても要確認のまま。代理の画面に今の状態が出ない | 計画 4 | Task 4 |
| 管理者の人数が 1 時間の上限と同じだと、連絡が 1 通も出ない | 計画 4 | Task 5 |

## Global Constraints

- 計画 1〜4 の Global Constraints をすべて引き継ぐ（PHP `^8.3`、SQLite の PRAGMA、UTC の時刻の文字列、名前空間、`.htaccess`、`setUp` / `tearDown` を使わない、テストでは日付に依る値に `FrozenClock` を使う、コミットメッセージの末尾の `Co-Authored-By`、Psalm errorLevel 1、Pint）
- DB の変更は新しいマイグレーション（`M0005`）で足す。`M0004` は main にあり、開発用の DB がすでに当てているので書き換えない
- 依存を増やさない。配布物の `vendor/` は 1,800 ファイル以下のまま
- 画面と見張りのメールの文言は、各タスクに書いたとおりにする

## Review Focus

- 計画 3・4 の版が `feed.failed_telegrams` に書いた項目（`kind` が無い）→ 今までどおり「取れなかった電文」として出る（Task 1）
- 同じ読めない電文が、その地震や関連する地震を判定し直すたびに読まれる → 一覧に 1 回だけ並ぶ（Task 1）
- MySQL 8.4 の既定の `sql_mode`（`ONLY_FULL_GROUP_BY`、`STRICT_TRANS_TABLES` など）→ 手元の MariaDB でも同じモードでテストする（Task 2）
- 消した地震の電文が、消した後に届く → 地震を作り直さない。消していない地震の電文は今までどおり判定する（Task 3）
- 代理で「無事」にした後に本人の返信が届く → 書いてあれば要確認に戻り、空なら無事のまま（Task 4）

---

### Task 1：読めない電文があっても、残りの電文で判定する

**Files:**
- Create: `src/Feed/FailedTelegrams.php`
- Modify: `src/Feed/TelegramRepository.php`、`src/Feed/FeedJob.php`、`src/Quakes/DispatchJob.php`、`src/HomeController.php`、`templates/home.php`、`src/Monitor/WatchJob.php`、`README.md`、`tests/Support/QuakeWorld.php`
- Test: `tests/Quakes/DispatchJobTest.php`、`tests/Monitor/HomeTest.php`、`tests/Monitor/WatchJobTest.php`

**Interfaces:**
- Produces: `ReBuji\Feed\FailedTelegrams`（`__construct(SystemState $state)`）
  - `public const int KEPT = 10;`（`FeedJob::FAILED_TELEGRAMS_KEPT` をここへ移す）
  - `public const string FETCH = 'fetch';`（取れなかった）、`public const string READ = 'read';`（取れたが読めなかった）
  - `add(string $kind, string $title, string $url, string $reason, DateTimeImmutable $at): void`：状態 `feed.failed_telegrams` の JSON の配列に `{kind, title, url, reason, at}` を足す。同じ `url` がもう並んでいれば足さない。新しい `KEPT` 件だけ残す
- Produces: `TelegramRepository::reportsOf(string $eventId, ?callable $unreadable = null): list<Report>`。読めない電文は飛ばし、`$unreadable(string $url, string $title, string $reason)` を呼ぶ（`Throwable` を捕まえる。例外は外へ出さない）
- Produces: `DispatchJob` の引数の最後に `FailedTelegrams $failed`。読めない電文は `FailedTelegrams::READ` で記録する。1 通も読めない地震は判定せず、処理済みにする
- `FeedJob` は引数に `FailedTelegrams` を足し、取れなかった電文を `FailedTelegrams::FETCH` で記録する（動きは今までどおり。`FeedJob` を組み立てているテストも直す）

- [ ] **Step 1: 失敗するテストを書く**

```php
// DispatchJobTest。読めない電文は、テストから telegrams の表へ直接入れる
// （url 'https://example.com/broken.xml'、event_id self::EVENT、status '通常'、title '震源・震度に関する情報'、
//   info_type '発表'、reported_at・received_at は時計の今、processed_at NULL、xml '<Report>'）

#[Test] public function judgesWithTheTelegramsItCanRead(): void
// 山田 花子（2221300）を登録 → JmaSamples::store で self::FIRST、続けて読めない電文 → dispatch を動かす
// → 山田さんの連絡がある。unprocessedEventIds() === []
// → feed.failed_telegrams が 1 件：kind 'read'、url 'https://example.com/broken.xml'、title '震源・震度に関する情報'

#[Test] public function listsAnUnreadableTelegramOnce(): void
// 読めない電文だけ → dispatch → findByEventId(self::EVENT) === null、unprocessedEventIds() === []
// → self::FIRST を保存して dispatch → 地震ができる。feed.failed_telegrams はまだ 1 件

// HomeTest
#[Test] public function saysWhichTelegramsCouldNotBeRead(): void
// feed.failed_telegrams に kind 'read' の項目 → '読めなかった電文があります：震源・震度に関する情報（11月5日 12時05分）'
// kind の無い項目（計画 4 までの形）→ 今までどおり '取れなかった電文があります：…'

// WatchJobTest
#[Test] public function tellsAboutTelegramsItCouldNotFetchOrRead(): void
// feed.failed_telegrams に 1 件 → 本文に '・取れなかったか読めなかった電文が 1 通あります。'
```

- [ ] **Step 2: 失敗を確かめる**

Run: `vendor/bin/phpunit tests/Quakes/DispatchJobTest.php tests/Monitor`
Expected: FAIL（判定が例外で止まる、文言が古い）

- [ ] **Step 3: 実装する**

`DispatchJob::event()` は `?EarthquakeEvent` を返す（1 通も読めなければ null）。`judge()` は null なら何もしない。`unansweredRelatedContact()` は相手の地震が null なら飛ばす。`HomeController::failedTelegrams()` は各項目に `unreadable: bool`（`kind` が `read`）を足し、`templates/home.php` は `unreadable` なら「読めなかった電文があります：」、そうでなければ今までの「取れなかった電文があります：」を出す。見張りの文言は `'取れなかったか読めなかった電文が ' . $failed . ' 通あります。'`。README の「ホーム画面には、次のことも出ます。」の一覧の「取れなかった電文」を「取れなかった、または読めなかった電文」にする。`QuakeWorld` は `SystemState` を `DispatchJob` より先に作り、`new FailedTelegrams($this->state)` を渡す。

- [ ] **Step 4: 通ることを確かめる**

Run: `vendor/bin/phpunit`、`vendor/bin/psalm --no-progress`、`vendor/bin/pint --test`
Expected: OK、No errors found、passed

- [ ] **Step 5: コミット**

```bash
git add -A
git commit -m "fix: judge a quake from the telegrams that can be read, and list the ones that cannot"
```

---

### Task 2：判定と送信のテストを MySQL でも動かす

**Files:**
- Modify: `tests/Support/TestDatabase.php`、`tests/Support/QuakeWorld.php`、`tests/Replies/ReplyJobTest.php`
- Test: `tests/Support/QuakeWorldTest.php`（新規）

**Interfaces:**
- Produces: `TestDatabase::quakeDriver(): string`：`REBUJI_TEST_MYSQL_DSN` があれば `'mysql'`、無ければ `'sqlite'`。CI の MySQL のジョブ（MySQL 8.4）では、`QuakeWorld` を使うテスト（判定・送信・返信・画面・見張りの 7 ファイル）がすべて MySQL で動く。SQLite では PHP 8.3〜8.5 のジョブが動かす **[判断]**：テストのメソッドを書き換えずに済み、MySQL のジョブがいちばん多くの SQL を通す
- Produces: `QuakeWorld::$driver`（`public readonly string`）。`QuakeWorld` の DB は `TestDatabase::open(TestDatabase::quakeDriver())`
- Produces: `TestDatabase::open('mysql')` は、表を消した後に `SET SESSION sql_mode = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'` を流す（MySQL 8.4 の既定）**[判断]**：手元の MariaDB でも CI と同じ厳しさで試すため

- [ ] **Step 1: 失敗するテストを書く**

```php
// QuakeWorldTest
#[Test] public function runsOnMysqlWhenTheTestsHaveIt(): void
// $world = new QuakeWorld('2009-08-10T20:12:00Z')
// → $world->driver === (getenv('REBUJI_TEST_MYSQL_DSN') ? 'mysql' : 'sqlite')
// → $world->db->driver() === $world->driver
```

- [ ] **Step 2: 失敗を確かめる**

Run: `vendor/bin/phpunit tests/Support/QuakeWorldTest.php`
Expected: FAIL（`$driver` が無い）

- [ ] **Step 3: 実装する**

`ReplyJobTest::keepsReadingPastAMailItCannotRecord` のトリガーを、`$world->driver` で書き分ける。MySQL では `CREATE TRIGGER refuse_poison BEFORE INSERT ON replies FOR EACH ROW BEGIN IF NEW.subject = 'poison' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'disk full'; END IF; END`。

- [ ] **Step 4: SQLite と MySQL の両方で通ることを確かめる**

Run: `vendor/bin/phpunit`（SQLite）、続けて一時的な MariaDB（計画 4 と同じ立て方）で `REBUJI_TEST_MYSQL_DSN=… vendor/bin/phpunit`
Expected: どちらも OK。計画 5 を書く前の試し（2026-11-13）では、MariaDB 10.11 で `QuakeWorld` の 77 テストのうち、落ちたのはこのトリガーの 1 件だけだった。ほかに落ちたら、テストを直さずに SQL の側を直し、台帳に記録する

- [ ] **Step 5: コミット**

```bash
git add -A
git commit -m "test: run the quake, sending and reply tests against MySQL too"
```

---

### Task 3：消した地震を、後から届いた電文で作り直さない

**Files:**
- Create: `migrations/M0005CreateDeletedEvents.php`
- Modify: `src/Database/Migrations.php`、`src/Feed/TelegramRepository.php`、`src/Quakes/EarthquakesController.php`、`README.md`
- Test: `tests/Feed/TelegramRepositoryTest.php`、`tests/Quakes/QuakeDeletionTest.php`

**Interfaces:**
- Produces: 表 `deleted_events`（`event_id VARCHAR(32) NOT NULL PRIMARY KEY`、`deleted_at VARCHAR(20) NOT NULL`）。マイグレーションの説明は「消した地震の EventID」。行はずっと残す **[判断]**：1 地震 1 行で増え方が小さく、何日後の電文でも作り直さないため
- Produces: `TelegramRepository::forgetEvent(string $eventId, DateTimeImmutable $at): void`：その EventID の電文を消し、`deleted_events` に入れる（`deleteEvent()` は無くす）。`store()` は、電文の EventID が `deleted_events` にあれば保存しない
- `EarthquakesController::delete()` は `forgetEvent($quake->eventId, $this->clock->now())` を呼ぶ（訓練のように EventID が無ければ呼ばない）

- [ ] **Step 1: 失敗するテストを書く**

```php
// TelegramRepositoryTest（TestDatabase::each() の両方の DB で）
#[Test] public function ignoresTelegramsOfAForgottenEvent(): void
// '32-35_07_01_100915_VXSE51.xml' を保存 → forgetEvent('20090811050711', 時刻)
// → 同じ地震の後の電文 '32-35_07_06_240613_VXSE53.xml' を保存 → telegrams に event_id '20090811050711' の行が 0
// → unprocessedEventIds() === []。別の地震の電文（'32-35_08_02_100915_VXSE51.xml'）は今までどおり保存される

// QuakeDeletionTest
#[Test] public function doesNotBringADeletedQuakeBack(): void
// deletesAQuakeWithItsRecords と同じ形で地震を消す → '32-35_07_06_240613_VXSE53.xml' を JmaSamples::store で保存し、コンテナの DispatchJob を動かす
// → findByEventId('20090811050711') === null。連絡は 0 件
```

- [ ] **Step 2: 失敗を確かめる**

Run: `vendor/bin/phpunit tests/Feed/TelegramRepositoryTest.php tests/Quakes/QuakeDeletionTest.php`
Expected: FAIL（`forgetEvent` が無い、地震が作り直される）

- [ ] **Step 3: 実装する**

README の「記録の削除」の段落の終わりに「消した地震の電文が後から届いても、地震を作り直しません。」を足す。

- [ ] **Step 4: 通ることを確かめる**

Run: `vendor/bin/phpunit`、`vendor/bin/psalm --no-progress`、`vendor/bin/pint --test`
Expected: OK、No errors found、passed（`MigratorTest` が全部のマイグレーションを当てて通ること）

- [ ] **Step 5: コミット**

```bash
git add -A
git commit -m "fix: keep a deleted quake deleted when more of its telegrams arrive"
```

---

### Task 4：代理の記録は、責任者の判断どおりにする

**Files:**
- Modify: `src/Quakes/NotificationRepository.php`、`src/Quakes/ContactsController.php`、`templates/contacts/proxy.php`
- Test: `tests/Quakes/NotificationRepositoryTest.php`、`tests/Quakes/ProxyAnswerTest.php`

**Interfaces:**
- Produces: `recordAnswer()` の引数は変えない。`$source` が `'mail'` のときは今までどおり弱まらない（要確認の後の空の返信で無事に戻らない）。`'proxy'` のときは、渡された答えをそのまま入れる **[判断]**：電話などで確かめた責任者の判断で、本人のメールより確か。代理で無事にした後に本人から書いてある返信が届けば、メールの決まりで要確認に戻る
- Produces: 代理の画面に、答えを数える連絡（まとめられた人なら、まとめ先）の今の記録を 1 行出す
  - 答えが無い：`今の記録：まだ答えがありません`
  - メール：`今の記録：無事（本人のメール）`、`今の記録：要確認（本人のメール）`
  - 代理：`今の記録：無事（安否 太郎 さんが代理で記録）`
  - 要確認で確認済みなら、かっこの中の終わりに `、確認済み` を足す

- [ ] **Step 1: 失敗するテストを書く**

```php
// NotificationRepositoryTest（両方の DB で）
#[Test] public function letsAnAdminDecideTheAnswer(): void
// recordAnswer(mail, needs_check) → recordAnswer(proxy, safe) → answer 'safe'、answer_source 'proxy'
// → recordAnswer(mail, safe) → 'safe' のまま → recordAnswer(mail, needs_check) → 'needs_check'

// ProxyAnswerTest
#[Test] public function turnsAReplyThatNeedsCheckingIntoSafe(): void
// メールで要確認の連絡に、画面から代理で「無事」→ answer 'safe'、answered_by '安否 太郎'、地震の画面で無事の側に並ぶ

#[Test] public function showsTheCurrentRecord(): void
// メールで要確認の連絡の代理の画面 → '今の記録：要確認（本人のメール）'
// 答えの無い連絡 → '今の記録：まだ答えがありません'
```

- [ ] **Step 2: 失敗を確かめる**

Run: `vendor/bin/phpunit tests/Quakes/NotificationRepositoryTest.php tests/Quakes/ProxyAnswerTest.php`
Expected: FAIL（要確認のまま、今の記録が出ない）

- [ ] **Step 3: 実装する**

`recordAnswer()` の `answer` の式は、`'proxy'` なら `answer = ?`、`'mail'` なら今の `CASE` のまま（どちらも PHP で組み立てる。SQLite の束縛した値の比較を使わない）。

- [ ] **Step 4: 通ることを確かめる**

Run: `vendor/bin/phpunit`、`vendor/bin/psalm --no-progress`、`vendor/bin/pint --test`
Expected: OK、No errors found、passed

- [ ] **Step 5: コミット**

```bash
git add -A
git commit -m "fix: let an admin's record on someone's behalf decide their answer, and show the current one"
```

---

### Task 5：管理者が多くても、連絡を止めない

**Files:**
- Modify: `src/Mail/RateLimit.php`、`src/Monitor/WatchJob.php`
- Test: `tests/Mail/RateLimitTest.php`、`tests/Monitor/WatchJobTest.php`、`tests/Mail/NotificationSenderTest.php`

**Interfaces:**
- Produces: `RateLimit::RESERVE_SHARE = 10` と `RateLimit::reserve(): int` = `min(管理者の人数, intdiv(1 時間の上限, RESERVE_SHARE))`。`remaining()` は `remainingForNotices() - reserve()` **[判断]**：上限は 10 通以上なので、管理者が 1 人以上いれば 1 通は残り、連絡は上限の 9 割以上を使える。既定（上限 100、管理者 1〜2 人）では今までと同じ
- Produces: `WatchJob` は、`remainingForNotices()` が 1 以上なら送る。送るのは `AdminRepository::all()` の順（id 順）に、残りの通数までの管理者 **[判断]**：全員に送れないときも、送れる人には知らせる。「1 回だけ知らせる」の印は、1 人にでも送れたら付ける

- [ ] **Step 1: 失敗するテストを書く**

```php
// RateLimitTest
#[Test] public function keepsAtMostATenthOfTheLimitBack(): void
// 上限 10、管理者 12 人、メールなし → remaining() === 9、remainingForNotices() === 10
// 上限 100、管理者 2 人 → remaining() === 98（今までどおり）

// NotificationSenderTest
#[Test] public function sendsWhenThereAreAsManyAdminsAsTheLimit(): void
// 上限 10、管理者 10 人、連絡 1 件 → 送られる

// WatchJobTest
#[Test] public function tellsAsManyAdminsAsTheLimitAllows(): void
// 上限 10、管理者 12 人（world の 2 人に 10 人を足す）、直近 1 時間に 9 通、問題が 1 つ
// → 送るのは 1 通で、宛先は admin@example.com
```

- [ ] **Step 2: 失敗を確かめる**

Run: `vendor/bin/phpunit tests/Mail tests/Monitor/WatchJobTest.php`
Expected: FAIL（remaining が 0 以下、連絡も見張りのメールも出ない）

- [ ] **Step 3: 実装する**

- [ ] **Step 4: 通ることを確かめる**

Run: `vendor/bin/phpunit`、`vendor/bin/psalm --no-progress`、`vendor/bin/pint --test`
Expected: OK、No errors found、passed（`SettingsPagesTest::keepsTheTestMailWithinTheLimit` は、管理者 1 人・上限 10 で 9 通のまま）

- [ ] **Step 5: コミット**

```bash
git add -A
git commit -m "fix: keep at most a tenth of the hourly limit for the admins' notices"
```

---

## 計画 5 の後：本物のサーバーで試して v0.1.0 を出す

コードの変更は無い。外に向けた操作（タグ、リリース、本物のメールの送受信）は、それぞれ作者に確かめてから行う。パスワードやアカウントの入力は作者がする。

1. この計画の PR をマージした main で `bin/build-release.sh 0.1.0` を動かし、`build/re-buji-0.1.0.zip` を作る
2. 作者のレンタルサーバーに置き、初期設定を通す。返信を受けるのは Re:無事 専用のメールボックスにする
3. 設定画面の「テストメールを送る」「受信を確かめる」を通す（TLS の SMTP と IMAP を本物で確かめる。計画 4 の Task 3・11 の判断で、自動テストでは確かめていない）
4. 「訓練」で自分あてに送り、空の返信と書いてある返信をして、地震の画面に無事と要確認が出ることを確かめる
5. 問題が無ければ、README の冒頭の「v0.1 の準備中です」を書き換える PR を作り、マージ後にタグ `v0.1.0` と GitHub のリリース（zip を添える）を作る

| 残ること | 扱い |
|---|---|
| 計画 3 の minor 9 件と計画 4 の minor 8 件（PR #4・#5 の本文。計画 4 の「地震の削除」の項目のうち、まとめた連絡の答えが消えるほうは残る） | v0.1.0 の後に、作者と選ぶ |

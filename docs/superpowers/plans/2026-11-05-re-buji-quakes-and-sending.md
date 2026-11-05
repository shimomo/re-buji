# Re:無事 計画 3：地震と送信 実装計画

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** cron で気象庁のフィードを確かめ、登録した市町村が基準の震度以上になった従業員に、1 時間の上限を守ってメールを送る。返信の無い人には再送し、取消・遅れて届いた地震・近い時間の地震・訓練を扱う。

**Architecture:** `FeedJob` は、jmaxml/feed の `poll()` と電文の保存を 1 つのトランザクションで行う。`DispatchJob` は、保存した電文から地震を組み立て直し、「地震」（`earthquakes`）と「1 人・1 地震の連絡」（`notifications`）を作る。送信は `SendJob`（初回と取消の知らせ）と `ResendJob`（再送）が行い、送ったメールの記録（`messages`）で 1 時間の上限を数える。SMTP は `MailSender` の裏に隠す。テストでは、記録するだけの偽物と、別のプロセスで動く偽の SMTP サーバーを使う。

**Tech Stack:** 計画 2 と同じものに、`jmaxml/feed` ^0.1.1、`jmaxml/earthquake` ^0.1.1（`jmaxml/core` は依存で入る）、`symfony/mailer`（`^7.4 || ^8.0`。8 系は PHP 8.4 が必要なので、lock は 7.4 になる）を足す。

**Spec:** `docs/superpowers/specs/2026-11-05-re-buji-design.md`（4 章、5.1、5.3、5.5 の送信の側、5.7、6.1、6.2 の画面の側、7 章の「地震の一覧」「訓練の送信」「設定」「遅れて届いた地震」）

**前の計画：** `docs/superpowers/plans/2026-11-05-re-buji-registration.md`（PR #2 でマージ済み）。その「後の計画へ渡すこと」の表から、次の 4 件をこの計画で扱う。

| 渡されたこと | 扱うタスク |
|---|---|
| ドットが続くアドレスに送れるか | Task 5（symfony/mime は拒むが、ローカル部を引用符で囲めば送れる。登録はこのまま受け付ける） |
| 送った記録がある従業員を削除したとき | Task 1（連絡に名前と所属を写し、`employee_id` は NULL にして残す） |
| 停止中の人に送らない | Task 3（判定のとき）、Task 6（送る直前にも） |
| 市町村から細分区域を引く | Task 3（`EarthquakeEvent::citiesAtLeast()` が行う） |

## Global Constraints

- 計画 1・2 の Global Constraints をすべて引き継ぐ（PHP `^8.3`、SQLite の PRAGMA、UTC の時刻の文字列、名前空間、`.htaccess`、`setUp` / `tearDown` を使わない、`escape` に `''`、コミットメッセージの末尾）
- 購読は `new Subscription(FeedType::EarthquakeVolcano, ['VXSE51', 'VXSE52', 'VXSE53'])`。運用種別は既定の「通常」だけで、気象庁の訓練・試験の電文は保存もしない（設計書 4 章）
- フィードの User-Agent は `'Re-Buji/' . Version::CURRENT . ' (+https://github.com/shimomo/re-buji)'`
- 電文の XML の列は、MySQL では `MEDIUMTEXT` にする（震源・震度に関する情報は 300 KB を超える）
- 設定の既定値（設計書 4 章、5.3）：しきい値 `'5-'`（震度 5 弱）、送るまでの待ち時間 0 分、遅れて届いた地震とみなすのは 3 時間、再送 3 回・60 分ごと、1 時間の上限 100 通
- しきい値に選べるのは 震度 4・5 弱・5 強・6 弱・6 強・7 **[判断]**：3 以下は連絡が多すぎ、メールの上限を使い切る
- メールは宛先のアドレスごとに 1 通で、Message-ID も 1 通ごとに作る。1 時間の上限は、この 1 通ずつを数える。テストメールも数える
- 差出人、Reply-To、エンベロープの送信者（MAIL FROM）は、どれも設定の送信元アドレスにする（設計書 5.1）
- 件名の先頭は `[安否確認 #xxxxxxxx]`（連絡ごとに 8 桁の 16 進の番号。再送でも同じ番号）。再送は続けて `【再送】`、訓練は `【訓練】` を付ける
- 送るメールには `Auto-Submitted: auto-generated` を付ける **[判断]**：RFC 3834 に従う自動応答は、このヘッダーのあるメールには返事をしない。不在の自動応答が「返信」として届くことが減る（設計書 5.2 のアンチゴール）
- 震度の順位は `SeismicIntensity::rank()` の値を使う（5 弱 = 5、6 弱 = 7）。未入電で震度の無い市町村は 5 として扱う
- 時刻は画面では Asia/Tokyo で出し、メールでは `n月j日 G時i分` の形にする（例：`8月11日 5時07分`）
- `bin/build-release.sh` の `vendor/` は 1,800 ファイル以下にする（設計書 2.2 の目安は約 1,460）

## Review Focus

- cron が止まっていた後や、フィードの抜けを埋めた後に、何時間も前の地震の電文が届く → 自動では送らず「遅れて届いた地震」に出す。責任者が送ると決めたら、本文に「○ 時間前の地震です」と入れる（Task 3、Task 5、Task 8）
- 電文を保存する途中で処理が落ちる（DB に書けない、時間切れで止められる）→ 既読の記録も一緒に戻り、次の回に同じ電文をもう一度受け取る（Task 2）
- 1 時間の上限より、送るアドレスの数が多い（1 人 4 件のアドレスの人が多い）→ 上限は超えない。強く揺れた市町村の人から送り、残りは次の回に回す。ホームに、次に送れる時刻を出す（Task 6、Task 8）
- SMTP サーバーにつながらない・認証に失敗する → 「届かなかった」にはしない。送信待ちのまま次の回にやり直し、ホームに理由を出す（Task 5、Task 6）
- 「.」が続く、または「@」の直前や先頭が「.」の携帯のアドレス（`taro..yamada@docomo.ne.jp`）→ ローカル部を引用符で囲んで送る（Task 5）

## ファイルの構成

```
src/Settings/        # Settings、SmtpSettings、SettingsController
src/Feed/            # DatabaseStateStore、TelegramRepository、FeedJob
src/Quakes/          # Earthquake、EarthquakeRepository、Notification、NotificationRepository、MatchedCity、
                     # QuakeSnapshot、DispatchJob、EarthquakesController、DrillsController
src/Mail/            # MailAddress、OutgoingMail、MailSender、SmtpMailSender、RecipientAwareSmtpTransport、
                     # RecipientRefused、MailUnavailable、NotificationMail、MessageRepository、RateLimit、
                     # NotificationSender、SendJob、ResendJob
src/Monitor/FeedNoticeController.php
migrations/M0003CreateQuakeTables.php
templates/settings/index.php、templates/earthquakes/index.php、templates/drills/new.php
tests/Fixtures/jma/  # 気象庁のサンプル電文（無加工）と README.md
tests/Support/       # JmaSamples、FakeJmaServer、RecordingMailSender、FakeSmtpServer、fake-smtp-server.php
```

## 連絡の状態（`notifications.state`）

| 値 | 意味 | 次に起きること |
|---|---|---|
| `pending` | 送信待ち | `not_before` を過ぎたら SendJob が送る |
| `held` | 遅れて届いた地震で、責任者の判断を待っている | 「送る」で `pending`、「送らない」で `skipped` |
| `sent` | 1 件以上のアドレスに送った | 返信が無ければ ResendJob が再送する |
| `failed` | すべてのアドレスが拒まれた（届かなかった） | 再送しない |
| `merged` | 近い時間の関連しそうな地震の連絡に含めた（`merged_into_id`） | 送らない。含めた先の地震が取り消されたら `pending` に戻す |
| `cancelled` | 地震が取り消された | 送っていた人には `cancel_notice = 'pending'` で取消の知らせを送る |
| `skipped` | 送らなかった（停止中・削除された人、「送らない」を選んだ遅れた地震） | なし |

返信（`replied_at`）は計画 4 で入れる。この計画では、再送と「近い時間の地震」の判定で `replied_at IS NULL` を見るだけにする。

---

### Task 1：依存、表、設定

**Files:**
- Modify: `composer.json`、`composer.lock`（`jmaxml/feed` ^0.1.1、`jmaxml/earthquake` ^0.1.1、`symfony/mailer` `^7.4 || ^8.0`）
- Create: `migrations/M0003CreateQuakeTables.php`、`src/Settings/Settings.php`、`src/Settings/SmtpSettings.php`
- Modify: `src/Database/Migrations.php`（M0003 を足す）
- Test: `tests/Settings/SettingsTest.php`、`tests/Database/QuakeTablesTest.php`

**Interfaces:**
- Produces: 表（M0003、説明は `'地震、連絡、送ったメール、設定、気象庁のフィード'`）。`$id`・`$ref`・`$table` は M0002 と同じ書き方。時刻の列はすべて `VARCHAR(20)`
  - `settings`：`name VARCHAR(64) PRIMARY KEY`、`value TEXT NOT NULL`、`updated_at`
  - `feed_seen`：`url VARCHAR(255) PRIMARY KEY`、`expires_at`（索引）
  - `feed_states`：`feed_url VARCHAR(255) PRIMARY KEY`、`state TEXT NOT NULL`（`FeedState::toArray()` の JSON）
  - `telegrams`：`id`、`url VARCHAR(255) NOT NULL UNIQUE`、`event_id VARCHAR(32) NOT NULL`（索引）、`status VARCHAR(16)`、`title VARCHAR(64)`、`info_type VARCHAR(16)`、`reported_at`、`received_at`、`processed_at NULL`、`xml`（SQLite は `TEXT`、MySQL は `MEDIUMTEXT`）
  - `earthquakes`：`id`、`kind VARCHAR(8)`（`'quake'` か `'drill'`）、`event_id VARCHAR(32) NULL UNIQUE`、`title VARCHAR(255)`、`hypocenter VARCHAR(255) NULL`、`magnitude VARCHAR(16) NULL`、`max_intensity VARCHAR(4) NULL`、`detected_at`、`basis_title VARCHAR(64) NULL`、`basis_reported_at NULL`、`threshold VARCHAR(4) NULL`、`delivery VARCHAR(16)`（`'auto'`・`'held'`・`'released'`・`'dismissed'`）、`late INTEGER`、`cancelled_at NULL`、`created_at`、`updated_at`
  - `notifications`：`id`、`earthquake_id`（`ON DELETE CASCADE`）、`employee_id NULL`（`ON DELETE SET NULL`）、`employee_name VARCHAR(255)`、`employee_department VARCHAR(255)`、`token VARCHAR(16) UNIQUE`、`cities TEXT`（`MatchedCity` の JSON の配列）、`priority INTEGER`、`state VARCHAR(16)`（索引）、`not_before NULL`、`sent_count INTEGER`、`first_sent_at NULL`、`last_sent_at NULL`、`next_send_at NULL`、`replied_at NULL`、`cancel_notice VARCHAR(16) NULL`、`merged_into_id NULL`（外部キーなし）、`created_at`、`updated_at`、`UNIQUE (earthquake_id, employee_id)`
  - `messages`：`id`、`notification_id NULL`（`ON DELETE CASCADE`。テストメールは NULL）、`kind VARCHAR(16)`（`'first'`・`'resend'`・`'cancel'`・`'test'`）、`address VARCHAR(255)`、`message_id VARCHAR(255) UNIQUE`（`<>` を除いた形）、`sent INTEGER`、`error TEXT NOT NULL`、`attempted_at`（索引）
- Produces: `final class ReBuji\Settings\Settings`：`__construct(Database $db, ClockInterface $clock)`
  - `public const array DEFAULTS`：`threshold` `'5-'`、`delay_minutes` `'0'`、`late_after_hours` `'3'`、`resend_count` `'3'`、`resend_interval_minutes` `'60'`、`hourly_limit` `'100'`、`mail.subject` `'地震がありました（{地震}）'`、`mail.body`（下の既定の本文）、`smtp.host` `''`、`smtp.port` `'587'`、`smtp.encryption` `'starttls'`、`smtp.username` `''`、`smtp.password` `''`、`mail.from_address` `''`、`mail.from_name` `'安否確認'`
  - `get(string $name): string`（保存した値か既定値。`DEFAULTS` に無い名前は `LogicException`）、`int(string $name): int`、`threshold(): SeismicIntensity`
  - `smtp(): ?SmtpSettings`（`smtp.host` と `mail.from_address` の両方が入っていなければ `null`）
  - `save(array<string, string> $values): void`（1 つのトランザクションで上書き。知らない名前は `LogicException`）
- Produces: `final readonly class ReBuji\Settings\SmtpSettings`：`__construct(string $host, int $port, string $encryption /* 'ssl'|'starttls' */, string $username, string $password, string $fromAddress, string $fromName)`

既定の本文（`mail.body`）。設計書 5.1 で本文に書くと決めた 4 つ（返信で無事として記録されること、本文は空でよいこと、事情は本文に書くこと、対象の市町村と震度）が入っている。

```
{名前} さん

{発生時刻}ごろ、地震がありました（{地震}）。
登録している次の場所が、強く揺れた可能性があります。

{市町村と震度}

このメールに返信してください。返信が届けば「無事」として記録します。
本文は空のままで構いません。
けがをした、出社できないなどの事情があれば、本文に書いてください。
```

- [ ] **Step 1: 依存を足し、ファイル数を確かめる**

Run: `composer require jmaxml/feed:^0.1.1 jmaxml/earthquake:^0.1.1 "symfony/mailer:^7.4 || ^8.0"`、`composer show symfony/mailer jmaxml/feed jmaxml/earthquake | grep -E '^(name|versions)'`
Expected: symfony/mailer は v7.4.x（`config.platform.php` が 8.3.16 のため）、jmaxml は 0.1.1 以上。配布物のファイル数は Step 6 で確かめる

- [ ] **Step 2: 失敗するテストを書く**

```php
// SettingsTest（TestDatabase::each() の両方）
#[Test] public function returnsTheDefaultsUntilSaved(): void
// threshold() === SeismicIntensity::FiveLower、int('delay_minutes') === 0、int('late_after_hours') === 3、int('resend_count') === 3、
// int('resend_interval_minutes') === 60、int('hourly_limit') === 100、get('mail.subject') === '地震がありました（{地震}）'、smtp() === null
#[Test] public function keepsSavedValues(): void
// save(['threshold' => '6-', 'hourly_limit' => '300']) → 新しい Settings でも threshold() === SixLower、int('hourly_limit') === 300、
// int('resend_count') は 3 のまま
#[Test] public function refusesUnknownNames(): void
// save(['thresold' => '6-']) と get('thresold') は LogicException
#[Test] public function hasSmtpOnceTheHostAndTheSenderAreSaved(): void
// smtp.host だけでは null。mail.from_address も保存すると SmtpSettings（port 587、encryption 'starttls'、fromName '安否確認'）

// QuakeTablesTest（両方の DB。行は SQL で直接入れる）
#[Test] public function keepsTheContactWhenTheEmployeeIsDeleted(): void
// 従業員・地震・連絡（employee_name '安否 太郎'）・送ったメールを入れ、EmployeeRepository::delete() →
// notifications.employee_id は NULL、employee_name は '安否 太郎' のまま、messages の行も残る
#[Test] public function removesContactsAndMessagesWithTheEarthquake(): void
#[Test] public function allowsOneContactPerPersonAndEarthquake(): void
// 同じ (earthquake_id, employee_id) の 2 行目は PDOException
```

- [ ] **Step 3: 失敗を確かめる**

Run: `vendor/bin/phpunit tests/Settings tests/Database`
Expected: FAIL（`Settings` が無い、表が無い）

- [ ] **Step 4: 実装する**

`Settings::get()` は 1 回の呼び出しごとに DB を読む（cron の 1 回の中で設定が変わっても、次の呼び出しから効く）。

- [ ] **Step 5: 通ることを確かめる**

Run: `vendor/bin/phpunit`、`vendor/bin/psalm --no-progress`、`vendor/bin/pint --test`、`composer normalize --dry-run`
Expected: OK、No errors found、passed、already normalized

- [ ] **Step 6: コミットし、配布物のファイル数を確かめる**

```bash
git add -A
git commit -m "feat: add the quake, contact and message tables and the settings store"
bin/build-release.sh 0.0.0-check && rm build/re-buji-0.0.0-check.zip
```

Expected: `vendor/ files:` が 1,800 以下（超えたら止まる）。数を台帳に残す

---

### Task 2：フィードの確認と電文の保存

設計書 6.1 の 1。jmaxml/feed の README のとおり、`poll()` と電文の保存を同じ DB の 1 つのトランザクションで行い、途中で落ちたら既読の記録も戻す。

**Files:**
- Create: `src/Feed/DatabaseStateStore.php`、`src/Feed/TelegramRepository.php`、`src/Feed/FeedJob.php`
- Create: `tests/Fixtures/jma/`（下の 13 ファイルと `README.md`）、`tests/Support/JmaSamples.php`、`tests/Support/FakeJmaServer.php`
- Modify: `src/Container.php`（`StateStore` と `FeedClient`）、`src/Cron/CronJobs.php`、`src/Monitor/Health.php`、`src/Monitor/StatusController.php`、`src/HomeController.php`、`templates/home.php`、`src/Install/Steps/EnvironmentStep.php`、`.gitattributes`（`tests/Fixtures/jma/** -text`）
- Modify（テスト）：`tests/Cron/CronEntryTest.php`、`tests/Monitor/HealthTest.php`、`tests/Monitor/HomeTest.php`、`tests/Monitor/StatusUrlTest.php`（`cron.last_finished_at` を入れていたところを `feed.last_checked_at` に）
- Test: `tests/Feed/FeedJobTest.php`、`tests/Feed/TelegramRepositoryTest.php`、`tests/Feed/DatabaseStateStoreTest.php`

サンプルは `~/github/shimomo/jmaxml-php/resources/jmaxml/samples/` から無加工で写す（気象庁の「サンプルデータ」2026-09-17 版。出典と利用規約は jmaxml-php の `resources/jmaxml/README.md` と同じ文で `tests/Fixtures/jma/README.md` に書く）。

| 地震 | ファイル | 使い方 |
|---|---|---|
| 駿河湾（2009-08-11、EventID `20090811050711`、検知 `2009-08-10T20:07:00Z`、最大 6 弱） | `32-35_07_01_100915_VXSE51.xml`、`32-35_07_05_100915_VXSE51.xml`、`32-35_07_06_240613_VXSE53.xml` | 判定、対象の追加、震度の下方修正 |
| 取り消された地震（EventID `20080614084350`、検知 `2008-06-13T23:43:00Z`） | `32-35_06_01_240613_VXSE52.xml`〜`32-35_06_06_100915_VXSE53.xml` の 6 ファイル | 取消 |
| 地震 A（EventID `20100705065511`、検知 `2010-07-04T21:55:00Z`） | `32-35_08_01_100915_VXSE51.xml`、`32-35_08_03_100915_VXSE51.xml`、`32-35_08_05_100915_VXSE51.xml` | 近い時間の地震 |
| 地震 B（EventID `20100705065610`、検知 `2010-07-04T21:56:00Z`、最大 4） | `32-35_08_02_100915_VXSE51.xml` | 近い時間の地震、基準に届かない地震 |

**Interfaces:**
- Produces: `final class ReBuji\Feed\DatabaseStateStore implements JmaXml\Feed\StateStore`：`__construct(Database $db)`、`purge(DateTimeImmutable $now): void`（期限の過ぎた既読を消す）
- Produces: `final class ReBuji\Feed\TelegramRepository`：`__construct(Database $db)`
  - `store(string $url, string $xml, DateTimeImmutable $receivedAt): void`。`Envelope::fromString($xml)` から `event_id`（`head->eventID`）、`status`（`control->status`）、`title`（`control->title`）、`info_type`（`head->infoType`）、`reported_at`（`head->reportDateTime`）を入れる。同じ URL がすでにあれば何もしない
  - `unprocessedEventIds(): list<string>`（受け取った順）、`reportsOf(string $eventId): list<JmaXml\Report>`、`latestOf(string $eventId): ?array{title: string, reportedAt: DateTimeImmutable}`（`reported_at` が最も新しい電文。判定に使った電文として地震に写す）、`markProcessed(string $eventId, DateTimeImmutable $now): void`
  - `prune(DateTimeImmutable $now): int`：処理済みで、受け取ってから 8 日を過ぎ、その EventID の地震（`earthquakes`）が無い電文を消す **[判断]**：8 日は jmaxml/feed の既読の保持と同じ。連絡を作った地震の電文は、地震を組み立て直せるように残す（消すのは設計書 6.5 の「古い地震の記録を消す」で、計画 4）
- Produces: `final class ReBuji\Feed\FeedJob implements CronJob`：`name()` は `'feed'`。`__construct(FeedClient $client, Database $db, DatabaseStateStore $store, TelegramRepository $telegrams, SystemState $state, LoggerInterface $logger)`、`static subscription(): Subscription`
  - `run()`：トランザクションの中で `poll()` し、返った電文をすべて `store()`。`FeedException` はそのまま投げる（`CronRunner` が `cron.last_error` に書く）
  - 成功したら、状態 `feed.last_checked_at` に今の時刻（304 でも）
  - `gap` があって `covered === false` なら `feed.gap_since` に `gap->since`（すでに入っていれば早いほう）。`covered === true` の `gap` が返ったら `''` にする。`gap` が `null` のときは変えない（6 日以上空いたときの `gap` は 1 回しか返らないので、責任者が「確かめた」を押すまで残す。Task 8）
  - `willRetry === false` の失敗は、ログ（error）に書き、状態 `feed.failed_telegrams`（JSON の配列 `[{title, url, reason, at}]`、新しい 10 件まで）に足す。`warnings` はログ（warning）だけ
  - 最後に `DatabaseStateStore::purge()`
- Produces: `Container`：`StateStore::class` は `DatabaseStateStore`、`FeedClient::class` は `new FeedClient($c->get(ClientInterface::class), $c->get(RequestFactoryInterface::class), $store, $clock, 'Re-Buji/' . Version::CURRENT . ' (+https://github.com/shimomo/re-buji)')`
- Produces: `CronJobs::all()` は `[FeedJob]`（Task 3・6 が足す）
- Produces: `Health::lastCheckedAt(): ?DateTimeImmutable`（`feed.last_checked_at`）。`lastRunAt()` を置き換え、`isStale()` もこれで判定する（計画 1 の Task 8 で決めた差し替え）。状態の URL も、これで 503 になる。状態の URL の JSON は `{"status": "ok"|"stale", "last_checked": "…"|null}`（`last_run` の名前を変える。まだ公開していないので互換は気にしない）
- Produces: ホームの見張りの文言。`null`：`'まだ一度も気象庁の情報を確かめていません。サーバーの管理画面で bin/cron.php を登録したか確かめてください。'`、15 分以上：`'15 分以上、気象庁の情報を確かめていません（最後は {時刻}）。地震があっても連絡が送られません。cron の設定と、前回の失敗の理由を確かめてください。'`、それ以外：`'最後に気象庁の情報を確かめたのは {時刻} です。'`
- Produces: 初期設定の環境の確認に、`'気象庁の情報を取りに行けません。PHP の curl 拡張か、allow_url_fopen を有効にしてください。'`（`extension_loaded('curl')` でも `allow_url_fopen` でもないとき。計画 1 の判断 49）。テストの環境では両方を外せないので、この分岐のテストは書かない
- Produces（テスト）：`JmaSamples::xml(string $file): string`、`JmaSamples::edited(string $file, array<string, string> $replacements): string`（置き換える文字列がファイルに無ければ `LogicException`）、`JmaSamples::url(string $file): string`（`'https://www.data.jma.go.jp/developer/xml/data/' . $file`）、`JmaSamples::store(TelegramRepository $repo, DateTimeImmutable $at, string ...$files): void`（フィードを通さずに、`url()` の URL で保存する）
- Produces（テスト）：`final class FakeJmaServer implements ClientInterface`：`serve(string $url, string|int $answer): void`（本文なら 200、数なら その状態コード。無い URL は 404）、`$requested`、`static feed(string $updated, list<array{url: string, title?: string}> $entries): string`（jmaxml/feed の `AtomFixture` と同じ形の Atom）、`static telegramUrl(string $created, string $code): string`（`'https://www.data.jma.go.jp/developer/xml/data/' . $created . '_0_' . $code . '_010000.xml'`。jmaxml/feed はファイル名からデータ種類を読む）

- [ ] **Step 1: サンプルを写す**

```bash
mkdir -p tests/Fixtures/jma
for f in 32-35_07_01_100915_VXSE51 32-35_07_05_100915_VXSE51 32-35_07_06_240613_VXSE53 \
    32-35_06_01_240613_VXSE52 32-35_06_02_100915_VXSE52 32-35_06_03_240613_VXSE53 32-35_06_04_240613_VXSE53 \
    32-35_06_05_240613_VXSE53 32-35_06_06_100915_VXSE53 \
    32-35_08_01_100915_VXSE51 32-35_08_03_100915_VXSE51 32-35_08_05_100915_VXSE51 32-35_08_02_100915_VXSE51; do
    cp ~/github/shimomo/jmaxml-php/resources/jmaxml/samples/$f.xml tests/Fixtures/jma/
done
cmp ~/github/shimomo/jmaxml-php/resources/jmaxml/samples/32-35_07_06_240613_VXSE53.xml tests/Fixtures/jma/32-35_07_06_240613_VXSE53.xml
```

- [ ] **Step 2: 失敗するテストを書く**

```php
// FeedJobTest（SQLite のファイル。時計は FrozenClock('2009-08-10T20:12:00Z')、HTTP は FakeJmaServer）
#[Test] public function primesOnTheFirstRun(): void
// 高頻度と長期のフィードに 1 件ずつ → 1 回目の run は電文を保存しない、feed.last_checked_at は '2009-08-10T20:12:00Z'
#[Test] public function storesNewTelegrams(): void
// 1 回目の後、時計を 1 分進め、高頻度のフィードに 07_01 の XML を返すエントリを足して run →
// telegrams に 1 行（event_id '20090811050711'、title '震度速報'、status '通常'、processed_at NULL）。もう一度 run しても 1 行のまま
#[Test] public function ignoresDrillTelegrams(): void
// JmaSamples::edited('32-35_07_01_100915_VXSE51.xml', ['<Status>通常</Status>' => '<Status>訓練</Status>']) を返すエントリ → telegrams は 0 行
#[Test] public function receivesTheTelegramAgainAfterAFailedSave(): void
// SQLite のトリガー（BEFORE INSERT ON telegrams で RAISE(ABORT, 'disk full')）を置いて run → 例外、feed_seen にその URL は無い。
// トリガーを消して run → 1 行
#[Test] public function remembersAGapItCouldNotFill(): void
// 2 回目のフィードの <updated> を前回の取得から 20 分後にし、長期のフィードを 500 にする → feed.gap_since が入る。
// 長期のフィードが返るようになって covered の gap が返ると ''
#[Test] public function keepsTelegramsThatCouldNotBeRead(): void
// エントリの電文を 10 分を超えて 404 にし続ける → feed.failed_telegrams の JSON に title と url
#[Test] public function leavesTheCheckTimeWhenTheFeedFails(): void
// 高頻度のフィードが 500 → FeedException、feed.last_checked_at は前回のまま

// TelegramRepositoryTest（両方の DB）
#[Test] public function groupsUnprocessedTelegramsByEvent(): void
// 07_01・07_05・08_02 を保存 → unprocessedEventIds() === ['20090811050711', '20100705065610']、reportsOf('20090811050711') は 2 通、
// latestOf() の title '震度速報'、reportedAt は 07_05 の発表時刻。markProcessed('20090811050711') の後は ['20100705065610']
#[Test] public function prunesOldTelegramsOfQuakesWithoutContacts(): void
// 9 日前に受け取った処理済みの電文 2 通（片方の EventID だけ earthquakes にある）→ prune() は 1、残るのは earthquakes にある方。
// 処理していない電文は古くても消さない

// DatabaseStateStoreTest（両方の DB）
#[Test] public function savesTheStateAndTheSeenEntries(): void  // save/load の往復、isSeen/markSeen、purge で期限切れだけ消える

// HealthTest・StatusUrlTest：cron.last_finished_at ではなく feed.last_checked_at で判定するように書き換える。StatusUrlTest は JSON の 'last_checked'
// HomeTest：見張りの 3 つの文言を新しいものに書き換える
// CronEntryTest::runsOnAnInstalledCopy：子プロセスの環境に https_proxy・HTTPS_PROXY を 'http://127.0.0.1:9'、no_proxy を '' で渡し、
// 本物の気象庁に届かないようにする → 終了コード 0、cron.last_finished_at が入り、cron.last_error は 'feed: ' で始まる
```

- [ ] **Step 3: 失敗を確かめる**

Run: `vendor/bin/phpunit tests/Feed tests/Monitor tests/Cron`
Expected: FAIL

- [ ] **Step 4: 実装する**

`FeedJob::run()` は `Database::transaction()` の中で `poll()` と `store()` を行う。SQLite では、この間ほかの書き込みが最大 5 秒待つ（`busy_timeout`）。

- [ ] **Step 5: 通ることを確かめる**

Run: `vendor/bin/phpunit`、`vendor/bin/psalm --no-progress`、`vendor/bin/pint --test`
Expected: OK、No errors found、passed

- [ ] **Step 6: コミット**

```bash
git add -A
git commit -m "feat: poll the JMA feed and keep earthquake telegrams in the database"
```

---

### Task 3：地震の判定と連絡づくり

設計書 6.1 の 2・3 と 4 章。保存した電文から地震を組み立て直し、送る相手を決める。送るのは Task 6。

**Files:**
- Create: `src/Quakes/Earthquake.php`、`src/Quakes/EarthquakeRepository.php`、`src/Quakes/Notification.php`、`src/Quakes/NotificationRepository.php`、`src/Quakes/MatchedCity.php`、`src/Quakes/QuakeSnapshot.php`、`src/Quakes/DispatchJob.php`
- Modify: `src/Cron/CronJobs.php`（`FeedJob` の次に `DispatchJob`）
- Test: `tests/Quakes/DispatchJobTest.php`、`tests/Quakes/NotificationRepositoryTest.php`

**Interfaces:**
- Consumes: `TelegramRepository`（Task 2）、`Settings`（Task 1）、`EmployeeRepository::all()`・`Employee`・`EmployeeCity`・`Cities::find()`（計画 2）
- Produces: `final readonly class ReBuji\Quakes\MatchedCity`：`__construct(string $code, string $label, string $place, ?string $intensity, bool $notYetReceived, string $basis /* 'city'|'area'|'none' */)`、`static fromMatch(CityMatch $match, EmployeeCity $city): self`（`place` は `Cities::find()?->label()`、無ければコード）、`rank(): int`（`intensity` の `rank()`、無ければ 5）、`toArray()`・`static fromArray()`
- Produces: `final readonly class ReBuji\Quakes\QuakeSnapshot`：`title`、`hypocenter`、`magnitude`、`maxIntensity`、`detectedAt`（`?DateTimeImmutable`）、`basisTitle`、`basisReportedAt`。`static fromEvent(EarthquakeEvent $event, ?array{title: string, reportedAt: DateTimeImmutable} $basis): self`
  - `title`：震源と最大震度があれば `'駿河湾・最大震度6弱'`、最大震度だけなら `'最大震度6弱'`、震源だけなら `'駿河湾'`、どちらも無ければ `'地震'`（震度は `SeismicIntensity::label()`）
  - `magnitude`：`Hypocenter::$magnitude` があれば小数 1 桁の文字列（`'6.6'`）、無ければ `magnitudeDescription`
- Produces: `final readonly class ReBuji\Quakes\Earthquake`（行のとおりの項目。`detectedAt` は `DateTimeImmutable`、`late` は `bool`）、定数 `KIND_QUAKE`・`KIND_DRILL`、`AUTO`・`HELD`・`RELEASED`・`DISMISSED`
- Produces: `final readonly class ReBuji\Quakes\Notification`（行のとおり。`cities` は `list<MatchedCity>`）、状態の定数 `PENDING`・`HELD`・`SENT`・`FAILED`・`MERGED`・`CANCELLED`・`SKIPPED`
- Produces: `final class ReBuji\Quakes\EarthquakeRepository`：`__construct(Database $db, ClockInterface $clock)`、`findByEventId(string $eventId): ?Earthquake`、`find(int $id): ?Earthquake`、`createQuake(string $eventId, QuakeSnapshot $snapshot, string $threshold, string $delivery, bool $late, DateTimeImmutable $detectedAt): int`、`updateSnapshot(int $id, QuakeSnapshot $snapshot): void`（`detected_at` は変えない）、`quakesDetectedWithin(DateTimeImmutable $at, int $seconds, int $exceptId): list<Earthquake>`
- Produces: `final class ReBuji\Quakes\NotificationRepository`：`__construct(Database $db, ClockInterface $clock)`
  - `create(int $earthquakeId, Employee $employee, list<MatchedCity> $cities, int $priority, string $state, ?DateTimeImmutable $notBefore, ?int $mergedIntoId = null): int`（`token` は `bin2hex(random_bytes(4))`。重なったら作り直す）
  - `find(int $id): ?Notification`、`findFor(int $earthquakeId, int $employeeId): ?Notification`、`forEarthquake(int $earthquakeId): list<Notification>`
  - `updateCities(int $id, list<MatchedCity> $cities, int $priority): void`（`priority` は前の値との大きいほう）
  - `unansweredQuakeContactsOf(int $employeeId, int $exceptEarthquakeId): list<Notification>`（種類が `quake` で、取り消されていない地震の、`replied_at IS NULL`・状態が `pending`・`held`・`sent` の連絡）
  - `markSent(int $id, DateTimeImmutable $at, ?DateTimeImmutable $nextSendAt): void`（Task 6 も使う。`sent_count` を 1 増やす。1 回目なら `first_sent_at` も入れ、状態を `sent` にする）
- Produces: `final class ReBuji\Quakes\DispatchJob implements CronJob`：`name()` は `'dispatch'`。`__construct(Database $db, TelegramRepository $telegrams, EarthquakeRepository $earthquakes, NotificationRepository $notifications, EmployeeRepository $employees, Settings $settings, LoggerInterface $logger)`
- Produces: `CronJobs::all()` は `[FeedJob, DispatchJob]`

`DispatchJob::run($now)` の流れ（EventID ごとに 1 つのトランザクション。例外は記録して次の EventID へ進み、最後にまとめて投げ直す。処理済みにしないので、次の回にやり直す）：

```
foreach unprocessedEventIds() as $eventId:
    $event = EarthquakeEvent::fromReports(reportsOf($eventId))
    $quake = findByEventId($eventId)
    if 取消（$event->isCancelled()）または $quake が取り消し済み: Task 4。処理済みにして次へ
    $threshold = $quake?->threshold ?? 設定のしきい値        // [判断] 最初に判定したときのしきい値を使い続ける
    $matches = $event->citiesAtLeast($threshold, 有効な従業員の市町村コード)
    $quakeTime = $event->detectedAt ?? $event->originTime ?? $now   // [判断] 時刻が分からなければ遅れとみなさない
    従業員ごとに MatchedCity をまとめ、priority は rank() の最大
    if 一致が無く $quake も無い: 処理済みにして次へ（基準に届かない地震は記録しない）
    if $quake が無い: $late = $now - $quakeTime > late_after_hours。delivery は $late ? HELD : AUTO で作る
    else: updateSnapshot
    foreach 一致した従業員:
        既にあれば updateCities だけ（2 通目は作らない。設計書 4 章「1 つの地震につき、1 人に 1 通」）
        無ければ、delivery が HELD なら held、DISMISSED なら skipped、ほかは pending（近い時間の地震は Task 4）。
        not_before は $quakeTime + delay_minutes（RELEASED の地震に後から足す人は $now）
    markProcessed($eventId)
最後に TelegramRepository::prune($now)
```

- 一度対象になった人は、後の電文で震度が下がって一致しなくなっても、連絡を消さない **[判断]**：震度速報の段階の判定は細分区域の単位で、実際より強めに出ることがある（jmaxml/earthquake の README）。ただ、揺れた地域にいたことは確かで、連絡が届かないことのほうが困る
- `unknownCityCodes` はログ（warning）に書くだけにする。画面には計画 2 の「判定に使えないコード」の警告がすでにある

- [ ] **Step 1: 失敗するテストを書く**

```php
// DispatchJobTest（SQLite。時計は FrozenClock('2009-08-10T20:12:00Z')、駿河湾の地震の 5 分後）
// 従業員は EmployeeInput で作る：山田 花子（掛川市 2221300、呼び名 勤務地）、佐藤 一郎（東京千代田区 1310100）
#[Test] public function contactsPeopleWhoseMunicipalityReachedTheThreshold(): void
// 07_01（震度速報だけ）を保存して run → earthquakes 1 行（event_id '20090811050711'、title '最大震度6弱'、delivery 'auto'、late false、
// threshold '5-'、basis_title '震度速報'）。連絡は 山田 の 1 行だけ（state 'pending'、priority 7、
// cities は [code '2221300', label '勤務地', place '静岡県 掛川市', intensity '6-', basis 'area']、not_before '2009-08-10T20:07:00Z'）。
// 電文は処理済み
#[Test] public function skipsStoppedPeople(): void           // 山田 を停止にしておく → 連絡は 0 行
#[Test] public function makesOneContactPerPersonAndQuake(): void
// 07_01 → run → 07_06 を保存して run → 山田 の連絡は 1 行のまま、cities は intensity '5-'・basis 'city'。
// 地震の title は '駿河湾・最大震度6弱'、magnitude '6.6'、basis_title '震源・震度に関する情報'
#[Test] public function addsPeopleWhoMatchLater(): void
// 泰阜村 2041400 の人は 07_01 では対象でなく、07_05 を保存して run すると連絡が 1 行増える
#[Test] public function keepsTheContactAfterTheIntensityIsRevisedDown(): void
// 熱海市 2220500 の人は 07_01 で対象（静岡県伊豆 5 強）。07_06 で市の震度が 4 になっても、連絡は残り、state も変わらない
#[Test] public function holdsAQuakeThatArrivedLate(): void
// 時計を '2009-08-10T23:08:00Z'（検知から 3 時間 1 分後）→ delivery 'held'、late true、山田 の連絡は state 'held'
#[Test] public function waitsTheConfiguredDelay(): void      // delay_minutes '10' → not_before '2009-08-10T20:17:00Z'
#[Test] public function keepsTheThresholdOfTheFirstJudgement(): void
// 07_01 → run → しきい値を '6-' に変えて 07_05 を保存して run → 泰阜村（5 弱）の人も連絡ができる。地震の threshold は '5-' のまま
#[Test] public function ignoresQuakesBelowTheThreshold(): void
// 地震 B（08_02、最大 4）→ earthquakes 0 行、電文は処理済み

// NotificationRepositoryTest（両方の DB）
#[Test] public function roundTripsAContact(): void
// create → find の cities・priority・state・not_before が同じ。token は 8 文字の 16 進。markSent の後は sent_count 1、first_sent_at が入る
```

- [ ] **Step 2: 失敗を確かめる**

Run: `vendor/bin/phpunit tests/Quakes`
Expected: FAIL

- [ ] **Step 3: 実装する**

- [ ] **Step 4: 通ることを確かめる**

Run: `vendor/bin/phpunit`、`vendor/bin/psalm --no-progress`、`vendor/bin/pint --test`
Expected: OK、No errors found、passed

- [ ] **Step 5: コミット**

```bash
git add -A
git commit -m "feat: judge quakes against registered municipalities and plan one contact per person"
```

---

### Task 4：取消と、近い時間の地震

設計書 4 章の「取消」と「近い時間の地震」。

**Files:**
- Modify: `src/Quakes/DispatchJob.php`、`src/Quakes/EarthquakeRepository.php`（`markCancelled`）、`src/Quakes/NotificationRepository.php`（`cancelFor`）
- Test: `tests/Quakes/DispatchJobTest.php`

**Interfaces:**
- Produces: `EarthquakeRepository::markCancelled(int $id, DateTimeImmutable $at): void`
- Produces: `NotificationRepository::cancelFor(int $earthquakeId): void`：その地震の連絡のうち、`pending`・`held` は `cancelled`。`sent`・`failed` で `sent_count > 0` のものは `cancelled` にし、送れたアドレスがあれば（`messages` に `sent = 1` の行）`cancel_notice = 'pending'`。ほかの地震の連絡で、ここに含めていた（`merged_into_id` がこの地震の連絡を指す）ものは `pending` に戻し、`merged_into_id` を NULL、`not_before` を今にする
- Produces: 取り消された地震は、その後に電文が届いても組み立て直さない **[判断]**：気象庁が取消の後に同じ EventID で出し直すことはまず無い。一度「返信は不要です」と送った人に、もう一度送るほうが混乱する

近い時間の地震（Task 3 の「無ければ」の中で、state が `pending` になるときだけ見る）：

```
foreach unansweredQuakeContactsOf($employee->id, $quake->id) as $other:
    $otherQuake = find($other->earthquakeId)
    if |$otherQuake->detectedAt - $quakeTime| > 300 秒: 次へ
    $otherEvent = EarthquakeEvent::fromReports(reportsOf($otherQuake->eventId))   // 1 回の run の中では EventID ごとに 1 度だけ組み立てる
    if $event->isPossiblyRelatedTo($otherEvent): state は merged、merged_into_id は $other の merged_into_id ?? $other->id
```

- [ ] **Step 1: 失敗するテストを書く**

```php
// 取り消された地震。奥州市 0321500 の人。時計 FrozenClock('2008-06-13T23:48:00Z')
#[Test] public function cancelsContactsNotSentYet(): void
// 06_01・06_03 → run（pending）→ 06_02・06_04・06_05・06_06 → run → state 'cancelled'、cancel_notice NULL、地震の cancelled_at が入る
#[Test] public function queuesACancelNoticeForPeopleAlreadyContacted(): void
// 1 回目の run の後に markSent と messages の sent = 1 の行 → 取消の後は state 'cancelled'、cancel_notice 'pending'

// 地震 A・B。しきい値 '4'、陸前高田市 0321000 の人。時計 FrozenClock('2010-07-04T22:00:00Z')
#[Test] public function foldsARelatedQuakeIntoTheContactNotAnsweredYet(): void
// A（08_01・08_03・08_05）→ run → B（08_02）→ run → B の連絡は state 'merged'、merged_into_id は A の連絡の id
#[Test] public function contactsAgainForARelatedQuakeAfterAReply(): void
// A の連絡に replied_at を入れてから B → B の連絡は 'pending'
#[Test] public function contactsPeopleFoldedIntoAQuakeThatWasCancelled(): void
// A → B（merged）→ A を取り消す電文（JmaSamples::edited('32-35_08_05_100915_VXSE51.xml', ['<InfoType>発表</InfoType>' => '<InfoType>取消</InfoType>',
// '<ReportDateTime>2010-07-05T06:59:00+09:00</ReportDateTime>' => '<ReportDateTime>2010-07-05T07:00:00+09:00</ReportDateTime>'])）を
// 別の URL で保存して run → A の連絡は 'cancelled'、B の連絡は 'pending'・merged_into_id NULL
```

- [ ] **Step 2: 失敗を確かめる**

Run: `vendor/bin/phpunit tests/Quakes`
Expected: FAIL

- [ ] **Step 3: 実装する**

- [ ] **Step 4: 通ることを確かめる**

Run: `vendor/bin/phpunit`、`vendor/bin/psalm --no-progress`、`vendor/bin/pint --test`
Expected: OK、No errors found、passed

- [ ] **Step 5: コミット**

```bash
git add -A
git commit -m "feat: cancel contacts of cancelled quakes and fold related quakes into one contact"
```

---

### Task 5：メールの部品

設計書 5.1。SMTP は symfony/mailer の `EsmtpTransport` を使い、アプリには `MailSender` だけを見せる（設計書 5.7 の経路の抽象化は、計画 4 で LINE などを足すときにこの形を広げる）。

**Files:**
- Create: `src/Mail/MailAddress.php`、`src/Mail/OutgoingMail.php`、`src/Mail/MailSender.php`、`src/Mail/SmtpMailSender.php`、`src/Mail/RecipientAwareSmtpTransport.php`、`src/Mail/RecipientRefused.php`、`src/Mail/MailUnavailable.php`、`src/Mail/NotificationMail.php`
- Create: `tests/Support/RecordingMailSender.php`、`tests/Support/FakeSmtpServer.php`、`tests/Support/fake-smtp-server.php`
- Modify: `src/Container.php`（`MailSender::class` は `new SmtpMailSender($settings->smtp())`）
- Test: `tests/Mail/MailAddressTest.php`、`tests/Mail/SmtpMailSenderTest.php`、`tests/Mail/NotificationMailTest.php`

**Interfaces:**
- Produces: `final class ReBuji\Mail\MailAddress`：`static forSending(string $address): Symfony\Component\Mime\Address`。そのままで `RfcComplianceException` なら、最後の `@` より前を `"…"` で囲んで（`\` と `"` は `\` で逃がす）作り直す。それでも作れなければ `RecipientRefused`
- Produces: `final readonly class ReBuji\Mail\OutgoingMail`：`__construct(string $fromAddress, string $fromName, string $to, string $subject, string $body, string $messageId /* <> なし */)`
- Produces: `interface ReBuji\Mail\MailSender`：`send(OutgoingMail $mail): void`。宛先だけが拒まれたら `RecipientRefused`（やり直さない）、それ以外の失敗は `MailUnavailable`（次の回にやり直す）
- Produces: `final class RecipientRefused extends RuntimeException`、`final class MailUnavailable extends RuntimeException`（`ReBuji\Mail`）
- Produces: `final class ReBuji\Mail\SmtpMailSender implements MailSender`：`__construct(?SmtpSettings $settings)`。`null` なら `send()` は `MailUnavailable('メールの送信の設定がありません。「設定」で SMTP を登録してください。')`。接続は 1 つのインスタンスの中で使い回す（`RecipientRefused` の後も続けて送れる）。タイムアウトは 15 秒
  - メールは text だけ（UTF-8）。ヘッダーは From（名前つき）、To、Reply-To（送信元アドレス）、Subject、`Message-ID: <{messageId}>`、`Auto-Submitted: auto-generated`。エンベロープは `new Envelope(new Address($fromAddress), [MailAddress::forSending($to)])`
  - 暗号化は `ssl` なら `new RecipientAwareSmtpTransport($host, $port, true)`、`starttls` なら `false`（サーバーが STARTTLS を示せば使う）
- Produces: `final class RecipientAwareSmtpTransport extends EsmtpTransport`：`executeCommand()` を上書きし、`RCPT TO:` で始まるコマンドが 5xx の `UnexpectedResponseException` になったら、`TransportException` を継いだ内部の例外に包む。`SmtpMailSender` はそれを `RecipientRefused` に、ほかの `TransportExceptionInterface` を `MailUnavailable` に変える **[判断]**：認証の失敗（535）や MAIL FROM の拒否も 5xx なので、状態コードだけでは「その宛先だけの問題」か分からない。宛先を送る段階の拒否だけを「届かなかった」とする
- Produces: `final class ReBuji\Mail\NotificationMail`：`__construct(Settings $settings, ClockInterface $clock)`、`public const array PLACEHOLDERS = ['{名前}', '{発生時刻}', '{地震}', '{市町村と震度}']`
  - `compose(Earthquake $quake, Notification $notification, string $kind /* 'first'|'resend'|'cancel' */, string $to): OutgoingMail`。送信元は `Settings::smtp()`（`null` なら `MailUnavailable`）。Message-ID は `bin2hex(random_bytes(16)) . '@' . 送信元アドレスのドメイン`
  - `testMail(string $to): OutgoingMail`：件名 `'Re:無事 のテストメール'`、本文 `'Re:無事 からのテストメールです。このメールが届いていれば、送信の設定は正しく動いています。'`
  - 件名：`'[安否確認 #' . token . '] '` に、再送なら `'【再送】'`、訓練なら `'【訓練】'`、続けて `mail.subject` の差し込みを埋めたもの。取消の知らせは `'[安否確認 #' . token . '] 先ほどの連絡は取り消されました'`
  - 本文：`mail.body` の差し込みを埋めたもの。訓練なら先頭に `"これは訓練です。実際の地震ではありません。\n\n"`、遅れて届いた地震（`late`）なら先頭に `"{n} 時間前の地震です。気象庁の情報が遅れて届いたため、今お送りしています。\n\n"`（`n` は検知からの時間の切り捨て、最小 1）。取消の知らせの本文は固定で `"{名前} さん\n\n先ほどお送りした安否確認の連絡は、気象庁が地震の情報を取り消したため、取り消します。返信は不要です。\n"`
  - 差し込み：`{名前}` は連絡に写した名前、`{発生時刻}` は地震の `detected_at`（Asia/Tokyo、`n月j日 G時i分`）、`{地震}` は地震の `title`、`{市町村と震度}` は市町村ごとに 1 行 `'・{呼び名}：{place}　{震度}'`（呼び名が空なら `'・{place}　{震度}'`、訓練は `'・{呼び名}：{place}'`）
  - 震度：`city` は `'震度5弱'`、`area` は `'震度6弱（地域の震度）'`、未入電で震度なしは `'震度5弱以上の可能性（観測値が未着）'`、未入電で震度ありは `'震度5強以上の可能性（観測値が未着）'`
- Produces（テスト）：`final class RecordingMailSender implements MailSender`：`__construct(array<string, 'refuse'|'unavailable'> $failures = [])`（宛先ごと）、`$sent`（`list<OutgoingMail>`）、`$unavailable`（`true` なら全部 `MailUnavailable`）
- Produces（テスト）：`final class FakeSmtpServer`：`static start(array{rejectRecipients?: list<string>, rejectAuth?: bool} $options = []): self`（`proc_open` で `fake-smtp-server.php` を動かし、標準出力の 1 行目からポートを読む）、`port(): int`、`transcript(): string`、`stop(): void`。`127.0.0.1` の空いたポートで待ち、接続を順に受ける。EHLO では `AUTH PLAIN LOGIN` を示し、STARTTLS は示さない。MAIL・RCPT・DATA・RSET・NOOP・QUIT に答え、やり取りをすべてファイルに書く。`rejectRecipients` の宛先の RCPT には `550 5.1.1 User unknown`、`rejectAuth` なら AUTH に `535 5.7.8 Authentication failed`

- [ ] **Step 1: 失敗するテストを書く**

```php
// MailAddressTest
#[Test] public function keepsOrdinaryAddresses(): void       // 'taro.yamada@docomo.ne.jp' → getAddress() は同じ
#[Test] public function quotesAddressesWithDotsThatTheRfcRefuses(): void
// 'taro..yamada@docomo.ne.jp' → '"taro..yamada"@docomo.ne.jp'、'taro.@docomo.ne.jp' → '"taro."@docomo.ne.jp'、'.taro@ezweb.ne.jp' → '".taro"@ezweb.ne.jp'
#[Test] public function refusesWhatCannotBeSent(): void      // 'no-at-sign' → RecipientRefused

// SmtpMailSenderTest（FakeSmtpServer。終わったら finally で stop）
#[Test] public function sendsThroughTheAccount(): void
// SmtpSettings('127.0.0.1', port, 'starttls', 'anpi', 'secret', 'anpi@example.co.jp', '安否確認')、宛先 'taro..yamada@docomo.ne.jp'。
// やり取りに 'AUTH '、'MAIL FROM:<anpi@example.co.jp>'、'RCPT TO:<"taro..yamada"@docomo.ne.jp>'、'Message-ID: <0123abcd@example.co.jp>'、
// 'Auto-Submitted: auto-generated'、'Reply-To: anpi@example.co.jp'。件名を iconv_mime_decode すると '[安否確認 #a1b2c3d4] 地震がありました（駿河湾・最大震度6弱）'
#[Test] public function reportsARefusedRecipientAndKeepsSending(): void
// rejectRecipients ['gone@example.com'] → その宛先は RecipientRefused、次の 'ok@example.com' は送れる（同じインスタンス）
#[Test] public function reportsAnUnavailableServer(): void
// rejectAuth → MailUnavailable。誰も待っていないポート → MailUnavailable
#[Test] public function reportsMissingSettings(): void        // new SmtpMailSender(null) → MailUnavailable（設定が無いという文言）

// NotificationMailTest（Settings は SQLite のメモリ。送信元は anpi@example.co.jp）
#[Test] public function writesTheFirstContact(): void
// 駿河湾の地震（detected_at '2009-08-10T20:07:00Z'、title '駿河湾・最大震度6弱'）、山田 花子、掛川市（勤務地、'5-'、city）→
// 件名 '[安否確認 #a1b2c3d4] 地震がありました（駿河湾・最大震度6弱）'、本文は '山田 花子 さん' で始まり、
// '8月11日 5時07分ごろ、地震がありました（駿河湾・最大震度6弱）。'、'・勤務地：静岡県 掛川市　震度5弱'、'このメールに返信してください。' を含む
#[Test] public function marksResendsAndDrills(): void
// 再送は '[安否確認 #a1b2c3d4] 【再送】地震がありました…'。訓練の地震（kind 'drill'、title '訓練'）は '【訓練】' と、本文の先頭 'これは訓練です。'、
// 市町村の行は '・勤務地：静岡県 掛川市'
#[Test] public function saysHowLateTheQuakeIs(): void        // late の地震を、時計を検知の 5 時間 20 分後にして → 本文の先頭 '5 時間前の地震です。'
#[Test] public function describesUncertainIntensities(): void
// area '6-' → '震度6弱（地域の震度）'、未入電で震度なし → '震度5弱以上の可能性（観測値が未着）'、未入電で '5+' → '震度5強以上の可能性（観測値が未着）'
#[Test] public function writesTheCancelNotice(): void        // 件名 '[安否確認 #a1b2c3d4] 先ほどの連絡は取り消されました'、本文に '返信は不要です。'
#[Test] public function makesUnguessableMessageIds(): void   // 2 通の Message-ID が違い、32 桁の 16 進 + '@example.co.jp'
```

- [ ] **Step 2: 失敗を確かめる**

Run: `vendor/bin/phpunit tests/Mail`
Expected: FAIL

- [ ] **Step 3: 実装する**

- [ ] **Step 4: 通ることを確かめる**

Run: `vendor/bin/phpunit`、`vendor/bin/psalm --no-progress`、`vendor/bin/pint --test`
Expected: OK、No errors found、passed

- [ ] **Step 5: コミット**

```bash
git add -A
git commit -m "feat: compose contact mails and send them over SMTP, quoting addresses the RFC refuses"
```

---

### Task 6：上限を守った送信と再送

設計書 5.3 と、6.1 の 4・6。返信を読む（6.1 の 5）のは計画 4 で、`SendJob` と `ResendJob` の間に入る。

**Files:**
- Create: `src/Mail/MessageRepository.php`、`src/Mail/RateLimit.php`、`src/Mail/NotificationSender.php`、`src/Mail/SendJob.php`、`src/Mail/ResendJob.php`
- Modify: `src/Quakes/NotificationRepository.php`（送る対象の読み出しと状態の更新）、`src/Cron/CronJobs.php`（`[FeedJob, DispatchJob, SendJob, ResendJob]`）
- Test: `tests/Mail/RateLimitTest.php`、`tests/Mail/NotificationSenderTest.php`

**Interfaces:**
- Produces: `final class ReBuji\Mail\MessageRepository`：`__construct(Database $db)`、`record(?int $notificationId, string $kind, string $address, string $messageId, bool $sent, string $error, DateTimeImmutable $at): void`、`countSince(DateTimeImmutable $since): int`、`oldestSince(DateTimeImmutable $since): ?DateTimeImmutable`、`sentAddresses(int $notificationId): list<string>`、`refusedAddresses(int $notificationId): list<string>`（どちらも `EmployeeRepository::addressKey()` で比べ、重なりは 1 つ）
- Produces: `final class ReBuji\Mail\RateLimit`：`__construct(MessageRepository $messages, Settings $settings)`、`remaining(DateTimeImmutable $now): int`（`hourly_limit` から、直近 60 分の `messages` の行の数を引いたもの。拒まれた宛先も数える）、`nextSlotAt(DateTimeImmutable $now): ?DateTimeImmutable`（残りが 0 以下なら、直近 60 分で最も古い行の 60 分後。残りがあれば `null`）
- Produces: `NotificationRepository`：`dueFirstContacts(DateTimeImmutable $now): list<Notification>`（`pending` で `not_before <= now`、`priority` の大きい順、同じなら `employee_id`、`id` の順）、`dueCancelNotices(): list<Notification>`、`dueResends(DateTimeImmutable $now): list<Notification>`（`sent`、`replied_at IS NULL`、`next_send_at <= now`、`next_send_at` の順）、`markFailed(int $id): void`、`markSkipped(int $id): void`、`markCancelNoticeSent(int $id): void`、`stopResending(int $id): void`（`next_send_at` を NULL）、`countWaiting(DateTimeImmutable $now): int`（Task 8 のホーム用。`dueFirstContacts` の数）
- Produces: `final class ReBuji\Mail\NotificationSender`：`__construct(NotificationRepository $notifications, EarthquakeRepository $earthquakes, EmployeeRepository $employees, MessageRepository $messages, RateLimit $limit, NotificationMail $mails, MailSender $sender, Settings $settings, SystemState $state, ClockInterface $clock, LoggerInterface $logger)`、`sendFirstContacts(): void`、`sendCancelNotices(): void`、`sendResends(): void`
- Produces: `SendJob`（`'send'`）は `sendFirstContacts()` の後に `sendCancelNotices()`、`ResendJob`（`'resend'`）は `sendResends()` **[判断]**：初回の連絡がいちばん急ぐ。誤報の取消の知らせは、その次
- Produces: 状態 `mail.last_error`（失敗の理由。成功した送信があり、`MailUnavailable` が無かった回に `''` に戻す）

送り方の決まり：
- 1 人の連絡は、その人の今のアドレス（再送は拒まれたアドレスを除く。取消の知らせは送れたアドレス）すべてに 1 通ずつ送る。残りの上限がその数より少なければ、その回はそこで止める（1 人の連絡を途中で分けない）**[判断]**：分けると、同じ人の残りのアドレスが次の回に初回として送られ、記録がややこしくなる。1 人は 4 通までなので、上限 10 通以上（Task 7）なら必ず進む
- 送る直前に従業員を引き直す。削除されたか停止中なら `skipped`（再送なら `stopResending`）
- `RecipientRefused` は `messages` に `sent = 0` と理由を書いて次のアドレスへ。初回で全部拒まれたら `failed`
- `MailUnavailable` は `mail.last_error` に理由を書き、その回の送信をすべて止める。その人の連絡は、1 通も送れていなければ `pending` のまま。1 通でも送れていれば `sent` にする（残りのアドレスは再送で届く）
- 初回を送れたら `markSent(id, now, resend_count > 0 ? now + resend_interval_minutes : null)`。再送を送れたら、`sent_count` が `1 + resend_count` に届いたら `next_send_at` を NULL、そうでなければ今から `resend_interval_minutes` 後
- 1 回の呼び出しは 120 秒を目安に止める **[判断]**：レンタルサーバーは重い cron を止めることがある（設計書 6.1）。残りは次の回に送る。時計は、始めに 1 回（記録に使う時刻）と、1 人ごとに送る前に 1 回だけ読み、始めてから 120 秒を過ぎていたらその人へ進まない

- [ ] **Step 1: 失敗するテストを書く**

```php
// RateLimitTest（両方の DB）
#[Test] public function countsTheLastHour(): void
// hourly_limit '10'、61 分前に 3 行、30 分前に 4 行（うち 1 行は sent = 0）→ remaining() === 6
#[Test] public function saysWhenTheNextMailCanGo(): void
// 上限ちょうどまで送った → nextSlotAt() は直近 60 分で最も古い行の 60 分後。残りがあれば null

// NotificationSenderTest（SQLite、RecordingMailSender、FrozenClock。地震と連絡はリポジトリで直接作る。送信元は設定に入れておく）
#[Test] public function sendsEachAddressItsOwnMail(): void
// アドレス 2 件の人 → 2 通（Message-ID が違う）、messages 2 行（kind 'first'）、連絡は sent・sent_count 1・next_send_at は 60 分後
#[Test] public function staysWithinTheHourlyLimit(): void
// hourly_limit '10'、アドレス 4 件の人が 3 人 → 1 回目は 2 人（8 通）、3 人目は pending のまま。時計を 61 分進めると 3 人目に 4 通
#[Test] public function sendsTheStrongestFirst(): void
// どちらもアドレス 3 件。先に登録した priority 5 の人と、後に登録した priority 7 の人。hourly_limit '10' のうち 7 通を送ってある（残り 3 通）
// → priority 7 の人だけに 3 通、5 の人は pending のまま
#[Test] public function waitsForTheDelay(): void            // not_before が 1 分後の連絡は送らない
#[Test] public function marksRefusedAddresses(): void
// 2 件のうち 1 件を refuse → sent。全部 refuse → state 'failed'、ResendJob でも送らない
#[Test] public function keepsContactsWhenTheServerIsUnavailable(): void
// unavailable → 1 通も送らず pending のまま、mail.last_error に理由。次の回に送れたら mail.last_error は ''
#[Test] public function skipsStoppedOrDeletedPeople(): void // 連絡を作った後に停止 → 'skipped'。削除 → 'skipped'
#[Test] public function resendsUntilTheConfiguredCount(): void
// resend_count '2'：60 分ごとに件名に '【再送】'（kind 'resend'）。初回を含めて 3 回送った後は next_send_at が NULL。replied_at を入れた人には送らない
#[Test] public function resendsOnlyToAddressesThatWereNotRefused(): void
#[Test] public function sendsTheCancelNoticeToAddressesThatGotTheContact(): void
// cancel_notice 'pending' → 送れたアドレスだけに kind 'cancel'、cancel_notice 'sent'。取り消された連絡は再送しない
#[Test] public function stopsAfterTheTimeBudget(): void
// NotificationSender にだけ、1 回目は t0 を返し、その後は呼ぶたびに 50 秒進む時計を渡す（ほかは止まった時計）。3 人が pending →
// 送るのは 2 人（50 秒・100 秒）、3 人目（150 秒）は pending のまま
```

- [ ] **Step 2: 失敗を確かめる**

Run: `vendor/bin/phpunit tests/Mail`
Expected: FAIL

- [ ] **Step 3: 実装する**

- [ ] **Step 4: 通ることを確かめる**

Run: `vendor/bin/phpunit`、`vendor/bin/psalm --no-progress`、`vendor/bin/pint --test`
Expected: OK、No errors found、passed

- [ ] **Step 5: コミット**

```bash
git add -A
git commit -m "feat: send contacts within the hourly limit, strongest first, and resend to people who have not replied"
```

---

### Task 7：設定の画面

設計書 7 章の「設定」。IMAP の欄は計画 4 で足す。

**Files:**
- Create: `src/Settings/SettingsController.php`、`templates/settings/index.php`
- Modify: `src/Http/AppFactory.php`（`GET`・`POST /settings`、`POST /settings/test-mail`。ログインが要るグループ）、`templates/nav.php`（「設定」）
- Test: `tests/Settings/SettingsPagesTest.php`

**Interfaces:**
- Consumes: `Settings`（Task 1）、`NotificationMail::testMail()`・`MailSender`（Task 5）、`MessageRepository::record()`・`RateLimit`（Task 6）、`EmployeeValidator::isAddress()`（計画 2）
- Produces: `SettingsController`：`show`、`save`、`testMail`。保存に成功したら 302 で `/settings`、フラッシュ `'設定を保存しました。'`。確かめに通らなければ 200 で入力を残して再表示し、何も保存しない
- Produces: 確かめと文言

| 項目 | 範囲 | 文言 |
|---|---|---|
| しきい値 | `4`・`5-`・`5+`・`6-`・`6+`・`7` | `しきい値を選んでください。` |
| 送るまでの待ち時間 | 0〜60 分 | `送るまでの待ち時間は 0〜60 分にしてください。` |
| 遅れて届いた地震とみなす時間 | 1〜72 時間 | `遅れて届いた地震とみなすのは 1〜72 時間にしてください。` |
| 再送の回数 | 0〜5 回 | `再送の回数は 0〜5 回にしてください。` |
| 再送の間隔 | 10〜1440 分 | `再送の間隔は 10〜1440 分にしてください。` |
| 1 時間の上限 | 10〜10000 通 | `1 時間の上限は 10〜10000 通にしてください。` |
| 件名・本文 | 1〜100 字・1〜4000 字 | `件名は 1〜100 文字にしてください。`、`本文は 1〜4000 文字にしてください。` |
| 差し込み | `NotificationMail::PLACEHOLDERS` だけ | `文面に使えない差し込みがあります：{部署}`（`/\{[^}]*\}/u` で見つけたもののうち、知らないものを「、」で並べる） |
| SMTP | どれか 1 つでも入れたら、サーバー名と送信元のアドレスが要る | `SMTP のサーバー名と送信元のアドレスを入れてください。` |
| ポート | 1〜65535 | `ポート番号は 1〜65535 にしてください。` |
| 暗号化 | `ssl`・`starttls` | `暗号化の方式を選んでください。` |
| 送信元のアドレス | `EmployeeValidator::isAddress()` | `送信元のアドレスの形が正しくありません。` |
| 送信元の名前 | 1〜50 字 | `送信元の名前は 1〜50 文字にしてください。` |

- パスワードの欄はいつも空で出し、空のまま保存したら今の値を残す
- 画面に、1 時間の上限の目安を表で出す（設計書 2.1、5.3）：ロリポップ エコノミー 100、ライト 300、スタンダード以上 1,000、さくら 100（15 分に 100 通程度のため）、エックスサーバー 1,500、ConoHa WING はサーバーの案内を確かめる
- テストメール：ログインしている管理者のアドレスに `NotificationMail::testMail()` を送り、`messages` に kind `'test'`・`notification_id` NULL で記録する（上限に数える）。送れたらフラッシュ `'テストメールを {アドレス} に送りました。届いたか確かめてください。'`、`RecipientRefused` か `MailUnavailable` なら 200 で `'テストメールを送れませんでした：{理由}'`。上限に残りが無ければ送らずに `'1 時間の上限に達しているため、テストメールを送れません。'`

- [ ] **Step 1: 失敗するテストを書く**

```php
// SettingsPagesTest（TestApp::installed、ログイン。MailSender は RecordingMailSender で上書き）
#[Test] public function showsTheCurrentSettings(): void
// GET /settings は 200。しきい値の選択肢に '震度4' と '震度7'、'震度5弱' が選ばれている。件名の欄に '地震がありました（{地震}）'。
// パスワードの欄の value は空。目安の表に 'ロリポップ エコノミー'
#[Test] public function savesValidSettings(): void          // 302 /settings、次の GET でフラッシュ、Settings::int('hourly_limit') が保存した値
#[Test] public function refusesValuesOutOfRange(): void
// hourly_limit '5'・delay_minutes '61'・resend_count '6'・本文に '{部署}' → 200 で 4 つの文言、Settings は既定値のまま
#[Test] public function keepsThePasswordWhenLeftEmpty(): void
#[Test] public function sendsATestMailToTheAdmin(): void
// POST /settings/test-mail → RecordingMailSender の宛先は 'admin@example.com'、messages に kind 'test' の行、フラッシュ
#[Test] public function showsWhyTheTestMailFailed(): void  // unavailable → 200 で 'テストメールを送れませんでした：'
```

- [ ] **Step 2: 失敗を確かめる**

Run: `vendor/bin/phpunit tests/Settings`
Expected: FAIL

- [ ] **Step 3: 実装する**

- [ ] **Step 4: 通ることを確かめる**

Run: `vendor/bin/phpunit`、`vendor/bin/psalm --no-progress`、`vendor/bin/pint --test`
Expected: OK、No errors found、passed

- [ ] **Step 5: コミット**

```bash
git add -A
git commit -m "feat: add the settings page for thresholds, timing, limits, wording and SMTP"
```

---

### Task 8：地震の一覧、遅れて届いた地震、ホームの見張り

設計書 7 章の「地震の一覧」（一覧だけ。地震ごとの安否の画面は計画 4）と「遅れて届いた地震」、6.2 の画面の側。

**Files:**
- Create: `src/Quakes/EarthquakesController.php`、`templates/earthquakes/index.php`、`src/Monitor/FeedNoticeController.php`
- Modify: `src/Quakes/EarthquakeRepository.php`（`summaries`、`setDelivery`、`countHeld`）、`src/Quakes/NotificationRepository.php`（`release`、`dismiss`）、`src/HomeController.php`、`templates/home.php`、`src/Http/AppFactory.php`、`templates/nav.php`（「地震」）
- Test: `tests/Quakes/EarthquakePagesTest.php`、`tests/Monitor/HomeTest.php`

**Interfaces:**
- Produces: `EarthquakeRepository::summaries(): list<array{earthquake: Earthquake, total: int, sent: int, waiting: int, failed: int, merged: int}>`（`detected_at` の新しい順。`waiting` は `pending` と `held`）、`setDelivery(int $id, string $delivery): void`、`countHeld(): int`
- Produces: `NotificationRepository::release(int $earthquakeId, DateTimeImmutable $now): void`（`held` を `pending`、`not_before` を今）、`dismiss(int $earthquakeId): void`（`held` を `skipped`）
- Produces: ルート `GET /earthquakes`、`POST /earthquakes/{id:[0-9]+}/release`（delivery を `released`。フラッシュ `'遅れて届いた地震の連絡を送ります。cron が順に送ります。'`）、`POST /earthquakes/{id:[0-9]+}/dismiss`（delivery を `dismissed`。フラッシュ `'遅れて届いた地震の連絡を送らないことにしました。'`）。`held` でない地震や無い id には 404
- Produces: 一覧の列：発生時刻、地震（title。訓練は「訓練」）、対象（total）、送信済み、送信待ち、届かなかった。取り消された地震は「取消」、`held` の地震は「遅れて届いた地震」と出し、「送る」「送らない」のボタンを置く（「送らない」は `data-confirm`）
- Produces: `POST /feed/acknowledge`（`FeedNoticeController::acknowledge`）：`feed.gap_since` と `feed.failed_telegrams` を空にして 302 `/`
- Produces: ホームに足す警告（上から順に）
  - `countHeld() > 0`：`'遅れて届いた地震が {n} 件あります。送るかどうかを決めてください。'`（`/earthquakes` へのリンク）
  - `Settings::smtp() === null`：`'メールの送信の設定がありません。「設定」で SMTP を登録してください。'`
  - `mail.last_error` が空でない：`'メールを送れていません：{理由}'`
  - `countWaiting() > 0` で `RateLimit::nextSlotAt()` があれば `'1 時間の上限のため、{n} 人への連絡が送信待ちです。次に送れるのは {H:i} ごろです。'`、無ければ `'送信待ちの連絡が {n} 件あります。次の cron で送ります。'`
  - `feed.gap_since`：`'気象庁の情報に抜けがあります（{n月j日 G時i分} 以降）。この間の地震は連絡されていない可能性があります。'`、`feed.failed_telegrams` は `'取れなかった電文があります：{title}（{時刻}）'` を 1 件ずつ。どちらかがあれば「確かめた」のボタン（`/feed/acknowledge`）

- [ ] **Step 1: 失敗するテストを書く**

```php
// EarthquakePagesTest（TestApp::installed、ログイン。地震と連絡はリポジトリで作る）
#[Test] public function listsQuakesNewestFirst(): void
// 2 つの地震と 1 つの訓練 → 発生時刻の新しい順。'駿河湾・最大震度6弱'、対象・送信済み・送信待ちの数。取り消された地震に「取消」
#[Test] public function sendsALateQuakeWhenAsked(): void
// held の地震に POST release → 302 /earthquakes、delivery 'released'、連絡は pending・not_before は今
#[Test] public function dropsALateQuakeWhenAsked(): void    // dismiss → delivery 'dismissed'、連絡は 'skipped'
#[Test] public function refusesQuakesThatAreNotHeld(): void // auto の地震や無い id に release → 404

// HomeTest に足す
#[Test] public function warnsAboutLateQuakes(): void
#[Test] public function warnsWhenContactsWaitForTheLimit(): void
// hourly_limit '10' を使い切り、pending の連絡が 2 件 → '1 時間の上限のため、2 人への連絡が送信待ちです。次に送れるのは'
#[Test] public function warnsWhenMailCannotBeSent(): void    // smtp 未設定の文言と、mail.last_error の文言
#[Test] public function warnsAboutTheFeedUntilAcknowledged(): void
// feed.gap_since と feed.failed_telegrams → 両方の文言。POST /feed/acknowledge の後は出ない
```

- [ ] **Step 2: 失敗を確かめる**

Run: `vendor/bin/phpunit tests/Quakes tests/Monitor`
Expected: FAIL

- [ ] **Step 3: 実装する**

- [ ] **Step 4: 通ることを確かめる**

Run: `vendor/bin/phpunit`、`vendor/bin/psalm --no-progress`、`vendor/bin/pint --test`
Expected: OK、No errors found、passed

- [ ] **Step 5: コミット**

```bash
git add -A
git commit -m "feat: list quakes, let admins decide on late ones, and show feed and mail problems at home"
```

---

### Task 9：訓練の送信と README

設計書 4 章の「訓練」と、計画 1 から残した README の「出典と注意」。

**Files:**
- Create: `src/Quakes/DrillsController.php`、`templates/drills/new.php`
- Modify: `src/Quakes/EarthquakeRepository.php`（`createDrill`）、`src/Http/AppFactory.php`（`GET /drills/new`、`POST /drills`）、`templates/nav.php`（「訓練」）、`README.md`
- Test: `tests/Quakes/DrillPagesTest.php`

**Interfaces:**
- Produces: `EarthquakeRepository::createDrill(DateTimeImmutable $now): int`（kind `'drill'`、title `'訓練'`、`detected_at` は今、delivery `'auto'`、late false、event_id・threshold は NULL）
- Produces: `GET /drills/new`：有効な従業員の一覧（チェックボックス、名前・所属）と、「全員に送る」「選んだ人に送る」。`POST /drills`（`target` は `all` か `chosen`、`employees[]`）：訓練を作り、選んだ人（停止中は除く）ごとに連絡を `pending`、priority 0、not_before は今、cities はその人の登録した市町村（`MatchedCity`：intensity NULL、notYetReceived false、basis `'none'`）で作る。302 `/earthquakes`、フラッシュ `'訓練の連絡を {n} 人に送ります。cron が順に送ります。'`。1 人もいなければ 200 で `'送る人を選んでください。'`
- 訓練は DispatchJob の対象にならない（event_id が無い）。近い時間の地震にも含めない（`unansweredQuakeContactsOf` は kind `quake` だけを見る）

README に足すこと：
- 出典と注意：`出典：[気象庁ホームページ「気象庁防災情報XMLフォーマット」](https://xml.kishou.go.jp/)`。Re:無事 と jmaxml は気象庁の公式のソフトウェアではなく、内容の正確さや速さは保証しないこと。公開のフィードは予告なく止まることがあること。実際の防災の行動は、気象庁や自治体などの公式の発表に従うこと
- cron は 1 分ごとにできるサーバーではそうする（最初の連絡まで最大 5 分ほど遅れるため。設計書 2.1）
- 1 時間の上限：人数 × アドレスの数が上限を超えると、最後の人にはさらに遅れて届く。安いプランが向くのは数十人までの組織
- 設定の画面で SMTP を登録し、テストメールで確かめること。返信を受けるアドレスと同じアカウントを使うこと（設計書 5.1）
- 「.」が続く携帯のアドレスは、引用符で囲んで送ること
- 訓練の送信のしかた

- [ ] **Step 1: 失敗するテストを書く**

```php
// DrillPagesTest（TestApp::installed、ログイン）
#[Test] public function sendsADrillToEveryone(): void
// 有効 2 人・停止 1 人で target 'all' → 訓練 1 行（kind 'drill'、title '訓練'）、連絡 2 行（pending、priority 0、
// cities は登録した市町村で basis 'none'）。302 /earthquakes、フラッシュ '訓練の連絡を 2 人に送ります。'
#[Test] public function sendsADrillToChosenPeople(): void  // target 'chosen'、employees[] に 1 人 → 連絡 1 行
#[Test] public function refusesADrillWithNobody(): void    // target 'chosen' で誰も選ばない → 200 で '送る人を選んでください。'、訓練は作らない
```

- [ ] **Step 2: 失敗を確かめる**

Run: `vendor/bin/phpunit tests/Quakes`
Expected: FAIL

- [ ] **Step 3: 実装する**

- [ ] **Step 4: 通ることを確かめる**

Run: `vendor/bin/phpunit`、`vendor/bin/psalm --no-progress`、`vendor/bin/pint --test`、`composer normalize --dry-run`
Expected: OK、No errors found、passed、already normalized

- [ ] **Step 5: 実機に近い確認**

作業ツリーの写しを `PHP_CLI_SERVER_WORKERS=4 php -S … -t public public/index.php` で動かし、設置済みの状態で次を確かめる（計画 1・2 と同じやり方。止めるときは PID で）。
- `FakeSmtpServer` と同じスクリプトを手で動かし、設定の画面でそのポートを登録して、テストメールが届く
- 駿河湾の電文を `TelegramRepository::store()` で入れてから `php bin/cron.php` を 1 回動かす（フィードは `https_proxy` で外へ出さない）。2009 年の地震なので、地震の一覧に「遅れて届いた地震」として出て、メールはまだ送られない。「送る」を押して cron をもう 1 回動かすと、掛川市の従業員にメールが届き、本文の先頭に「… 時間前の地震です。」がある

- [ ] **Step 6: コミット**

```bash
git add -A
git commit -m "feat: send drills to everyone or chosen people and document sources and sending limits"
```

---

## 計画 3 で作らないもの（計画 4 へ渡すこと）

| 内容 | 渡し方 |
|---|---|
| IMAP で返信を読む、返事の照合（設計書 5.2） | `ReplyJob` を `CronJobs::all()` の `SendJob` と `ResendJob` の間に入れる。照合に使うのは、`In-Reply-To`・`References` は `messages.message_id`、件名の番号は `notifications.token`、送り主は `employee_emails.address_key` から直近の未回答の連絡。照合できたら `notifications.replied_at` を入れる（再送はそこで止まる） |
| 自動応答、宛先不明で戻ったメール | 自動応答は `replied_at` を入れない。戻ったメールは、その宛先の `messages` を「届かなかった」にする。すべてのアドレスが届かなければ連絡を `failed` にする |
| 責任者の画面（設計書 5.6）、地震ごとの安否 | 一覧（Task 8）から地震ごとの画面へ。「送信待ち（上限のため、予定時刻）」の時刻は `RateLimit::nextSlotAt()`。`merged` の人は含めた先の連絡の状態を出す |
| 照合できなかったメール、代理回答（設計書 5.4） | |
| 見張りのメール（設計書 6.2） | フィードの抜け（`feed.gap_since`）、取れなかった電文（`feed.failed_telegrams`）、送信の失敗（`mail.last_error`）、上限による送信待ち（`countWaiting`）を管理者へメールで知らせる |
| 初期設定のテスト送受信（設計書 6.3 の 3）、IMAP の設定 | `InstallSteps` の `finish` の前に `mail`。設定は `Settings` に保存し、テストメールは `NotificationMail::testMail()` |
| 古い地震の記録を消す（設計書 6.5） | 地震を消すと、連絡と送ったメールも消える（`ON DELETE CASCADE`）。その EventID の電文も消す |

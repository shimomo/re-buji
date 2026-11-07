# Re:無事 計画 4：返信と画面 実装計画

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 返信用のメールボックスを IMAP で読み、返信を連絡に結び付けて「無事」「要確認」を記録する。不在の自動応答や、宛先不明で戻ってきたメールは無事と数えない。責任者の画面、代理回答、照合できなかったメールの一覧、見張りのメール、初期設定でのテスト送受信、古い記録の削除をそろえ、設計書の v0.1 の範囲を満たす。

**Architecture:**
- IMAP は自前の `ImapClient` で読み、アプリには `Mailbox`（未読の取得と既読の印）だけを見せる。
- 受け取ったメールは `IncomingMail` が zbateson/mail-mime-parser で解析し、宛先不明・自動応答・返信に分ける。
- `ReplyMatcher` が設計書 5.2 の順で連絡を探し、`ReplyRecorder` が連絡と送ったメールの記録を更新する。この 2 つを `ReplyJob` が cron の `SendJob` と `ResendJob` の間で動かす。
- 責任者の画面の中身は `QuakeBoard` が組み立て、コントローラーは表示だけをする。

**Tech Stack:** 計画 3 と同じものに、`zbateson/mail-mime-parser` ^3.0 を足す（設計書 2.2）。依存で `guzzlehttp/psr7`、`zbateson/mb-wrapper`、`zbateson/stream-decorators` が入る。PHP-DI はすでにある。

**Spec:** `docs/superpowers/specs/2026-11-05-re-buji-design.md`（5.2、5.4、5.5、5.6、6.2 のメール、6.3 の 2〜3、6.5、7 章の残り）

**前の計画：** `docs/superpowers/plans/2026-11-05-re-buji-quakes-and-sending.md`（PR #4 でマージ済み）。その「計画 4 へ渡すこと」の表をすべてこの計画で扱う。計画 3 の最終レビューで後回しにした minor のうち、TLS を必須にすることと取消の知らせの文言の 2 件は Task 1 で直す **[判断]**：前者は安全に関わり、後者は返信の照合と同じく、責任者と従業員の混乱を減らすため。ほかの 11 件は計画 4 の範囲外。

| 渡されたこと | 扱うタスク |
|---|---|
| IMAP で返信を読む、返事の照合 | Task 3、4、5 |
| 自動応答、宛先不明で戻ったメール | Task 4、5 |
| 責任者の画面、`merged` の人の状態 | Task 6 |
| 照合できなかったメール、代理回答 | Task 7 |
| 見張りのメール | Task 9 |
| 初期設定のテスト送受信、IMAP の設定 | Task 8、10 |
| 古い地震の記録を消す | Task 11 |

## Global Constraints

- 計画 1〜3 の Global Constraints をすべて引き継ぐ（PHP `^8.3`、SQLite の PRAGMA、UTC の時刻の文字列、名前空間、`.htaccess`、`setUp` / `tearDown` を使わない、テストでは日付に依る値に `FrozenClock` を使う、コミットメッセージの末尾）
- ext-imap は使わない（設計書 2.1）。IMAP は `stream_socket_client` の上に自前で書き、ログインは TLS の上でだけ行う（`ssl` は 993 番、`starttls` は 143 番で STARTTLS を必須にする）。`none` はテストの偽のサーバーのためだけに `ImapClient` が受け付け、設定の画面には出さない **[判断]**
- 1 回の cron で読むのは最大 50 通・60 秒まで。古い順に読み、読んだメールには `\Seen` の印を付ける。メールボックスからは消さない（設計書 5.2）
- 読むのは、IMAP の設定を保存してから届いたメールだけ（`UID SEARCH UNSEEN SINCE`、状態 `imap.since`）**[判断]**：設置した時点のメールボックスに残っていた古い未読のメールを、照合できなかったメールとして並べないため
- 本文は引用を除いた部分だけを記録し、空なら「無事」、何か書いてあれば「要確認」とする。言葉による自動の振り分けはしない（設計書 5.6）
- 不在の自動応答と、宛先不明で戻ったメールは、返信として数えない（設計書 5.2 のアンチゴール）
- 照合の順は設計書 5.2 のとおり：① `In-Reply-To`・`References` を送ったメールの Message-ID と比べる → ② 件名の `#番号` → ③ 送り主のアドレスを登録済みのアドレスと比べ、その人の直近の未回答の連絡 → ④ どれでもなければ「照合できなかったメール」
- 画面の時刻は Asia/Tokyo の `G:i`（日付が要るところは `n月j日 G:i`）
- `bin/build-release.sh` の `vendor/` は 1,800 ファイル以下（設計書 2.2 の目安は約 1,460）

## Review Focus

- 返信の引用の書き方は、メールの道具ごとに違う（`>` の行、「…は書きました：」、iPhone の「…のメール:」、Gmail の「<アドレス>:」、Outlook の「差出人:」の見出し、署名の区切り）→ 本人が書いた部分だけを記録する。何も書いていなければ「無事」（Task 4）
- 不在の自動応答（`Auto-Submitted: auto-replied`、`X-Autoreply`、件名の「自動応答」「不在」）と、宛先不明で戻ったメール（`multipart/report`。これも `Auto-Submitted` を持つ）→ 返信と数えず再送を続ける。戻ったメールの宛先は「届かなかった」にする（Task 4、Task 5）
- 同じメールを 2 回読む（記録した後、既読の印を付ける前に処理が落ちた）→ 二重に数えない（Task 5）
- 登録していないアドレスや、番号の無い件名からの返信（私用のアドレスから送った、転送した）→ 照合できなかったメールに出る。責任者はそれを見て「代理で記録」できる（Task 5、Task 7）
- 設置した時点で、メールボックスに未読のメールが何百通もある → 照合できなかったメールとして並べない。1 回の cron は 50 通・60 秒で区切る（Task 5）

## ファイルの構成

```
src/Mail/Imap/        # ImapClient、ImapSettings、Mailbox、ImapMailbox、RawMail、MailboxUnavailable
src/Replies/          # IncomingMail、Bounce、ReplyText、ReplyMatcher、ReplyMatch、ReplyRecorder、ReplyRepository、ReplyJob、
                      # UnmatchedController
src/Quakes/Board/     # QuakeBoard、BoardRow
src/Quakes/ContactsController.php    # 確認済みにする、代理で記録
src/Monitor/WatchJob.php
src/Settings/SettingsForm.php        # 設定の画面と初期設定で共通の確かめ
src/Install/Steps/MailStep.php、ReplyStep.php
migrations/M0004CreateReplyTables.php
templates/earthquakes/show.php、templates/contacts/proxy.php、templates/mail/unmatched.php、
templates/install/mail.php、templates/install/reply.php
tests/Support/        # FakeImapServer、fake-imap-server.php、FakeMailbox、MailFixtures
```

## 連絡の答え（`notifications` に足す列）

| 列 | 意味 |
|---|---|
| `answer` | `NULL`（返信なし）、`'safe'`（空の返信、または代理で無事）、`'needs_check'`（本文のある返信、または代理で要確認） |
| `answer_source` | `'mail'` か `'proxy'` |
| `answered_by`・`proxy_note` | 代理で記録した管理者の名前とメモ（設計書 5.4） |
| `auto_replied_at` | 不在の自動応答だけが届いた時刻。返信には数えない |
| `checked_at`・`checked_by` | 要確認を「確認済みにする」を押した時刻と管理者。新しい本文の返信が届いたら `NULL` に戻す |

`replied_at`（計画 3 からある列）は、最初の返信か代理の記録の時刻。これが入ると再送が止まる。`answer` は弱くならない（`needs_check` の後に空の返信が届いても `safe` に戻さない）。

---

### Task 1：計画 3 から持ち越した 2 件

**Files:**
- Modify: `src/Mail/SmtpMailSender.php`、`src/Mail/NotificationMail.php`
- Test: `tests/Mail/SmtpMailSenderTest.php`、`tests/Mail/NotificationMailTest.php`

**Interfaces:**
- Produces: 暗号化が `starttls` のとき `EsmtpTransport::setRequireTls(true)`。サーバーが STARTTLS を示さなければ `MailUnavailable`（パスワードを暗号化せずに送らない）。`'none'` は TLS なし（`tls` false、`requireTls` false）で、テストの偽のサーバーのためだけに受け付ける。設定の画面の選択肢は `ssl` と `starttls` のまま
- Produces: 取消の知らせの本文を `"{名前} さん\n\n{発生時刻}ごろの地震（{地震}）について先ほどお送りした安否確認の連絡は、気象庁が地震の情報を取り消したため、取り消します。返信は不要です。\n"` にする（差し込みは `compose()` と同じ値）

- [ ] **Step 1: 失敗するテストを書く**

```php
// SmtpMailSenderTest
#[Test] public function refusesToLogInWithoutTls(): void
// FakeSmtpServer（STARTTLS を示さない）に 'starttls' の設定で送る → MailUnavailable。やり取りに 'AUTH' が無い
// ほかの SmtpMailSenderTest は、偽のサーバーに TLS が無いので、SmtpSettings の暗号化に 'none' を使うよう書き換える
// （SmtpMailSender は 'none' を TLS なしとして受け付ける。設定の画面には出さない）

// NotificationMailTest
#[Test] public function writesTheCancelNotice(): void
// 本文が '山田 花子 さん\n\n8月11日 5時07分ごろの地震（駿河湾・最大震度6弱）について先ほどお送りした…返信は不要です。\n'
```

- [ ] **Step 2: 失敗を確かめる**

Run: `vendor/bin/phpunit tests/Mail`
Expected: FAIL（STARTTLS なしでも送れてしまう、取消の文言が古い）

- [ ] **Step 3: 実装する**

- [ ] **Step 4: 通ることを確かめる**

Run: `vendor/bin/phpunit`、`vendor/bin/psalm --no-progress`、`vendor/bin/pint --test`
Expected: OK、No errors found、passed

- [ ] **Step 5: コミット**

```bash
git add -A
git commit -m "fix: require TLS before logging in over STARTTLS, and say which quake a cancel notice cancels"
```

---

### Task 2：依存、表、受信の設定

**Files:**
- Modify: `composer.json`、`composer.lock`（`zbateson/mail-mime-parser` ^3.0）
- Create: `migrations/M0004CreateReplyTables.php`、`src/Mail/Imap/ImapSettings.php`、`src/Replies/ReplyRepository.php`
- Modify: `src/Database/Migrations.php`、`src/Settings/Settings.php`、`src/Quakes/Notification.php`、`src/Quakes/NotificationRepository.php`、`src/Mail/MessageRepository.php`
- Test: `tests/Database/ReplyTablesTest.php`、`tests/Settings/SettingsTest.php`、`tests/Replies/ReplyRepositoryTest.php`

**Interfaces:**
- Produces: M0004（説明 `'返信、連絡の答え、戻ってきたメール'`）
  - `replies`：`id`、`notification_id NULL`（`ON DELETE CASCADE`。照合できなかったメールは NULL）、`kind VARCHAR(16)`（`'reply'`・`'auto_reply'`・`'bounce'`・`'unreadable'`）、`matched_by VARCHAR(16) NULL`（`'thread'`・`'number'`・`'sender'`）、`from_address VARCHAR(255)`、`subject VARCHAR(255)`、`body TEXT`、`message_id VARCHAR(255) NULL UNIQUE`、`received_at`、`created_at`。`notification_id` に索引
  - `notifications` に `answer VARCHAR(16) NULL`、`answer_source VARCHAR(8) NULL`、`answered_by VARCHAR(255) NULL`、`proxy_note TEXT NULL`、`auto_replied_at NULL`、`checked_at NULL`、`checked_by VARCHAR(255) NULL` を足す（`ALTER TABLE … ADD COLUMN` を 1 列ずつ。SQLite も MySQL もこの形で動く）
  - `messages` に `bounced_at NULL`、`bounce_reason TEXT NULL` を足す
- Produces: `Settings::DEFAULTS` に `imap.host` `''`、`imap.port` `'993'`、`imap.encryption` `'ssl'`、`imap.username` `''`、`imap.password` `''`。`Settings::imap(): ?ImapSettings`（`imap.host` が空なら `null`。ユーザー名とパスワードが空なら SMTP のものを使う **[判断]**：返信を受けるのは送信と同じアカウント（設計書 5.1）なので、たいていは同じ）
- Produces: `final readonly class ReBuji\Mail\Imap\ImapSettings`：`__construct(string $host, int $port, string $encryption /* 'ssl'|'starttls'|'none' */, string $username, string $password)`
- Produces: `Notification` に、上の表の列を、コンストラクタの最後の引数として既定値つきで足す（`?string $answer = null`、`?string $answerSource = null`、`?string $answeredBy = null`、`?string $proxyNote = null`、`?DateTimeImmutable $autoRepliedAt = null`、`?DateTimeImmutable $checkedAt = null`、`?string $checkedBy = null`）。`NotificationRepository::COLUMNS` と `hydrate()` も合わせる
- Produces: `final class ReBuji\Replies\ReplyRepository`：`__construct(Database $db, ClockInterface $clock)`
  - `record(?int $notificationId, string $kind, ?string $matchedBy, string $fromAddress, string $subject, string $body, ?string $messageId, DateTimeImmutable $receivedAt): int`（件名は 255 文字、本文は 4000 文字で切る）
  - `knows(string $messageId): bool`
  - `forNotification(int $notificationId): list<array{kind: string, body: string, receivedAt: DateTimeImmutable}>`（古い順）
  - `unmatched(): list<array{id: int, kind: string, fromAddress: string, subject: string, body: string, receivedAt: DateTimeImmutable}>`（新しい順）
  - `countUnmatched(): int`、`deleteUnmatched(int $id): bool`（照合できなかったものだけを消せる）
- Produces: `MessageRepository`：`findByMessageId(string $messageId): ?array{id: int, notificationId: ?int, address: string}`、`latestSentTo(string $address): ?array{id: int, notificationId: ?int, address: string}`（`sent = 1` の最新）、`markBounced(int $id, string $reason, DateTimeImmutable $at): void`、`deliveredAddresses(int $notificationId): list<string>`（`sent = 1` で戻ってきていないもの）。`refusedAddresses()` は、戻ってきたアドレスも含める（再送で使わない）

- [ ] **Step 1: 依存を足す**

Run: `composer require "zbateson/mail-mime-parser:^3.0"`、`composer show zbateson/mail-mime-parser | grep versions`
Expected: 3.0.x（3.0.9 で確かめた。PHP 8.1 以上）

- [ ] **Step 2: 失敗するテストを書く**

```php
// ReplyTablesTest（両方の DB）
#[Test] public function removesRepliesWithTheEarthquake(): void      // 地震を消すと replies の行も消える
#[Test] public function keepsUnmatchedMailsWithoutAContact(): void    // notification_id NULL の行を入れられる
#[Test] public function refusesTheSameIncomingMailTwice(): void        // 同じ message_id の 2 行目は PDOException

// SettingsTest
#[Test] public function hasImapOnceTheHostIsSaved(): void
// imap.host が空なら null。host を保存し、ユーザー名とパスワードが空なら smtp.username・smtp.password を使う
// （ImapSettings('imap.example.co.jp', 993, 'ssl', 'anpi', 'secret')）

// ReplyRepositoryTest（両方の DB）
#[Test] public function keepsRepliesAndUnmatchedMailsApart(): void
// 連絡に結び付いた返信 1 件と、結び付かないもの 2 件 → forNotification() は 1 件、unmatched() は新しい順に 2 件、countUnmatched() は 2。
// deleteUnmatched() は結び付いた行には false、照合できなかった行には true
#[Test] public function cutsLongSubjectsAndBodies(): void              // 300 文字の件名は 255、5,000 文字の本文は 4,000 で保存

// NotificationRepositoryTest：roundTripsAContact に、新しい列が NULL のまま往復することを足す
// MessageRepository の新しい読み出しは Task 5 のテストで確かめる
```

- [ ] **Step 3: 失敗を確かめる**

Run: `vendor/bin/phpunit tests/Database tests/Settings tests/Replies tests/Quakes/NotificationRepositoryTest.php`
Expected: FAIL

- [ ] **Step 4: 実装する**

- [ ] **Step 5: 通ることを確かめる**

Run: `vendor/bin/phpunit`、`vendor/bin/psalm --no-progress`、`vendor/bin/pint --test`、`composer normalize --dry-run`
Expected: OK、No errors found、passed、already normalized

- [ ] **Step 6: コミットし、配布物のファイル数を確かめる**

```bash
git add -A
git commit -m "feat: add the reply tables, contact answers and the IMAP settings"
bin/build-release.sh 0.0.0-check && rm build/re-buji-0.0.0-check.zip
```

Expected: `vendor/ files:` が 1,800 以下。数を台帳に残す

---

### Task 3：IMAP で読む

設計書 2.2 の「IMAP は自前で書く」。要るのは、ログイン、受信箱を開く、未読の検索、取得、既読の印、ログアウトだけ。

**Files:**
- Create: `src/Mail/Imap/ImapClient.php`、`src/Mail/Imap/Mailbox.php`、`src/Mail/Imap/ImapMailbox.php`、`src/Mail/Imap/RawMail.php`、`src/Mail/Imap/MailboxUnavailable.php`
- Create: `tests/Support/FakeImapServer.php`、`tests/Support/fake-imap-server.php`、`tests/Support/FakeMailbox.php`
- Modify: `src/Container.php`（`Mailbox::class` は `new ImapMailbox($settings->imap())`）、`psalm.xml.dist`（`fake-imap-server.php` を対象から外す）
- Test: `tests/Mail/Imap/ImapClientTest.php`

**Interfaces:**
- Produces: `final class ReBuji\Mail\Imap\ImapClient`：`__construct(ImapSettings $settings, int $timeoutSeconds = 15)`
  - `open(): void`：つなぐ（`ssl` は `ssl://host:port`、`starttls` は平文でつないで `STARTTLS` を送り `stream_socket_enable_crypto`。サーバーの CAPABILITY に STARTTLS が無ければ失敗）→ `LOGIN "user" "pass"`（`\` と `"` は逃がす）→ `SELECT INBOX`
  - `unseenSince(DateTimeImmutable $since): list<int>`（`UID SEARCH UNSEEN SINCE 8-Nov-2026` の結果を小さい順）
  - `fetch(int $uid): string`（`UID FETCH {uid} (BODY.PEEK[])`。`{n}` のリテラルを読む）
  - `markSeen(int $uid): void`（`UID STORE {uid} +FLAGS.SILENT (\Seen)`）
  - `close(): void`（`LOGOUT`。失敗しても投げない）
  - 失敗（つながらない、`NO`・`BAD`、時間切れ、切断）は `MailboxUnavailable`（メッセージにサーバーの答えの行。パスワードは含めない）
- Produces: `interface ReBuji\Mail\Imap\Mailbox`：`unseen(DateTimeImmutable $since, int $limit): list<RawMail>`（古い順に最大 `$limit` 通）、`markSeen(RawMail $mail): void`。失敗は `MailboxUnavailable`
- Produces: `final readonly class RawMail`：`__construct(int $uid, string $raw)`
- Produces: `final class ImapMailbox implements Mailbox`：`__construct(?ImapSettings $settings)`。`null` なら `MailboxUnavailable('返信を読む設定（IMAP）がありません。「設定」で登録してください。')`。1 つのインスタンスの中で接続を使い回し、`__destruct` で閉じる
- Produces（テスト）：`final class FakeMailbox implements Mailbox`：`add(string $raw): int`（uid を返す）、`$seen`（`list<int>`）、`$unavailable`、`$asked`（受け取った `$since` と `$limit`）
- Produces（テスト）：`final class FakeImapServer`：`static start(string $mailDir, array{rejectLogin?: bool} $options = []): self`、`port()`、`transcript()`、`stop()`。`$mailDir` の `{uid}.eml` を受信箱とし、既読は `{uid}.seen` の印のファイルで持つ。CAPABILITY（`IMAP4rev1 AUTH=PLAIN`。STARTTLS は示さない）、LOGIN、SELECT、`UID SEARCH UNSEEN …`（SINCE は受け取って無視する）、`UID FETCH … (BODY.PEEK[])`、`UID STORE … +FLAGS.SILENT (\Seen)`、LOGOUT に答える

- [ ] **Step 1: 失敗するテストを書く**

```php
// ImapClientTest（FakeImapServer。暗号化は 'none'。finally で stop）
#[Test] public function readsUnseenMailOldestFirst(): void
// 1.eml・2.eml・3.eml（2 は既読）→ unseenSince('2026-11-08') は [1, 3]。fetch(3) は 3.eml と同じバイト列（日本語の ISO-2022-JP の本文を含む）。
// やり取りに 'UID SEARCH UNSEEN SINCE 8-Nov-2026'
#[Test] public function marksMailSeenWithoutDeletingIt(): void         // markSeen(1) の後は [3]。1.eml は残る。'EXPUNGE' を送らない
#[Test] public function quotesTheLogIn(): void                          // パスワード 'pa"ss\word' → 'LOGIN "anpi" "pa\"ss\\word"'
#[Test] public function reportsARefusedLogIn(): void                    // rejectLogin → MailboxUnavailable。メッセージにパスワードが無い
#[Test] public function reportsAServerNobodyAnswers(): void             // 9 番ポート → MailboxUnavailable
#[Test] public function refusesStartTlsWhenTheServerDoesNotOfferIt(): void  // 'starttls' → MailboxUnavailable。'LOGIN' を送らない
#[Test] public function readsAtMostTheLimit(): void                     // ImapMailbox で 5 通のうち unseen($since, 2) は uid 1・2 の 2 通
#[Test] public function saysWhenImapIsNotSetUp(): void                  // new ImapMailbox(null) → MailboxUnavailable（設定が無いという文言）
```

- [ ] **Step 2: 失敗を確かめる**

Run: `vendor/bin/phpunit tests/Mail/Imap`
Expected: FAIL

- [ ] **Step 3: 実装する**

応答の読み方：1 行ずつ読み、行の末尾が `{n}` ならその `n` バイトをそのまま読んでから続きの行を読む。自分の付けた札（`a1`、`a2` …）の行で `OK` なら終わり、`NO`・`BAD` なら `MailboxUnavailable`。

- [ ] **Step 4: 通ることを確かめる**

Run: `vendor/bin/phpunit`、`vendor/bin/psalm --no-progress`、`vendor/bin/pint --test`
Expected: OK、No errors found、passed

- [ ] **Step 5: コミット**

```bash
git add -A
git commit -m "feat: read the reply mailbox over IMAP with a small client of our own"
```

---

### Task 4：受け取ったメールの読み方

**Files:**
- Create: `src/Replies/IncomingMail.php`、`src/Replies/Bounce.php`、`src/Replies/ReplyText.php`、`tests/Support/MailFixtures.php`
- Test: `tests/Replies/IncomingMailTest.php`、`tests/Replies/ReplyTextTest.php`

**Interfaces:**
- Produces: `final readonly class ReBuji\Replies\IncomingMail`：`messageId ?string`、`references list<string>`（`In-Reply-To` の後に `References`。`<…>` を外し、重複を除く）、`subject string`（復号済み）、`fromAddress string`、`date ?DateTimeImmutable`、`text string`（`text/plain` の本文。無ければ HTML からタグを除いたもの）、`autoReply bool`、`bounce ?Bounce`。`static parse(string $raw): self`（読めなければ `InvalidArgumentException`）、`kind(): string`（`'bounce'`・`'auto_reply'`・`'reply'`）
- Produces: `final readonly class Bounce`：`recipients list<string>`（`message/delivery-status` の `Final-Recipient: rfc822; …` で `Action: failed` のもの。`Action` が無ければすべて）、`originalMessageId ?string`（`message/rfc822` か `text/rfc822-headers` の部分の `Message-ID`。無ければ全体から自分の Message-ID 以外の `Message-ID:` 行）、`reason string`（`Status` と `Diagnostic-Code`。無ければ件名）
- 宛先不明と見なす：`multipart/report` で `report-type=delivery-status`、または送り主のローカル部が `mailer-daemon`・`postmaster`（大文字小文字を問わない）。宛先不明を先に判定する（DSN は `Auto-Submitted` も持つ）
- 自動応答と見なす（宛先不明でないとき）：`Auto-Submitted` があって `no` 以外、`X-Autoreply`・`X-Autorespond` がある、`Precedence` が `auto_reply`・`bulk`・`junk`、件名が `/自動応答|自動返信|不在|out of office|automatic reply|auto[- ]?reply/iu` に当たる
- Produces: `final class ReplyText`：`static extract(string $text): string`。改行をそろえ、次の行より後を捨てる：`-----Original Message-----` などの `/^-{2,}\s*(original message|元のメッセージ|forwarded message|転送されたメッセージ)/iu`、`/^On .+wrote:\s*$/i`、`/書きました[:：]?\s*$/u`、`/のメール[:：]\s*$/u`（iPhone）、`/<[^<>\s]+@[^<>\s]+>\s*[:：]\s*$/u`（Gmail の「… <アドレス>:」）、`/^(from|差出人|送信者)\s*[:：]/iu`（Outlook の見出し）、`/^_{5,}\s*$/`、署名の区切り `/^--\s?$/`、`[安否確認 #` を含む行。残りから `>` で始まる行を除き、前後の空白（全角も）を取り、3 行以上の空行は 2 行に縮める
- Produces（テスト）：`final class MailFixtures`：`reply(string $from, string $body, ?string $inReplyTo = null, string $subject = 'Re: [安否確認 #a1b2c3d4] 地震がありました', string $charset = 'UTF-8', ?string $messageId = null): string`、`autoReply(string $from, ?string $inReplyTo = null): string`、`bounce(string $recipient, ?string $originalMessageId): string`（Postfix の DSN の形）

- [ ] **Step 1: 失敗するテストを書く**

```php
// IncomingMailTest
#[Test] public function readsAReply(): void
// ISO-2022-JP の件名と本文、In-Reply-To <0123abcd@example.co.jp>、References に別の 2 つ → references は 3 つ（In-Reply-To が先頭）、
// fromAddress 'Taro.Yamada@Example.com'、subject と text は UTF-8、kind() 'reply'
#[Test] public function readsTheTextOfAnHtmlOnlyReply(): void           // text/html だけ → タグを除いた本文
#[Test] public function tellsAutoRepliesApart(): void
// Auto-Submitted: auto-replied／X-Autoreply: yes／Precedence: bulk／件名「【自動応答】不在にしています」→ kind() 'auto_reply'。Auto-Submitted: no は 'reply'
#[Test] public function readsABounce(): void
// MailFixtures::bounce('gone@example.com', '0123abcd@example.co.jp') → kind() 'bounce'、recipients ['gone@example.com']、
// originalMessageId '0123abcd@example.co.jp'、reason に '5.1.1'。DSN の Auto-Submitted があっても 'auto_reply' にしない
#[Test] public function readsABounceFromMailerDaemonWithoutAReport(): void
// From: MAILER-DAEMON@…、text/plain で元のメールのヘッダーが本文に入っている → 'bounce'、originalMessageId を本文から拾う
#[Test] public function refusesWhatIsNotMail(): void                     // '' → InvalidArgumentException

// ReplyTextTest（どれも本人の言葉だけが残る）
#[Test] public function keepsAnEmptyReplyEmpty(): void                   // "\n\n> 山田 花子 さん\n> 地震がありました\n" → ''
#[Test] public function dropsQuotesAfterTheWrittenLine(): void
// "無事です。\n\n2026年11月5日(木) 12:12 安否確認 <anpi@example.co.jp>:\n> 山田 花子 さん\n" → '無事です。'
#[Test] public function dropsTheIphoneQuote(): void                      // "足を怪我しました\n\n2026/11/05 12:12、安否確認 <anpi@example.co.jp>のメール:\n\n> …" → '足を怪我しました'
#[Test] public function dropsTheOutlookHeaderBlock(): void               // "出社できません\r\n\r\n差出人: 安否確認 <anpi@…>\r\n送信日時: …\r\n件名: …" → '出社できません'
#[Test] public function dropsTheJapaneseWroteLine(): void                // "家族も無事\n\n安否確認 さんは書きました：\n山田 花子 さん…" → '家族も無事'
#[Test] public function dropsTheSignature(): void                        // "無事\n-- \n山田 花子\n静岡支店" → '無事'
#[Test] public function dropsOurOwnSubjectQuotedWithoutMarks(): void     // "大丈夫です\n件名: [安否確認 #a1b2c3d4] 地震…\n山田 花子 さん" → '大丈夫です'
#[Test] public function keepsFullWidthSpacesOnlyAsEmpty(): void          // "　\n　" → ''
```

- [ ] **Step 2: 失敗を確かめる**

Run: `vendor/bin/phpunit tests/Replies`
Expected: FAIL

- [ ] **Step 3: 実装する**

解析は `ZBateson\MailMimeParser\Message::from($raw, false)`。`In-Reply-To`・`References` は生の値から `/<([^<>]+)>/` で取る。DSN は `getAllPartsByMimeType('message/delivery-status')` の `getContent()` を段落に分けて読む。

- [ ] **Step 4: 通ることを確かめる**

Run: `vendor/bin/phpunit`、`vendor/bin/psalm --no-progress`、`vendor/bin/pint --test`
Expected: OK、No errors found、passed

- [ ] **Step 5: コミット**

```bash
git add -A
git commit -m "feat: read incoming mail into replies, auto-replies and bounces, keeping only the person's own words"
```

---

### Task 5：返事の照合と記録

設計書 5.2。cron の順は `[FeedJob, DispatchJob, SendJob, ReplyJob, ResendJob]`（返信を読んでから再送する）。

**Files:**
- Create: `src/Replies/ReplyMatcher.php`、`src/Replies/ReplyMatch.php`、`src/Replies/ReplyRecorder.php`、`src/Replies/ReplyJob.php`
- Modify: `src/Quakes/NotificationRepository.php`、`src/Mail/MessageRepository.php`（`refusedAddresses()`）、`src/Cron/CronJobs.php`（`imap.since` は `SystemState` に持つ）
- Test: `tests/Replies/ReplyMatcherTest.php`、`tests/Replies/ReplyJobTest.php`

**Interfaces:**
- Produces: `final readonly class ReplyMatch`：`__construct(int $notificationId, string $by /* 'thread'|'number'|'sender' */)`
- Produces: `final class ReplyMatcher`：`__construct(Database $db, MessageRepository $messages)`、`match(IncomingMail $mail): ?ReplyMatch`
  - ① `references` のどれかが `messages.message_id` にあれば、その連絡（`thread`）
  - ② 件名の `/#([0-9a-f]{8})\b/` が `notifications.token` にあれば、その連絡（`number`）
  - ③ 送り主の `EmployeeRepository::addressKey()` が `employee_emails.address_key` にあれば、その人の連絡のうち `sent_count > 0`・`replied_at IS NULL` で `last_sent_at` が最も新しいもの（`sender`）
- Produces: `NotificationRepository`：`recordAnswer(int $id, string $answer /* 'safe'|'needs_check' */, string $source, ?string $by, ?string $note, DateTimeImmutable $at): void`（`replied_at` は最初の時刻を残す。`answer` は `needs_check` から `safe` に戻さない。`needs_check` を記録したら `checked_at`・`checked_by` を NULL に戻す。`next_send_at` を NULL）、`recordAutoReply(int $id, DateTimeImmutable $at): void`（`answer` が NULL のときだけ）、`markChecked(int $id, string $by, DateTimeImmutable $at): void`
- Produces: `final class ReplyRecorder`：`__construct(Database $db, ReplyMatcher $matcher, ReplyRepository $replies, NotificationRepository $notifications, MessageRepository $messages, ClockInterface $clock)`、`record(IncomingMail $mail): void`（1 つのトランザクション）
  - 宛先不明：`originalMessageId` の送ったメール、無ければ `recipients` のそれぞれへの最新の送ったメールを `markBounced()`。連絡の `deliveredAddresses()` が空になったら、その連絡を `failed` にする（返信があった連絡は変えない）。`replies` に `kind 'bounce'`（結び付いた連絡があればその id）
  - 自動応答：照合できれば `recordAutoReply()`。`replies` に `kind 'auto_reply'`
  - 返信：照合できれば、`ReplyText::extract()` が空なら `safe`、空でなければ `needs_check` で `recordAnswer(…, 'mail', null, null, $mail->date ?? 今)`。`replies` に `kind 'reply'` と抜き出した本文
  - 照合できなければ、`replies` に `notification_id` NULL で、本文は `text` の先頭 2,000 文字
- Produces: `final class ReplyJob implements CronJob`：`name()` は `'replies'`。`__construct(Mailbox $mailbox, ReplyRecorder $recorder, ReplyRepository $replies, Settings $settings, SystemState $state, ClockInterface $clock, LoggerInterface $logger)`
  - `Settings::imap()` が null なら何もしない（ホームが知らせる。Task 8）
  - `imap.since` が無ければ今を入れてから読む。`unseen(imap.since, 50)` の古い順に、`IncomingMail::parse()` → `message_id` が `ReplyRepository::knows()` なら記録せずに既読にする → `record()` → `markSeen()`。読めないメールは `kind 'unreadable'`（件名は `'（読めないメール）'`）で照合できなかったメールにして既読にする
  - 始めてから 60 秒を過ぎたら次のメールへ進まない
  - 成功したら状態 `imap.last_checked_at` を今、`imap.last_error` を `''`。`MailboxUnavailable` は `imap.last_error` に理由を書き、そのまま投げる（`CronRunner` が記録する）
- Produces: `MessageRepository::refusedAddresses()` に戻ってきたアドレスが入るので、再送は戻ってきたアドレスに送らない

- [ ] **Step 1: 失敗するテストを書く**

```php
// ReplyMatcherTest（QuakeWorld。山田 花子 に連絡を送ってある。Message-ID は RecordingMailSender の送ったもの）
#[Test] public function matchesTheThreadFirst(): void         // In-Reply-To がその Message-ID → by 'thread'（件名の番号が別の連絡でも、スレッドを優先）
#[Test] public function matchesTheNumberInTheSubject(): void   // In-Reply-To なし、件名 'Re: [安否確認 #{token}] …' → 'number'
#[Test] public function matchesTheSenderToTheirLatestUnansweredContact(): void
// 2 つの地震で連絡を送り、片方は返信済み → 番号も In-Reply-To も無い、山田 のアドレス（大文字混じり）からのメールは、未回答のほうに 'sender'
#[Test] public function leavesUnknownSendersUnmatched(): void   // 登録していないアドレス → null

// ReplyJobTest（QuakeWorld、FakeMailbox、FrozenClock。山田 花子 に送ってある）
#[Test] public function recordsAnEmptyReplyAsSafe(): void
// 空の本文の返信 → answer 'safe'、replied_at が入り、next_send_at NULL、replies に kind 'reply'。メールは既読
#[Test] public function recordsAWrittenReplyForChecking(): void
// 「足を怪我しました」+ 引用 → answer 'needs_check'、replies の body は '足を怪我しました'
#[Test] public function neverWeakensAnAnswer(): void
// needs_check の後に空の返信 → needs_check のまま。確認済みの後に本文の返信 → checked_at が NULL に戻る
#[Test] public function doesNotCountAnAutoReply(): void
// 自動応答 → answer NULL、replied_at NULL、auto_replied_at が入り、ResendJob の時刻が来れば再送される
#[Test] public function marksABouncedAddress(): void
// 2 件のアドレスのうち 1 件が戻る → その messages に bounced_at、連絡は sent のまま、再送はもう 1 件だけに。
// もう 1 件も戻る → 連絡は 'failed'、再送されない
#[Test] public function keepsUnmatchedMail(): void              // 登録していないアドレス → replies に notification_id NULL、ReplyRepository::countUnmatched() 1
#[Test] public function readsTheSameMailOnce(): void
// 同じ Message-ID のメールが 2 通（1 通目を記録した後に既読にできずに落ちた、の再現）→ 記録は 1 件、2 通目も既読にする
#[Test] public function readsOnlyMailSinceTheSettings(): void
// imap.since が無い → 今を入れ、FakeMailbox に渡した since が今。50 を渡している
#[Test] public function keepsUnreadableMail(): void              // 'not a mail' → kind 'unreadable' で照合できなかったメール、既読
#[Test] public function reportsAMailboxThatCannotBeRead(): void
// unavailable → MailboxUnavailable を投げ、imap.last_error に理由。次の回に読めたら ''
#[Test] public function stopsAfterTheTimeBudget(): void          // SteppingClock（30 秒ずつ）、5 通 → 60 秒を過ぎたら残りは未読のまま
#[Test] public function waitsForTheImapSettings(): void          // imap.host が空 → FakeMailbox に何も聞かない
```

- [ ] **Step 2: 失敗を確かめる**

Run: `vendor/bin/phpunit tests/Replies`
Expected: FAIL

- [ ] **Step 3: 実装する**

- [ ] **Step 4: 通ることを確かめる**

Run: `vendor/bin/phpunit`、`vendor/bin/psalm --no-progress`、`vendor/bin/pint --test`
Expected: OK、No errors found、passed

- [ ] **Step 5: コミット**

```bash
git add -A
git commit -m "feat: match replies to contacts and record who is safe, who needs checking and which addresses bounced"
```

---

### Task 6：責任者の画面

設計書 5.6。見た目は `docs/superpowers/specs/mockups/2026-11-05-manager-dashboard.html` に沿う。内容と並び順が要件。

**Files:**
- Create: `src/Quakes/Board/QuakeBoard.php`、`src/Quakes/Board/BoardRow.php`、`src/Quakes/ContactsController.php`、`templates/earthquakes/show.php`
- Modify: `src/Quakes/EarthquakesController.php`（`show`）、`templates/earthquakes/index.php`（地震の名前から画面へのリンク）、`src/Http/AppFactory.php`
- Test: `tests/Quakes/QuakeBoardTest.php`、`tests/Quakes/QuakeBoardPageTest.php`

**Interfaces:**
- Produces: `final readonly class BoardRow`：`contactId int`、`name string`、`department string`、`places string`（`'静岡県 掛川市 震度5弱'` を「、」でつなぐ。訓練は震度なし）、`reason string`、`repliedAt ?string`（`G:i`）、`bodies list<string>`、`proxy ?string`（`'安否 太郎 さんが代理で記録'`）、`foldedInto ?string`（含めた先の地震の名前）
- Produces: `final class QuakeBoard`：`__construct(NotificationRepository $notifications, EarthquakeRepository $earthquakes, ReplyRepository $replies, RateLimit $limit, ClockInterface $clock)`、`build(Earthquake $quake): array{noReply: list<BoardRow>, needsCheck: list<BoardRow>, checked: list<BoardRow>, safe: list<BoardRow>, total: int}`
  - `skipped` の連絡は数えない。`merged` の連絡は、含めた先の連絡の答えと送信の状態で数え、`foldedInto` を付ける
  - 答えが `needs_check` で `checked_at` が無ければ要確認、あれば確認済み、`safe` なら無事、無ければ返信なし
  - 返信なしの並び：届かなかった → 自動応答だけ → 送信済み → 送信待ち → 保留・取消、同じなら priority の大きい順
  - 返信なしの理由（時刻は `G:i`）：`failed` は `'届かなかった'`、`auto_replied_at` は `'不在の自動応答だけ'`、`sent` は `'{最初} に送信'`＋再送していれば `'、{最後} に再送'`＋次があれば `'（次の再送 {次}）'`、`pending` で `not_before` が先なら `'送信待ち（{not_before} に送る予定）'`、来ていて `RateLimit::nextSlotAt()` があれば `'送信待ち（上限のため {時刻} 予定）'`、無ければ `'送信待ち（次の cron で送ります）'`、`held` は `'保留（遅れて届いた地震）'`、`cancelled` は送っていなければ `'取消のため送っていません'`、送っていれば `'{最初} に送信（取消済み）'`
- Produces: `GET /earthquakes/{id}`（無ければ 404）。上から順に：
  1. `'{n月j日 G:i} ごろ　{震源}（M{規模}）{最大震度}'`（震源や規模が無ければ省く。訓練は `'{n月j日 G:i}　訓練'`）、`'{判定に使った電文}（{G:i} 発表）をもとに判定　｜　対象：{しきい値の震度}以上の市町村に登録した人'`、`'通常（訓練ではない）'` か `'訓練'`。取り消された地震は `'この地震は気象庁が取り消しました。'`、遅れて届いた地震は計画 3 の「送る」「送らない」
  2. 人数：`'対象 {n} 人'`、`'返信なし {n} 人'`、`'要確認（本文あり） {n} 人'`、`'無事（空の返信） {n} 人'`（確認済みがいれば `'確認済み {n} 人'`）
  3. 返信なし：名前、所属、市町村と震度、理由。20 人を超える分は `<details><summary>ほか {n} 人を表示</summary>`
  4. 要確認：名前、所属、`'{G:i} 返信'`、本文（古い順に「」で囲む）、「確認済みにする」のボタン。代理なら `proxy`
  5. 確認済み（いれば、`<details>`）
  6. 無事：`'無事（{n} 人）'` と `<details><summary>一覧を開く</summary>`
  7. 各行に「代理で記録」のリンク（Task 7）
- Produces: `POST /contacts/{id:[0-9]+}/check`（`ContactsController::check`）：`markChecked(id, ログインしている管理者の名前, 今)` → 302 で地震の画面。答えが `needs_check` でなければ 404

- [ ] **Step 1: 失敗するテストを書く**

```php
// QuakeBoardTest（QuakeWorld、FrozenClock '2009-08-10T20:12:00Z'。連絡はリポジトリで作り、状態は直接入れる）
#[Test] public function sortsPeopleByTheirAnswer(): void
// safe 2・needs_check 1・needs_check で確認済み 1・返信なし 3・skipped 1 → noReply 3、needsCheck 1、checked 1、safe 2、total 7
#[Test] public function explainsWhyThereIsNoReply(): void
// failed → '届かなかった'。auto_replied_at → '不在の自動応答だけ'。20:12 に送り 21:12 に再送、次は 22:12 → '5:12 に送信、6:12 に再送（次の再送 7:12）'。
// not_before 20:20 → '送信待ち（5:20 に送る予定）'。上限を使い切った pending → '送信待ち（上限のため … 予定）'。held → '保留（遅れて届いた地震）'
#[Test] public function listsTheProblemsFirst(): void          // 返信なしの並びが 届かなかった → 自動応答 → 送信済み → 送信待ち
#[Test] public function showsFoldedPeopleWithTheContactTheyWereFoldedInto(): void
// B の merged の連絡は、A の連絡が safe なら B の画面でも無事、foldedInto は A の名前
#[Test] public function showsTheRepliesOfPeopleToCheck(): void // replies の本文が古い順に bodies、repliedAt '5:28'

// QuakeBoardPageTest（TestApp::installed、ログイン、QuakeRecords）
#[Test] public function showsTheQuakeInTheOrderOfTheDesign(): void
// 見出し（'ごろ'、'最大震度6弱'）→ 人数 → '返信なし' → '要確認' → '無事' の順に本文に現れる。'通常（訓練ではない）'
#[Test] public function marksAReplyChecked(): void              // POST /contacts/{id}/check → 302、checked_at と checked_by '安否 太郎'
#[Test] public function refusesToCheckWhatNeedsNoChecking(): void  // 返信なしの連絡に check → 404
#[Test] public function saysAQuakeWasCancelled(): void          // 'この地震は気象庁が取り消しました。'
#[Test] public function linksTheListToEachQuake(): void         // /earthquakes に '/earthquakes/{id}' へのリンク
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
git commit -m "feat: show each quake's contacts by answer, with why someone has not replied"
```

---

### Task 7：代理回答と、照合できなかったメール

設計書 5.4 と、5.2 の ④。

**Files:**
- Create: `src/Replies/UnmatchedController.php`、`templates/contacts/proxy.php`、`templates/mail/unmatched.php`
- Modify: `src/Quakes/ContactsController.php`（`proxyForm`、`proxy`）、`templates/earthquakes/show.php`、`src/HomeController.php`、`templates/home.php`、`templates/nav.php`、`src/Http/AppFactory.php`
- Test: `tests/Quakes/ProxyAnswerTest.php`、`tests/Replies/UnmatchedPageTest.php`

**Interfaces:**
- Produces: `GET /contacts/{id}/proxy`：名前、地震、今の状態と、`answer`（`safe`「無事」／`needs_check`「要確認」）とメモ（2,000 文字まで）のフォーム。`POST /contacts/{id}/proxy`：`recordAnswer(id, answer, 'proxy', 管理者の名前, メモ, 今)` → 302 で地震の画面、フラッシュ `'代理の記録を残しました。'`。答えを選んでいなければ 200 で `'無事か要確認かを選んでください。'`、メモが長すぎれば `'メモは 2000 文字までです。'`。`merged` の連絡は、含めた先の連絡に記録する
- 代理の記録は、`needs_check` ならメモを本文の代わりに出す（`proxy_note`）
- Produces: `GET /mail/unmatched`：受け取った時刻（`n月j日 G:i`）、送り主、件名、種類（`reply` は「返信」、`auto_reply` は「自動応答」、`bounce` は「宛先不明」、`unreadable` は「読めないメール」）、本文の先頭 200 文字、「消す」のボタン（`POST /mail/unmatched/{id}/delete`、`data-confirm`）。上に `'登録していないアドレスからのメールや、番号の無いメールです。本人からの安否の知らせなら、地震の画面の「代理で記録」で記録してください。'`。無ければ `'照合できなかったメールはありません。'`
- Produces: ホームの警告に `'照合できなかったメールが {n} 通あります。'`（`/mail/unmatched` へのリンク）。ナビに「照合できなかったメール」

- [ ] **Step 1: 失敗するテストを書く**

```php
// ProxyAnswerTest（TestApp::installed、ログイン、QuakeRecords）
#[Test] public function recordsAPersonAsSafeOnTheirBehalf(): void
// POST /contacts/{id}/proxy（answer 'safe'）→ 302 /earthquakes/{quake}、answer 'safe'、answer_source 'proxy'、answered_by '安否 太郎'、replied_at が入る
#[Test] public function keepsTheNoteOfAPersonToCheck(): void   // answer 'needs_check'、note '電話で確認。足を骨折' → 地震の画面に '電話で確認。足を骨折' と '安否 太郎 さんが代理で記録'
#[Test] public function recordsOnTheContactAFoldedPersonWasFoldedInto(): void
#[Test] public function refusesAProxyAnswerWithoutAChoice(): void  // answer なし → 200 で '無事か要確認かを選んでください。'

// UnmatchedPageTest
#[Test] public function listsUnmatchedMailNewestFirst(): void   // 2 通 → 新しい順、種類のラベル、本文の先頭
#[Test] public function deletesUnmatchedMail(): void            // POST delete → 302、一覧から消える。結び付いた返信の id には 404
#[Test] public function warnsAtHomeAboutUnmatchedMail(): void   // ホームに '照合できなかったメールが 1 通あります。'
```

- [ ] **Step 2: 失敗を確かめる**

Run: `vendor/bin/phpunit tests/Quakes/ProxyAnswerTest.php tests/Replies/UnmatchedPageTest.php`
Expected: FAIL

- [ ] **Step 3: 実装する**

- [ ] **Step 4: 通ることを確かめる**

Run: `vendor/bin/phpunit`、`vendor/bin/psalm --no-progress`、`vendor/bin/pint --test`
Expected: OK、No errors found、passed

- [ ] **Step 5: コミット**

```bash
git add -A
git commit -m "feat: record answers on someone's behalf and list mail that matched nobody"
```

---

### Task 8：受信の設定と、ホームの警告

**Files:**
- Create: `src/Settings/SettingsForm.php`
- Modify: `src/Settings/SettingsController.php`（確かめを `SettingsForm` に移し、IMAP の欄と `testImap` を足す）、`templates/settings/index.php`、`src/HomeController.php`、`templates/home.php`、`src/Http/AppFactory.php`
- Test: `tests/Settings/SettingsPagesTest.php`、`tests/Monitor/HomeTest.php`

**Interfaces:**
- Produces: `final class ReBuji\Settings\SettingsForm`：`public const array FIELDS`（`SettingsController::FIELDS` を移し、`imap_host`・`imap_port`・`imap_encryption`・`imap_username`・`imap_password` を足す）、`static read(array $body): array<string, string>`、`static validate(array<string, string> $values, bool $accountsRequired): list<string>`（`$accountsRequired` は初期設定で true：SMTP と IMAP のサーバー名が要る）。IMAP の文言：`'IMAP のポート番号は 1〜65535 にしてください。'`、`'受信の暗号化の方式を選んでください。'`、初期設定で空なら `'返信を受ける IMAP のサーバー名を入れてください。'`
- Produces: 設定の画面に「返信を受けるアカウント（IMAP）」の欄（ユーザー名とパスワードは空なら送信と同じ）と、「受信を確かめる」（`POST /settings/test-imap`：`Mailbox::unseen(今, 1)` が通ればフラッシュ `'受信できました。'`、`MailboxUnavailable` なら 200 で `'メールを受信できませんでした：{理由}'`）。IMAP のサーバー名かユーザー名が変わったら、状態 `imap.since` を今にする
- Produces: ホームの警告に、`Settings::imap() === null` なら `'返信を読む設定（IMAP）がありません。「設定」で登録してください。'`、状態 `imap.last_error` があれば `'返信を読めていません：{理由}'`

- [ ] **Step 1: 失敗するテストを書く**

```php
// SettingsPagesTest に足す
#[Test] public function savesTheImapAccount(): void
// IMAP の欄を入れて保存 → Settings::imap() が ImapSettings（ユーザー名を空にすると SMTP のもの）、imap.since が今
#[Test] public function refusesAnImapPortOutOfRange(): void     // imap_port '0' → 'IMAP のポート番号は 1〜65535 にしてください。'
#[Test] public function checksTheMailbox(): void                // FakeMailbox → フラッシュ '受信できました。'。unavailable → 'メールを受信できませんでした：'

// HomeTest に足す
#[Test] public function warnsWhenRepliesCannotBeRead(): void
// IMAP 未設定の文言、設定した後は imap.last_error の文言
```

- [ ] **Step 2: 失敗を確かめる**

Run: `vendor/bin/phpunit tests/Settings tests/Monitor`
Expected: FAIL

- [ ] **Step 3: 実装する**

- [ ] **Step 4: 通ることを確かめる**

Run: `vendor/bin/phpunit`、`vendor/bin/psalm --no-progress`、`vendor/bin/pint --test`
Expected: OK、No errors found、passed

- [ ] **Step 5: コミット**

```bash
git add -A
git commit -m "feat: set up and check the reply mailbox, and warn at home when replies cannot be read"
```

---

### Task 9：見張りのメール

設計書 6.2：フィードの抜け、送信の失敗、受信の失敗、上限による送信待ちを、画面に加えて管理者へのメールで知らせる。

**Files:**
- Create: `src/Monitor/WatchJob.php`
- Modify: `src/Cron/CronJobs.php`（最後に `WatchJob`）
- Test: `tests/Monitor/WatchJobTest.php`

**Interfaces:**
- Produces: `final class WatchJob implements CronJob`：`name()` は `'watch'`。`__construct(SystemState $state, NotificationRepository $notifications, RateLimit $limit, AdminRepository $admins, NotificationMail $mails, MailSender $sender, MessageRepository $messages, ClockInterface $clock, LoggerInterface $logger)`
  - 見張ること（キーと文言。文言はホームと同じ）：`feed_gap`（`feed.gap_since`）、`feed_failed`（`feed.failed_telegrams`）、`mail`（`mail.last_error`）、`imap`（`imap.last_error`）、`waiting`（`countWaiting() > 0` で `nextSlotAt()` があるとき）
  - 今ある問題のうち、状態 `watch.sent.{キー}` に入れた文言と違うものだけを 1 通にまとめ、管理者全員に 1 人 1 通送る。件名 `'[Re:無事] 確かめてほしいことがあります'`、本文 `"Re:無事 の管理者の皆さん\n\n次のことが起きています。管理画面のホームで確かめてください。\n\n・{文言}\n…"`。`messages` に kind `'watch'`・`notification_id` NULL で記録する（上限に数える）
  - 送れたら `watch.sent.{キー}` に文言を入れる。消えた問題は `watch.sent.{キー}` を `''` に戻す（また起きたら知らせる）
  - 上限の残りが管理者の数より少ないときと、`MailUnavailable` のときは送らずに次の回へ。試すのは 60 分に 1 回まで（状態 `watch.last_attempt_at`）**[判断]**：送信が止まっているときに、毎回同じタイムアウトを待たないため
- Produces: `NotificationMail::watchMail(string $to, list<string> $problems): OutgoingMail`

- [ ] **Step 1: 失敗するテストを書く**

```php
// WatchJobTest（QuakeWorld、RecordingMailSender、管理者 2 人）
#[Test] public function tellsTheAdminsAboutANewProblem(): void
// feed.gap_since を入れて run → 2 人に 1 通ずつ、本文に '気象庁の情報に抜けがあります'、messages に kind 'watch' 2 行
#[Test] public function tellsEachProblemOnce(): void            // もう一度 run → 送らない。文言が変われば送る
#[Test] public function tellsAgainWhenAProblemComesBack(): void // 消えた後（''）に、また起きたら送る
#[Test] public function groupsProblemsInOneMail(): void         // mail.last_error と imap.last_error → 1 人 1 通に 2 つ
#[Test] public function waitsWhenMailCannotGoOut(): void
// unavailable → watch.sent は空のまま。59 分後は試さない、60 分後に試す
#[Test] public function leavesTheLimitToContacts(): void        // 上限の残り 1、管理者 2 人 → 送らない
```

- [ ] **Step 2: 失敗を確かめる**

Run: `vendor/bin/phpunit tests/Monitor/WatchJobTest.php`
Expected: FAIL

- [ ] **Step 3: 実装する**

- [ ] **Step 4: 通ることを確かめる**

Run: `vendor/bin/phpunit`、`vendor/bin/psalm --no-progress`、`vendor/bin/pint --test`
Expected: OK、No errors found、passed

- [ ] **Step 5: コミット**

```bash
git add -A
git commit -m "feat: mail the admins once about each new problem the home page shows"
```

---

### Task 10：初期設定のメールの段階

設計書 6.3 の 2〜3。段階の順は `environment`、`key`、`database`、`admin`、`mail`、`reply`、`exposure`、`finish` **[判断]**：テストメールは最初の管理者に送るので `admin` の後。公開の確かめは最後のままにする。

**Files:**
- Create: `src/Install/Steps/MailStep.php`、`src/Install/Steps/ReplyStep.php`、`templates/install/mail.php`、`templates/install/reply.php`
- Modify: `src/Install/InstallSteps.php`、`tests/Install/InstallFlowTest.php`（`reachExposure()` などの補助に、メールの 2 段階を足す）
- Test: `tests/Install/MailStepsTest.php`

**Interfaces:**
- Produces: `MailStep`（key `'mail'`、title `'メールの設定'`）：SMTP と IMAP の欄、1 時間の上限（計画 3 の目安の表）、しきい値。送信すると `SettingsForm::validate(…, true)` → `Settings::save()`（`imap.since` を今）→ `Mailbox::unseen(今, 1)` で受信を確かめる → 最初の管理者にテストメールを送る（件名 `'[Re:無事 の確認 #{8 桁の 16 進}] このメールに返信してください'`、本文 `"{名前} さん\n\nRe:無事 の設定の確認です。このメールに、そのまま返信してください（本文は空で構いません）。\n返信したら、初期設定の画面で「返信を確かめる」を押してください。\n"`。`messages` に kind `'test'`）。下書きに Message-ID と番号を入れる。受信と送信のどちらかが失敗すれば、理由を出して進まない
- Produces: `ReplyStep`（key `'reply'`、title `'返信の確認'`）：テストメールを送った先と、「返信を確かめる」のボタン、「メールの設定に戻る」のリンク。送信すると `Mailbox::unseen(imap.since, 50)` を読み、`IncomingMail` の `references` に下書きの Message-ID があるか、件名に `#番号` があるメールを探して既読にする。あれば次へ。無ければ `'まだ返信が見つかりません。返信してから少し待って、もう一度押してください。'`
- 初期設定では、この 2 つを通らないと終えられない（設計書 6.3 の 3）

- [ ] **Step 1: 失敗するテストを書く**

```php
// MailStepsTest（TestApp、MailSender は RecordingMailSender、Mailbox は FakeMailbox。管理者まで済ませた状態から）
#[Test] public function sendsATestMailToTheFirstAdmin(): void
// POST /install/mail → 302 /install/reply。宛先 'admin@example.com'、件名 '[Re:無事 の確認 #'、Settings::smtp() と imap() が入っている
#[Test] public function stopsWhenTheMailboxCannotBeRead(): void  // FakeMailbox unavailable → 200 で 'メールを受信できませんでした：'、送らない
#[Test] public function needsTheReplyToGoOn(): void
// 返信が無い → POST /install/reply は 200 で 'まだ返信が見つかりません'。In-Reply-To にテストメールの Message-ID の返信を足す → 302 /install/exposure、そのメールは既読
#[Test] public function acceptsAReplyCarryingTheNumberOnly(): void  // In-Reply-To なし、件名に '#番号' → 進める
// InstallFlowTest の completesTheWholeFlowWithSqlite などは、mail と reply を通ってから exposure・finish へ
```

- [ ] **Step 2: 失敗を確かめる**

Run: `vendor/bin/phpunit tests/Install`
Expected: FAIL

- [ ] **Step 3: 実装する**

段階の中では、`$context->container` から `Settings`・`MailSender`・`Mailbox`・`AdminRepository` を受け取る（DB は `database` の段階で使えるようになっている）。

- [ ] **Step 4: 通ることを確かめる**

Run: `vendor/bin/phpunit`、`vendor/bin/psalm --no-progress`、`vendor/bin/pint --test`
Expected: OK、No errors found、passed

- [ ] **Step 5: コミット**

```bash
git add -A
git commit -m "feat: set up mail during installation and finish only after a test reply is read"
```

---

### Task 11：古い記録の削除と README

設計書 6.5。

**Files:**
- Modify: `src/Quakes/EarthquakeRepository.php`（`delete`）、`src/Feed/TelegramRepository.php`（`deleteEvent`）、`src/Quakes/EarthquakesController.php`（`delete`）、`templates/earthquakes/show.php`、`src/Http/AppFactory.php`、`README.md`
- Test: `tests/Quakes/QuakeDeletionTest.php`

**Interfaces:**
- Produces: `EarthquakeRepository::delete(int $id): void`、`TelegramRepository::deleteEvent(string $eventId): void`
- Produces: `POST /earthquakes/{id}/delete`：1 つのトランザクションで、地震（連絡、送ったメール、返信は `ON DELETE CASCADE`）と、その EventID の電文を消す → 302 `/earthquakes`、フラッシュ `'地震の記録を消しました。'`。地震の画面の下に「この地震の記録を消す」（`data-confirm` は `'この地震の連絡と返信の記録をすべて消します。元に戻せません。消しますか？'`）。無い id は 404
- README に足すこと：返信の数え方（空なら無事、本文があれば要確認、自動応答と宛先不明は数えない）、責任者の画面、代理で記録、照合できなかったメール、見張りのメール、IMAP の設定、記録の削除。冒頭の注意を `'v0.1 の準備中です。設計書の機能はそろいましたが、まだリリースしていません。'` にする

- [ ] **Step 1: 失敗するテストを書く**

```php
// QuakeDeletionTest（TestApp::installed、ログイン）
#[Test] public function deletesAQuakeWithItsRecords(): void
// 地震、連絡、送ったメール、返信、電文 → POST delete → どれも 0 行。照合できなかったメールとほかの地震は残る
#[Test] public function refusesAnUnknownQuake(): void          // 無い id → 404
```

- [ ] **Step 2: 失敗を確かめる**

Run: `vendor/bin/phpunit tests/Quakes/QuakeDeletionTest.php`
Expected: FAIL

- [ ] **Step 3: 実装する**

- [ ] **Step 4: 通ることを確かめる**

Run: `vendor/bin/phpunit`、`vendor/bin/psalm --no-progress`、`vendor/bin/pint --test`、`composer normalize --dry-run`
Expected: OK、No errors found、passed、already normalized

- [ ] **Step 5: 実機に近い確認**

計画 3 と同じく、作業ツリーの写しを `php -S` で動かす。偽の SMTP サーバーと偽の IMAP サーバー（`fake-imap-server.php` を手で動かす）を使う。どちらも TLS を持たないので、画面で保存した後に、`Settings::save()` で `smtp.encryption` と `imap.encryption` を `'none'` にする。
- 初期設定のメールの段階：テストメールが偽の SMTP に届く。その Message-ID を `In-Reply-To` にした返信を、偽の IMAP の受信箱に置く → 「返信を確かめる」で次へ進む
- 地震の連絡を送った後に、空の返信・本文の返信・自動応答・宛先不明を受信箱に置いて `php bin/cron.php` → 地震の画面で、無事・要確認・返信なし（不在の自動応答だけ／届かなかった）に分かれる。「確認済みにする」と「代理で記録」も動く

- [ ] **Step 6: コミット**

```bash
git add -A
git commit -m "feat: delete old quake records and document replies, the manager screen and mail settings"
```

---

## 計画 4 の後に残ること

| 内容 | 扱い |
|---|---|
| 計画 3 の minor のうち 11 件（ホームの赤い警告が消せない、フィードのトランザクションと SQLite のロック、SMTP のタイムアウト、メールの記録の時刻、送信待ちの文言、テストメールでエラーが消えない、保留の 2 つの地震、一覧の merged の数、読めない電文、MySQL のテストの範囲、市町村の置き換え） | v0.1 のリリースの前に、作者と選ぶ |
| リリース（GitHub のリリースに zip を置く。設計書 8 章） | 計画 4 のマージの後、作者が決める |
| LINE などの経路（設計書 5.7） | v0.1 の範囲外 |

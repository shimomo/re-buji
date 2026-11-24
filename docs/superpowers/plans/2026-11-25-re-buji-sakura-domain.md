# Re:無事 計画 33：さくらの独自ドメインに SSH で入れる（v0.4.1） 実装計画

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** さくらで 0.4.0 を流して見つかった、公開する URL の問いと返信の待ちの問題を直し、手順書をさくらの独自ドメインの手順にして、v0.4.1 を出せる状態にする。

**Architecture:**
- DB の形は変えない
- 返信の待ち：`Mailbox` に、既読かどうかにかかわらずテストメールへの返信を探す `answersTo()` を足す。IMAP では、検索の前に NOOP を送り、受信サーバーの `SEARCH` で絞る。`SetupReplyFinder` はこれを使う（`bin/console install` と `bin/console check --wait-reply` の両方に効く）
- 公開する URL：`ConsoleExposure::run()` を、URL が届くかの `reach()` と、残りの確かめの `finish()` に分ける。`reach()` は確かめの道が残る転送を 1 回だけたどる。`InstallCommand` は URL を聞いた直後に `reach()` を呼び、手で入れた URL が届かなければ聞き直す
- 新しいクラス：`ReachedUrl`（届いた URL と、ウェブ側の様子）

**Tech Stack:** 計画 32 と同じ。依存は増やさない。

**Spec:** `docs/superpowers/specs/2026-11-25-re-buji-sakura-domain-design.md`（以下「設計」）。元にした設計は `docs/superpowers/specs/2026-11-24-re-buji-ssh-install-design.md`

**前の計画：** `docs/superpowers/plans/2026-11-24-re-buji-ssh-install.md`（計画 32、PR #37 でマージ済み、v0.4.0 はリリース済み）

## Global Constraints

- 計画 1〜32 の Global Constraints をすべて引き継ぐ。主なもの：
  - PHP `^8.3`、`setUp` / `tearDown` を使わない、コミットメッセージの末尾に `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`、Psalm errorLevel 1、Pint
  - `src/php-version.php`、`public/index.php`、`bin/cron.php` は触らない
  - `bin/install.sh` と `bin/upgrade.sh` は、この計画では触らない
- DB の形は変えない（マイグレーションを足さない）
- 画面とコンソールに出す文は、設計 3 章の例と同じ意味にする。この計画に「」で書いた文は、そのとおりにする
- cron の返信の取り込み（`Mailbox::unseen()` を使う側）は変えない
- ブラウザの初期設定の公開の確かめ（`ExposureStep`）は変えない
- 版は `0.4.1`（設計 7 章）

## Review Focus

- `Location` が相対（`/install/probe/<nonce>?from=console`）の転送 → 求めた URL のスキームとホストに足して、確かめの道が残る転送として扱う（Task 3 `followsARelativeRedirectThatKeepsTheProbe`）
- テストメールの Message-ID か番号が空 → 受信サーバーへの検索に空の文字列を入れない（空の `HEADER` は、その見出しを持つメール全部に当たる）（Task 1 `searchesOnlyWhatItIsGiven`）
- 手で入れた `http://` の URL が `https://` に転送されて届いた → 「注意：http の URL です」は出さない（使う URL は https）（Task 3 `warnsAboutHttpOnlyForTheUrlInUse`）
- 確かめの道が残る転送のあと、転送先にサーバーから届かない → ブラウザに開いてもらう URL は転送先のもの（Task 3 `asksTheBrowserToOpenTheUrlItWasLedTo`）
- 返信を探す検索に、送ったテストメール自身が当たる（管理者のアドレスと受信箱が同じとき）→ 返信とは数えない。送るメールには `Auto-Submitted: auto-generated` が付き、`IncomingMail::kind()` が自動応答とするため。これをテストで縛る（Task 2 `doesNotTakeTheTestMailItselfForTheReply`）

---

### Task 1：既読も探す、返信の検索と NOOP（IMAP の層）

**Files:**
- Modify: `src/Mail/Imap/Mailbox.php`（`answersTo()` を足す）
- Modify: `src/Mail/Imap/ImapMailbox.php`、`src/Mail/Imap/ImapClient.php`
- Modify: `tests/Support/fake-imap-server.php`（NOOP と、見出しでの検索）
- Modify: `tests/Support/FakeMailbox.php`、`tests/Support/AnsweringMailbox.php`、`tests/Console/CheckCommandTest.php:254`、`tests/Console/InstallCommandTest.php:279`（`Mailbox` を実装する 4 つ。インターフェースに合わせるだけ）
- Test: `tests/Mail/Imap/ImapClientTest.php`

**Interfaces:**
- Produces:
  - `Mailbox::answersTo(DateTimeImmutable $since, string $messageId, string $token, int $limit): iterable<RawMail>`：その日から受け取ったメールのうち、`In-Reply-To` か `References` に `$messageId` を含むもの、または件名に `$token` を含むものを、既読かどうかにかかわらず、古い順に `$limit` 通まで。空の `$messageId`、`$token` は条件にしない。両方とも空なら何も返さない。`@throws MailboxUnavailable`
  - `ImapClient::answersSince(DateTimeImmutable $since, string $messageId, string $token): list<int>`：`NOOP` を送ってから、`UID SEARCH SINCE <j-M-Y> <条件>` を送り、UID を古い順に返す
  - `FakeMailbox::answersTo()`：`$messageId` か `$token` を本文のどこかに含むメール（既読も）を返し、`$asked` に `['since' => …, 'limit' => …]` を記録する。空の値は条件にしない（`str_contains()` は空の文字列で常に真になるため）

- [ ] **Step 1: 失敗するテストを書く**（`tests/Mail/Imap/ImapClientTest.php`）

```php
#[Test]
public function findsAnswersReadOrNotAfterAskingForNewMail(): void
{
    // 1：返信（既読）、2：関係のないメール、3：件名の番号だけで返信と分かるメール
    // mailbox(3) の 1.eml を "In-Reply-To: <t1@example.jp>" 付き、3.eml を "Subject: Re: [Re:無事 の確認 #ab12cd34]" に書き換え、1.seen を作る
    $client->open();

    $this->assertSame([1, 3], $client->answersSince(new DateTimeImmutable(self::SINCE), 't1@example.jp', '#ab12cd34'));
    $transcript = $server->transcript();
    $this->assertStringContainsString('UID SEARCH SINCE 8-Nov-2026 OR HEADER IN-REPLY-TO "t1@example.jp" OR HEADER REFERENCES "t1@example.jp" HEADER SUBJECT "#ab12cd34"', $transcript);
    $this->assertLessThan(strpos($transcript, 'UID SEARCH SINCE'), strpos($transcript, ' NOOP'));
}

#[Test]
public function searchesOnlyWhatItIsGiven(): void
{
    $this->assertSame([3], $client->answersSince(new DateTimeImmutable(self::SINCE), '', '#ab12cd34'));
    $this->assertStringContainsString('UID SEARCH SINCE 8-Nov-2026 HEADER SUBJECT "#ab12cd34"', $server->transcript());
    $this->assertSame([], $client->answersSince(new DateTimeImmutable(self::SINCE), '', ''));
    // 両方とも空なら、受信サーバーに何も聞かない
    $this->assertSame(1, substr_count($server->transcript(), 'UID SEARCH'));
}
```

- [ ] **Step 2: 失敗を確かめる**

Run: `vendor/bin/phpunit --filter 'findsAnswersReadOrNotAfterAskingForNewMail|searchesOnlyWhatItIsGiven' tests/Mail/Imap/ImapClientTest.php`
Expected: FAIL（`answersSince()` が無い）

- [ ] **Step 3: 偽のサーバーを足す**（`tests/Support/fake-imap-server.php`）

- `NOOP` に `<tag> OK NOOP completed` と答える
- `UID SEARCH` で、残りに `UNSEEN` があれば今のとおり。無ければ、`HEADER <名前> "<文字列>"` の組をすべて取り出し、どれかの見出し（名前は大文字小文字を区別しない）がその文字列を含むメールの UID を、既読かどうかにかかわらず返す。`SINCE` は見ない（ファイルに日付が無いため。コメントに書く）

- [ ] **Step 4: `ImapClient::answersSince()` と `ImapMailbox::answersTo()`、`Mailbox::answersTo()` を実装する**

- 条件は、空でないものだけで作る。`$messageId` があれば `HEADER IN-REPLY-TO` と `HEADER REFERENCES`、`$token` があれば `HEADER SUBJECT`。2 つ以上なら IMAP の `OR`（2 つずつの前置き）でつなぐ。文字列は今の `quote()` で囲む
- 条件が 1 つも無ければ、何も送らずに `[]` を返す
- `ImapMailbox::answersTo()` は `unseen()` と同じく、`$limit` で切り、1 通ずつ取り出す（`fetching()` を使う）
- `Mailbox` の docblock に、`unseen()` との違い（既読も返す。検索の前に新しいメールを確かめる）を書く
- テストの 4 つの `Mailbox` に `answersTo()` を足す。`AnsweringMailbox` は、`unseen()` と同じく送ったメールへの返信を足してから、中の `FakeMailbox::answersTo()` を返す。`CheckCommandTest` と `InstallCommandTest` の無名クラスも、それぞれの `unseen()` と同じ振る舞いにする

- [ ] **Step 5: 通ることを確かめる**

Run: `composer test && composer analyse && composer lint`
Expected: すべて通る

- [ ] **Step 6: コミット**

```bash
git add src/Mail/Imap tests/Support tests/Mail/Imap/ImapClientTest.php tests/Console/CheckCommandTest.php tests/Console/InstallCommandTest.php
git commit -m "feat: let the mailbox find answers to a mail whether they are read or not, asking for new mail first"
```

---

### Task 2：返信の待ちを直す（既読も探す、回ごとの表示、テストメールの本文）

**Files:**
- Modify: `src/Install/SetupReplyFinder.php`
- Modify: `src/Console/InstallCommand.php`（`waitForReply()`）
- Modify: `src/Mail/NotificationMail.php:97`（テストメールの本文）
- Test: `tests/Install/SetupReplyFinderTest.php`、`tests/Console/InstallCommandTest.php`、`tests/Install/MailStepsTest.php`（本文を見ていれば）

**Interfaces:**
- Consumes: Task 1 の `Mailbox::answersTo()`
- Produces: `SetupReplyFinder::find(DateTimeImmutable $since, string $messageId, string $number): bool`（形は変えない。中で `answersTo($since, $messageId, $number === '' ? '' : '#' . $number, 50)` を使う）

- [ ] **Step 1: 失敗するテストを書く**

`tests/Install/SetupReplyFinderTest.php`：

```php
#[Test]
public function findsAReplyThatWasAlreadyRead(): void
{
    $mailbox = new FakeMailbox();
    $mailbox->seen[] = $mailbox->add(MailFixtures::reply('admin@example.jp', '', 'abc@example.jp'));

    $this->assertTrue((new SetupReplyFinder($mailbox))->find((new FrozenClock())->now(), 'abc@example.jp', '1234abcd'));
}

#[Test]
public function doesNotTakeTheTestMailItselfForTheReply(): void
{
    // 管理者のアドレスと受信箱が同じとき、送ったテストメールそのものが受信箱に届く
    $mailbox = new FakeMailbox();
    $mailbox->add("From: anpi@example.jp\r\nTo: anpi@example.jp\r\nSubject: [Re:無事 の確認 #1234abcd] このメールに返信してください\r\nMessage-ID: <abc@example.jp>\r\nAuto-Submitted: auto-generated\r\n\r\n管理 さん\r\n");

    $this->assertFalse((new SetupReplyFinder($mailbox))->find((new FrozenClock())->now(), 'abc@example.jp', '1234abcd'));
}
```

`tests/Console/InstallCommandTest.php` の `waitsAnotherFiveMinutesWhenAsked` に足す：

```php
$this->assertSame(2, substr_count($io->output(), 'テストメールに返信してください。返信を待っています（5 分まで）…'));
```

テストメールの本文（`MailStepsTest` か、`NotificationMail` のテストがあればそこ）：

```php
$this->assertStringContainsString('画面で初期設定をしているときは、返信したあとで「返信を確かめる」を押してください。', $mail->body);
$this->assertStringNotContainsString('返信したら、初期設定の画面で', $mail->body);
```

- [ ] **Step 2: 失敗を確かめる**

Run: `vendor/bin/phpunit --filter 'findsAReplyThatWasAlreadyRead|doesNotTakeTheTestMailItselfForTheReply|waitsAnotherFiveMinutesWhenAsked'`
Expected: `findsAReplyThatWasAlreadyRead` と `waitsAnotherFiveMinutesWhenAsked` が FAIL。`doesNotTakeTheTestMailItselfForTheReply` は今も通る（今の作りでも自動応答として飛ばすため。縛りのテスト）。本文のテストも FAIL

- [ ] **Step 3: 実装する**

- `SetupReplyFinder::find()`：`unseen()` を `answersTo()` に替える。取り出したあとの確かめ（読めないメールを飛ばす、`kind()` が返信でないものを飛ばす、参照か件名の番号で合うかを見る、合えば `markSeen()`）は今のまま残す。受信サーバーの絞り込みは目安で、合うかの決め手はこちらの確かめ。docblock の「unseen」を、既読も探すことに直す
- `InstallCommand::waitForReply()`：「テストメールに返信してください。返信を待っています（5 分まで）…」を、ループの外から、回ごと（`replyArrives()` を呼ぶ前）に移す
- テストメールの本文を、次のとおりにする（`{name}` は今の `$name`）

```
{name} さん

Re:無事 の設定の確認です。このメールに、そのまま返信してください（本文は空で構いません）。
返信が届けば、確認は終わりです。画面で初期設定をしているときは、返信したあとで「返信を確かめる」を押してください。
```

- [ ] **Step 4: 通ることを確かめる**

Run: `composer test && composer analyse && composer lint`
Expected: すべて通る

- [ ] **Step 5: コミット**

```bash
git add src/Install/SetupReplyFinder.php src/Console/InstallCommand.php src/Mail/NotificationMail.php tests
git commit -m "fix: find the reply to the setup mail even once it is read, and show the wait on every round"
```

---

### Task 3：公開する URL の確かめ（転送、聞き直し、ディレクトリを示す）

**Files:**
- Create: `src/Install/ReachedUrl.php`
- Modify: `src/Install/ConsoleExposure.php`（`run()` を `reach()` と `finish()` に分ける）
- Modify: `src/Console/InstallCommand.php`（`publicUrl()` を `reachedUrl()` に、`run()` の順番、`checkExposure()`）
- Test: `tests/Install/ConsoleExposureTest.php`、`tests/Console/InstallCommandTest.php`

**Interfaces:**
- Produces:
  - `final readonly class ReachedUrl`：`public string $url`（この Re:無事 に届いた URL。転送をたどったなら転送先、末尾の `/` なし）、`public WebSide $web`、`public bool $byBrowser`（サーバーから届かず、ブラウザで開いてもらったか）
  - `ConsoleExposure::reach(string $publicUrl, string $directory, ConsoleIo $io): ReachedUrl|false|null`：届けば `ReachedUrl`。URL が別のところへ行く（この Re:無事 でない答え、確かめの道が残らない転送）なら `false`（別の URL を聞けば通るかもしれない）。止まったら `null`。`false` と `null` のときは、理由を `$io->error()` で書いてある
  - `ConsoleExposure::finish(ReachedUrl $reached, string $driver, ConsoleIo $io): bool`：今の `run()` の、`webSideIsFit()` 以降（ウェブの PHP のドライバーと `storage/`、ファイルが読めないことの確かめ、ブラウザで確かめてもらう問い）
  - `InstallCommand::reachedUrl(?string $given, string $directory, ConsoleIo $io): ?ReachedUrl`（private）
- Consumes: `ExposureCheck::reach()`、`ProbeAnswer`、`ProbeOutcome`（変えない）

- [ ] **Step 1: 失敗するテストを書く**

`tests/Install/ConsoleExposureTest.php`（今の `run()` を使うテストは、`reach()` と `finish()` に書き換える。`stopsOnARedirectAndSaysWhichUrlToUse` は、次の 2 つに置き換える）。転送のテストは、求めた URL の nonce を読んで答える無名の `ClientInterface` を使う：`http://example.jp/anpi/install/probe/<nonce>?from=console` には 302（`Location` は `https://example.jp/anpi/install/probe/<nonce>?from=console`）、https の方には `WebSide` の JSON（`FakeHttpClient` と同じ答え方）で答える。

```php
#[Test]
public function followsARedirectThatKeepsTheProbe(): void
{
    $reached = $this->exposure($root, $client)->reach('http://example.jp/anpi', '/home/u/www/anpi', $io);

    $this->assertInstanceOf(ReachedUrl::class, $reached);
    $this->assertSame('https://example.jp/anpi', $reached->url);
    $this->assertStringContainsString('→ https://example.jp/anpi に転送されたので、こちらを使います', $io->output());
}

#[Test]
public function followsARelativeRedirectThatKeepsTheProbe(): void
{
    // Location: /anpi/install/probe/<nonce>?from=console を、http://example.jp に足す（スキームはそのまま）
    $this->assertSame('http://example.jp/anpi', $reached->url);
}

#[Test]
public function reportsARedirectElsewhereWithoutTheProbesQuery(): void
{
    // Location: https://shimomo.net/?from=console（パスが捨てられている）
    $this->assertFalse($this->exposure($root, $client)->reach('https://example.jp/re-buji-test', '/home/u/www/anpi', $io));
    $this->assertStringContainsString('https://example.jp/re-buji-test は https://shimomo.net/ に転送されました。/home/u/www/anpi に届いていません。', $io->errors());
    $this->assertStringNotContainsString('from=console', $io->errors() . $io->output());
    $this->assertStringNotContainsString('--url', $io->errors());
}

#[Test]
public function treatsASecondRedirectAsNotReached(): void
{
    // http → https（道が残る）→ https://example.jp/login（道が残らない）
    $this->assertFalse($reached);
    $this->assertStringContainsString('https://example.jp/anpi は https://example.jp/login に転送されました。', $io->errors());
}

#[Test]
public function namesTheDirectoryWhenAnotherAnswerComes(): void
{
    // 404（FakeHttpClient(404, [], false)）
    $this->assertFalse($reached);
    $this->assertStringContainsString('（状態 404）', $io->errors());
    $this->assertStringContainsString('その URL が /home/u/www/anpi を開くか、ウェブの PHP が 8.3 以上かを確かめてください。', $io->errors());
}

#[Test]
public function asksTheBrowserToOpenTheUrlItWasLedTo(): void
{
    // http → https に転送され、https には接続できない（FakeNetworkException）。ブラウザが開く（browserOpens()）
    $this->assertInstanceOf(ReachedUrl::class, $reached);
    $this->assertTrue($reached->byBrowser);
    $this->assertStringContainsString('  https://example.jp/anpi/install/probe/', $io->output());
}
```

`tests/Console/InstallCommandTest.php`（`--url` を付けない引数は `['--public=' . $dir . '/www', '--php=' . self::PHP]`）：

```php
#[Test]
public function asksForTheUrlNamingTheDirectory(): void
{
    $this->assertStringContainsString($dir . '/www を開く URL（例：https://anpi.example.jp）：', $io->output());
    // --public が無いとき（--root だけ）は、本体の場所を出す：別の install で $root . ' を開く URL'
}

#[Test]
public function asksAgainWhenATypedUrlDoesNotReachThisCopy(): void
{
    // https://example.jp/wrong には 404、https://example.jp/anpi の確かめの道には答える無名の ClientInterface
    // 入力："https://example.jp/wrong\nhttps://example.jp/anpi\n" . self::ANSWERS
    $this->assertSame(0, $code, $io->errors());
    $this->assertStringContainsString('サーバーから https://example.jp/wrong を開きましたが', $io->errors());
    $this->assertSame(2, substr_count($io->output(), 'を開く URL（例：https://anpi.example.jp）：'));
}

#[Test]
public function stopsAfterThreeTypedUrlsThatDoNotReachThisCopy(): void
{
    // FakeHttpClient(404, [], false)、入力は URL を 3 つ（どれも形は正しい）
    $this->assertSame(1, $code);
    $this->assertStringContainsString('3 回とも通らなかったので、止めます。同じコマンドで、もう一度流せます。', $io->errors());
    $this->assertStringNotContainsString('最初の管理者', $io->output());
}

#[Test]
public function warnsAboutHttpOnlyForTheUrlInUse(): void
{
    // 入力 "http://example.jp/anpi"。http には https への 302（道が残る）、https には確かめの道で答える
    $this->assertSame(0, $code, $io->errors());
    $this->assertStringNotContainsString('注意：http の URL です。', $io->output());
}
```

今のテストの直し：
- `stopsBeforeAnyQuestionWhenTheUrlDoesNotReachThisCopy`（`--url` で 404）：今のまま通ること（聞き直さない）。加えて、出力に「を開く URL」が無いことを確かめる
- `asksForTheUrlAndUsesItInOneForm`：3 つめの `HTTP://Example.JP:8080/anpi/` は 404 で、3 回で止まる。「注意：http の URL です。」の確かめは外す（`warnsAboutHttpOnlyForTheUrlInUse` と、http の URL が届くときに出す確かめに移す。http のまま届く URL で「注意」が出るテストを 1 つ足す）

- [ ] **Step 2: 失敗を確かめる**

Run: `vendor/bin/phpunit tests/Install/ConsoleExposureTest.php tests/Console/InstallCommandTest.php`
Expected: 新しいテストが FAIL（`ReachedUrl` と `reach()` が無い、質問の文が違う、聞き直さない）

- [ ] **Step 3: `ReachedUrl` と `ConsoleExposure::reach()`、`finish()` を実装する**

- `reach()` は、`ExposureCheck::reach()` の答えで分ける
  - `Reached`：`ReachedUrl($url, $web, false)`
  - `Unreachable`：今の `waitForBrowser()` で、その URL を開いてもらう。開かれれば `ReachedUrl($url, $web, true)`、開かれなければ `null`
  - `Refused`：「サーバーから {URL} を開きましたが、この Re:無事 に届きませんでした（{理由}）。」と「その URL が {ディレクトリ} を開くか、ウェブの PHP が 8.3 以上かを確かめてください。」を書いて `false`
  - `Redirected`：`Location` が `/` で始まれば、求めた URL のスキームとホスト（ポートがあればそれも）に足す。`/install/probe/` を含み、まだ転送をたどっていなければ、その前を新しい URL として「→ {新しい URL} に転送されたので、こちらを使います」を出し、新しい URL で最初から確かめる（たどるのは 1 回だけ）。それ以外は、`Location` からクエリの `from=console` を除いて（ほかのクエリは残し、残りが無ければ `?` も除く）、「{URL} は {転送先} に転送されました。{ディレクトリ} に届いていません。」を書いて `false`
- `finish()` は、今の `run()` の `webSideIsFit()` から後を、`$reached->web`、`$reached->byBrowser`、`$reached->url` で行う
- `run()` は消す

- [ ] **Step 4: `InstallCommand` を直す**

- `run()` の順番：公開ファイルを置く → `reachedUrl()` → `InstallContext::forConsole(…, $reached->url)` → データベース → `checkExposure($reached, $driver, $io)`（`finish()` を呼ぶ）→ 管理者 → …
- `reachedUrl()` の `$directory` は、`--public` のディレクトリ（`$public`）、無ければ `$this->paths->root()`
- `reachedUrl()`：
  - `--url` があれば、その URL を 1 回だけ確かめる。形が違えば今のとおり `URL_FORM` で止まる。`reach()` が `ReachedUrl` 以外なら `null`（聞き直さない）
  - 無ければ、「{ディレクトリ} を開く URL（例：https://anpi.example.jp）：」と聞く。形が違えば `URL_FORM`、`reach()` が `false` なら、もう一度聞く。形の誤りと届かない URL を合わせて `TRIES`（3）回で、通らなければ `GAVE_UP` で止まる。`reach()` が `null` なら、そこで止まる
  - 届いたら、`$reached->url` が `http://` で始まるときだけ、今の「注意：http の URL です。…」を出す
- `reach()` と `finish()` の両方を、今の `checkExposure()` と同じく `RuntimeException`（`ProbeVisit` が `storage/` に書けない）を受けて止まる形にする。`ConsoleExposure` は 1 つ作って両方に使う
- `InstallCommand` が案内するコマンドのパスを `'…'` で囲む（設計 3.2）：「上げるときは sh '{本体}/bin/upgrade.sh' を流してください。」と「chmod 600 '{設定ファイル}' で、自分だけが読めるようにしてください。」。それぞれを確かめている今のテストの文も直す
- **Ruling：** `bin/install.sh` の同じ形の案内（`sh $root/bin/upgrade.sh`）は、この計画では触らない（Global Constraints）。パスは HOME の下で、`?` や `*` を含むことはまず無い。外れていたら、そうしたパスのときに tcsh で案内どおりに打てない

- [ ] **Step 5: 通ることを確かめる**

Run: `composer test && composer analyse && composer lint`
Expected: すべて通る

- [ ] **Step 6: コミット**

```bash
git add src/Install/ReachedUrl.php src/Install/ConsoleExposure.php src/Console/InstallCommand.php tests/Install/ConsoleExposureTest.php tests/Console/InstallCommandTest.php
git commit -m "fix: ask for the public URL naming its directory, ask again when a typed one does not reach, and follow a redirect that keeps the probe"
```

---

### Task 4：手順書をさくらの独自ドメインの手順にし、版を 0.4.1 にする

**Files:**
- Modify: `docs/install.md`（「SSH で入れる」の節）
- Modify: `README.md`（「レンタルサーバーに置く」の 2 行と説明、開発の節の版）
- Modify: `src/Version.php`、`.env.example`、`compose.yaml`（2 か所）：`0.4.0` → `0.4.1`
- Test: 今のテスト（`DocumentLinksTest`、`EnvExample` を使うテストなど）が通ること

- [ ] **Step 1: `docs/install.md` の「SSH で入れる」を書き直す**

節の頭（2 行のコマンドの前）を、次の内容にする。文はこのとおりにする。

```markdown
## SSH で入れる（さくらのレンタルサーバ）

さくらのレンタルサーバ（スタンダードプラン以上。SSH と CRON がスタンダード以上のため）で確かめています。ほかのサーバーでは確かめていません。

### 先にドメインを用意する

1. サーバーコントロールパネルの「ドメイン/SSL」の「ドメイン新規追加」で、独自ドメインか、そのサブドメインを足します（[ドメインを追加したい](https://help.sakura.ad.jp/domain/2145/)）。ドキュメントルートは、`~/www` の下の新しい空のフォルダ（たとえば `~/www/anpi`）にします。反映には数時間かかることがあります。
2. 同じ画面の、そのドメインの「設定」→「SSL設定」で、無料 SSL（Let's Encrypt）を使います（[無料SSLを設定したい](https://help.sakura.ad.jp/rs/2153/)）。発行には数十分から数時間かかります。Let's Encrypt は独自ドメインが対象です。http から https に転送したいときは、「HTTPS転送設定」をオンにします（[ドメインリダイレクトを設定したい](https://help.sakura.ad.jp/domain/2150/)）。
3. ブラウザで `https://<ドメイン>/` が、証明書の警告なしに開くことを確かめます（空のフォルダなら 403 か 404 になります）。

初期ドメイン（`アカウント名.sakura.ne.jp`）でも動きますが、URL に契約のアカウント名が出ます。Re:無事 は自分のドメインを覚えていないので、あとで別のドメインに移るときは、ドキュメントルートを付け替えて、監視サービスに見張りの URL を登録し直すだけです。

### 入れる
```

- 続く 2 行のコマンドを `sh install.sh --public ~/www/anpi --url https://anpi.example.jp` にし、「`~/www/anpi` と `https://anpi.example.jp` は、先に用意したフォルダとドメインに読み替えてください」と書く
- 番号つきの流れ（今の 1〜6）の 4 は、「公開する URL」を「公開する URL（`--url` を付けなければ、公開ディレクトリを示して聞きます）」にする
- 引数の表の `--url` の行：「公開する URL。付けなければ、公開ディレクトリを示して聞きます。届かなければ、その場で聞き直します」
- 「php の探し方」の節：さくらのコマンドラインの php は `/usr/local/bin/php` と書き、出典を付ける（[プログラムのパス](https://help.sakura.ad.jp/rs/2252/)）。例の `env PHP=…` は `env PHP=/usr/local/bin/php sh install.sh --public ~/www/anpi --url https://anpi.example.jp` にする。探す順の説明（ほかの候補）は残す
- 設定ファイルの節の後か「途中で止まったとき」の前に、次の段落を足す

```markdown
### テストメールの宛先

テストメールは、最初の管理者のアドレスに送ります。返信を受ける受信箱とは別のアドレスにしてください。同じでも設置は終わりますが、受信箱のメールをメールソフトで読むと、Re:無事 が読むメールと混ざります。
```

- 「cron」の節：4 社の表と、その前後の「4 社の公式のマニュアルを調べた結果です」「書いていない会社では…」の段落を、次に置き換える（最初の段落、`crontab` が使えるときの説明は残す）

```markdown
さくらのレンタルサーバでは、SSH の `crontab` で足した行が、サーバーコントロールパネルの「スクリプト」→「CRON設定」にも出ます（公式のマニュアルには書かれていません。作者が確かめました）。管理画面の「CRON設定」では、最短の間隔が 2 分で、毎分は選べません。毎分（1）で足した行は、管理画面で変えて保存しようとすると弾かれます。管理画面でも変えたいときは、何分ごとかの問いに 2 以上を答えてください。管理画面から登録できるのは、最大 10 個です（[CRONを設定したい](https://help.sakura.ad.jp/rs/2242/)）。
```

- 「FTP で置く」の節と、それより後は変えない

- [ ] **Step 2: `README.md` を直す**

- 「レンタルサーバーに置く」の 1 段落目を「さくらのレンタルサーバ（SSH が使えるスタンダードプラン以上）で確かめています。先にサーバーコントロールパネルでドメインと無料 SSL を用意し（[docs/install.md](docs/install.md)）、次の 2 行で、ファイルを置くところから初期設定までを済ませます。」にする
- 2 行目を `sh install.sh --public ~/www/anpi --url https://anpi.example.jp` にする
- 開発の節の `0.4.0`（3 か所）を `0.4.1` にする

- [ ] **Step 3: 版を 0.4.1 にする**

`src/Version.php` の `CURRENT`、`.env.example` の `REBUJI_VERSION`、`compose.yaml` の 2 か所の既定値。`docs/install.md` の「（0.4.0 以降）」は `install.sh` が使える最初の版なので、変えない。

- [ ] **Step 4: 通ることを確かめる**

Run: `composer test && composer analyse && composer lint && bin/build-release.sh 0.4.1`
Expected: すべて通り、`build/re-buji-0.4.1.zip` ができる

- [ ] **Step 5: コミット**

```bash
git add docs/install.md README.md src/Version.php .env.example compose.yaml
git commit -m "docs: write the SSH install as Sakura's steps with a custom domain, and set the version to 0.4.1"
```

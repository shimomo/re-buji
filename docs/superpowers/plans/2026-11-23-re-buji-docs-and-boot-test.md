# Re:無事 計画 26：文書の 4 件と、起動のテスト 実装計画

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 上げ方と使い方の文書の 3 件、設計書の 1 件、Docker の起動のテストの 1 件を直す。5 件は作者が選んだ。

**Architecture:**
- アプリのコード（`src/`、`templates/`、`public/`、`bin/`）は変えない。DB の形も変えない
- 変えるのは `docs/upgrade.md`、`docs/usage.md`、送信のアカウントの見張りの設計書、`tests/Console/BootCommandTest.php` だけ

**Tech Stack:** 計画 25 と同じ。依存は増やさない。

**Spec:**
- `docs/superpowers/specs/2026-11-23-re-buji-mail-check-design.md`（送信のアカウントの見張り。Task 2 で直す）
- 文書の 3 件には設計書が無い。出どころの PR の本文（#27、#29、#30）の「後回しにした minor」が根拠

**前の計画：** `docs/superpowers/plans/2026-11-23-re-buji-favicon.md`（計画 25、PR #30 でマージ済み、v0.3.6 はリリース済み）。直すのは次の 5 件。作者が 2026-11-23 に選んだ。

| 直すこと | 出どころ | タスク |
|---|---|---|
| `docs/upgrade.md` が、`assets/` のフォルダを「画面の見た目の `app.css`」とだけ説明している。文字どおりに `app.css` だけを上げると、アイコンが出ない | 計画 25 の 1 | Task 1 |
| `public/` の中身だけを置く例のフォルダが、`docs/install.md` では `public_html/anpi/`、`docs/upgrade.md` では `~/www/re-buji` と違う | 計画 22 の 4 | Task 1 |
| `docs/usage.md` の「次の回に送り直します」は、実際より早く読める。一時的に断られた連絡と再送は、15 分後（`NotificationSender::DEFER_MINUTES`）に送り直す | 計画 24 の 2 | Task 1 |
| `checksTheSendingAccountOnTheNextRunAfterEveryStart` は 2 回目の起動でパスワードを変えているので、「設定が変わったときだけ確かめる」作りでも通る | 計画 24 の 3 | Task 2 |
| 設計書の「いつ確かめるか」に、Docker の起動のことが書いていない | 計画 24 の 1 | Task 2 |

## Global Constraints

- 計画 1〜25 の Global Constraints をすべて引き継ぐ。主なもの：
  - PHP `^8.3`、`setUp` / `tearDown` を使わない、コミットメッセージの末尾に `Co-Authored-By`、Psalm errorLevel 1、Pint
  - `docs/superpowers/` へのリンクは GitHub の絶対 URL。`README.md` と `docs/*.md` のリンクは `DocumentLinksTest` が確かめる
- アプリのコードは変えない。終わったときに `git diff main --stat -- src templates public bin migrations` が何も出さない（Task 2 で一時的に `src/` を変えるが、コミットの前に戻す）
- 文書の文言は、各タスクに書いたとおりにする
- **[判断]** 版は上げない（`0.3.6` のまま）。アプリの動きが変わらず、文書は GitHub の main で読まれる（README とリリースノートのリンクは main を指す）ため。外れていたら、zip の中の `docs/` が次の版まで古いまま。出すなら、作者がマージの後に版を上げる
- **[判断]** 例のフォルダは `docs/install.md` の `public_html/anpi/` にそろえる（`docs/upgrade.md` の `~/www/re-buji` を `~/public_html/anpi` にする）。設置のときに読んだ例と、上げるときの例が同じになるように。外れていたら、さくらのレンタルサーバ（公開ディレクトリは `~/www`）の人には、前の例のほうが近かった

## Review Focus

- `assets/` に今後ファイルが増えても、文書を直さずに済む → Task 1 の新しい文は、ファイルを 1 つずつ挙げず「アイコン」とまとめる
- `docs/upgrade.md` の `--root ~/re-buji` の例 → `docs/install.md` の `rebuji-root.php` の例（`/home/ユーザー名/re-buji`）と合っているので、変えない
- 送信の設定を変えずに 2 回起動する → 2 回目のあとも、次の cron の回で確かめる（Task 2 で直すテストが押さえる）
- 失敗の直後の起動 → 10 分待つ。既存の `BootCommandTest::holdsTheCheckAfterAStartRightAfterAFailure` と、`SendingAccountTest` の `waitsAfterAFreshFailureBeforeCheckingAfterAStart`・`checksOnTheNextRunAfterAStartLongAfterAFailure` が押さえていて、Task 2 では触らない
- 設計書の数字（10 分）と、コードの `SendingAccount::START_RECHECK_MINUTES` が合っている（Task 2 Step 6）

---

### Task 1：上げ方と使い方の文書を直す

**Files:**
- Modify: `docs/upgrade.md`（20 行目、29 行目、37 行目）
- Modify: `docs/usage.md`（21 行目）

文の変更だけなので、残るテストは足さない。下の `grep` と `DocumentLinksTest` で確かめる。

- [ ] **Step 1: FTP の節の `assets/` の説明を直す**

`docs/upgrade.md` の「FTP で上げる」の 2 つめの段落を、次のとおりにする。

前：

```markdown
`public/` の中身だけを公開ディレクトリに置いている場合は、本体を上書きした後で、公開ディレクトリも新しい `public/` の中身で上書きしてください。`assets/` のフォルダ（画面の見た目の `app.css`）を先に、`index.php` を最後に上げます（`rebuji-root.php` はそのまま残します）。`assets/` を上げないと、画面の見た目がそろいません。`index.php` を本体より先に上げると、本体を上げ終わるまで画面がエラーになります。
```

後：

```markdown
`public/` の中身だけを公開ディレクトリに置いている場合は、本体を上書きした後で、公開ディレクトリも新しい `public/` の中身で上書きしてください。`assets/` のフォルダを丸ごと先に、`index.php` を最後に上げます（`rebuji-root.php` はそのまま残します）。`assets/` には、画面の見た目の `app.css` と、ブラウザのタブやブックマークに出るアイコンが入っています。`assets/` を上げないと、画面の見た目がそろわず、アイコンも出ません。`index.php` を本体より先に上げると、本体を上げ終わるまで画面がエラーになります。
```

- [ ] **Step 2: `--public` の例のフォルダを、`docs/install.md` にそろえる**

`docs/upgrade.md` の 2 か所（20 行目と 29 行目）の `~/www/re-buji` を `~/public_html/anpi` にする。ほかの部分（`--root ~/re-buji`、29 行目の末尾のコメント）は変えない。

- [ ] **Step 3: 送り直すまでの時間を書く**

`docs/usage.md` の 21 行目の「次の回に送り直します」を「15 分ほどあとに送り直します」にする。文のほかの部分は変えない。

- [ ] **Step 4: 確かめる**

Run: `grep -n 'のフォルダ（画面の見た目\|www/re-buji\|次の回に送り直します' docs/upgrade.md docs/usage.md; grep -c 'public_html/anpi' docs/upgrade.md; vendor/bin/phpunit tests/DocumentLinksTest.php`
Expected: 1 つめの `grep` は何も出さない。2 つめは `2`。`DocumentLinksTest` は OK

- [ ] **Step 5: コミット**

```bash
git add docs/upgrade.md docs/usage.md
git commit -m "docs: say assets/ holds the icons too, match the folder examples, and give the 15-minute retry"
```

---

### Task 2：起動のたびに確かめることを、テストと設計書で押さえる

**Files:**
- Modify: `tests/Console/BootCommandTest.php`（`checksTheSendingAccountOnTheNextRunAfterEveryStart`）
- Modify: `docs/superpowers/specs/2026-11-23-re-buji-mail-check-design.md`（「いつ確かめるか」と「私が決めたこと」）
- 一時的に変えて戻す：`src/Console/BootCommand.php`（コミットしない）

**Interfaces:**
- Consumes: `BootCommand::run()` の `SendingAccount::checkAfterStart()` の呼び出し（145 行目）、`SendingAccount::START_RECHECK_MINUTES = 10`

今のコードはすでに起動のたびに確かめるので、テストを直しても初めから通る。そこで、「変わったときだけ確かめる」作りを一時的に入れ、今のテストがそれを見逃すこと、直したテストが捕まえることを確かめてから戻す。

- [ ] **Step 1: 「変わったときだけ確かめる」作りを一時的に入れる**

`src/Console/BootCommand.php` の `$this->service(SendingAccount::class)->checkAfterStart();` を、次に置き換える（コミットしない）。

```php
$fingerprint = hash('sha256', serialize($settings->smtp()));

if ($state->get('mail.account.boot_fingerprint') !== $fingerprint) {
    $state->set('mail.account.boot_fingerprint', $fingerprint);
    $this->service(SendingAccount::class)->checkAfterStart();
}
```

- [ ] **Step 2: 今のテストが見逃すことを確かめる**

Run: `vendor/bin/phpunit --filter checksTheSendingAccountOnTheNextRunAfterEveryStart`
Expected: PASS（2 回目の起動でパスワードが変わるので、この作りでも確かめる。これが計画 24 の 3）

- [ ] **Step 3: テストを直す**

`checksTheSendingAccountOnTheNextRunAfterEveryStart` の 2 回目の起動を、1 回目と同じ設定（`self::MAIL`）にする。名前と、ほかの確かめは変えない。

```php
// The same settings as the first start: a start cannot tell a fixed .env from an unchanged one, so it checks either way.
[$code, , $second] = $this->boot($root, self::MAIL, $clock);
```

- [ ] **Step 4: 直したテストが捕まえることを確かめる**

Run: `vendor/bin/phpunit --filter checksTheSendingAccountOnTheNextRunAfterEveryStart`
Expected: FAIL（`isDue()` が false。設定が変わっていないので、一時的な作りは確かめない）

- [ ] **Step 5: 一時的な作りを戻して、通ることを確かめる**

Run: `git checkout -- src/Console/BootCommand.php && git diff --quiet -- src && vendor/bin/phpunit tests/Console/BootCommandTest.php`
Expected: OK（`src/` に差分が無く、`BootCommandTest` がすべて通る）

- [ ] **Step 6: 設計書に Docker の起動のことを書く**

`docs/superpowers/specs/2026-11-23-re-buji-mail-check-design.md` の「いつ確かめるか」の、「送信の設定（SMTP の欄）を保存したら、次の cron の回で確かめる」の次の行に足す。

```markdown
- Docker のコンテナを起動したら（`bin/console boot`）、送信の設定が変わったかを見分けずに、次の cron の回で確かめる。`.env` で直した設定を、最大 1 時間待たずに確かめるため。ただし、最後に使えなかった時刻から 10 分たつまでは待つ（再起動を繰り返すコンテナが、間違ったパスワードで cron の回ごとにログインして、事業者に締め出されないように）。計画 24（v0.3.5）で足した
```

「私が決めたこと（作者が直せる）」の最後に足す。

```markdown
- **Docker の起動では、送信の設定が変わったかを見分けない**：見分けるには前の起動の設定を覚える必要があり、パスワードから作った値を DB に残すことになるため。外れていたら、再起動のたびに、次の cron の回のログインが 1 回増える
```

Run: `grep -n 'START_RECHECK_MINUTES = ' src/Mail/SendingAccount.php`
Expected: `10`（設計書の「10 分」と合う）

- [ ] **Step 7: すべてを確かめる**

Run: `composer test && composer analyse && composer lint && git diff main --stat -- src templates public bin migrations`
Expected: テスト、Psalm、Pint は OK。最後の `git diff` は何も出さない

- [ ] **Step 8: コミット**

```bash
git add tests/Console/BootCommandTest.php docs/superpowers/specs/2026-11-23-re-buji-mail-check-design.md
git commit -m "test: check the sending account on every start even with the same settings, and write the start into the spec"
```

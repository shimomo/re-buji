# Re:無事 計画 21：README の整理 実装計画

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** README を最初に辿り着いた人のためのものにし、設置・使い方・上げ方を `docs/` の 3 つの文書に移す。運用の内容は 1 つも失わない。

**Architecture:** 文を書き直さずに移す。リリースの zip に `docs/*.md` を入れ（`docs/superpowers/` は入れない）、README を指していた設置の画面のエラー文とリリースのスキルを、新しい文書に向ける。アプリの動きと DB の形は変えない。

**Tech Stack:** 計画 20 と同じ。依存は増やさない。

**Spec:** `docs/superpowers/specs/2026-11-23-re-buji-readme-split-design.md`

**前の計画：** `docs/superpowers/plans/2026-11-23-re-buji-v0.3.2.md`（計画 20、PR #25 でマージ済み、v0.3.2 はリリース済み）。

## Global Constraints

- 計画 1〜20 の Global Constraints をすべて引き継ぐ。主なもの：
  - PHP `^8.3`、`setUp` / `tearDown` を使わない、コミットメッセージの末尾に `Co-Authored-By`、Psalm errorLevel 1、Pint
  - `src/php-version.php`、`public/index.php`、`bin/cron.php` は触らない
- 移すときは文を変えない。変えてよいのは、見出しの深さ、リンク、移した先で前後をつなぐリンクの 1 行だけ。例外は「メールの設定」の、空行で割れた箇条書きを 1 つにすること
- 新しく書く文は、README の「できること」「はじめる」の「レンタルサーバーに置く」「文書」だけ
- ファイル名は英語、文書の題名は日本語（`docs/docker.md` に合わせる）
- `docs/superpowers/` へのリンクは GitHub の絶対 URL（`https://github.com/shimomo/re-buji/blob/main/docs/superpowers/…`）。zip に入らないため
- **[判断]** 版は上げない（`0.3.2` のまま）。アプリの動きは変わらず、変わるのはエラー文の指す先と zip の中の文書だけなので、次に出す版にまとめて入れる。外れていたら、0.3.2 の zip の README は運用の節を持ったまま、しばらく残る（GitHub の README とは別物になるだけで、害はない）

## Review Focus

- 今の README の説明のうち、どの文書からも辿れなくなるもの → 失敗とみなす。Task 2 の Step 6 で、今の README の行がすべて新しい文書のどこかにあることを確かめる
- zip の中で文書を開いたときに切れるリンク（`docs/superpowers/` への相対リンク）→ 無いこと（Task 2 のテスト）
- GitHub の見出しのアンカー（日本語、全角の括弧、`CSV` のような大文字）→ 「できること」などの節へのリンクが、正しい見出しに着くこと（Task 2 のテスト）
- 0.3.2 までの設置の画面で「…方法（README）」を見て README を開いた人 → README の「文書」の表から、`docs/install.md` の置き方に 1 回で行けること（Task 2、読んで確かめる）
- 全体を公開ディレクトリに置いたサーバーで、`docs/` がウェブから読めるか → ルートの `.htaccess` がすべてを `public/` に回すので読めない。`mod_rewrite` が無くて読めても、中身は GitHub で公開しているものなので害はない（確かめるだけで、変えない）

---

### Task 1：リリースの zip に `docs/*.md` を入れる

**Files:**
- Modify: `bin/build-release.sh`（`rm -rf` の行）
- Test: `tests/BuildReleaseTest.php`

**Interfaces:**
- Produces: zip の中の `re-buji/docs/*.md`。Task 2 の README の相対リンク `docs/…` が、zip の中でも切れなくなる

- [ ] **Step 1: 失敗するテストを書く**

`BuildReleaseTest::shipsTheUserDocsButNotThePlans` を足す。準備は `leavesTheDockerFilesOutOfTheZip` と同じ（一時リポジトリに `bin/build-release.sh`、`src/Version.php`、`build-files/zip.php`、`build-files/vendor.htaccess`、`composer.json` を置いてコミットし、`Version::CURRENT` でビルド）。置くファイルは `docs/install.md`、`docs/superpowers/plans/plan.md`、`docs/superpowers/specs/spec.md`（中身は `"x\n"`）。

```php
$this->assertContains('re-buji/docs/install.md', $names);
$this->assertSame([], array_values(array_filter($names, static fn(string $name): bool => str_starts_with($name, 're-buji/docs/superpowers'))));
```

- [ ] **Step 2: 失敗を確かめる**

Run: `vendor/bin/phpunit --filter shipsTheUserDocsButNotThePlans`
Expected: FAIL（`re-buji/docs/install.md` が無い）

- [ ] **Step 3: `bin/build-release.sh` で、消すのを `"$app/docs"` から `"$app/docs/superpowers"` にする**

- [ ] **Step 4: 通ることを確かめる**

Run: `vendor/bin/phpunit tests/BuildReleaseTest.php`
Expected: OK（5 tests）

- [ ] **Step 5: コミット**

```bash
git add bin/build-release.sh tests/BuildReleaseTest.php
git commit -m "build: ship the user docs in the release zip, but not the plans and designs"
```

---

### Task 2：運用の部分を `docs/` に移し、README を最初の人向けにする

**Files:**
- Create: `docs/install.md`、`docs/usage.md`、`docs/upgrade.md`
- Modify: `README.md`、`docs/docker.md`（3 行目の設計書へのリンクだけ）
- Test: `tests/DocumentLinksTest.php`（新）

**Interfaces:**
- Consumes: Task 1（zip に `docs/*.md` が入る）
- Produces: `docs/install.md`（Task 3 のエラー文が指す）、`docs/upgrade.md`（Task 3 のリリースノートのひな形が指す）

- [ ] **Step 1: 失敗するテストを書く**

`tests/DocumentLinksTest.php`。対象は `README.md` と `docs/*.md`（`docs/superpowers/` の中は対象外）。どちらのテストも、` ``` ` で囲まれたコードの中は読まない。リンクは `/\[[^\]]*\]\(([^)\s]+)\)/u` で拾い、`http:`・`https:`・`mailto:` で始まるものは外す。

- `pointsEveryRelativeLinkAtAFileAndHeadingThatExist`：相対リンクの先のファイルが、そのファイルのディレクトリから見てあること。`#` があれば、先のファイル（`#` だけなら同じファイル）に、その名前の見出しがあること。見出しの名前は GitHub と同じ作り方：
  ```php
  str_replace(' ', '-', (string) preg_replace('/[^\p{L}\p{M}\p{N}\p{Pc}\- ]/u', '', mb_strtolower(trim($heading))))
  ```
  同じ名前の見出しが 2 つ目からは `-1`、`-2`…を付ける。失敗の文に、ファイル名とリンクを出す
- `keepsThePlansOutOfTheShippedDocuments`：相対リンクの先が `docs/superpowers/` の中でないこと（zip に入らないので、zip の中では切れる）

- [ ] **Step 2: 失敗を確かめる**

Run: `vendor/bin/phpunit tests/DocumentLinksTest.php`
Expected: `keepsThePlansOutOfTheShippedDocuments` が FAIL（README の NOTE と `docs/docker.md` の 3 行目が、設計書を相対リンクで指している）。`pointsEveryRelativeLinkAtAFileAndHeadingThatExist` は今のままでも通る（今の README のリンクはどれも切れていない）

- [ ] **Step 3: 3 つの文書を作る**

どの文書も、題名の下に `[README に戻る](../README.md)` を置く。中身は今の README から、文を変えずに移す。

`docs/install.md`（題名「# Re:無事 をレンタルサーバーに置く」）
1. 今の「### レンタルサーバーに置く」の 1.〜4.（題名の直下に置く。文は変えない）
2. 「## 初期設定」：今の表
3. 「## cron」：今の節そのまま
4. 「## 見張り」：今の「見張り」の 1 段落目（「状態の URL」を監視サービスに登録する段落）だけ。その後に、つなぎの 1 行「ホーム画面に出ることと、管理者に届く知らせは、[使い方](usage.md#ホーム画面) にあります。」

`docs/usage.md`（題名「# Re:無事 の使い方」）
1. 「## ホーム画面」：つなぎの 1 行「見張りの URL の登録は、[設置](install.md#見張り) にあります。」の後に、今の「見張り」の 2 段落目以降（「ホーム画面には、次のことも出ます。」から、管理者へのメールの段落まで）
2. 「## 従業員の登録」と「### CSV」、「## メールの設定」（割れた箇条書きを 1 つにする）、「## 地震の連絡」、「## 返信と責任者の画面」、「## 訓練」、「## 記録の削除」：今の節そのまま、この順で

`docs/upgrade.md`（題名「# Re:無事 を新しい版に上げる」）
1. 「## SSH で上げる（0.3.1 から）」と「## FTP で上げる」：今の「バージョンアップ」の 2 つの節そのまま（0.3.0 までから上げる段落と、0.2.0 までの権限の段落も、今の位置のまま）
2. 最後に、つなぎの 1 行「Docker で動かしている場合は、[Docker で動かす](docker.md#更新) を見てください。」

`docs/docker.md`：3 行目の設計書へのリンクを、GitHub の絶対 URL にする。ほかは変えない。

- [ ] **Step 4: README を書き換える**

上から次の順にする。

1. 題名、紹介の 2 段落：今のまま
2. NOTE：今のまま。設計書へのリンクだけ GitHub の絶対 URL にする
3. 「## できること」（新しく書く）：1 行 1 件、7 件ほど。各行の終わりに、詳しい節へのリンク。事実は今の README のとおりに書く
   - 登録した市町村が震度 5 弱（変えられる）以上になった人に、地震ごとにメールを 1 通 → `docs/usage.md#地震の連絡`
   - 何も書かずに返信すれば「無事」、何か書けば「要確認」 → `docs/usage.md#返信と責任者の画面`
   - 返信の無い人には送り直す（既定は 3 回・60 分ごと） → `docs/usage.md#地震の連絡`
   - 責任者の画面で、返信なし・要確認・無事を見る。電話で確かめた人は代理で記録 → `docs/usage.md#返信と責任者の画面`
   - 従業員は、Excel の CSV でまとめて登録 → `docs/usage.md#csv`
   - 訓練の連絡を送れる → `docs/usage.md#訓練`
   - cron が止まっても、監視サービスと管理者へのメールで気づける → `docs/install.md#見張り`
4. 「## 必要なもの」：今のまま
5. 「## はじめる」：
   - 「### Docker で動かす（技術者向け、おすすめ）」：今の節そのまま（4 行のコードと、VPS の手順へのリンク）
   - 「### レンタルサーバーに置く」（新しく書く、1〜2 行）：リリースから zip を取って FTP で置き、ブラウザで初期設定を進めること。置き方は 2 通りあり、手順は `docs/install.md` にあること
6. 「## 文書」（新しく書く）：表。列は「文書」「読むとき」
   - `docs/install.md`：レンタルサーバーに置くとき（置き方、初期設定、cron、見張り）
   - `docs/usage.md`：使っているとき（ホーム画面、従業員、メール、地震の連絡、返信と責任者の画面、訓練、記録の削除）
   - `docs/upgrade.md`：新しい版に上げるとき
   - `docs/docker.md`：Docker で動かすとき（手元、VPS）
7. 「## 開発」「## 出典と注意」「## ライセンス」：今のまま

今の README の「## 初期設定」〜「## バージョンアップ」は、README から消す（Step 3 で移した）。

- [ ] **Step 5: テストが通ることを確かめ、リンクのテストが壊れたリンクを捕まえることも確かめる**

Run: `vendor/bin/phpunit tests/DocumentLinksTest.php`
Expected: OK（2 tests）

README の `docs/usage.md#地震の連絡` の 1 つを、一時的に `docs/usage.md#地震の知らせ` に変えて、もう一度流す。
Expected: `pointsEveryRelativeLinkAtAFileAndHeadingThatExist` が FAIL し、失敗の文に `README.md` と `docs/usage.md#地震の知らせ` が出る。確かめたら元に戻す。

- [ ] **Step 6: 今の README の行が、どれも新しい文書にあることを確かめる**

```bash
php -r '
$old = explode("\n", (string) shell_exec("git show main:README.md"));
$new = file_get_contents("README.md");
foreach (glob("docs/*.md") as $f) { $new .= "\n" . file_get_contents($f); }
foreach ($old as $line) {
    $t = trim($line);
    if ($t === "" || preg_match("/^#{1,6} /", $t) === 1) { continue; }
    if (!str_contains($new, $t)) { echo $t, "\n"; }
}'
```

Expected: 出るのは NOTE の行（設計書へのリンクを絶対 URL にした行）だけ。ほかの行が出たら、移しそこねか書き換えなので、直す。見出しの行はこの確かめから外しているので、`grep -c '^#' README.md docs/install.md docs/usage.md docs/upgrade.md` と今の README の見出しを目で突き合わせる。

- [ ] **Step 7: 全体を確かめてコミット**

Run: `composer test && composer analyse && composer lint`
Expected: すべて通る

```bash
git add README.md docs/install.md docs/usage.md docs/upgrade.md docs/docker.md tests/DocumentLinksTest.php
git commit -m "docs: move installing, using and upgrading out of the README into docs/"
```

---

### Task 3：README を指していた先を、新しい文書に向ける

**Files:**
- Modify: `src/Install/Steps/KeyStep.php`、`src/Install/Steps/ExposureStep.php`、`.claude/skills/releasing-re-buji/release-notes-template.md`、`.claude/skills/releasing-re-buji/SKILL.md`
- Test: `tests/Install/InstallFlowTest.php`

**Interfaces:**
- Consumes: Task 2 の `docs/install.md`（置き方の節）、`docs/upgrade.md`

- [ ] **Step 1: 失敗するテストを書く**

`InstallFlowTest` の 2 つに、1 行ずつ足す。

- `cannotFinishWhileFilesAreExposed`：`$this->assertStringContainsString('公開する方法（docs/install.md）', $body);`
- `refusesAKeyThatCanBeReadFromTheWeb`：`$this->assertStringContainsString('公開する方法（docs/install.md）', (string) $response->getBody());`

- [ ] **Step 2: 失敗を確かめる**

Run: `vendor/bin/phpunit tests/Install/InstallFlowTest.php`
Expected: 2 つが FAIL（今の文は「…方法（README）」）

- [ ] **Step 3: エラー文を直す**

- `KeyStep`：「…public/ の中身だけを公開する方法（README）に切り替えてから…」の `（README）` を `（docs/install.md）` にする
- `ExposureStep`：「…public/ の中身だけを公開する方法（README）を使ってください。」の `（README）` を `（docs/install.md）` にする

- [ ] **Step 4: 通ることを確かめる**

Run: `vendor/bin/phpunit tests/Install/InstallFlowTest.php`
Expected: OK

- [ ] **Step 5: リリースのスキルを直す**

- `release-notes-template.md`：「（README の「バージョンアップ」）」を「（[docs/upgrade.md](https://github.com/shimomo/re-buji/blob/main/docs/upgrade.md)）」にする
- `SKILL.md` の手順 4：足したファイルを見る `git diff` に、`docs` と `':!docs/superpowers'` を足す（zip に入る文書が増えたら、ノートの「新しいファイル」に出るように）

```
git diff --stat --diff-filter=A v{前の版} HEAD -- src templates public migrations bin docs ':!bin/release.sh' ':!bin/build-release.sh' ':!docs/superpowers'
```

出した版のノートは直さない。

- [ ] **Step 6: 全体を確かめてコミット**

Run: `composer test && composer analyse && composer lint`
Expected: すべて通る

```bash
git add src/Install/Steps/KeyStep.php src/Install/Steps/ExposureStep.php tests/Install/InstallFlowTest.php .claude/skills/releasing-re-buji/release-notes-template.md .claude/skills/releasing-re-buji/SKILL.md
git commit -m "docs: point the install errors and the release notes at the new docs"
```

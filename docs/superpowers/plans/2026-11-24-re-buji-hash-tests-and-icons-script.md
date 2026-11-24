# Re:無事 計画 31：ハッシュのテストの仕上げと、アイコンを作るスクリプト 実装計画

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** `LoginHashes` のテストの抜け 2 つとメッセージ 1 つ、`build-files/icons.sh` の 2 件を直す。5 件は作者が選んだ。

**Architecture:**
- アプリのコード（`src/`、`templates/`、`public/`、`bin/`）は変えない。DB の形も変えない
- 変えるのは `tests/Auth/LoginHashesTest.php` と `build-files/icons.sh` だけ

**Tech Stack:** 計画 30 と同じ。依存は増やさない。

**Spec:** `docs/superpowers/specs/2026-11-05-re-buji-design.md`（3.2 管理者）と `docs/superpowers/specs/2026-11-23-re-buji-favicon-design.md`（PNG を作り直す道具）。5 件の根拠は、出どころの PR の本文（#30、#35）の「後回しにした minor」

**前の計画：** `docs/superpowers/plans/2026-11-24-re-buji-login-hashes-and-rate-limit.md`（計画 30、PR #35 でマージ済み。版は上げていない）。直すのは次の 5 件。作者が 2026-11-24 に選んだ。

| 直すこと | 出どころ | タスク |
|---|---|---|
| 強さを渡さずに作る `LoginHashes`（実際のアプリが使う形）を確かめるテストが無い。将来の PHP の既定の強さに合う仮のハッシュが無い場合も気づけない | 計画 30 の 1 | Task 1 |
| 「bcrypt でない、壊れたハッシュは、仮のハッシュの選び方で数えない」ことのテストが抜けた | 計画 30 の 2 | Task 1 |
| 前提を確かめるテストのメッセージが `weakerThanDefault()` だけを挙げている。`dummy()` も bcrypt を前提にしている | 計画 30 の 4 | Task 1 |
| `build-files/icons.sh` を Ctrl-C などで止めると、一時フォルダが残る（dash は `EXIT` の trap を流さない） | 計画 25 の 3 | Task 2 |
| `build-files/icons.sh`：Node は `NODE_PATH` より先に、スクリプトのあるフォルダから上の `node_modules` を見るので、上に `@resvg/resvg-js` があると、固定した 2.6.2 ではなくそちらを使う | 計画 25 の 2 | Task 2 |

## Global Constraints

- 計画 1〜30 の Global Constraints をすべて引き継ぐ。主なもの：
  - PHP `^8.3`、`setUp` / `tearDown` を使わない、コミットメッセージの末尾に `Co-Authored-By`、Psalm errorLevel 1、Pint
- アプリのコードは変えない。終わったときに `git diff main --stat -- src templates public bin migrations` が何も出さない
- 版は上げない（`0.3.9` のまま）。利用者から見た動きが変わらないので、リリースも要らない
- テストのメッセージとスクリプトのコメントの文言は、各タスクに書いたとおりにする
- **[判断]** 足すテストは、今のコードで初めから通る（今の動きを押さえるテスト）。そこで、テストが守るところを一時的に壊し、失敗することを確かめてから戻す（計画 26、28 と同じやり方）。外れていたら、壊し方が実際の崩れ方と違い、テストが守る範囲を読み違える
- **[判断]** `icons.sh` は、`icons.cjs` を一時フォルダに写して、そこから動かす。Node はスクリプトのあるフォルダから上へ部品を探すので、一時フォルダの `node_modules` だけが見つかる（`NODE_PATH` は要らなくなる）。外れていたら、`icons.cjs` が自分の場所を頼るように変わったとき（今は頼っていない）、写した先で動かなくなる

## Review Focus

- PHP 8.3（既定の強さ 10）で、強さを渡さずに作る → 仮のハッシュは強さ 10 のもの。CI の PHP 8.3 で、Task 1 `LoginHashesTest::usesPhpsDefaultCostWhenNoneIsGiven` が流れる
- 強さを渡さずに作ったときの作り直し → 既定より 1 つ弱いハッシュは作り直す（同じテスト）
- スクリプトを変えた後も、同じ SVG から 1 バイトも違わない PNG ができる（Task 2 Step 4）
- 上のフォルダに別の `@resvg/resvg-js` がある → 固定した 2.6.2 を使う（Task 2 Step 2・5）
- 途中で止める → 一時フォルダが残らない（Task 2 Step 2・5）

---

### Task 1：`LoginHashes` のテストの抜けを埋め、前提のメッセージを直す

**Files:**
- Test: `tests/Auth/LoginHashesTest.php`
- 一時的に変えて戻す：`src/Auth/LoginHashes.php`（コミットしない）

- [ ] **Step 1: テストを書く**

```php
#[Test] public function usesPhpsDefaultCostWhenNoneIsGiven(): void
// 強さを渡さずに作る（実際のアプリと同じ）
// (new LoginHashes(self::admins([])))->dummy() は sprintf('$2y$%02d$', PASSWORD_BCRYPT_DEFAULT_COST) で始まる
//   （PHP の既定の強さに合う仮のハッシュが無い版では、ここで落ちる）
// (new LoginHashes(self::admins([])))->weakerThanDefault(password_hash('x', PASSWORD_BCRYPT, ['cost' => PASSWORD_BCRYPT_DEFAULT_COST - 1])) は true
```

`fallsBackToTheDefaultCost` に 4 つめの確かめを足す：

```php
// bcrypt として数えないハッシュ：'not a hash' と、'$2a$12$' . substr(self::COST_12, 7)
// (new LoginHashes(self::admins([それら 2 つ]), 10))->dummy() は '$2y$10$' で始まる（$2a$ を強さ 12 と数えれば 12 になる）
```

`assumesBcryptIsThePhpDefault` のメッセージを、次にする：

```php
'LoginHashes compares bcrypt costs and keeps bcrypt dummies: revisit weakerThanDefault() and dummy() if PHP\'s default algorithm changes'
```

- [ ] **Step 2: 通ることを確かめる**

Run: `vendor/bin/phpunit tests/Auth/LoginHashesTest.php`
Expected: PASS（今の動きを押さえるテスト）

- [ ] **Step 3: テストが守るところを壊して、失敗することを確かめる**

1 つずつ壊して流し、`git checkout -- src` で戻す。
- `LoginHashes` のコンストラクタの `$defaultCost ?? (defined(...) ? PASSWORD_BCRYPT_DEFAULT_COST : 10)` を `$defaultCost ?? 10` にする → `usesPhpsDefaultCostWhenNoneIsGiven` が FAIL（PHP 8.4 以降。手元の 8.5 で確かめる）
- `bcryptCost()` を、`password_get_info()` を使わず、`preg_match('/^\$2[abxy]\$(\d\d)\$/', $hash, $m) === 1 ? (int) $m[1] : null` を返す形にする → `fallsBackToTheDefaultCost` が FAIL（`$2a$` を強さ 12 と数える）

Run（最後に）: `git checkout -- src && git diff --quiet -- src && vendor/bin/phpunit tests/Auth && composer analyse`
Expected: `src/` に差分が無く、OK

- [ ] **Step 4: コミット**

```bash
git add tests/Auth/LoginHashesTest.php
git commit -m "test: pin LoginHashes as the app builds it, keep unknown hashes out of the dummy, and name both bcrypt assumptions"
```

---

### Task 2：`icons.sh` を、止めても片付け、固定した版だけを使うようにする

**Files:**
- Modify: `build-files/icons.sh`

テストの仕組みが無いスクリプトなので、手で確かめる。確かめに使うものは、作業用のフォルダ（`.superpowers/sdd/<plan>/`）とリポジトリの直下の `node_modules/` に一時的に置き、最後に消す。

- [ ] **Step 1: 直す前の動きを確かめる（2 つとも、困ったことが起きる）**

1. 止めたとき：
   - 作業用のフォルダに、10 秒待つだけの偽の `npm`（`#!/bin/sh` と `sleep 10`）を置き、実行できるようにする
   - `TMPDIR` を作業用のフォルダの中の空のフォルダにし、`PATH` の先頭に偽の `npm` のフォルダを置いて、`timeout -s INT 2 sh build-files/icons.sh` を流す
   - `TMPDIR` のフォルダに、`mktemp -d` が作ったフォルダが残る
2. 上のフォルダの部品：
   - リポジトリの直下に `node_modules/@resvg/resvg-js/package.json`（`{"name":"@resvg/resvg-js","main":"index.js"}`）と `index.js`（`throw new Error('decoy resvg');`）を置く
   - `sh build-files/icons.sh` を流す
   - 「decoy resvg」で止まる（固定した版ではなく、こちらを読む）

- [ ] **Step 2: 直す**

`build-files/icons.sh` の `trap 'rm -rf "$tmp"' EXIT` から最後までを、次にする。

```sh
trap 'rm -rf "$tmp"' EXIT
# dash runs the EXIT trap on exit only: a signal exits first, so the temporary directory still goes.
trap 'exit 1' HUP INT TERM

npm install --prefix "$tmp" --no-save --no-audit --no-fund --silent @resvg/resvg-js@2.6.2
# Node looks for a module from the script's own folder upwards before NODE_PATH, so a node_modules above this
# repository would win over the pinned version. Run from the temporary directory, where only the pinned one is.
cp "$root/build-files/icons.cjs" "$tmp/icons.cjs"
node "$tmp/icons.cjs" "$root"
```

- [ ] **Step 3: 止めたときを確かめ直す**

Run: Step 1 の 1 と同じ（偽の `npm`、空の `TMPDIR`、`timeout -s INT 2 sh build-files/icons.sh`）
Expected: `TMPDIR` のフォルダは空

- [ ] **Step 4: PNG が変わらないことを確かめる**

Run: リポジトリの直下の `node_modules/` を消してから、`sh build-files/icons.sh && git diff --quiet -- public/assets && echo same`
Expected: `same`（npm で部品を取るので、ネットにつながっている必要がある）

- [ ] **Step 5: 上のフォルダの部品を確かめ直す**

Run: Step 1 の 2 と同じ偽の部品を置いて、`sh build-files/icons.sh && git diff --quiet -- public/assets && echo same`
Expected: `same`（偽の部品を読まない）。終わったら、リポジトリの直下の `node_modules/` を消す

- [ ] **Step 6: 片付けを確かめる**

Run: `rm -rf node_modules && git status --short && git diff main --stat -- src templates public bin migrations`
Expected: `git status` は `build-files/icons.sh` の変更だけ。`git diff` は何も出さない

- [ ] **Step 7: コミット**

```bash
git add build-files/icons.sh
git commit -m "build: clean up icons.sh's temporary directory on a signal, and run icons.cjs where only the pinned resvg is"
```

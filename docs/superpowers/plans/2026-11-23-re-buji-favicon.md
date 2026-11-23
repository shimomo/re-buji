# Re:無事 計画 25：ファビコンとアイコン 実装計画

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 青い角丸の四角に白い線の「Re」のファビコンを付け、タイトルなしのブックマークでも見分けられるようにする。同じ形の SVG を、いずれ大きいアイコンにも使える元の絵として置く。

**Architecture:**
- アイコンは静的なファイル 3 つ（`public/assets/` の `favicon.svg`、`favicon-32.png`、`apple-touch-icon.png`）
- `templates/layout.php` の `<head>` に `<link>` を 3 行足す
- PNG は SVG から一度だけ作ってリポジトリに入れる。作り直す道具は zip に入らない `build-files/` に置く
- PHP のコードと DB は変えない

**Tech Stack:** 計画 24 と同じ。アプリと zip の依存は増やさない。PNG を作るときだけ、Node と `@resvg/resvg-js@2.6.2` を一時ディレクトリに取る。

**Spec:** `docs/superpowers/specs/2026-11-23-re-buji-favicon-design.md`

**前の計画：** `docs/superpowers/plans/2026-11-23-re-buji-v0.3.5.md`（計画 24、PR #29 でマージ済み、v0.3.5 はリリース済み）

## Global Constraints

- 計画 1〜24 の Global Constraints をすべて引き継ぐ。主なもの：
  - PHP `^8.3`、`setUp` / `tearDown` を使わない、コミットメッセージの末尾に `Co-Authored-By`、Psalm errorLevel 1、Pint
  - `src/php-version.php`、`public/index.php`、`bin/cron.php` は触らない
- DB の形は変えない
- 形は作者がブラウザで選んだ C1 のまま。座標・色・線の太さは Task 1 の `favicon.svg` のとおり（設計書：角丸 7、R の線 3.2、e の線 2.9、地 `#2b6cb0`、線 `#fff`）
- 文字はフォントを使わず `path` で描く（SVG に `<text>` を入れない）
- アイコンの URL は `$this->url()` で作り、`app.css` と同じく `?v=` に版を付ける
- `<link>` の順は、PNG（`sizes="32x32"`）、SVG、`apple-touch-icon`
- 版は `0.3.6`（Task 2）

## Review Focus

- `/re-buji/` のようなフォルダに置いた構成 → 3 つの URL すべてに基準のパスが付く（Task 2 `linksTheIconsUnderASubdirectory`）
- 初期設定のページとエラーのページ（ログイン前、ナビの無いページ）→ 同じレイアウトなので、アイコンが出る（Task 2 は初期設定のページで確かめる）
- PNG を作り直しても同じファイルになる（zip の sha256 が版ごとに再現できるため）→ Task 1 の Step 5 で 2 回作って比べる
- `public/` の中身だけを公開ディレクトリに置く構成 → `bin/upgrade.sh --public` は `assets/` を丸ごと写すので、新しい 3 つも入る。FTP で上げる人には、リリースノートで知らせる（リリースのとき）
- 古い PHP の案内のページ（`src/php-version.php`）→ レイアウトを通らないので、アイコンは出ない。PHP 8.2 以前でだけ出るページなので、このまま（テストは足さない）

---

### Task 1：アイコンのファイルを作る

**Files:**
- Create: `public/assets/favicon.svg`
- Create: `build-files/apple-touch-icon.svg`（`apple-touch-icon.png` の元。zip には入らない）
- Create: `build-files/icons.sh`、`build-files/icons.cjs`（PNG を作り直す道具）
- Create: `public/assets/favicon-32.png`、`public/assets/apple-touch-icon.png`（道具で作る）
- Test: `tests/Http/LayoutTest.php`

**Interfaces:**
- Produces: `public/assets/` の 3 つのファイル（Task 2 が `<link>` で指す）

- [ ] **Step 1: 失敗するテストを書く**

`LayoutTest`：

```php
#[Test] public function shipsTheIcons(): void
// public/assets/favicon.svg：
//   simplexml で読める。ルートの viewBox が '0 0 32 32'
//   '#2b6cb0' と '<path' を含み、'<text' を含まない
// public/assets/favicon-32.png：getimagesize() が 32×32、IMAGETYPE_PNG
// public/assets/apple-touch-icon.png：getimagesize() が 180×180、IMAGETYPE_PNG
```

- [ ] **Step 2: 失敗を確かめる**

Run: `vendor/bin/phpunit --filter shipsTheIcons`
Expected: FAIL（ファイルが無い）

- [ ] **Step 3: SVG を 2 つ書く**

`public/assets/favicon.svg`（作者が選んだ形。このとおりに書く）：

```svg
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32">
<rect width="32" height="32" rx="7" fill="#2b6cb0"/>
<g fill="none" stroke="#fff" stroke-linejoin="round">
<path d="M7 23.5V9.6h4.6a3.6 3.6 0 0 1 0 7.2H7" stroke-width="3.2"/>
<path d="M11.4 16.8l4.4 6.7" stroke-width="3.2"/>
<path d="M18.6 18.4h7.6a3.95 3.95 0 1 0-1.25 3.2" stroke-width="2.9" stroke-linecap="round"/>
</g>
</svg>
```

`build-files/apple-touch-icon.svg` は、同じ絵を次のように変える。
- 地の `rect` は角を丸めない（`rx` を付けない）。iPhone が丸める
- 線の `g` に `transform="translate(16 16) scale(.8) translate(-16 -16)"` を付け、文字を内側に寄せる

- [ ] **Step 4: PNG を作る道具を書いて、PNG を作る**

- `build-files/icons.cjs`
  - `@resvg/resvg-js` の `Resvg` で、次の 2 つを作る
    - `public/assets/favicon.svg` → `public/assets/favicon-32.png`（幅 32）
    - `build-files/apple-touch-icon.svg` → `public/assets/apple-touch-icon.png`（幅 180）
  - 大きさは `fitTo: { mode: 'width', value: N }` で決める
  - リポジトリの根は引数で受け取る
- `build-files/icons.sh`（`sh` で動く。`set -eu`）
  - 一時ディレクトリを作り、終わったら消す（`trap`）
  - `npm install --prefix "$tmp" --no-save --no-audit --no-fund @resvg/resvg-js@2.6.2`
  - `NODE_PATH="$tmp/node_modules" node build-files/icons.cjs "$root"`
  - 頭のコメントには、何をするか、Node と npm が要ること、版を固定していることを書く

Run: `sh build-files/icons.sh`

- [ ] **Step 5: 作った PNG を確かめる**

- Read で 2 つの PNG を開き、目で見る：青い地に白い「Re」。`favicon-32.png` の角は透明、`apple-touch-icon.png` の角は青
- もう一度作って、同じファイルになることを確かめる

Run: `sha256sum public/assets/*.png > /tmp/a && sh build-files/icons.sh && sha256sum public/assets/*.png | diff /tmp/a - && echo same`
Expected: `same`

- [ ] **Step 6: 通ることを確かめる**

Run: `vendor/bin/phpunit tests/Http/LayoutTest.php && composer test`
Expected: OK

- [ ] **Step 7: コミット**

```bash
git add public/assets/favicon.svg public/assets/favicon-32.png public/assets/apple-touch-icon.png build-files/apple-touch-icon.svg build-files/icons.sh build-files/icons.cjs tests/Http/LayoutTest.php
git commit -m "feat: add the Re icon as an SVG, a 32px PNG and an Apple touch icon"
```

---

### Task 2：レイアウトでアイコンを指す（と、版を 0.3.6 にする）

**Files:**
- Modify: `templates/layout.php`（`<head>`、`app.css` の `<link>` の前）
- Modify: `src/Version.php`、`.env.example`、`compose.yaml`（2 か所）、`README.md`（「開発」の 3 行）
- Test: `tests/Http/LayoutTest.php`

**Interfaces:**
- Consumes: Task 1 の 3 つのファイル
- Produces: `<head>` の 3 行（`V` は `Version::CURRENT`、`/BASE` は基準のパス。ドメインの直下なら空）

```html
<link rel="icon" href="/BASE/assets/favicon-32.png?v=V" sizes="32x32" type="image/png">
<link rel="icon" href="/BASE/assets/favicon.svg?v=V" type="image/svg+xml">
<link rel="apple-touch-icon" href="/BASE/assets/apple-touch-icon.png?v=V">
```

- [ ] **Step 1: 失敗するテストを書く**

`LayoutTest`：

```php
#[Test] public function linksTheIconsWithTheVersion(): void
// /install/environment の本文に、上の 3 行がそのまま（BASE は空、V は Version::CURRENT）ある
// PNG の行が SVG の行より前にある（strpos で比べる）

#[Test] public function linksTheIconsUnderASubdirectory(): void
// Url('/re-buji') で、'href="/re-buji/assets/favicon-32.png?v='、'href="/re-buji/assets/favicon.svg?v='、
// 'href="/re-buji/assets/apple-touch-icon.png?v=' がある
```

作り方は、同じファイルの `linksTheStylesheetUnderTheBasePathWithTheVersion` と `linksTheStylesheetUnderASubdirectory` に合わせる。

- [ ] **Step 2: 失敗を確かめる**

Run: `vendor/bin/phpunit --filter 'linksTheIconsWithTheVersion|linksTheIconsUnderASubdirectory'`
Expected: FAIL（`<link rel="icon"` が無い）

- [ ] **Step 3: `layout.php` に 3 行足す**

`app.css` の行と同じく、`$this->e($this->url('/assets/…'))` と `?v=<?= $this->e(ReBuji\Version::CURRENT) ?>` で書く。

- [ ] **Step 4: 版を 0.3.6 にする**

- `src/Version.php` の `CURRENT` を `'0.3.6'`
- `.env.example` の `REBUJI_VERSION=0.3.6`
- `compose.yaml` の 2 か所の `${REBUJI_VERSION:-0.3.6}`
- `README.md` の「開発」の 3 行の `0.3.5` を `0.3.6`

- [ ] **Step 5: 確かめる**

Run: `grep -rn "0\.3\.5" src/Version.php .env.example compose.yaml README.md; composer test && composer analyse && composer lint`
Expected: `grep` は何も出さない。テスト、Psalm、Pint は OK

- [ ] **Step 6: コミット**

```bash
git add templates/layout.php tests/Http/LayoutTest.php src/Version.php .env.example compose.yaml README.md
git commit -m "feat: point every page at the Re icon, and bump to 0.3.6"
```

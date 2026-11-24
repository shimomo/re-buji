# Re:無事 計画 30：ログインのハッシュと送信の上限の整理 実装計画

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** v0.3.9 で直したログインのハッシュのまわりを確かめられる形にし、送信の上限のクラスの説明と使われないメソッドを直す。5 件は作者が選んだ。

**Architecture:**
- ログインのハッシュの扱い（仮のハッシュの選び方、作り直すかどうか）を、`LoginController` から新しいクラス `LoginHashes` に移す。今の PHP の既定の強さを外から渡せるので、PHP 8.4 以降の手元でも、8.3 に戻したときの選び方をテストできる
- `RateLimit` のクラスの docblock を直し、`reserve()` を private にし、`nextSlotAt()` を消す
- 利用者から見た動きは変えない。DB の形も変えない

**Tech Stack:** 計画 29 と同じ。依存は増やさない。

**Spec:** `docs/superpowers/specs/2026-11-05-re-buji-design.md`（3.2 管理者、5.3 送信の上限と順番）。5 件の根拠は、出どころの PR の本文（#6、#14、#34）の「後回しにした minor」

**前の計画：** `docs/superpowers/plans/2026-11-24-re-buji-v0.3.9.md`（計画 29、PR #34 でマージ済み、v0.3.9 はリリース済み）。直すのは次の 5 件。作者が 2026-11-24 に選んだ。

| 直すこと | 出どころ | タスク |
|---|---|---|
| `dummyCost()` 自体はテストしたが、`dummyHash()` がそれを管理者のハッシュで使っていることを確かめるテストが無い | 計画 29 の 3 | Task 1 |
| `weakerThanDefault()` は、強さの比べ方を bcrypt 以外のアルゴリズムにも使う。将来 PHP の既定が argon2 になると、ログインのたびに作り直して書き込む | 計画 29 の 2 | Task 1 |
| `tests/Auth/LoginTest.php` の `setTheHashCost()` の上に、docblock が 2 つ重なっている。古いほうの文も今は正しくない | 計画 29 の 1 | Task 1 |
| `RateLimit` のクラスのコメントが「管理者 1 人に 1 通」のままで、今の「上限の 1 割まで」と合わない。`reserve()` は外から呼ばれていないのに public | 計画 5 の 4 | Task 2 |
| `RateLimit::nextSlotAt()` は、テストでしか使われていない | 計画 12 の 7 | Task 2 |

## Global Constraints

- 計画 1〜29 の Global Constraints をすべて引き継ぐ。主なもの：
  - PHP `^8.3`、`setUp` / `tearDown` を使わない、コミットメッセージの末尾に `Co-Authored-By`、Psalm errorLevel 1、Pint
  - `src/php-version.php`、`public/index.php`、`bin/cron.php` は触らない
- DB の形は変えない（マイグレーションを足さない）
- docblock とコメントの文言は、各タスクに書いたとおりにする
- **[判断]** ハッシュの扱いを `LoginHashes` に移す。仮のハッシュの選び方は、今の PHP の既定の強さで変わるが、手元と CI の PHP 8.4・8.5 では既定が 12 で、仮のハッシュのいちばん強いものと同じになり、管理者のハッシュを読んでいるかどうかを見分けられないため。外れていたら、クラスが 1 つ増えただけになる
- **[判断]** 計画 29 の 2 は、既定が bcrypt でなくなったときの分かれ道を作らず、「PHP の既定は bcrypt」という前提をテストで確かめる（前提が崩れたら、テストが落ちて、直す所を指す）。PHP 8.3〜8.5 の既定は bcrypt なので、分かれ道を作ってもテストで通せないため。外れていたら、PHP の既定が変わった版で、テストが落ちるまで気づかない（落ちれば気づく）
- **[判断]** 版は上げない（`0.3.9` のまま）。利用者から見た動きが変わらないため。外れていたら、zip の中身が次の版まで古いまま（動きは同じ）

## Review Focus

- 管理者がまだいない（初期設定の途中）→ 仮のハッシュは、今の既定の強さのもの（Task 1 `LoginHashesTest::fallsBackToTheDefaultCost` の 1 つめ）
- 管理者のハッシュの強さに合う仮のハッシュが無い（強さ 13 など）→ 今の既定の強さのもの（同じテストの 2 つめ）
- bcrypt でない、または壊れたハッシュ → 仮のハッシュの選び方では数えない。作り直しは、今までどおり必ずする（Task 1 `LoginHashesTest::rehashesOnlyAWeakerHash`）
- PHP の既定のアルゴリズム → bcrypt であることをテストで確かめる（Task 1 `LoginHashesTest::assumesBcryptIsThePhpDefault`）
- 送信の上限の計算 → 変わらない。既存の `RateLimitTest::keepsRoomForTheAdminsNotices`、`keepsAtMostATenthOfTheLimitBack` が押さえる

---

### Task 1：ログインのハッシュの扱いを `LoginHashes` に移し、確かめる

**Files:**
- Create: `src/Auth/LoginHashes.php`
- Modify: `src/Auth/LoginController.php`（`LoginHashes` を受け取って使う。`DUMMY_HASHES`、`dummyCost()`、`dummyHash()`、`defaultCost()`、`weakerThanDefault()` を消す）
- Create: `tests/Auth/LoginHashesTest.php`
- Modify: `tests/Auth/LoginTest.php`（`timesAnUnknownEmailLikeTheStrongestAdminsHash` を消す。`setTheHashCost()` の上の古い docblock を消す）

**Interfaces:**
- Produces: `final class LoginHashes`
  - `__construct(AdminRepository $admins, ?int $defaultCost = null)`：null なら `PASSWORD_BCRYPT_DEFAULT_COST`（定義が無ければ 10）
  - `dummy(): string`：知らないメールアドレスと比べる仮のハッシュ。管理者の bcrypt のハッシュのうち、仮のハッシュがある強さの、いちばん強いもの。それが今の既定より弱いか、無ければ、今の既定の強さのもの（それも無ければ強さ 10 のもの）
  - `weakerThanDefault(string $hash): bool`：bcrypt でない（アルゴリズムが `PASSWORD_DEFAULT` と違う）か、強さが今の既定より低いとき true

- [ ] **Step 1: 失敗するテストを書く**

`tests/Auth/LoginHashesTest.php`（新しく作る）。`Database::sqliteInMemory()` に migrate し、`AdminRepository` で管理者を作ってから、`UPDATE admins SET password_hash = ?` で決まったハッシュに置き換える。強さ 10 と 12 のハッシュは、`LoginController` の今の `DUMMY_HASHES` の 2 つの値を使う（作り直すより速い）。

```php
#[Test] public function comparesAnUnknownEmailWithTheStrongestAdminsCost(): void
// 管理者 2 人：強さ 10 と 12 のハッシュ
// (new LoginHashes($admins, 10))->dummy() は '$2y$12$' で始まる（PHP を 8.4 から 8.3 に戻したサーバー）

#[Test] public function fallsBackToTheDefaultCost(): void
// 1. 管理者なし：(new LoginHashes($admins, 10))->dummy() は '$2y$10$' で始まる
// 2. 管理者 1 人、強さ 13 のハッシュ（password_hash(..., ['cost' => 13]) を 1 回だけ作る）：
//    (new LoginHashes($admins, 12))->dummy() は '$2y$12$' で始まる
// 3. 管理者 1 人、強さ 10：(new LoginHashes($admins, 12))->dummy() は '$2y$12$' で始まる

#[Test] public function rehashesOnlyAWeakerHash(): void
// $hashes = new LoginHashes($admins, 12)
// 強さ 10 → true、強さ 12 → false、強さ 13 → false
// '$2a$12$' で始まるハッシュ → true、'not a hash' → true

#[Test] public function assumesBcryptIsThePhpDefault(): void
// assertSame(PASSWORD_BCRYPT, PASSWORD_DEFAULT, 'LoginHashes::weakerThanDefault() compares bcrypt costs: revisit it if PHP\'s default algorithm changes')
```

`LoginTest::timesAnUnknownEmailLikeTheStrongestAdminsHash` は、1 つめと 2 つめのテストに置き換わるので消す。

- [ ] **Step 2: 失敗を確かめる**

Run: `vendor/bin/phpunit tests/Auth/LoginHashesTest.php`
Expected: `assumesBcryptIsThePhpDefault` のほかは FAIL（`LoginHashes` が無い）。`assumesBcryptIsThePhpDefault` は今の PHP の前提を押さえるテストなので PASS

- [ ] **Step 3: 作る**

- `LoginHashes`：上の Interfaces のとおり。`DUMMY_HASHES` は、`LoginController` の定数とそのコメントを、文言を変えずに移す（「the one used matches the strongest admin's hash (dummyCost())」は「(dummy())」にする）
- `weakerThanDefault()` の docblock：

```php
    /**
     * Whether the hash is weaker than what password_hash() makes today: another algorithm, or bcrypt with a lower cost.
     * A stronger bcrypt hash stays, so moving the server back to an older PHP does not weaken it. This compares bcrypt
     * costs only, as PHP's default is bcrypt (LoginHashesTest::assumesBcryptIsThePhpDefault()).
     */
```

- `LoginController`：コンストラクタに `private readonly LoginHashes $hashes` を足し、`$this->dummyHash()` を `$this->hashes->dummy()`、`self::weakerThanDefault(...)` を `$this->hashes->weakerThanDefault(...)` にする。消すメソッドと定数は Files のとおり。ログインのところのコメント（「The password is at hand only now: …」）は残す
- `tests/Auth/LoginTest.php`：`setTheHashCost()` の上の「Stores the admin's password with a cost below any default, …」の docblock を消す（「Gives the admin a bcrypt hash of this cost. …」だけを残す）

- [ ] **Step 4: 通ることを確かめる**

Run: `vendor/bin/phpunit tests/Auth && composer analyse && for f in $(grep -rl '' src tests --include=*.php); do n=$(grep -n -A1 '^     \*/$' $f | grep -c -- '-    /\*\*$'); [ "$n" != 0 ] && echo "$f $n"; done; true`
Expected: テストと Psalm は OK。docblock の重なりの確かめは、`src/` と `tests/` のどちらでも何も出さない

- [ ] **Step 5: コミット**

```bash
git add src/Auth/LoginHashes.php src/Auth/LoginController.php tests/Auth/LoginHashesTest.php tests/Auth/LoginTest.php
git commit -m "refactor: move the login's hash handling into LoginHashes, and test the dummy against the admins' hashes"
```

---

### Task 2：`RateLimit` の説明を直し、使われないところを閉じる

**Files:**
- Modify: `src/Mail/RateLimit.php`（クラスの docblock、`reserve()` を private に、`nextSlotAt()` を消す）
- Modify: `tests/Mail/RateLimitTest.php`（`nextSlotAt($now)` を `nextSlotFor($now, 1)` に）

動きは変わらないので、テストは足さない。既存のテストが通り続けることで確かめる。

- [ ] **Step 1: クラスの docblock を直す**

「One mail per admin is kept back from contacts and test mails: …」の段落を、次にする。

```php
 * Mails are kept back from contacts and test mails for the admins' notices: one per admin, but no more than a tenth of
 * the limit (reserve()). The notice that contacts are held back by the limit (design 6.2) could otherwise never go
 * out, since it is needed exactly when the limit is used up.
```

- [ ] **Step 2: `reserve()` を private にし、`nextSlotAt()` を消す**

- `reserve()` を `private function reserve(): int` にする（docblock はそのまま）
- `nextSlotAt()` を docblock ごと消す
- `RateLimitTest` の 3 か所の `$limit->nextSlotAt($now)` を `$limit->nextSlotFor($now, 1)` にする（`nextSlotAt()` は `nextSlotFor($now, 1)` を返していたので、確かめる値は同じ）

- [ ] **Step 3: 確かめる**

Run: `grep -rn 'nextSlotAt\|->reserve()' src tests templates; composer test && composer analyse && composer lint`
Expected: `grep` は `src/Mail/RateLimit.php` の `$this->reserve()` の 1 行だけ。テスト、Psalm、Pint は OK

- [ ] **Step 4: コミット**

```bash
git add src/Mail/RateLimit.php tests/Mail/RateLimitTest.php
git commit -m "chore: say how RateLimit keeps mails back for the admins, and close what only it uses"
```

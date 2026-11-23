# Re:無事（Re:Buji）

地震のあと、従業員に届いたメールに**返信するだけ**で無事を確かめられる、レンタルサーバーに置ける安否確認システムです。BCP（事業継続計画）のために安否確認のツールを入れたいが費用をかけられない組織が、自分のレンタルサーバーに設置して使うことを目指しています。

気象庁防災情報XML（[jmaxml](https://github.com/shimomo/jmaxml-php)）を使います。**非公式**で、気象庁とは関係ありません。

> [!NOTE]
> v0.1.0 が最初の版です。さくらのレンタルサーバで、設置から訓練の連絡への返信までを確かめました。ロリポップなど、ほかのサーバーではまだ試していません。v0.3.0 から、Docker でも動かせます（技術者向け。[docs/docker.md](docs/docker.md)）。設計は [docs/superpowers/specs/2026-11-05-re-buji-design.md](https://github.com/shimomo/re-buji/blob/main/docs/superpowers/specs/2026-11-05-re-buji-design.md) にあります。

## できること

- 登録した市町村のどれかが震度 5 弱（設定で変えられます）以上になった人に、1 つの地震につき 1 通、メールを送ります（[地震の連絡](docs/usage.md#地震の連絡)）
- 従業員は、届いたメールに返信するだけです。何も書かずに返信すれば「無事」、何か書けば「要確認」になります（[返信と責任者の画面](docs/usage.md#返信と責任者の画面)）
- 返信の無い人には、決まった回数・間隔で送り直します。既定は 3 回・60 分ごとです（[地震の連絡](docs/usage.md#地震の連絡)）
- 責任者の画面で、返信なし・要確認・無事の人を一覧できます。電話などで確かめた人は、代理で記録できます（[返信と責任者の画面](docs/usage.md#返信と責任者の画面)）
- 従業員は、Excel で作った CSV でまとめて登録できます（[CSV](docs/usage.md#csv)）
- 訓練の連絡を送れます。送り方や再送は本番と同じです（[訓練](docs/usage.md#訓練)）
- cron が止まったことは、無料の監視サービスで気づけます。返信を読めない、気象庁の情報に抜けがあるなどの問題は、管理者全員にメールで知らせます（[見張り](docs/install.md#見張り)、[ホーム画面](docs/usage.md#ホーム画面)）

## 必要なもの

- PHP 8.3 以上（PDO と、pdo_sqlite か pdo_mysql）
- cron（5 分ごと以上。SSH が無くても、管理画面で PHP ファイルを指定できれば足ります）
- MySQL は無くても動きます（SQLite を使います）
- HTTPS（レンタルサーバーの無料 SSL で足ります）

## はじめる

### Docker で動かす（技術者向け、おすすめ）

手元で試すなら、次の 4 行で立ち上がります。メールは同梱のテスト用メールサーバーに留まり、外へは出ません。

```bash
git clone https://github.com/shimomo/re-buji.git && cd re-buji
docker compose -f compose.yaml -f compose.dev.yaml up -d --build
docker compose -f compose.yaml -f compose.dev.yaml exec app php bin/console admin:create
# http://localhost:8080 が Re:無事、http://localhost:8081 がウェブメール
```

VPS で HTTPS 付きの本番として動かす手順、更新、バックアップは [docs/docker.md](docs/docker.md) にあります。

### レンタルサーバーに置く

[リリース](https://github.com/shimomo/re-buji/releases)から zip を取り、FTP でアップロードして、ブラウザで初期設定を進めます。置き方は、全体を公開ディレクトリに置く方法と、本体を公開ディレクトリの外に置いて `public/` の中身だけを公開する方法の 2 通りです。手順は [docs/install.md](docs/install.md) にあります。

## 文書

| 文書 | 読むとき |
|---|---|
| [docs/install.md](docs/install.md) | レンタルサーバーに置くとき（置き方、初期設定、cron、見張り） |
| [docs/usage.md](docs/usage.md) | 使っているとき（ホーム画面、従業員、メール、地震の連絡、返信と責任者の画面、訓練、記録の削除） |
| [docs/upgrade.md](docs/upgrade.md) | 新しい版に上げるとき（バージョンアップ。SSH、FTP） |
| [docs/docker.md](docs/docker.md) | Docker で動かすとき（手元、VPS） |

## 開発

```bash
composer install
composer test       # PHPUnit
composer analyse    # Psalm
composer lint       # Pint
bin/build-release.sh 0.3.8   # build/re-buji-0.3.8.zip を作る（版は src/Version.php と同じにし、コミットしてから）
bin/release.sh check 0.3.8   # main で、リリースできるかを確かめて zip を作る
bin/release.sh publish 0.3.8 notes.md   # 署名つきのタグと GitHub のリリースを作る
```

## 出典と注意

出典：[気象庁ホームページ「気象庁防災情報XMLフォーマット」](https://xml.kishou.go.jp/)

- Re:無事 と jmaxml は気象庁の公式のソフトウェアではありません。内容の正確さや速さは保証しません。
- 気象庁の公開のフィードには、リアルタイムの保証がなく、予告なく止まることがあります。
- 実際の防災の行動は、気象庁や自治体などの公式の発表に従ってください。

## ライセンス

MIT

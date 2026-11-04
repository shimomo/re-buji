# Re:無事（Re:Buji）

地震のあと、従業員に届いたメールに**返信するだけ**で無事を確かめられる、レンタルサーバーに置ける安否確認システムです。BCP（事業継続計画）のために安否確認のツールを入れたいが費用をかけられない組織が、自分のレンタルサーバーに設置して使うことを目指しています。

気象庁防災情報XML（[jmaxml](https://github.com/shimomo/jmaxml-php)）を使います。**非公式**で、気象庁とは関係ありません。

> [!NOTE]
> 開発中です。今の版でできるのは、設置、初期設定、管理者のログイン、cron と見張りの仕組みまでです。地震の検知、メールの送信、返信の記録は、これから足していきます。設計は [docs/superpowers/specs/2026-11-05-re-buji-design.md](docs/superpowers/specs/2026-11-05-re-buji-design.md) にあります。

## 必要なもの

- PHP 8.3 以上（PDO と、pdo_sqlite か pdo_mysql）
- cron（5 分ごと以上。SSH が無くても、管理画面で PHP ファイルを指定できれば足ります）
- MySQL は無くても動きます（SQLite を使います）
- HTTPS（レンタルサーバーの無料 SSL で足ります）

## 設置

1. [リリース](https://github.com/shimomo/re-buji/releases)から `re-buji-<版>.zip` を取り、展開します。
2. 中の `re-buji/` を FTP でアップロードします。置き方は 2 通りです。
   - **全体を公開ディレクトリに置く**（たとえば `public_html/re-buji/`）。ロリポップのように、公開ディレクトリの外にファイルを置けないサーバー向けです。同梱の `.htaccess` が `public/` の外へのアクセスを拒否します。
   - **本体を公開ディレクトリの外に置き、`public/` の中身だけを公開ディレクトリに置く**。そのときは、公開ディレクトリに置いた `index.php` の隣に、本体の場所を返す `rebuji-root.php` を作ります。

     ```php
     <?php return '/home/ユーザー名/re-buji';
     ```
3. `storage/` に書き込めるようにします（権限 705 か 755）。
4. ブラウザで `https://あなたのドメイン/re-buji/` を開き、初期設定を進めます。

### 初期設定

| 段階 | 内容 |
|---|---|
| 環境の確認 | PHP の版、拡張、`storage/` の書き込みを確かめます |
| 設置の鍵 | 画面を開くと `storage/install-key.txt` ができます。FTP で開き、中の文字列を貼ります。FTP に入れる人だけが設置できるようにするためです |
| データベース | SQLite か MySQL を選びます |
| 最初の管理者 | 名前、メールアドレス、パスワード（12 文字以上） |
| 公開されていないことの確認 | データベースや設定ファイルがウェブから読めないことを、アプリ自身が確かめます。読めてしまうと先に進めません |
| 仕上げ | cron に登録するファイルと、見張りの URL を控えて完了します |

## cron

`bin/cron.php` を 5 分ごと（できれば 1 分ごと）に動かします。

- **ロリポップ**：管理画面の cron 設定で、プログラムに `re-buji/bin/cron.php` を指定します（エコノミー・ライトは 5 分ごと、スタンダード以上は 1 分ごとにできます）。
- **SSH が使えるサーバー**：`crontab -e` で登録します。

  ```
  */5 * * * * php /home/ユーザー名/re-buji/bin/cron.php
  ```

## 見張り

cron が止まると、地震があっても連絡が送られません。ホーム画面に出る「状態の URL」を、[UptimeRobot](https://uptimerobot.com/) などの無料の監視サービスに登録してください。cron が 15 分以上動いていないと、この URL は 503 を返すので、監視サービスからメールが届きます。URL は推測できない文字列を含み、ホーム画面で作り直せます。

## バージョンアップ

新しい zip の中身を FTP で上書きします（`storage/` は上書きしません）。そのあと、管理画面の「DB の更新」を開いて、ボタンを押します。

## 開発

```bash
composer install
composer test       # PHPUnit
composer analyse    # Psalm
composer lint       # Pint
bin/build-release.sh 0.1.0   # build/re-buji-0.1.0.zip を作る
```

## ライセンス

MIT

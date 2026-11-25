# Re:無事 を新しい版に上げる

[README に戻る](../README.md)

## SSH で上げる（0.3.1 から）

SSH で入れるサーバーでは、次の 1 行で上げられます。GitHub で最新の版を探し、zip を取って sha256 を確かめ、上書きと DB の更新までを済ませます。

```bash
sh ~/re-buji/bin/upgrade.sh
```

- 上げる版と DB の更新の有無を示し、「上げますか？」と聞いてから進みます。`--yes` を付けると聞きません
- 上書きの前に、今の中身（`storage/` を除く）と `database.sqlite` を `storage/upgrade-backups/` に写します。戻し方は、終わったときに出ます。上げ終わると、前に上げたときの写しは消し、今回の写しだけを残します。問題なく動いていれば、今回の写しも消して構いません。MySQL の DB は写さないので、先に `mysqldump` で取ってください
- 上げているあいだ cron は止まります（動いている途中なら、終わるのを待ってから始めます）
- 「上げますか？」に答えたあとは、SSH が切れても Ctrl-C を押しても、最後まで進みます（途中で止まって、古いファイルと新しいファイルが混ざらないように）。やめるときは、問いに `n` と答えてください
- `install.sh` で入れたものは、公開ディレクトリを覚えているので、`--public` は要りません
- `public/` の中身だけを公開ディレクトリに置いている場合は、初回だけ `--public` で公開ディレクトリを渡します。`index.php` と `assets/` を置き換えます（`.htaccess` と `rebuji-root.php` はそのまま）。2 回目からは覚えています

  ```bash
  sh ~/re-buji/bin/upgrade.sh --public ~/public_html/anpi
  ```
- `php` が 8.3 より古いサーバーでは、cron と同じように版を指定した php を渡します：`env PHP=/usr/local/php/8.3/bin/php sh ~/re-buji/bin/upgrade.sh`
- 特定の版を入れるときは `--version 0.3.1`、サーバーに置いた zip から入れるときは `--zip re-buji-0.3.1.zip` を付けます

**0.3.0 までから上げるとき**は、まだ `bin/upgrade.sh` が無いので、初回だけスクリプトを取ってきて、設置した場所を `--root` で渡します。

```bash
curl -fsSL -o ~/rebuji-upgrade.sh https://raw.githubusercontent.com/shimomo/re-buji/v0.3.1/bin/upgrade.sh
sh ~/rebuji-upgrade.sh --root ~/re-buji --public ~/public_html/anpi   # 全体を公開ディレクトリに置いているなら --public は付けない
rm ~/rebuji-upgrade.sh
```

## FTP で上げる

新しい zip の中身を FTP で上書きします（`storage/` は上書きしません）。そのあと、管理画面の「DB の更新」を開いて、ボタンを押します。

`public/` の中身だけを公開ディレクトリに置いている場合は、本体を上書きした後で、公開ディレクトリも新しい `public/` の中身で上書きしてください。`assets/` のフォルダを丸ごと先に、`index.php` を最後に上げます（`rebuji-root.php` はそのまま残します）。`assets/` には、画面の見た目の `app.css` と、ブラウザのタブやブックマーク、画面の上に出るアイコンが入っています。`assets/` を上げないと、画面の見た目がそろわず、アイコンも出ません。`index.php` を本体より先に上げると、本体を上げ終わるまで画面がエラーになります。

上書きしてから「DB の更新」を押すまでは、管理画面は「DB の更新」の画面しか開けず、cron も地震の情報を取りに行きません（15 分たつと、状態の URL が 503 を返します）。上書きしたら、すぐに押してください。DB の変更が無い版では、押す必要はありません。

0.2.0 までに設置した場合、`storage/database.sqlite` とログ（`storage/logs/re-buji.log`）は、同じサーバーのほかの利用者にも読める権限（644 など）で作られています。0.2.1 からは新しく作るときに自分だけが読める 600 にしますが、今あるファイルの権限は変えません。web と cron が同じユーザーで動くサーバー（さくらのレンタルサーバなど）なら、`chmod 600 storage/database.sqlite storage/logs/re-buji.log` で直してください。

Docker で動かしている場合は、[Docker で動かす](docker.md#更新) を見てください。

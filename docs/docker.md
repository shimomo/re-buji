# Re:無事 を Docker で動かす

手元で試す方法と、VPS で本番として動かす方法です。設計は [docs/superpowers/specs/2026-11-22-re-buji-docker-design.md](https://github.com/shimomo/re-buji/blob/main/docs/superpowers/specs/2026-11-22-re-buji-docker-design.md) にあります。

**手元で試す構成（`compose.dev.yaml`）では、メールは外へ出ません。** 送ったメールはすべて、同梱のテスト用メールサーバー（GreenMail）の中のメールボックスに入るだけです。宛先が `@gmail.com` でも同じです。本物の人へ訓練の連絡が届くことはありません。

## 手元で試す

Docker と Docker Compose（v2.24 以降）が要ります。`.env` は要りません。

```bash
git clone https://github.com/shimomo/re-buji.git
cd re-buji
docker compose -f compose.yaml -f compose.dev.yaml up -d --build
```

- `http://localhost:8080` が Re:無事、`http://localhost:8081` がウェブメール（Roundcube）です。どちらも、このパソコンの中（`127.0.0.1`）からだけ開けます（ウェブメールはパスワードを確かめないので、同じネットワークの人に見せないため）。
- 最初は、イメージを作るので数分かかります。`docker compose -f compose.yaml -f compose.dev.yaml ps` で `app` が `healthy` になれば使えます。
- メールの接続は TLS のままです。手元用に作った CA（ボリューム `dev_certs`）を `app` と `cron` が追加で信頼するので、証明書の確認は切っていません。

### 1. 管理者を作る

```bash
docker compose -f compose.yaml -f compose.dev.yaml exec app php bin/console admin:create
```

名前とメールアドレス（たとえば `admin@example.test`）を聞かれ、パスワード（12 文字以上）を 2 回入れます。

### 2. ログインして、従業員を取り込む

1. `http://localhost:8080` を開き、作った管理者でログインします。
2. 「従業員」の「CSV を取り込む」で、`docker/dev/employees.csv` を選びます。山田太郎（`taro@example.test`）と佐藤花子（`hanako@example.test`）の 2 人が入ります。

### 3. 訓練の連絡を送る

「訓練」で「全員」を選び、「訓練の連絡を送る」を押します。`cron` が 1 分ごとに動くので、1 分ほどで送られます。

### 4. 従業員として返信する

1. `http://localhost:8081`（ウェブメール）を開きます。
2. ユーザー名に `taro@example.test`、パスワードに何か（空でなければ何でも）を入れて入ります。
3. 受信箱に届いた「[安否確認 #…] 【訓練】地震がありました（訓練）」に、本文を空のまま返信します。

### 5. 無事を確かめる

1 分ほど待つと、`cron` が返信を読みます。`http://localhost:8080` のホームの訓練に、山田太郎が「無事」と出ます。花子としても返信すれば、2 人とも「無事」になります。

### 片づけ

```bash
docker compose -f compose.yaml -f compose.dev.yaml down -v
```

`-v` で、データベースと手元用の証明書も消えます。

### 手元の構成を通しで確かめる

`docker/dev/e2e.sh` は、起動 → 管理者の作成 → テストメールを送る → 返信する → `bin/console check --wait-reply` が読めるのを確かめる、までを自動で流し、最後に `down -v` します（CI でも使っています）。

```bash
docker/dev/e2e.sh
```

ウェブメールを使わずに従業員の返信を送るには、`docker/dev/reply.php` を使います。テスト用メールサーバーにはコンテナの中からしか届かないので、`app` のイメージで流します。

```bash
docker compose -f compose.yaml -f compose.dev.yaml run --rm --no-deps -T \
    -v "$PWD/docker/dev/reply.php:/reply.php:ro" \
    app php /reply.php <送り主> <宛先> <Message-ID> [件名]
```

## VPS で本番として動かす

### 用意するもの

- Docker と Docker Compose が入った VPS。80 番と 443 番を開けておきます。
- ドメイン。A / AAAA レコードをこの VPS に向けます。
- 安否確認の専用のメールアカウント（SMTP で送り、IMAP で返信を読みます）。送信元のアドレスとこのメールボックスは Re:無事 の専用にしてください。

### 設置

```bash
git clone https://github.com/shimomo/re-buji.git
cd re-buji
cp .env.example .env    # REBUJI_DOMAIN とメール（SMTP・IMAP）の設定を書く
docker compose up -d
docker compose exec app php bin/console admin:create
docker compose exec app php bin/console check --wait-reply
```

1. `.env` を書きます。各項目の説明は `.env.example` にあります。`.env` には秘密が入るので、Git に入れません。パスワードは `REBUJI_SMTP_PASSWORD_FILE` のように、ファイルから読ませることもできます。
2. `docker compose up -d` で、Let's Encrypt の証明書を自動で取り、更新します。
3. `admin:create` で最初の管理者を作ります。パスワードは対話で入れます（`.env` や引数には書きません）。
4. `check --wait-reply` は、テストメールを送り、その返信を待ちます。メールが受け取れたら、**返信してください**。「✓ 返信を読めた」と出れば、送信も受信も通っています。
5. `docker compose exec app php bin/console status-url https://<ドメイン>`（`<ドメイン>` は `REBUJI_DOMAIN` に書いたもの）が出す URL を、外部の監視サービスに登録します。cron が止まると 503 を返します。

#### IPv6 で来る接続について

`compose.proxy.yaml` を重ねずに直接 HTTPS で受けるとき、Docker のネットワークで IPv6 を有効にしていないと、IPv6 で来た接続は Docker（docker-proxy）が中継し、接続元がどれも Docker のブリッジのゲートウェイの IP に見えます。ログインの回数制限はこの IP で数えるので、IPv6 で来る人どうしが 1 つの枠を分け合います（だれかがパスワードを続けて間違えると、IPv6 で来る全員がしばらくログインできません）。避けるには、Docker で IPv6 を有効にしてください（Docker の文書の「IPv6 networking」）。IPv4 で来る接続は、本当の接続元で数えます。

### nginx などのリバースプロキシの後ろで動かす

HTTPS をプロキシで受けるときは、`compose.proxy.yaml` を重ねます。`app` は HTTPS を持たず、`127.0.0.1:8080` だけで待ち受けます。`.env` の `REBUJI_DOMAIN` は使いません。

```bash
docker compose -f compose.yaml -f compose.proxy.yaml up -d
```

nginx の設定例です。接続元の IP は、クライアントが付けた偽の値を引き継がないよう、`$remote_addr` で上書きします。

```nginx
server {
    listen 443 ssl;
    server_name anpi.example.com;

    # ssl_certificate などは自分の設定に合わせる

    location / {
        proxy_pass http://127.0.0.1:8080;
        proxy_set_header Host $host;
        proxy_set_header X-Forwarded-For $remote_addr;
        proxy_set_header X-Forwarded-Proto https;
    }
}
```

### 更新

`.env` の `REBUJI_VERSION` を新しい版に書き換えてから、次を流します。版は固定してください（`latest` は使いません）。

```bash
docker compose pull
docker compose up -d
```

起動のときに、データベースの更新が自動で流れます。

### バックアップと戻し方

止めずにバックアップを取るには、`backup` を使います。出力先のパスを渡します（まだ無いファイルにしてください）。コンテナの中に作り、手元へ写して、中のものは消します。

```bash
docker compose exec app php bin/console backup /app/storage/backup.sqlite
docker compose cp app:/app/storage/backup.sqlite ./database.sqlite
docker compose exec app rm /app/storage/backup.sqlite
```

できた `database.sqlite` は、VPS の外にも写してください。定期的に取るなら、cron などで上の 3 行を流します。

戻すときは、`app` と `cron` を止め、`storage` ボリュームの `database.sqlite` を、取っておいた写し（カレントディレクトリの `database.sqlite`）で置き換えてから、起動し直します。

```bash
docker compose stop app cron
docker run --rm -v re-buji_storage:/app/storage -v "$PWD":/backup alpine \
    sh -c 'cp /backup/database.sqlite /app/storage/database.sqlite && chown 1000:1000 /app/storage/database.sqlite && chmod 600 /app/storage/database.sqlite'
docker compose up -d
```

ボリューム名（`re-buji_storage`）は、`docker volume ls` で確かめてください（リポジトリのディレクトリ名が前に付きます）。

### MySQL を使うとき

既定は SQLite です（`storage` ボリュームの中）。MySQL を使うときは、MySQL のサーバーを自分で用意し（Compose には入っていません）、`.env` に書きます。

```
REBUJI_DB_DRIVER=mysql
REBUJI_DB_HOST=
REBUJI_DB_PORT=3306
REBUJI_DB_NAME=
REBUJI_DB_USERNAME=
REBUJI_DB_PASSWORD=
```

MySQL のときのバックアップは、`mysqldump` など MySQL 側の方法で取ります。

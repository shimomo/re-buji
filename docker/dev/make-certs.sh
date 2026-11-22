#!/bin/sh
# 手元で試すための CA と、テスト用メールサーバー（mail）の証明書を、ボリューム（/certs）の中に作る。
# 秘密鍵はリポジトリに入れない。ここで実行時に作り、CA の鍵は作り終えたら消す。
#
#   /certs/ca.pem    Re:無事（app と cron）が追加で信頼する CA（REBUJI_MAIL_CA_FILE）
#   /certs/mail.p12  GreenMail の鍵と証明書（パスワード rebuji-dev）

set -eu

cd /certs

if [ -f ca.pem ] && [ -f mail.p12 ]; then
    echo "証明書はすでにあります。"
    exit 0
fi

work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT

openssl req -x509 -newkey rsa:2048 -nodes -days 3650 -subj "/CN=Re:Buji dev CA" \
    -keyout "$work/ca.key" -out "$work/ca.pem" 2>/dev/null

openssl req -newkey rsa:2048 -nodes -subj "/CN=mail" \
    -keyout "$work/mail.key" -out "$work/mail.csr" 2>/dev/null

printf 'subjectAltName=DNS:mail\nbasicConstraints=CA:FALSE\nkeyUsage=digitalSignature,keyEncipherment\nextendedKeyUsage=serverAuth\n' > "$work/ext.cnf"

openssl x509 -req -in "$work/mail.csr" -CA "$work/ca.pem" -CAkey "$work/ca.key" -CAcreateserial \
    -days 3650 -extfile "$work/ext.cnf" -out "$work/mail.pem" 2>/dev/null

openssl pkcs12 -export -inkey "$work/mail.key" -in "$work/mail.pem" -certfile "$work/ca.pem" \
    -name mail -passout pass:rebuji-dev -out "$work/mail.p12"

# app（uid 1000）と GreenMail が読めるようにする。テスト用の鍵で、ボリュームの外には出ない。
install -m 0644 "$work/ca.pem" /certs/ca.pem
install -m 0644 "$work/mail.p12" /certs/mail.p12

echo "証明書を作りました。"

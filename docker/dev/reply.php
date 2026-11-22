<?php

declare(strict_types=1);

// 手元の確認用。テスト用メールサーバーの SMTP（mail:3025。平文だが、手元のコンテナの中だけ）で、
// In-Reply-To と References を付けた空の返信を送る。CI と手での確認に使う。
//
//   php docker/dev/reply.php <送り主> <宛先> <Message-ID> [件名]
//
// 本文は空。件名は省けば `Re: reply`（check の確認用）。訓練の連絡への返信で「無事」と読ませるときは、ウェブメールの返信と
// 同じく、受けた連絡の件名の前に `Re: ` を付けたものを渡す（連絡の件名が [安否確認 #51d762cc] 【訓練】… なら、
// `Re: [安否確認 #51d762cc] 【訓練】…`）。
// Message-ID は `bin/console check` が出す `message-id:` の値（山括弧なし）。

if ($argc < 4 || $argc > 5) {
    fwrite(STDERR, "使い方：php docker/dev/reply.php <送り主> <宛先> <Message-ID> [件名]\n");
    exit(2);
}

[, $from, $to, $messageId] = $argv;
$subject = $argv[4] ?? 'Re: reply';
$messageId = trim($messageId, '<>');
$host = getenv('REBUJI_DEV_SMTP_HOST') ?: 'mail';

$socket = @stream_socket_client('tcp://' . $host . ':3025', $errno, $error, 10);

if ($socket === false) {
    fwrite(STDERR, "メールサーバーにつながりません：{$error}\n");
    exit(1);
}

stream_set_timeout($socket, 10);

$expect = static function (string $prefix) use ($socket): void {
    do {
        $line = fgets($socket);

        if ($line === false) {
            fwrite(STDERR, "メールサーバーの返事が途切れました。\n");
            exit(1);
        }
    } while (isset($line[3]) && $line[3] === '-');

    if (!str_starts_with($line, $prefix)) {
        fwrite(STDERR, '想定外の返事：' . trim($line) . "\n");
        exit(1);
    }
};

$send = static function (string $line) use ($socket): void {
    fwrite($socket, $line . "\r\n");
};

$expect('220');
$send('EHLO reply.test');
$expect('250');
$send('MAIL FROM:<' . $from . '>');
$expect('250');
$send('RCPT TO:<' . $to . '>');
$expect('250');
$send('DATA');
$expect('354');
$send('From: <' . $from . '>');
$send('To: <' . $to . '>');
$send('Subject: =?UTF-8?B?' . base64_encode($subject) . '?=');
$send('Date: ' . date('r'));
$send('Message-ID: <' . bin2hex(random_bytes(8)) . '@reply.test>');
$send('In-Reply-To: <' . $messageId . '>');
$send('References: <' . $messageId . '>');
$send('MIME-Version: 1.0');
$send('Content-Type: text/plain; charset=UTF-8');
$send('');
$send('');
$send('.');
$expect('250');
$send('QUIT');
fclose($socket);

echo "返信を送りました：{$from} → {$to}\n";

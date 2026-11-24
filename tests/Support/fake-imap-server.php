<?php

declare(strict_types=1);

// A minimal IMAP server for tests, run by FakeImapServer in its own process:
//   php fake-imap-server.php MAIL_DIR TRANSCRIPT_FILE OPTIONS_JSON
// The inbox is MAIL_DIR/{uid}.eml; a message is seen when MAIL_DIR/{uid}.seen exists. Prints the port it listens on,
// answers connections one after another and appends every line to TRANSCRIPT_FILE (message bodies are not logged).
// A SEARCH with UNSEEN finds unseen mail; one with HEADER <name> "<text>" pairs finds mail whose header contains any of
// the texts, read or not. SINCE is ignored: the files carry no date.
// It exits after 30 seconds without a connection. Options: rejectLogin, and failStartTls, which offers STARTTLS but
// goes on in plain text after agreeing, so the client's TLS handshake fails (otherwise it offers no STARTTLS).

[, $dir, $transcript, $json] = $argv + [null, null, null, '{}'];
$options = json_decode((string) $json, true);
$rejectLogin = is_array($options) && ($options['rejectLogin'] ?? false) === true;
$failStartTls = is_array($options) && ($options['failStartTls'] ?? false) === true;

$server = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);

if ($server === false) {
    fwrite(STDERR, $errorMessage . "\n");
    exit(1);
}

$name = (string) stream_socket_get_name($server, false);
echo substr($name, strrpos($name, ':') + 1), "\n";
fflush(STDOUT);

$log = static function (string $line) use ($transcript): void {
    file_put_contents((string) $transcript, $line . "\n", FILE_APPEND);
};

$uids = static function () use ($dir): array {
    $found = [];

    foreach (glob($dir . '/*.eml') ?: [] as $file) {
        $found[] = (int) basename($file, '.eml');
    }

    sort($found);

    return $found;
};

while (($client = @stream_socket_accept($server, 30)) !== false) {
    $say = static function (string $reply) use ($client, $log): void {
        fwrite($client, $reply . "\r\n");
        $log('S: ' . $reply);
    };

    $say('* OK fake IMAP4rev1 ready');

    while (($line = fgets($client)) !== false) {
        $line = rtrim($line, "\r\n");
        $log('C: ' . $line);
        [$tag, $command, $rest] = array_pad(explode(' ', $line, 3), 3, '');
        $command = strtoupper($command);

        if ($command === 'CAPABILITY') {
            $say('* CAPABILITY IMAP4rev1 ' . ($failStartTls ? 'STARTTLS ' : '') . 'AUTH=PLAIN');
            $say($tag . ' OK CAPABILITY completed');
        } elseif ($command === 'STARTTLS' && $failStartTls) {
            $say($tag . ' OK Begin TLS negotiation now');
            // Wait for the ClientHello: sent with the line above, the plain text could reach the client's buffer before
            // the handshake starts, and OpenSSL would see only a clean end of stream, with no warning to report.
            fread($client, 5);
            $say('* BYE not really TLS');

            break;
        } elseif ($command === 'LOGIN') {
            $say($rejectLogin ? $tag . ' NO [AUTHENTICATIONFAILED] Invalid credentials' : $tag . ' OK LOGIN completed');
        } elseif ($command === 'SELECT') {
            $say('* ' . count($uids()) . ' EXISTS');
            $say($tag . ' OK [READ-WRITE] SELECT completed');
        } elseif ($command === 'NOOP') {
            $say($tag . ' OK NOOP completed');
        } elseif ($command === 'UID' && str_starts_with(strtoupper($rest), 'SEARCH')) {
            if (stripos($rest, 'UNSEEN') !== false) {
                $found = array_values(array_filter($uids(), static fn(int $uid): bool => !is_file($dir . '/' . $uid . '.seen')));
            } else {
                preg_match_all('/HEADER (\S+) "((?:[^"\\\\]|\\\\.)*)"/i', $rest, $pairs, PREG_SET_ORDER);
                $found = [];

                foreach ($uids() as $uid) {
                    $head = explode("\r\n\r\n", (string) file_get_contents($dir . '/' . $uid . '.eml'), 2)[0];

                    foreach ($pairs as $pair) {
                        $text = stripslashes($pair[2]);

                        if (preg_match('/^' . preg_quote($pair[1], '/') . ':(.*)$/im', $head, $header) === 1 && stripos($header[1], $text) !== false) {
                            $found[] = $uid;

                            break;
                        }
                    }
                }
            }

            $say('* SEARCH' . ($found === [] ? '' : ' ' . implode(' ', $found)));
            $say($tag . ' OK SEARCH completed');
        } elseif ($command === 'UID' && preg_match('/^FETCH (\d+) /i', $rest, $match) === 1) {
            $file = $dir . '/' . $match[1] . '.eml';

            if (is_file($file)) {
                $raw = (string) file_get_contents($file);
                $origin = '';

                // A partial fetch: BODY.PEEK[]<start.length>
                if (preg_match('/<(\d+)\.(\d+)>/', $rest, $part) === 1) {
                    $raw = substr($raw, (int) $part[1], (int) $part[2]);
                    $origin = '<' . $part[1] . '>';
                }

                fwrite($client, '* 1 FETCH (UID ' . $match[1] . ' BODY[]' . $origin . ' {' . strlen($raw) . "}\r\n" . $raw . ")\r\n");
                $log('S: * 1 FETCH (UID ' . $match[1] . ' BODY[]' . $origin . ' {' . strlen($raw) . '} …)');
            }

            $say($tag . ' OK FETCH completed');
        } elseif ($command === 'UID' && preg_match('/^STORE (\d+) \+FLAGS/i', $rest, $match) === 1) {
            touch($dir . '/' . $match[1] . '.seen');
            $say($tag . ' OK STORE completed');
        } elseif ($command === 'LOGOUT') {
            $say('* BYE fake IMAP server logging out');
            $say($tag . ' OK LOGOUT completed');

            break;
        } else {
            $say($tag . ' BAD Command not recognized');
        }
    }

    fclose($client);
}

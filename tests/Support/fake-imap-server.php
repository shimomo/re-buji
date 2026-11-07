<?php

declare(strict_types=1);

// A minimal IMAP server for tests, run by FakeImapServer in its own process:
//   php fake-imap-server.php MAIL_DIR TRANSCRIPT_FILE OPTIONS_JSON
// The inbox is MAIL_DIR/{uid}.eml; a message is seen when MAIL_DIR/{uid}.seen exists. Prints the port it listens on,
// answers connections one after another and appends every line to TRANSCRIPT_FILE (message bodies are not logged).
// It offers no STARTTLS and exits after 30 seconds without a connection. Options: rejectLogin.

[, $dir, $transcript, $json] = $argv + [null, null, null, '{}'];
$options = json_decode((string) $json, true);
$rejectLogin = is_array($options) && ($options['rejectLogin'] ?? false) === true;

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
            $say('* CAPABILITY IMAP4rev1 AUTH=PLAIN');
            $say($tag . ' OK CAPABILITY completed');
        } elseif ($command === 'LOGIN') {
            $say($rejectLogin ? $tag . ' NO [AUTHENTICATIONFAILED] Invalid credentials' : $tag . ' OK LOGIN completed');
        } elseif ($command === 'SELECT') {
            $say('* ' . count($uids()) . ' EXISTS');
            $say($tag . ' OK [READ-WRITE] SELECT completed');
        } elseif ($command === 'UID' && str_starts_with(strtoupper($rest), 'SEARCH')) {
            $unseen = array_values(array_filter($uids(), static fn(int $uid): bool => !is_file($dir . '/' . $uid . '.seen')));
            $say('* SEARCH' . ($unseen === [] ? '' : ' ' . implode(' ', $unseen)));
            $say($tag . ' OK SEARCH completed');
        } elseif ($command === 'UID' && preg_match('/^FETCH (\d+) /i', $rest, $match) === 1) {
            $file = $dir . '/' . $match[1] . '.eml';

            if (is_file($file)) {
                $raw = (string) file_get_contents($file);
                fwrite($client, '* 1 FETCH (UID ' . $match[1] . ' BODY[] {' . strlen($raw) . "}\r\n" . $raw . ")\r\n");
                $log('S: * 1 FETCH (UID ' . $match[1] . ' BODY[] {' . strlen($raw) . '} …)');
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

<?php

declare(strict_types=1);

// A minimal SMTP server for tests, run by FakeSmtpServer in its own process:
//   php fake-smtp-server.php TRANSCRIPT_FILE OPTIONS_JSON
// Prints the port it listens on, then answers connections one after another and appends every line it receives and
// sends to TRANSCRIPT_FILE. It offers AUTH PLAIN and LOGIN, never STARTTLS, and exits after 30 seconds without a
// connection.

[, $transcript, $json] = $argv + [null, null, '{}'];
$options = json_decode((string) $json, true);
$rejectRecipients = is_array($options) && is_array($options['rejectRecipients'] ?? null) ? $options['rejectRecipients'] : [];
$rejectAuth = is_array($options) && ($options['rejectAuth'] ?? false) === true;

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

while (($client = @stream_socket_accept($server, 30)) !== false) {
    $say = static function (string $reply) use ($client, $log): void {
        fwrite($client, $reply . "\r\n");
        $log('S: ' . $reply);
    };
    $read = static function () use ($client, $log): ?string {
        $line = fgets($client);

        if ($line === false) {
            return null;
        }

        $line = rtrim($line, "\r\n");
        $log('C: ' . $line);

        return $line;
    };

    $say('220 fake.example ESMTP');

    while (($line = $read()) !== null) {
        $command = strtoupper(substr($line, 0, 4));

        if ($command === 'EHLO') {
            fwrite($client, "250-fake.example\r\n250-AUTH PLAIN LOGIN\r\n250 8BITMIME\r\n");
            $log('S: 250 fake.example (AUTH PLAIN LOGIN)');
        } elseif ($command === 'HELO') {
            $say('250 fake.example');
        } elseif ($command === 'AUTH') {
            if ($rejectAuth) {
                $say('535 5.7.8 Authentication failed');
            } elseif (stripos($line, 'AUTH LOGIN') === 0) {
                $say('334 VXNlcm5hbWU6');
                $read();
                $say('334 UGFzc3dvcmQ6');
                $read();
                $say('235 2.7.0 Authentication successful');
            } else {
                $say('235 2.7.0 Authentication successful');
            }
        } elseif ($command === 'MAIL') {
            $say('250 2.1.0 Ok');
        } elseif ($command === 'RCPT') {
            $refused = false;

            foreach ($rejectRecipients as $address) {
                if (is_string($address) && str_contains($line, '<' . $address . '>')) {
                    $refused = true;
                }
            }

            $say($refused ? '550 5.1.1 User unknown' : '250 2.1.5 Ok');
        } elseif ($command === 'DATA') {
            $say('354 End data with <CR><LF>.<CR><LF>');

            while (($data = $read()) !== null && $data !== '.') {
                // The lines are logged by $read().
            }

            $say('250 2.0.0 Ok: queued');
        } elseif ($command === 'RSET' || $command === 'NOOP') {
            $say('250 2.0.0 Ok');
        } elseif ($command === 'QUIT') {
            $say('221 2.0.0 Bye');

            break;
        } else {
            $say('502 5.5.2 Command not recognized');
        }
    }

    fclose($client);
}

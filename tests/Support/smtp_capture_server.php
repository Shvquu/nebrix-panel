<?php

declare(strict_types=1);

/*
 * Minimal SMTP server for Mailer tests: accepts one message without TLS or
 * authentication and writes what the client sent after DATA to a file.
 * Usage: php smtp_capture_server.php <port-file> <message-file>
 */

$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if ($server === false) {
    fwrite(STDERR, $errstr);
    exit(1);
}
$name = stream_socket_get_name($server, false);
file_put_contents($argv[1], (string) substr((string) $name, strrpos((string) $name, ':') + 1));

$client = stream_socket_accept($server, 10);
if ($client === false) {
    exit(1);
}
fwrite($client, "220 fake.example ESMTP\r\n");
$data = null;
while (($line = fgets($client)) !== false) {
    if ($data !== null) {
        if (rtrim($line, "\r\n") === '.') {
            file_put_contents($argv[2], $data);
            $data = null;
            fwrite($client, "250 2.0.0 Ok: queued\r\n");
            continue;
        }
        $data .= $line;
        continue;
    }
    $command = strtoupper(substr(trim($line), 0, 4));
    if ($command === 'EHLO' || $command === 'HELO') {
        fwrite($client, "250-fake.example\r\n250 8BITMIME\r\n");
    } elseif ($command === 'DATA') {
        $data = '';
        fwrite($client, "354 End data with <CR><LF>.<CR><LF>\r\n");
    } elseif ($command === 'QUIT') {
        fwrite($client, "221 2.0.0 Bye\r\n");
        break;
    } else {
        fwrite($client, "250 OK\r\n");
    }
}

<?php

declare(strict_types=1);

/*
 * Minimal SMTP server for Mailer tests: offers AUTH without TLS and
 * rejects every login with 535 (like Postfix with wrong credentials).
 * Usage: php smtp_socket_server.php <port-file>
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
while (($line = fgets($client)) !== false) {
    $command = strtoupper(substr(trim($line), 0, 4));
    if ($command === 'EHLO' || $command === 'HELO') {
        fwrite($client, "250-fake.example\r\n250-AUTH PLAIN LOGIN\r\n250 8BITMIME\r\n");
    } elseif ($command === 'AUTH') {
        fwrite($client, "535 5.7.8 Error: authentication failed: UGFzc3dvcmQ6\r\n");
    } elseif ($command === 'QUIT') {
        fwrite($client, "221 2.0.0 Bye\r\n");
        break;
    } else {
        fwrite($client, "250 OK\r\n");
    }
}

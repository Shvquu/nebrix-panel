<?php

declare(strict_types=1);

/*
 * Minimal ServerQuery on stdin/stdout, used as an SSH ForceCommand to test
 * the SSH transport against a real SSH server (see TeamSpeakSshTest).
 * Same answers as ts3_socket_server.php; lines end with "\n\r" like TeamSpeak.
 */

$out = static function (string $text): void {
    fwrite(STDOUT, $text);
    fflush(STDOUT);
};
$out("TS3\n\rWelcome to the TeamSpeak 3 ServerQuery interface, type \"help\" for a list of commands and \"help <command>\" for information on a specific command.\n\r");
$loggedIn = false;
while (($line = fgets(STDIN)) !== false) {
    $line = trim($line);
    if ($line === '') {
        continue;
    }
    if (str_starts_with($line, 'login ')) {
        $loggedIn = str_contains($line, 'client_login_password=s3cr\\/et\\sx');
        $out($loggedIn ? "error id=0 msg=ok\n\r" : "error id=520 msg=invalid\\sloginname\\sor\\spassword\n\r");
    } elseif ($line === 'version') {
        $out("version=3.13.7 build=1 platform=Linux\n\rerror id=0 msg=ok\n\r");
    } elseif ($line === 'serverlist -uid' && $loggedIn) {
        $out("virtualserver_id=1 virtualserver_port=9987 virtualserver_status=online|virtualserver_id=2 virtualserver_port=9988 virtualserver_status=offline\n\rerror id=0 msg=ok\n\r");
    } elseif ($line === 'quit') {
        break;
    } else {
        $out("error id=256 msg=command\\snot\\sfound\n\r");
    }
}

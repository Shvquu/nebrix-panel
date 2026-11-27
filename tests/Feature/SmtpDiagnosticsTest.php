<?php

declare(strict_types=1);

namespace Nebrix\Tests\Feature;

use Nebrix\Mail\Mailer;
use Nebrix\Settings\SettingsRepository;
use Nebrix\Tests\FeatureTestCase;
use RuntimeException;

/**
 * The SMTP test in the admin panel names the server's answer (e.g. a
 * rejected login) instead of a generic error, and never echoes what the
 * client sent (credentials).
 */
final class SmtpDiagnosticsTest extends FeatureTestCase
{
    public function testConnectionTestReportsTheServersAnswer(): void
    {
        $portFile = (string) tempnam(sys_get_temp_dir(), 'smtp');
        $process = proc_open([PHP_BINARY, __DIR__ . '/../Support/smtp_socket_server.php', $portFile], [], $pipes);
        self::assertIsResource($process);
        for ($i = 0; $i < 50 && (string) file_get_contents($portFile) === ''; $i++) {
            usleep(100_000);
        }

        $settings = $this->app->container()->get(SettingsRepository::class);
        foreach (['mail.host' => '127.0.0.1', 'mail.port' => (int) file_get_contents($portFile), 'mail.encryption' => 'none', 'mail.username' => 'billing@example.test', 'mail.password' => 'wrong-password', 'mail.from_address' => 'billing@example.test'] as $key => $value) {
            $settings->set($key, $value);
        }

        try {
            $this->app->container()->get(Mailer::class)->testConnection();
            self::fail('The rejected login was not reported.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('535 5.7.8 Error: authentication failed', $exception->getMessage());
            self::assertStringNotContainsString('wrong-password', $exception->getMessage());
            self::assertStringNotContainsString(base64_encode("\0billing@example.test\0wrong-password"), $exception->getMessage(), 'Client lines (credentials) are never included.');
        } finally {
            proc_terminate($process);
            proc_close($process);
            @unlink($portFile);
        }

        self::assertTrue(Mailer::isLoopback('localhost'));
        self::assertTrue(Mailer::isLoopback('127.0.0.1'));
        self::assertTrue(Mailer::isLoopback('[::1]'));
        self::assertFalse(Mailer::isLoopback('mail.example.com'));
        self::assertFalse(Mailer::isLoopback('203.0.113.10'));
    }
}

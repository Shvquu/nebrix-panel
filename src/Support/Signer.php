<?php

declare(strict_types=1);

namespace Nebrix\Support;

use Nebrix\Core\Config;
use Nebrix\Security\Encrypter;

/**
 * HMAC-SHA256 signatures with keys derived from APP_KEY, one key per
 * purpose (e-mail reply addresses, status page unsubscribe links). The
 * signed values are never stored: they are recomputed and compared with
 * hash_equals.
 */
class Signer
{
    public function __construct(private readonly Config $config)
    {
    }

    /** Lower-case hex signature of $data for $purpose, optionally shortened. */
    public function sign(string $purpose, string $data, int $hexLength = 64): string
    {
        $key = hash_hmac('sha256', 'nebrix-signer:' . $purpose, Encrypter::decodeKey($this->config->string('app.key')), true);

        return substr(hash_hmac('sha256', $data, $key), 0, max(16, min(64, $hexLength)));
    }

    public function verify(string $purpose, string $data, string $signature, int $hexLength = 64): bool
    {
        $signature = strtolower($signature);

        return strlen($signature) === max(16, min(64, $hexLength)) && hash_equals($this->sign($purpose, $data, $hexLength), $signature);
    }
}

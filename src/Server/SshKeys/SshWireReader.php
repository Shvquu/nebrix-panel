<?php

declare(strict_types=1);

namespace Nebrix\Server\SshKeys;

use LengthException;

/**
 * Reads length-prefixed strings of the SSH wire format (RFC 4251, section 5).
 */
final class SshWireReader
{
    private int $offset = 0;

    public function __construct(private readonly string $data)
    {
    }

    /** @throws LengthException when the data ends early */
    public function string(): string
    {
        if (strlen($this->data) - $this->offset < 4) {
            throw new LengthException('Truncated SSH data.');
        }
        $length = unpack('N', substr($this->data, $this->offset, 4));
        $length = is_array($length) ? (int) $length[1] : -1;
        $this->offset += 4;
        if ($length < 0 || $length > strlen($this->data) - $this->offset) {
            throw new LengthException('Truncated SSH data.');
        }
        $value = substr($this->data, $this->offset, $length);
        $this->offset += $length;

        return $value;
    }

    public function atEnd(): bool
    {
        return $this->offset === strlen($this->data);
    }
}

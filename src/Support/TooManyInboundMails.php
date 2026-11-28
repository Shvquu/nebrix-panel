<?php

declare(strict_types=1);

namespace Nebrix\Support;

/** A rate limit for inbound ticket e-mails was exceeded; the provider should retry later. */
final class TooManyInboundMails extends \RuntimeException
{
}

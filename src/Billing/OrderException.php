<?php

declare(strict_types=1);

namespace Nebrix\Billing;

use RuntimeException;

/** Order could not be placed; the message is safe to show to customers. */
class OrderException extends RuntimeException
{
}

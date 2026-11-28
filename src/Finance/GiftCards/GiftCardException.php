<?php

declare(strict_types=1);

namespace Nebrix\Finance\GiftCards;

use RuntimeException;

/** A gift card cannot be created or redeemed; the message is safe to show. */
final class GiftCardException extends RuntimeException
{
}

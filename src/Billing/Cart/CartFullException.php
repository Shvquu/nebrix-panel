<?php

declare(strict_types=1);

namespace Nebrix\Billing\Cart;

use Nebrix\Billing\OrderException;

/** The cart holds the maximum number of items. */
final class CartFullException extends OrderException
{
}

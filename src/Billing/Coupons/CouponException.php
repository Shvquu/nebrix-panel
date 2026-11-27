<?php

declare(strict_types=1);

namespace Nebrix\Billing\Coupons;

use Nebrix\Billing\OrderException;

/** A coupon code cannot be used; the message is shown to the customer. */
final class CouponException extends OrderException
{
}

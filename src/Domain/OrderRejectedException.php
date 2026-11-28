<?php

declare(strict_types=1);

namespace Nebrix\Domain;

use Nebrix\Integration\IntegrationException;

/**
 * The registrar refused an order before or when it was submitted (local
 * validation or a definitive error answer) – nothing was ordered.
 */
final class OrderRejectedException extends IntegrationException
{
}

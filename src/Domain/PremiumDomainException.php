<?php

declare(strict_types=1);

namespace Nebrix\Domain;

use Nebrix\Integration\IntegrationException;

/** The domain is available only at a premium price (not sold through the panel). */
final class PremiumDomainException extends IntegrationException
{
}

<?php

declare(strict_types=1);

namespace Mnd\DmPlatform\Exceptions;

/**
 * This installation has failed too often. Other installations keep their own counters.
 */
class CircuitOpenException extends DmPlatformException
{
}

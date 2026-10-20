<?php

declare(strict_types=1);

namespace Mnd\DmPlatform\Exceptions;

/**
 * The installation stopped answering, or the network failed, and the retries are spent.
 */
class ServiceUnavailableException extends DmPlatformException
{
}

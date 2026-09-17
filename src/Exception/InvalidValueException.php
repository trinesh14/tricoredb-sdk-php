<?php

declare(strict_types=1);

namespace TriCoreDb\Exception;

/**
 * An argument cannot be represented on the wire as the caller meant it
 * (a lossy float, a list where an object is required, an unknown enum value).
 * Nothing was sent.
 */
final class InvalidValueException extends TriCoreException
{
}

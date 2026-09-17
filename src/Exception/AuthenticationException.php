<?php

declare(strict_types=1);

namespace TriCoreDb\Exception;

/**
 * Authentication was refused: an AUTH_OK carrying `ok: false`, or an ERROR
 * frame in reply to AUTH. The connection is closed.
 */
final class AuthenticationException extends TriCoreException
{
}

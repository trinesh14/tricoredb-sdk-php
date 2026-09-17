<?php

declare(strict_types=1);

namespace TriCoreDb\Exception;

/**
 * The call needs a capability the server did not grant in the handshake
 * (`SERVER_PARAMS`, `SESSION_TXN` or `CORRELATION_ID`). Nothing was sent.
 */
final class FeatureNotGrantedException extends TriCoreException
{
}

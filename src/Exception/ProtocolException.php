<?php

declare(strict_types=1);

namespace TriCoreDb\Exception;

/**
 * A frame-level failure: an ERROR frame, a refused handshake, an unexpected
 * tag, an oversized or unreadable frame.
 */
final class ProtocolException extends TriCoreException
{
}

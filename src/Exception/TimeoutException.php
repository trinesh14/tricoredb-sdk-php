<?php

declare(strict_types=1);

namespace TriCoreDb\Exception;

/**
 * A reply did not arrive within the client-side read timeout.
 *
 * The connection is closed when this is thrown: the late reply may still be
 * in flight and would otherwise be read as the answer to the next request.
 * To make the server stop the work, set a request timeout instead.
 */
final class TimeoutException extends TriCoreException
{
}

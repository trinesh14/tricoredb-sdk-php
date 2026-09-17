<?php

declare(strict_types=1);

namespace TriCoreDb;

/**
 * This package's version, sent to the server as part of the client name.
 */
final class Version
{
    /** Kept in step with the release tag. */
    public const VERSION = '0.1.0';

    private function __construct()
    {
    }
}

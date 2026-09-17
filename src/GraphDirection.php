<?php

declare(strict_types=1);

namespace TriCoreDb;

/**
 * Edge direction for graph navigation.
 */
enum GraphDirection: string
{
    case Outgoing = 'outgoing';
    case Incoming = 'incoming';
    case Both = 'both';
}

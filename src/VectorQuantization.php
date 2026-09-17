<?php

declare(strict_types=1);

namespace TriCoreDb;

/**
 * Index quantization of a vector collection. Stored vectors keep full precision.
 */
enum VectorQuantization: string
{
    case None = 'none';
    case Int8 = 'int8';
}

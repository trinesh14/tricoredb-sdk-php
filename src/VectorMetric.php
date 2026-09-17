<?php

declare(strict_types=1);

namespace TriCoreDb;

/**
 * Similarity metric of a vector collection. With `L2` a search score is the
 * negated squared distance, so higher is closer.
 */
enum VectorMetric: string
{
    case Cosine = 'cosine';
    case Dot = 'dot';
    case L2 = 'l2';
}

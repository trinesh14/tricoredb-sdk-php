<?php

declare(strict_types=1);

namespace TriCoreDb;

/**
 * Rendering of an LLM export.
 */
enum OutputFormat: string
{
    case Native = 'native';
    case Json = 'json';
    case Toon = 'toon';
    case Markdown = 'markdown';
}

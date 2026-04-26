<?php

declare(strict_types=1);

namespace OmniPorter\Exceptions;

use RuntimeException;

/**
 * Base exception for all OmniPorter errors.
 *
 * Consuming packages should catch this type to handle any OmniPorter-specific
 * failure without coupling to internal sub-exception hierarchies.
 *
 * Sub-classes:
 *  - {@see ImportException}  – thrown during import pipeline errors
 *  - {@see ExportException}  – thrown during export pipeline errors
 */
class OmniPorterException extends RuntimeException
{
    //
}

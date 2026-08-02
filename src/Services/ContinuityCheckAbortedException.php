<?php

declare(strict_types=1);

namespace MediaManager\Services;

use RuntimeException;

/** Thrown when a continuity HTTP batch is aborted by a cooperative cancel. */
final class ContinuityCheckAbortedException extends RuntimeException
{
}

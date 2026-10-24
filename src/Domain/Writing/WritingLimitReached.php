<?php declare(strict_types=1);

namespace Dansk\Domain\Writing;

use RuntimeException;

/** Today's checks are used up. */
final class WritingLimitReached extends RuntimeException {}

<?php declare(strict_types=1);

namespace Dansk\Domain\Writing;

use RuntimeException;

/** The learner, or the site as a whole, has used today's checks. */
final class WritingLimitReached extends RuntimeException {}

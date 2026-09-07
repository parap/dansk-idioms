<?php declare(strict_types=1);

namespace Dansk\Domain\Reading;

use RuntimeException;

/**
 * A passage that could not be rendered or graded. Raised before anything is written, so
 * a rejected document leaves no partial passage behind for a learner to run into.
 */
final class InvalidPassage extends RuntimeException
{
}

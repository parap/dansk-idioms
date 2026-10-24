<?php declare(strict_types=1);

namespace Dansk\Domain\Writing;

use RuntimeException;

/** The check is not offered to this visitor, or no reviewer is configured. */
final class WritingCheckUnavailable extends RuntimeException {}

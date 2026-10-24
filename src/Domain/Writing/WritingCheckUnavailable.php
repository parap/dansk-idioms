<?php declare(strict_types=1);

namespace Dansk\Domain\Writing;

use RuntimeException;

/** No reviewer is configured, so nothing can be checked. */
final class WritingCheckUnavailable extends RuntimeException {}

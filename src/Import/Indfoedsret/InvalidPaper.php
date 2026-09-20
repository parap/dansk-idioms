<?php declare(strict_types=1);

namespace Dansk\Import\Indfoedsret;

use RuntimeException;

/**
 * An exam paper or answer sheet that could not be read with certainty. Raised before a
 * document is written, because a paper whose answers are guessed marks a learner wrong
 * for being right and reports nothing while doing it.
 */
final class InvalidPaper extends RuntimeException
{
}

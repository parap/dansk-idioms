<?php declare(strict_types=1);

namespace Dansk\Import;

final class ParsedEntry
{
    /** @param array<string,string> $labels  e.g. ['Значение' => '...', 'Объяснение' => '...'] */
    public function __construct(
        public string  $rawText,
        public ?string $term = null,
        public ?string $termNote = null,
        public ?string $inflectedForm = null,
        public ?string $explanation = null,
        /** Text after the separator on the head line -- often the short translation. */
        public ?string $headRemainder = null,
        /** Continuation lines carrying no explicit label. @var list<string> */
        public array $trailingLines = [],
        public array   $labels = [],
        public ?string $strategy = null,
        public ?string $separatorKind = null,
        public float   $confidence = 0.0,
        /** @var array<string,float> */
        public array   $signals = [],
    ) {}

    public function label(string $name): ?string
    {
        return $this->labels[$name] ?? null;
    }
}

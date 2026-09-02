<?php
declare(strict_types=1);

namespace HexaLite\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
readonly class TimeAlwaysExecuted
{
    public function __construct(
        public float $seconds = 2.0
    ) {
    }
}
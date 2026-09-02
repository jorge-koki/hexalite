<?php
declare(strict_types=1);

namespace HexaLite\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
readonly class Route
{
    public function __construct(
        public string $path,
        public string $method = 'GET'
    ) {}
}

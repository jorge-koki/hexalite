<?php
declare(strict_types=1);

namespace HexaLite\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
readonly class Middleware
{
    public function __construct(
        public string $middlewareClass,
        public int $priority = 100
    ) {}
}

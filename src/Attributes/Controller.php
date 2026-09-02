<?php
declare(strict_types=1);

namespace HexaLite\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
readonly class Controller
{
    public function __construct(
        public string $basePath = ''
    ) {}
}

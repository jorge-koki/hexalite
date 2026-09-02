<?php
declare(strict_types=1);

namespace HexaLite\Http\DTO\Attributes;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
readonly class IsFloat implements ValidationAttribute
{
    public function __construct(public ?string $message = null) {}

    public function toRuleString(): string { return 'float'; }
    public function getRuleName(): string { return 'float'; }
    public function getMessage(): ?string { return $this->message; }
}

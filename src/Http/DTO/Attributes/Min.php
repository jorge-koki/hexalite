<?php
declare(strict_types=1);

namespace HexaLite\Http\DTO\Attributes;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
readonly class Min implements ValidationAttribute
{
    public function __construct(public int|float $value, public ?string $message = null) {}

    public function toRuleString(): string { return "min:{$this->value}"; }
    public function getRuleName(): string { return 'min'; }
    public function getMessage(): ?string { return $this->message; }
}

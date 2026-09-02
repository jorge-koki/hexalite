<?php
declare(strict_types=1);

namespace HexaLite\Http\DTO\Attributes;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
readonly class IsBoolean implements ValidationAttribute
{
    public function __construct(public ?string $message = null) {}

    public function toRuleString(): string { return 'boolean'; }
    public function getRuleName(): string { return 'boolean'; }
    public function getMessage(): ?string { return $this->message; }
}

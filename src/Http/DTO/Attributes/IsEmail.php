<?php
declare(strict_types=1);

namespace HexaLite\Http\DTO\Attributes;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
readonly class IsEmail implements ValidationAttribute
{
    public function __construct(public ?string $message = null) {}

    public function toRuleString(): string { return 'email'; }
    public function getRuleName(): string { return 'email'; }
    public function getMessage(): ?string { return $this->message; }
}

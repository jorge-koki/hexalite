<?php
declare(strict_types=1);

namespace HexaLite\Http\DTO\Attributes;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
readonly class IsUrl implements ValidationAttribute
{
    public function __construct(public ?string $message = null) {}

    public function toRuleString(): string { return 'url'; }
    public function getRuleName(): string { return 'url'; }
    public function getMessage(): ?string { return $this->message; }
}

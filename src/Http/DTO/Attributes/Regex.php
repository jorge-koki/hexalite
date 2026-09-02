<?php
declare(strict_types=1);

namespace HexaLite\Http\DTO\Attributes;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
readonly class Regex implements ValidationAttribute
{
    public function __construct(public string $pattern, public ?string $message = null) {}

    public function toRuleString(): string { return "regex:{$this->pattern}"; }
    public function getRuleName(): string { return 'regex'; }
    public function getMessage(): ?string { return $this->message; }
}

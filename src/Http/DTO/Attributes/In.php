<?php
declare(strict_types=1);

namespace HexaLite\Http\DTO\Attributes;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
readonly class In implements ValidationAttribute
{
    public array $values;
    public ?string $message;

    public function __construct(array $values, ?string $message = null)
    {
        $this->values  = $values;
        $this->message = $message;
    }

    public function toRuleString(): string { return 'in:' . implode(',', $this->values); }
    public function getRuleName(): string { return 'in'; }
    public function getMessage(): ?string { return $this->message; }
}

<?php
declare(strict_types=1);

namespace HexaLite\Http\DTO\Attributes;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
readonly class DateFormat implements ValidationAttribute
{
    public function __construct(public string $format = '', public ?string $message = null) {}

    public function toRuleString(): string
    {
        return $this->format !== '' ? "date:{$this->format}" : 'date';
    }

    public function getRuleName(): string { return 'date'; }
    public function getMessage(): ?string { return $this->message; }
}

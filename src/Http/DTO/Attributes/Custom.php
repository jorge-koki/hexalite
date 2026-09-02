<?php
declare(strict_types=1);

namespace HexaLite\Http\DTO\Attributes;

use HexaLite\Http\DTO\Validators\CustomValidator;

#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::IS_REPEATABLE)]
readonly class Custom implements ValidationAttribute
{
    /**
     * @param class-string<CustomValidator> $validator FQCN de una clase que implemente CustomValidator.
     */
    public function __construct(public string $validator, public ?string $message = null) {}

    public function toRuleString(): string { return "custom:{$this->validator}"; }

    /**
     * Incluye el FQCN para que varios #[Custom] en el mismo campo
     * no compartan la misma clave de mensaje.
     */
    public function getRuleName(): string { return "custom:{$this->validator}"; }

    public function getMessage(): ?string { return $this->message; }
}

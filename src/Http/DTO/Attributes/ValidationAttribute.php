<?php
declare(strict_types=1);

namespace HexaLite\Http\DTO\Attributes;

interface ValidationAttribute
{
    /**
     * Serializa la regla en el formato "name" o "name:param" usado por el pipeline interno.
     */
    public function toRuleString(): string;

    /**
     * Nombre canónico de la regla (sin parámetros). Ej: 'min', 'email', 'required'.
     * Usado para asociar mensajes custom al evaluar la regla.
     */
    public function getRuleName(): string;

    /**
     * Mensaje de error personalizado, si la subclase lo declara.
     * Cuando es null, el validador usa el mensaje por defecto.
     */
    public function getMessage(): ?string;
}

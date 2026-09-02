<?php
declare(strict_types=1);

namespace HexaLite\Http\DTO\Validators;

/**
 * Contrato para validadores personalizados invocados desde #[Custom(validator: MiClase::class)].
 *
 * Implementaciones típicas: StrongPasswordValidator, UniqueEmailValidator, etc.
 * Deben tener constructor sin argumentos (se instancian con `new $class()` y se cachean por proceso).
 */
interface CustomValidator
{
    /**
     * Retorna true si el valor pasa la validación, false si falla.
     *
     * @param mixed $value   Valor del campo a validar.
     * @param array $allData Datos completos del DTO (para validaciones cross-field).
     */
    public function passes(mixed $value, array $allData = []): bool;

    /**
     * Mensaje de error por defecto cuando #[Custom] no provee uno explícito.
     * Recibe el nombre del campo para componer el texto.
     */
    public function defaultMessage(string $field): string;
}

<?php
declare(strict_types=1);

namespace HexaLite\Validation;

use DateTime;
use Exception;
use HexaLite\Http\DTO\Validators\CustomValidator;
use LogicException;

/**
 * Motor único de validación de HexaLite.
 *
 * Centraliza el parseo de reglas, la semántica de cada regla y los mensajes por
 * defecto. Tanto {@see \HexaLite\Validation\Validator} (camino $request->validate())
 * como {@see \HexaLite\Http\DTO\Dtos} (camino de DTOs auto-inyectados) delegan aquí,
 * de modo que existe UNA sola fuente de verdad y ambos motores no vuelven a divergir.
 *
 * Soporta dos formatos de reglas por campo:
 *   - string legacy:   "required|min:3|regex:^\\d+$"      → se separa por '|'
 *   - array de reglas: ["required", "min:3", "regex:^(a|b)$"]
 *
 * El formato array resuelve el viejo bug del delimitador: un patrón regex que
 * contenga '|' (alternancia) se conserva íntegro porque NO se separa por '|',
 * solo cada regla suelta se parte por el primer ':'.
 */
final class RuleEngine
{
    /** @var array<string, list<array{name:string, param:string|null}>> cache de parseo de strings legacy */
    private static array $parseCache = [];

    /** @var array<string, CustomValidator> instancias de validadores custom por FQCN */
    private static array $customValidators = [];

    // ─────────────────────────────────────────────────────────────────────────
    // PARSEO
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Normaliza la definición de reglas de un campo a una lista de {name, param}.
     *
     * @param string|array<int, string> $rules
     * @return list<array{name:string, param:string|null}>
     */
    public static function parse(string|array $rules): array
    {
        if (\is_string($rules)) {
            if (isset(self::$parseCache[$rules])) {
                return self::$parseCache[$rules];
            }
            // Cota de memoria para procesos daemon (Amp)
            if (\count(self::$parseCache) > 1000) {
                \array_shift(self::$parseCache);
            }
            $parts  = $rules === '' ? [] : \explode('|', $rules);
            $parsed = self::parseParts($parts);
            self::$parseCache[$rules] = $parsed;
            return $parsed;
        }

        // Array: cada elemento es una regla suelta ("name" o "name:param").
        // No se cachea (la lista ya viene materializada y suele cachearla el llamador).
        return self::parseParts($rules);
    }

    /**
     * @param array<int, string> $parts
     * @return list<array{name:string, param:string|null}>
     */
    private static function parseParts(array $parts): array
    {
        $out = [];
        foreach ($parts as $rule) {
            $rule = \trim((string) $rule);
            if ($rule === '') {
                continue;
            }
            [$name, $param] = \array_pad(\explode(':', $rule, 2), 2, null);
            $out[] = ['name' => $name, 'param' => $param];
        }
        return $out;
    }

    /**
     * ¿La lista de reglas parseadas declara `nullable`?
     *
     * @param list<array{name:string, param:string|null}> $parsed
     */
    public static function isNullable(array $parsed): bool
    {
        foreach ($parsed as $r) {
            if ($r['name'] === 'nullable') {
                return true;
            }
        }
        return false;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // EVALUACIÓN — fuente única de verdad de la semántica de cada regla
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Evalúa UNA regla (salvo `custom`, que requiere instanciar el validador y se
     * maneja con {@see runCustom()}). Retorna true si pasa.
     */
    public static function passes(string $name, mixed $value, ?string $param, array $allData, string $field): bool
    {
        return match ($name) {
            'required'         => self::checkRequired($value),
            'nullable'         => true,
            'string'           => $value === null || $value === '' || \is_string($value) || \is_numeric($value),
            'int', 'integer'   => $value === null || $value === '' || \filter_var((string) $value, \FILTER_VALIDATE_INT) !== false,
            'numeric', 'float' => $value === null || $value === '' || \is_numeric($value),
            'boolean', 'bool'  => self::checkBoolean($value),
            'email'            => $value === null || $value === '' || \filter_var((string) $value, \FILTER_VALIDATE_EMAIL) !== false,
            'url'              => $value === null || $value === '' || \filter_var((string) $value, \FILTER_VALIDATE_URL) !== false,
            'array'            => $value === null || \is_array($value),
            'min'              => self::checkMin($value, $param),
            'max'              => self::checkMax($value, $param),
            'in'               => $value === null || self::checkIn($value, $param),
            'regex'            => $value === null || $value === '' || self::checkRegex((string) $value, (string) ($param ?? '')),
            'no_html'          => $value === null || $value === '' || self::checkNoHtml((string) $value),
            'confirmed'        => ($allData["{$field}_confirmation"] ?? null) === $value,
            'date'             => self::checkDate($value, $param),
            default            => true, // regla desconocida = pasa (comportamiento legacy)
        };
    }

    private static function checkRequired(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }
        if (\is_string($value) && $value === '') {
            return false;
        }
        if (\is_array($value) && \count($value) === 0) {
            return false;
        }
        return true; // acepta false, 0, "0", y cualquier otro valor no vacío
    }

    private static function checkBoolean(mixed $value): bool
    {
        if ($value === null || $value === '') {
            return true;
        }
        if (\is_bool($value)) {
            return true;
        }
        return \filter_var($value, \FILTER_VALIDATE_BOOLEAN, \FILTER_NULL_ON_FAILURE) !== null;
    }

    private static function checkMin(mixed $value, ?string $param): bool
    {
        if ($value === null || $value === '') {
            return true;
        }
        // String SIEMPRE por longitud (evita que un teléfono "+5219381040076" caiga al
        // chequeo numérico). Array por cantidad. Numérico real por valor.
        if (\is_string($value)) {
            return \mb_strlen($value) >= (int) $param;
        }
        if (\is_array($value)) {
            return \count($value) >= (int) $param;
        }
        if (\is_numeric($value)) {
            return (float) $value >= (float) $param;
        }
        return true;
    }

    private static function checkMax(mixed $value, ?string $param): bool
    {
        if ($value === null || $value === '') {
            return true;
        }
        if (\is_string($value)) {
            return \mb_strlen($value) <= (int) $param;
        }
        if (\is_array($value)) {
            return \count($value) <= (int) $param;
        }
        if (\is_numeric($value)) {
            return (float) $value <= (float) $param;
        }
        return true;
    }

    private static function checkIn(mixed $value, ?string $param): bool
    {
        if ($param === null || $param === '') {
            return false;
        }
        if (\is_array($value)) {
            return false;
        }
        $allowed = \array_map('trim', \explode(',', $param));
        return \in_array((string) $value, $allowed, true);
    }

    private static function checkRegex(string $value, string $pattern): bool
    {
        if ($pattern === '') {
            return false;
        }
        // Si el patrón no trae delimitador, lo envolvemos en '/.../'.
        $first = $pattern[0];
        if (!\in_array($first, ['/', '#', '~', '%', '!'], true)) {
            $pattern = '/' . \str_replace('/', '\/', $pattern) . '/';
        }
        return @\preg_match($pattern, $value) === 1;
    }

    /**
     * `no_html`: rechaza los delimitadores de etiqueta HTML ('<' y '>') y los
     * caracteres de control (salvo tab/LF/CR). Es defensa en profundidad para
     * campos de identidad (nombres de empresa/cliente/usuario): no tienen razón
     * legítima para contener '<>' y así una carga como `<script>…` se rechaza en
     * la ENTRADA, no solo se escapa en la salida.
     */
    private static function checkNoHtml(string $value): bool
    {
        if (\strpbrk($value, '<>') !== false) {
            return false;
        }
        // Caracteres de control C0 (0x00–0x1F) y DEL (0x7F), excepto \t \n \r.
        return \preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) !== 1;
    }

    private static function checkDate(mixed $value, ?string $format): bool
    {
        if ($value === null || $value === '') {
            return true;
        }
        $value = (string) $value;
        if ($format !== null && $format !== '') {
            $d = DateTime::createFromFormat($format, $value);
            return $d && $d->format($format) === $value;
        }
        try {
            new DateTime($value);
            return true;
        } catch (Exception) {
            return false;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // REGLAS CUSTOM (custom:FQCN)
    // ─────────────────────────────────────────────────────────────────────────

    /** Ejecuta un validador custom (con caché de instancias). Retorna true si pasa. */
    public static function runCustom(?string $class, mixed $value, array $allData): bool
    {
        if ($class === null || $class === '') {
            return true; // sin validador declarado → no hay nada que validar
        }
        return self::getCustom($class)->passes($value, $allData);
    }

    /** Mensaje por defecto del validador custom para un campo dado. */
    public static function customDefaultMessage(string $class, string $field): string
    {
        return self::getCustom($class)->defaultMessage($field);
    }

    private static function getCustom(string $class): CustomValidator
    {
        if (!isset(self::$customValidators[$class])) {
            if (!\class_exists($class) || !\is_subclass_of($class, CustomValidator::class)) {
                throw new LogicException("El validador custom '{$class}' no existe o no implementa CustomValidator.");
            }
            self::$customValidators[$class] = new $class();
        }
        return self::$customValidators[$class];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // MENSAJES POR DEFECTO
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Mensaje por defecto para una regla fallida. Recibe el valor para elegir el
     * texto adecuado (p.ej. longitud vs. valor numérico en min/max).
     */
    public static function message(string $name, string $field, ?string $param, mixed $value = null): string
    {
        return match ($name) {
            'required'         => "El campo '{$field}' es obligatorio.",
            'string'           => "El campo '{$field}' debe ser texto.",
            'int', 'integer'   => "El campo '{$field}' debe ser un número entero.",
            'numeric', 'float' => "El campo '{$field}' debe ser un valor numérico.",
            'boolean', 'bool'  => "El campo '{$field}' debe ser verdadero o falso.",
            'email'            => "El campo '{$field}' debe ser un email válido.",
            'url'              => "El campo '{$field}' debe ser una URL válida.",
            'array'            => "El campo '{$field}' debe ser un arreglo.",
            'min'              => match (true) {
                \is_array($value)  => "El campo '{$field}' debe tener al menos {$param} elementos.",
                \is_string($value) => "El campo '{$field}' debe tener al menos {$param} caracteres.",
                default            => "El campo '{$field}' debe ser mayor o igual a {$param}.",
            },
            'max'              => match (true) {
                \is_array($value)  => "El campo '{$field}' no puede tener más de {$param} elementos.",
                \is_string($value) => "El campo '{$field}' no puede tener más de {$param} caracteres.",
                default            => "El campo '{$field}' debe ser menor o igual a {$param}.",
            },
            'in'               => "El campo '{$field}' debe ser uno de: {$param}.",
            'regex'            => "El campo '{$field}' no cumple el formato requerido.",
            'no_html'          => "El campo '{$field}' no puede contener los signos < o > ni caracteres de control.",
            'confirmed'        => "El campo '{$field}' no coincide con su confirmación.",
            'date'             => "El campo '{$field}' debe ser una fecha válida" . ($param ? " con formato {$param}." : "."),
            default            => "El campo '{$field}' no es válido.",
        };
    }
}

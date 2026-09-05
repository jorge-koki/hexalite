<?php

declare(strict_types=1);

namespace HexaLite\Support;

use InvalidArgumentException;

/**
 * Exporta un valor como código PHP válido en UNA sola línea.
 *
 * Es el reemplazo de `var_export()` para los archivos de caché que el framework
 * genera y luego carga con `require` (rutas compiladas y metadata del contenedor).
 * `var_export()` produce una línea por elemento: una app con 200 rutas acaba con
 * un archivo de decenas de miles de líneas para un array que se reconstruye igual
 * escrito de corrido.
 *
 * Qué se gana, siendo honestos: el archivo pesa bastante menos y el parseo en frío
 * es más barato. Con OPcache caliente da igual —lo que se sirve son los opcodes ya
 * compilados—, así que la ganancia está en el primer arranque de cada worker y en
 * los despliegues donde OPcache está desactivado.
 *
 * Qué NO se pierde: el resultado es código PHP normal, así que `require` lo carga
 * exactamente igual que antes y OPcache lo compila igual. No es un formato propio.
 *
 * Soporta arrays, strings, enteros, flotantes, booleanos y null — que es todo lo
 * que puede haber en esos cachés. Cualquier otra cosa lanza, en vez de escribir un
 * archivo que reventaría al hacerle `require`: mejor quedarse sin caché que con uno
 * corrupto.
 *
 * @internal Detalle de implementación del caché. No forma parte de la API estable.
 */
final class PhpExporter
{
    /**
     * @throws InvalidArgumentException Si el valor contiene algo no exportable
     *                                  (objetos, recursos, closures).
     */
    public static function export(mixed $value): string
    {
        if (\is_array($value)) {
            return self::exportArray($value);
        }

        if (\is_string($value)) {
            return self::exportString($value);
        }

        if ($value === null) {
            return 'NULL';
        }

        if (\is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (\is_int($value)) {
            // PHP_INT_MIN como literal NO vuelve a ser un entero: el parser lee un
            // menos unario aplicado a 9223372036854775808, que ya no cabe en int y
            // se convierte en float. Se emite como resta, igual que hace var_export().
            return $value === \PHP_INT_MIN
                ? '(-' . \PHP_INT_MAX . '-1)'
                : (string) $value;
        }

        // Los flotantes se delegan a var_export(), que con la precisión por defecto
        // de PHP (serialize_precision=-1) garantiza el viaje de ida y vuelta exacto.
        if (\is_float($value)) {
            return \var_export($value, true);
        }

        throw new InvalidArgumentException(
            'PhpExporter no puede exportar un valor de tipo ' . \get_debug_type($value) . '.'
        );
    }

    private static function exportArray(array $value): string
    {
        // En una lista (claves 0..n correlativas) las claves se omiten: al volver a
        // cargarlo PHP las regenera idénticas, y el archivo pesa menos.
        $isList = \array_is_list($value);

        $parts = [];
        foreach ($value as $key => $item) {
            $parts[] = $isList
                ? self::export($item)
                : self::export($key) . '=>' . self::export($item);
        }

        return '[' . \implode(',', $parts) . ']';
    }

    private static function exportString(string $value): string
    {
        // Sin caracteres de control basta con comillas simples: dentro de ellas solo
        // la barra invertida y la propia comilla tienen significado. Es la forma más
        // corta y la que menos puede salir mal.
        if (\preg_match('/[\x00-\x1F\x7F]/', $value) !== 1) {
            return "'" . \strtr($value, ['\\' => '\\\\', "'" => "\\'"]) . "'";
        }

        // Con caracteres de control hay que ir a comillas dobles y escaparlos, o el
        // literal partiría la línea — justo lo que se quiere evitar. Se escapa el '$'
        // para matar la interpolación (y con ella '{$...}', que deja de serlo en
        // cuanto el '$' va escapado).
        $escaped = \strtr($value, ['\\' => '\\\\', '"' => '\\"', '$' => '\\$']);

        // \xNN consume como mucho dos dígitos hexadecimales, y aquí siempre se emiten
        // dos, así que un carácter hexadecimal literal a continuación nunca se pega
        // al escape.
        return '"' . \preg_replace_callback(
            '/[\x00-\x1F\x7F]/',
            static fn(array $m): string => \sprintf('\\x%02X', \ord($m[0])),
            $escaped
        ) . '"';
    }
}

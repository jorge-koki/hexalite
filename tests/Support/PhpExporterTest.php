<?php

declare(strict_types=1);

namespace HexaLite\Tests\Support;

use HexaLite\Support\PhpExporter;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PhpExporterTest extends TestCase
{
    /**
     * Escribe el código exportado a un archivo y lo carga con `require`, que es
     * exactamente lo que hacen Router y Container con sus cachés. Comprobar el
     * string exportado no bastaría: lo que importa es que PHP lo vuelva a leer
     * como el MISMO valor.
     */
    private function roundTrip(mixed $value): mixed
    {
        $file = \sys_get_temp_dir() . '/hexalite_export_' . \uniqid('', true) . '.php';
        \file_put_contents($file, "<?php\nreturn " . PhpExporter::export($value) . ";\n");

        try {
            return require $file;
        } finally {
            @\unlink($file);
        }
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function valoresProvider(): array
    {
        return [
            'array vacío'          => [[]],
            'lista'                => [[1, 2, 3]],
            'null, true y false'   => [['a' => null, 'b' => true, 'c' => false]],
            'flotantes'            => [[2.0, 0.1, 1e100, 1 / 3]],
            'enteros límite'       => [[\PHP_INT_MAX, \PHP_INT_MIN, 0, -1]],
            'clase con barras'     => [['controller' => 'App\\Controllers\\HelloController']],
            'comillas simples'     => [["it's", "a'b'c"]],
            'barras invertidas'    => [['C:\\ruta', '\\\\', 'regex\\d+']],
            'regex compilado'      => [['~^/user/([^/]+)$~', '~^(?:/a/([^/]+)(*MARK:0))$~']],
            'saltos de línea'      => [["a\nb", "r\rn", "tab\there"]],
            'caracteres de control'=> [["\x00nul", "\x1Fus", "\x7Fdel", "\e[31m"]],
            'dólar y llaves'       => [['$var', '{$interp}', '${x}']],
            'comillas dobles'      => [['di "hola"', '"']],
            'utf-8'                => [['ñandú', '日本語', '🚀']],
            'claves mixtas'        => [[0 => 'a', 'x' => 'b', 5 => 'c']],
            'lista con hueco'      => [[0 => 'a', 2 => 'c']],
            'anidamiento profundo' => [['a' => ['b' => ['c' => ['d' => [1, 2, ['e' => null]]]]]]],
            'hex tras un escape'   => [["\x0A" . 'BEEF', "\x01" . '23']],
        ];
    }

    #[DataProvider('valoresProvider')]
    public function testElValorSobreviveAlViajeDeIdaYVuelta(mixed $value): void
    {
        $this->assertSame($value, $this->roundTrip($value));
    }

    #[DataProvider('valoresProvider')]
    public function testElResultadoCabeEnUnaSolaLinea(mixed $value): void
    {
        $this->assertStringNotContainsString("\n", PhpExporter::export($value));
        $this->assertStringNotContainsString("\r", PhpExporter::export($value));
    }

    /**
     * PHP_INT_MIN como literal se parsearía como menos unario sobre un número que
     * ya no cabe en int, y volvería como float. Tiene que seguir siendo int.
     */
    public function testPhpIntMinSigueSiendoEntero(): void
    {
        $back = $this->roundTrip(['min' => \PHP_INT_MIN]);

        $this->assertIsInt($back['min']);
        $this->assertSame(\PHP_INT_MIN, $back['min']);
    }

    /** En una lista las claves se omiten, porque PHP las regenera igual. */
    public function testLasListasSeExportanSinClaves(): void
    {
        $this->assertSame("['a','b','c']", PhpExporter::export(['a', 'b', 'c']));
    }

    /** Si las claves NO son una lista correlativa, hay que conservarlas. */
    public function testLasClavesNoCorrelativasSeConservan(): void
    {
        $this->assertSame("[0=>'a',2=>'c']", PhpExporter::export([0 => 'a', 2 => 'c']));
    }

    /**
     * Mejor quedarse sin caché que escribir uno que reventaría al hacerle require:
     * un valor no exportable tiene que lanzar, no colarse.
     */
    public function testLanzaConValoresNoExportables(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PhpExporter::export(['servicio' => new \stdClass()]);
    }

    public function testLanzaConClosures(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PhpExporter::export([static fn() => 1]);
    }

    /** El resultado tiene que coincidir con lo que devolvía var_export(). */
    public function testCoincideConVarExportEnUnaTablaDeRutas(): void
    {
        $rutas = [
            'GET' => [
                'static' => [
                    '/' => [
                        'controller'      => 'App\\Controllers\\HelloController',
                        'method'          => 'index',
                        'middlewares'     => [],
                        'guards'          => [],
                        'time'            => null,
                        'param_names'     => [],
                        'param_signature' => [],
                        'regex'           => null,
                    ],
                ],
                'dynamic' => [
                    [
                        'controller'  => 'App\\Controllers\\HelloController',
                        'method'      => 'hola',
                        'param_names' => ['nombre'],
                        'regex'       => '~^/hola/([^/]+)$~',
                        'path'        => '/hola/{nombre}',
                        'time'        => 2.0,
                    ],
                ],
                'combined_regex' => '~^(?:/hola/([^/]+)(*MARK:0))$~',
            ],
        ];

        $this->assertSame($rutas, $this->roundTrip($rutas));
    }
}

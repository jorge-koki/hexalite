# API — Logging y seguridad

[← Índice de la referencia](README.md)

- [`LoggerFactory`](#loggerfactory) · [`SimpleLogger`](#simplelogger) · [`EncryptionService`](#encryptionservice)

---

## LoggerFactory

`HexaLite\Logging\LoggerFactory` — `src/Logging/LoggerFactory.php`

```php
static create(string $logDir, string $channel = 'app'): LoggerInterface
```

Devuelve un logger PSR-3. Si `monolog/monolog` está instalado, un Monolog con un
`StreamHandler` hacia `{$logDir}/app.log` a nivel `Debug`; si no, el
[`SimpleLogger`](#simplelogger) incluido.

El directorio se pasa por parámetro y se crea si no existe: **el core no asume ninguna
constante global ni layout de carpetas de tu aplicación.** El `$channel` solo lo usa
Monolog.

---

## SimpleLogger

`HexaLite\Logging\SimpleLogger` — `src/Logging/SimpleLogger.php`

```php
__construct(string $logDir)
```

Implementación PSR-3 con cero dependencias. Escribe en `{$logDir}/app.log` con la
forma:

```
[2026-09-01 21:42:07] [ERROR] El mensaje {"clave":"valor"}
```

Implementa los ocho niveles PSR-3 (`emergency`, `alert`, `critical`, `error`,
`warning`, `notice`, `info`, `debug`) más `log($level, $message, $context = [])`.

Dos detalles del formato:

- **Los CR/LF del mensaje se escapan** a `\r` y `\n` literales, para que nadie pueda
  forjar líneas de log inyectando saltos.
- **El contexto se serializa a JSON** y se adjunta a la línea, con
  `JSON_PARTIAL_OUTPUT_ON_ERROR` para que un valor no serializable no haga
  desaparecer el registro entero.

Si el fichero no se puede escribir, cae a `error_log()`.

---

## EncryptionService

`HexaLite\Security\EncryptionService` — `src/Security/EncryptionService.php`

Cifrado simétrico autenticado (XSalsa20-Poly1305) vía libsodium, para guardar
**secretos en reposo**: la contraseña SMTP del usuario, el token de una integración,
el número de cuenta que la ley obliga a no dejar en claro.

**No es para contraseñas de acceso** — esas se hashean con
[`PasswordPolicy`](auth.md#passwordpolicy), no se cifran.

```php
__construct(?string $secret = null)
encrypt(string $plain): string     // base64(nonce . ciphertext)
decrypt(string $encoded): ?string  // texto plano, o null
```

```php
$enc = new EncryptionService();
$fila = $enc->encrypt($appPassword);
$pwd  = $enc->decrypt($fila) ?? throw new RuntimeException('secreto corrupto');
```

### La clave

Si no se pasa `$secret`, se lee de `APP_ENCRYPTION_KEY` y, como respaldo, de
`JWT_SECRET`. Se **deriva** con BLAKE2b a 32 bytes, así que cualquier cadena sirve —
no hace falta que mida exactamente 32 bytes— y el secreto original nunca se usa tal
cual.

El constructor lanza `RuntimeException` si falta `ext-sodium` o si no hay ningún
secreto configurado. Genera uno con:

```sh
php -r 'echo bin2hex(random_bytes(32));'
```

**Dentro de un mismo entorno la clave no puede cambiar mientras existan datos
cifrados con ella**, o dejarían de poder descifrarse. Rotarla exige descifrar con la
vieja y volver a cifrar con la nueva.

### Garantías

**«Autenticado» significa que un texto cifrado manipulado no se descifra a basura:
falla.** `decrypt()` devuelve `null` —nunca lanza, nunca devuelve datos alterados— si
el dato no es base64 válido, viene truncado, fue manipulado o se cifró con otra clave.
Quien llama decide si eso es un error fatal o un secreto que hay que volver a pedir.

**El nonce es aleatorio en cada llamada**, así que cifrar dos veces el mismo texto da
resultados distintos: nadie puede deducir por igualdad de columnas que dos usuarios
comparten el mismo secreto.

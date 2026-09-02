<?php

declare(strict_types=1);

namespace HexaLite\Auth\Exceptions;

use RuntimeException;

/** Base de los errores de token. Permite un solo catch para "el token no sirve". */
class TokenException extends RuntimeException
{
}

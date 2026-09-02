<?php

declare(strict_types=1);

namespace HexaLite\Auth\Exceptions;

/** Token malformado, con firma inválida, algoritmo inesperado o revocado. */
final class InvalidTokenException extends TokenException
{
}

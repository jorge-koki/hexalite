<?php

declare(strict_types=1);

namespace HexaLite\Auth\Exceptions;

/**
 * El token está bien FORMADO y bien FIRMADO, pero venció (`exp`) o aún no es
 * válido (`nbf`). Se distingue de {@see InvalidTokenException} porque el flujo de
 * refresh reacciona distinto: expirado → renovar; inválido → 401 sin más.
 */
final class ExpiredTokenException extends TokenException
{
}

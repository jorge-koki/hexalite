<?php

declare(strict_types=1);

namespace HexaLite\Auth\Middlewares;

use HexaLite\Auth\Services\JwtPayload;
use HexaLite\Auth\Services\SessionCookies;
use HexaLite\Auth\Services\TokenRevocationService;
use HexaLite\Auth\Services\TokenService;
use HexaLite\Http\Domain\MiddlewareInterface;
use HexaLite\Http\Request;
use HexaLite\Http\Response;
use Throwable;

/**
 * Exige una sesión válida y publica al usuario en `$request->setAttribute('user')`,
 * de donde lo leen `$request->user()`, los guards de rol/permiso y tus controladores.
 *
 * Acepta la credencial por cookie `access_token` (web) o por cabecera
 * `Authorization: Bearer` (móvil, integraciones). Si el acceso venció pero hay un
 * `refresh_token` válido en cookie, lo RENUEVA de forma transparente y adjunta la
 * cookie nueva a la respuesta: el usuario no ve un 401 cada quince minutos.
 *
 * Por eso el usuario se publica en la petición y los controladores deben leerlo
 * de ahí en vez de volver a decodificar la cookie: la cookie del request sigue
 * siendo la vieja (la nueva va en la RESPUESTA), y re-decodificarla lanzaría un
 * "token expirado" desde el controlador.
 */
final class AuthMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly TokenService $tokens,
        private readonly TokenRevocationService $revocation,
        private readonly SessionCookies $cookies,
    ) {
    }

    public function handle(Request $request, callable $next): Response
    {
        $payload     = null;
        $newAccess   = null;
        $remembered  = false;
        $accessToken = $request->cookie('access_token') ?: $request->getBearerToken();

        if ($accessToken) {
            try {
                $payload = $this->tokens->validateAccessToken($accessToken);

                // Un token con firma buena pero emitido ANTES de un cambio de
                // contraseña (o de un logout) está revocado: se trata como vencido
                // y se intenta refrescar; el refresh viejo también caerá.
                if (!$this->revocation->isTokenValid($payload->sub, $payload->iat)) {
                    $payload = null;
                }
            } catch (Throwable) {
                $payload = null;
            }
        }

        if ($payload === null) {
            [$payload, $newAccess, $remembered] = $this->tryRefresh($request);
        }

        if ($payload === null) {
            return Response::json([
                'error'   => 'Unauthorized',
                'message' => 'Tu sesión no es válida o expiró.',
            ], 401);
        }

        $request->setAttribute('user', $payload);

        $response = $next($request);

        if ($newAccess !== null) {
            // Sin "recordarme", la cookie renovada sigue siendo de SESIÓN
            // (expires = 0). Poner aquí una caducidad fija convertiría un «no me
            // recuerdes» en 30 días a la primera renovación, en silencio.
            $response = $this->cookies->attachAccess(
                $response,
                $newAccess,
                $this->tokens->createCsrfToken(),
                $remembered ? time() + $this->tokens->refreshTtl(true) : 0,
            );
        }

        return $response;
    }

    /**
     * Intenta acuñar un access token nuevo a partir del refresh en cookie.
     *
     * @return array{0: JwtPayload|null, 1: string|null, 2: bool}
     *         [payload, accessToken nuevo, si la sesión era "recordada"]
     */
    private function tryRefresh(Request $request): array
    {
        $refreshToken = $request->cookie('refresh_token');
        if (!$refreshToken) {
            return [null, null, false];
        }

        try {
            $refresh = $this->tokens->validateRefreshToken($refreshToken);

            if (!$this->revocation->isTokenValid($refresh->sub, $refresh->iat)) {
                return [null, null, false];
            }

            // Los claims propios de la app (tenant, plan…) viajan también en el
            // refresh, así que el acceso nuevo nace con la misma información que
            // el original y nada se pierde al renovar.
            $accessToken = $this->tokens->createAccessToken(
                $refresh->sub,
                TokenService::customClaims($refresh),
            );

            return [
                $this->tokens->validateAccessToken($accessToken),
                $accessToken,
                TokenService::isRemembered($refresh),
            ];
        } catch (Throwable) {
            return [null, null, false];
        }
    }
}

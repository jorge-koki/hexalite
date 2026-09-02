<?php

declare(strict_types=1);

namespace HexaLite\Tests\Auth;

use HexaLite\Auth\AuthConfig;
use HexaLite\Auth\AuthServiceProvider;
use HexaLite\Auth\Domain\UserRepositoryInterface;
use HexaLite\Auth\Services\TokenService;
use HexaLite\Container\Container;
use HexaLite\Http\Request;
use HexaLite\Http\Response;
use HexaLite\Http\Router;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Recorrido funcional de extremo a extremo del kit de autenticación: entra por
 * el Router (rutas por atributos, DTOs, guards, middlewares) y sale por la
 * Response, igual que una petición real. Lo único simulado es la persistencia
 * ({@see InMemoryUserRepository}) y el correo (LogMailer, el que se elige solo
 * cuando no hay proveedor configurado).
 */
final class AuthFlowTest extends TestCase
{
    private Container $container;
    private Router $router;
    private InMemoryUserRepository $users;

    /** @var array<string, string> Cookies que el "navegador" del test arrastra. */
    private array $cookies = [];

    protected function setUp(): void
    {
        foreach (['JWT_SECRET', 'JWT_REFRESH_SECRET', 'APP_ENV', 'APP_NAME', 'FRONTEND_URL'] as $key) {
            putenv($key);
            unset($_ENV[$key]);
        }
        putenv('JWT_SECRET=clave-de-acceso-para-tests');
        putenv('JWT_REFRESH_SECRET=clave-de-refresco-para-tests');
        putenv('FRONTEND_URL=https://app.test');

        $this->cookies = [];
        $this->users   = new InMemoryUserRepository();

        $this->container = new Container();
        // Sin logger, el LogMailer escribe a error_log y ensucia la salida del test.
        $this->container->set(LoggerInterface::class, new NullLogger());
        (new AuthServiceProvider($this->container))->register();

        // Se sustituye SOLO la persistencia: es exactamente lo que haría una app
        // con su propia tabla de usuarios.
        $this->container->set(UserRepositoryInterface::class, $this->users);
        $this->container->set(AuthConfig::class, new AuthConfig(
            appName: 'Test',
            frontendUrl: 'https://app.test',
        ));

        $this->router = new Router(
            AuthServiceProvider::controllers(),
            $this->container,
            '',
            false,
            AuthServiceProvider::guardAttributes(),
        );
    }

    protected function tearDown(): void
    {
        $_COOKIE = [];
    }

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Lanza una petición contra el router arrastrando las cookies de la
     * respuesta anterior, igual que haría un navegador.
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function request(string $method, string $path, array $body = [], array $headers = []): array
    {
        $server = [
            'REQUEST_METHOD' => $method,
            'REQUEST_URI'    => $path,
            'REMOTE_ADDR'    => '127.0.0.1',
        ];
        foreach ($headers as $name => $value) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }

        $_COOKIE = $this->cookies;

        $response = $this->router->handle(new Request([], $body, $server, [], $this->container));

        $this->rememberCookies($response);

        $decoded = json_decode((string) $response->getContent(), true);

        return [$response->getStatusCode(), is_array($decoded) ? $decoded : []];
    }

    /** Opciones con las que se emitió una cookie en la última respuesta. */
    private array $lastCookieOptions = [];

    private function rememberCookies(Response $response): void
    {
        $this->lastCookieOptions = $response->getCookies();

        foreach ($response->getCookies() as $name => $cookie) {
            $expired = $cookie['expires'] !== 0 && $cookie['expires'] < time();

            if ($cookie['value'] === '' || $expired) {
                unset($this->cookies[$name]);
                continue;
            }
            $this->cookies[$name] = $cookie['value'];
        }
    }

    /** Cabecera CSRF que el front debe reenviar en las escrituras. */
    private function csrf(): array
    {
        return ['X-CSRF-TOKEN' => $this->cookies['csrf_token'] ?? ''];
    }

    private function registerUser(string $email = 'ada@example.com'): array
    {
        return $this->request('POST', '/auth/register', [
            'name'                  => 'Ada Lovelace',
            'email'                 => $email,
            'password'              => 'Contra5eña!',
            'password_confirmation' => 'Contra5eña!',
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ALTA
    // ─────────────────────────────────────────────────────────────────────────

    public function testRegisterCreatesUserAndOpensSession(): void
    {
        [$status, $body] = $this->registerUser();

        $this->assertSame(201, $status);
        $this->assertSame('registered', $body['message']);
        $this->assertSame('ada@example.com', $body['user']['email']);
        $this->assertFalse($body['user']['email_verified']);

        // Auto-login: las tres cookies de sesión salen en la misma respuesta.
        $this->assertArrayHasKey('access_token', $this->cookies);
        $this->assertArrayHasKey('refresh_token', $this->cookies);
        $this->assertArrayHasKey('csrf_token', $this->cookies);

        // Fuera de producción el enlace se expone para poder probar el flujo.
        $this->assertStringStartsWith('https://app.test/auth/verify-email?token=', $body['verification_url']);
    }

    public function testRegisterNeverLeaksThePasswordHash(): void
    {
        [, $body] = $this->registerUser();

        $this->assertArrayNotHasKey('password', $body['user']);
    }

    public function testDuplicateEmailIsRejected(): void
    {
        $this->registerUser();
        $this->cookies = [];

        [$status, $body] = $this->registerUser();

        $this->assertSame(409, $status);
        $this->assertSame('email_taken', $body['code']);
    }

    public function testWeakPasswordIsRejected(): void
    {
        [$status, $body] = $this->request('POST', '/auth/register', [
            'name'                  => 'Ada',
            'email'                 => 'ada@example.com',
            'password'              => 'floja',
            'password_confirmation' => 'floja',
        ]);

        $this->assertSame(422, $status);
        $this->assertSame('weak_password', $body['code']);
    }

    public function testMismatchedConfirmationIsRejected(): void
    {
        [$status, $body] = $this->request('POST', '/auth/register', [
            'name'                  => 'Ada',
            'email'                 => 'ada@example.com',
            'password'              => 'Contra5eña!',
            'password_confirmation' => 'Otra5eña!',
        ]);

        $this->assertSame(422, $status);
        $this->assertArrayHasKey('password', $body['details']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // INICIO DE SESIÓN
    // ─────────────────────────────────────────────────────────────────────────

    public function testLoginWithCorrectCredentials(): void
    {
        $this->registerUser();
        $this->cookies = [];

        [$status, $body] = $this->request('POST', '/auth/login', [
            'email'       => 'ada@example.com',
            'password'    => 'Contra5eña!',
            'remember_me' => true,
        ]);

        $this->assertSame(200, $status);
        $this->assertSame('logged_in', $body['message']);
        $this->assertSame(['user'], $body['user']['roles']);
        $this->assertContains('users:read', $body['user']['permissions']);
    }

    public function testLoginIsCaseInsensitiveOnEmail(): void
    {
        $this->registerUser();
        $this->cookies = [];

        [$status] = $this->request('POST', '/auth/login', [
            'email'    => 'ADA@Example.com',
            'password' => 'Contra5eña!',
        ]);

        $this->assertSame(200, $status);
    }

    public function testUnknownEmailAndWrongPasswordAreIndistinguishable(): void
    {
        $this->registerUser();
        $this->cookies = [];

        [$statusA, $bodyA] = $this->request('POST', '/auth/login', [
            'email'    => 'ada@example.com',
            'password' => 'incorrecta',
        ]);
        [$statusB, $bodyB] = $this->request('POST', '/auth/login', [
            'email'    => 'nadie@example.com',
            'password' => 'incorrecta',
        ]);

        // Mismo código, mismo mensaje: el endpoint no delata qué correos existen.
        $this->assertSame(401, $statusA);
        $this->assertSame($statusA, $statusB);
        $this->assertSame($bodyA['message'], $bodyB['message']);
        $this->assertSame($bodyA['code'], $bodyB['code']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // SESIÓN
    // ─────────────────────────────────────────────────────────────────────────

    public function testMeRequiresASession(): void
    {
        [$status] = $this->request('GET', '/auth/me');

        $this->assertSame(401, $status);
    }

    public function testMeReturnsTheAuthenticatedUser(): void
    {
        $this->registerUser();

        [$status, $body] = $this->request('GET', '/auth/me');

        $this->assertSame(200, $status);
        $this->assertSame('ada@example.com', $body['user']['email']);
        $this->assertSame(['user'], $body['user']['roles']);
    }

    public function testWriteWithoutCsrfHeaderIsRejected(): void
    {
        $this->registerUser();

        [$status] = $this->request('PATCH', '/auth/me', ['name' => 'Ada L.']);

        $this->assertSame(403, $status);
    }

    public function testWriteWithCsrfHeaderSucceeds(): void
    {
        $this->registerUser();

        [$status, $body] = $this->request('PATCH', '/auth/me', ['name' => 'Ada L.'], $this->csrf());

        $this->assertSame(200, $status);
        $this->assertSame('Ada L.', $body['user']['name']);
    }

    public function testWithoutRememberMeTheCookiesDieWithTheBrowser(): void
    {
        $this->registerUser();
        $this->cookies = [];

        $this->request('POST', '/auth/login', [
            'email'       => 'ada@example.com',
            'password'    => 'Contra5eña!',
            'remember_me' => false,
        ]);

        // expires = 0 → cookie de sesión.
        $this->assertSame(0, $this->lastCookieOptions['refresh_token']['expires']);
        $this->assertSame(0, $this->lastCookieOptions['access_token']['expires']);
    }

    public function testRememberMeMakesTheCookiesPersistent(): void
    {
        $this->registerUser();
        $this->cookies = [];

        $this->request('POST', '/auth/login', [
            'email'       => 'ada@example.com',
            'password'    => 'Contra5eña!',
            'remember_me' => true,
        ]);

        $this->assertGreaterThan(time(), $this->lastCookieOptions['refresh_token']['expires']);
    }

    public function testRefreshDoesNotPromoteASessionCookieToPersistent(): void
    {
        $this->registerUser();
        $this->cookies = [];

        $this->request('POST', '/auth/login', [
            'email'       => 'ada@example.com',
            'password'    => 'Contra5eña!',
            'remember_me' => false,
        ]);

        $this->request('POST', '/auth/refresh');

        // Renovar la sesión no puede convertir un «no me recuerdes» en 30 días.
        $this->assertSame(0, $this->lastCookieOptions['access_token']['expires']);
    }

    public function testExpiredAccessTokenIsRefreshedTransparently(): void
    {
        $this->registerUser();

        // Se sustituye el access token por uno ya vencido, dejando intacto el
        // refresh: es lo que ocurre a los 15 minutos de uso normal.
        $expired = (new TokenService('clave-de-acceso-para-tests', 'clave-de-refresco-para-tests', accessTtl: -600))
            ->createAccessToken(1);
        $this->cookies['access_token'] = $expired;

        [$status, $body] = $this->request('GET', '/auth/me');

        $this->assertSame(200, $status);
        $this->assertSame('ada@example.com', $body['user']['email']);
        // Y el navegador se queda con un access token nuevo, sin haber visto un 401.
        $this->assertNotSame($expired, $this->cookies['access_token']);
    }

    public function testLogoutClearsCookiesAndRevokesTheSession(): void
    {
        $this->registerUser();
        $accessToken = $this->cookies['access_token'];

        [$status] = $this->request('POST', '/auth/logout', [], $this->csrf());
        $this->assertSame(200, $status);
        $this->assertArrayNotHasKey('access_token', $this->cookies);

        // El token que el usuario tenía ya no vale aunque no haya expirado:
        // el logout movió la marca de revocación.
        $this->cookies['access_token'] = $accessToken;
        [$statusAfter] = $this->request('GET', '/auth/me');

        $this->assertSame(401, $statusAfter);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // VERIFICACIÓN DE CORREO
    // ─────────────────────────────────────────────────────────────────────────

    public function testVerifyEmailWithTheTokenFromTheLink(): void
    {
        [, $body] = $this->registerUser();
        $token    = $this->tokenFromUrl($body['verification_url']);

        [$status, $verified] = $this->request('POST', '/auth/verify-email', ['token' => $token]);

        $this->assertSame(200, $status);
        $this->assertSame('email_verified', $verified['message']);

        [, $me] = $this->request('GET', '/auth/me');
        $this->assertTrue($me['user']['email_verified']);
    }

    public function testVerificationTokenIsSingleUse(): void
    {
        [, $body] = $this->registerUser();
        $token    = $this->tokenFromUrl($body['verification_url']);

        $this->request('POST', '/auth/verify-email', ['token' => $token]);
        [$status, $second] = $this->request('POST', '/auth/verify-email', ['token' => $token]);

        $this->assertSame(422, $status);
        $this->assertSame('invalid_verification_token', $second['code']);
    }

    public function testResendVerificationIssuesANewToken(): void
    {
        [, $body] = $this->registerUser();
        $first    = $this->tokenFromUrl($body['verification_url']);

        [$status, $resent] = $this->request('POST', '/auth/resend-verification', [], $this->csrf());

        $this->assertSame(200, $status);
        $this->assertSame('sent', $resent['message']);
        $this->assertNotSame($first, $this->tokenFromUrl($resent['verification_url']));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CONTRASEÑA
    // ─────────────────────────────────────────────────────────────────────────

    public function testForgotPasswordAnswersTheSameForUnknownEmails(): void
    {
        $this->registerUser();
        $this->cookies = [];

        [$statusKnown, $bodyKnown]     = $this->request('POST', '/auth/forgot-password', ['email' => 'ada@example.com']);
        [$statusUnknown, $bodyUnknown] = $this->request('POST', '/auth/forgot-password', ['email' => 'nadie@example.com']);

        $this->assertSame(200, $statusKnown);
        $this->assertSame(200, $statusUnknown);
        $this->assertSame($bodyKnown, $bodyUnknown);
    }

    public function testResetPasswordWithTheEmailedToken(): void
    {
        $this->registerUser();
        $this->cookies = [];

        $this->request('POST', '/auth/forgot-password', ['email' => 'ada@example.com']);
        $token = (string) $this->users->rows[1]['password_reset_token'];

        [$status, $body] = $this->request('POST', '/auth/reset-password', [
            'token'                 => $token,
            'password'              => 'NuevaContra5!',
            'password_confirmation' => 'NuevaContra5!',
        ]);

        $this->assertSame(200, $status);
        $this->assertSame('password_reset', $body['message']);

        // La contraseña vieja ya no sirve y la nueva sí.
        [$oldStatus] = $this->request('POST', '/auth/login', [
            'email' => 'ada@example.com', 'password' => 'Contra5eña!',
        ]);
        $this->assertSame(401, $oldStatus);

        [$newStatus] = $this->request('POST', '/auth/login', [
            'email' => 'ada@example.com', 'password' => 'NuevaContra5!',
        ]);
        $this->assertSame(200, $newStatus);
    }

    public function testResetTokenIsSingleUse(): void
    {
        $this->registerUser();
        $this->cookies = [];

        $this->request('POST', '/auth/forgot-password', ['email' => 'ada@example.com']);
        $token = (string) $this->users->rows[1]['password_reset_token'];

        $payload = [
            'token'                 => $token,
            'password'              => 'NuevaContra5!',
            'password_confirmation' => 'NuevaContra5!',
        ];

        $this->request('POST', '/auth/reset-password', $payload);
        [$status] = $this->request('POST', '/auth/reset-password', $payload);

        $this->assertSame(422, $status);
    }

    public function testChangePasswordKeepsThisDeviceLoggedIn(): void
    {
        $this->registerUser();

        [$status, $body] = $this->request('POST', '/auth/change-password', [
            'current_password' => 'Contra5eña!',
            'new_password'     => 'OtraContra5!',
        ], $this->csrf());

        $this->assertSame(200, $status);
        $this->assertSame('password_changed', $body['message']);

        // Este dispositivo recibió credenciales nuevas y sigue dentro...
        [$meStatus] = $this->request('GET', '/auth/me');
        $this->assertSame(200, $meStatus);
    }

    public function testChangePasswordRevokesTheOtherSessions(): void
    {
        $this->registerUser();
        $otherDeviceToken = $this->cookies['access_token'];

        $this->request('POST', '/auth/change-password', [
            'current_password' => 'Contra5eña!',
            'new_password'     => 'OtraContra5!',
        ], $this->csrf());

        // Un token emitido ANTES del cambio deja de valer, aunque no haya expirado.
        $this->cookies = ['access_token' => $otherDeviceToken];
        [$status] = $this->request('GET', '/auth/me');

        $this->assertSame(401, $status);
    }

    public function testChangePasswordRejectsAWrongCurrentPassword(): void
    {
        $this->registerUser();

        [$status, $body] = $this->request('POST', '/auth/change-password', [
            'current_password' => 'no-es-esta',
            'new_password'     => 'OtraContra5!',
        ], $this->csrf());

        $this->assertSame(422, $status);
        $this->assertSame('wrong_current_password', $body['code']);
    }

    // ─────────────────────────────────────────────────────────────────────────

    public function testHealthEndpoint(): void
    {
        [$status, $body] = $this->request('GET', '/auth/health');

        $this->assertSame(200, $status);
        $this->assertSame('ok', $body['status']);
    }

    public function testThrottleReturns429AfterTheLimit(): void
    {
        // /auth/register está limitado a 5 por minuto.
        for ($i = 0; $i < 5; $i++) {
            $this->cookies = [];
            $this->registerUser("user$i@example.com");
        }

        [$status] = $this->registerUser('sexto@example.com');

        $this->assertSame(429, $status);
    }

    private function tokenFromUrl(string $url): string
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return (string) ($query['token'] ?? '');
    }
}

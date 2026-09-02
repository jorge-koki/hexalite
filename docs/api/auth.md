# API — Autenticación

[← Índice de la referencia](README.md)

El kit de autenticación completo: 45 clases repartidas en configuración, dominio,
casos de uso, servicios, guards, middlewares y atributos. Para el contrato con el
cliente (cookies, CSRF, respuestas) mira [`../FRONTEND.md`](../FRONTEND.md).

- [`AuthConfig`](#authconfig) · [`AuthServiceProvider`](#authserviceprovider)
- **Dominio** — [`User`](#user) · [`UserRepositoryInterface`](#userrepositoryinterface) · [`PdoUserRepository`](#pdouserrepository)
- **Aplicación** — [`AuthSession`](#authsession) · [DTOs de entrada](#dtos-de-entrada) · [Casos de uso](#casos-de-uso)
- **Servicios** — [`TokenService`](#tokenservice) · [`TokenRevocationService`](#tokenrevocationservice) · [`JwtPayload`](#jwtpayload) · [`SessionCookies`](#sessioncookies) · [`PasswordPolicy`](#passwordpolicy) · [`GoogleIdTokenService`](#googleidtokenservice) · [`RecaptchaService`](#recaptchaservice) · [`EmailTemplates`](#emailtemplates)
- **Middlewares** — [`AuthMiddleware`](#authmiddleware) · [`CsrfMiddleware`](#csrfmiddleware) · [`SecurityHeadersMiddleware`](#securityheadersmiddleware)
- **Guards y atributos** — [`Roles`](#roles) · [`Permission`](#permission) · [`Throttle`](#throttle) · [`RoleGuard`](#roleguard) · [`PermissionGuard`](#permissionguard) · [`ThrottleGuard`](#throttleguard)
- [`AuthController`](#authcontroller) y sus [endpoints](#endpoints)
- [Excepciones de token](#excepciones-de-token)

---

## AuthConfig

`HexaLite\Auth\AuthConfig` — `src/Auth/AuthConfig.php`

Configuración del módulo en un solo objeto inmutable (`final readonly`).

Existe porque los ajustes de sesión —sobre todo el `domain` y el `SameSite` de las
cookies— tienen que ser **idénticos** en login, registro, refresh y logout. Si cada
endpoint los arma por su cuenta, tarde o temprano uno emite la cookie con un dominio y
otro intenta borrarla con otro, y el síntoma que ve el usuario es «no puedo cerrar
sesión» sin ninguna pista en los logs.

```php
__construct(
    public string $appName              = 'HexaLite',
    public string $frontendUrl          = 'http://localhost:5173',
    public string $cookieDomain         = '',
    public bool   $secureCookies        = false,
    public string $sameSite             = 'Lax',
    public bool   $isProduction         = false,
    public int    $resetTtlMinutes      = 60,
    public bool   $requireVerifiedEmail = false,
    public array  $defaultRoles         = ['user'],
    public string $superRole            = '',
    public string $verifyEmailPath      = '/auth/verify-email',
    public string $resetPasswordPath    = '/auth/reset-password',
)
```

| Propiedad | Significado |
|---|---|
| `$appName` | Marca que aparece en los correos. |
| `$frontendUrl` | Base del front para los enlaces de los correos. |
| `$cookieDomain` | `''` = *host-only*. En producción con subdominios: `'.midominio.com'`. |
| `$secureCookies` | Cookies solo por HTTPS. |
| `$sameSite` | `'Lax'`, `'None'` o `'Strict'`. Front y API en dominios distintos → `'None'`. |
| `$isProduction` | Fuera de producción se exponen los enlaces de verificación y reset en la respuesta. |
| `$resetTtlMinutes` | Vigencia del enlace de restablecimiento. |
| `$requireVerifiedEmail` | Si es `true`, el login exige el correo ya verificado (verificación **dura**). |
| `$defaultRoles` | Roles que se asignan a quien se registra por su cuenta. |
| `$superRole` | Rol con acceso total: salta la comprobación de permisos puntuales. `''` lo desactiva. |

### Métodos

```php
static fromEnv(): AuthConfig
verificationUrl(string $token): string
passwordResetUrl(string $token): string
```

`fromEnv()` lee `APP_NAME`, `APP_ENV`, `FRONTEND_URL` (o `FRONTEND_ORIGINS`),
`COOKIE_DOMAIN`, `COOKIE_SECURE`, `COOKIE_SAMESITE`, `AUTH_RESET_TTL_MINUTES`,
`AUTH_REQUIRE_VERIFIED_EMAIL`, `AUTH_DEFAULT_ROLES` y `AUTH_SUPER_ROLE`.

**En producción los valores por defecto de las cookies se endurecen solos**
(`Secure` + `SameSite=None`), que es lo que necesita un front alojado en otro dominio.

---

## AuthServiceProvider

`HexaLite\Auth\AuthServiceProvider` — `src/Auth/AuthServiceProvider.php`
· implementa [`ProviderInterface`](contenedor.md#providerinterface)

Registra el kit completo con **cero configuración**: se lee todo del entorno y cada
pieza se activa sola si sus variables están llenas (PostgreSQL/MySQL, Redis,
SMTP/Resend, Google, reCAPTCHA).

```php
__construct(Container $container, array $config = [])

static controllers(): array       // controladores para el Router
static guardAttributes(): array   // atributos-guard para el Router
register(): void
boot(): void
```

`$config` admite tres sobrescrituras: `'connection'` (nombre de la conexión de base de
datos), `'table_prefix'` (prefijo de las tablas de auth) y `'auth'` (una instancia de
[`AuthConfig`](#authconfig) ya construida).

```php
$container = new Container($cache, $isProduction);
(new AuthServiceProvider($container))->register();

$router = new Router(
    controllers:     [...AuthServiceProvider::controllers(), MiControlador::class],
    container:       $container,
    cacheFile:       $rutasCache,
    isProduction:    $isProduction,
    guardAttributes: AuthServiceProvider::guardAttributes(),
);
```

### Qué registra

`DatabaseManager`, `DatabaseInterface`, `CacheInterface`, `EncryptionService`,
`MailerInterface`, `AuthConfig`, `PasswordPolicy`, `EmailTemplates`, `TokenService`,
`SessionCookies`, `UserRepositoryInterface`, `TokenRevocationService`,
`PermissionGuard`, `ThrottleGuard`, `GoogleIdTokenService` y `RecaptchaService`.

**Todo con `setFactory`, así que nada se instancia hasta que alguien lo pide:** una
petición que no toca la autenticación no abre la conexión a la base de datos ni la de
Redis.

Cualquier enlace se puede sobrescribir **después** registrando el tuyo — el contenedor
se queda con el último. Lo habitual es reemplazar
[`UserRepositoryInterface`](#userrepositoryinterface) para apuntar a tu propia tabla de
usuarios.

---

## User

`HexaLite\Auth\Domain\User` — `src/Auth/Domain/User.php` · `final readonly`

Usuario autenticable. Los campos son los que exige el flujo (identidad, credencial,
estado y verificación) y nada más. **Todo lo específico de tu negocio —empresa, plan,
avatar, preferencias— vive en tus tablas, no aquí.**

```php
__construct(
    public ?int $id,
    public string $name,
    public string $email,
    public ?string $password = null,
    public string $status = 'A',
    public ?DateTimeImmutable $email_verified_at = null,
    public ?DateTimeImmutable $last_login_at = null,
    public ?string $google_sub = null,
    public ?DateTimeImmutable $created_at = null,
    public ?DateTimeImmutable $updated_at = null,
)
```

`$password` es el hash bcrypt/argon; `null` significa que la cuenta solo entra por un
proveedor externo. `$status` es `'A'` (activa) o `'I'` (dada de baja).

| Método | Devuelve |
|---|---|
| `static fromArray(array $row): User` | Hidrata desde una fila de la base de datos. |
| `toArray(): array` | Representación pública. |
| `isActive(): bool` | `$status === 'A'`. |
| `hasVerifiedEmail(): bool` | ¿Tiene `email_verified_at`? |
| `verifyPassword(string $plain): bool` | Compara contra el hash. |

**`toArray()` nunca incluye el hash de la contraseña** — es el array que acaba en el
JSON de `/auth/me` y `/auth/login`. Expone `id`, `name`, `email`, `status`,
`email_verified`, `has_password`, `google_linked`, `last_login_at` y `created_at`.

**`verifyPassword()` devuelve `false` en una cuenta sin contraseña, no una excepción.**
Así el login la trata como cualquier credencial incorrecta y la respuesta no delata
qué tipo de cuenta es.

---

## UserRepositoryInterface

`HexaLite\Auth\Domain\UserRepositoryInterface` — `src/Auth/Domain/UserRepositoryInterface.php`

Puerto de persistencia del módulo. **Si tu tabla de usuarios ya existe y es distinta,
implementa esta interfaz contra ella y enlázala en el contenedor:** el resto del módulo
—casos de uso, controlador, middlewares— sigue funcionando sin tocarlo.

### Lectura

| Método | Devuelve |
|---|---|
| `findByEmail(string $email): ?User` | Búsqueda por correo, insensible a mayúsculas. |
| `findById(int $id): ?User` | Por id. |
| `findByGoogleSub(string $googleSub): ?User` | Por el identificador estable de Google (claim `sub`). |
| `emailExists(string $email): bool` | ¿Está ese correo dado de alta? |
| `health(): array` | Ping de conectividad para el endpoint de salud. |

### Alta y credenciales

```php
create(
    string $name, string $email, ?string $passwordHash,
    ?string $verificationToken = null, bool $emailVerified = false,
    ?string $googleSub = null, array $roles = [],
): User

updatePassword(int $userId, string $passwordHash): bool   // false si no existe
updateProfile(int $userId, string $name): ?User
touchLastLogin(int $userId): void                          // best-effort
```

En `create()`, `$passwordHash` a `null` es una cuenta solo-Google;
`$verificationToken` a `null` significa que nace verificada; los roles que no existan
se ignoran.

`touchLastLogin()` es *best-effort* a propósito: **nunca debe tumbar el login**.

### Verificación de correo

| Método | Devuelve |
|---|---|
| `verifyEmailByToken(string $token): ?array` | `{id, email, name}`, o `null` si el token no existe. |
| `refreshVerificationToken(int $userId, string $token): ?array` | `{email, name, already_verified}`, o `null`. |

El token de verificación es de **un solo uso**: se limpia al consumirlo.

### Restablecimiento de contraseña

| Método | Devuelve |
|---|---|
| `createPasswordResetToken(int $userId, string $token, int $ttlMinutes): ?array` | `{email, name}`, o `null`. |
| `resetPasswordByToken(string $token, string $passwordHash): ?array` | `{id, email, name}`, o `null` si era inválido o venció. |

`resetPasswordByToken()` comprueba existencia y expiración **en la misma consulta que
actualiza**, para que no haya ventana entre validar y aplicar.

### Revocación de sesiones

```php
getTokensValidAfter(int $userId): int          // epoch, 0 = sin marca
setTokensValidAfter(int $userId, int $epoch): void
```

### Google

```php
linkGoogle(int $userId, string $googleSub, bool $markEmailVerified): bool
unlinkGoogle(int $userId): void
```

`linkGoogle()` devuelve `false` si ese Google ya está enlazado a **otro** usuario.
`$markEmailVerified` solo debe ser `true` cuando el correo de Google es el mismo del
usuario: entonces Google ya lo comprobó.

### Roles y permisos

```php
getRoleNames(int $userId): array            // string[]
getEffectivePermissions(int $userId): array // string[], unión de los de todos sus roles
```

---

## PdoUserRepository

`HexaLite\Auth\Infrastructure\Persistence\PdoUserRepository` — `src/Auth/Infrastructure/Persistence/PdoUserRepository.php`

Implementación de [`UserRepositoryInterface`](#userrepositoryinterface) sobre
PostgreSQL o MySQL con una sola clase.

```php
__construct(
    DatabaseInterface $db,
    string $driver = 'pgsql',
    ?string $tablePrefix = null,
)
```

`$driver` es `'pgsql'` o `'mysql'`. `$tablePrefix` fuerza el prefijo; por defecto
`'auth.'` en PostgreSQL y `'auth_'` en MySQL, que es lo que crean las migraciones de
[`src/Auth/migrations/`](../../src/Auth/migrations/).

**Las dos únicas diferencias reales entre motores están aisladas:**

- **Nombres de tabla** — PostgreSQL usa el esquema `auth.users`; MySQL no tiene
  esquemas dentro de una base, así que ahí son `auth_users`.
- **Recuperar el id de un `INSERT`** — `RETURNING` en PostgreSQL, `lastInsertId()` en
  MySQL.

Todo lo demás se escribió a propósito en SQL que ambos entienden: las marcas de tiempo
usan `CURRENT_TIMESTAMP` y las expiraciones se calculan en PHP y viajan como
parámetro, en vez de usar aritmética de intervalos (que sí difiere entre motores).

---

## AuthSession

`HexaLite\Auth\Application\AuthSession` — `src/Auth/Application/AuthSession.php` · `final readonly`

Resultado de un login o registro correcto: el usuario y las tres credenciales que el
controlador convierte en cookies. **Los casos de uso no tocan HTTP**; se limitan a
devolver esto.

```php
__construct(
    public User $user,
    public string $accessToken,
    public string $refreshToken,
    public string $csrfToken,
    public bool $remember = false,
    public array $roles = [],
    public array $permissions = [],
)

toArray(): array   // cuerpo JSON estándar de /auth/login y /auth/register
```

---

## DTOs de entrada

`HexaLite\Auth\Application\Dtos\*` — `src/Auth/Application/Dtos/`

Subclases de [`Dtos`](dto-validacion.md#dtos), todas `final`, con propiedades
`readonly`. El Router las hidrata y valida automáticamente.

| DTO | Propiedades |
|---|---|
| `RegisterDto` | `name`, `email`, `password`, `password_confirmation`, `?recaptcha_token` |
| `LoginDto` | `email`, `password`, `?remember_me`, `?recaptcha_token` |
| `GoogleAuthDto` | `id_token` |
| `VerifyEmailDto` | `token` |
| `ForgotPasswordDto` | `email`, `?recaptcha_token` |
| `ResetPasswordDto` | `token`, `password`, `password_confirmation` |
| `ChangePasswordDto` | `current_password`, `new_password` |
| `UpdateProfileDto` | `name` |

**`RegisterDto` no valida la fuerza de la contraseña.** Eso lo hace
[`PasswordPolicy`](#passwordpolicy), que es configurable por entorno; duplicar la regla
en el DTO haría que las dos acabaran divergiendo.

`UpdateProfileDto` solo lleva el nombre: el correo identifica la cuenta y cambiarlo
exige re-verificar, así que va por otro flujo.

---

## Casos de uso

`HexaLite\Auth\Application\UseCases\*` — `src/Auth/Application/UseCases/`

Todos son `final readonly` y exponen un único `execute()`. No tocan HTTP: reciben
datos, devuelven datos.

### Login

```php
execute(LoginDto $input, ?string $clientIp = null): AuthSession
```

Todo el diseño gira alrededor de **no filtrar qué correos están registrados**: mismo
código, mismo mensaje y —gracias a un hash de descarte— tiempos de respuesta parecidos
tanto si la cuenta no existe como si la contraseña es incorrecta.

### Register

```php
execute(RegisterDto $input, ?string $clientIp = null): array
// {session: AuthSession, verification_url: string}
```

Crea el usuario, manda el correo de verificación y **deja la sesión ya iniciada**, que
es lo que espera cualquiera que acaba de rellenar un formulario de registro.

La verificación es **blanda por defecto**: el usuario entra y usa la app mientras el
front le muestra un aviso. Se endurece con `AUTH_REQUIRE_VERIFIED_EMAIL=true`.

`verification_url` se devuelve siempre para poder registrarlo y para los tests, pero
el controlador solo lo expone al cliente fuera de producción.

### AuthenticateWithGoogle

```php
execute(string $idToken): array   // {session: AuthSession, created: bool}
```

Un solo endpoint que entra o da de alta según haga falta. Resuelve en este orden:

1. **Por `google_sub`** — la vía principal. El correo de una cuenta de Google puede
   cambiar; el `sub` no.
2. **Por correo** — primera vez que ese usuario usa Google. Se enlaza al vuelo y se da
   el correo por verificado: Google ya lo comprobó.
3. **No existe** — se crea la cuenta sin contraseña y ya verificada.

Sin reCAPTCHA a propósito: el reto de Google ya es la barrera anti-bots.

### VerifyEmail

```php
execute(string $token): bool   // true si el correo quedó verificado
```

El token es de un solo uso: al aplicarlo se borra, así que reabrir el enlace ya no
vale — y por eso el controlador responde 422 en el segundo intento, no un 500.

### ResendVerification

```php
execute(int $userId): array   // {already_verified: bool, verification_url: ?string}
```

Reenvía con un token **nuevo** (el anterior deja de valer). Conviene limitarlo con
[`#[Throttle]`](#throttle): es un endpoint que manda correos.

### RequestPasswordReset

```php
execute(string $email, ?string $recaptchaToken = null, ?string $clientIp = null): void
```

Anti-enumeración de principio a fin: **se sale en silencio en todos los caminos**
(correo vacío, cuenta inexistente, envío fallido) y el controlador responde siempre lo
mismo. Un atacante no puede usar este endpoint para averiguar qué correos tienen
cuenta.

Ponle un `#[Throttle]` agresivo: cada llamada legítima manda un correo.

### ResetPassword

```php
execute(string $token, string $newPassword): bool   // false si no existía o venció
```

### ChangePassword

```php
execute(int $userId, string $currentPassword, string $newPassword): int
```

Verifica la contraseña actual y **revoca todas las sesiones** al terminar. Devuelve la
marca de revocación: el controlador acuña las credenciales nuevas de *este* dispositivo
con ese `iat` para que queden por encima de la marca y la sesión actual no se caiga.
Los demás dispositivos sí salen, que es exactamente lo que quieres si cambias la
contraseña por sospecha.

### LinkGoogleAccount / UnlinkGoogleAccount

```php
LinkGoogleAccount::execute(int $userId, string $idToken): array  // {email: string}
UnlinkGoogleAccount::execute(int $userId): void
```

`LinkGoogleAccount` cubre el caso que el enlace automático por correo no alcanza: que
el correo de la cuenta y el de Google sean distintos.

---

## TokenService

`HexaLite\Auth\Services\TokenService` — `src/Auth/Services/TokenService.php`

Emisión y verificación de JWT (HS256) **sin dependencias**: `hash_hmac` y `hash_equals`
bastan para HMAC-SHA256, y así el kit no arrastra un paquete de terceros.

```php
__construct(
    ?string $secret        = null,   // JWT_SECRET
    ?string $refreshSecret = null,   // JWT_REFRESH_SECRET
    int     $accessTtl     = 900,    // 15 min
    int     $refreshTtl    = 604800, // 7 días
    int     $rememberTtl   = 2592000,// 30 días
    string  $issuer        = 'hexalite',
    int     $leeway        = 30,     // tolerancia de reloj entre servidores
)
```

### Modelo de dos tokens

- **Access** — corto, viaja en cada petición.
- **Refresh** — largo (o `rememberTtl` con «recordarme»), firmado con **otra clave** y
  con `jti` propio. Solo sirve para acuñar accesos nuevos.

Se firman con claves distintas a propósito: **si se filtra la de acceso, el atacante no
puede fabricarse un refresh** y quedarse dentro para siempre.

### Métodos

| Método | Devuelve |
|---|---|
| `createAccessToken(int $userId, array $claims = [], ?int $issuedAt = null): string` | Access token. |
| `createRefreshToken(int $userId, bool $remember = false, array $claims = [], ?int $issuedAt = null): string` | Refresh token. |
| `createCsrfToken(): string` | Token CSRF para el patrón *double-submit*. |
| `validateAccessToken(string $token): JwtPayload` | Lanza `ExpiredTokenException` o `InvalidTokenException`. |
| `validateRefreshToken(string $token): JwtPayload` | Ídem. |
| `static customClaims(JwtPayload $payload): array` | Los claims propios de tu app, sin los reservados. |
| `static isRemembered(JwtPayload $payload): bool` | ¿El refresh se emitió con «recordarme»? |
| `accessTtl(): int` / `refreshTtl(bool $remember = false): int` | Los TTL configurados. |

`$claims` son claims extra de tu aplicación (tenant, plan…). **Nunca metas datos
sensibles: el payload de un JWT va firmado, no cifrado.**

`$issuedAt` fija un `iat` explícito. Se usa al re-acuñar credenciales justo después de
revocar sesiones, para que el token nuevo quede por encima de la marca de revocación.

`customClaims()` existe para arrastrar tus claims al token nuevo al renovar la sesión:
si se perdieran, el usuario acabaría con un access token sin su tenant ni su plan.

La constante `RESERVED_CLAIMS` lista los claims estándar que `customClaims()` filtra.

---

## TokenRevocationService

`HexaLite\Auth\Services\TokenRevocationService` — `src/Auth/Services/TokenRevocationService.php`

Revocación de sesiones por **marca de agua** (`tokens_valid_after`).

```php
__construct(UserRepositoryInterface $users, ?CacheInterface $cache = null)

isTokenValid(int $userId, int $issuedAt): bool
invalidateUser(int $userId): int   // devuelve la marca fijada
```

Un JWT es válido solo si su `iat` es ≥ la marca del usuario. Al cambiar o restablecer
la contraseña —o al cerrar sesión— la marca se pone en «ahora», y todos los tokens
emitidos antes mueren al instante. **Es la forma barata de revocar sin llevar una
lista negra de `jti`: una comparación de enteros.**

La base de datos es la fuente de verdad; el caché (Redis, si está) evita consultarla en
cada petición autenticada. Sin caché funciona igual, solo con una consulta más.

**La marca es `time() + 1`, no `time()`.** El `iat` de un JWT tiene resolución de
segundos, así que con `time()` un token emitido en el mismo segundo que el logout
sobreviviría a la revocación. Con `+1` muere todo lo emitido hasta ese segundo
inclusive.

`invalidateUser()` devuelve la marca precisamente para que quien deba seguir dentro
—el dispositivo desde el que se cambió la contraseña— acuñe sus tokens nuevos con ese
valor como `issuedAt`.

---

## JwtPayload

`HexaLite\Auth\Services\JwtPayload` — `src/Auth/Services/JwtPayload.php` · `final readonly`

Claims ya validados de un JWT. Es lo que el middleware publica en
`$request->setAttribute('user', ...)` y lo que devuelve `$request->user()`.

```php
__construct(
    public int $sub,              // id del usuario
    public int $iat = 0,
    public int $exp = 0,
    public string $iss = '',
    public string $jti = '',
    public string $type = 'access',   // 'access' | 'refresh'
    public array $claims = [],        // payload completo
)

static fromClaims(array $claims): JwtPayload
get(string $claim, mixed $default = null): mixed
isAccessToken(): bool
isRefreshToken(): bool
```

Los claims estándar están tipados; **cualquier claim extra que hayas metido en el token
sigue accesible con `get()`**.

---

## SessionCookies

`HexaLite\Auth\Services\SessionCookies` — `src/Auth/Services/SessionCookies.php` · `final readonly`

Emite y borra el trío de cookies de sesión. **Todos los endpoints de auth pasan por
aquí para que las opciones sean siempre las mismas:** una cookie emitida con `domain` y
borrada sin él no se borra, y el usuario se queda con una sesión que el logout no mata.

```php
__construct(AuthConfig $config)

attach(Response $response, string $accessToken, string $refreshToken,
       string $csrfToken, int $accessExpires = 0, int $refreshExpires = 0): Response
attachAccess(Response $response, string $accessToken, string $csrfToken, int $expires = 0): Response
clear(Response $response): Response
```

| Cookie | Flags | Para qué |
|---|---|---|
| `access_token` | HttpOnly | Credencial de cada petición. |
| `refresh_token` | HttpOnly | Solo para renovar el acceso. |
| `csrf_token` | **legible** | El front la lee y la reenvía en `X-CSRF-TOKEN`. |

`csrf_token` **no es HttpOnly a propósito**: si lo fuera, el JavaScript legítimo no
podría copiarla, que es justo lo que exige el patrón *double-submit*.

Los `$expires` son epoch; `0` = cookie de sesión. `attachAccess()` renueva solo el
acceso tras un refresh; `clear()` expira las tres con las mismas opciones con que se
emitieron.

---

## PasswordPolicy

`HexaLite\Auth\Services\PasswordPolicy` — `src/Auth/Services/PasswordPolicy.php`

La política de contraseñas **en un solo sitio**, para que el registro, el reset y el
cambio no puedan divergir — que es como acaban colándose contraseñas que un flujo
acepta y otro no.

```php
__construct(
    int  $minLength        = 8,
    int  $maxLength        = 250,
    bool $requireMixedCase = true,
    bool $requireNumber    = true,
    bool $requireSymbol    = true,
)

static fromEnv(): PasswordPolicy
check(string $password): ?string   // primer incumplimiento, o null
passes(string $password): bool
assert(string $password): void     // lanza HttpException 422
hash(string $password): string
toRegex(): string
```

`fromEnv()` lee `PASSWORD_MIN_LENGTH`, `PASSWORD_MAX_LENGTH`,
`PASSWORD_REQUIRE_MIXED_CASE`, `PASSWORD_REQUIRE_NUMBER` y `PASSWORD_REQUIRE_SYMBOL`.

`hash()` usa **el algoritmo por defecto de PHP** (hoy bcrypt, mañana lo que PHP
considere mejor), para no quedarse anclado a uno concreto.

`toRegex()` devuelve la expresión regular equivalente, para validar lo mismo en los
DTOs y en el front sin reescribir la regla.

---

## GoogleIdTokenService

`HexaLite\Auth\Services\GoogleIdTokenService` — `src/Auth/Services/GoogleIdTokenService.php`

Verifica el ID token de «Continuar con Google» **sin librerías de JWT**: la firma RS256
se comprueba con `openssl_verify` contra las llaves públicas que Google publica en su
JWKS.

```php
__construct(string $clientId = '', ?string $cacheDir = null)

isConfigured(): bool
verify(string $idToken): array
// {sub: string, email: string, name: string, picture: ?string}
```

`$clientId` es `GOOGLE_CLIENT_ID` (es público). `$cacheDir` es dónde guardar el JWKS;
`null` usa el directorio temporal del sistema.

### Verificar de verdad son cinco cosas, y las cinco importan

1. **Firma** válida contra el JWKS de Google. Sin esto, cualquiera fabrica un token
   diciendo ser quien quiera.
2. **`aud` == nuestro client ID.** Si no, vale un token emitido para otra app: un
   atacante podría reusar el de cualquier sitio donde el usuario haya entrado.
3. **`iss`** de Google.
4. **`exp`** vigente.
5. **`email_verified` == true.** Google también emite tokens de cuentas con el correo
   sin confirmar; sin este chequeo alguien reclama un correo ajeno.

**Nunca se confía en lo que mande el navegador** (correo, nombre): todo sale del token
ya verificado. `verify()` lanza [`HttpException`](http.md#httpexception) si el token no
sirve — nunca devuelve datos a medias.

---

## RecaptchaService

`HexaLite\Auth\Services\RecaptchaService` — `src/Auth/Services/RecaptchaService.php` · `final readonly`

```php
__construct(string $secret = '', float $minimumScore = 0.5)

isEnabled(): bool
verify(?string $token, ?string $remoteIp = null): void
```

Verificación de reCAPTCHA v2 y v3 contra Google. `$minimumScore` es el umbral de v3
(0.0–1.0) y se ignora en v2.

Sigue la regla del kit: **sin `RECAPTCHA_SECRET` la verificación se salta en silencio**
y el login y el registro funcionan igual. En cuanto pones el secreto, el captcha pasa a
ser **obligatorio** en los endpoints que lo usan — sin eso, un bot se saltaría el reto
simplemente no enviando el token.

`verify()` lanza `HttpException` 422 si el reto no pasa, y 503 si Google no responde.

---

## EmailTemplates

`HexaLite\Auth\Services\EmailTemplates` — `src/Auth/Services/EmailTemplates.php` · `final readonly`

```php
__construct(string $brand = 'HexaLite', string $accentColor = '#1e293b')

verification(string $url, ?string $name = null): array
passwordReset(string $url, ?string $name = null, int $ttlMinutes = 60): array
passwordChanged(?string $name = null): array
welcome(string $loginUrl, ?string $name = null): array
```

Cada método devuelve `{subject: string, html: string, text: string}`.

El HTML usa tablas y estilos en línea a propósito: **es lo único que renderizan bien
Outlook y Gmail.** Todo lo interpolado se escapa — un nombre de usuario con `<script>`
no debe acabar ejecutándose en el cliente de correo de nadie.

---

## AuthMiddleware

`HexaLite\Auth\Middlewares\AuthMiddleware` — `src/Auth/Middlewares/AuthMiddleware.php`
· implementa [`MiddlewareInterface`](http.md#middlewareinterface)

```php
__construct(TokenService $tokens, TokenRevocationService $revocation, SessionCookies $cookies)
handle(Request $request, callable $next): Response
```

Exige una sesión válida y publica al usuario en `$request->setAttribute('user')`, de
donde lo leen `$request->user()`, los guards de rol y permiso y tus controladores.

Acepta la credencial por cookie `access_token` (web) o por cabecera
`Authorization: Bearer` (móvil, integraciones).

**Si el acceso venció pero hay un `refresh_token` válido en cookie, lo renueva de forma
transparente** y adjunta la cookie nueva a la respuesta: el usuario no ve un 401 cada
quince minutos.

Por eso el usuario se publica en la petición y **los controladores deben leerlo de ahí
en vez de volver a decodificar la cookie**: la cookie del *request* sigue siendo la
vieja —la nueva va en la respuesta— y re-decodificarla lanzaría «token expirado» desde
el controlador.

---

## CsrfMiddleware

`HexaLite\Auth\Middlewares\CsrfMiddleware` — `src/Auth/Middlewares/CsrfMiddleware.php`
· implementa [`MiddlewareInterface`](http.md#middlewareinterface)

```php
__construct(array $exemptPaths = ['/auth/verify-email', '/auth/reset-password'])
handle(Request $request, callable $next): Response
```

Protección CSRF por *double-submit cookie*. En una petición autenticada el navegador
manda siempre la cookie `csrf_token` —también si quien dispara la petición es un sitio
atacante—, pero **ese sitio no puede leerla** para copiarla en `X-CSRF-TOKEN`. Por eso,
cuando la cookie está presente, se exige que la cabecera coincida.

**La exigencia se activa solo si existe la cookie**, es decir, solo si hay sesión. Así
el middleware puede ser global sin romper las rutas públicas (login, registro,
forgot-password), que todavía no tienen sesión que abusar.

Las rutas exentas son aquellas cuyo secreto viaja **en el cuerpo**, no en la cookie: el
token que se mandó por correo. Ahí el double-submit no protege nada —el atacante no
conoce ese token— y en cambio estorba: quien abre el enlace en el navegador donde ya
tiene sesión trae la cookie CSRF, se le exige una cabecera que la pantalla pública no
manda, y el enlace bueno se ve como «no válido».

---

## SecurityHeadersMiddleware

`HexaLite\Auth\Middlewares\SecurityHeadersMiddleware` — `src/Auth/Middlewares/SecurityHeadersMiddleware.php`
· implementa [`MiddlewareInterface`](http.md#middlewareinterface)

```php
__construct(bool $hsts = true, int $hstsMaxAge = 31536000)
handle(Request $request, callable $next): Response
```

Cabeceras de seguridad para respuestas de API (JSON y descargas).

La CSP es deliberadamente cerrada: **una API no carga recursos ni se embebe como
página**, así que `default-src 'none'` no rompe nada y cierra de golpe el clickjacking,
el secuestro de URLs relativas y los plugins. La CSP del *documento* (`script-src`,
`style-src`, `connect-src` de tu SPA) es otra cosa y va en el servidor que sirve el
front, no aquí.

**Activa `$hsts` solo si el dominio va entero por HTTPS**: sobre `http://` no hace nada,
y un subdominio interno sin certificado se vuelve inaccesible durante todo el `max-age`.

---

## Roles

`HexaLite\Auth\Attributes\Roles` — `src/Auth/Attributes/Roles.php` · `final readonly`

```php
__construct(string ...$roles)
public readonly string $guardClass;   // RoleGuard
public readonly array $roles;
public readonly int $priority;
```

Exige que el usuario tenga **al menos uno** de los roles indicados.

```php
#[Roles('admin', 'owner')]
```

Requiere que un middleware de auth haya publicado ya al usuario en la petición: sin
sesión, el guard responde 401 (no 403).

---

## Permission

`HexaLite\Auth\Attributes\Permission` — `src/Auth/Attributes/Permission.php` · `final readonly`

```php
__construct(string ...$permissions)
```

Exige que el usuario tenga **todos** los permisos indicados.

```php
#[Permission('users:write')]
#[Permission('billing:read', 'billing:write')]
```

Cada argumento admite **alternativas separadas por `|`**, de las que basta cumplir una:

```php
#[Permission('ver:vehiculo|ver:cotizacion')]
```

Es lo que hace usable un recurso de referencia al que llegan varios flujos, sin tener
que inventar un permiso nuevo para cada combinación.

Los permisos son más finos que los roles y **sobreviven mejor a los cambios de
organigrama**: al añadir un rol nuevo no hay que volver a tocar los controladores.

---

## Throttle

`HexaLite\Auth\Attributes\Throttle` — `src/Auth/Attributes/Throttle.php` · `final readonly`

```php
__construct(int $limit = 60, int $ttl = 60, int $priority = 5)
```

| Parámetro | Significado |
|---|---|
| `$limit` | Máximo de peticiones dentro de la ventana. |
| `$ttl` | Duración de la ventana, en segundos. |
| `$priority` | Orden de ejecución entre guards (menor = antes). |

```php
#[Throttle(5, 60)]   // 5 peticiones por minuto
```

Registra el atributo en el Router (parámetro `guardAttributes`) para que lo reconozca.
El propio atributo declara qué guard lo ejecuta.

---

## RoleGuard

`HexaLite\Auth\Guards\RoleGuard` — `src/Auth/Guards/RoleGuard.php`
· implementa [`GuardInterface`](http.md#guardinterface)

```php
__construct(UserRepositoryInterface $users)
canActivate(Request $request): bool|Response
```

Deja pasar si el usuario tiene al menos uno de los roles del atributo
[`#[Roles]`](#roles).

---

## PermissionGuard

`HexaLite\Auth\Guards\PermissionGuard` — `src/Auth/Guards/PermissionGuard.php`
· implementa [`GuardInterface`](http.md#guardinterface)

```php
__construct(UserRepositoryInterface $users, string $superRole = '')
canActivate(Request $request): bool|Response
```

Deja pasar solo si el usuario cumple **todos** los requisitos del atributo
[`#[Permission]`](#permission). Los permisos son la unión de los de sus roles más los
asignados directamente.

Cada requisito puede ser un permiso simple o un grupo de alternativas separadas por
`|`.

Con un rol superusuario configurado (`AUTH_SUPER_ROLE`), quien lo tenga pasa sin
revisar permisos puntuales. `$superRole` a `''` desactiva el atajo **y evita la
consulta extra de roles**.

---

## ThrottleGuard

`HexaLite\Auth\Guards\ThrottleGuard` — `src/Auth/Guards/ThrottleGuard.php`
· implementa [`GuardInterface`](http.md#guardinterface)

```php
__construct(?CacheInterface $cache = null)
canActivate(Request $request): bool
```

Rate limit por **IP + ruta**, con ventana fija.

El contador vive en el [`CacheInterface`](cache.md#cacheinterface) inyectado:

- **Con Redis** el límite es global — todos los workers y todas las máquinas comparten
  la cuenta.
- **Con el `ArrayCache` de respaldo** es por proceso y, bajo PHP-FPM, prácticamente
  inservible como defensa. Sirve para no romper en desarrollo, no para protegerte en
  producción. **Si te importa el límite, configura Redis.**

El Router publica los parámetros del atributo como atributos de la petición
(`_guard_limit`, `_guard_ttl`) y lee de vuelta `_throttle_*` para poner las cabeceras
`X-RateLimit-*` en la respuesta 429.

---

## AuthController

`HexaLite\Auth\Infrastructure\Controllers\AuthController` — `src/Auth/Infrastructure/Controllers/AuthController.php`

Todos los endpoints del kit, bajo `#[Controller('/auth')]`. Recibe por constructor los
diez casos de uso más el repositorio, `TokenService`, `TokenRevocationService`,
`SessionCookies`, `GoogleIdTokenService` y `AuthConfig`.

### Contrato con el frontend

- La sesión viaja en cookies HttpOnly, así que el front **siempre** debe llamar con
  `credentials: 'include'`.
- Las peticiones de escritura con sesión iniciada tienen que reenviar la cookie
  `csrf_token` en la cabecera `X-CSRF-TOKEN`.
- **Nadie tiene que gestionar el refresco:** [`AuthMiddleware`](#authmiddleware) renueva
  el access token de forma transparente mientras el refresh siga vivo.

El detalle completo está en [`../FRONTEND.md`](../FRONTEND.md).

### Endpoints

| Método y ruta | Función | Protección |
|---|---|---|
| `POST /auth/register` | `registerUser()` | `#[Throttle(5, 60)]` |
| `POST /auth/login` | `loginUser()` | `#[Throttle(10, 60)]` |
| `POST /auth/google` | `loginWithGoogle()` | `#[Throttle(10, 60)]` |
| `POST /auth/logout` | `logout()` | — |
| `POST /auth/refresh` | `refresh()` | `#[Throttle(30, 60)]` |
| `GET /auth/me` | `me()` | `AuthMiddleware` |
| `PATCH /auth/me` | `updateMe()` | `AuthMiddleware` + `CsrfMiddleware` |
| `POST /auth/verify-email` | `verify()` | `#[Throttle(10, 60)]` |
| `POST /auth/resend-verification` | `resend()` | `AuthMiddleware` + `CsrfMiddleware` + `#[Throttle(3, 300)]` |
| `POST /auth/forgot-password` | `forgotPassword()` | `#[Throttle(3, 300)]` |
| `POST /auth/reset-password` | `reset()` | `#[Throttle(10, 60)]` |
| `POST /auth/change-password` | `changeMyPassword()` | `AuthMiddleware` + `CsrfMiddleware` |
| `POST /auth/me/google` | `link()` | `AuthMiddleware` + `CsrfMiddleware` + `#[Throttle(10, 60)]` |
| `DELETE /auth/me/google` | `unlink()` | `AuthMiddleware` + `CsrfMiddleware` |
| `GET /auth/health` | `health()` | `#[Throttle(6, 60)]` |

Notas de comportamiento:

- **`POST /auth/google`** devuelve un campo `created` que le dice al front si debe
  llevar al usuario al onboarding.
- **`POST /auth/refresh`** existe para clientes que quieran refrescar a propósito; en
  el flujo normal del navegador no hace falta llamarlo.
- **`POST /auth/forgot-password` responde 200 siempre**, exista o no el correo.
  Cualquier otra cosa —un 404, un mensaje distinto— convertiría este endpoint en un
  detector de cuentas registradas.
- **`POST /auth/verify-email` y `POST /auth/reset-password`** son públicos: los llaman
  las pantallas que abren los enlaces del correo.

---

## Excepciones de token

`HexaLite\Auth\Exceptions\*` — `src/Auth/Exceptions/`

| Excepción | Cuándo |
|---|---|
| `TokenException` | Base de las tres. Permite un solo `catch` para «el token no sirve». |
| `ExpiredTokenException` | El token está bien formado y bien firmado, pero venció (`exp`) o aún no es válido (`nbf`). |
| `InvalidTokenException` | Malformado, con firma inválida, algoritmo inesperado o revocado. |

Se distinguen porque **el flujo de refresh reacciona distinto**: expirado → renovar;
inválido → 401 sin más.

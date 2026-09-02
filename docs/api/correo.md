# API — Correo

[← Índice de la referencia](README.md)

- [`MailerInterface`](#mailerinterface) · [`MailerFactory`](#mailerfactory) · [`SmtpMailer`](#smtpmailer) · [`ResendMailer`](#resendmailer) · [`LogMailer`](#logmailer)

---

## MailerInterface

`HexaLite\Mail\MailerInterface` — `src/Mail/MailerInterface.php`

```php
send(string $to, string $subject, string $html, ?string $text = null): void
isConfigured(): bool
```

| Parámetro | Para qué |
|---|---|
| `$to` | Destinatario. |
| `$subject` | Asunto. |
| `$html` | Cuerpo HTML. |
| `$text` | Alternativa en texto plano. Recomendada: mejora la entregabilidad. |

**Las implementaciones lanzan `RuntimeException` si el envío falla.** Quien llama
decide si eso tumba la operación o solo se registra — en los flujos de autenticación,
un correo que no sale nunca debe tumbar el registro ni delatar si una cuenta existe.

`isConfigured()` dice si el mailer puede entregar de verdad. Un mailer sin
credenciales devuelve `false`.

---

## MailerFactory

`HexaLite\Mail\MailerFactory` — `src/Mail/MailerFactory.php`

```php
static fromEnv(?LoggerInterface $logger = null): MailerInterface
```

| Configuración | Mailer que devuelve |
|---|---|
| `RESEND_API_KEY` | [`ResendMailer`](#resendmailer) |
| `MAIL_HOST` | [`SmtpMailer`](#smtpmailer) |
| Nada de lo anterior | [`LogMailer`](#logmailer) |

`MAIL_MAILER=smtp\|resend\|log` fuerza uno concreto y salta la deducción.

Variables comunes: `MAIL_FROM_EMAIL`, `MAIL_FROM_NAME` (con `RESEND_FROM_EMAIL` y
`RESEND_FROM_NAME` como alias). SMTP añade `MAIL_PORT`, `MAIL_USERNAME`,
`MAIL_PASSWORD`, `MAIL_ENCRYPTION`.

---

## SmtpMailer

`HexaLite\Mail\SmtpMailer` — `src/Mail/SmtpMailer.php`

Cliente SMTP mínimo escrito sobre sockets nativos, **sin dependencias**.

```php
__construct(
    string $host,
    int    $port       = 587,
    string $username   = '',
    string $password   = '',
    string $encryption = 'tls',
    string $fromEmail  = '',
    string $fromName   = '',
    int    $timeout    = 20,
)
```

`$encryption`: `'tls'` (STARTTLS, puerto 587), `'ssl'` (SMTPS, 465) o `'none'`.

Cubre lo que necesita un correo transaccional: STARTTLS o SMTPS, `AUTH LOGIN`/`PLAIN`
y un cuerpo `multipart/alternative` (texto + HTML) con las cabeceras codificadas para
acentos.

Si tu aplicación ya usa PHPMailer o Symfony Mailer, implementa
[`MailerInterface`](#mailerinterface) con ellos y enlázalo en el contenedor — esta
clase existe para que el kit funcione recién instalado, no para competir con ellos.

`isConfigured()` exige `host` y `fromEmail`.

---

## ResendMailer

`HexaLite\Mail\ResendMailer` — `src/Mail/ResendMailer.php`

Envío por la API HTTP de [Resend](https://resend.com) con cURL, sin el SDK.

```php
__construct(
    string $apiKey,
    string $fromEmail,
    string $fromName = '',
    int    $timeout  = 15,
)
```

Es la opción cómoda cuando no quieres administrar un servidor SMTP: solo hacen falta
una API key y un dominio verificado.

Lanza `RuntimeException` si falta la configuración o si `ext-curl` no está cargada.
`isConfigured()` exige `apiKey` y `fromEmail`.

---

## LogMailer

`HexaLite\Mail\LogMailer` — `src/Mail/LogMailer.php`

```php
__construct(?LoggerInterface $logger = null)
```

Mailer de desarrollo: no envía nada, escribe el correo en el log (o en `error_log` si
no hay logger). Es el que se usa cuando no hay ni SMTP ni Resend configurados, para
que el flujo completo de registro, verificación y restablecimiento funcione recién
clonado el proyecto — el enlace aparece en el log.

**`isConfigured()` devuelve `false` a propósito.** Es la señal para que el arranque
avise en producción de que los correos no se están entregando.

# Contrato para el frontend

Todo lo que necesita saber quien construye el cliente (Vue, React, Svelte, móvil)
para que la autenticación de HexaLite funcione correctamente. Si algo del front
no funciona, el 90 % de las veces es una de las tres reglas de la primera sección.

---

## 1. Las tres reglas que no se pueden saltar

### 1.1 `credentials: 'include'` en TODAS las llamadas

La sesión no viaja en un header que tú controlas: viaja en **cookies HttpOnly**
que el JavaScript no puede leer (a propósito — así un XSS no puede robar el
token). El navegador solo las envía si se lo pides explícitamente.

```js
fetch(`${API}/auth/me`, { credentials: 'include' })
```

Con Axios: `axios.defaults.withCredentials = true`.

**Síntoma si falta:** todo devuelve `401`, aunque acabes de iniciar sesión.

### 1.2 Reenviar el CSRF en las escrituras

Al iniciar sesión recibes una cookie `csrf_token` que **sí** es legible desde
JavaScript. En cada `POST`/`PUT`/`PATCH`/`DELETE` con sesión iniciada tienes que
copiarla a la cabecera `X-CSRF-TOKEN`.

Esa asimetría *es* la protección: un sitio atacante puede provocar que el
navegador envíe la cookie, pero no puede leerla para replicar la cabecera.

```js
const csrf = document.cookie.match(/(?:^|;\s*)csrf_token=([^;]*)/)?.[1] ?? ''

fetch(`${API}/auth/me`, {
  method: 'PATCH',
  credentials: 'include',
  headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
  body: JSON.stringify({ name: 'Ada' }),
})
```

**Síntoma si falta:** `403 {"error": "CSRF token mismatch"}`.

> Las pantallas públicas de **verificar correo** y **restablecer contraseña**
> están exentas: su secreto es el token del enlace, no la cookie.

### 1.3 El backend está en otro origen → configúralo allí

Pon la URL del front en `FRONTEND_ORIGINS` del `.env` de la API. Con eso se
habilitan CORS con credenciales y los enlaces de los correos apuntan a tu front.
`Access-Control-Allow-Origin: *` **no** funciona con cookies: es una regla del
navegador, no una decisión del framework.

---

## 2. Lo que NO tienes que hacer

- **No gestionar el refresco del token.** El access token dura 15 minutos, pero
  el servidor lo renueva solo mientras el refresh siga vivo (30 días con
  "recordarme"). No hay que interceptar 401 ni encolar reintentos.
- **No guardar tokens** en `localStorage` ni en memoria. No los vas a ver: son
  HttpOnly. Si guardas algo, guarda el objeto `user` para pintar la interfaz.
- **No mandar `Authorization: Bearer`** desde el navegador. Existe para clientes
  móviles e integraciones; en la web, la cookie es más segura.

---

## 3. Endpoints

Base: `/auth`. Todo es JSON. Los errores traen `{"message", "code"}`; los de
validación, `{"error": "Validation Failed", "details": {campo: [mensajes]}}`.

### Sesión

| Método | Ruta | Cuerpo | Devuelve |
|---|---|---|---|
| `POST` | `/auth/register` | `name`, `email`, `password`, `password_confirmation`, `recaptcha_token?` | `201` + `user` + cookies |
| `POST` | `/auth/login` | `email`, `password`, `remember_me?`, `recaptcha_token?` | `200` + `user` + cookies |
| `POST` | `/auth/google` | `id_token` | `200`/`201` + `user` + `created` + cookies |
| `POST` | `/auth/logout` | — | `200`, borra las cookies |
| `POST` | `/auth/refresh` | — | `200`, renueva el access token |
| `GET` | `/auth/me` | — | `200` + `user` + `providers` |
| `PATCH` | `/auth/me` | `name` | `200` + `user` |

### Verificación de correo

| Método | Ruta | Cuerpo | Notas |
|---|---|---|---|
| `POST` | `/auth/verify-email` | `token` | Público. Token de un solo uso |
| `POST` | `/auth/resend-verification` | — | Requiere sesión. Máx. 3 cada 5 min |

### Contraseña

| Método | Ruta | Cuerpo | Notas |
|---|---|---|---|
| `POST` | `/auth/forgot-password` | `email`, `recaptcha_token?` | Público. **Siempre 200** |
| `POST` | `/auth/reset-password` | `token`, `password`, `password_confirmation` | Público |
| `POST` | `/auth/change-password` | `current_password`, `new_password` | Requiere sesión |

### Google (con sesión iniciada)

| Método | Ruta | Cuerpo |
|---|---|---|
| `POST` | `/auth/me/google` | `id_token` — vincula |
| `DELETE` | `/auth/me/google` | — desvincula |

### Salud

`GET /auth/health` → `{"status": "ok", "database": {...}}`.

---

## 4. Respuestas

### Login / registro correctos

```json
{
  "message": "logged_in",
  "user": {
    "id": 1,
    "name": "Ada Lovelace",
    "email": "ada@example.com",
    "status": "A",
    "email_verified": false,
    "has_password": true,
    "google_linked": false,
    "last_login_at": "2026-08-02T10:00:00+00:00",
    "created_at": "2026-08-02T09:00:00+00:00",
    "roles": ["user"],
    "permissions": ["users:read"]
  }
}
```

Usa `roles` y `permissions` para mostrar u ocultar menús y botones. **Pintar es
todo lo que hacen**: la autorización de verdad la aplica el servidor con
`#[Roles]` y `#[Permission]`, así que ocultar un botón no protege nada.

`GET /auth/me` añade `providers: { google: true|false }` para saber si mostrar el
botón de Google sin cablear configuración en el front.

### Códigos de error

| HTTP | `code` | Qué mostrar |
|---|---|---|
| 401 | `invalid_credentials` | «Correo o contraseña incorrectos» |
| 403 | `email_not_verified` | Solo con verificación dura activada |
| 403 | `user_inactive` | Cuenta deshabilitada |
| 409 | `email_taken` | «Ese correo ya está registrado» |
| 422 | `weak_password` | El `message` ya trae el motivo exacto |
| 422 | `wrong_current_password` | En el cambio de contraseña |
| 422 | `invalid_verification_token` | Enlace caducado o ya usado → ofrecer reenvío |
| 422 | `invalid_reset_token` | Enlace caducado → volver a pedirlo |
| 429 | — | Rate limit. La cabecera `Retry-After` dice cuántos segundos |

El `message` viene redactado para el usuario final: se puede mostrar tal cual.

---

## 5. Pantallas que tienes que construir

### `/auth/verify-email?token=...`

El enlace del correo apunta aquí. La pantalla lee `token` de la query, lo postea
y muestra el resultado.

```js
const token = new URLSearchParams(location.search).get('token')

const res = await fetch(`${API}/auth/verify-email`, {
  method: 'POST',
  credentials: 'include',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({ token }),
})
// 200 → «Correo verificado». 422 → enlace caducado o ya usado.
```

### `/auth/reset-password?token=...`

Igual, pero con el formulario de contraseña nueva. Manda `token`, `password` y
`password_confirmation`.

> Las rutas de estas dos pantallas se configuran en el backend
> (`AuthConfig::$verifyEmailPath` / `$resetPasswordPath`) si tu front usa otras.

### Aviso de «verifica tu correo»

Por defecto la verificación es **blanda**: el usuario entra igual. Si
`user.email_verified` es `false`, muestra un banner con un botón que llame a
`/auth/resend-verification`.

---

## 6. Un cliente completo, copiable

```js
const API = import.meta.env.VITE_API_URL

function csrfToken() {
  return document.cookie.match(/(?:^|;\s*)csrf_token=([^;]*)/)?.[1] ?? ''
}

export async function api(path, { method = 'GET', body } = {}) {
  const headers = { 'Content-Type': 'application/json' }

  // Las escrituras exigen el double-submit del CSRF.
  if (method !== 'GET') headers['X-CSRF-TOKEN'] = csrfToken()

  const res = await fetch(`${API}${path}`, {
    method,
    headers,
    credentials: 'include',            // sin esto, la sesión no viaja
    body: body ? JSON.stringify(body) : undefined,
  })

  const data = await res.json().catch(() => ({}))

  if (!res.ok) {
    // El backend redacta `message` para el usuario final.
    throw Object.assign(new Error(data.message ?? 'Error de red'), {
      status: res.status,
      code: data.code,
      details: data.details,           // errores de validación por campo
    })
  }

  return data
}

export const auth = {
  register: (payload)  => api('/auth/register', { method: 'POST', body: payload }),
  login:    (payload)  => api('/auth/login',    { method: 'POST', body: payload }),
  logout:   ()         => api('/auth/logout',   { method: 'POST' }),
  me:       ()         => api('/auth/me'),
  google:   (idToken)  => api('/auth/google',   { method: 'POST', body: { id_token: idToken } }),

  forgotPassword: (email)         => api('/auth/forgot-password', { method: 'POST', body: { email } }),
  resetPassword:  (payload)       => api('/auth/reset-password',  { method: 'POST', body: payload }),
  changePassword: (payload)       => api('/auth/change-password', { method: 'POST', body: payload }),
  verifyEmail:    (token)         => api('/auth/verify-email',    { method: 'POST', body: { token } }),
  resendVerification: ()          => api('/auth/resend-verification', { method: 'POST' }),
}
```

### Arranque de la app

```js
// Al cargar: preguntar quién soy. Si hay sesión (o si se puede refrescar sola),
// llega el usuario; si no, un 401 limpio que significa «al login».
try {
  store.user = (await auth.me()).user
} catch (e) {
  if (e.status === 401) router.push('/login')
}
```

---

## 7. «Continuar con Google»

1. En el backend: `GOOGLE_CLIENT_ID` en el `.env`.
2. En el front, carga Google Identity Services y manda el `credential` que
   devuelve —el ID token— a `POST /auth/google`.

```js
google.accounts.id.initialize({
  client_id: import.meta.env.VITE_GOOGLE_CLIENT_ID,
  callback: async ({ credential }) => {
    const { user, created } = await auth.google(credential)
    router.push(created ? '/onboarding' : '/')
  },
})
```

Un solo endpoint entra o da de alta según haga falta; `created` te dice cuál de
las dos cosas pasó. La cuenta creada así nace **sin contraseña** y con el correo
ya verificado (`has_password: false`) — si el usuario quiere una, que use
«olvidé mi contraseña».

---

## 8. Desarrollo local

Con `APP_ENV` distinto de `production`, `/auth/register` y
`/auth/resend-verification` devuelven además `verification_url` en el JSON. Así
puedes probar el flujo completo sin configurar un servidor de correo: abre ese
enlace y ya está. **En producción nunca se devuelve** — es una credencial de un
solo uso.

Si tampoco hay mailer configurado, el correo entero aparece en el log de la API.

---

## 9. Diagnóstico rápido

| Síntoma | Causa casi segura |
|---|---|
| Todo da 401 aunque el login funcionó | Falta `credentials: 'include'` |
| Las escrituras dan 403 | Falta la cabecera `X-CSRF-TOKEN` |
| El navegador bloquea la respuesta | El origen del front no está en `FRONTEND_ORIGINS` |
| Login OK, pero al recargar no hay sesión | En producción faltan `COOKIE_SECURE=1` y `COOKIE_SAMESITE=None`, o `COOKIE_DOMAIN` no cubre ambos subdominios |
| El logout no cierra la sesión | `COOKIE_DOMAIN` cambió entre el login y el logout: la cookie se borra con un dominio distinto al que se emitió |
| Nunca llegan los correos | No hay `MAIL_HOST` ni `RESEND_API_KEY`: mira el log de la API |
| 429 al desarrollar | Rate limit por IP. Espera lo que diga `Retry-After` |

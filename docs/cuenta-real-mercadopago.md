# Cuenta real de Mercado Pago (sandbox y live)

Desde la versión `0.9.0-alpha.1`, la orquestación (`ConfiguredAccounts`) acepta, además del emulador, cuentas de **Mercado Pago Checkout Pro** contra la API real:

| `environment` | Qué es | Dinero |
|---|---|---|
| `emulator` (por defecto) | Payment Emulator Lab en `localhost` (Plaza, Vector, Harbor) | ficticio |
| `sandbox` | Mercado Pago con credenciales de prueba (usuarios de prueba) | ficticio |
| `live` | Mercado Pago de producción | **real** |

El paquete es el mismo en los tres casos: solo cambia la configuración de la cuenta.

## Configuración de la cuenta

```php
'accounts' => [
    'mp-ar' => [
        'driver' => 'mercadopago',
        'environment' => env('PAYMENTS_MP_AR_ENVIRONMENT', 'emulator'),  // emulator | sandbox | live
        'api' => 'checkout_pro',                    // obligatorio fuera del emulador
        'currency' => 'ARS',
        'merchant_id' => env('PAYMENTS_MP_AR_MERCHANT_ID'),      // user id de la cuenta de Mercado Pago
        'configuration_version' => 1,
        'access_token' => env('PAYMENTS_MP_AR_ACCESS_TOKEN'),    // live: APP_USR-…
        'webhook_secret' => env('PAYMENTS_MP_AR_WEBHOOK_SECRET'), // "clave secreta" de Webhooks en Tus integraciones
        'webhook_secret_previous' => env('PAYMENTS_MP_AR_WEBHOOK_SECRET_PREVIOUS', ''),
        'webhook_secret_previous_until' => env('PAYMENTS_MP_AR_WEBHOOK_SECRET_PREVIOUS_UNTIL', ''),
        'statement_descriptor' => env('PAYMENTS_MP_AR_STATEMENT_DESCRIPTOR'), // opcional, ≤ 22 caracteres
        // api_url y checkout_url solo se usan con environment emulator; fuera de él se ignoran.
    ],
],
```

## Reglas (fallan al construir la cuenta, nunca en el primer cobro)

| Regla | Error |
|---|---|
| `environment` distinto de `emulator`, `sandbox` o `live` | `unsupported_account` |
| Fuera del emulador, un driver que no sea `mercadopago` | `unsupported_account` |
| Fuera del emulador, un `api` que no sea `checkout_pro` | `unsupported_live_api` |
| Falta `access_token`, `webhook_secret` o `merchant_id` | `account_not_ready` |
| `live` con un token que no empiece por `APP_USR-` (p. ej. `TEST-…`) | `live_requires_production_credentials` |
| Moneda sin sitio conocido (hoy solo `ARS` → mercadopago.com.ar) | `unsupported_account` |
| `statement_descriptor` con caracteres no admitidos o más de 22 | `invalid_statement_descriptor` |
| Rotación del secreto del webhook sin fin o de más de 7 días | `invalid_webhook_rotation` |

Además:

- **El host no es configurable.** Las llamadas van siempre a `https://api.mercadopago.com`. Una `api_url` o `checkout_url` del emulador que quede en el `.env` se ignora.
- **El comprador solo puede ir a Mercado Pago.**
  - `live` admite `https://www.mercadopago.com.ar`.
  - `sandbox` admite además `https://sandbox.mercadopago.com.ar`.
  - Cualquier otra URL de pago se rechaza con `unsafe_next_action`.
- **Una cuenta sin `environment` sigue las reglas del emulador.** Una URL real no la convierte en cuenta real: da `unsafe_emulator_endpoint`.
- **El entorno forma parte de la identidad de la cuenta.**
  - El historial de `sandbox` nunca se mezcla con el de `live`.
  - El de `orders` (emulador) tampoco se mezcla con el de `checkout_pro`.
  - Si cambias el entorno o la API, la cuenta pasa a ser otra: los pagos anteriores solo se resuelven con su configuración original.

## Por qué solo Checkout Pro

- **Con Checkout Pro**, el comprador paga en la página de Mercado Pago (`init_point`): tarjeta, dinero en cuenta, efectivo, etc. El pago resultante es un pago de la Payments API, que llega por webhook `payment` y se lee con `GET /v1/payments/{id}`.
- **Con la Orders API** hace falta un token de tarjeta generado en la página del comercio (Card Payment Brick). Sin token, solo crea un cupón de pago en efectivo. La orquestación no maneja el Brick, así que en real solo se admite Checkout Pro.

## Qué configurar en Mercado Pago

1. **Credenciales.** En [Tus integraciones](https://www.mercadopago.com.ar/developers/panel/app), dentro de la aplicación:
   - **Credenciales de producción → Access Token** (`APP_USR-…`) va en `access_token`;
   - el **User ID** del vendedor va en `merchant_id`.
2. **Webhooks.** En la misma aplicación, en **Webhooks**:
   - URL de producción: `https://<tu-dominio>/client/payments/webhooks/mp-ar` (la ruta que expone tu aplicación para la cuenta);
   - evento **Pagos**;
   - copia la **clave secreta** en `webhook_secret`.

   La firma `x-signature` se verifica con esa clave y una tolerancia de 600 s.
3. **Sandbox.** Usa las credenciales de prueba y [usuarios de prueba](https://www.mercadopago.com.ar/developers/es/docs/checkout-pro/additional-content/your-integrations/test/accounts): uno vendedor y otro comprador.

## Cómo pasar de emulador a real sin riesgo

1. **Empieza en `sandbox`.**
   - Pon `environment = sandbox` con las credenciales de prueba.
   - Haz una compra completa con el usuario comprador de prueba.
   - Comprueba que el webhook llega y que el pago queda `captured`.
2. **Cambia a `live`** solo después:
   - pon `environment = live` y las credenciales `APP_USR-` de producción;
   - en Laravel ejecuta `php artisan config:cache` y, al final, `PAYMENTS_ACCEPT_NEW=true`;
   - haz una primera compra real de importe mínimo y reembólsala desde el panel de Mercado Pago.

   Los reembolsos de la orquestación siguen deshabilitados por defecto.
3. **No cambies `environment` con pagos en curso.** Los intentos abiertos quedan ligados a la identidad anterior: espera a que terminen o expiren.

## Límites

- **Sin pruebas contra Mercado Pago real.** Las pruebas del paquete (`tests/Orchestration/ConfiguredAccountsProviderTest.php`) usan `FakeMercadoPago` y nunca salen a la red. El modo `sandbox` no se ha ejecutado contra Mercado Pago desde este repositorio (M10 en [PLAN-2.md](PLAN-2.md)).
- **Solo `ARS`.** Para otro país hay que añadir su sitio en `ConfiguredAccounts::MERCADOPAGO_CHECKOUT_ORIGINS` y su moneda en `Account::CURRENCIES`.
- **Sin `back_urls`.** La preferencia no las envía (`returnsToMerchant = false`): el comprador paga en otra pestaña y el comercio sigue el estado en la suya.
- **Stripe y PayPal siguen solo en el emulador** dentro de la orquestación. Los drivers sueltos sí admiten `sandbox` y `live`.

# ronu/payment-gateways

**Un contrato de pagos para cualquier pasarela.** Vienen tres drivers —Mercado Pago,
Stripe y PayPal— con el mismo código de consumidor, probados de punta a punta
contra el **Payment Emulator Lab** (perfiles Plaza, Vector y Harbor). Pasar del
emulador a la API real es cambiar `environment` y las credenciales.

PHP ≥ 8.2, Guzzle 7. Núcleo sin framework; puente Laravel opcional.

```bash
composer require ronu/payment-gateways
```

## Configuración

Cada pasarela tiene las mismas secciones. Lo que falta o sobra falla **al
construirla**, con un mensaje que dice qué clave es.

```php
use Ronu\PaymentGateways\GatewayFactory;

$gateway = GatewayFactory::make('tienda', [
    'driver'      => 'mercadopago',          // mercadopago | stripe | paypal | uno tuyo (extend)
    'environment' => 'emulator',             // emulator | sandbox | live

    'credentials' => ['access_token' => getenv('MERCADOPAGO_ACCESS_TOKEN')],
    'webhooks'    => ['secret' => getenv('MERCADOPAGO_WEBHOOK_SECRET')],
    'options'     => ['currency' => 'ARS'],

    // solo con environment = emulator
    'emulator'    => ['api_url' => 'http://localhost:8080', 'checkout_url' => 'http://localhost:3001'],

    'http'        => ['timeout' => 15, 'connect_timeout' => 5],
]);
```

| Sección | Mercado Pago | Stripe | PayPal |
|---|---|---|---|
| `credentials` | `access_token` | `secret_key` | `client_id` + `client_secret` (o `access_token`) |
| `webhooks` | `secret`, `tolerance_seconds` (600) | `secret`, `tolerance_seconds` (300) | `id`, `cert_hosts`, `certificate`, `tolerance_seconds` (300) |
| `options` | `currency`, `ticket_method`, `notification_url`, `statement_descriptor` | `api_version` (fíjala) | `brand_name`, `locale`, `shipping_preference` |
| `emulator` | `api_url`, `checkout_url`, `scenario` | `api_url`, `scenario` | `api_url` |

Un valor `null` (un `env()` sin definir) se ignora y deja el valor por defecto.
`base_url` sustituye el host del entorno (proxies, mocks).

### Presets

Atajos que rellenan `driver`, `environment` y valores por defecto; lo que tú
pases gana: `GatewayFactory::make('plaza', ['credentials' => […], 'emulator' => […]])`
o `['preset' => 'plaza', …]` con cualquier nombre.

| Driver | Emulador | Sandbox | Live |
|---|---|---|---|
| `mercadopago` | `plaza` | `mercadopago_sandbox` | `mercadopago` |
| `stripe` | `vector` | `stripe_test` | `stripe` |
| `paypal` | `harbor` | `paypal_sandbox` | `paypal` |

### Laravel

```bash
php artisan vendor:publish --tag=payment-gateways-config
```

`config/payment-gateways.php` trae las tres pasarelas comentadas; cada una elige
su entorno con `MERCADOPAGO_ENVIRONMENT`, `STRIPE_ENVIRONMENT` y `PAYPAL_ENVIRONMENT`.

```php
app(\Ronu\PaymentGateways\Contracts\Gateway::class);                                  // la de 'default'
app(\Ronu\PaymentGateways\Laravel\PaymentGatewaysManager::class)->gateway('paypal');  // una por nombre
```

## Tres formas de cobrar

| | Mercado Pago | Stripe | PayPal |
|---|---|---|---|
| **A. Página del proveedor**: `createCheckout()` | Checkout Pro (`init_point`) | Checkout Session (`url`) | Aprobación de la orden |
| **B. Formulario incrustado**: `createPayment()` + `PaymentMethod` | Card Payment Brick → `PaymentMethod::card($token, 'visa')` | Elements → `PaymentMethod::card('pm_…')`, o sin token y `clientSecret` | JS SDK con el id de la orden |
| **Ticket**: `createPayment()` sin método | Pago Fácil, Rapipago… (`ticket_url`) | — | — |

### A. Página del proveedor (recomendado)

```php
use Ronu\PaymentGateways\{CreatePayment, Money, Payer, CheckoutStatus};

$checkout = $gateway->createCheckout(new CreatePayment(
    amount: Money::fromDecimal('1500.50', 'ARS'),   // el importe lo decide tu servidor
    reference: 'ORDER-123',                         // tu id, único por checkout
    idempotencyKey: 'checkout-ORDER-123',
    description: 'Reserva',
    returnUrl: 'https://shop.example/pago/vuelta',  // obligatorio en Stripe y PayPal
    cancelUrl: 'https://shop.example/carrito',
    payer: Payer::fromFullName('Ana Pérez', 'ana@example.test', '30111222'),
));
// Guarda $checkout->id y redirige a $checkout->url.

// De vuelta en returnUrl (o al llegar el webhook):
$checkout = $gateway->getCheckout($checkoutId);
if ($checkout?->status === CheckoutStatus::Completed) {
    $payment = $gateway->getPayment($checkout->paymentId);   // y sigue el flujo común
}
```

Stripe: pon `{CHECKOUT_SESSION_ID}` en `returnUrl` para recibir el id en tu página.
Mercado Pago Checkout Pro cobra al momento (sin captura manual).

### B. Formulario incrustado

```php
use Ronu\PaymentGateways\PaymentMethod;

// Mercado Pago: token y payment_method_id que devuelve el Brick.
$payment = $gateway->createPayment(new CreatePayment(…, paymentMethod: PaymentMethod::card($token, 'visa', installments: 3)));

// Stripe: confirmar en el servidor con el PaymentMethod de Elements…
$payment = $gateway->createPayment(new CreatePayment(…, returnUrl: $url3ds, paymentMethod: PaymentMethod::card('pm_…')));
// …o crearlo sin token y confirmarlo en el navegador con $payment->clientSecret.
```

### El flujo común

```php
// Webhook: verifica con el cuerpo CRUDO; el evento es un aviso.
$event = $gateway->parseWebhook($request->headers(), $request->rawBody()); // InvalidWebhookSignature ⇒ 401
$payment = $gateway->getPayment($gateway->paymentIdForEvent($event));

if ($payment->status->awaitsMerchant()) {              // PayPal aprobado / captura manual
    $payment = $gateway->capture($payment->id, idempotencyKey: "capture-{$payment->id}");
}
if ($payment->status === PaymentStatus::Captured && $payment->matches('ORDER-123', $importe)) {
    // confirmar el pedido, una sola vez
}

$gateway->refund($payment->id, Money::fromDecimal('500', 'ARS'), 'refund-ORDER-123-1'); // null = todo
```

## Capacidades

Pregunta antes de llamar, en vez de capturar `UnsupportedOperation`:

```php
if ($gateway->supports(Capability::PartialCapture)) { … }
```

| | Mercado Pago | Stripe | PayPal |
|---|---|---|---|
| `HostedCheckout` | ✓ | ✓ | ✓ |
| `EmbeddedCheckout` | ✓ | ✓ | ✓ |
| `ManualCapture` | ✓ (Orders) | ✓ | ✓ |
| `PartialCapture` | — | ✓ | ✓ (con captura manual) |
| `PartialRefund` | ✓ | ✓ | ✓ |
| `OfflinePayments` | ✓ | — | — |

## Añadir otra pasarela

Implementa `Contracts\Gateway` (y `Contracts\HostedCheckout` si el proveedor tiene
página propia), recibe un `Config\GatewayConfig` y regístralo:

```php
GatewayFactory::extend('adyen', fn (GatewayConfig $config, ?ClientInterface $http) => new AdyenGateway($config, $http));

GatewayFactory::make('eu', ['driver' => 'adyen', 'environment' => 'sandbox', 'credentials' => ['api_key' => '…']]);
```

`GatewayConfig` te da `credential()` (obligatoria, con mensaje claro), `option()`,
`webhook()`, `isEmulator()` y `resolveBaseUrl($hosts, $perfilDelEmulador)`.

## Estados

`created · requires_action · processing · approved · authorized · captured ·
partially_refunded · refunded · canceled · failed · disputed · unknown`

- `approved`: el comprador aprobó y **falta que cobres** (`capture()`), como en PayPal.
- `authorized`: captura manual pendiente (`capture()` o `cancel()`).
- `unknown`: un estado que la biblioteca no conoce. No cumplas el pedido.

## Errores

`GatewayException::$retryable` decide: `true` (red, 408, 429, 5xx) ⇒ reintenta
con la **misma** clave de idempotencia. `$providerCode` trae el código del proveedor.
Una clave reutilizada con otro cuerpo responde 409 y no es reintentable.

## Por proveedor

| | Mercado Pago | Stripe | PayPal |
|---|---|---|---|
| APIs | Orders, Checkout Pro (preferences), Payments | PaymentIntents, Refunds, Checkout Sessions | OAuth, Orders v2, Payments v2 |
| Idempotencia | `X-Idempotency-Key` | `Idempotency-Key` | `PayPal-Request-Id` |
| Formato | JSON, importes decimales | **formulario**, unidades menores | JSON, importes decimales |
| Ids | `ORD…` (Orders), numérico (Payments) | `pi_…`, `cs_…` | id de orden |
| Cobro | automático o `capture()` | automático o `capture()` | **siempre `capture()`** tras aprobar |
| Webhook | `x-signature`: HMAC de `id;request-id;ts` | `stripe-signature`: HMAC de `t.cuerpo` | RSA-SHA256 de `id\|time\|webhook_id\|crc32(cuerpo)` |

## Límites conocidos

- **El emulador no tiene Checkout Pro ni Checkout Sessions.** En `environment: emulator`,
  `createCheckout()` de Mercado Pago y Stripe usa las páginas propias de Plaza y Vector.
  Contra la API real, esos dos caminos están probados con respuestas simuladas según la
  documentación, todavía **no contra el sandbox** (fase M10 de `docs/PLAN-2.md`).
- **Mercado Pago Checkout Pro**: `getCheckout()` busca el pago por `external_reference`;
  usa una referencia única por checkout.
- **Stripe**: un PaymentIntent no refleja devoluciones; `getPayment()` sigue en
  `captured`. Usa el `Refund` devuelto.
- **PayPal**: el certificado de firma solo se descarga de `webhooks.cert_hosts`
  (por defecto los hosts de PayPal); en el emulador se añade su host.

## Tests

```bash
composer test        # unitarios (Guzzle simulado)
composer analyse     # PHPStan nivel 8

# Contra un emulador real (crea sus propias aplicaciones):
PAYMENT_EMULATOR_URL=http://localhost:8080 \
PAYMENT_EMULATOR_ADMIN_TOKEN=… \
PAYMENT_EMULATOR_SINK_PORT=8095 composer test:integration
```

Plan: [docs/PLAN-2.md](docs/PLAN-2.md) · historia: [docs/PLAN.md](docs/PLAN.md).

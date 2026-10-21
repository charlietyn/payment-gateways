# ronu/payment-gateways

**Un contrato de pagos, tres pasarelas.** Mercado Pago (Orders API), Stripe
(PaymentIntents) y PayPal (Orders v2), con el mismo código de consumidor y
probadas de punta a punta contra el **Payment Emulator Lab** (perfiles Plaza,
Vector y Harbor). Pasar del emulador a la API real es cambiar el preset.

| Preset | Driver | Destino |
|---|---|---|
| `plaza` | `mercadopago` | Emulador · perfil Plaza |
| `mercadopago` | `mercadopago` | `https://api.mercadopago.com` |
| `vector` | `stripe` | Emulador · perfil Vector |
| `stripe` | `stripe` | `https://api.stripe.com` |
| `harbor` | `paypal` | Emulador · perfil Harbor |
| `paypal_sandbox` / `paypal` | `paypal` | `https://api-m.sandbox.paypal.com` / `https://api-m.paypal.com` |

PHP ≥ 8.2, Guzzle 7. Núcleo sin framework; puente Laravel opcional.

## Uso

```php
use Ronu\PaymentGateways\{CreatePayment, GatewayFactory, Money, Payer, PaymentStatus};

$gateway = GatewayFactory::make('plaza', [
    'emulator_url' => 'http://localhost:8080',
    'emulator_checkout_url' => 'http://localhost:3001',
    'access_token' => getenv('PLAZA_ACCESS_TOKEN'),
    'webhook_secret' => getenv('PLAZA_WEBHOOK_SECRET'),
]);

// 1. Crear: el importe lo decide tu servidor, nunca el navegador.
$payment = $gateway->createPayment(new CreatePayment(
    amount: Money::fromDecimal('1500.50', 'ARS'),
    reference: 'ORDER-123',                 // tu id; vuelve en $payment->reference
    idempotencyKey: 'checkout-ORDER-123',   // misma clave + mismo cuerpo ⇒ mismo recurso
    description: 'Reserva',
    returnUrl: 'https://shop.example/pago/vuelta',
    payer: Payer::fromFullName('Ana Pérez', 'ana@example.test', '30111222'),
));
// Guarda $payment->id. Lleva al comprador a $payment->nextAction->url.
// Si nextAction->returnsToMerchant es false, ábrelo en otra pestaña y sondea tu estado.

// 2. Webhook: verifica con el cuerpo CRUDO; el evento es un aviso.
$event = $gateway->parseWebhook($request->headers(), $request->rawBody()); // InvalidWebhookSignature ⇒ 401
$current = $gateway->getPayment($gateway->paymentIdForEvent($event));

// 3. Actuar sobre el estado releído.
if ($current->status->awaitsMerchant()) {         // PayPal aprobado / captura manual
    $current = $gateway->capture($current->id, idempotencyKey: "capture-{$current->id}");
}
if ($current->status === PaymentStatus::Captured && $current->matches('ORDER-123', Money::fromDecimal('1500.50', 'ARS'))) {
    // confirmar la reserva, una sola vez
}

// 4. Devolver (null = todo lo que queda).
$gateway->refund($current->id, Money::fromDecimal('500', 'ARS'), 'refund-ORDER-123-1');
```

### Estados

`created · requires_action · processing · approved · authorized · captured ·
partially_refunded · refunded · canceled · failed · disputed · unknown`

- `approved`: el comprador aprobó y **falta que cobres** (`capture()`), como en PayPal.
- `authorized`: captura manual pendiente (`capture()` o `cancel()`).
- `unknown`: un estado que la biblioteca no conoce. No cumplas el pedido.

### Errores

`GatewayException::$retryable` decide: `true` (red, 408, 429, 5xx) ⇒ reintenta
con la **misma** clave de idempotencia. `$providerCode` trae el código del proveedor.
Una clave reutilizada con otro cuerpo responde 409 y no es reintentable.

## Laravel

```bash
php artisan vendor:publish --tag=payment-gateways-config
```

```php
app(\Ronu\PaymentGateways\Laravel\PaymentGatewaysManager::class)->gateway('paypal');
app(\Ronu\PaymentGateways\Contracts\Gateway::class); // el gateway por defecto
```

## Por proveedor

| | Mercado Pago (Orders) | Stripe (PaymentIntents) | PayPal (Orders v2) |
|---|---|---|---|
| Credencial | `access_token` | `secret_key` | `client_id` + `client_secret` (OAuth, token en caché) |
| Idempotencia | `X-Idempotency-Key` (obligatoria) | `Idempotency-Key` | `PayPal-Request-Id` |
| Formato | JSON, importes decimales | **formulario**, importes en unidades menores | JSON, importes decimales |
| Paso del comprador | URL del ticket / checkout de Plaza | `next_action.redirect_to_url` (emulador) o Stripe.js con `clientSecret` (real) | enlace `approve` / `payer-action` |
| Cobro | automático | automático (o `capture()` si manual) | **siempre `capture()`** tras aprobar |
| Webhook | `x-signature`: HMAC de `id;request-id;ts` | `stripe-signature`: HMAC de `t.cuerpo` | RSA-SHA256 de `id|time|webhook_id|crc32(cuerpo)` |
| Config webhook | `webhook_secret` | `webhook_secret` | `webhook_id` + `webhook_cert_hosts` (o `webhook_certificate` fijado) |

## Límites conocidos

- **Stripe**: un PaymentIntent no refleja devoluciones; `getPayment()` sigue en
  `captured`. Usa el `Refund` devuelto.
- **Stripe real**: la confirmación con tarjeta ocurre en el navegador
  (Stripe.js + `clientSecret`); la biblioteca no la sustituye.
- **Mercado Pago real**: `ticket_url` en `transactions.payments[0].payment_method`
  — **requiere verificación** contra la documentación vigente. Tarjeta exige Brick.
- **PayPal**: el certificado de firma solo se descarga de `webhook_cert_hosts`
  (por defecto los hosts de PayPal); el preset `harbor` añade el host del emulador.
- **Emulador**: Plaza firma el `data.id` tal cual (el verificador acepta ambas
  formas); las operaciones del comercio (capture/refund) no emiten webhook con el
  escenario por defecto.

## Tests

```bash
composer test                     # unitarios (Guzzle simulado), 42 tests
composer analyse                  # PHPStan nivel 8

# Contra un emulador real (crea sus propias aplicaciones):
PAYMENT_EMULATOR_URL=http://localhost:8080 \
PAYMENT_EMULATOR_ADMIN_TOKEN=… \
PAYMENT_EMULATOR_SINK_PORT=8095 composer test:integration
```

Plan y registro: [docs/PLAN.md](docs/PLAN.md).

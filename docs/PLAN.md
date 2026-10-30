# Plan — `ronu/payment-gateways`

Biblioteca PHP reutilizable de pasarelas de pago con **un solo contrato** y tres
drivers, cada uno probado contra su perfil del Payment Emulator Lab y apuntable
a la API real cambiando solo la configuración.

| Driver | Proveedor real | Perfil del emulador | API |
|---|---|---|---|
| `mercadopago` | Mercado Pago | **Plaza** | Orders API (`/v1/orders`) |
| `stripe` | Stripe | **Vector** | PaymentIntents + Refunds (`/v1/payment_intents`, `/v1/refunds`) |
| `paypal` | PayPal | **Harbor** | OAuth + Orders v2 + Payments v2 |


## 1. Principios

1. **El contrato es del negocio, no del proveedor.** Un consumidor habla de
   `Payment`, `PaymentStatus`, `Money`, `Refund` y `WebhookEvent`; nunca de
   `preference`, `payment_intent` ni `purchase_units`.
2. **El webhook es un aviso.** `parseWebhook()` verifica la firma y devuelve qué
   recurso cambió; la verdad se relee con `getPayment()`.
3. **Mismo código contra el emulador y contra la API real.** El preset decide la
   base URL y, solo en el emulador, cómo se resuelve la URL de pago cuando el
   proveedor real no la da en la respuesta.
4. **Dinero en unidades menores**, moneda ISO-4217 explícita. Nada de floats.
5. **Idempotencia en todas las escrituras**, con el header que cada proveedor
   documenta (`X-Idempotency-Key`, `Idempotency-Key`, `PayPal-Request-Id`).
6. **Sin dependencias de framework en el núcleo.** Guzzle como transporte; un
   puente Laravel opcional (`ServiceProvider` + config + manager).
7. **Fallar cerrado en webhooks.** Sin secreto o sin certificado permitido no hay
   evento. El certificado de PayPal solo se descarga de hosts de una lista blanca.

## 2. Contrato

```php
interface Gateway {
    public function name(): string;                                   // 'mercadopago' | 'stripe' | 'paypal'
    public function createPayment(CreatePayment $request): Payment;   // + nextAction (URL del comprador)
    public function getPayment(string $paymentId): ?Payment;
    public function capture(string $paymentId, ?Money $amount = null, ?string $idempotencyKey = null): Payment;
    public function cancel(string $paymentId, ?string $idempotencyKey = null): Payment;
    public function refund(string $paymentId, ?Money $amount, string $idempotencyKey): Refund;
    public function parseWebhook(array $headers, string $rawBody): WebhookEvent;   // InvalidWebhookSignature
    public function paymentIdForEvent(WebhookEvent $event): ?string;  // puede consultar (p. ej. captura → orden en PayPal)
}
```

Estados canónicos (`PaymentStatus`), los mismos del núcleo del emulador:
`created · requires_action · processing · approved · authorized · captured ·
partially_refunded · refunded · canceled · failed · disputed · unknown`.

`approved` significa "el comprador consintió, falta que el comercio cobre"
(PayPal). `authorized` es captura manual pendiente. `capture()` resuelve los dos.

## 3. Fases

Leyenda: ☐ pendiente · ☑ hecho.

| ID | Tarea | Criterio de hecho | Estado |
|---|---|---|---|
| L0 | Repo, `composer.json`, PHPUnit, PHPStan | `composer install` y `vendor/bin/phpunit` corren | ☑ |
| L1 | Núcleo: `Money`, `PaymentStatus`, `CreatePayment`, `Payment`, `NextAction`, `Refund`, `WebhookEvent`, excepciones, transporte HTTP | Tests unitarios | ☑ |
| L2 | Driver `mercadopago` (Orders): create/get/capture/cancel/refund, firma `x-signature`, preset `plaza` | Tests con `MockHandler` | ☑ |
| L3 | Driver `stripe` (PaymentIntents): formularios codificados, `next_action`, refunds, `stripe-signature` sobre el cuerpo crudo, preset `vector` | idem | ☑ |
| L4 | Driver `paypal` (Orders v2): OAuth con caché, approve→capture/authorize, refunds sobre la captura, webhooks RSA-SHA256 + CRC32 con certificado de host permitido, preset `harbor` | idem | ☑ |
| L5 | `GatewayFactory` + presets + puente Laravel (`PaymentGatewaysManager`, config publicable) | Test del manager | ☑ |
| L6 | Suite de integración contra el emulador (crea una app por proveedor con el token de operador) | Los tres flujos completos en verde contra un emulador real | ☑ |
| L8 | README con guía por proveedor y tabla de fidelidad/limitaciones | Revisado | ☑ |

## 4. Limitaciones conocidas (se documentan, no se esconden)

- **Stripe**: un PaymentIntent no refleja devoluciones (siguen en el cargo). El
  driver informa `refunded/partially_refunded` desde las respuestas de `refund()`;
  `getPayment()` devuelve `captured`. Consultar el cargo queda fuera del alcance.
- **Mercado Pago real**: la URL de pago de un pedido con medio `ticket` se lee de
  `transactions.payments[0].payment_method.ticket_url` — **REQUIERE VERIFICACIÓN**
  contra la documentación vigente. Tarjeta exige tokenización (Brick): fuera del alcance.
- **Plaza** firma el `data.id` tal cual; Mercado Pago documenta minúsculas. El
  verificador acepta ambas.
- **Harbor** devuelve recursos de webhook mínimos; el driver obtiene la orden de
  una captura mediante el enlace `rel=up`, que la API real también expone.

## 5. Registro de ejecución

| Fecha | ID | Evidencia |
|---|---|---|
| 2026-10-21 | L0–L5 | `composer test` → **42 tests, 155 aserciones OK**; `composer analyse` (PHPStan nivel 8) sin errores |
| 2026-10-21 | L6 | `composer test:integration` contra un emulador real (API :8090, worker con outbound): **4 tests, 31 aserciones OK** — Plaza (idempotencia, conflicto 409, pago, devolución parcial/total, webhooks HMAC verificados), Vector (requires_action → pago → devolución, webhooks `stripe-signature` verificados), Harbor (OAuth, aprobación, `capture()`, devolución, webhook RSA verificado con el certificado descargado), rechazo |
| 2026-10-21 | Hallazgo | El emulador solo programa webhooks para la creación y la confirmación en el checkout; capture/refund lanzados por el comercio no notifican con el escenario por defecto. Harbor tampoco incluye `supplementary_data` en el recurso: el driver resuelve la orden por el enlace `rel=up` |

# Changelog

Formato [Keep a Changelog](https://keepachangelog.com/es-ES/1.1.0/); versiones
[SemVer](https://semver.org/lang/es/). Antes de `1.0.0` una versión menor puede
romper la API, y aquí se dice cómo.

## [Sin publicar]

### Cambiado

- Pint gatea `src/Orchestration` y `tests/Orchestration` (`composer format:check`, también en CI); el CI
  carga `pdo_pgsql`. README: comando de la suite de PostgreSQL.

## [0.3.0-alpha.1] — 2026-10-29 (candidato local, sin publicar)

Artefacto `ronu-payment-gateways-0.3.0-alpha.1.zip`, sha256
`e8251abc95bb8d9903850bd86710a745f259895445d7c452d22fe8b6f0633696`. Su contenido está congelado: cualquier
cambio en lo que se distribuye sale con otra versión.

### Añadido

- **Orquestación de pagos** (`Ronu\PaymentGateways\Orchestration`): incorpora el paquete
  `ronu/payment-orchestration` `0.1.0-alpha.8` completo. Incluye coordinador de checkout con estados
  duraderos, `PostgresStore`, `RecoveryWorker`, refunds (deshabilitados por defecto), refunds externos,
  disputas, compensación, eventos financieros, reproceso auditado de elementos agotados, métricas,
  alertas y el puente Laravel de registro explícito. Ver [docs/orquestacion.md](docs/orquestacion.md).
- `Contracts\RefundLookup` y `Contracts\DisputeLookup`: consulta de solo lectura de un refund o una
  disputa individual; Mercado Pago Orders implementa la de refunds.
- `Dispute` y `Support\SensitiveData` (redacción de datos sensibles en errores y logs).

### Cambiado

- `require-dev`: `laravel/framework ^12.0` sustituye a `illuminate/config|container|support`, y se
  añade `laravel/pint`. Las dependencias de ejecución no cambian.
- `phpstan.neon` incluye `phpstan-baseline.neon`, que solo contiene errores de
  `src/Orchestration` heredados de su nivel 5 anterior. El resto de la biblioteca sigue en nivel 8 sin
  baseline.

### Migración

- Quien usaba `ronu/payment-orchestration`: quita ese paquete y cambia el namespace
  `Ronu\PaymentOrchestration\` por `Ronu\PaymentGateways\Orchestration\`. Esquema, eventos y
  protocolo de workers no cambian.
- `conflict: ronu/payment-orchestration`: los dos paquetes no pueden instalarse juntos, porque
  serían dos coordinadores distintos sobre las mismas tablas.

## [0.2.0] — 2026-10-21

Primera versión etiquetada.

### Añadido

- **Configuración legible**: secciones fijas `driver`, `environment` (`emulator` · `sandbox` · `live`),
  `base_url`, `credentials`, `webhooks`, `options`, `emulator` y `http`, validadas al construir
  con la clave exacta del error. Presets como datos (`plaza`, `mercadopago_sandbox`, `mercadopago`,
  `vector`, `stripe_test`, `stripe`, `harbor`, `paypal_sandbox`, `paypal`).
- `GatewayFactory::extend()` para registrar pasarelas sin tocar la biblioteca; `Capability` + `supports()`.
- **Página del proveedor** (`Contracts\HostedCheckout`): Mercado Pago Checkout Pro, Stripe Checkout
  Sessions y aprobación de PayPal. En el emulador, las páginas de Plaza, Vector y Harbor.
- **Formulario incrustado** (`PaymentMethod`): tokens de Card Payment Brick (Orders) y `pm_…` de Stripe.
- Mercado Pago Payments API (ids numéricos): consultar, capturar, cancelar, devolver, webhooks.
- `Services`: cliente HTTP, caché PSR-16, logger PSR-3 y espera entre reintentos, fuera del array de config.
- Token OAuth de PayPal compartido en caché PSR-16. Tras un 401 se pide uno nuevo una sola vez.
- Stripe: `getPayment()` lee devoluciones y disputas del cargo expandido.
- Reintentos para 408, 429, 5xx y fallos de red, con la misma clave de idempotencia, espera
  exponencial y `Retry-After`. Una línea de log por llamada, sin cabeceras ni cuerpos.
- Webhooks: `WebhookEvent::$kind` (`EventType` canónico), `EventDeduplicator` (memoria o PSR-16) y
  verificación remota en PayPal (`webhooks.verification: remote`).
- `Testing\GatewayConformanceTestCase`: el kit que pasa todo driver.
- `Testing\FakeMercadoPago`: la Orders API en memoria para tests de aplicaciones.
- Puente Laravel: usa la caché, el logger (`payment-gateways.log_channel`) y un cliente Guzzle
  ligado como `payment-gateways.http`; liga `EventDeduplicator`.
- `emulator.api_url` acepta la URL con o sin el perfil (`…:8090` o `…:8090/plaza`).
- Pruebas de contrato contra los OpenAPI oficiales (`composer test:contract`) y workflow semanal.

### Cambiado (rompe la API del commit inicial)

- Drivers renombrados a `MercadoPagoGateway`, `StripeGateway` y `PayPalGateway`.
- La config plana (`access_token`, `secret_key`…) pasa a sus secciones (`credentials.access_token`…).
  Las claves desconocidas son un error.
- `GatewayFactory::make($nombre, $config, $services)`: el tercer argumento acepta un
  `ClientInterface` o un `Services`. `extend()` recibe `callable(GatewayConfig, Services)`.
- `Gateway::supports()` es parte del contrato.

### Conocido

- Checkout Pro y Checkout Sessions solo están probados con respuestas simuladas y contra los
  OpenAPI. Aún no contra los sandbox (necesita credenciales de prueba).
- En el emulador, cancelar un pago capturado en Plaza o Vector responde 500.

[Sin publicar]: https://github.com/charlietyn/payment-gateways/compare/v0.2.0...HEAD
[0.2.0]: https://github.com/charlietyn/payment-gateways/releases/tag/v0.2.0

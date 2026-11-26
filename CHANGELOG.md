# Changelog

Formato [Keep a Changelog](https://keepachangelog.com/es-ES/1.1.0/); versiones
[SemVer](https://semver.org/lang/es/). Antes de `1.0.0` una versión menor puede
romper la API, y aquí se dice cómo.

## [Sin publicar]

## [0.10.0-alpha.2] — 2026-11-26

### Cambiado

- **`payments:setup --frontend-env`**: el mapeo `payments.setup.frontend` se aplica solo al front que
  lo usa. Un `.env` que declara los nombres canónicos y ninguno de los mapeados recibe los canónicos
  (`frontend-names: front_declares_canonical_names`); antes recibía los mapeados, que ese front no lee,
  y quedaba sin cliente partner ni clave. Un front que declara los mapeados, o ninguno, no cambia.

## [0.10.0-alpha.1] — 2026-11-25

### Añadido

- **Superficie de administración (back office).** `Orchestration\Laravel\AdminController` con
  `index` (listado paginado y filtrado por estado financiero, estado de entrega, cuenta, referencia y
  sujeto), `show` (detalle con operaciones, reembolsos, notificaciones al producto, webhooks,
  reconciliación, incidencias y acciones efectivas), `refund` + `refundShow` (reembolso de operador) y
  `reconcile` (adelantar una reconciliación pendiente). Se monta en el canal admin autenticado del
  proyecto.
- **`Orchestration\Operator`** y el contrato del proyecto `Orchestration\Laravel\OperatorAdapter`
  (`config('payments.admin.operator')`). La autorización la decide el adaptador en cada petición, sin
  depender de un middleware que pueda estar apagado; sin adaptador la API responde `admin_unavailable`
  (503). Capacidades: `read`, `refund`, `recover`.
- **`OperatorConsole`**: separa estado financiero, entrega del producto y compensación; las acciones
  llevan el motivo cuando no están disponibles (`forbidden`, `refunds_disabled`, `not_captured`,
  `nothing_refundable`, `recovery_disabled`, `not_pending`, `account_identity_changed`).
- **`RefundCoordinator::requestAsOperator`**: el operador se autoriza por proyecto/tenant/cuenta, nunca
  suplanta al comprador; el reembolso pertenece al pago igual que uno del cliente y su clave queda
  atribuida (`operator:<auditId>:<clave>`).
- Evento `Laravel\Events\OperatorActionRecorded`, despachado tras el commit, para el log de auditoría.

### Cambiado

- Los intents NUEVOS guardan `project` y `tenant` (nunca el comprador). Los anteriores no pueden
  atribuirse a un tenant: solo los ve un operador con `legacyHistory` concedido expresamente.
- Reconciliar desde HTTP nunca reinicia intentos: un elemento agotado sigue exigiendo
  `payments:run replay-exhausted` con actor y evidencia.
- **`payments:setup`** se detiene con `account_api_env_conflict:<cuenta>` o
  `account_environment_env_conflict:<cuenta>` si una variable `PAYMENTS_<CUENTA>_API`/`_ENVIRONMENT`
  contradice la configuración (la familia de API y el entorno son identidad de la cuenta), y con
  `frontend_env_conflict:<canónica>/<mapeada>` si el front tiene el nombre canónico y el mapeado con
  valores distintos. El código de partner puede fijarse en `payments.setup.partner`.
- El puente Laravel admite `laravel/framework` `^12.0 || ^13.0`.

## [0.9.0-alpha.5] — 2026-11-22

### Corregido

- **Una base que no existe ya no se lee como «servidor inalcanzable»**. PostgreSQL responde
  `08006` también en ese caso, y el clasificador lo enviaba a `database_unreachable_check_host`. Ahora es
  `database_missing_check_name` (también MySQL 1049 y SQL Server 4060); una tabla inexistente no cuenta.

## [0.9.0-alpha.4] — 2026-11-22

### Corregido

- **`payments:setup` distingue por qué no llega a la base de datos.** Todo error de base era
  `database_unavailable_check_connection`, y un `.env` con `DB_PASSWORD` vacío se leía como «servidor
  caído». PostgreSQL responde `08006` en ambos casos, así que el SQLSTATE no basta; ahora:
  - usuario o contraseña rechazados (incluido `fe_sendauth: no password supplied`) es
    `database_credentials_rejected_check_password`;
  - nadie escucha en ese host y puerto es `database_unreachable_check_host`;
  - el resto sigue siendo `database_unavailable_check_connection`.
- El log `payments: setup failed` lleva además `sqlstate` y `driver_code`, que no contienen datos.
  El mensaje del driver sigue sin salir nunca.

## [0.9.0-alpha.3] — 2026-11-21

### Corregido

- **`payments:setup` ya no esconde la causa de un fallo.** Todo error inesperado se convertía en
  `setup_unavailable_check_configuration`, sin más. Ahora:
  - un error de la base de datos es `database_unavailable_check_connection`;
  - una clase de `payments.adapter` que no existe es `product_adapter_class_missing:<clase>`, y una que no
    implementa `ProductAdapter`, `product_adapter_invalid:<clase>`;
  - cualquier otro lleva la clase de la excepción (`setup_unavailable_check_configuration:<Clase>`) y deja
    en el log `payments: setup failed` con clase, archivo y línea. Nunca el mensaje.
- La comprobación del webhook fallaba con un `APP_URL` publicado en un subdirectorio
  (`http://localhost/eros`): buscaba la ruta con ese prefijo, Laravel lanzaba `NotFoundHttpException` y el
  setup respondía el error genérico. La ruta se busca ahora sin el subdirectorio, y una ruta que no existe
  es `payment_webhook_not_registered`.
- Guía: «Si el setup falla» en `docs/setup-local.md`.

## [0.9.0-alpha.2] — 2026-11-16

### Añadido

- **Rastro de los fallos que la orquestación responde en lugar de lanzar.** Un fallo del proveedor
  durante un checkout o un refund deja la operación en `UNKNOWN`, y un fallo inesperado de una acción
  HTTP responde `payment_unavailable`. Ninguno llegaba al manejador de excepciones de la aplicación ni
  a ningún log. Ahora:
  - `Orchestration\Contracts\FailureReporter` recibe la etapa (`checkout`, `refund`, `request`), la
    excepción y sólo identificadores (operación, intent, cuenta, ruta). Se llama **después** de
    confirmar el estado, y un reporter que falla se ignora: el rastro nunca decide el resultado.
  - En Laravel, `Orchestration\Laravel\FailureReporting` escribe una línea de log
    (`payments: {stage} failed`) y despacha el evento `Orchestration\Laravel\Events\PaymentFailureReported`.
    Un listener puede entregarlo al manejador de la aplicación: `report($event->error)`.
  - Nunca se registra el mensaje de una excepción ajena, sólo su clase, archivo y línea.
- `Coordinator` y `RefundCoordinator` aceptan un `FailureReporter` opcional como último argumento.

### Corregido

- La orquestación en Laravel construía las cuentas sin logger, así que las llamadas al proveedor
  (`payment-gateways: … failed`) no se escribían nunca. `PaymentRuntime` les pasa ahora el log de la
  aplicación, con el canal de `payment-gateways.log_channel`, y el cliente HTTP enlazado en
  `PaymentGatewaysServiceProvider::HTTP` si existe, igual que la fachada de pasarelas.

## [0.9.0-alpha.1] — 2026-11-09

### Añadido

- **Cuentas reales de Mercado Pago en la orquestación.** `ConfiguredAccounts` acepta `environment`
  `sandbox` o `live` (por defecto, `emulator`) para cuentas `mercadopago` con `api` `checkout_pro`.
  - El host es siempre `https://api.mercadopago.com`.
  - El comprador solo puede ir a `www.mercadopago.com.ar` (en sandbox, también a `sandbox.mercadopago.com.ar`).
  - `live` rechaza credenciales que no sean `APP_USR-`.
  - `statement_descriptor` es opcional y se valida.
  - La rotación del secreto del webhook funciona igual que en el emulador.
- Nuevos códigos de error: `unsupported_live_api`, `live_requires_production_credentials`, `invalid_statement_descriptor`.
- `ConfiguredAccounts` acepta un `Services` opcional (cliente HTTP) para pruebas.
- Guía: `docs/cuenta-real-mercadopago.md`.

### Cambiado

- `Account` admite los entornos `emulator`, `sandbox` y `live` (`Account::ENVIRONMENTS`). El entorno ya
  formaba parte de la identidad, así que las cuentas existentes del emulador no cambian de identidad.

## [0.8.0-alpha.3] — 2026-11-04

### Corregido

- Documentación de instalación actualizada para la versión alpha disponible en Packagist.
- Finales de línea LF para los archivos PHP, para que la comprobación de formato sea consistente
  en Windows y Linux.

## [0.8.0-alpha.2] — 2026-11-03

### Corregido

- El setup acepta el mapa estructurado `schema/table` que utiliza el runtime y emite el generador.
  Lo normaliza solo para comprobar tablas e historial; conserva la configuración del runtime.
- Regresión de la puerta F4: migraciones correctas que alpha.1 rechazaba como `payment_tables_not_migrated`.

## [0.8.0-alpha.1] — 2026-11-03

- Nuevo `payments:setup`: validación de tablas, adaptador, rutas y scheduler; partner config/Eloquent;
  provisión idempotente de Plaza, Vector y Harbor, actualización del webhook y configuración del front.
- `--dry-run` no escribe ni provisiona; `--json` no contiene secretos. Las ventas solo se abren con
  `--emulator`, fuera de producción y tras las comprobaciones. No se ejecutan migraciones ni cobros.
- Conservación de claves y permisos por cuenta; rechazo de identidades históricas cambiadas, clientes
  revocados, nombres duplicados y credenciales perdidas (sin rotación implícita).
- Puerto `Setup\ValueStore` para almacenes externos de secretos; el uso de Floci sin integración
  configurada falla antes de escribir secretos en `.env`.
- Requisitos y configuración: `docs/setup-local.md`. La orquestación sigue limitada al emulador.

## [0.7.0-alpha.1] — 2026-11-02

### Añadido

- **Adaptador de producto genérico** `Orchestration\Laravel\Products\TableSubjectAdapter`: cobra una fila de
  una tabla del proyecto configurada en `payments.product` (tabla, referencia única, importe en decimal o
  céntimos, moneda por fila o fija, estados, cupo, caducidad, revisión, descripción, borrado lógico). Valida su
  configuración (`ProductConfiguration`) y, si falla, `ready()` es `false` con todos los problemas en el log.
- Estrategias de propiedad configurables: `owner`, `tenant` y `link` (referencia ≥ 16 caracteres).
- Eventos de Laravel tras el commit y al menos una vez: `PaymentSubjectDelivered`, `…Rejected` (con motivo),
  `…CompensationRequested`, `…CompensationAcknowledged`, `…Refunded` (`full`) y `…Disputed` (`state`).
- `ActorResolver`: el proyecto aporta quién paga; la librería no depende de ningún paquete de autenticación.
- `Quote` acepta `shared`, `retryable` y `description` (opcionales; los adaptadores existentes no cambian).
- **Sujetos compartidos**: con `shared` el coordinador serializa por sujeto y rechaza con
  `subject_already_reserved` (409) un segundo cobro de OTRA aplicación mientras uno está en curso.
- **Reintento tras un pago rechazado**: con `retryable`, si el último intento terminó sin dinero, el siguiente se
  guarda como `<sujeto>#<n>` (sin cambiar el esquema; el primero conserva el sujeto). `SubjectAttempt`.
- `PaymentStore::intentsForSubject()`; la descripción del `Quote` llega al proveedor (`CreatePayment`).
- Documentación: `docs/adaptador-de-producto.md`.


## [0.6.0-alpha.2] — 2026-11-02

Primera versión publicada desde `0.3.0-alpha.2`: incluye `0.4.0-alpha.1`, `0.5.0-alpha.1`, `0.5.0-alpha.2` y
`0.6.0-alpha.1`, que nunca se publicaron por separado.

### Corregido

- Reembolsos de Checkout Pro: la evidencia (`ProviderView::refund()` y `GatewayRefundEvidenceReader`) se
  reetiqueta con el `provider_id` del intent (la preferencia), porque el almacén comprueba que el reembolso
  pertenece al pago que registró. Con 0.6.0-alpha.1 el reembolso se enviaba bien al proveedor y quedaba
  `UNKNOWN` en local (evidencia del gate `h17-checkoutpro-invoices-001`).

## [0.6.0-alpha.1] — 2026-11-01 (candidato local, nunca publicado)

### Añadido

- **Mercado Pago Checkout Pro en la orquestación.** `Account` acepta la familia de API `checkout_pro` para
  `mercadopago` (parámetro opcional `api`, `Account::API_OPTIONS`); es una identidad distinta de `orders`.
  `ConfiguredAccounts` la lee de `config('payments.accounts.*.api')`.
- `ProviderView`: el único sitio que sabe que en Checkout Pro el `provider_id` es la PREFERENCIA. Crea con
  `createCheckout()`, lee por `getCheckout()` → pago (identidad = preferencia, `transactionId` = id numérico
  del pago) y apunta los reembolsos al pago. Lo usan `Coordinator` (crear, refrescar), `RecoveryWorker`
  (inbox y conciliación) y los reembolsos (`RefundCoordinator`, `GatewayRefundEvidenceReader`).
- `MercadoPagoGateway::getRefund()` admite pagos de la Payments API (id numérico) leyendo `refunds` de
  `GET /v1/payments/{id}`.
- `FakeMercadoPago` habla Checkout Pro (preferencias, pagos numéricos que solo existen tras el intento,
  búsqueda por `external_reference`, reembolsos sobre el pago).

### Cambiado

- `createCheckout()`/`getCheckout()` de Mercado Pago ya **no** toman el atajo de emulador (crear una orden):
  Plaza sirve Checkout Pro, así que el camino es el de producción. Una creación incierta de Checkout Pro
  queda pendiente de evidencia (Mercado Pago no notifica preferencias); nunca se repite la creación.

## [0.5.0-alpha.2] — 2026-11-01 (candidato local, nunca publicado)

### Añadido

- `RecoveryWorker::reconcile` captura las órdenes PayPal aprobadas por el comprador (Orders v2 no mueve dinero
  al aprobar). La clave `capture-v1-<referencia>` es determinista: si se pierde la respuesta, la siguiente
  pasada relee la orden y repite con el mismo `PayPal-Request-Id`, que el proveedor resuelve devolviendo la
  captura original; nunca hay una segunda captura. El drenaje no la detiene porque completa una venta ya
  aprobada.

## [0.5.0-alpha.1] — 2026-11-01 (candidato local, nunca publicado)

Incluye todo lo de `0.4.0-alpha.1` más cuentas Stripe y PayPal en la orquestación.

### Añadido

- `ConfiguredAccounts`: la clave `driver` de cada cuenta (`mercadopago` por defecto, `stripe`, `paypal`) elige
  el preset del emulador (Plaza, Vector, Harbor) y las credenciales que espera cada driver; `currency` por
  cuenta (por defecto `ARS`). PayPal exige `client_id` y verifica webhooks con el id de webhook de Harbor.
- `Account::CURRENCIES`: Mercado Pago ARS; Stripe ARS, USD y EUR; PayPal USD y EUR. Solo emulador y solo
  monedas de dos decimales que el emulador admite.

### Cambiado

- La identidad de cuenta incluye la familia de API del proveedor (`payment_intents`, `orders_v2`); Mercado
  Pago conserva `orders`, de modo que ninguna identidad existente cambia.

## [0.4.0-alpha.1] — 2026-11-01 (candidato local, nunca publicado)

Incluye todo lo de `0.3.0-alpha.2` más devoluciones operativas.

### Añadido

- Puente Laravel: acciones `PaymentController::refund` (POST con `reference` del pago, `amount` decimal
  exacto y `currency`, más cabecera `Idempotency-Key`) y `refundShow` (lectura de la operación). Exigen
  la capability `refund` sobre la cuenta del pago; una incertidumbre del proveedor responde 202 con la
  operación durable, que recupera el worker sin reenviar.
- Configuración `payments.refunds_enabled` (por defecto `false`): sin ella las escrituras nuevas de
  refund responden `refunds_disabled` (503) antes de reservar; lecturas y recuperación no dependen de ella.

### Cambiado

- `PostgresStore::confirmRefund` registra un evento `payment.refunded` (versión 1) por cada refund
  operativo confirmado, para que el producto aplique su efecto una vez. Los de compensación
  (`compensation:`) no lo emiten: se acusan por su propio flujo. Confirmar dos veces el mismo refund no
  cambia nada. Un consumidor sin `FinancialEventConsumer` verá ese evento pendiente en el outbox, como ya
  ocurría con los refunds externos.

## [0.3.0-alpha.2] — 2026-10-30

Primera 0.3 publicada: incluye todo lo de `0.3.0-alpha.1` más lo de abajo.

### Cambiado

- Pint gatea `src/Orchestration` y `tests/Orchestration` (`composer format:check`, también en CI); el CI
  carga `pdo_pgsql`. README: comando de la suite de PostgreSQL.
- PHPStan nivel 8 sin baseline en toda la biblioteca: se borra `phpstan-baseline.neon` y
  `src/Orchestration` declara los tipos de sus arrays (filas `array<string, mixed>`, listas, formas).

### Corregido

- Orquestación: una fila que debía existir y no existe ahora lanza `PaymentError` en vez de un aviso
  o un `TypeError` de PHP. Un intent o una operación que falta da `not_found` (404). La fila que sigue
  a un upsert o un agregado que falta da `missing_row` (503), y un JSON o una columna que no son lo
  esperado dan `invalid_row` (503).
- `Coordinator::apply()` comprueba dentro de la transacción que un pago `Captured` trae importe y
  transacción (`capture_evidence_missing`), en lugar de suponerlo.
- Puente Laravel: el webhook descarta las cabeceras con valor nulo antes de verificar la firma;
  `payments:run` trata como vacía una opción que no es texto; el proveedor lee la config con
  `$app->make('config')`.

## [0.3.0-alpha.1] — 2026-10-29 (candidato local, nunca publicado)

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

[Sin publicar]: https://github.com/charlietyn/payment-gateways/compare/v0.3.0-alpha.2...HEAD
[0.3.0-alpha.2]: https://github.com/charlietyn/payment-gateways/compare/v0.2.0...v0.3.0-alpha.2
[0.2.0]: https://github.com/charlietyn/payment-gateways/releases/tag/v0.2.0

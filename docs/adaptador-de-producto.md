# Adaptador de producto

La orquestación cobra **sujetos**: algo del producto que tiene precio (una factura, una matrícula, una
entrada, una cuota, una reserva). No sabe nada del negocio; se lo pregunta a un **adaptador de producto**
(`Orchestration\Laravel\ProductAdapter`):

| La orquestación pregunta | Método |
| --- | --- |
| ¿Quién paga? | `actor(Request)` |
| ¿Existe, cuánto vale, quién es el dueño, sigue siendo pagable? | `resolve(Actor, string $subject): Quote` |
| Ya se cobró: entrégalo (una vez por evento) | `deliver(string $eventId, string $subject, array $payment): bool` |
| No se pudo entregar: hay que devolver el dinero | `compensate(...)` |
| Hubo un reembolso o una disputa | `consume(string $eventId, string $type, array $payload)` |
| ¿Está listo para trabajar? | `ready(): bool` |

Hay dos formas de tenerlo: **configurarlo** (`TableSubjectAdapter`, el caso normal) o **escribirlo** (una clase
del proyecto que implementa `ProductAdapter`, para negocios que no caben en "cobrar una fila").

## 1. `TableSubjectAdapter`: cobrar una fila de una tabla del proyecto

El producto crea la fila **con el precio ya calculado** (impuestos, descuentos, cantidad, envío: todo antes).
El adaptador la lee, deja que la orquestación la cobre y, al capturarse el dinero, la marca como pagada. Lo
demás (dar acceso, enviar un correo, preparar un envío) lo hacen **listeners de eventos**.

```php
// config/payments.php
'adapter' => \Ronu\PaymentGateways\Orchestration\Laravel\Products\TableSubjectAdapter::class,
'product' => [
    'connection' => 'db',                       // la del sujeto; las tablas de recibos van en la misma
    'table' => 'academy.enrollments',
    'reference' => 'code',                      // columna ÚNICA que identifica el sujeto
    'amount' => ['column' => 'price', 'unit' => 'decimal'],   // o 'minor' (entero en céntimos)
    'currency' => ['column' => 'currency'],     // o ['fixed' => 'ARS']
    'ownership' => ['strategy' => 'owner', 'column' => 'owner_scope'],
    'tenant' => ['column' => 'tenant_id'],      // opcional salvo con strategy tenant
    'status' => [
        'column' => 'status',
        'payable' => ['pending'],
        'paid' => 'paid',
        'refunded' => 'refunded',               // opcionales: si faltan, solo se notifica
        'partiallyRefunded' => 'partially_refunded',
        'disputed' => 'disputed',
    ],
    'deliverable' => ['column' => 'is_open'],   // opcional: "aún se puede entregar" (cupo)
    'expires' => ['column' => 'expires_at', 'lateDelivery' => 'reject'], // o ['ttlMinutes' => 60]
    'revision' => ['column' => 'price_version'],// opcional; si falta, la revisión es el propio precio
    'description' => ['column' => 'title'],     // opcional: texto del ítem en la página del proveedor
    'paidAt' => ['column' => 'paid_at'],        // opcional
    'softDelete' => ['column' => 'deleted_at'], // opcional
    'retries' => true,                          // reintentar tras un pago rechazado (por defecto sí)
    'receipts' => ['table' => 'academy.payment_subject_receipts'],
    'notifications' => ['table' => 'academy.payment_subject_notifications'],
    'actorResolver' => \App\Payments\PaymentActors::class,
],
```

Si algo está mal, `ready()` devuelve `false`, registra **todos** los problemas en el log y la API responde
`adapter_not_ready` (503), nunca un 500.

### 1.1 Quién paga: el actor y las estrategias

El **actor** es la **aplicación cliente** autenticada (el BFF del frontend, una app móvil, un TPV), no la persona.
La persona la protege el producto (enlace no adivinable) y el BFF (sesión anónima ligada a la orden). El
proyecto aporta un `ActorResolver` (el generador emite uno sobre `ronu/laravel-app-context`).

| `ownership.strategy` | Quién puede pagar | Uso típico |
| --- | --- | --- |
| `owner` | solo la aplicación que creó el sujeto (`ownership.column` = `actor->scope()`) | sujetos privados de una integración |
| `tenant` | cualquier aplicación del mismo tenant (`tenant.column` = `actor->tenant`) | un tenant con web, móvil y TPV |
| `link` | cualquier aplicación del proyecto (y del tenant si hay `tenant.column`) que tenga la referencia | enlace de pago por correo o WhatsApp; paga un tercero |

Con `link` la referencia es una capacidad: debe tener al menos 16 caracteres y no ser adivinable (UUID, token);
una referencia más corta no se resuelve nunca. Con `tenant` y `link` el sujeto es **compartido**: la
orquestación no deja que una segunda aplicación abra un cobro mientras otra tiene uno en curso
(`subject_already_reserved`, 409).

### 1.2 Reintento tras un pago rechazado

Con `retries: true`, si el último intento terminó **sin dinero** (rechazado o cancelado), el siguiente cobro del
mismo sujeto se abre como un intento nuevo, guardado internamente como `<referencia>#<n>` (el primero es la
referencia tal cual). El que paga sigue usando la referencia sin `#`. Mientras un intento está en curso,
pagado, reembolsado o en disputa, el sujeto sigue reservado. Una referencia con `#` no se acepta desde fuera.

### 1.3 Entrega y casos extremos

`deliver` bloquea la fila, deduplica por `eventId` y entrega solo si la fila **sigue pagable, entregable, con
el mismo importe y moneda y sin caducar** (salvo `lateDelivery: accept`). Si no, registra el rechazo con su
motivo y la orquestación pide la compensación:

| Motivo | Cuándo |
| --- | --- |
| `already_paid` | otra pestaña o aplicación ya lo pagó |
| `not_payable` | el producto cambió el estado o cerró el cupo |
| `amount_changed` | el precio de la fila cambió después del cobro |
| `expired` | el dinero llegó después de `expires` |

Reembolsos y disputas se registran una vez y se proyectan en el estado **solo desde un estado que puso el pago**
(si el producto ya movió la fila a otro estado, se respeta) y nunca con una observación más vieja que otra ya
aplicada (los eventos pueden llegar desordenados).

### 1.4 Eventos

Se emiten **después del commit** y **al menos una vez**: el mismo `eventId` puede llegar de nuevo, así que
los listeners deben ser idempotentes por `eventId`.

| Evento | Cuándo | Para qué |
| --- | --- | --- |
| `PaymentSubjectDelivered` | cobrado y marcado pagado | dar acceso, enviar recibo, preparar envío |
| `PaymentSubjectRejected` (`reason`) | cobrado pero no entregable | avisar al cliente; llega una compensación |
| `PaymentSubjectCompensationRequested` | hay que devolver el dinero | tarea para el operador |
| `PaymentSubjectCompensationAcknowledged` | el operador registró cómo se resolvió | cerrar la tarea |
| `PaymentSubjectRefunded` (`refundedMinor`, `full`) | reembolso completado | revocar acceso si `full` |
| `PaymentSubjectDisputed` (`state`) | contracargo abierto, ganado o perdido | congelar o restaurar |

Laravel resuelve los listeners por clase concreta (o interfaz), **no por clase padre**: escucha cada evento por
su nombre o usa el comodín `Ronu\PaymentGateways\Orchestration\Laravel\Products\Events\*`.

### 1.5 Tablas de soporte (misma conexión que el sujeto)

| Tabla | Columnas |
| --- | --- |
| recibos | `event_id` (único), `subject`, `accepted` (bool), `reason` (nullable), `created_at` |
| notificaciones | `event_id` (único), `subject`, `type`, `observation_version`, `minor` (nullable), `currency` (nullable), `state` (nullable), `created_at` |

### 1.6 Fuera de alcance

Suscripciones y cobro recurrente (un sujeto por periodo es la alternativa), marketplace o reparto entre
vendedores, preautorizar y capturar después, y pagar parcialmente un mismo sujeto (usa cuotas: una fila por
cuota).

## 2. Adaptador propio

Cuando el negocio no es "cobrar una fila" (retener plazas con capacidad compartida, estados en una tabla de
estados, cotizaciones complejas), el proyecto escribe una clase que implementa `ProductAdapter` (y
`ManualCompensation` si registra resoluciones del operador) y la declara en `payments.adapter`. Debe cumplir el
mismo contrato: precio y dueño autoritativos en `resolve`, entrega deduplicada por `eventId` con su decisión
(también `false`), compensación que nunca asume el reembolso, y consumo de eventos que ignora observaciones
viejas. Si quiere reintentos o sujetos compartidos, lo declara en su `Quote` (`retryable`, `shared`).

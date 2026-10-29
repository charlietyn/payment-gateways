# Orquestación de pagos (`Ronu\PaymentGateways\Orchestration`)

Coordinación financiera duradera sobre PostgreSQL, encima del contrato de pasarelas. Antes era el
paquete `ronu/payment-orchestration` (último candidato `0.1.0-alpha.8`); desde `0.3.0` vive en esta
biblioteca. Es PHP puro con puertos explícitos para el sujeto, la cuenta, el actor, el almacenamiento y
la entrega. No necesita ninguna entidad del producto ni una fachada de Laravel.

> **Estado:** candidato de integración sin publicar. Sólo está acreditado para Laravel 12 +
> PostgreSQL + Mercado Pago Orders. Stripe, PayPal y el refund operativo siguen deshabilitados hasta
> que tengan su gate completo.

## Migrar desde `ronu/payment-orchestration`

| Antes                                         | Ahora                                                   |
| --------------------------------------------- | ------------------------------------------------------- |
| `composer require ronu/payment-orchestration` | `composer require ronu/payment-gateways` (ya la incluye) |
| `Ronu\PaymentOrchestration\…`                 | `Ronu\PaymentGateways\Orchestration\…`                  |
| `Ronu\PaymentOrchestration\Laravel\…`         | `Ronu\PaymentGateways\Orchestration\Laravel\…`          |

El esquema de las tablas, los eventos y el protocolo de los workers no cambian: sólo cambian el nombre
del paquete y el namespace.

## Almacenamiento

El consumidor aporta siete tablas PostgreSQL versionadas y las mapea en su configuración. El paquete
no ejecuta migraciones al arrancar. La tabla de auditoría de reprocesos (`replays`) es opcional; sin
ella, el reproceso manual falla cerrado con `replay_audit_unavailable`.

## Checkout

- `PREPARED` y `SENDING` se persisten **antes** de la E/S remota. Un resultado ambiguo queda como
  `UNKNOWN`.
- Un reintento con la misma identidad comercial no repite un comando ya enviado.
- Captura, ledger, outbox y confirmación de la operación se confirman juntos. La entrega al producto es
  una decisión duradera aparte.
- El adaptador del producto debe deduplicar la entrega y la compensación por ID de evento.

## Refunds

- `RefundCoordinator` está **deshabilitado por defecto** y no registra ningún endpoint HTTP.
- Reserva el saldo reembolsable y confirma `SENDING` antes del transporte. Las reservas `UNKNOWN` y
  `PENDING` se conservan.
- La recuperación usa el puerto de sólo lectura `RefundEvidenceReader` con evidencia individual del
  proveedor (identidad, importe, moneda). Nunca repite un POST ni construye un movimiento a partir de
  totales agregados.
- `GatewayRefundEvidenceReader` usa el contrato opcional `Contracts\RefundLookup` para un ID de
  refund conocido. Sin ID devuelve "sin resolver" y no hace E/S: no se correlaciona por importe.
- Los permisos de lectura, los de refund y la propiedad de la cuenta se comprueban por separado.
- `ExternalRefundCoordinator` registra refunds hechos fuera de la aplicación a partir de evidencia
  individual, con comprobación de versión local. Una reserva `UNKNOWN` sin correlacionar sigue
  retenida aunque el importe coincida. PostgreSQL suma el ledger en numérico antes de convertirlo a
  entero de PHP.

## Compensación

Si el producto no está disponible, se confirma `compensation_required` antes de llamar a su
adaptador. Esa decisión es irreversible y sobrevive a los fallos del callback. El estado financiero
(`compensationState`) y el acuse del producto (`fulfillmentState`) son independientes.

## Disputas

`DisputeCoordinator` lee una `DisputeEvidence` de la cuenta histórica. El ciclo permite
`opened → won/lost` y rechaza regresiones o resultados finales contradictorios. Una disputa activa o
perdida bloquea nuevos refunds y la entrega. Los ajustes de comisión, las apelaciones y la evidencia
sin principal conocido no están soportados en v1.

## Eventos financieros

Requieren un `FinancialEventConsumer`. Sin él, el outbox los retiene con reintentos acotados. El
consumidor debe deduplicar por ID de evento estable y usar `observationVersion`, porque la entrega
concurrente puede reordenarlos. Los payloads no llevan IDs del proveedor ni cuerpos crudos.

## Workers y cola de recuperación

- `RecoveryWorker` reconcilia los pagos y refunds pendientes. Cada observación se hace fuera de
  transacción; `PREPARED` nunca se envía desde el worker.
- Un elemento se agota tras **ocho** intentos fallidos y se queda donde está: no se mueve ni se borra.

## Operación (Laravel)

`Orchestration\Laravel\PaymentServiceProvider` se registra **explícitamente**; no se autodescubre. Comandos
`php artisan payments:run <acción>`:

| Acción                                                                                                                 | Qué hace                                                                                                                                      |
| ---------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------- |
| `exhausted --queue=inbox\|outbox\|intents [--reference=<item>]`                                                         | Lista los elementos agotados sólo por identificador, o busca uno concreto.                                                                    |
| `replay-exhausted --queue=<q> --reference=<item> --actor=<id> --evidence=<ref> --key=<clave>`                          | Devuelve UN elemento agotado a su presupuesto de reintentos y registra quién, por qué y cuándo, en la misma transacción. Idempotente por clave. |
| `metrics`                                                                                                              | Pendientes, agotados y estancados, y la antigüedad del más viejo.                                                                             |
| `alerts --stale=<segundos>`                                                                                            | Condiciones accionables; sale con código 2 si hay alguna.                                                                                     |
| `observe-refund`, `compensation-status`, `acknowledge-compensation`, `recover-refund`                                  | Operaciones de consola revisadas: exigen actor, referencia de evidencia y, en su caso, clave de idempotencia.                                 |

Ninguna salida lleva payloads, cuerpos del proveedor, credenciales ni datos personales. No escribas
datos de clientes ni secretos en las referencias.

## Tests

```bash
composer test                       # incluye los tests de orquestación que no necesitan base de datos
composer test:orchestration-postgres
```

La suite de PostgreSQL se niega a arrancar fuera de un entorno aislado: base marcada, rol sin
privilegios de superusuario y bloqueo consultivo tomado por el runner. Hoy la lanza el generador con
`scripts/payment-feature/run-orchestration.mjs`. Ningún test usa un proveedor real.

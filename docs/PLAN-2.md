# Plan maestro 2 — biblioteca genérica, lista para las APIs reales

Sigue a [PLAN.md](PLAN.md) (L0–L8: núcleo y tres drivers contra el emulador).
Objetivo: que `ronu/payment-gateways` sirva para **cualquier pasarela** por
configuración, y que Mercado Pago, Stripe y PayPal funcionen igual contra el
Payment Emulator Lab (Plaza, Vector, Harbor) y contra sus APIs reales.

## 1. Decisiones (2026-10-21)

| # | Decisión | Elegido |
|---|---|---|
| D1 | Formas de cobro | **C**: página del proveedor (`createCheckout()`) **y** formulario incrustado (`PaymentMethod`), empezando por la primera |
| D2 | Cambios incompatibles antes de 1.0 | Sí |
| D3 | Cliente HTTP | Guzzle (se inyecta `ClientInterface`) |
| D4 | Alojamiento | GitHub + Packagist |
| D5 | Configuración | Legible: secciones fijas (`driver`, `environment`, `credentials`, `webhooks`, `options`, `emulator`, `http`), validada al construir |

## 2. Hallazgo que guía el plan

El emulador **no** es una copia completa: no emula Checkout Pro (preferences), ni
Checkout Sessions, ni Elements, ni la tokenización de tarjetas (ver
`payment-emulator-lab/docs/es/fidelity/*.md`). En real, Mercado Pago y Stripe
**no cobran tarjeta** sin uno de esos caminos. Por eso existen D1 y M8/M10.

## 3. Fases

Leyenda: ☐ pendiente · ◐ en curso · ☑ hecho.

| ID | Fase | Criterio de hecho | Estado |
|---|---|---|---|
| M0 | Primer commit, CI (GitHub Actions: PHPUnit + PHPStan en PHP 8.2–8.4) | Commit inicial; workflow en `.github/workflows/ci.yml` | ☑ github.com/charlietyn/payment-gateways, CI en verde (PHP 8.2–8.4) |
| M1 | Núcleo genérico: `GatewayConfig` legible, `Environment`, presets como datos, `GatewayFactory::extend()`, `Capability` + `supports()` | Un driver externo se registra sin tocar `src/`; errores de config con la clave exacta | ☑ |
| M2 | Emulador fuera de los drivers | Solo `GatewayConfig::emulatorHeaders()`/`isEmulator()`; ningún driver lee claves `emulator_*` | ☑ (contenido en puntos marcados, no un middleware) |
| M3 | Transporte de producción: reintentos con la misma clave, `Retry-After`, log PSR-3 con secretos ocultos, caché PSR-16 del token OAuth de PayPal | Tests de reintento y ocultación | ☑ |
| M4 | Webhooks canónicos: enum de eventos, `DedupStore`, verificación remota opcional en PayPal | Tabla de mapeo con tests por proveedor | ☑ |
| M5 | Mercado Pago real: `ticket_url` confirmado en el OpenAPI; tarjeta con Brick (`PaymentMethod::card`) | Tests | ☑ — cuerpos validados contra los OpenAPI oficiales (`tests/Contract`) |
| M6 | Stripe real: confirmar solo con `pm_…`; `api_version` en `options` | Tests | ☑ |
| M7 | PayPal real: config nueva, `experience_context` (marca, envío, idioma) | Tests | ☑ |
| M8 | Página del proveedor: `Contracts\HostedCheckout` — Checkout Pro, Checkout Session, aprobación PayPal | Unitarios + recorrido en el emulador | ☑ — falta probarlo en sandbox (M10) |
| M9 | Kit de conformidad `Testing\GatewayConformanceTestCase` para drivers nuevos; `Testing\FakeMercadoPago` | Los tres drivers lo pasan | ☑ — en el emulador; en CI contra el fake |
| M10 | E2E en sandbox real (Stripe test, PayPal sandbox, usuarios de prueba MP) | Informe de diferencias emulador ↔ real | ☐ necesita credenciales |
| M11 | Packagist, semver, guía "añadir una pasarela", L7 (`eros/payments`) | `v1.0.0` publicada | ◐ — `v0.2.0`, CHANGELOG y guía hechos; falta Packagist (lo envía el dueño), M10 y L7 antes de `v1.0.0` |

Mejoras propuestas al emulador (otro repositorio, no bloquean): Preferences en
Plaza y Checkout Sessions en Vector (para probar M8 en local), `amount_refunded`
en Vector, webhooks de capture/refund lanzados por el comercio, y que cancelar un
pago capturado responda 400 y no 500 (`assertTransition` lanza un error genérico;
lo detectó el kit de M9).

## 4. Puntos a verificar contra el sandbox (M10)

- Mercado Pago: si las credenciales de prueba deben usar `sandbox_init_point` o `init_point`.
- Mercado Pago: formato exacto de `expiration_date_to` aceptado y búsqueda por `external_reference`.
- Stripe: `payment_intent` de una Checkout Session es `null` hasta completarla (versiones recientes).
- PayPal: `shipping_preference` y `brand_name` dentro de `payment_source.paypal.experience_context`.

## 5. Registro

| Fecha | ID | Evidencia |
|---|---|---|
| 2026-10-21 | M0 | Commit inicial `29d0738` (estado de PLAN.md) |
| 2026-10-21 | M1, M2, M5, M8 | `composer test` → **66 tests, 275 aserciones OK**; PHPStan nivel 8 sin errores; `composer test:integration` contra el emulador (:8090) → **5 tests, 49 aserciones OK**, incluido `createCheckout()` → pago → `getCheckout()` → captura en Plaza, Vector y Harbor |
| 2026-10-21 | M7, M6, M3, M4 | `b00e208` token de PayPal en caché PSR-16 (reintento único tras 401); `1ee8967` Stripe lee devoluciones y disputas del cargo expandido; `408f274` reintentos con la misma clave de idempotencia, `Retry-After`, log PSR-3 sin cabeceras ni cuerpos; `a51fd65` `EventType` canónico, `EventDeduplicator`, verificación remota en PayPal |
| 2026-10-21 | M5 | `48181bc`: cada escritura de los tres drivers validada contra los OpenAPI oficiales (`composer test:contract`, 3 tests OK) y workflow semanal en verde. Totales: **83 tests, 365 aserciones**; integración **5 tests, 49 aserciones** |
| 2026-10-21 | M9 | Kit `Testing\GatewayConformanceTestCase` (13 pruebas) y `Testing\FakeMercadoPago`. Unitarios **115 tests, 478 aserciones OK** (incluye el kit contra el fake); integración contra :8090 **44 tests, 209 aserciones**: el kit pasa en Plaza, Vector y Harbor salvo "cancelar un pago capturado" en Plaza/Vector, marcado incompleto (el emulador responde 500). También: cliente HTTP inyectable en Laravel (`payment-gateways.http`), `emulator.api_url` con o sin `/plaza`, tabla de estados de Orders con un test por fila |

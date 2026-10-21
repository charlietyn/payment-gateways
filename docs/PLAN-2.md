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
| M0 | Primer commit, CI (GitHub Actions: PHPUnit + PHPStan en PHP 8.2–8.4) | Commit inicial; workflow en `.github/workflows/ci.yml` | ◐ falta crear el repo en GitHub y ver el CI en verde |
| M1 | Núcleo genérico: `GatewayConfig` legible, `Environment`, presets como datos, `GatewayFactory::extend()`, `Capability` + `supports()` | Un driver externo se registra sin tocar `src/`; errores de config con la clave exacta | ☑ |
| M2 | Emulador fuera de los drivers | Solo `GatewayConfig::emulatorHeaders()`/`isEmulator()`; ningún driver lee claves `emulator_*` | ☑ (contenido en puntos marcados, no un middleware) |
| M3 | Transporte de producción: reintentos con la misma clave, `Retry-After`, log PSR-3 con secretos ocultos, caché PSR-16 del token OAuth de PayPal | Tests de reintento y ocultación | ☐ |
| M4 | Webhooks canónicos: enum de eventos, `DedupStore`, verificación remota opcional en PayPal | Tabla de mapeo con tests por proveedor | ☐ |
| M5 | Mercado Pago real: `ticket_url` confirmado en el OpenAPI; tarjeta con Brick (`PaymentMethod::card`) | Tests | ☑ — falta validar cuerpos contra `contracts/plaza/orders.json` |
| M6 | Stripe real: confirmar solo con `pm_…`; `api_version` en `options` | Tests | ◐ — falta reflejar devoluciones leyendo el cargo |
| M7 | PayPal real: config nueva, `experience_context` (marca, envío, idioma) | Tests | ◐ — falta caché PSR-16 (M3) |
| M8 | Página del proveedor: `Contracts\HostedCheckout` — Checkout Pro, Checkout Session, aprobación PayPal | Unitarios + recorrido en el emulador | ☑ — falta probarlo en sandbox (M10) |
| M9 | Kit de conformidad `GatewayContractTest` para drivers nuevos | Los tres drivers lo pasan | ☐ |
| M10 | E2E en sandbox real (Stripe test, PayPal sandbox, usuarios de prueba MP) | Informe de diferencias emulador ↔ real | ☐ necesita credenciales |
| M11 | Packagist, semver, guía "añadir una pasarela", L7 (`eros/payments`) | `v1.0.0` publicada | ☐ |

Mejoras propuestas al emulador (otro repositorio, no bloquean): Preferences en
Plaza y Checkout Sessions en Vector (para probar M8 en local), `amount_refunded`
en Vector, webhooks de capture/refund lanzados por el comercio.

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

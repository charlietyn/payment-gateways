# Setup local de pagos — candidato 0.8.0-alpha.1

`payments:setup` pertenece a esta biblioteca. El generador configura sus nombres de variables y los
scripts de arranque; el producto conserva su adaptador y sus datos de negocio. El comando no migra,
arranca servicios, crea pagos, rota claves existentes ni publica paquetes.

## Uso

```sh
php artisan payments:setup --dry-run --json
php artisan payments:setup --emulator=http://localhost:8090 --emulator-checkout=http://localhost:3101 --emulator-token-file=/ruta/privada/admin.token --frontend-env=/ruta/front/.env --json
php artisan payments:setup --emulator=http://localhost:8090 --emulator-checkout=http://localhost:3101 --emulator-token-file=/ruta/privada/admin.token --frontend-env=/ruta/front/.env --json
```

También se acepta `PAYMENTS_EMULATOR_ADMIN_TOKEN` en el entorno del proceso. Nunca se pasa el token en
la línea de comandos. `--partner` selecciona el app_code (por defecto `<project>-payments`) y `--tenant`
el tenant (por defecto el proyecto). En JSON, un partner nuevo requiere `--frontend-env` para no perder
la clave ni incluirla en el informe. Sin JSON ni front, la clave nueva se muestra una sola vez: esa
ejecución no se debe redirigir a un log. No se imprime ninguna credencial del proveedor.

La segunda ejecución conserva aplicaciones, claves, secretos de sesión y ficheros sin cambios. Los pasos
de aviso se mantienen como `skipped`; los pasos de provisión quedan `unchanged`. `--dry-run` realiza
lecturas y comprobaciones, pero no crea ficheros ni realiza POST/PATCH. Devuelve 1 ante `failed`, y 0
cuando solo hay pasos correctos o avisos opcionales; un 0 con `skipped` no certifica una compra completa.

## Contrato del consumidor

Registrar `Orchestration\Laravel\PaymentServiceProvider` y `ronu/laravel-app-context ^1.3.1`. Las tablas de
`payments.tables` deben existir; `ProductAdapter::ready()` debe devolver true, cada cuenta debe tener
su ruta POST `/client/payments/webhooks/<alias>` y el scheduler de recuperación debe estar declarado.
El proceso scheduler debe ejecutarse para entregar pagos: la comprobación de declaración no lo arranca.
Antes de escribir, quitar una caché de configuración previa con el runner adecuado del proyecto.

Además de `payments.accounts`, declarar el mapeo exacto que ya consumen sus llamadas `env()`:

```php
'setup' => [
    'accounts' => [
        'mp' => [
            'merchant_id' => 'PAYMENTS_MP_MERCHANT_ID',
            'access_token' => 'PAYMENTS_MP_ACCESS_TOKEN',
            'webhook_secret' => 'PAYMENTS_MP_WEBHOOK_SECRET',
            'api_url' => 'PAYMENTS_MP_API_URL',
            'checkout_url' => 'PAYMENTS_MP_CHECKOUT_URL',
        ],
    ],
],
```

PayPal añade `client_id`; su `clientSecret` se almacena en la variable de `access_token`. La aplicación
del emulador se busca por nombre exacto `<project>-<alias>` a través de todas las páginas. Se crea su
merchant una sola vez y se conserva el webhook secret. Para adoptar una aplicación histórica cuyo
nombre no sigue esa regla, `payments.setup.applications.<alias>` puede declarar su id existente.
No se reescriben automáticamente las identidades con historial. Un conflicto requiere revisar la
cuenta/aplicación correcta o declarar otra cuenta; no existe `--force` que cambie el pasado.

Con driver `config`, el consumidor debe fusionar `storage/app/payments/partner-clients.php` en
`app-context.client_repository.config.clients`. El fichero contiene hashes, no claves en claro. El
directorio se protege con `.gitignore`. La biblioteca nunca edita `config/app-context.php`.
Con `eloquent`, se respetan conexión y modelos/tablas configurados; se soporta el esquema separado
`api_apps` + `api_app_keys`. La actualización conserva hashes, revocación y restricciones ajenas.
El conjunto de capacidades de pago del partner se reemplaza por create/read, las cuentas actuales
y refund si está habilitado; usar un partner dedicado a pagos.

El front recibe `NUXT_PAYMENTS_API_BASE_URL`, `NUXT_PAYMENTS_CLIENT_ID`, `NUXT_PAYMENTS_API_KEY`,
`NUXT_PAYMENTS_SESSION_SECRET` y, con emulador, `NUXT_PAYMENTS_LOCAL_ACTIONS`. Un front propio puede
cambiar esos nombres mediante `payments.setup.frontend` con claves `base_url`, `client_id`, `api_key`,
`session_secret`, `local_actions`; no se incluyen nombres específicos de un producto en la biblioteca.
Una clave preexistente del front se verifica contra el hash del partner; no se sustituye ni rota.

## Escritura, seguridad y recuperación

- Emulador: exclusivamente HTTP loopback, sin usuario/contraseña, path, query ni fragmento. Se rechaza
  `APP_ENV=production` antes de escribir. Los redirects HTTP del control plane y checkout están desactivados.
- `.env` conserva comentarios, claves ajenas y EOL. Se hace backup único `.env.backup-<fecha>-<sufijo>`,
  escritura temporal y exclusión mutua. Ignorar `.env.backup-*` y `.env.payments-*` en el consumidor:
  también pueden contener secretos. Los backups nunca deben entrar en evidencias ni en Git.
- `payments.setup.value_store` puede identificar una clase del contenedor que implemente
  `Setup\ValueStore` (`read(): array<string,string>`, `write(array): created|updated|unchanged`). La
  integración Floci debe separar secretos y valores públicos y usar Secrets Manager; no hay fallback
  silencioso a `.env` cuando existe configuración Floci.
- Sin `--emulator`, las ventas se conservan sin modificar y se explica lo pendiente. La orquestación
  actual soporta emulador; no se declara lista para cobros reales. Sin token o emulador disponible se
  prepara lo independiente y no se abren ventas. Una respuesta 401/403 es fallo explícito.
- Una aplicación encontrada sin credenciales locales produce `emulator_credentials_missing_no_rotation`.
  Restaurar las credenciales originales desde el almacén privado; el setup nunca rota por conveniencia.
- Después de cambiar variables o clientes, reiniciar los procesos consumidores para que relean su
  configuración. La provisión no implica que el webhook sea alcanzable desde otro contenedor/host.

## Validación

Las pruebas unitarias usan Guzzle simulado y SQLite en memoria con los repositorios reales de
laravel-app-context. La integración `SetupEmulatorTest` valida altas y reejecución de los tres proveedores
contra el emulador aislado del arnés. La puerta de proyecto generado y compra en navegador corresponde
al generador y no se sustituye por estas pruebas.

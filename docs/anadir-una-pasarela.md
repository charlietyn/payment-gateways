# Añadir una pasarela

Una pasarela nueva es una clase que implementa `Contracts\Gateway`, se registra
con `GatewayFactory::extend()` y pasa el kit de conformidad. No hace falta tocar
la biblioteca: el driver puede vivir en tu aplicación o en su propio paquete.

Como ejemplo, un proveedor imaginario **Acme** con API JSON, cobro por redirección
y webhooks firmados con HMAC.

## 1. La configuración

El consumidor escribe lo mismo que para los drivers incluidos:

```php
'acme' => [
    'driver' => 'acme',
    'environment' => env('ACME_ENVIRONMENT', 'sandbox'),   // emulator | sandbox | live
    'credentials' => ['api_key' => env('ACME_API_KEY')],
    'webhooks' => ['secret' => env('ACME_WEBHOOK_SECRET')],
    'options' => ['merchant_account' => env('ACME_MERCHANT')],
],
```

`GatewayConfig` valida las secciones al construirse y te da:

| Método | Para qué |
|---|---|
| `credential('api_key')` | Credencial obligatoria; si falta, `ConfigurationException` con la clave exacta. Llámala en el constructor para fallar pronto. |
| `credentialOrNull()`, `option()`, `webhook()` | Valores opcionales con su valor por defecto |
| `resolveBaseUrl(HOSTS, 'perfil')` | `base_url` si se dio; si no, el host del entorno (`sandbox`, `live`) o `{emulator.api_url}/{perfil}` |
| `isEmulator()`, `emulatorHeaders()` | Lo único distinto en el emulador (cabecera `X-Emulator-Scenario`) |

## 2. El driver

```php
final class AcmeGateway implements Gateway
{
    public const HOSTS = ['sandbox' => 'https://sandbox.acme.example', 'live' => 'https://api.acme.example'];

    private Transport $http;

    public function __construct(private readonly GatewayConfig $config, Services $services = new Services())
    {
        $config->credential('api_key');

        $this->http = Transport::for(
            $config,
            $config->resolveBaseUrl(self::HOSTS, 'acme'),
            $services,
            fn (array $body): ?string => isset($body['error']['code']) ? (string) $body['error']['code'] : null,
        );
    }

    public function name(): string { return 'acme'; }

    public function supports(Capability $capability): bool
    {
        return in_array($capability, [Capability::PartialRefund], true);
    }

    public function createPayment(CreatePayment $request): Payment
    {
        $body = $this->http->request('POST', '/payments', [
            'Authorization' => 'Bearer ' . $this->config->credential('api_key'),
            'Idempotency-Key' => $request->idempotencyKey,
        ] + $this->config->emulatorHeaders(), [
            'amount' => $request->amount->minor,
            'currency' => $request->amount->currency,
            'reference' => $request->reference,
            'return_url' => $request->returnUrl,
        ]);

        return $this->toPayment($body ?? []);
    }

    // getPayment, capture, cancel, refund, parseWebhook, paymentIdForEvent…
}
```

Reglas que el kit comprueba y que conviene tener presentes desde el principio:

- **Idempotencia en todas las escrituras**, con la cabecera que documente el proveedor.
  Si es una de `Transport::IDEMPOTENCY_HEADERS`, el transporte reintenta la escritura
  con la misma clave. Si el proveedor usa otra, pásale `idempotent: true` a `request()`.
- **`getPayment()` devuelve `null`** si el id no existe (`allowNotFound: true`) o no
  tiene el formato del proveedor. Nunca metas un id sin validar en una ruta.
- **Estados**: traduce los del proveedor a `PaymentStatus` en un `mapStatus()` estático
  con un test por fila. Si no sabes qué es un estado, devuelve `Unknown`, nunca `Captured`.
- **Errores**: deja que `Transport` lance `GatewayException`. `retryable` es `true` solo
  para red, 408, 429 y 5xx. Si una operación no aplica al estado del pago, lanza
  `UnsupportedOperation`.
- **Webhooks**: verifica la firma sobre el cuerpo crudo con `hash_equals`, con
  tolerancia de tiempo. Sin secreto configurado, rechaza
  (`InvalidWebhookSignature::NOT_CONFIGURED`). Rellena `WebhookEvent::$kind` con
  un `EventType`.
- **Dinero**: `Money` en unidades menores. Convierte al formato del proveedor solo
  en el borde (`toDecimal()`, `minor`).
- **`raw`**: guarda el cuerpo del proveedor sin tokens de tarjeta.
- **`supports()` y las interfaces**: `HostedCheckout` si y solo si
  `supports(Capability::HostedCheckout)`.

## 3. Registrarlo

```php
GatewayFactory::extend('acme', fn (GatewayConfig $config, Services $services) => new AcmeGateway($config, $services));

$gateway = GatewayFactory::make('acme', $config['gateways']['acme']);
```

En Laravel, en el `register()` de un service provider de tu app. El manager usa
la misma fábrica y pasa el cliente HTTP, la caché y el logger de la aplicación.

## 4. Pasar el kit de conformidad

```php
use Ronu\PaymentGateways\Testing\GatewayConformanceTestCase;

final class AcmeConformanceTest extends GatewayConformanceTestCase
{
    protected function gateway(): Gateway
    {
        return GatewayFactory::make('acme', [
            'driver' => 'acme', 'environment' => 'sandbox',
            'credentials' => ['api_key' => getenv('ACME_API_KEY')],
            'webhooks' => ['secret' => 'whsec_test'],
        ]);
    }

    protected function currency(): string { return 'USD'; }

    /** Lo que hace el comprador en Payment::$nextAction o en Checkout::$url. */
    protected function buyerPays(Gateway $gateway, string $resourceId): void
    {
        // Sandbox: la API de pruebas del proveedor, o un navegador. Fake: $fake->pay($resourceId).
    }

    // Opcionales:
    protected function unknownPaymentId(): string { return 'pay_' . bin2hex(random_bytes(8)); }
    protected function signedWebhook(Gateway $gateway, Payment $payment): ?array { return null; }
    protected function readsRefundsBack(): bool { return true; }
}
```

El kit solo usa el contrato público. Prueba la creación, la idempotencia, la
lectura, el pago, las devoluciones parcial y total y el exceso de devolución.
También que un pago capturado no se cancele, la captura manual, la página del
proveedor y las firmas de webhook. Lo que `supports()` niega se omite.

Hay dos formas de ejecutarlo, y conviene tener ambas:

1. **En CI, contra un fake** del proveedor, como `tests/Unit/FakeMercadoPagoConformanceTest.php`
   con `Testing\FakeMercadoPago`. Un fake es un handler de Guzzle con estado:
   `new Client(['handler' => HandlerStack::create($fake)])`.
2. **Contra el proveedor de verdad**: el Payment Emulator Lab si tiene un perfil
   (`tests/Integration/*ConformanceTest.php`) o su sandbox.

Si el entorno se aparta del proveedor real, sobrescribe la prueba concreta con
`markTestIncomplete('por qué')` en vez de debilitar el kit. Así lo hacen Plaza y
Vector con la cancelación de un pago capturado.

## 5. Contrastarlo con el OpenAPI del proveedor

Si el proveedor publica OpenAPI, añade un caso en `tests/Contract/ProviderContractTest.php`.
`OpenApiValidator` comprueba cada escritura del driver contra el `requestBody` del spec.

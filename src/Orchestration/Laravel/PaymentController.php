<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Orchestration\Laravel;

use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\Route;
use Ronu\PaymentGateways\Orchestration\PaymentError;
use Ronu\PaymentGateways\Exceptions\InvalidWebhookSignature;

final class PaymentController
{
    public function __construct(private PaymentRuntime $runtime)
    {
    }

    public function checkout(Request $request): JsonResponse
    {
        return $this->respond($request, function () use ($request): array {
            if (!$request->isJson() || strlen($request->getContent()) > 4096) {
                throw new PaymentError('invalid_request', 422);
            }
            $input = $request->json()->all();
            if (array_diff(array_keys($input), ['subject', 'quoteRevision', 'account']) || !is_string($input['subject'] ?? null)
                || !is_string($input['quoteRevision'] ?? null) || (isset($input['account']) && !is_string($input['account']))) {
                throw new PaymentError('invalid_request', 422);
            }
            $key = $request->header('Idempotency-Key');
            if (!is_string($key)) {
                throw new PaymentError('invalid_idempotency_key', 422);
            }
            $actor = $this->runtime->adapter()->actor($request);
            return $this->runtime->coordinator()->checkout($actor, $input['subject'], $input['quoteRevision'], $key, $input['account'] ?? null);
        });
    }

    public function operation(Request $request, string $reference): JsonResponse
    {
        return $this->respond($request, function () use ($request, $reference): array {
            self::reference($reference);
            return $this->runtime->coordinator()->operationView($this->runtime->adapter()->actor($request), $reference);
        });
    }

    public function show(Request $request, string $reference): JsonResponse
    {
        return $this->respond($request, function () use ($request, $reference): array {
            self::reference($reference);
            $actor = $this->runtime->adapter()->actor($request);
            $intent = $this->runtime->store()->intent($reference);
            if ($intent === null || $intent['scope'] !== $actor->scope()) {
                throw new PaymentError('not_found', 404);
            }
            $operation = $this->runtime->store()->createOperationFor($reference) ?? throw new PaymentError('not_found', 404);
            return $this->runtime->coordinator()->operationView($actor, $operation['reference']);
        });
    }

    public function webhook(Request $request, string $account): JsonResponse
    {
        return $this->respond($request, function () use ($request, $account): array {
            $body = $request->getContent();
            if (strlen($body) > 262144) {
                throw new PaymentError('webhook_too_large', 413);
            }
            $this->runtime->store()->receiveWebhook($this->runtime->accounts()->account($account), array_map(static fn (array $values): array => array_values(array_filter($values, is_string(...))), $request->headers->all()), $body);
            return ['received' => true, 'httpStatus' => 200];
        });
    }

    private static function reference(string $value): void
    {
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $value)) {
            throw new PaymentError('not_found', 404);
        }
    }

    /**
     * The operation read lives beside the route that served this request, whatever channel the
     * application mounted it on. Null when it cannot be resolved: by then the work has COMMITTED,
     * and the body already carries operationReference, so a missing convenience header must never
     * turn an accepted checkout into an error the client would read as "nothing happened".
     */
    private static function operationLocation(Request $request, string $reference): ?string
    {
        try {
            $served = $request->route()->getName();
            if (!is_string($served) || !preg_match('/^(.*)payments\.[a-z]+\.[a-z]+$/D', $served, $match)) {
                return null;
            }
            $name = $match[1].'payments.operations.show';
            return Route::has($name) ? route($name, ['reference' => $reference], false) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function respond(Request $request, callable $work): JsonResponse
    {
        try {
            $data = $work();
            $status = $data['httpStatus'];
            unset($data['httpStatus']);
            $headers = ['Cache-Control' => 'private, no-store'];
            if ($status === 202 && isset($data['operationReference'])) {
                $location = self::operationLocation($request, $data['operationReference']);
                if ($location !== null) {
                    $headers['Location'] = $location;
                }
                $headers['Retry-After'] = '3';
            }
            return new JsonResponse($data, $status, $headers);
        } catch (InvalidWebhookSignature) {
            return new JsonResponse(['error' => 'invalid_signature'], 401, ['Cache-Control' => 'private, no-store']);
        } catch (PaymentError $error) {
            return new JsonResponse(['error' => $error->reason], $error->httpStatus, ['Cache-Control' => 'private, no-store']);
        } catch (\Throwable) {
            // Never expose provider request objects, configuration or SQL even with APP_DEBUG enabled.
            return new JsonResponse(['error' => 'payment_unavailable'], 503, ['Cache-Control' => 'private, no-store']);
        }
    }
}

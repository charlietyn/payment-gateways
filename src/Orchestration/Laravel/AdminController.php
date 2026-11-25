<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Orchestration\Laravel;

use Illuminate\Http\{JsonResponse, Request};
use Ronu\PaymentGateways\Money;
use Ronu\PaymentGateways\Orchestration\Contracts\FailureReporter;
use Ronu\PaymentGateways\Orchestration\{Operator, OperatorConsole, PaymentError};

/**
 * Back-office payment API (0.10.0). Mount it on the application's authenticated ADMIN channel.
 *
 * Every action resolves the operator through the project's OperatorAdapter first, so authorization
 * never depends on a route middleware being enabled. Visibility is project + tenant + accounts; a
 * payment outside it answers 404 exactly like an unknown reference. There is no CRUD on the inbox,
 * outbox or the financial state, and no console command is reachable from here: the only writes are
 * an operator refund (Idempotency-Key, never retried here) and bringing a pending reconciliation
 * forward (never resetting an exhausted one).
 */
final class AdminController
{
    private const FILTER_RULES = [
        'state' => null,
        'fulfillment' => null,
        'account' => '/^[a-z][a-z0-9_-]{0,62}$/D',
        'reference' => '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D',
        'subject' => '/^[A-Za-z0-9_.:\/-]{1,128}$/D',
    ];

    public function __construct(private PaymentRuntime $runtime)
    {
    }

    public function index(Request $request): JsonResponse
    {
        return $this->respond($request, function (Operator $operator) use ($request): array {
            $query = $request->query->all();
            $allowed = [...array_keys(self::FILTER_RULES), 'page', 'perPage'];
            if (array_diff(array_keys($query), $allowed)) {
                throw new PaymentError('invalid_request', 422);
            }
            $filters = [];
            foreach (self::FILTER_RULES as $key => $pattern) {
                if (!array_key_exists($key, $query) || $query[$key] === '') {
                    continue;
                }
                $value = $query[$key];
                $valid = is_string($value) && match ($key) {
                    'state' => in_array($value, OperatorConsole::FINANCIAL_STATES, true),
                    'fulfillment' => in_array($value, OperatorConsole::FULFILLMENT_STATES, true),
                    default => is_string($pattern) && (bool) preg_match($pattern, $value),
                };
                if (!$valid) {
                    throw new PaymentError('invalid_request', 422);
                }
                $filters[$key] = $value;
            }
            $page = self::integer($query['page'] ?? '1', 1, 10000);
            $perPage = self::integer($query['perPage'] ?? '25', 1, 100);
            return $this->runtime->console()->list($operator, $filters, $page, $perPage) + ['httpStatus' => 200];
        });
    }

    public function show(Request $request, string $reference): JsonResponse
    {
        return $this->respond($request, function (Operator $operator) use ($reference): array {
            self::reference($reference);
            return ['data' => $this->runtime->console()->detail($operator, $reference), 'httpStatus' => 200];
        });
    }

    public function refund(Request $request, string $reference): JsonResponse
    {
        return $this->respond($request, function (Operator $operator) use ($request, $reference): array {
            self::reference($reference);
            if (!$request->isJson() || strlen($request->getContent()) > 4096) {
                throw new PaymentError('invalid_request', 422);
            }
            $input = $request->json()->all();
            if (array_diff(array_keys($input), ['amount', 'currency']) || !is_string($input['amount'] ?? null) || !is_string($input['currency'] ?? null)
                || !preg_match('/^(?:0|[1-9][0-9]{0,17})(?:\.[0-9]{1,3})?$/D', $input['amount'])) {
                throw new PaymentError('invalid_request', 422);
            }
            $key = $request->header('Idempotency-Key');
            if (!is_string($key)) {
                throw new PaymentError('invalid_idempotency_key', 422);
            }
            try {
                $amount = Money::fromDecimal($input['amount'], $input['currency']);
            } catch (\Throwable) {
                throw new PaymentError('invalid_request', 422);
            }
            $result = $this->runtime->refunds()->requestAsOperator($operator, $reference, $amount, $key);
            $this->runtime->recordOperatorAction(new Events\OperatorActionRecorded($operator->auditId(), 'refund', $reference, $result['operationState'], $result['operationReference'], $result['amount'], $result['currency']));
            return $result;
        }, 'refund');
    }

    public function refundShow(Request $request, string $reference): JsonResponse
    {
        return $this->respond($request, function (Operator $operator) use ($reference): array {
            self::reference($reference);
            return $this->runtime->refunds()->viewForOperator($operator, $reference);
        });
    }

    public function reconcile(Request $request, string $reference): JsonResponse
    {
        return $this->respond($request, function (Operator $operator) use ($reference): array {
            self::reference($reference);
            $result = $this->runtime->console()->requestReconciliation($operator, $reference);
            $this->runtime->recordOperatorAction(new Events\OperatorActionRecorded($operator->auditId(), 'reconcile', $reference, $result['reconciliation']));
            return $result;
        }, 'reconcile');
    }

    private static function integer(mixed $value, int $min, int $max): int
    {
        if (!is_string($value) || !preg_match('/^[1-9][0-9]{0,5}$/D', $value) || (int) $value < $min || (int) $value > $max) {
            throw new PaymentError('invalid_request', 422);
        }
        return (int) $value;
    }

    private static function reference(string $value): void
    {
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $value)) {
            throw new PaymentError('not_found', 404);
        }
    }

    /** @param callable(Operator): array<string, mixed> $work */
    private function respond(Request $request, callable $work, ?string $write = null): JsonResponse
    {
        $headers = ['Cache-Control' => 'private, no-store'];
        try {
            $data = $work($this->runtime->operators()->operator($request));
            $status = $data['httpStatus'];
            unset($data['httpStatus']);
            return new JsonResponse($data, $status, $headers);
        } catch (PaymentError $error) {
            return new JsonResponse(['error' => $error->reason], $error->httpStatus, $headers);
        } catch (\Throwable $error) {
            try {
                $this->runtime->failures()->report($write === null ? FailureReporter::REQUEST : FailureReporter::REFUND, $error, array_filter(['route' => $request->route()?->getName()], 'is_string'));
            } catch (\Throwable) {
            }
            // A write that failed here may still have reached the provider: the client must read, never resend.
            return new JsonResponse(['error' => 'payment_unavailable'], 503, $headers);
        }
    }
}

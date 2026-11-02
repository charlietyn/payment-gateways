<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Tests\Unit;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Events\Dispatcher as DispatcherContract;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\DatabaseManager;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;
use Ronu\PaymentGateways\Orchestration\Actor;
use Ronu\PaymentGateways\Orchestration\Laravel\Products\ActorResolver;
use Ronu\PaymentGateways\Orchestration\Laravel\Products\Events\PaymentSubjectCompensationRequested;
use Ronu\PaymentGateways\Orchestration\Laravel\Products\Events\PaymentSubjectDelivered;
use Ronu\PaymentGateways\Orchestration\Laravel\Products\Events\PaymentSubjectDisputed;
use Ronu\PaymentGateways\Orchestration\Laravel\Products\Events\PaymentSubjectEvent;
use Ronu\PaymentGateways\Orchestration\Laravel\Products\Events\PaymentSubjectRefunded;
use Ronu\PaymentGateways\Orchestration\Laravel\Products\Events\PaymentSubjectRejected;
use Ronu\PaymentGateways\Orchestration\Laravel\Products\ProductConfiguration;
use Ronu\PaymentGateways\Orchestration\Laravel\Products\TableSubjectAdapter;
use Ronu\PaymentGateways\Orchestration\PaymentError;

final class FixedActors implements ActorResolver
{
    public function actor(Request $request): Actor
    {
        return new Actor('shop', 'tenant-a', 'app-a', ['mp'], ['create', 'read']);
    }
}

/** Generic payment scenarios of a configurable subject table (plan §9.4), on SQLite. */
final class TableSubjectAdapterTest extends TestCase
{
    private Container $container;
    private Capsule $capsule;
    /** @var list<PaymentSubjectEvent> */
    private array $events = [];
    private Actor $owner;
    private Actor $sameTenant;
    private Actor $otherTenant;
    private const LINK = 'pay-7f3a9c2e5b1d4a6f8e0c';

    protected function setUp(): void
    {
        $this->container = new Container();
        $this->capsule = new Capsule($this->container);
        $this->capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''], 'shop');
        $this->container->instance(DatabaseManager::class, $this->capsule->getDatabaseManager());
        $events = new Dispatcher($this->container);
        // Laravel resolves listeners by concrete class (and interfaces), never by parent class: listen by wildcard.
        $events->listen('Ronu\PaymentGateways\Orchestration\Laravel\Products\Events\*', fn (string $name, array $payload) => $this->events[] = $payload[0]);
        $this->container->instance(DispatcherContract::class, $events);
        $this->owner = new Actor('shop', 'tenant-a', 'app-a', ['mp'], ['create']);
        $this->sameTenant = new Actor('shop', 'tenant-a', 'app-b', ['mp'], ['create']);
        $this->otherTenant = new Actor('shop', 'tenant-b', 'app-c', ['mp'], ['create']);

        $schema = $this->capsule->getDatabaseManager()->connection('shop')->getSchemaBuilder();
        $schema->create('enrollments', function ($table): void {
            $table->string('code')->unique();
            $table->string('owner_scope')->nullable();
            $table->string('tenant_id');
            $table->decimal('price', 12, 2);
            $table->integer('price_minor')->nullable();
            $table->string('currency', 3);
            $table->string('status');
            $table->boolean('is_open')->default(true);
            $table->string('expires_at');
            $table->string('price_version')->nullable();
            $table->string('title')->nullable();
            $table->string('paid_at')->nullable();
            $table->string('deleted_at')->nullable();
        });
        $schema->create('subject_receipts', function ($table): void {
            $table->string('event_id')->unique();
            $table->string('subject');
            $table->boolean('accepted');
            $table->string('reason')->nullable();
            $table->string('created_at');
        });
        $schema->create('subject_notifications', function ($table): void {
            $table->string('event_id')->unique();
            $table->string('subject');
            $table->string('type');
            $table->integer('observation_version');
            $table->bigInteger('minor')->nullable();
            $table->string('currency', 3)->nullable();
            $table->string('state')->nullable();
            $table->string('created_at');
        });
    }

    /** @param array<string, mixed> $overrides */
    private function adapter(array $overrides = []): TableSubjectAdapter
    {
        $product = array_replace_recursive([
            'connection' => 'shop',
            'table' => 'enrollments',
            'reference' => 'code',
            'amount' => ['column' => 'price', 'unit' => 'decimal'],
            'currency' => ['column' => 'currency'],
            'ownership' => ['strategy' => 'owner', 'column' => 'owner_scope'],
            'tenant' => ['column' => 'tenant_id'],
            'status' => ['column' => 'status', 'payable' => ['pending'], 'paid' => 'paid', 'refunded' => 'refunded', 'partiallyRefunded' => 'partially_refunded', 'disputed' => 'disputed'],
            'deliverable' => ['column' => 'is_open'],
            'expires' => ['column' => 'expires_at'],
            'description' => ['column' => 'title'],
            'paidAt' => ['column' => 'paid_at'],
            'softDelete' => ['column' => 'deleted_at'],
            'receipts' => ['table' => 'subject_receipts'],
            'notifications' => ['table' => 'subject_notifications'],
            'actorResolver' => FixedActors::class,
        ], $overrides);
        $this->container->instance(Config::class, new Repository(['payments' => ['connection' => 'shop', 'product' => $product]]));
        return new TableSubjectAdapter($this->container);
    }

    /** @param array<string, mixed> $row */
    private function subject(string $code, array $row = []): void
    {
        $this->capsule->getDatabaseManager()->connection('shop')->table('enrollments')->insert($row + [
            'code' => $code, 'owner_scope' => $this->owner->scope(), 'tenant_id' => 'tenant-a', 'price' => '120.00',
            'currency' => 'ARS', 'status' => 'pending', 'is_open' => true, 'expires_at' => gmdate('Y-m-d H:i:s', time() + 3600),
            'title' => 'Course seat',
        ]);
    }

    private function statusOf(string $code): string
    {
        return (string) $this->capsule->getDatabaseManager()->connection('shop')->table('enrollments')->where('code', $code)->value('status');
    }

    /** @return array<string, string> */
    private function payment(string $minor = '12000', string $currency = 'ARS'): array
    {
        return ['minor' => $minor, 'currency' => $currency];
    }

    private function refusal(callable $call): array
    {
        try {
            $call();
        } catch (PaymentError $error) {
            return [$error->reason, $error->httpStatus];
        }
        self::fail('Expected a payment error');
    }

    public function test_configuration_problems_are_all_reported_and_the_adapter_is_not_ready(): void
    {
        [$configuration, $problems] = ProductConfiguration::parse(['table' => 'x;drop', 'amount' => ['unit' => 'float'], 'ownership' => ['strategy' => 'anyone']]);
        self::assertNull($configuration);
        self::assertContains('table must be a table name (optionally schema.table)', $problems);
        self::assertContains('amount.unit must be minor or decimal', $problems);
        self::assertContains('ownership.strategy must be one of owner, tenant, link', $problems);
        self::assertFalse($this->adapter(['table' => 'missing_table'])->ready());
        self::assertTrue($this->adapter()->ready());
    }

    public function test_owner_strategy_quotes_the_row_and_hides_foreign_and_missing_alike(): void
    {
        $this->subject('C-1');
        $quote = $this->adapter()->resolve($this->owner, 'C-1');
        self::assertSame([12000, 'ARS', false, true, 'Course seat'], [$quote->amount->minor, $quote->amount->currency, $quote->shared, $quote->retryable, $quote->description]);
        self::assertSame($this->owner->scope(), $quote->ownerScope);
        self::assertSame(['not_found', 404], $this->refusal(fn () => $this->adapter()->resolve($this->sameTenant, 'C-1')));
        self::assertSame(['not_found', 404], $this->refusal(fn () => $this->adapter()->resolve($this->owner, 'C-404')));
    }

    public function test_tenant_strategy_is_shared_inside_the_tenant_only(): void
    {
        $this->subject('C-2');
        $adapter = $this->adapter(['ownership' => ['strategy' => 'tenant']]);
        $quote = $adapter->resolve($this->sameTenant, 'C-2');
        self::assertTrue($quote->shared);
        self::assertSame($this->sameTenant->scope(), $quote->ownerScope);
        self::assertSame(['not_found', 404], $this->refusal(fn () => $adapter->resolve($this->otherTenant, 'C-2')));
    }

    public function test_link_strategy_needs_an_unguessable_reference(): void
    {
        $this->subject('C-3');
        $this->subject(self::LINK);
        $adapter = $this->adapter(['ownership' => ['strategy' => 'link']]);
        self::assertTrue($adapter->resolve($this->sameTenant, self::LINK)->shared);
        self::assertSame(['not_found', 404], $this->refusal(fn () => $adapter->resolve($this->sameTenant, 'C-3')));
        self::assertSame(['not_found', 404], $this->refusal(fn () => $adapter->resolve($this->otherTenant, self::LINK)));
    }

    public function test_unpayable_undeliverable_expired_and_deleted_subjects(): void
    {
        $this->subject('C-paid', ['status' => 'paid']);
        $this->subject('C-full', ['is_open' => false]);
        $this->subject('C-late', ['expires_at' => gmdate('Y-m-d H:i:s', time() - 60)]);
        $this->subject('C-gone', ['deleted_at' => gmdate('Y-m-d H:i:s')]);
        $adapter = $this->adapter();
        self::assertSame(['subject_unavailable', 409], $this->refusal(fn () => $adapter->resolve($this->owner, 'C-paid')));
        self::assertSame(['subject_unavailable', 409], $this->refusal(fn () => $adapter->resolve($this->owner, 'C-full')));
        self::assertSame(['subject_unavailable', 409], $this->refusal(fn () => $adapter->resolve($this->owner, 'C-late')));
        self::assertSame(['not_found', 404], $this->refusal(fn () => $adapter->resolve($this->owner, 'C-gone')));
    }

    public function test_amounts_in_minor_units_fixed_currency_and_invalid_amounts(): void
    {
        $this->subject('C-minor', ['price_minor' => 4550]);
        $this->subject('C-zero', ['price' => '0.00']);
        $this->subject('C-eur', ['currency' => 'eu']);
        $minor = $this->adapter(['amount' => ['column' => 'price_minor', 'unit' => 'minor'], 'currency' => ['column' => null, 'fixed' => 'USD']]);
        self::assertSame([4550, 'USD'], [$minor->resolve($this->owner, 'C-minor')->amount->minor, $minor->resolve($this->owner, 'C-minor')->amount->currency]);
        self::assertSame(['invalid_quote', 422], $this->refusal(fn () => $this->adapter()->resolve($this->owner, 'C-zero')));
        self::assertSame(['invalid_quote', 422], $this->refusal(fn () => $this->adapter()->resolve($this->owner, 'C-eur')));
    }

    public function test_a_changed_price_changes_the_revision(): void
    {
        $this->subject('C-4');
        $before = $this->adapter()->resolve($this->owner, 'C-4')->revision;
        $this->capsule->getDatabaseManager()->connection('shop')->table('enrollments')->where('code', 'C-4')->update(['price' => '130.00']);
        self::assertNotSame($before, $this->adapter()->resolve($this->owner, 'C-4')->revision);
        $this->capsule->getDatabaseManager()->connection('shop')->table('enrollments')->where('code', 'C-4')->update(['price_version' => 'v7']);
        self::assertSame('v7', $this->adapter(['revision' => ['column' => 'price_version']])->resolve($this->owner, 'C-4')->revision);
    }

    public function test_delivery_is_once_per_event_and_a_second_payment_is_rejected(): void
    {
        $this->subject('C-5');
        $adapter = $this->adapter();
        self::assertTrue($adapter->deliver('evt-1', 'C-5', $this->payment()));
        self::assertSame('paid', $this->statusOf('C-5'));
        // The same event again (worker retry): same decision, the event dispatched again for idempotent listeners.
        self::assertTrue($adapter->deliver('evt-1', 'C-5', $this->payment()));
        // Another payment of the already-paid subject (two tabs): refused, compensation follows.
        self::assertFalse($adapter->deliver('evt-2', 'C-5', $this->payment()));
        self::assertSame(
            [PaymentSubjectDelivered::class, PaymentSubjectDelivered::class, PaymentSubjectRejected::class],
            array_map(fn ($e) => $e::class, $this->events),
        );
        self::assertSame('already_paid', $this->events[2]->reason);
    }

    public function test_delivery_refuses_a_changed_amount_and_a_late_payment_unless_accepted(): void
    {
        $this->subject('C-6');
        $this->subject('C-7', ['expires_at' => gmdate('Y-m-d H:i:s', time() - 60)]);
        $this->subject('C-8', ['expires_at' => gmdate('Y-m-d H:i:s', time() - 60)]);
        self::assertFalse($this->adapter()->deliver('evt-6', 'C-6', $this->payment('10000')));
        self::assertSame('amount_changed', $this->events[0]->reason);
        self::assertFalse($this->adapter()->deliver('evt-7', 'C-7', $this->payment()));
        self::assertSame('expired', $this->events[1]->reason);
        self::assertTrue($this->adapter(['expires' => ['lateDelivery' => 'accept']])->deliver('evt-8', 'C-8', $this->payment()));
        self::assertSame(['pending', 'pending', 'paid'], [$this->statusOf('C-6'), $this->statusOf('C-7'), $this->statusOf('C-8')]);
    }

    public function test_a_retry_attempt_delivers_the_same_row(): void
    {
        $this->subject('C-9');
        self::assertTrue($this->adapter()->deliver('evt-9', 'C-9#2', $this->payment()));
        self::assertSame(['paid', 'C-9', 2], [$this->statusOf('C-9'), $this->events[0]->subject, $this->events[0]->attempt]);
    }

    public function test_a_missing_subject_is_retried_never_dropped(): void
    {
        self::assertSame(['subject_missing', 503], $this->refusal(fn () => $this->adapter()->deliver('evt-x', 'C-none', $this->payment())));
    }

    public function test_refunds_project_partial_then_full_and_ignore_older_observations(): void
    {
        $this->subject('C-10', ['status' => 'paid']);
        $adapter = $this->adapter();
        $refund = fn (string $id, int $version, string $minor) => $adapter->consume($id, 'payment.refunded', ['version' => 1, 'observationVersion' => $version, 'subject' => 'C-10', 'minor' => $minor, 'currency' => 'ARS']);
        $refund('r-1', 3, '2000');
        self::assertSame('partially_refunded', $this->statusOf('C-10'));
        $refund('r-2', 5, '10000');
        self::assertSame('refunded', $this->statusOf('C-10'));
        $refund('r-1', 3, '2000');
        self::assertSame('refunded', $this->statusOf('C-10'));
        self::assertSame([false, true, true], array_map(fn (PaymentSubjectRefunded $e) => $e->full, $this->events));
    }

    public function test_disputes_mark_and_restore_the_status(): void
    {
        $this->subject('C-11', ['status' => 'paid']);
        $adapter = $this->adapter();
        $dispute = fn (string $id, int $version, string $state) => $adapter->consume($id, 'payment.dispute_updated', ['version' => 1, 'observationVersion' => $version, 'subject' => 'C-11', 'state' => $state, 'minor' => '12000', 'currency' => 'ARS']);
        $dispute('d-1', 4, 'opened');
        self::assertSame('disputed', $this->statusOf('C-11'));
        $dispute('d-2', 6, 'won');
        self::assertSame('paid', $this->statusOf('C-11'));
        self::assertSame(['opened', 'won'], array_map(fn (PaymentSubjectDisputed $e) => $e->state, $this->events));
    }

    public function test_a_status_the_product_moved_elsewhere_is_kept(): void
    {
        $this->subject('C-12', ['status' => 'archived']);
        $this->adapter()->consume('r-12', 'payment.refunded', ['version' => 1, 'observationVersion' => 2, 'subject' => 'C-12', 'minor' => '12000', 'currency' => 'ARS']);
        self::assertSame('archived', $this->statusOf('C-12'));
    }

    public function test_compensation_is_recorded_once_and_announced(): void
    {
        $this->subject('C-13');
        $adapter = $this->adapter();
        $adapter->compensate('c-1', 'C-13', $this->payment());
        $adapter->compensate('c-1', 'C-13', $this->payment());
        self::assertSame(1, $this->capsule->getDatabaseManager()->connection('shop')->table('subject_notifications')->where('event_id', 'c-1')->count());
        self::assertInstanceOf(PaymentSubjectCompensationRequested::class, $this->events[0]);
    }

    public function test_the_actor_comes_from_the_project_resolver(): void
    {
        self::assertSame('app-a', $this->adapter()->actor(Request::create('/'))->owner);
    }
}

<?php

use Illuminate\Http\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use MLE\Examples\Tests\Fixtures\CorrelateRequest;
use MLE\Examples\Tests\Fixtures\SettleInvoice;

/**
 * Executed claims for chapters/quality/observability.md.
 */
it('correlates every log line of a request with its context', function () {
    /** @example-id observability/log-context */
    $records = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$records) {
        $records[] = $event;
    });

    $response = (new CorrelateRequest)->handle(
        Request::create('/orders', 'GET', [], [], [], ['HTTP_X_TENANT_ID' => 't-7']),
        fn ($request) => response('ok'),
    );

    Log::info('orders.listed', ['count' => 3]);

    expect($response->headers->get('X-Request-Id'))->not->toBeNull();

    $listed = collect($records)->first(fn (MessageLogged $e) => $e->message === 'orders.listed');
    expect($listed)->not->toBeNull();
    expect($listed->context['request_id'])->toBe($response->headers->get('X-Request-Id'));
    expect($listed->context['tenant_id'])->toBe('t-7');
    expect($listed->context['count'])->toBe(3);
});

it('re-hydrates context into queued jobs at processing time', function () {
    /** @example-id observability/job-context */
    config(['queue.default' => 'database']);

    // The dispatch site: context attached by the "request boundary".
    Context::add('tenant_id', 'tenant-42');

    SettleInvoice::dispatch(1);
    expect(DB::table('jobs')->count())->toBe(1);

    // One real worker cycle: full worker machinery, so the documented
    // hydration events fire exactly as in production.
    Artisan::call('queue:work', [
        '--once' => true,
        '--stop-when-empty' => true,
    ]);

    expect(SettleInvoice::$capturedTenant)->toBe('tenant-42');
    expect(DB::table('jobs')->count())->toBe(0);
});

beforeEach(function () {
    SettleInvoice::$capturedTenant = null;
});

afterEach(function () {
    Context::flush();
});

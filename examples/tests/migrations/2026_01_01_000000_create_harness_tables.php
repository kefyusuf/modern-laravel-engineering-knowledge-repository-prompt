<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shared harness schema. Minimal on purpose — just enough to encode the
 * chapter claims. Mirrors the migrations shown in the chapters.
 */
return new class extends Migration
{
    public function up(): void
    {
        // transactions / locking
        Schema::create('inventories', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('available');
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedBigInteger('sku_id')->nullable();
            $table->unsignedBigInteger('merchant_id')->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->string('status')->default('pending');
            $table->timestamp('invoiced_at')->nullable();
            $table->timestamp('placed_at')->nullable();
            $table->unique(['customer_id', 'sku_id', 'status']);
        });

        // idempotency / outbox inbox
        Schema::create('processed_messages', function (Blueprint $table) {
            $table->id();
            $table->string('source');
            $table->string('message_key');
            $table->timestamp('created_at');
            $table->unique(['source', 'message_key']);
        });

        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();
            $table->unsignedBigInteger('points')->default(0);
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->string('external_id')->unique();
            $table->string('status')->default('pending');
            $table->timestamp('paid_until')->nullable();
        });

        // idempotency middleware
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->string('scope');
            $table->string('key');
            $table->string('request_fingerprint');
            $table->string('status');
            $table->longText('response_body')->nullable();
            $table->timestamp('created_at');
            $table->unique(['scope', 'key']);
        });

        // outbox
        Schema::create('outbox_messages', function (Blueprint $table) {
            $table->id();
            $table->string('event_type');
            $table->json('payload');
            $table->unsignedBigInteger('aggregate_id');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->index(['dispatched_at', 'id']);
        });

        // database queue driver table (queue dispatch-timing tests)
        Schema::create('jobs', function (Blueprint $table) {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });

        Schema::create('job_batches', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name');
            $table->integer('total_jobs');
            $table->integer('pending_jobs');
            $table->integer('failed_jobs');
            $table->longText('failed_job_ids');
            $table->mediumText('options')->nullable();
            $table->integer('cancelled_at')->nullable();
            $table->integer('created_at');
            $table->integer('finished_at')->nullable();
        });

        Schema::create('failed_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });

        // cqrs projection
        Schema::create('merchant_shipment_totals', function (Blueprint $table) {
            $table->unsignedBigInteger('merchant_id')->primary();
            $table->unsignedBigInteger('shipments_total');
            $table->timestamp('updated_at');
        });
    }
};

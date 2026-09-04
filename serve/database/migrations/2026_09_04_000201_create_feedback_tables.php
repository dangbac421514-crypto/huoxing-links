<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feedback_channels', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('domain_id')->constrained()->restrictOnDelete();
            $table->char('code', 24)->unique();
            $table->boolean('status')->default(true);
            $table->string('name', 80);
            $table->string('operator_name', 80);
            $table->string('intro', 500)->default('');
            $table->string('service_phone', 32)->nullable();
            $table->string('sla_text', 80);
            $table->json('categories');
            $table->boolean('contact_required')->default(false);
            $table->unsignedSmallInteger('retention_days')->default(180);
            $table->text('webhook_url')->nullable();
            $table->timestamp('webhook_configured_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status']);
        });

        Schema::create('feedback_tickets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('feedback_channel_id')->constrained()->restrictOnDelete();
            $table->string('public_no', 19)->unique();
            $table->string('category', 40);
            $table->string('status', 20)->default('pending');
            $table->text('contact')->nullable();
            $table->text('content')->nullable();
            $table->char('visitor_hash', 64)->nullable();
            $table->char('idempotency_key', 36);
            $table->timestamp('submitted_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('anonymized_at')->nullable();
            $table->timestamps();
            $table->unique(['feedback_channel_id', 'idempotency_key']);
            $table->index(['user_id', 'status', 'submitted_at']);
        });

        Schema::create('feedback_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('feedback_ticket_id')->constrained()->restrictOnDelete();
            $table->string('disk', 40);
            $table->string('path', 255);
            $table->text('original_name');
            $table->string('mime', 80);
            $table->unsignedInteger('size');
            $table->char('sha256', 64);
            $table->timestamps();
            $table->index(['feedback_ticket_id', 'sha256']);
        });

        Schema::create('feedback_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('feedback_ticket_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event', 40);
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20)->nullable();
            $table->text('note')->nullable();
            $table->timestamp('created_at');
            $table->index(['feedback_ticket_id', 'created_at']);
        });

        Schema::create('feedback_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('feedback_channel_id')->constrained()->restrictOnDelete();
            $table->foreignId('feedback_ticket_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 20);
            $table->string('channel', 20)->default('wecom');
            $table->string('status', 20);
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->string('last_error_code', 64)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->string('idempotency_key', 80)->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feedback_deliveries');
        Schema::dropIfExists('feedback_events');
        Schema::dropIfExists('feedback_attachments');
        Schema::dropIfExists('feedback_tickets');
        Schema::dropIfExists('feedback_channels');
    }
};

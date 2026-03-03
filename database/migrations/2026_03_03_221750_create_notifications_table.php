<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('batch_id')->nullable()->index();
            $table->string('idempotency_key')->nullable()->unique();
            $table->uuid('correlation_id');
            $table->string('recipient');
            $table->enum('channel', ['sms', 'email', 'push'])->index();
            $table->text('content');
            $table->enum('priority', ['high', 'normal', 'low'])->default('normal')->index();
            $table->enum('status', ['pending', 'queued', 'processing', 'delivered', 'failed', 'retrying', 'permanently_failed', 'cancelled'])->default('pending')->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedInteger('max_attempts')->default(3);
            $table->timestamp('next_retry_at')->nullable();
            $table->timestamp('last_attempted_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name')->unique();
            $table->enum('channel', ['sms', 'email', 'push']);
            $table->text('body_template');
            $table->json('variables');
            $table->timestamps();
        });

        Schema::table('notifications', function (Blueprint $table) {
            $table->uuid('template_id')->nullable()->after('batch_id');
            $table->json('template_variables')->nullable()->after('template_id');

            $table->foreign('template_id')
                ->references('id')
                ->on('notification_templates')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropForeign(['template_id']);
            $table->dropColumn(['template_id', 'template_variables']);
        });

        Schema::dropIfExists('notification_templates');
    }
};

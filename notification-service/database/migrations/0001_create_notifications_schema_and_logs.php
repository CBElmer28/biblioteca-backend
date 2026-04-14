<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'pgsql_direct';

    public function up(): void
    {
        DB::statement('CREATE SCHEMA IF NOT EXISTS notifications');

        Schema::create('notification_logs', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));

            // Evento que disparó la notificación
            $table->string('event_type', 100);
            $table->string('source_service', 60);

            // Destinatario
            $table->string('recipient_email');
            $table->uuid('recipient_user_id')->nullable();

            // Qué se envió
            $table->string('notification_class', 150);
            $table->string('subject', 255)->nullable();

            // Estado del envío
            $table->enum('status', ['pending', 'sent', 'failed', 'skipped'])
                  ->default('pending');
            $table->text('error_message')->nullable();
            $table->unsignedTinyInteger('attempt')->default(1);

            // Payload original para replay
            $table->json('event_payload');

            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index('event_type');
            $table->index('recipient_email');
            $table->index(['status', 'created_at']);
            $table->index('source_service');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_logs');
        DB::statement('DROP SCHEMA IF EXISTS notifications CASCADE');
    }
};
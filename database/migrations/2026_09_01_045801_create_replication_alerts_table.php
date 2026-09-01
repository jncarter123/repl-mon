<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('replication_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_pair_id')->constrained()->cascadeOnDelete();
            $table->foreignId('replication_check_id')->nullable()->constrained()->nullOnDelete();

            $table->string('kind');
            $table->string('status');
            $table->string('subject');
            $table->text('summary')->nullable();
            $table->json('recipients');
            $table->decimal('lag_seconds', 12, 3)->nullable();
            $table->text('delivery_error')->nullable();
            $table->timestamp('sent_at');

            $table->index(['server_pair_id', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('replication_alerts');
    }
};

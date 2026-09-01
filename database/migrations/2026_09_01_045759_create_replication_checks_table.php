<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('replication_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_pair_id')->constrained()->cascadeOnDelete();

            $table->string('status');
            $table->boolean('primary_reachable')->default(false);
            $table->boolean('replica_reachable')->default(false);

            // Lag measured by our own clock: we stamp the beat, the replica
            // echoes it back. No comparison of two servers' clocks anywhere.
            $table->decimal('lag_seconds', 12, 3)->nullable();
            $table->timestamp('beat_written_at', 3)->nullable();
            $table->timestamp('beat_seen_at', 3)->nullable();

            // What SHOW REPLICA STATUS said, when we were allowed to ask.
            $table->string('io_running')->nullable();
            $table->string('sql_running')->nullable();
            $table->integer('seconds_behind_source')->nullable();
            $table->text('replica_error')->nullable();
            $table->text('status_query_error')->nullable();

            $table->text('message')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('checked_at', 3);

            $table->index(['server_pair_id', 'checked_at']);
            $table->index('checked_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('replication_checks');
    }
};

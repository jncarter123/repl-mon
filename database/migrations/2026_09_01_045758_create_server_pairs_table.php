<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_pairs', function (Blueprint $table) {
            $table->id();

            // Stable key written into the heartbeat row on the primary, so the
            // replica side can find this pair's beat even if the name changes.
            $table->uuid('monitor_key')->unique();
            $table->string('name')->unique();
            $table->text('description')->nullable();
            $table->boolean('enabled')->default(true);

            $table->string('primary_host');
            $table->unsignedSmallInteger('primary_port')->default(3306);
            $table->string('primary_username');
            $table->text('primary_password')->nullable();
            $table->string('primary_database');
            $table->boolean('primary_use_tls')->default(false);

            $table->string('replica_host');
            $table->unsignedSmallInteger('replica_port')->default(3306);
            $table->string('replica_username');
            $table->text('replica_password')->nullable();
            $table->string('replica_database');
            $table->boolean('replica_use_tls')->default(false);

            $table->string('heartbeat_table')->default('repl_monitor_heartbeat');
            $table->unsignedInteger('lag_threshold_seconds')->default(60);
            $table->boolean('check_replica_status')->default(true);
            $table->unsignedTinyInteger('failures_before_alert')->default(1);
            $table->unsignedInteger('realert_after_minutes')->default(60);
            $table->unsignedTinyInteger('connect_timeout_seconds')->default(5);

            // Rolling state, maintained by the checker.
            $table->string('current_status')->default('unknown');
            $table->text('last_message')->nullable();
            $table->decimal('last_lag_seconds', 12, 3)->nullable();
            $table->timestamp('status_changed_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('last_ok_at')->nullable();
            $table->timestamp('failing_since')->nullable();
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->timestamp('last_alert_at')->nullable();
            $table->boolean('alerting')->default(false);

            $table->timestamps();

            $table->index(['enabled', 'current_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_pairs');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An alert row said who was told and when, and — for a recovery — that things
 * were fine again, which is no help at all the next morning. These columns hold
 * the episode behind the alert, written at the moment it is sent, so the record
 * survives the check history being pruned.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('replication_alerts', function (Blueprint $table) {
            $table->timestamp('incident_started_at')->nullable();
            // No healthy check sits before the run, so `incident_started_at` is
            // the oldest failure we can see rather than the moment it began.
            $table->boolean('incident_truncated')->default(false);
            $table->unsignedInteger('incident_duration_seconds')->nullable();
            $table->unsignedInteger('failed_checks')->nullable();
            $table->string('worst_status')->nullable();
            $table->decimal('peak_lag_seconds', 12, 3)->nullable();
            $table->text('first_failure_message')->nullable();
            $table->text('replica_error')->nullable();
            $table->json('status_counts')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('replication_alerts', function (Blueprint $table) {
            $table->dropColumn([
                'incident_started_at',
                'incident_truncated',
                'incident_duration_seconds',
                'failed_checks',
                'worst_status',
                'peak_lag_seconds',
                'first_failure_message',
                'replica_error',
                'status_counts',
            ]);
        });
    }
};

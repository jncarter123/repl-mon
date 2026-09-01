<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alert_recipients', function (Blueprint $table) {
            $table->id();

            // Null means global: used by any pair that names no recipients of
            // its own. A pair with its own list does not also get the globals.
            $table->foreignId('server_pair_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name')->nullable();
            $table->string('email');
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->index(['server_pair_id', 'enabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_recipients');
    }
};

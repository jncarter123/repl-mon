<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('health_tokens', function (Blueprint $table) {
            $table->id();

            // What it is for — "icinga-master", "the laptop" — so an operator
            // deleting one a year from now knows which one they are deleting.
            $table->string('name');

            // Encrypted rather than hashed, deliberately: this one is meant to
            // be read back. Setting up a second checker months later should not
            // mean rotating the token every other check is already using.
            $table->text('token');

            // The other half of "the monitor is not silently failing": a token
            // nobody has ever used is a check nobody ever finished wiring up.
            $table->timestamp('last_used_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('health_tokens');
    }
};

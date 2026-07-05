<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('huts', function (Blueprint $table) {
            // The primary key IS the hutId from the Hut Reservation Service
            // (hut-reservation.org), so catalog rows upsert idempotently.
            $table->unsignedInteger('id')->primary();
            $table->string('name');
            $table->string('country', 2)->nullable()->index();
            $table->string('club')->nullable();          // tenantCode: OEAV, DAV, ...
            $table->decimal('latitude', 9, 6)->nullable();
            $table->decimal('longitude', 9, 6)->nullable();
            $table->unsignedInteger('altitude')->nullable();
            $table->unsignedInteger('total_beds')->nullable();
            $table->string('phone')->nullable();
            $table->string('website')->nullable();
            $table->timestamp('catalog_synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('huts');
    }
};

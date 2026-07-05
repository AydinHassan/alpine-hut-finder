<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hut_availabilities', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('hut_id');
            $table->date('date')->index();
            $table->unsignedInteger('free_beds')->default(0);
            $table->unsignedInteger('total_beds')->nullable();
            $table->string('hut_status')->nullable();   // SERVICED / NOT_SERVICED / CLOSED
            $table->string('percentage')->nullable();   // AVAILABLE / NEARLY FULL / FULL / CLOSED
            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();

            $table->foreign('hut_id')->references('id')->on('huts')->cascadeOnDelete();
            $table->unique(['hut_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hut_availabilities');
    }
};

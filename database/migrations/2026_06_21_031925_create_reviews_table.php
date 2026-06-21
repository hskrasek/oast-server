<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->string('spec_ref')->nullable();
            $table->string('spec_hash')->index();
            $table->string('mode');
            $table->string('dimension');
            $table->json('panelists')->nullable();
            $table->unsignedInteger('panel_size')->default(0);
            $table->json('raw_panelist_responses')->nullable();
            $table->json('findings')->nullable();
            $table->json('metrics')->nullable();
            $table->string('status');
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reviews');
    }
};

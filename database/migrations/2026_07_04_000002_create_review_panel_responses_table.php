<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('review_panel_responses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('review_id')->constrained()->cascadeOnDelete();
            $table->string('model');
            $table->boolean('ok');
            $table->longText('content')->nullable();
            $table->string('error')->nullable();
            $table->unsignedInteger('ms')->default(0);
            $table->json('usage')->nullable();
            $table->decimal('cost_usd', 10, 6)->nullable();
            $table->boolean('late')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_panel_responses');
    }
};

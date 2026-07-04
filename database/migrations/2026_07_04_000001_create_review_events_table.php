<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('review_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('review_id')->constrained()->cascadeOnDelete();
            $table->string('event');
            $table->json('data');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['review_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_events');
    }
};

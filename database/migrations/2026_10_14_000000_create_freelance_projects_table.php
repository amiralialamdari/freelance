<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('freelance_projects', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('freelancer_id')->unique();
            $table->string('title');
            $table->string('url');
            // pending | sent | skipped | irrelevant | failed | expired
            $table->string('status')->default('pending')->index();
            $table->string('skip_reason')->nullable();
            $table->json('payload');
            $table->json('analysis')->nullable();
            $table->unsignedTinyInteger('score')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('freelance_projects');
    }
};

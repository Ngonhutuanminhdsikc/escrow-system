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
        Schema::create('disputes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('escrow_orders')->cascadeOnDelete();
            $table->foreignId('raised_by_id')->constrained('users')->cascadeOnDelete();
            $table->text('reason');
            $table->string('status')->default('PENDING'); // PENDING, RESOLVED_REFUND, RESOLVED_RELEASE, REJECTED
            $table->timestamp('created_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('disputes');
    }
};

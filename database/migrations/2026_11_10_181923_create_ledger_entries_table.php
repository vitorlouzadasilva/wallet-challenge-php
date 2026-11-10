<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('operation_id')->constrained()->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('wallet_id')->constrained()->restrictOnDelete()->restrictOnUpdate();
            $table->bigInteger('delta_cents');
            $table->timestamps();

            $table->unique(['operation_id', 'wallet_id']);
            $table->index(['wallet_id', 'created_at']);
        });

        DB::statement('ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_delta_nonzero CHECK (delta_cents <> 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
    }
};

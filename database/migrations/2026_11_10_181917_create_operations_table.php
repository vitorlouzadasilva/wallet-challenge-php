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
        Schema::create('operations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('initiated_by_user_id')->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->string('type', 16)->collation('utf8mb4_bin');
            $table->string('status', 16)->collation('utf8mb4_bin')->default('completed');
            $table->bigInteger('amount_cents');
            // O padrão NO ACTION do InnoDB é restritivo e compatível com CHECKs nestas FKs.
            $table->foreignId('source_wallet_id')->nullable()->constrained('wallets');
            $table->foreignId('destination_wallet_id')->nullable()->constrained('wallets');
            $table->string('idempotency_key', 128)->collation('utf8mb4_bin')->unique();
            $table->foreignId('reversal_of_operation_id')->nullable()->unique()->constrained('operations');
            $table->timestamps();

            $table->index(['initiated_by_user_id', 'created_at']);
            $table->index(['source_wallet_id', 'created_at']);
            $table->index(['destination_wallet_id', 'created_at']);
        });

        // O Schema Builder do Laravel 12 não oferece uma API para CHECK; o alvo é MySQL 8.4.
        DB::statement(<<<'SQL'
            ALTER TABLE operations
                ADD CONSTRAINT operations_amount_positive CHECK (amount_cents > 0),
                ADD CONSTRAINT operations_type_valid CHECK (type IN ('deposit', 'transfer', 'reversal')),
                ADD CONSTRAINT operations_status_valid CHECK (status IN ('completed', 'reversed')),
                ADD CONSTRAINT operations_reversal_completed CHECK (type <> 'reversal' OR status = 'completed'),
                ADD CONSTRAINT operations_idempotency_not_blank CHECK (CHAR_LENGTH(TRIM(idempotency_key)) > 0),
                ADD CONSTRAINT operations_reversal_reference CHECK (
                    (type = 'reversal' AND reversal_of_operation_id IS NOT NULL)
                    OR (type <> 'reversal' AND reversal_of_operation_id IS NULL)
                ),
                ADD CONSTRAINT operations_wallet_shape CHECK (
                    (type = 'deposit' AND source_wallet_id IS NULL AND destination_wallet_id IS NOT NULL)
                    OR (type = 'transfer' AND source_wallet_id IS NOT NULL AND destination_wallet_id IS NOT NULL
                        AND source_wallet_id <> destination_wallet_id)
                    OR (type = 'reversal' AND (source_wallet_id IS NOT NULL OR destination_wallet_id IS NOT NULL)
                        AND (source_wallet_id IS NULL OR destination_wallet_id IS NULL
                            OR source_wallet_id <> destination_wallet_id))
                )
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('operations');
    }
};

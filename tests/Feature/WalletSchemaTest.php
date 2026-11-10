<?php

namespace Tests\Feature;

use App\Models\LedgerEntry;
use App\Models\Operation;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WalletSchemaTest extends TestCase
{
    private User $actor;

    private Wallet $source;

    private Wallet $destination;

    private Operation $original;

    protected function setUp(): void
    {
        parent::setUp();

        // Os testes de integridade nunca devem escrever no banco de desenvolvimento.
        self::assertSame('mysql', DB::connection()->getDriverName());
        self::assertSame('testing', DB::connection()->getDatabaseName());
        DB::beginTransaction();

        $this->actor = User::factory()->create();
        $this->source = Wallet::forceCreate(['user_id' => $this->actor->id, 'balance_cents' => -200]);
        $this->destination = Wallet::forceCreate(['user_id' => User::factory()->create()->id]);
        $this->original = Operation::forceCreate($this->operationAttributes());
    }

    protected function tearDown(): void
    {
        try {
            if ($this->app !== null && DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_signed_money_defaults_and_eloquent_relationships(): void
    {
        self::assertSame(-200, $this->source->fresh()->balance_cents);
        self::assertSame(0, $this->destination->fresh()->balance_cents);
        self::assertTrue($this->actor->wallet->is($this->source));
        self::assertTrue($this->source->user->is($this->actor));
        self::assertSame('completed', $this->original->fresh()->status);

        $transfer = Operation::forceCreate($this->operationAttributes([
            'type' => 'transfer',
            'source_wallet_id' => $this->source->id,
            'destination_wallet_id' => $this->destination->id,
        ]));
        $debit = LedgerEntry::forceCreate([
            'operation_id' => $transfer->id, 'wallet_id' => $this->source->id, 'delta_cents' => -100,
        ]);
        $credit = LedgerEntry::forceCreate([
            'operation_id' => $transfer->id, 'wallet_id' => $this->destination->id, 'delta_cents' => 100,
        ]);
        $reversal = Operation::forceCreate($this->operationAttributes([
            'type' => 'reversal',
            'source_wallet_id' => $this->destination->id,
            'destination_wallet_id' => $this->source->id,
            'reversal_of_operation_id' => $transfer->id,
        ]));
        $transfer->status = 'reversed';
        $transfer->save();

        self::assertTrue($transfer->initiatedByUser->is($this->actor));
        self::assertTrue($transfer->sourceWallet->is($this->source));
        self::assertTrue($transfer->destinationWallet->is($this->destination));
        self::assertCount(2, $transfer->ledgerEntries);
        self::assertTrue($this->source->ledgerEntries->first()->is($debit));
        self::assertTrue($debit->operation->is($transfer));
        self::assertTrue($debit->wallet->is($this->source));
        self::assertSame(-100, $debit->fresh()->delta_cents);
        self::assertSame(100, $credit->fresh()->delta_cents);
        self::assertSame(100, $transfer->fresh()->amount_cents);
        self::assertTrue($reversal->originalOperation->is($transfer));
        self::assertTrue($transfer->reversal->is($reversal));
        self::assertSame('reversed', $transfer->fresh()->status);
        self::assertDatabaseHas('operations', ['id' => $transfer->id]);
    }

    public function test_wallet_user_is_unique(): void
    {
        $this->assertMysqlRejects(fn () => Wallet::forceCreate(['user_id' => $this->actor->id]), 1062);
    }

    public function test_idempotency_key_is_unique_globally(): void
    {
        $this->assertMysqlRejects(fn () => Operation::forceCreate($this->operationAttributes([
            'initiated_by_user_id' => $this->destination->user_id,
            'idempotency_key' => $this->original->idempotency_key,
        ])), 1062);
    }

    public function test_nullable_original_reference_allows_multiple_non_reversals(): void
    {
        $other = Operation::forceCreate($this->operationAttributes());

        self::assertNull($this->original->reversal_of_operation_id);
        self::assertNull($other->reversal_of_operation_id);
    }

    public function test_only_one_reversal_can_reference_an_original(): void
    {
        $attributes = $this->operationAttributes([
            'type' => 'reversal',
            'source_wallet_id' => $this->source->id,
            'destination_wallet_id' => null,
            'reversal_of_operation_id' => $this->original->id,
        ]);
        Operation::forceCreate($attributes);
        $attributes['idempotency_key'] = (string) Str::uuid();

        $this->assertMysqlRejects(fn () => Operation::forceCreate($attributes), 1062);
    }

    public function test_ledger_entry_is_unique_per_operation_and_wallet(): void
    {
        $attributes = ['operation_id' => $this->original->id, 'wallet_id' => $this->source->id, 'delta_cents' => 100];
        LedgerEntry::forceCreate($attributes);

        $this->assertMysqlRejects(fn () => LedgerEntry::forceCreate($attributes), 1062);
    }

    #[DataProvider('invalidOperations')]
    public function test_operation_checks_reject_invalid_rows(string $scenario): void
    {
        $overrides = match ($scenario) {
            'zero' => ['amount_cents' => 0],
            'negative' => ['amount_cents' => -1],
            'type' => ['type' => 'withdrawal'],
            'uppercase_type' => ['type' => 'DEPOSIT'],
            'status' => ['status' => 'pending'],
            'blank_key' => ['idempotency_key' => '   '],
            'deposit_without_destination' => ['destination_wallet_id' => null],
            'deposit_with_source' => ['source_wallet_id' => $this->destination->id],
            'transfer_without_source' => ['type' => 'transfer'],
            'transfer_without_destination' => ['type' => 'transfer', 'source_wallet_id' => $this->source->id, 'destination_wallet_id' => null],
            'same_wallet' => ['type' => 'transfer', 'source_wallet_id' => $this->source->id],
            'reversal_without_original' => ['type' => 'reversal'],
            'deposit_with_original' => ['reversal_of_operation_id' => $this->original->id],
            'reversed_reversal' => ['type' => 'reversal', 'status' => 'reversed', 'reversal_of_operation_id' => $this->original->id],
            'reversal_without_wallets' => ['type' => 'reversal', 'destination_wallet_id' => null, 'reversal_of_operation_id' => $this->original->id],
            'reversal_same_wallet' => ['type' => 'reversal', 'source_wallet_id' => $this->source->id, 'reversal_of_operation_id' => $this->original->id],
        };

        $this->assertMysqlRejects(fn () => Operation::forceCreate($this->operationAttributes($overrides)), 3819);
    }

    public static function invalidOperations(): array
    {
        $scenarios = [
            'zero', 'negative', 'type', 'uppercase_type', 'status', 'blank_key',
            'deposit_without_destination', 'deposit_with_source', 'transfer_without_source',
            'transfer_without_destination', 'same_wallet', 'reversal_without_original',
            'deposit_with_original', 'reversed_reversal', 'reversal_without_wallets', 'reversal_same_wallet',
        ];

        return array_combine($scenarios, array_map(fn (string $scenario): array => [$scenario], $scenarios));
    }

    public function test_check_also_rejects_updating_amount_to_zero(): void
    {
        $this->assertMysqlRejects(fn () => DB::table('operations')->where('id', $this->original->id)->update(['amount_cents' => 0]), 3819);
    }

    public function test_zero_ledger_delta_is_rejected(): void
    {
        $this->assertMysqlRejects(fn () => LedgerEntry::forceCreate([
            'operation_id' => $this->original->id, 'wallet_id' => $this->source->id, 'delta_cents' => 0,
        ]), 3819);
    }

    #[DataProvider('foreignKeys')]
    public function test_foreign_keys_reject_missing_parents(string $table, string $column): void
    {
        $missing = 9223372036854775807;
        $attributes = match ($table) {
            'wallets' => ['user_id' => $missing],
            'ledger_entries' => [
                'operation_id' => $this->original->id, 'wallet_id' => $this->source->id,
                'delta_cents' => 100, $column => $missing,
            ],
            'operations' => $this->operationAttributes(match ($column) {
                'source_wallet_id' => ['type' => 'transfer', $column => $missing],
                'reversal_of_operation_id' => ['type' => 'reversal', $column => $missing],
                default => [$column => $missing],
            }),
        };

        $this->assertMysqlRejects(fn () => DB::table($table)->insert($attributes), 1452);
    }

    public static function foreignKeys(): array
    {
        return [
            ['wallets', 'user_id'],
            ['operations', 'initiated_by_user_id'],
            ['operations', 'source_wallet_id'],
            ['operations', 'destination_wallet_id'],
            ['operations', 'reversal_of_operation_id'],
            ['ledger_entries', 'operation_id'],
            ['ledger_entries', 'wallet_id'],
        ];
    }

    #[DataProvider('restrictedDeletes')]
    public function test_deleting_financial_parents_is_restricted(string $target): void
    {
        $transfer = Operation::forceCreate($this->operationAttributes([
            'type' => 'transfer', 'source_wallet_id' => $this->source->id,
            'destination_wallet_id' => $this->destination->id,
        ]));
        LedgerEntry::forceCreate(['operation_id' => $transfer->id, 'wallet_id' => $this->source->id, 'delta_cents' => -100]);
        Operation::forceCreate($this->operationAttributes([
            'type' => 'reversal', 'reversal_of_operation_id' => $this->original->id,
        ]));
        $model = match ($target) {
            'user' => $this->actor,
            'source' => $this->source,
            'destination' => $this->destination,
            'operation' => $transfer,
            'original' => $this->original,
        };

        $this->assertMysqlRejects(fn () => $model->delete(), 1451);
    }

    public static function restrictedDeletes(): array
    {
        return [['user'], ['source'], ['destination'], ['operation'], ['original']];
    }

    public function test_money_columns_indexes_and_foreign_key_actions(): void
    {
        foreach (['wallets' => 'balance_cents', 'operations' => 'amount_cents', 'ledger_entries' => 'delta_cents'] as $table => $column) {
            $metadata = DB::selectOne(
                'SELECT COLUMN_TYPE AS column_type FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                [$table, $column]
            );
            self::assertSame('bigint', $metadata->column_type);
        }

        foreach (['wallets', 'operations', 'ledger_entries'] as $table) {
            foreach (Schema::getForeignKeys($table) as $foreignKey) {
                self::assertContains(strtolower($foreignKey['on_delete']), ['restrict', 'no action']);
                self::assertContains(strtolower($foreignKey['on_update']), ['restrict', 'no action']);
            }
        }

        $operationIndexes = collect(Schema::getIndexes('operations'));
        foreach (['initiated_by_user_id', 'source_wallet_id', 'destination_wallet_id'] as $column) {
            self::assertTrue($operationIndexes->contains(fn (array $index): bool => $index['columns'] === [$column, 'created_at']));
        }
        self::assertTrue(collect(Schema::getIndexes('ledger_entries'))->contains(
            fn (array $index): bool => $index['columns'] === ['wallet_id', 'created_at']
        ));
        $checks = DB::selectOne(
            "SELECT COUNT(*) AS total FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME IN ('operations', 'ledger_entries') AND CONSTRAINT_TYPE = 'CHECK' AND ENFORCED = 'YES'"
        );
        self::assertSame(8, (int) $checks->total);
    }

    private function operationAttributes(array $overrides = []): array
    {
        return array_replace([
            'initiated_by_user_id' => $this->actor->id,
            'type' => 'deposit',
            'amount_cents' => 100,
            'source_wallet_id' => null,
            'destination_wallet_id' => $this->source->id,
            'idempotency_key' => (string) Str::uuid(),
        ], $overrides);
    }

    private function assertMysqlRejects(callable $statement, int $expectedError): void
    {
        try {
            $statement();
        } catch (QueryException $exception) {
            self::assertSame($expectedError, $exception->errorInfo[1]);

            return;
        }

        self::fail('O MySQL aceitou uma escrita que deveria violar uma restrição.');
    }
}

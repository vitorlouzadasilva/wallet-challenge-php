<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\Wallet\BalanceLimitExceeded;
use App\Exceptions\Wallet\FinancialPersistenceException;
use App\Exceptions\Wallet\IdempotencyConflict;
use App\Exceptions\Wallet\InsufficientBalance;
use App\Exceptions\Wallet\InvalidAmount;
use App\Exceptions\Wallet\InvalidIdempotencyKey;
use App\Exceptions\Wallet\InvalidOperation;
use App\Exceptions\Wallet\OperationAlreadyReversed;
use App\Exceptions\Wallet\UnauthorizedOperation;
use App\Exceptions\Wallet\WalletNotFound;
use App\Models\LedgerEntry;
use App\Models\Operation;
use App\Models\User;
use App\Models\Wallet;
use App\Services\WalletService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;
use Throwable;

class WalletServiceTest extends TestCase
{
    private WalletService $service;
    private User $alice;
    private User $bob;
    private User $carol;
    private array $walletIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame('mysql', DB::connection()->getDriverName());
        self::assertSame('testing', DB::connection()->getDatabaseName());
        self::assertSame(8, PHP_INT_SIZE);
        DB::beginTransaction();
        $this->service = app(WalletService::class);
        $this->alice = $this->newActor();
        $this->bob = $this->newActor();
        $this->carol = $this->newActor();
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

    public function test_deposit_records_positive_credit_in_the_actors_wallet(): void
    {
        $operation = $this->service->deposit($this->alice, 12345, 'deposit-valid');
        self::assertSame(12345, $this->balance($this->alice));
        self::assertSame(0, $this->balance($this->bob));
        self::assertSame('deposit', $operation->type);
        self::assertSame('completed', $operation->status);
        self::assertSame($this->alice->id, (int) $operation->initiated_by_user_id);
        self::assertNull($operation->source_wallet_id);
        self::assertSame($this->alice->wallet->id, (int) $operation->destination_wallet_id);
        self::assertSame(12345, $operation->amount_cents);
        self::assertCount(1, $operation->ledgerEntries);
        self::assertSame(12345, $operation->ledgerEntries->sole()->delta_cents);
        $this->assertIntegrity();
    }

    #[DataProvider('invalidAmounts')]
    public function test_deposit_rejects_non_positive_or_non_integer_amounts(mixed $amount): void
    {
        $this->assertFailure(fn () => $this->service->deposit($this->alice, $amount, 'invalid-deposit'), InvalidAmount::class);
    }

    #[DataProvider('invalidAmounts')]
    public function test_transfer_rejects_non_positive_or_non_integer_amounts(mixed $amount): void
    {
        $this->assertFailure(fn () => $this->service->transfer($this->alice, $this->bob->wallet->id, $amount, 'invalid-transfer'), InvalidAmount::class);
    }

    public static function invalidAmounts(): array
    {
        return [[0], [-1], [PHP_INT_MIN], [1.5], ['100'], [true], [null], [PHP_INT_MAX + 1]];
    }

    #[DataProvider('invalidKeys')]
    public function test_all_cases_require_a_valid_idempotency_key(string $key): void
    {
        $this->assertFailure(fn () => $this->service->deposit($this->alice, 1, $key), InvalidIdempotencyKey::class);
        $this->assertFailure(fn () => $this->service->transfer($this->alice, $this->bob->wallet->id, 1, $key), InvalidIdempotencyKey::class);
        $this->assertFailure(fn () => $this->service->reverse($this->alice, 1, $key), InvalidIdempotencyKey::class);
    }

    public static function invalidKeys(): array
    {
        return [[''], [' '], [' leading'], ['trailing '], [str_repeat('a', 129)], ["inside\ncontrol"], ["trailing\u{00A0}"]];
    }

    public function test_deposit_adds_to_a_negative_balance_instead_of_replacing_it(): void
    {
        $this->makeBobNegative();
        self::assertSame(-100, $this->balance($this->bob));
        $this->service->deposit($this->bob, 40, 'negative-balance-deposit');
        self::assertSame(-60, $this->balance($this->bob));
        $this->assertIntegrity();
    }

    public function test_deposit_derives_ownership_from_database_not_a_cached_foreign_wallet(): void
    {
        $this->alice->setRelation('wallet', $this->bob->wallet);
        $operation = $this->service->deposit($this->alice, 50, 'server-owned-wallet');
        self::assertSame($this->alice->id, (int) $operation->destinationWallet->user_id);
        self::assertSame(50, $this->balance($this->alice));
        self::assertSame(0, $this->balance($this->bob));
        $this->assertIntegrity();
    }

    public function test_missing_own_wallet_and_unpersisted_actor_are_rejected(): void
    {
        $withoutWallet = User::factory()->create();
        $this->assertFailure(fn () => $this->service->deposit($withoutWallet, 1, 'missing-wallet'), WalletNotFound::class);
        $this->assertFailure(fn () => $this->service->transfer($withoutWallet, $this->bob->wallet->id, 1, 'missing-source'), WalletNotFound::class);
        $this->assertFailure(fn () => $this->service->deposit(new User(), 1, 'unsaved-actor'), UnauthorizedOperation::class);
        $this->assertFailure(fn () => $this->service->reverse(new User(), 1, 'unsaved-reversal'), UnauthorizedOperation::class);
    }

    public function test_deposit_replay_does_not_duplicate_balance_or_entries(): void
    {
        $original = $this->service->deposit($this->alice, 100, 'deposit-replay');
        $before = $this->snapshot();
        $replay = $this->service->deposit($this->alice, 100, 'deposit-replay');
        self::assertSame($original->id, $replay->id);
        self::assertSame($before, $this->snapshot());
        $this->assertIntegrity();
    }

    public function test_keys_are_case_sensitive_and_accept_the_maximum_length(): void
    {
        $first = $this->service->deposit($this->alice, 1, 'Request-ABC');
        $second = $this->service->deposit($this->alice, 1, 'request-abc');
        $this->service->deposit($this->alice, 1, str_repeat('k', 128));
        self::assertNotSame($first->id, $second->id);
        self::assertSame(3, $this->balance($this->alice));
        $this->assertIntegrity();
    }

    public function test_transfer_records_opposite_entries_and_preserves_total_balance(): void
    {
        $this->service->deposit($this->alice, 500, 'alice-funding');
        $this->service->deposit($this->bob, 100, 'bob-funding');
        $operation = $this->service->transfer($this->alice, $this->bob->wallet->id, 150, 'valid-transfer');
        self::assertSame('transfer', $operation->type);
        self::assertSame($this->alice->wallet->id, (int) $operation->source_wallet_id);
        self::assertSame($this->bob->wallet->id, (int) $operation->destination_wallet_id);
        self::assertSame(350, $this->balance($this->alice));
        self::assertSame(250, $this->balance($this->bob));
        self::assertSame(600, $this->balance($this->alice) + $this->balance($this->bob));
        self::assertSame(-150, $operation->ledgerEntries->firstWhere('wallet_id', $this->alice->wallet->id)->delta_cents);
        self::assertSame(150, $operation->ledgerEntries->firstWhere('wallet_id', $this->bob->wallet->id)->delta_cents);
        $this->assertIntegrity();
    }

    public function test_insufficient_balance_rejects_the_whole_transfer(): void
    {
        $this->service->deposit($this->alice, 99, 'insufficient-funding');
        $this->assertFailure(fn () => $this->service->transfer($this->alice, $this->bob->wallet->id, 100, 'insufficient-transfer'), InsufficientBalance::class);
        $this->assertIntegrity();
    }

    public function test_self_transfer_and_missing_recipient_are_rejected(): void
    {
        $this->assertFailure(fn () => $this->service->transfer($this->alice, $this->alice->wallet->id, 1, 'self-transfer'), InvalidOperation::class);
        $this->assertFailure(fn () => $this->service->transfer($this->alice, PHP_INT_MAX, 1, 'missing-recipient'), WalletNotFound::class);
    }

    public function test_negative_source_cannot_transfer_but_negative_destination_can_receive(): void
    {
        $this->makeBobNegative();
        $this->assertFailure(fn () => $this->service->transfer($this->bob, $this->alice->wallet->id, 1, 'negative-source'), InsufficientBalance::class);
        $this->service->transfer($this->alice, $this->bob->wallet->id, 50, 'negative-destination');
        self::assertSame(-50, $this->balance($this->bob));
        $this->assertIntegrity();
    }

    public function test_transfer_replay_succeeds_even_after_source_balance_was_spent(): void
    {
        $this->service->deposit($this->alice, 100, 'replay-funding');
        $original = $this->service->transfer($this->alice, $this->bob->wallet->id, 100, 'transfer-replay');
        $before = $this->snapshot();
        $replay = $this->service->transfer($this->alice, $this->bob->wallet->id, 100, 'transfer-replay');
        self::assertSame($original->id, $replay->id);
        self::assertSame($before, $this->snapshot());
        $this->assertIntegrity();
    }

    public function test_idempotency_rejects_different_amount_type_and_actor_with_the_same_generic_message(): void
    {
        $this->service->deposit($this->alice, 100, 'global-request');
        foreach ([
            fn () => $this->service->deposit($this->alice, 101, 'global-request'),
            fn () => $this->service->transfer($this->alice, $this->bob->wallet->id, 100, 'global-request'),
            fn () => $this->service->deposit($this->bob, 100, 'global-request'),
        ] as $action) {
            $exception = $this->assertFailure($action, IdempotencyConflict::class);
            self::assertSame((new IdempotencyConflict())->getMessage(), $exception->getMessage());
        }
    }

    public function test_idempotency_rejects_a_different_destination(): void
    {
        $this->service->deposit($this->alice, 100, 'destination-funding');
        $this->service->transfer($this->alice, $this->bob->wallet->id, 100, 'destination-request');
        $this->assertFailure(fn () => $this->service->transfer($this->alice, $this->carol->wallet->id, 100, 'destination-request'), IdempotencyConflict::class);
    }

    public function test_deposit_reversal_preserves_original_and_records_a_compensating_debit(): void
    {
        $original = $this->service->deposit($this->alice, 100, 'original-deposit');
        $entries = $original->ledgerEntries->toArray();
        $reversal = $this->service->reverse($this->alice, $original->id, 'reverse-deposit');
        self::assertSame(0, $this->balance($this->alice));
        self::assertSame('reversal', $reversal->type);
        self::assertSame('completed', $reversal->status);
        self::assertSame($original->id, (int) $reversal->reversal_of_operation_id);
        self::assertSame($this->alice->wallet->id, (int) $reversal->source_wallet_id);
        self::assertNull($reversal->destination_wallet_id);
        self::assertSame(-100, $reversal->ledgerEntries->sole()->delta_cents);
        self::assertSame('reversed', $original->fresh()->status);
        self::assertSame('deposit', $original->fresh()->type);
        self::assertSame(100, $original->fresh()->amount_cents);
        self::assertSame('original-deposit', $original->fresh()->idempotency_key);
        self::assertSame($entries, $original->fresh()->ledgerEntries->toArray());
        $this->assertIntegrity();
    }

    public function test_transfer_reversal_preserves_entries_and_can_make_the_recipient_negative(): void
    {
        $this->service->deposit($this->alice, 100, 'transfer-reversal-funding');
        $original = $this->service->transfer($this->alice, $this->bob->wallet->id, 100, 'original-transfer');
        $entries = $original->ledgerEntries->toArray();
        $this->service->transfer($this->bob, $this->carol->wallet->id, 80, 'recipient-spent');
        $reversal = $this->service->reverse($this->alice, $original->id, 'reverse-transfer');
        self::assertSame($this->bob->wallet->id, (int) $reversal->source_wallet_id);
        self::assertSame($this->alice->wallet->id, (int) $reversal->destination_wallet_id);
        self::assertSame(100, $this->balance($this->alice));
        self::assertSame(-80, $this->balance($this->bob));
        self::assertSame(80, $this->balance($this->carol));
        self::assertSame(-100, $reversal->ledgerEntries->firstWhere('wallet_id', $this->bob->wallet->id)->delta_cents);
        self::assertSame(100, $reversal->ledgerEntries->firstWhere('wallet_id', $this->alice->wallet->id)->delta_cents);
        self::assertSame('reversed', $original->fresh()->status);
        self::assertSame($entries, $original->fresh()->ledgerEntries->toArray());
        $this->assertIntegrity();
    }

    public function test_second_reversal_and_reversal_of_reversal_are_rejected(): void
    {
        $original = $this->service->deposit($this->alice, 100, 'once-original');
        $reversal = $this->service->reverse($this->alice, $original->id, 'once-reversal');
        $this->assertFailure(fn () => $this->service->reverse($this->alice, $original->id, 'twice-reversal'), OperationAlreadyReversed::class);
        $this->assertFailure(fn () => $this->service->reverse($this->alice, $reversal->id, 'reverse-a-reversal'), InvalidOperation::class);
    }

    public function test_non_initiator_and_unknown_original_are_not_authorized(): void
    {
        $this->service->deposit($this->alice, 100, 'authorization-funding');
        $original = $this->service->transfer($this->alice, $this->bob->wallet->id, 100, 'authorization-transfer');
        $first = $this->assertFailure(fn () => $this->service->reverse($this->bob, $original->id, 'recipient-reversal'), UnauthorizedOperation::class);
        $second = $this->assertFailure(fn () => $this->service->reverse($this->carol, PHP_INT_MAX, 'unknown-reversal'), UnauthorizedOperation::class);
        self::assertSame($first->getMessage(), $second->getMessage());
    }

    public function test_reversal_revalidates_ownership_in_the_database(): void
    {
        $original = $this->service->deposit($this->alice, 10, 'ownership-original');
        $newOwner = User::factory()->create();
        DB::table('wallets')->where('id', $this->alice->wallet->id)->update(['user_id' => $newOwner->id]);
        $this->assertFailure(fn () => $this->service->reverse($this->alice, $original->id, 'ownership-reversal'), UnauthorizedOperation::class);
    }

    public function test_reversal_and_original_replays_never_move_money_again(): void
    {
        $deposit = $this->service->deposit($this->alice, 100, 'reversed-deposit');
        $reversal = $this->service->reverse($this->alice, $deposit->id, 'reversed-deposit-compensation');
        $before = $this->snapshot();
        self::assertSame($reversal->id, $this->service->reverse($this->alice, $deposit->id, 'reversed-deposit-compensation')->id);
        self::assertSame('reversed', $this->service->deposit($this->alice, 100, 'reversed-deposit')->status);
        self::assertSame($before, $this->snapshot());

        $this->service->deposit($this->alice, 100, 'reversed-transfer-funding');
        $transfer = $this->service->transfer($this->alice, $this->bob->wallet->id, 100, 'reversed-transfer');
        $this->service->reverse($this->alice, $transfer->id, 'reversed-transfer-compensation');
        $before = $this->snapshot();
        self::assertSame('reversed', $this->service->transfer($this->alice, $this->bob->wallet->id, 100, 'reversed-transfer')->status);
        self::assertSame($before, $this->snapshot());
        $this->assertIntegrity();
    }

    public function test_reversal_key_cannot_be_reused_for_another_original(): void
    {
        $first = $this->service->deposit($this->alice, 10, 'key-original-one');
        $second = $this->service->deposit($this->alice, 10, 'key-original-two');
        $this->service->reverse($this->alice, $first->id, 'shared-reversal-key');
        $this->assertFailure(fn () => $this->service->reverse($this->alice, $second->id, 'shared-reversal-key'), IdempotencyConflict::class);
    }

    public function test_reversal_rejects_inconsistent_original_ledger(): void
    {
        $original = $this->service->deposit($this->alice, 10, 'corrupt-original');
        DB::table('ledger_entries')->where('operation_id', $original->id)->update(['delta_cents' => 11]);
        $this->assertFailure(fn () => $this->service->reverse($this->alice, $original->id, 'corrupt-reversal'), LogicException::class);
    }

    public function test_bigint_upper_limit_is_exact_and_overflow_is_rejected(): void
    {
        $operation = $this->service->deposit($this->alice, PHP_INT_MAX, 'maximum-deposit');
        self::assertSame(PHP_INT_MAX, $this->balance($this->alice));
        self::assertSame(PHP_INT_MAX, $operation->ledgerEntries->sole()->delta_cents);
        $this->assertFailure(fn () => $this->service->deposit($this->alice, 1, 'overflow-deposit'), BalanceLimitExceeded::class);
        $this->service->reverse($this->alice, $operation->id, 'reverse-maximum');
        self::assertSame(0, $this->balance($this->alice));
        $this->assertIntegrity();
    }

    public function test_transfer_overflow_rolls_back_both_wallets_and_history(): void
    {
        $this->service->deposit($this->bob, PHP_INT_MAX, 'maximum-recipient');
        $this->service->deposit($this->alice, 1, 'overflow-source');
        $this->assertFailure(fn () => $this->service->transfer($this->alice, $this->bob->wallet->id, 1, 'overflow-transfer'), BalanceLimitExceeded::class);
        $this->assertIntegrity();
    }

    public function test_reversal_overflow_is_atomic(): void
    {
        $this->service->deposit($this->alice, 1, 'reversal-overflow-funding');
        $original = $this->service->transfer($this->alice, $this->bob->wallet->id, 1, 'reversal-overflow-original');
        $this->service->deposit($this->alice, PHP_INT_MAX, 'reversal-overflow-maximum');
        $this->assertFailure(fn () => $this->service->reverse($this->alice, $original->id, 'overflow-reversal'), BalanceLimitExceeded::class);
        $this->assertIntegrity();
    }

    public function test_bigint_lower_limit_is_exact_and_further_reversal_is_rejected(): void
    {
        $dave = $this->newActor();
        $first = $this->service->deposit($this->bob, 1, 'lower-first');
        $second = $this->service->deposit($this->bob, 1, 'lower-second');
        $this->service->transfer($this->bob, $this->carol->wallet->id, 2, 'lower-small-spending');
        $this->service->deposit($this->alice, PHP_INT_MAX, 'lower-large-funding');
        $large = $this->service->transfer($this->alice, $this->bob->wallet->id, PHP_INT_MAX, 'lower-large-transfer');
        $this->service->transfer($this->bob, $dave->wallet->id, PHP_INT_MAX, 'lower-large-spending');
        $this->service->reverse($this->alice, $large->id, 'lower-large-reversal');
        $this->service->reverse($this->bob, $first->id, 'lower-first-reversal');
        self::assertSame(PHP_INT_MIN, $this->balance($this->bob));
        $this->assertFailure(fn () => $this->service->reverse($this->bob, $second->id, 'underflow-reversal'), BalanceLimitExceeded::class);
        $this->assertIntegrity();
    }

    public function test_failure_on_second_transfer_entry_rolls_back_every_prior_write(): void
    {
        $this->service->deposit($this->alice, 100, 'failure-funding');
        $event = 'eloquent.creating: '.LedgerEntry::class;
        Event::listen($event, function (LedgerEntry $entry): void {
            if ($entry->delta_cents > 0) {
                throw new RuntimeException('Falha simulada no segundo lançamento.');
            }
        });
        try {
            $this->assertFailure(fn () => $this->service->transfer($this->alice, $this->bob->wallet->id, 100, 'failing-transfer'), RuntimeException::class);
        } finally {
            Event::forget($event);
        }
        $this->assertIntegrity();
    }

    public function test_failure_updating_original_status_rolls_back_the_entire_reversal(): void
    {
        $original = $this->service->deposit($this->alice, 100, 'status-failure-original');
        $event = 'eloquent.updating: '.Operation::class;
        Event::listen($event, fn () => throw new RuntimeException('Falha simulada ao atualizar status.'));
        try {
            $this->assertFailure(fn () => $this->service->reverse($this->alice, $original->id, 'status-failure-reversal'), RuntimeException::class);
        } finally {
            Event::forget($event);
        }
        self::assertSame('completed', $original->fresh()->status);
        $this->assertIntegrity();
    }

    public function test_cancelled_eloquent_save_cannot_be_reported_as_success(): void
    {
        $event = 'eloquent.creating: '.LedgerEntry::class;
        Event::listen($event, fn (): bool => false);
        try {
            $this->assertFailure(fn () => $this->service->deposit($this->alice, 100, 'cancelled-save'), FinancialPersistenceException::class);
        } finally {
            Event::forget($event);
        }
    }

    public function test_database_failure_is_propagated_with_a_safe_public_message(): void
    {
        $event = 'eloquent.creating: '.LedgerEntry::class;
        Event::listen($event, function (LedgerEntry $entry): void {
            $entry->operation_id = PHP_INT_MAX;
        });
        try {
            $exception = $this->assertFailure(fn () => $this->service->deposit($this->alice, 100, 'database-failure'), FinancialPersistenceException::class);
        } finally {
            Event::forget($event);
        }
        self::assertSame('Não foi possível concluir a operação financeira.', $exception->getMessage());
        self::assertInstanceOf(QueryException::class, $exception->getPrevious());
    }

    public function test_failure_in_the_initial_wallet_query_has_a_safe_public_message(): void
    {
        $scopes = Wallet::getAllGlobalScopes();
        Wallet::addGlobalScope('invalid-column', fn (Builder $query) => $query->where('missing_financial_column', 1));
        try {
            $exception = $this->assertFailure(fn () => $this->service->deposit($this->alice, 1, 'initial-query-failure'), FinancialPersistenceException::class);
        } finally {
            Wallet::setAllGlobalScopes($scopes);
        }
        self::assertSame('Não foi possível concluir a operação financeira.', $exception->getMessage());
        self::assertInstanceOf(QueryException::class, $exception->getPrevious());
    }

    public function test_duplicate_ledger_entry_is_a_failure_not_an_idempotent_success(): void
    {
        $event = 'eloquent.creating: '.LedgerEntry::class;
        Event::listen($event, function (LedgerEntry $entry): void {
            DB::table('ledger_entries')->insert([
                'operation_id' => $entry->operation_id,
                'wallet_id' => $entry->wallet_id,
                'delta_cents' => $entry->delta_cents,
            ]);
        });
        try {
            $this->assertFailure(fn () => $this->service->deposit($this->alice, 100, 'duplicate-ledger'), FinancialPersistenceException::class);
        } finally {
            Event::forget($event);
        }
        $this->assertIntegrity();
    }

    #[DataProvider('collisionRequests')]
    public function test_unique_collision_rolls_back_before_replay_or_conflict(string $scenario): void
    {
        $this->service->deposit($this->alice, 100, 'collision-funding');
        $original = $this->service->transfer($this->alice, $this->bob->wallet->id, 100, 'collision-request');
        $before = $this->snapshot();
        $scopes = Operation::getAllGlobalScopes();
        $hideFirstRead = true;
        // Simula apenas a leitura inicial vazia; INSERT/UNIQUE/rollback/replay são reais no MySQL.
        // Este teste sequencial não representa duas conexões concorrentes.
        Operation::addGlobalScope('stale-idempotency-read', function (Builder $query) use (&$hideFirstRead): void {
            if ($hideFirstRead) {
                $query->whereRaw('1 = 0');
                $hideFirstRead = false;
            }
        });
        try {
            $action = match ($scenario) {
                'same' => fn () => $this->service->transfer($this->alice, $this->bob->wallet->id, 100, 'collision-request'),
                'different destination' => fn () => $this->service->transfer($this->alice, $this->carol->wallet->id, 100, 'collision-request'),
                'different actor' => fn () => $this->service->deposit($this->bob, 100, 'collision-request'),
            };
            if ($scenario === 'same') {
                self::assertSame($original->id, $action()->id);
            } else {
                $this->assertFailure($action, IdempotencyConflict::class);
            }
        } finally {
            Operation::setAllGlobalScopes($scopes);
        }
        self::assertFalse($hideFirstRead);
        self::assertSame($before, $this->snapshot());
        self::assertSame(1, DB::transactionLevel());
        $this->assertIntegrity();
    }

    public static function collisionRequests(): array
    {
        return [['same'], ['different destination'], ['different actor']];
    }

    private function newActor(): User
    {
        $actor = User::factory()->create();
        $wallet = Wallet::forceCreate(['user_id' => $actor->id, 'balance_cents' => 0]);
        $this->walletIds[] = $wallet->id;
        $actor->setRelation('wallet', $wallet);

        return $actor;
    }

    private function balance(User $actor): int
    {
        return $actor->wallet()->firstOrFail()->balance_cents;
    }

    private function makeBobNegative(): void
    {
        $this->service->deposit($this->alice, 100, 'negative-funding');
        $original = $this->service->transfer($this->alice, $this->bob->wallet->id, 100, 'negative-original');
        $this->service->transfer($this->bob, $this->carol->wallet->id, 100, 'negative-spending');
        $this->service->reverse($this->alice, $original->id, 'negative-reversal');
    }

    private function snapshot(): array
    {
        return [
            'wallets' => Wallet::withoutGlobalScopes()->orderBy('id')->get()->toArray(),
            'operations' => Operation::withoutGlobalScopes()->orderBy('id')->get()->toArray(),
            'entries' => LedgerEntry::query()->orderBy('id')->get()->toArray(),
        ];
    }

    private function assertFailure(callable $action, string $exceptionClass): Throwable
    {
        $before = $this->snapshot();
        $caught = null;
        try {
            $action();
        } catch (Throwable $exception) {
            $caught = $exception;
        }
        self::assertInstanceOf($exceptionClass, $caught);
        self::assertSame($before, $this->snapshot());

        return $caught;
    }

    private function assertIntegrity(): void
    {
        foreach ($this->walletIds as $walletId) {
            $wallet = Wallet::query()->findOrFail($walletId);
            $sum = DB::table('ledger_entries')->where('wallet_id', $walletId)->sum('delta_cents');
            self::assertSame((string) $wallet->balance_cents, (string) $sum);
        }
        $duplicates = DB::table('ledger_entries')->select('operation_id', 'wallet_id')
            ->groupBy('operation_id', 'wallet_id')->havingRaw('COUNT(*) > 1')->get();
        self::assertCount(0, $duplicates);
    }
}

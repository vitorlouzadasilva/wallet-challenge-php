<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\LedgerEntry;
use App\Models\Operation;
use App\Models\User;
use App\Models\Wallet;
use App\Services\WalletService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Throwable;
use Tests\TestCase;

class WalletWebTest extends TestCase
{
    private User $alice;

    private User $bob;

    private WalletService $service;

    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame('mysql', DB::connection()->getDriverName());
        self::assertSame('testing', DB::connection()->getDatabaseName());
        DB::beginTransaction();
        $this->alice = $this->newUser();
        $this->bob = $this->newUser();
        $this->service = app(WalletService::class);
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

    public function test_guest_cannot_access_dashboard_or_financial_posts(): void
    {
        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->post(route('wallet.deposit'), $this->amountInput())->assertRedirect(route('login'));
        $this->post(route('wallet.transfer'), $this->amountInput(['email' => $this->bob->email]))->assertRedirect(route('login'));
        $this->post(route('operations.reverse', 1), $this->keyInput())->assertRedirect(route('login'));
        $this->assertNoOperations();
    }

    public function test_financial_posts_require_csrf_token(): void
    {
        $this->app->instance('env', 'local');
        try {
            $this->actingAs($this->alice)->post(route('wallet.deposit'), $this->amountInput())->assertStatus(419);
            $this->post(route('wallet.transfer'), $this->amountInput(['email' => $this->bob->email]))->assertStatus(419);
            $this->post(route('operations.reverse', 1), $this->keyInput())->assertStatus(419);
        } finally {
            $this->app->instance('env', 'testing');
        }
        $this->assertNoOperations();
    }

    public function test_deposit_updates_own_balance_and_statement(): void
    {
        $response = $this->actingAs($this->alice)->post(route('wallet.deposit'), $this->amountInput(['amount' => '10,50']));
        $response->assertRedirect(route('dashboard'))->assertSessionHas('status');
        self::assertSame(1050, $this->balance($this->alice));
        self::assertSame(0, $this->balance($this->bob));
        $this->get(route('dashboard'))->assertOk()->assertSee('R$ 10,50')->assertSee('Depósito');
        $this->assertIntegrity();
    }

    public function test_invalid_deposit_preserves_key_and_does_not_write(): void
    {
        $key = (string) Str::uuid();
        $this->actingAs($this->alice)->post(route('wallet.deposit'), $this->amountInput(['amount' => '1,001', 'idempotency_key' => $key]))
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('errors', fn ($errors): bool => $errors->getBag('deposit')->has('amount'));
        $this->get(route('dashboard'))->assertOk()->assertSee($key);
        $this->assertNoOperations();
    }

    public function test_repeated_deposit_and_tampered_source_do_not_duplicate_or_redirect_credit(): void
    {
        $input = $this->amountInput(['user_id' => $this->bob->id, 'source_wallet_id' => $this->bob->wallet->id, 'balance_cents' => 999999]);
        $this->actingAs($this->alice)->post(route('wallet.deposit'), $input)->assertSessionHas('status');
        $this->post(route('wallet.deposit'), $input)->assertSessionHas('status');
        self::assertSame(1000, $this->balance($this->alice));
        self::assertSame(0, $this->balance($this->bob));
        self::assertSame(1, Operation::query()->count());
        self::assertSame(1, LedgerEntry::query()->count());
        $this->assertIntegrity();
    }

    public function test_same_key_with_different_amount_is_a_safe_conflict(): void
    {
        $input = $this->amountInput();
        $this->actingAs($this->alice)->post(route('wallet.deposit'), $input)->assertSessionHas('status');
        $this->post(route('wallet.deposit'), array_replace($input, ['amount' => '20']))
            ->assertSessionHas('errors', fn ($errors): bool => $errors->getBag('deposit')->has('wallet'));
        self::assertSame(1000, $this->balance($this->alice));
        self::assertSame(1, Operation::query()->count());
        $this->assertIntegrity();
    }

    public function test_dashboard_renders_distinct_server_generated_form_keys_and_reverse_confirmation(): void
    {
        $original = $this->service->deposit($this->alice, 100, (string) Str::uuid());
        $html = $this->actingAs($this->alice)->get(route('dashboard'))->assertOk()->getContent();
        preg_match_all('/name="idempotency_key" value="([a-f0-9-]{36})"/', $html, $matches);
        self::assertCount(3, $matches[1]);
        self::assertCount(3, array_unique($matches[1]));
        self::assertStringContainsString("Confirma a reversão da operação #{$original->id}?", $html);
    }

    public function test_transfer_by_normalized_email_debits_and_credits_once(): void
    {
        $this->service->deposit($this->alice, 2000, (string) Str::uuid());
        $input = $this->amountInput(['amount' => '10,5', 'email' => '  '.strtoupper($this->bob->email).'  ', 'source_wallet_id' => $this->bob->wallet->id]);
        $this->actingAs($this->alice)->post(route('wallet.transfer'), $input)->assertSessionHas('status');
        $this->post(route('wallet.transfer'), $input)->assertSessionHas('status');
        self::assertSame(950, $this->balance($this->alice));
        self::assertSame(1050, $this->balance($this->bob));
        self::assertSame(2, Operation::query()->count());
        self::assertSame(3, LedgerEntry::query()->count());
        $this->actingAs($this->bob)->get(route('dashboard'))->assertOk()->assertSee('Transferência recebida')->assertSee('+R$ 10,50');
        $this->assertIntegrity();
    }

    public function test_unavailable_recipient_and_self_transfer_do_not_write(): void
    {
        $this->actingAs($this->alice)->post(route('wallet.transfer'), $this->amountInput(['email' => 'absent@example.com']))
            ->assertSessionHas('errors', fn ($errors): bool => $errors->getBag('transfer')->has('wallet'));
        $this->post(route('wallet.transfer'), $this->amountInput(['email' => $this->alice->email]))
            ->assertSessionHas('errors', fn ($errors): bool => $errors->getBag('transfer')->has('wallet'));
        $this->assertNoOperations();
    }

    public function test_invalid_transfer_keeps_its_key_and_does_not_write(): void
    {
        $key = (string) Str::uuid();
        $this->actingAs($this->alice)->post(route('wallet.transfer'), $this->amountInput([
            'email' => $this->bob->email, 'amount' => '1.000,00', 'idempotency_key' => $key, 'wallet_form' => 'transfer',
        ]))->assertRedirect(route('dashboard'))
            ->assertSessionHas('errors', fn ($errors): bool => $errors->getBag('transfer')->has('amount'));
        $this->get(route('dashboard'))->assertOk()->assertSee($key);
        $this->assertNoOperations();
    }

    public function test_insufficient_balance_does_not_leave_partial_transfer(): void
    {
        $this->actingAs($this->alice)->post(route('wallet.transfer'), $this->amountInput(['email' => $this->bob->email]))
            ->assertSessionHas('errors', fn ($errors): bool => $errors->getBag('transfer')->has('wallet'));
        $this->assertNoOperations();
        $this->assertIntegrity();
    }

    public function test_original_initiator_can_reverse_deposit_with_negative_balance(): void
    {
        $original = $this->service->deposit($this->alice, 1000, (string) Str::uuid());
        $this->service->transfer($this->alice, $this->bob->wallet->id, 800, (string) Str::uuid());
        $this->actingAs($this->alice)->post(route('operations.reverse', $original->id), $this->keyInput())->assertSessionHas('status');
        self::assertSame(-800, $this->balance($this->alice));
        self::assertSame('reversed', $original->fresh()->status);
        self::assertSame(1000, $original->ledgerEntries()->sole()->delta_cents);
        $this->get(route('dashboard'))->assertSee('-R$ 8,00')->assertSee('Reversão de depósito')->assertSee('Revertida');
        $this->assertIntegrity();
    }

    public function test_transfer_reversal_keeps_original_and_may_debit_recipient_below_zero(): void
    {
        $this->service->deposit($this->alice, 1000, (string) Str::uuid());
        $original = $this->service->transfer($this->alice, $this->bob->wallet->id, 1000, (string) Str::uuid());
        $this->service->transfer($this->bob, $this->alice->wallet->id, 900, (string) Str::uuid());
        $this->actingAs($this->alice)->post(route('operations.reverse', $original->id), $this->keyInput())->assertSessionHas('status');
        self::assertSame(-900, $this->balance($this->bob));
        self::assertSame(1900, $this->balance($this->alice));
        self::assertSame('reversed', $original->fresh()->status);
        self::assertSame(2, $original->ledgerEntries()->count());
        $this->assertIntegrity();
    }

    public function test_unauthorized_duplicate_and_reversal_of_reversal_are_rejected(): void
    {
        $original = $this->service->deposit($this->alice, 1000, (string) Str::uuid());
        $this->actingAs($this->bob)->post(route('operations.reverse', $original->id), $this->keyInput())
            ->assertSessionHas('errors', fn ($errors): bool => $errors->getBag('reverse')->has('wallet'));
        self::assertSame('completed', $original->fresh()->status);
        $this->actingAs($this->alice)->post(route('operations.reverse', $original->id), $this->keyInput())->assertSessionHas('status');
        $reversal = Operation::query()->where('reversal_of_operation_id', $original->id)->sole();
        $this->post(route('operations.reverse', $original->id), $this->keyInput())
            ->assertSessionHas('errors', fn ($errors): bool => $errors->getBag('reverse')->has('wallet'));
        $this->post(route('operations.reverse', $reversal->id), $this->keyInput())
            ->assertSessionHas('errors', fn ($errors): bool => $errors->getBag('reverse')->has('wallet'));
        self::assertSame(2, Operation::query()->count());
        $this->assertIntegrity();
    }

    public function test_oversized_reversal_id_is_rejected_without_internal_error(): void
    {
        $this->actingAs($this->alice)->post(route('operations.reverse', str_repeat('9', 30)), $this->keyInput())
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('errors', fn ($errors): bool => $errors->getBag('reverse')->has('wallet'));
        $this->assertNoOperations();
    }

    public function test_repeated_reverse_key_returns_success_without_second_compensation(): void
    {
        $original = $this->service->deposit($this->alice, 1000, (string) Str::uuid());
        $input = $this->keyInput();
        $this->actingAs($this->alice)->post(route('operations.reverse', $original->id), $input)->assertSessionHas('status');
        $this->post(route('operations.reverse', $original->id), $input)->assertSessionHas('status');
        self::assertSame(0, $this->balance($this->alice));
        self::assertSame(2, Operation::query()->count());
        self::assertSame(2, LedgerEntry::query()->count());
        $this->assertIntegrity();
    }

    public function test_statement_is_private_paginated_and_hides_reverse_for_recipient(): void
    {
        for ($i = 0; $i < 12; $i++) {
            $this->service->deposit($this->alice, 100 + $i, (string) Str::uuid());
        }
        $first = Operation::query()->orderBy('id')->firstOrFail();
        $this->actingAs($this->alice)->get(route('dashboard'))->assertOk()
            ->assertDontSee('Operação #'.$first->id.'<', false)
            ->assertSee('page=2');
        $this->get(route('dashboard', ['page' => 2]))->assertOk()->assertSee('Operação #'.$first->id);
        $this->actingAs($this->bob)->get(route('dashboard', ['wallet_id' => $this->alice->wallet->id]))
            ->assertOk()->assertDontSee('Operação #'.$first->id)->assertDontSee('Reverter');
    }

    public function test_recipient_sees_incoming_transfer_without_reverse_action(): void
    {
        $this->service->deposit($this->alice, 1000, (string) Str::uuid());
        $transfer = $this->service->transfer($this->alice, $this->bob->wallet->id, 200, (string) Str::uuid());
        $this->actingAs($this->bob)->get(route('dashboard'))->assertOk()
            ->assertSee('Transferência recebida')->assertSee('+R$ 2,00')
            ->assertSee('Operação #'.$transfer->id)->assertDontSee('Reverter');
    }

    public function test_statement_identifies_transfer_participants_with_escaped_names_only_for_their_wallets(): void
    {
        $this->alice->update(['name' => 'Alice <b>Origem</b>']);
        $this->bob->update(['name' => 'Bob <b>Destino</b>']);
        $this->service->deposit($this->alice, 1000, (string) Str::uuid());
        $transfer = $this->service->transfer($this->alice, $this->bob->wallet->id, 200, (string) Str::uuid());

        $this->actingAs($this->alice)->get(route('dashboard'))->assertOk()
            ->assertSee('Para: Bob &lt;b&gt;Destino&lt;/b&gt;', false)
            ->assertDontSee('Para: Bob <b>Destino</b>', false)
            ->assertSee('Operação #'.$transfer->id);
        $this->actingAs($this->bob)->get(route('dashboard'))->assertOk()
            ->assertSee('De: Alice &lt;b&gt;Origem&lt;/b&gt;', false)
            ->assertDontSee('De: Alice <b>Origem</b>', false);

        $outsider = $this->newUser();
        $this->actingAs($outsider)->get(route('dashboard', ['wallet_id' => $this->alice->wallet->id]))
            ->assertOk()->assertDontSee('Operação #'.$transfer->id)
            ->assertDontSee('Para: Bob')->assertDontSee('De: Alice');
    }

    public function test_failed_reversal_on_second_page_keeps_same_key_and_page(): void
    {
        $first = $this->service->deposit($this->alice, 100, (string) Str::uuid());
        $usedKey = (string) Str::uuid();
        $this->service->deposit($this->alice, 200, $usedKey);
        for ($i = 0; $i < 9; $i++) {
            $this->service->deposit($this->alice, 100, (string) Str::uuid());
        }
        $this->actingAs($this->alice)->post(route('operations.reverse', ['operation' => $first->id, 'page' => 2]), $this->keyInput([
            'reverse_operation_id' => $first->id, 'idempotency_key' => $usedKey,
        ]))->assertRedirect(route('dashboard', ['page' => 2]))
            ->assertSessionHas('errors', fn ($errors): bool => $errors->getBag('reverse')->has('wallet'));
        $this->get(route('dashboard', ['page' => 2]))->assertOk()->assertSee($usedKey)->assertSee('Operação #'.$first->id);
        self::assertSame('completed', $first->fresh()->status);
        $this->assertIntegrity();
    }

    public function test_financial_rate_limit_blocks_thirteenth_post_without_extra_credit(): void
    {
        $input = $this->amountInput();
        $this->actingAs($this->alice);
        for ($i = 0; $i < 12; $i++) {
            $this->post(route('wallet.deposit'), $input)->assertSessionHas('status');
        }
        $this->post(route('wallet.deposit'), $input)->assertStatus(429);
        self::assertSame(1000, $this->balance($this->alice));
        self::assertSame(1, Operation::query()->count());
    }

    public function test_failed_second_ledger_write_rolls_back_http_transfer(): void
    {
        $this->service->deposit($this->alice, 1000, (string) Str::uuid());
        $beforeOperations = Operation::query()->count();
        $beforeEntries = LedgerEntry::query()->count();
        $writes = 0;
        Event::listen('eloquent.creating: '.LedgerEntry::class, function () use (&$writes): bool {
            $writes++;

            return $writes !== 2;
        });
        $this->actingAs($this->alice)->post(route('wallet.transfer'), $this->amountInput(['email' => $this->bob->email]))
            ->assertSessionHas('errors', fn ($errors): bool => $errors->getBag('transfer')->has('wallet'));
        self::assertSame($beforeOperations, Operation::query()->count());
        self::assertSame($beforeEntries, LedgerEntry::query()->count());
        self::assertSame(1000, $this->balance($this->alice));
        self::assertSame(0, $this->balance($this->bob));
        $this->assertIntegrity();
    }

    #[DataProvider('internalFailures')]
    public function test_internal_failures_keep_response_and_log_sanitized(Throwable $exception): void
    {
        $key = (string) Str::uuid();
        $this->mock(WalletService::class)->shouldReceive('deposit')->once()->andThrow($exception);
        Log::shouldReceive('error')->once()->with('Falha inesperada em operação financeira.', [
            'type' => $exception::class,
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
        ]);

        $this->actingAs($this->alice)->post(route('wallet.deposit'), $this->amountInput(['idempotency_key' => $key]))
            ->assertRedirect(route('dashboard'))->assertSessionMissing('status')
            ->assertSessionHas('errors', fn ($errors): bool => $errors->getBag('deposit')->has('wallet'));
        $this->get(route('dashboard'))->assertOk()
            ->assertSee('Não foi possível concluir a solicitação. Tente novamente mais tarde.')
            ->assertSee($key)->assertDontSee('private-diagnostic');
        $this->assertNoOperations();
    }

    public static function internalFailures(): array
    {
        return [
            [new \RuntimeException('SELECT private-diagnostic FROM users')],
            [new \Error('private-diagnostic')],
            [new \App\Exceptions\Wallet\FinancialPersistenceException(new \RuntimeException('private-diagnostic'))],
        ];
    }

    public function test_invalid_array_inputs_can_be_redisplayed_without_internal_error(): void
    {
        $this->actingAs($this->alice)->post(route('wallet.deposit'), $this->amountInput([
            'amount' => ['10'], 'idempotency_key' => ['invalid'],
        ]))->assertSessionHas('errors', fn ($errors): bool => $errors->getBag('deposit')->has('amount'));
        $this->get(route('dashboard'))->assertOk();

        $this->actingAs($this->alice)->post(route('wallet.transfer'), $this->amountInput([
            'wallet_form' => 'transfer', 'email' => ['invalid'], 'amount' => ['10'], 'idempotency_key' => ['invalid'],
        ]))->assertSessionHas('errors', fn ($errors): bool => $errors->getBag('transfer')->has('amount'));
        $this->get(route('dashboard'))->assertOk();
        $this->assertNoOperations();
    }

    public function test_invalid_reverse_array_input_can_be_redisplayed(): void
    {
        $original = $this->service->deposit($this->alice, 100, (string) Str::uuid());
        $this->actingAs($this->alice)->post(route('operations.reverse', $original->id), $this->keyInput([
            'idempotency_key' => ['invalid'], 'reverse_operation_id' => ['invalid'],
        ]))->assertSessionHas('errors', fn ($errors): bool => $errors->getBag('reverse')->has('idempotency_key'));
        $this->get(route('dashboard'))->assertOk();
        self::assertSame('completed', $original->fresh()->status);
        self::assertSame(1, Operation::query()->count());
        $this->assertIntegrity();
    }

    private function newUser(): User
    {
        $user = User::factory()->create();
        Wallet::forceCreate(['user_id' => $user->id, 'balance_cents' => 0]);

        return $user->load('wallet');
    }

    private function keyInput(array $overrides = []): array
    {
        return array_replace(['wallet_form' => 'reverse', 'idempotency_key' => (string) Str::uuid()], $overrides);
    }

    private function amountInput(array $overrides = []): array
    {
        return array_replace(['wallet_form' => 'deposit', 'amount' => '10', 'idempotency_key' => (string) Str::uuid()], $overrides);
    }

    private function balance(User $user): int
    {
        return $user->wallet->fresh()->balance_cents;
    }

    private function assertNoOperations(): void
    {
        self::assertSame(0, Operation::query()->count());
        self::assertSame(0, LedgerEntry::query()->count());
        self::assertSame(0, $this->balance($this->alice));
        self::assertSame(0, $this->balance($this->bob));
    }

    private function assertIntegrity(): void
    {
        foreach ([$this->alice, $this->bob] as $user) {
            self::assertSame((int) ($user->wallet->ledgerEntries()->sum('delta_cents')), $this->balance($user));
        }
        foreach (Operation::all() as $operation) {
            self::assertSame($operation->type === 'transfer' || ($operation->type === 'reversal' && $operation->source_wallet_id !== null && $operation->destination_wallet_id !== null) ? 2 : 1, $operation->ledgerEntries()->count());
        }
    }
}

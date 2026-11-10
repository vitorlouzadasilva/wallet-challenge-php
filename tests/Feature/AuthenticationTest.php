<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    private const PASSWORD = 'UmaSenhaSegura!2026';

    protected function setUp(): void
    {
        parent::setUp();

        self::assertSame('mysql', DB::connection()->getDriverName());
        self::assertSame('testing', DB::connection()->getDatabaseName());
        DB::beginTransaction();
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

    public function test_registration_creates_user_and_zero_balance_wallet_without_financial_operations(): void
    {
        $this->startSession();
        $sessionId = session()->getId();
        $operations = DB::table('operations')->count();
        $entries = DB::table('ledger_entries')->count();

        $this->post(route('register.store'), $this->registrationData())->assertRedirect(route('dashboard'));

        $user = User::where('email', 'nova@example.com')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        self::assertSame('Nova Pessoa', $user->name);
        self::assertNotSame(self::PASSWORD, $user->password);
        self::assertTrue(Hash::check(self::PASSWORD, $user->password));
        self::assertSame(1, $user->wallet()->count());
        self::assertSame(0, $user->wallet->balance_cents);
        self::assertSame($operations, DB::table('operations')->count());
        self::assertSame($entries, DB::table('ledger_entries')->count());
        self::assertNotSame($sessionId, session()->getId());
    }

    public function test_registration_ignores_client_user_wallet_and_balance_ids(): void
    {
        $other = $this->userWithWallet();
        $this->post(route('register.store'), $this->registrationData([
            'user_id' => $other->id, 'wallet_id' => $other->wallet->id, 'balance_cents' => 999999,
        ]))->assertRedirect(route('dashboard'));

        $user = User::where('email', 'nova@example.com')->firstOrFail();
        self::assertSame($user->id, $user->wallet->user_id);
        self::assertSame(0, $user->wallet->balance_cents);
        self::assertSame(1, $other->wallet()->count());
        self::assertSame(0, $other->wallet->fresh()->balance_cents);
    }

    #[DataProvider('requiredRegistrationFields')]
    public function test_registration_requires_valid_fields(string $field, string $error): void
    {
        $data = $this->registrationData();
        unset($data[$field]);

        $this->from(route('register'))->post(route('register.store'), $data)
            ->assertRedirect(route('register'))->assertSessionHasErrors($error);

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'nova@example.com']);
        self::assertNull(session('_old_input.password'));
        self::assertNull(session('_old_input.password_confirmation'));
    }

    public static function requiredRegistrationFields(): array
    {
        return [['name', 'name'], ['email', 'email'], ['password', 'password'], ['password_confirmation', 'password']];
    }

    #[DataProvider('invalidRegistrationFields')]
    public function test_registration_rejects_invalid_input(array $overrides, string $field): void
    {
        $this->post(route('register.store'), $this->registrationData($overrides))->assertSessionHasErrors($field);
        $this->assertGuest();
    }

    public static function invalidRegistrationFields(): array
    {
        return [
            'invalid email' => [['email' => 'nao-e-email'], 'email'],
            'long name' => [['name' => str_repeat('a', 256)], 'name'],
            'short password' => [['password' => 'curta', 'password_confirmation' => 'curta'], 'password'],
            'confirmation mismatch' => [['password_confirmation' => 'OutraSenhaSegura'], 'password'],
            'bcrypt byte limit' => [['password' => str_repeat('á', 37), 'password_confirmation' => str_repeat('á', 37)], 'password'],
        ];
    }

    public function test_email_is_normalized_and_duplicate_email_is_rejected(): void
    {
        $this->userWithWallet(['email' => 'nova@example.com']);
        $this->post(route('register.store'), $this->registrationData(['email' => ' NOVA@EXAMPLE.COM ']))
            ->assertSessionHasErrors('email');
        self::assertSame(1, User::where('email', 'nova@example.com')->count());
        $this->assertGuest();
    }

    public function test_new_email_is_stored_in_canonical_form(): void
    {
        $this->post(route('register.store'), $this->registrationData(['email' => ' NOVA@EXAMPLE.COM ']))
            ->assertRedirect(route('dashboard'));
        $this->assertDatabaseHas('users', ['email' => 'nova@example.com']);
    }

    public function test_wallet_creation_failure_rolls_back_the_user_and_does_not_authenticate(): void
    {
        $walletCount = Wallet::count();
        $event = 'eloquent.creating: '.Wallet::class;
        Event::listen($event, function (Wallet $wallet): void {
            $wallet->user_id = 9223372036854775807;
        });

        try {
            $this->from(route('register'))->post(route('register.store'), $this->registrationData())
                ->assertRedirect(route('register'))->assertSessionHasErrors('registration');
        } finally {
            Event::forget($event);
        }

        $this->assertDatabaseMissing('users', ['email' => 'nova@example.com']);
        self::assertSame($walletCount, Wallet::count());
        $this->assertGuest();
        self::assertNull(session('_old_input.password'));
    }

    public function test_valid_login_authenticates_and_regenerates_session_and_token(): void
    {
        $user = $this->userWithWallet(['email' => 'login@example.com']);
        $this->startSession();
        $sessionId = session()->getId();
        $token = session()->token();

        $this->post(route('login.store'), ['email' => ' LOGIN@EXAMPLE.COM ', 'password' => self::PASSWORD])
            ->assertRedirect(route('dashboard'))->assertSessionHasNoErrors();

        $this->assertAuthenticatedAs($user);
        self::assertNotSame($sessionId, session()->getId());
        self::assertNotSame($token, session()->token());
    }

    public function test_invalid_login_does_not_reveal_whether_email_exists_or_flash_password(): void
    {
        $this->userWithWallet(['email' => 'login@example.com']);
        foreach (['login@example.com', 'ausente@example.com'] as $email) {
            $this->from(route('login'))->post(route('login.store'), ['email' => $email, 'password' => 'SenhaIncorreta'])
                ->assertRedirect(route('login'))->assertSessionHasErrors(['email' => 'E-mail ou senha inválidos.']);
            $this->assertGuest();
            self::assertNull(session('_old_input.password'));
        }
    }

    public function test_login_requires_email_and_password(): void
    {
        $this->post(route('login.store'), [])->assertSessionHasErrors(['email', 'password']);
        $this->assertGuest();
    }

    public function test_login_rejects_password_above_bcrypt_byte_limit(): void
    {
        $this->userWithWallet(['email' => 'login@example.com']);
        $this->post(route('login.store'), ['email' => 'login@example.com', 'password' => str_repeat('á', 37)])
            ->assertSessionHasErrors('password');
        $this->assertGuest();
    }

    public function test_login_is_limited_after_five_failures_and_recovers_after_expiration(): void
    {
        $this->userWithWallet(['email' => 'login@example.com']);
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post(route('login.store'), ['email' => 'login@example.com', 'password' => 'SenhaIncorreta'])
                ->assertSessionHasErrors(['email' => 'E-mail ou senha inválidos.']);
        }

        $this->post(route('login.store'), ['email' => 'login@example.com', 'password' => self::PASSWORD])
            ->assertSessionHasErrors('email');
        self::assertStringContainsString('Muitas tentativas.', session('errors')->first('email'));
        $this->assertGuest();

        $this->travel(61)->seconds();
        $this->post(route('login.store'), ['email' => 'login@example.com', 'password' => self::PASSWORD])
            ->assertRedirect(route('dashboard'));
        $this->assertAuthenticated();
    }

    public function test_changing_email_does_not_bypass_the_ip_limit(): void
    {
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $this->post(route('login.store'), ['email' => 'tentativa'.$attempt.'@example.com', 'password' => 'SenhaIncorreta'])
                ->assertSessionHasErrors(['email' => 'E-mail ou senha inválidos.']);
        }
        $this->post(route('login.store'), ['email' => 'outro@example.com', 'password' => 'SenhaIncorreta'])
            ->assertSessionHasErrors('email');
        self::assertStringContainsString('Muitas tentativas.', session('errors')->first('email'));
        $this->assertGuest();
    }

    public function test_logout_invalidates_session_and_regenerates_csrf_token(): void
    {
        $user = $this->userWithWallet();
        $this->startSession();
        $sessionId = session()->getId();
        $token = session()->token();
        $this->actingAs($user)->withSession(['private_marker' => 'sigiloso'])->post(route('logout'))
            ->assertRedirect(route('home'))->assertSessionMissing('private_marker');

        $this->assertGuest();
        self::assertNotSame($sessionId, session()->getId());
        self::assertNotSame($token, session()->token());
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }

    public function test_guest_cannot_access_dashboard_or_logout(): void
    {
        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->post(route('logout'))->assertRedirect(route('login'));
        $this->get('/logout')->assertMethodNotAllowed();
    }

    public function test_authenticated_user_is_redirected_away_from_guest_forms(): void
    {
        $user = $this->userWithWallet();
        foreach (['login', 'register'] as $route) {
            $this->actingAs($user)->get(route($route))->assertRedirect(route('dashboard'));
        }
        $this->post(route('register.store'), $this->registrationData())->assertRedirect(route('dashboard'));
        $this->assertDatabaseMissing('users', ['email' => 'nova@example.com']);
    }

    public function test_dashboard_reads_only_authenticated_users_wallet_even_with_foreign_ids(): void
    {
        $user = $this->userWithWallet(['name' => 'Pessoa Autenticada']);
        $other = $this->userWithWallet(['name' => 'Pessoa Restrita']);
        $user->wallet()->update(['balance_cents' => 12345]);
        $other->wallet()->update(['balance_cents' => 987654]);

        $this->actingAs($user)->get(route('dashboard', ['user_id' => $other->id, 'wallet_id' => $other->wallet->id]))
            ->assertOk()->assertSee('Pessoa Autenticada')->assertSee('R$ 123,45')
            ->assertDontSee('Pessoa Restrita')->assertDontSee('R$ 9.876,54')
            ->assertViewHas('wallet', fn (Wallet $wallet): bool => $wallet->user_id === $user->id);
    }

    public function test_dashboard_escapes_the_users_name(): void
    {
        $user = $this->userWithWallet(['name' => '<script>alert("nome")</script>']);
        $this->actingAs($user)->get(route('dashboard'))->assertOk()
            ->assertDontSee($user->name, false)->assertSee(e($user->name), false);
    }

    public function test_dashboard_does_not_create_a_wallet_for_legacy_users(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get(route('dashboard'))->assertOk()
            ->assertSee('Não foi encontrada uma carteira vinculada à sua conta.');
        self::assertSame(0, $user->wallet()->count());
    }

    public function test_authentication_post_routes_enforce_csrf_outside_the_test_bypass(): void
    {
        $user = $this->userWithWallet();
        // O middleware nativo dispensa CSRF em testes; ativá-lo aqui valida a proteção real.
        $this->app->instance('env', 'local');
        try {
            $this->post(route('register.store'), $this->registrationData())->assertStatus(419);
            $this->post(route('login.store'), ['email' => $user->email, 'password' => self::PASSWORD])->assertStatus(419);
            $this->actingAs($user)->post(route('logout'))->assertStatus(419);
        } finally {
            $this->app->instance('env', 'testing');
        }
    }

    private function registrationData(array $overrides = []): array
    {
        return array_replace([
            'name' => 'Nova Pessoa', 'email' => 'nova@example.com',
            'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD,
        ], $overrides);
    }

    private function userWithWallet(array $attributes = []): User
    {
        $user = User::factory()->create(array_replace(['password' => Hash::make(self::PASSWORD)], $attributes));
        Wallet::forceCreate(['user_id' => $user->id, 'balance_cents' => 0]);

        return $user;
    }
}

<?php

namespace App\Services;

use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use SensitiveParameter;

class RegisterUser
{
    public function create(string $name, string $email, #[SensitiveParameter] string $password): User
    {
        return DB::transaction(function () use ($name, $email, $password): User {
            $user = User::create([
                'name' => $name,
                'email' => $email,
                'password' => Hash::make($password),
            ]);

            // Cadastro e carteira são indivisíveis; não criar operações para o saldo inicial.
            $wallet = new Wallet();
            $wallet->user()->associate($user);
            $wallet->balance_cents = 0;
            $wallet->save();

            return $user;
        });
    }
}

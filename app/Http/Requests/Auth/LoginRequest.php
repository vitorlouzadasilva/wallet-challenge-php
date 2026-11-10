<?php

namespace App\Http\Requests\Auth;

use App\Rules\PasswordByteLimit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => Str::lower(trim($this->input('email')))]);
        }
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', new PasswordByteLimit()],
        ];
    }

    public function messages(): array
    {
        return [
            'email.required' => 'Informe seu e-mail.',
            'email.email' => 'Informe um e-mail válido.',
            'email.max' => 'O e-mail é muito longo.',
            'email.string' => 'Informe um e-mail válido.',
            'password.required' => 'Informe sua senha.',
            'password.string' => 'Informe uma senha válida.',
        ];
    }

    public function authenticate(): void
    {
        $keys = $this->throttleKeys();
        foreach ($keys as $key => $maximum) {
            if (RateLimiter::tooManyAttempts($key, $maximum)) {
                throw ValidationException::withMessages([
                    'email' => 'Muitas tentativas. Tente novamente em '.RateLimiter::availableIn($key).' segundos.',
                ]);
            }
        }

        if (! Auth::attempt($this->only('email', 'password'))) {
            foreach ($keys as $key => $maximum) {
                RateLimiter::hit($key, 60);
            }

            // A mesma mensagem protege contra enumeração de e-mails cadastrados.
            throw ValidationException::withMessages(['email' => 'E-mail ou senha inválidos.']);
        }

        RateLimiter::clear(array_key_first($keys));
    }

    private function throttleKeys(): array
    {
        // Armazenar hashes evita expor e-mails e IPs nas chaves do cache.
        return [
            'login:'.hash('sha256', $this->string('email').'|'.$this->ip()) => 5,
            'login-ip:'.hash('sha256', (string) $this->ip()) => 20,
        ];
    }
}

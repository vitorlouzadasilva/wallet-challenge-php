<?php

declare(strict_types=1);

namespace App\Http\Requests\Wallet;

use App\Support\ReaisToCents;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class TransferRequest extends FormRequest
{
    protected $errorBag = 'transfer';

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function getRedirectUrl(): string
    {
        return route('dashboard');
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
            'amount' => ['required', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                if (ReaisToCents::parse($value) === null) {
                    $fail('Informe um valor positivo em reais, como 10,50, sem separador de milhares.');
                }
            }],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.required' => 'Informe o e-mail do destinatário.',
            'email.email' => 'Informe um e-mail válido.',
            'email.max' => 'O e-mail é muito longo.',
            'amount.required' => 'Informe o valor da transferência.',
            'amount.string' => 'Informe um valor válido em reais.',
            'idempotency_key.required' => 'Atualize a página e tente novamente.',
            'idempotency_key.uuid' => 'Atualize a página e tente novamente.',
        ];
    }

    public function amountCents(): int
    {
        return ReaisToCents::parse($this->validated('amount'));
    }
}

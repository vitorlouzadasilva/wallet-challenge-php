<?php

declare(strict_types=1);

namespace App\Http\Requests\Wallet;

use App\Support\ReaisToCents;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class DepositRequest extends FormRequest
{
    protected $errorBag = 'deposit';

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function getRedirectUrl(): string
    {
        return route('dashboard');
    }

    public function rules(): array
    {
        return [
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
            'amount.required' => 'Informe o valor do depósito.',
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

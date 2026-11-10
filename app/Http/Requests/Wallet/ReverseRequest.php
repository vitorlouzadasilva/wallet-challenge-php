<?php

declare(strict_types=1);

namespace App\Http\Requests\Wallet;

use Illuminate\Foundation\Http\FormRequest;

class ReverseRequest extends FormRequest
{
    protected $errorBag = 'reverse';

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function getRedirectUrl(): string
    {
        $page = $this->statementPage();

        return route('dashboard', $page > 1 ? ['page' => $page] : []);
    }

    /** Mantém o formulário de reversão visível após erro em uma página do extrato. */
    public function statementPage(): int
    {
        $page = $this->query('page');

        return is_string($page) && ctype_digit($page) && (int) $page > 0 && (int) $page <= 1000000
            ? (int) $page : 1;
    }

    public function rules(): array
    {
        return ['idempotency_key' => ['required', 'uuid']];
    }

    public function messages(): array
    {
        return [
            'idempotency_key.required' => 'Atualize a página e tente novamente.',
            'idempotency_key.uuid' => 'Atualize a página e tente novamente.',
        ];
    }
}

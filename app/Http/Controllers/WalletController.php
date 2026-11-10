<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\Wallet\BalanceLimitExceeded;
use App\Exceptions\Wallet\IdempotencyConflict;
use App\Exceptions\Wallet\InsufficientBalance;
use App\Exceptions\Wallet\InvalidAmount;
use App\Exceptions\Wallet\InvalidIdempotencyKey;
use App\Exceptions\Wallet\InvalidOperation;
use App\Exceptions\Wallet\OperationAlreadyReversed;
use App\Exceptions\Wallet\UnauthorizedOperation;
use App\Exceptions\Wallet\WalletNotFound;
use App\Http\Requests\Wallet\DepositRequest;
use App\Http\Requests\Wallet\ReverseRequest;
use App\Http\Requests\Wallet\TransferRequest;
use App\Models\User;
use App\Services\WalletService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

class WalletController extends Controller
{
    public function __construct(private readonly WalletService $walletService)
    {
    }

    /** A carteira de destino do depósito é descoberta pelo serviço a partir da sessão. */
    public function deposit(DepositRequest $request): RedirectResponse
    {
        try {
            $this->walletService->deposit($request->user(), $request->amountCents(), $request->validated('idempotency_key'));

            return redirect()->route('dashboard')->with('status', 'Depósito realizado com sucesso.');
        } catch (Throwable $exception) {
            return $this->failure($exception, 'deposit', $request, ['amount', 'idempotency_key']);
        }
    }

    /** Resolve e-mail para carteira no servidor; o formulário não escolhe a origem. */
    public function transfer(TransferRequest $request): RedirectResponse
    {
        try {
            $recipient = User::query()->with('wallet')->where('email', $request->validated('email'))->first();
            if ($recipient === null || $recipient->wallet === null) {
                return $this->formError('transfer', 'Destinatário indisponível para transferência.', $request, ['email', 'amount', 'idempotency_key']);
            }
            if ($recipient->is($request->user())) {
                return $this->formError('transfer', 'Não é possível transferir para sua própria conta.', $request, ['email', 'amount', 'idempotency_key']);
            }

            $this->walletService->transfer($request->user(), (int) $recipient->wallet->id, $request->amountCents(), $request->validated('idempotency_key'));

            return redirect()->route('dashboard')->with('status', 'Transferência realizada com sucesso.');
        } catch (Throwable $exception) {
            return $this->failure($exception, 'transfer', $request, ['email', 'amount', 'idempotency_key']);
        }
    }

    /** O serviço carrega original, participantes e valor; somente o ID vem da rota. */
    public function reverse(ReverseRequest $request, string $operation): RedirectResponse
    {
        $operationId = filter_var($operation, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($operationId === false) {
            return $this->formError('reverse', 'Esta operação não está disponível para sua conta.', $request, ['idempotency_key']);
        }

        try {
            $this->walletService->reverse($request->user(), $operationId, $request->validated('idempotency_key'));

            return redirect()->route('dashboard')->with('status', 'Operação revertida com sucesso.');
        } catch (Throwable $exception) {
            return $this->failure($exception, 'reverse', $request, ['idempotency_key'], $operationId);
        }
    }

    /** Traduz somente falhas conhecidas; falhas internas recebem diagnóstico sanitizado. */
    private function failure(Throwable $exception, string $form, DepositRequest|TransferRequest|ReverseRequest $request, array $fields, ?int $operation = null): RedirectResponse
    {
        $message = match (true) {
            $exception instanceof InsufficientBalance => 'Saldo insuficiente para realizar a transferência.',
            $exception instanceof OperationAlreadyReversed => 'Esta operação já foi revertida.',
            $exception instanceof InvalidAmount => 'Informe um valor positivo válido.',
            $exception instanceof InvalidIdempotencyKey => 'Atualize a página e tente novamente.',
            $exception instanceof IdempotencyConflict => 'Esta chave já foi usada em outra solicitação. Atualize a página para iniciar uma nova operação.',
            $exception instanceof BalanceLimitExceeded => 'O saldo resultante ultrapassaria o limite permitido.',
            $exception instanceof WalletNotFound => 'Carteira indisponível para esta solicitação.',
            $exception instanceof UnauthorizedOperation, $exception instanceof InvalidOperation => 'Esta operação não está disponível para sua conta.',
            default => null,
        };

        if ($message === null) {
            // Não registrar SQL, bindings nem o payload; classe e localização bastam para investigação inicial.
            Log::error('Falha inesperada em operação financeira.', [
                'type' => $exception::class,
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ]);
            $message = 'Não foi possível concluir a solicitação. Tente novamente mais tarde.';
        }

        return $this->formError($form, $message, $request, $fields, $operation);
    }

    /** Redireciona sempre ao dashboard e mantém os dados da tentativa para replay seguro. */
    private function formError(string $form, string $message, DepositRequest|TransferRequest|ReverseRequest $request, array $fields, ?int $operation = null): RedirectResponse
    {
        $input = $request->only($fields);
        $input['wallet_form'] = $form;
        if ($operation !== null) {
            $input['reverse_operation_id'] = $operation;
        }

        $parameters = $request instanceof ReverseRequest && $request->statementPage() > 1
            ? ['page' => $request->statementPage()] : [];

        return redirect()->route('dashboard', $parameters)->withErrors(['wallet' => $message], $form)->withInput($input);
    }
}

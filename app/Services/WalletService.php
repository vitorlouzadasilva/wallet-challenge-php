<?php

declare(strict_types=1);

namespace App\Services;

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
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Coordena os casos de uso financeiros, sem dependência de HTTP ou da interface.
 * O chamador deve fornecer o usuário autenticado, nunca um ator escolhido pelo cliente.
 * Valores entram como mixed para rejeitar floats/strings mesmo em chamadores PHP sem strict_types.
 */
class WalletService
{
    /** Deposita na carteira do ator, preservando saldo anterior, inclusive negativo. */
    public function deposit(User $actor, mixed $amountCents, string $idempotencyKey): Operation
    {
        $amount = $this->positiveAmount($amountCents);
        $this->validateKey($idempotencyKey);
        $actorId = $this->actorId($actor);
        return $this->transact(function (bool $recoveringCollision) use ($actorId, $amount, $idempotencyKey): Operation {
            $walletId = $this->ownWalletId($actorId);
            $attributes = $this->attributes($actorId, 'deposit', $amount, null, $walletId, $idempotencyKey);
            if ($replay = $this->replay($attributes, $recoveringCollision)) {
                return $replay;
            }

            $wallet = $this->lockWallets([$walletId])[$walletId];
            $this->assertOwner($wallet, $actorId);
            $operation = $this->createOperation($attributes);
            $this->applyEntry($operation, $wallet, $amount);

            return $operation->load('ledgerEntries');
        });
    }

    /** Transfere da carteira do ator para outra carteira, após bloquear ambas e verificar o saldo. */
    public function transfer(User $actor, int $destinationWalletId, mixed $amountCents, string $idempotencyKey): Operation
    {
        $amount = $this->positiveAmount($amountCents);
        $this->validateKey($idempotencyKey);
        $actorId = $this->actorId($actor);
        return $this->transact(function (bool $recoveringCollision) use ($actorId, $destinationWalletId, $amount, $idempotencyKey): Operation {
            $sourceWalletId = $this->ownWalletId($actorId);
            $attributes = $this->attributes($actorId, 'transfer', $amount, $sourceWalletId, $destinationWalletId, $idempotencyKey);
            if ($replay = $this->replay($attributes, $recoveringCollision)) {
                return $replay;
            }
            if ($sourceWalletId === $destinationWalletId) {
                throw new InvalidOperation();
            }

            $wallets = $this->lockWallets([$sourceWalletId, $destinationWalletId]);
            $source = $wallets[$sourceWalletId];
            $destination = $wallets[$destinationWalletId];
            $this->assertOwner($source, $actorId);
            if ((int) $destination->user_id === $actorId) {
                throw new InvalidOperation();
            }

            // Reservar a chave antes de validar saldo evita rejeitar um replay concorrente já debitado.
            $operation = $this->createOperation($attributes);
            if ($source->balance_cents < $amount) {
                throw new InsufficientBalance();
            }

            $this->applyEntry($operation, $source, -$amount);
            $this->applyEntry($operation, $destination, $amount);

            return $operation->load('ledgerEntries');
        });
    }

    /** Compensa uma original autorizada, preservando seu histórico e permitindo saldo negativo. */
    public function reverse(User $actor, int $originalOperationId, string $idempotencyKey): Operation
    {
        $this->validateKey($idempotencyKey);
        $actorId = $this->actorId($actor);

        return $this->transact(function (bool $recoveringCollision) use ($actorId, $originalOperationId, $idempotencyKey): Operation {
            // A original é bloqueada antes de consultar estado, participantes ou elegibilidade.
            $original = Operation::query()->whereKey($originalOperationId)->lockForUpdate()->first();
            if ($original === null || (int) $original->initiated_by_user_id !== $actorId) {
                throw new UnauthorizedOperation();
            }
            if (! in_array($original->type, ['deposit', 'transfer'], true)) {
                throw new InvalidOperation();
            }

            $deltas = $this->originalDeltas($original);
            $wallets = $this->lockWallets(array_keys($deltas));
            $ownedWalletId = $original->type === 'deposit' ? $original->destination_wallet_id : $original->source_wallet_id;
            $this->assertOwner($wallets[(int) $ownedWalletId], $actorId);
            $attributes = $this->attributes(
                $actorId,
                'reversal',
                $original->amount_cents,
                (int) $original->destination_wallet_id,
                $original->source_wallet_id === null ? null : (int) $original->source_wallet_id,
                $idempotencyKey,
                (int) $original->id
            );

            // O replay antecede o teste de status: uma original reversed não deve movimentar novamente.
            if ($replay = $this->replay($attributes, $recoveringCollision, lockRead: true)) {
                return $replay;
            }
            if ($original->status !== 'completed') {
                throw new OperationAlreadyReversed();
            }

            $reversal = $this->createOperation($attributes);
            $this->assertOriginalLedger($original, $deltas);
            foreach ($wallets as $walletId => $wallet) {
                $this->applyEntry($reversal, $wallet, -$deltas[$walletId]);
            }
            $original->status = 'reversed';
            $this->persist($original);

            return $reversal->load('ledgerEntries');
        });
    }

    /** Rejeita coerção monetária e garante um valor representável em BIGINT assinado de 64 bits. */
    private function positiveAmount(mixed $amount): int
    {
        if (! is_int($amount) || $amount <= 0) {
            throw new InvalidAmount();
        }

        return $amount;
    }

    /** Valida a identidade da solicitação sem normalizá-la nem aceitar controles invisíveis. */
    private function validateKey(string $key): void
    {
        if (! mb_check_encoding($key, 'UTF-8') || mb_strlen($key, 'UTF-8') < 1
            || mb_strlen($key, 'UTF-8') > 128 || preg_match('/\A\s|\s\z|[\x00-\x1F\x7F]/u', $key)) {
            throw new InvalidIdempotencyKey();
        }
    }

    /** Exige contexto de usuário persistido; nomes e demais atributos recebidos não autorizam ações. */
    private function actorId(User $actor): int
    {
        if (! $actor->exists || $actor->getKey() === null || (int) $actor->getKey() < 1) {
            throw new UnauthorizedOperation();
        }

        return (int) $actor->getKey();
    }

    /** Descobre a origem no servidor; depósito e transferência não recebem carteira de origem do cliente. */
    private function ownWalletId(int $actorId): int
    {
        $id = Wallet::query()->where('user_id', $actorId)->value('id');
        if ($id === null) {
            throw new WalletNotFound();
        }

        return (int) $id;
    }

    /** Adquire locks por PK em ordem crescente, sem depender do plano de execução de um WHERE IN. */
    private function lockWallets(array $ids): array
    {
        $ids = array_values(array_unique($ids));
        sort($ids, SORT_NUMERIC);
        $wallets = [];
        foreach ($ids as $id) {
            $wallet = Wallet::query()->whereKey($id)->lockForUpdate()->first();
            if ($wallet === null) {
                throw new WalletNotFound();
            }
            $wallets[$id] = $wallet;
        }

        return $wallets;
    }

    /** Revalida a propriedade usando a carteira carregada do banco sob bloqueio. */
    private function assertOwner(Wallet $wallet, int $actorId): void
    {
        if ((int) $wallet->user_id !== $actorId) {
            throw new UnauthorizedOperation();
        }
    }

    /** Monta os dados canônicos internos usados tanto na persistência quanto na comparação do replay. */
    private function attributes(int $actorId, string $type, int $amount, ?int $source, ?int $destination, string $key, ?int $original = null): array
    {
        return [
            'initiated_by_user_id' => $actorId,
            'type' => $type,
            'amount_cents' => $amount,
            'source_wallet_id' => $source,
            'destination_wallet_id' => $destination,
            'idempotency_key' => $key,
            'reversal_of_operation_id' => $original,
        ];
    }

    /** Retorna somente um replay do mesmo ator e conteúdo, inclusive quando a original já foi revertida. */
    private function replay(array $attributes, bool $recoveringCollision, bool $lockRead = false): ?Operation
    {
        $query = Operation::query()->where('idempotency_key', $attributes['idempotency_key']);
        if ($recoveringCollision || $lockRead) {
            // A leitura atual enxerga a vencedora mesmo com snapshot antigo de uma transação externa.
            $query->lockForUpdate();
        }
        $operation = $query->first();
        if ($operation === null) {
            if ($recoveringCollision) {
                throw new FinancialPersistenceException();
            }

            return null;
        }

        foreach ($attributes as $field => $expected) {
            $actual = $operation->getAttribute($field);
            if (is_int($expected) && $actual !== null) {
                $actual = (int) $actual;
            }
            if ($actual !== $expected) {
                // A resposta é igual para outro ator ou outro conteúdo, sem revelar a operação encontrada.
                throw new IdempotencyConflict();
            }
        }
        if (! in_array($operation->status, ['completed', 'reversed'], true)) {
            throw new LogicException('Estado financeiro persistido inconsistente.');
        }

        return $operation->load('ledgerEntries');
    }

    /** Registra a operação e reserva suas restrições únicas antes de qualquer efeito financeiro. */
    private function createOperation(array $attributes): Operation
    {
        $operation = new Operation();
        foreach ($attributes as $field => $value) {
            $operation->setAttribute($field, $value);
        }
        $operation->status = 'completed';
        $this->persist($operation);

        return $operation;
    }

    /** Verifica overflow/underflow antes da soma; PHP não deve promover um resultado monetário a float. */
    private function addCents(int $balance, int $delta): int
    {
        if (($delta > 0 && $balance > PHP_INT_MAX - $delta)
            || ($delta < 0 && $balance < PHP_INT_MIN - $delta)) {
            throw new BalanceLimitExceeded();
        }

        return $balance + $delta;
    }

    /** Mantém saldo materializado e lançamento na mesma transação, sem atualizar históricos anteriores. */
    private function applyEntry(Operation $operation, Wallet $wallet, int $delta): void
    {
        $wallet->balance_cents = $this->addCents($wallet->balance_cents, $delta);
        $this->persist($wallet);
        $entry = new LedgerEntry();
        $entry->operation()->associate($operation);
        $entry->wallet()->associate($wallet);
        $entry->delta_cents = $delta;
        $this->persist($entry);
    }

    /** Reconstrói o efeito esperado da original a partir de valores e participantes persistidos. */
    private function originalDeltas(Operation $original): array
    {
        $amount = $original->amount_cents;
        $destination = $original->destination_wallet_id;
        $source = $original->source_wallet_id;
        if ($amount <= 0 || $destination === null
            || ($original->type === 'deposit' && $source !== null)
            || ($original->type === 'transfer' && ($source === null || (int) $source === (int) $destination))) {
            throw new LogicException('Participantes ou valor da operação original estão inconsistentes.');
        }

        return $original->type === 'deposit'
            ? [(int) $destination => $amount]
            : [(int) $source => -$amount, (int) $destination => $amount];
    }

    /** Impede compensar histórico ausente ou adulterado; os lançamentos originais permanecem imutáveis. */
    private function assertOriginalLedger(Operation $original, array $expected): void
    {
        $entries = $original->ledgerEntries()->orderBy('wallet_id')->lockForUpdate()->get();
        if ($entries->count() !== count($expected)) {
            throw new LogicException('Os lançamentos da operação original estão inconsistentes.');
        }
        foreach ($entries as $entry) {
            if (! array_key_exists((int) $entry->wallet_id, $expected) || $entry->delta_cents !== $expected[(int) $entry->wallet_id]) {
                throw new LogicException('Os lançamentos da operação original estão inconsistentes.');
            }
        }
    }

    /** Cancelamento de save por evento Eloquent também aborta a unidade financeira. */
    private function persist(Model $model): void
    {
        if (! $model->save()) {
            throw new FinancialPersistenceException();
        }
    }

    /** Reexecuta deadlocks e recupera uma colisão idempotente somente depois do rollback da tentativa. */
    private function transact(Closure $action): Operation
    {
        $recoveringCollision = false;
        while (true) {
            try {
                return DB::transaction(fn (): Operation => $action($recoveringCollision), attempts: 3);
            } catch (QueryException $exception) {
                // Este catch fica fora de DB::transaction: a tentativa perdedora já foi desfeita.
                if ($this->violatesUnique($exception, 'operations_idempotency_key_unique') && ! $recoveringCollision) {
                    $recoveringCollision = true;

                    continue;
                }
                if ($this->violatesUnique($exception, 'operations_reversal_of_operation_id_unique')) {
                    throw new OperationAlreadyReversed();
                }

                throw new FinancialPersistenceException($exception);
            }
        }
    }

    /** Identifica o índice no erro nativo; não confunde valores controlados pelo cliente com nomes de índices. */
    private function violatesUnique(QueryException $exception, string $index): bool
    {
        return (int) ($exception->errorInfo[1] ?? 0) === 1062
            && preg_match('/for key [\'`](?:[^\'`]+\.)?'.preg_quote($index, '/').'[\'`]\z/', (string) ($exception->errorInfo[2] ?? '')) === 1;
    }
}

@extends('layouts.app')
@section('title', 'Minha carteira')
@section('content')
<div class="container page-spacing">
    <div class="d-flex flex-wrap align-items-end justify-content-between gap-3 mb-4">
        <div>
            <p class="eyebrow">MINHA CARTEIRA</p>
            <h1 class="h2 fw-bold mb-2">Olá, {{ $user->name }}.</h1>
            <p class="text-secondary mb-0">Acompanhe seu saldo e suas movimentações.</p>
        </div>
        <span class="account-badge">Conta individual</span>
    </div>
    @if($wallet)
        <section class="balance-panel mb-4" aria-labelledby="balance-heading">
            <div class="balance-panel-content">
                <h2 id="balance-heading" class="balance-label">Saldo atual</h2>
                <p class="balance-amount mb-3">{{ $formattedBalance }}</p>
                <p class="balance-caption mb-0">Saldo disponível na sua carteira, em reais.</p>
            </div>
            <span class="balance-decoration" aria-hidden="true"></span>
        </section>
        <div class="row g-4 mb-4">
            <section class="col-lg-6" aria-labelledby="deposit-heading">
                <div class="surface wallet-action h-100">
                    <p class="eyebrow mb-2">ADICIONAR SALDO</p>
                    <h2 id="deposit-heading" class="h5 fw-bold mb-3">Depositar</h2>
                    @error('wallet', 'deposit')<div class="alert alert-danger" role="alert">{{ $message }}</div>@enderror
                    <form method="POST" action="{{ route('wallet.deposit') }}">
                        @csrf
                        <input type="hidden" name="wallet_form" value="deposit">
                        <input type="hidden" name="idempotency_key" value="{{ old('wallet_form') === 'deposit' ? old('idempotency_key', $depositKey) : $depositKey }}">
                        <label for="deposit-amount" class="form-label">Valor em reais</label>
                        <input id="deposit-amount" name="amount" type="text" inputmode="decimal" autocomplete="off"
                               placeholder="10,50" value="{{ old('wallet_form') === 'deposit' ? old('amount') : '' }}"
                               class="form-control @error('amount', 'deposit') is-invalid @enderror" required aria-describedby="deposit-help">
                        <div id="deposit-help" class="form-text">Exemplo: 10,50. Sem separador de milhares.</div>
                        @error('amount', 'deposit')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                        @error('idempotency_key', 'deposit')<div class="text-danger small mt-2">{{ $message }}</div>@enderror
                        <button type="submit" class="btn btn-primary mt-3">Confirmar depósito</button>
                    </form>
                </div>
            </section>
            <section class="col-lg-6" aria-labelledby="transfer-heading">
                <div class="surface wallet-action h-100">
                    <p class="eyebrow mb-2">ENVIAR VALOR</p>
                    <h2 id="transfer-heading" class="h5 fw-bold mb-3">Transferir</h2>
                    @error('wallet', 'transfer')<div class="alert alert-danger" role="alert">{{ $message }}</div>@enderror
                    <form method="POST" action="{{ route('wallet.transfer') }}">
                        @csrf
                        <input type="hidden" name="wallet_form" value="transfer">
                        <input type="hidden" name="idempotency_key" value="{{ old('wallet_form') === 'transfer' ? old('idempotency_key', $transferKey) : $transferKey }}">
                        <div class="mb-3">
                            <label for="transfer-email" class="form-label">E-mail do destinatário</label>
                            <input id="transfer-email" name="email" type="email" autocomplete="email"
                                   value="{{ old('wallet_form') === 'transfer' ? old('email') : '' }}"
                                   class="form-control @error('email', 'transfer') is-invalid @enderror" required>
                            @error('email', 'transfer')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <label for="transfer-amount" class="form-label">Valor em reais</label>
                        <input id="transfer-amount" name="amount" type="text" inputmode="decimal" autocomplete="off"
                               placeholder="10,50" value="{{ old('wallet_form') === 'transfer' ? old('amount') : '' }}"
                               class="form-control @error('amount', 'transfer') is-invalid @enderror" required aria-describedby="transfer-help">
                        <div id="transfer-help" class="form-text">Exemplo: 10,50. O destinatário precisa ter uma conta.</div>
                        @error('amount', 'transfer')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                        @error('idempotency_key', 'transfer')<div class="text-danger small mt-2">{{ $message }}</div>@enderror
                        <button type="submit" class="btn btn-primary mt-3">Confirmar transferência</button>
                    </form>
                </div>
            </section>
        </div>
    @else
        <div class="alert alert-warning" role="alert">Não foi encontrada uma carteira vinculada à sua conta.</div>
    @endif
    @if($wallet)
    <section class="surface movement-panel" aria-labelledby="movements-heading">
        <div class="d-flex flex-wrap justify-content-between gap-2 align-items-center mb-4">
            <h2 id="movements-heading" class="h5 fw-bold mb-0">Extrato</h2>
            <span class="text-secondary small">Somente as movimentações da sua carteira</span>
        </div>
        @error('wallet', 'reverse')<div class="alert alert-danger" role="alert">{{ $message }}</div>@enderror
        @error('idempotency_key', 'reverse')<div class="alert alert-danger" role="alert">{{ $message }}</div>@enderror
        @if($entries->isEmpty())
            <div class="empty-state">
                <span class="empty-symbol" aria-hidden="true">↔</span>
                <h3 class="h6 fw-bold mt-3">Nenhuma movimentação ainda</h3>
                <p class="text-secondary mb-0">Seus depósitos, transferências e reversões aparecerão aqui.</p>
            </div>
        @else
            <div class="table-responsive">
                <table class="table align-middle wallet-table">
                    <thead><tr><th scope="col">Data</th><th scope="col">Movimento</th><th scope="col">Situação</th><th scope="col" class="text-end">Valor</th><th scope="col" class="text-end">Ação</th></tr></thead>
                    <tbody>
                    @foreach($entries as $entry)
                        @php
                            $operation = $entry->operation;
                            $isDebit = $entry->delta_cents < 0;
                            $canReverse = $operation->status === 'completed'
                                && in_array($operation->type, ['deposit', 'transfer'], true)
                                && (int) $operation->initiated_by_user_id === (int) $user->id
                                && (int) ($operation->type === 'deposit' ? $operation->destination_wallet_id : $operation->source_wallet_id) === (int) $wallet->id;
                            $reverseKey = (string) \Illuminate\Support\Str::uuid();
                        @endphp
                        <tr>
                            <td class="text-nowrap"><time datetime="{{ $entry->created_at->toIso8601String() }}">{{ $entry->created_at->timezone('America/Sao_Paulo')->format('d/m/Y H:i') }}</time></td>
                            <td>
                                <span class="fw-semibold">
                                    @if($operation->type === 'deposit')Depósito
                                    @elseif($operation->type === 'transfer')Transferência {{ $isDebit ? 'enviada' : 'recebida' }}
                                    @else Reversão de {{ $operation->originalOperation?->type === 'deposit' ? 'depósito' : 'transferência' }}
                                    @endif
                                </span>
                                <small class="d-block text-secondary">Operação #{{ $operation->id }}@if($operation->reversal_of_operation_id) · Ref. #{{ $operation->reversal_of_operation_id }}@endif</small>
                            </td>
                            <td><span class="badge {{ $operation->status === 'reversed' ? 'text-bg-secondary' : 'text-bg-success' }}">{{ $operation->status === 'reversed' ? 'Revertida' : 'Concluída' }}</span></td>
                            <td class="text-end text-nowrap fw-semibold {{ $isDebit ? 'text-danger' : 'text-success' }}">{{ $isDebit ? '' : '+' }}{{ \App\Support\BrazilianCurrency::format($entry->delta_cents) }}</td>
                            <td class="text-end">
                                @if($canReverse)
                                    <form method="POST" action="{{ route('operations.reverse', ['operation' => $operation->id, 'page' => $entries->currentPage()]) }}" onsubmit="return confirm('Confirma a reversão da operação #{{ $operation->id }}?');">
                                        @csrf
                                        <input type="hidden" name="wallet_form" value="reverse">
                                        <input type="hidden" name="reverse_operation_id" value="{{ $operation->id }}">
                                        <input type="hidden" name="idempotency_key" value="{{ old('wallet_form') === 'reverse' && (string) old('reverse_operation_id') === (string) $operation->id ? old('idempotency_key', $reverseKey) : $reverseKey }}">
                                        <button type="submit" class="btn btn-outline-primary btn-sm">Reverter</button>
                                    </form>
                                @else
                                    <span class="text-secondary small">—</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-3">{{ $entries->links() }}</div>
        @endif
    </section>
    @endif
</div>
@endsection

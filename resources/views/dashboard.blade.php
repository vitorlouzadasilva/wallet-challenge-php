@extends('layouts.app')
@section('title', 'Minha carteira')
@section('content')
<div class="container page-spacing">
    <div class="d-flex flex-wrap align-items-end justify-content-between gap-3 mb-4">
        <div>
            <p class="eyebrow">MINHA CARTEIRA</p>
            <h1 class="h2 fw-bold mb-2">Olá, {{ $user->name }}.</h1>
            <p class="text-secondary mb-0">Confira uma visão geral do seu saldo.</p>
        </div>
        <span class="account-badge">Conta individual</span>
    </div>
    @if($wallet)
        <section class="balance-panel mb-4" aria-labelledby="balance-heading">
            <div class="balance-panel-content">
                <h2 id="balance-heading" class="balance-label">Saldo atual</h2>
                <p class="balance-amount mb-3">{{ $formattedBalance }}</p>
                <p class="balance-caption mb-0">O saldo da sua carteira, em reais.</p>
            </div>
            <span class="balance-decoration" aria-hidden="true"></span>
        </section>
    @else
        <div class="alert alert-warning" role="alert">Não foi encontrada uma carteira vinculada à sua conta.</div>
    @endif
    <section class="surface movement-panel" aria-labelledby="movements-heading">
        <div class="d-flex flex-wrap justify-content-between gap-2 align-items-center mb-4">
            <h2 id="movements-heading" class="h5 fw-bold mb-0">Movimentações</h2>
            <span class="text-secondary small">Seu histórico, em um só lugar</span>
        </div>
        <div class="empty-state">
            <span class="empty-symbol" aria-hidden="true">↔</span>
            <h3 class="h6 fw-bold mt-3">Um espaço para suas movimentações</h3>
            <p class="text-secondary mb-0">O histórico de depósitos e transferências estará disponível em breve.</p>
        </div>
    </section>
</div>
@endsection

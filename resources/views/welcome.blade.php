@extends('layouts.app')
@section('title', 'Bem-vindo')
@section('content')
<div class="container hero-section">
    <div class="row align-items-center justify-content-between g-5">
        <section class="col-lg-6" aria-labelledby="home-heading">
            <p class="eyebrow">SIMPLES. INDIVIDUAL. SUA.</p>
            <h1 id="home-heading" class="display-heading">Sua carteira,<br>com mais clareza.</h1>
            <p class="lead text-secondary mt-4 mb-4">Um espaço para acompanhar o seu saldo, com uma experiência simples desde o primeiro acesso.</p>
            <div class="d-flex flex-wrap gap-3 align-items-center">
                @auth
                    <a href="{{ route('dashboard') }}" class="btn btn-primary">Acessar minha carteira <span aria-hidden="true" class="ms-2">→</span></a>
                @else
                    <a href="{{ route('register') }}" class="btn btn-primary">Criar minha conta <span aria-hidden="true" class="ms-2">→</span></a>
                    <a href="{{ route('login') }}" class="btn btn-outline-primary">Já tenho uma conta</a>
                @endauth
            </div>
        </section>
        <section class="col-lg-5" aria-labelledby="onboarding-heading">
            <div class="onboarding-card">
                <p class="eyebrow mb-3">PRIMEIROS PASSOS</p>
                <h2 id="onboarding-heading" class="h3 fw-bold">Tudo começa<br>com a sua conta.</h2>
                <ol class="onboarding-steps">
                    <li><h3 class="h6 fw-bold mb-0">Crie seu acesso</h3><p>Cadastre seu nome, e-mail e uma senha.</p></li>
                    <li><h3 class="h6 fw-bold mb-0">Conheça sua carteira</h3><p>Uma carteira individual é criada junto com a conta.</p></li>
                    <li><h3 class="h6 fw-bold mb-0">Acompanhe seu saldo</h3><p>Consulte o valor da sua carteira sempre que entrar.</p></li>
                </ol>
            </div>
        </section>
    </div>
</div>
@endsection

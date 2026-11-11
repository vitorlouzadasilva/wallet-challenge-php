@extends('layouts.app')
@section('title', 'Criar conta')
@section('content')
<div class="container page-spacing">
    <div class="row g-5 align-items-start justify-content-between">
        <section class="col-lg-5 auth-intro" aria-labelledby="register-heading">
            <p class="eyebrow">SUA CARTEIRA COMEÇA AQUI</p>
            <h1 id="register-heading" class="display-heading">Um novo começo.<br>Uma carteira sua.</h1>
            <p class="lead text-secondary mt-4">Crie sua conta para acompanhar o seu saldo em um só lugar.</p>
            <p class="intro-note mt-4">Sua carteira é criada junto com a conta e começa com saldo de R$ 0,00.</p>
        </section>
        <section class="col-lg-6" aria-labelledby="register-form-heading">
            <div class="surface form-surface">
                <h2 id="register-form-heading" class="h4 fw-bold mb-2">Crie sua conta</h2>
                <p class="text-secondary mb-4">Preencha seus dados para começar.</p>
                @error('registration')<div class="alert alert-danger" role="alert">{{ $message }}</div>@enderror
                <form method="POST" action="{{ route('register.store') }}">
                    @csrf
                    <div class="mb-3">
                        <label for="name" class="form-label">Nome</label>
                        <input type="text" class="form-control @error('name') is-invalid @enderror" id="name" name="name"
                               value="{{ is_string(old('name')) ? old('name') : '' }}" autocomplete="name" maxlength="255" required autofocus
                               @error('name') aria-invalid="true" aria-describedby="name-error" @enderror>
                        @error('name')<div id="name-error" class="invalid-feedback" role="alert">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-3">
                        <label for="email" class="form-label">E-mail</label>
                        <input type="email" class="form-control @error('email') is-invalid @enderror" id="email" name="email"
                               value="{{ is_string(old('email')) ? old('email') : '' }}" autocomplete="email" maxlength="255" required
                               @error('email') aria-invalid="true" aria-describedby="email-error" @enderror>
                        @error('email')<div id="email-error" class="invalid-feedback" role="alert">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-3">
                        <label for="password" class="form-label">Senha</label>
                        <input type="password" class="form-control @error('password') is-invalid @enderror" id="password" name="password"
                               autocomplete="new-password" minlength="12" required aria-describedby="password-help @error('password') password-error @enderror"
                               @error('password') aria-invalid="true" @enderror>
                        <div id="password-help" class="form-text">Use pelo menos 12 caracteres.</div>
                        @error('password')<div id="password-error" class="invalid-feedback" role="alert">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-4">
                        <label for="password_confirmation" class="form-label">Confirme sua senha</label>
                        <input type="password" class="form-control" id="password_confirmation" name="password_confirmation"
                               autocomplete="new-password" minlength="12" required>
                    </div>
                    <button type="submit" class="btn btn-primary w-100">Criar minha conta</button>
                </form>
                <p class="text-center text-secondary mt-4 mb-0 small">Já tem uma conta? <a href="{{ route('login') }}">Entrar</a></p>
            </div>
        </section>
    </div>
</div>
@endsection

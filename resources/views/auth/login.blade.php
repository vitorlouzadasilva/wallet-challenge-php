@extends('layouts.app')
@section('title', 'Entrar')
@section('content')
<div class="container page-spacing">
    <div class="row g-5 align-items-center justify-content-between">
        <section class="col-lg-5 auth-intro" aria-labelledby="login-heading">
            <p class="eyebrow">BOM TER VOCÊ AQUI</p>
            <h1 id="login-heading" class="display-heading">Sua carteira,<br>mais perto.</h1>
            <p class="lead text-secondary mt-4">Acesse sua conta para consultar o seu saldo.</p>
            <div class="intro-note mt-4">Um espaço individual, com acesso pelos seus dados.</div>
        </section>
        <section class="col-lg-6" aria-labelledby="login-form-heading">
            <div class="surface form-surface">
                <h2 id="login-form-heading" class="h4 fw-bold mb-2">Entre na sua conta</h2>
                <p class="text-secondary mb-4">Informe seu e-mail e sua senha.</p>
                <form method="POST" action="{{ route('login.store') }}">
                    @csrf
                    <div class="mb-3">
                        <label for="email" class="form-label">E-mail</label>
                        <input type="email" class="form-control @error('email') is-invalid @enderror" id="email" name="email"
                               value="{{ is_string(old('email')) ? old('email') : '' }}" autocomplete="username" maxlength="255" required autofocus
                               @error('email') aria-invalid="true" aria-describedby="email-error" @enderror>
                        @error('email')<div id="email-error" class="invalid-feedback" role="alert">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-4">
                        <label for="password" class="form-label">Senha</label>
                        <input type="password" class="form-control @error('password') is-invalid @enderror" id="password" name="password"
                               autocomplete="current-password" required
                               @error('password') aria-invalid="true" aria-describedby="password-error" @enderror>
                        @error('password')<div id="password-error" class="invalid-feedback" role="alert">{{ $message }}</div>@enderror
                    </div>
                    <button type="submit" class="btn btn-primary w-100">Entrar</button>
                </form>
                <p class="text-center text-secondary mt-4 mb-0 small">Ainda não tem uma conta? <a href="{{ route('register') }}">Criar conta</a></p>
            </div>
        </section>
    </div>
</div>
@endsection

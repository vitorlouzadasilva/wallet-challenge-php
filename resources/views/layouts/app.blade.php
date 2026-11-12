<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="description" content="Sua carteira digital, com uma visão simples e clara do seu saldo.">
    <title>@yield('title', 'Wallet PHP') | Grupo Adriano Cobuccio </title>
    @vite('resources/js/app.js')
</head>
<body>
<a class="visually-hidden-focusable skip-link" href="#main-content">Ir para o conteúdo</a>
<header class="site-header">
    <nav class="navbar navbar-expand-sm container py-3" aria-label="Navegação principal">
        <a class="navbar-brand brand" href="{{ route('home') }}">
            <span class="brand-mark" aria-hidden="true">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7">
                    <path d="M4 6.5V6a2 2 0 0 1 2-2h12v4M4 7h15a1 1 0 0 1 1 1v11a1 1 0 0 1-1 1H6a2 2 0 0 1-2-2V7Z"/>
                    <path d="M20 11h-5v5h5M17 13.5h.01"/>
                </svg>
            </span>
            Wallet<span class="brand-caption">carteira digital</span>
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNavigation"
                aria-controls="mainNavigation" aria-expanded="false" aria-label="Abrir navegação">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="mainNavigation">
            <ul class="navbar-nav ms-auto align-items-sm-center gap-sm-3 pt-3 pt-sm-0">
                <li class="nav-item"><a class="nav-link" href="{{ route('home') }}" @if(request()->routeIs('home')) aria-current="page" @endif>Início</a></li>
                @auth
                    <li class="nav-item"><a class="nav-link" href="{{ route('dashboard') }}" @if(request()->routeIs('dashboard')) aria-current="page" @endif>Minha carteira</a></li>
                    <li class="nav-item">
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="btn btn-outline-primary btn-sm px-4">Sair</button>
                        </form>
                    </li>
                @else
                    <li class="nav-item"><a class="nav-link" href="{{ route('login') }}" @if(request()->routeIs('login')) aria-current="page" @endif>Entrar</a></li>
                    <li class="nav-item"><a class="btn btn-primary btn-sm px-4" href="{{ route('register') }}">Criar conta</a></li>
                @endauth
            </ul>
        </div>
    </nav>
</header>
<main id="main-content" class="main-content">
    @if(session('status'))
        <div class="container pt-4"><div class="alert alert-success mb-0" role="status">{{ session('status') }}</div></div>
    @endif
    @yield('content')
</main>
<footer class="site-footer">
    <div class="container d-flex flex-wrap justify-content-between gap-2 py-4">
        <span class="fw-semibold">Wallet</span>
        <span>Uma visão clara da sua carteira.</span>
    </div>
</footer>
</body>
</html>

<?php

namespace App\Http\Controllers;

use App\Support\BrazilianCurrency;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        // A relação parte da sessão; IDs enviados na requisição nunca selecionam a carteira.
        $wallet = $request->user()->wallet()->first();

        return view('dashboard', [
            'user' => $request->user(),
            'wallet' => $wallet,
            'formattedBalance' => $wallet === null ? null : BrazilianCurrency::format($wallet->balance_cents),
        ]);
    }
}

<?php

namespace App\Http\Controllers;

use App\Support\BrazilianCurrency;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        // A relação parte da sessão; IDs enviados na requisição nunca selecionam a carteira.
        $wallet = $request->user()->wallet()->first();
        $entries = $wallet?->ledgerEntries()
            ->with([
                'operation.originalOperation',
                'operation.sourceWallet:id,user_id',
                'operation.sourceWallet.user:id,name',
                'operation.destinationWallet:id,user_id',
                'operation.destinationWallet.user:id,name',
            ])
            ->orderByDesc('id')
            ->paginate(10);

        return view('dashboard', [
            'user' => $request->user(),
            'wallet' => $wallet,
            'formattedBalance' => $wallet === null ? null : BrazilianCurrency::format($wallet->balance_cents),
            'entries' => $entries,
            // Um novo GET sem erro representa formulários novos; old() preserva a chave em falhas.
            'depositKey' => (string) Str::uuid(),
            'transferKey' => (string) Str::uuid(),
        ]);
    }
}

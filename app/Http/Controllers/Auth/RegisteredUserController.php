<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Services\RegisterUser;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class RegisteredUserController extends Controller
{
    public function create(): View
    {
        return view('auth.register');
    }

    public function store(RegisterRequest $request, RegisterUser $registerUser): RedirectResponse
    {
        $data = $request->validated();

        try {
            $user = $registerUser->create($data['name'], $data['email'], $data['password']);
        } catch (QueryException) {
            // Não expor SQL, dados pessoais ou hashes de senha ao tratar falha transacional.
            throw ValidationException::withMessages([
                'registration' => 'Não foi possível criar sua conta. Tente novamente.',
            ]);
        }

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('dashboard');
    }
}

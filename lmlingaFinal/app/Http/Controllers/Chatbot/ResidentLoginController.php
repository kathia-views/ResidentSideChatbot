<?php

namespace App\Http\Controllers\Chatbot;

use App\Http\Controllers\Controller;
use App\Models\ResidentAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class ResidentLoginController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $account = ResidentAccount::where('email', $validated['email'])->first();

        if (! $account || ! Hash::check($validated['password'], $account->password)) {
            return back()
                ->withErrors([
                    'email' => 'The provided credentials do not match our records.',
                ])
                ->onlyInput('email');
        }

        $request->session()->regenerate();

        $request->session()->put('resident_account_id', $account->account_id);

        return redirect()->route('chatbot.main');
    }

    public function destroy(Request $request)
    {
        $request->session()->forget('resident_account_id');
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('chatbot.landing');
    }
}
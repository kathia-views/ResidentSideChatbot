<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Support\DemoStaffLogin;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * UI-phase demo login. Sets UiRole session only — not production auth.
 */
class DemoLoginController extends Controller
{
    public function show(): View
    {
        return view('pages.auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $identity = trim((string) $request->input('email', $request->input('full_name', '')));
        $password = (string) $request->input('password', '');

        $account = DemoStaffLogin::attempt($identity, $password);

        if ($account === null) {
            return redirect()
                ->route('login')
                ->withInput($request->except('password'))
                ->withErrors([
                    'email' => 'Invalid email or password. Please try again.',
                ]);
        }

        DemoStaffLogin::establishSession($account);

        return redirect()->route('dashboard');
    }
}

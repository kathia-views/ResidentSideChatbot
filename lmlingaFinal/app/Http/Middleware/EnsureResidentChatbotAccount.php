<?php

namespace App\Http\Middleware;

use App\Models\ResidentAccount;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureResidentChatbotAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        $accountId = $request->session()->get('resident_account_id');

        if ($accountId === null || $accountId === '') {
            return redirect()->route('chatbot.login');
        }

        $account = ResidentAccount::query()->find($accountId);

        if ($account === null) {
            $request->session()->forget('resident_account_id');

            return redirect()->route('chatbot.login');
        }

        $request->attributes->set('residentAccount', $account);

        return $next($request);
    }
}

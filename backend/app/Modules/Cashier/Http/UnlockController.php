<?php

namespace App\Modules\Cashier\Http;

use App\Modules\Access\Services\Governance;
use App\Modules\Cashier\Services\CashierPin;
use Illuminate\Http\Request;

final class UnlockController
{
    public function __invoke(Request $request, CashierPin $pins)
    {
        // PIN belongs in the POST body, and identity comes exclusively from the session.
        abort_unless($request->query() === [] && array_diff(array_keys($request->all()), ['cashier_pin']) === [], 422);
        $data = $request->validate(['cashier_pin' => ['required', 'string', 'regex:/\A[0-9]{4}\z/D']]);
        $tenant = $request->session()->get('active_tenant_id');
        abort_unless(is_string($tenant), 409, 'Select an active clinic.');
        if (! $pins->verify($request->user(), Governance::uuid($tenant), $data['cashier_pin'], (string) $request->ip())) {
            return response()->json(['message' => 'PIN noto‘g‘ri yoki hali sozlanmagan.', 'code' => 'invalid_pin'], 422);
        }

        // This verifies entry into the existing demo UI; it is not a payment/shift authorization token.
        return response()->noContent();
    }
}

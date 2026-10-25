<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Checkout\UpdateDeliveryCharge;
use App\Http\Controllers\Controller;
use App\Models\EshopSetting;
use App\Support\Money;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * The Sri Lanka delivery charge. Saving requires an explicit confirmation
 * tick so a value (including zero) is only ever set deliberately.
 */
class EshopSettingsController extends Controller
{
    public function edit(): View
    {
        $settings = EshopSetting::current();
        Gate::authorize('update', $settings);

        return view('admin.eshop-settings.edit', [
            'settings' => $settings,
            'history' => DB::table('audit_logs')
                ->leftJoin('users', 'users.id', '=', 'audit_logs.user_id')
                ->where('subject_type', 'eshop_setting')
                ->orderByDesc('audit_logs.id')
                ->limit(20)
                ->get(['audit_logs.old_values', 'audit_logs.new_values', 'audit_logs.created_at', 'users.name as actor_name']),
        ]);
    }

    public function update(Request $request, UpdateDeliveryCharge $updateDeliveryCharge): RedirectResponse
    {
        Gate::authorize('update', EshopSetting::current());

        $validated = $request->validate([
            'delivery_charge' => ['required', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_string($value) || ! Money::isValid($value)) {
                    $fail('Enter the delivery charge in LKR as a number with up to two decimals, for example 350 or 350.00.');
                }
            }],
            'confirm' => ['accepted'],
        ], [
            'confirm.accepted' => 'Tick the box to confirm this is the approved delivery charge.',
        ]);

        $updateDeliveryCharge->handle($request->user(), $validated['delivery_charge']);

        return redirect()->route('admin.eshop-settings.edit')->with('status', 'Delivery charge saved. New orders will include it; existing orders are unchanged.');
    }
}

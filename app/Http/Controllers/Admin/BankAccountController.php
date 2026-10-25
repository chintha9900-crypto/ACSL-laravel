<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Payments\ManageBankAccounts;
use App\Http\Controllers\Controller;
use App\Models\PaymentBankAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Receiving bank accounts shown to customers for bank transfers (membership
 * renewals and e-shop). Details are immutable once saved — replacing an
 * account means adding a new one and activating it (ManageBankAccounts).
 */
class BankAccountController extends Controller
{
    public function index(): View
    {
        Gate::authorize('viewAny', PaymentBankAccount::class);

        return view('admin.bank-accounts.index', [
            'accounts' => PaymentBankAccount::query()->orderByDesc('is_active')->orderByDesc('id')->get(),
            'history' => DB::table('audit_logs')
                ->leftJoin('users', 'users.id', '=', 'audit_logs.user_id')
                ->where('subject_type', 'payment_bank_account')
                ->orderByDesc('audit_logs.id')
                ->limit(30)
                ->get(['audit_logs.event', 'audit_logs.subject_id', 'audit_logs.new_values', 'audit_logs.created_at', 'users.name as actor_name']),
        ]);
    }

    public function create(): View
    {
        Gate::authorize('create', PaymentBankAccount::class);

        return view('admin.bank-accounts.create');
    }

    public function store(Request $request, ManageBankAccounts $manage): RedirectResponse
    {
        Gate::authorize('create', PaymentBankAccount::class);

        $validated = $request->validate([
            'label' => ['required', 'string', 'max:100'],
            'bank_name' => ['required', 'string', 'max:150'],
            'account_name' => ['required', 'string', 'max:150'],
            'account_number' => ['required', 'string', 'max:50', 'regex:/^[0-9A-Za-z -]+$/'],
            'branch' => ['nullable', 'string', 'max:150'],
            'swift_bic' => ['nullable', 'string', 'regex:/^[A-Za-z0-9]{8}([A-Za-z0-9]{3})?$/'],
            'iban' => ['nullable', 'string', 'max:34', 'regex:/^[A-Za-z0-9 ]+$/'],
            'currency' => ['required', 'string', 'in:LKR'],
            'instructions' => ['nullable', 'string', 'max:1000'],
            'activate' => ['nullable', 'boolean'],
        ]);

        $activate = (bool) ($validated['activate'] ?? false);
        unset($validated['activate']);

        $manage->create($request->user(), $validated, $activate);

        return redirect()->route('admin.bank-accounts.index')->with('status', $activate
            ? 'Bank account added and set as the active receiving account.'
            : 'Bank account added. It is not shown to customers until you activate it.');
    }

    public function activate(Request $request, PaymentBankAccount $bankAccount, ManageBankAccounts $manage): RedirectResponse
    {
        Gate::authorize('update', $bankAccount);

        $manage->activate($bankAccount, $request->user());

        return redirect()->route('admin.bank-accounts.index')->with('status', 'Account activated. Customers now see this account; the previous one was deactivated.');
    }

    public function deactivate(Request $request, PaymentBankAccount $bankAccount, ManageBankAccounts $manage): RedirectResponse
    {
        Gate::authorize('update', $bankAccount);

        $validated = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        $manage->deactivate($bankAccount, $request->user(), $validated['reason']);

        return redirect()->route('admin.bank-accounts.index')->with('status', 'Account deactivated. No bank account is shown to customers until another is activated.');
    }
}

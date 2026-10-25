<?php

namespace App\Actions\Payments;

use App\Actions\Orders\RecordOrderAudit;
use App\Models\PaymentBankAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Receiving bank accounts for bank transfers (membership renewals and the
 * e-shop share the one active account per currency; their approval logic
 * stays separate).
 *
 * Account details are never edited in place: a change is a new account,
 * activated in place of the old one, so every past transfer stays linked to
 * the details it was actually made to. Activation is atomic — the previous
 * active account for the currency is deactivated in the same transaction.
 * Every change is audited with the responsible administrator; account
 * numbers are masked in the audit trail.
 */
class ManageBankAccounts
{
    public function __construct(private readonly RecordOrderAudit $audit) {}

    /**
     * @param  array{label: string, bank_name: string, account_name: string, account_number: string, branch?: ?string, swift_bic?: ?string, iban?: ?string, currency: string, instructions?: ?string}  $details
     */
    public function create(User $admin, array $details, bool $activate): PaymentBankAccount
    {
        return DB::transaction(function () use ($admin, $details, $activate): PaymentBankAccount {
            $account = new PaymentBankAccount;
            $account->forceFill([
                ...$details,
                'is_active' => false,
                'created_by_user_id' => $admin->id,
                'updated_by_user_id' => $admin->id,
            ])->save();

            $this->audit->handle($account, 'bank_account.created', $admin, $this->summary($account));

            if ($activate) {
                $this->activateLocked($account, $admin);
            }

            return $account->refresh();
        }, 3);
    }

    public function activate(PaymentBankAccount $account, User $admin): PaymentBankAccount
    {
        return DB::transaction(fn (): PaymentBankAccount => $this->activateLocked($account, $admin), 3);
    }

    public function deactivate(PaymentBankAccount $account, User $admin, string $reason): PaymentBankAccount
    {
        return DB::transaction(function () use ($account, $admin, $reason): PaymentBankAccount {
            $locked = PaymentBankAccount::query()->lockForUpdate()->findOrFail($account->id);

            if (! $locked->is_active) {
                return $locked;
            }

            $locked->forceFill(['is_active' => false, 'updated_by_user_id' => $admin->id])->save();
            $this->audit->handle($locked, 'bank_account.deactivated', $admin, ['reason' => $reason] + $this->summary($locked));

            return $locked;
        }, 3);
    }

    private function activateLocked(PaymentBankAccount $account, User $admin): PaymentBankAccount
    {
        $sameCurrency = PaymentBankAccount::query()
            ->where('currency', $account->currency)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($sameCurrency as $other) {
            if ($other->id !== $account->id && $other->is_active) {
                $other->forceFill(['is_active' => false, 'updated_by_user_id' => $admin->id])->save();
                $this->audit->handle($other, 'bank_account.deactivated', $admin, ['reason' => 'Replaced by account #'.$account->id] + $this->summary($other));
            }
        }

        $locked = $sameCurrency->firstWhere('id', $account->id) ?? PaymentBankAccount::query()->lockForUpdate()->findOrFail($account->id);

        if (! $locked->is_active) {
            $locked->forceFill(['is_active' => true, 'updated_by_user_id' => $admin->id])->save();
            $this->audit->handle($locked, 'bank_account.activated', $admin, $this->summary($locked));
        }

        return $locked;
    }

    /**
     * @return array<string, string>
     */
    private function summary(PaymentBankAccount $account): array
    {
        return [
            'label' => $account->label,
            'bank_name' => $account->bank_name,
            'currency' => $account->currency,
            'account_number' => '…'.substr($account->account_number, -4),
        ];
    }
}

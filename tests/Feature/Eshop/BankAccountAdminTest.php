<?php

namespace Tests\Feature\Eshop;

use App\Actions\Membership\StartRenewal;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentBankAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\Concerns\CreatesEshopOrders;
use Tests\Concerns\CreatesRenewals;
use Tests\Concerns\CreatesReviewableApplications;
use Tests\Concerns\CreatesUploads;
use Tests\MysqlTestCase;

class BankAccountAdminTest extends MysqlTestCase
{
    use CreatesEshopOrders;
    use CreatesRenewals;
    use CreatesReviewableApplications;
    use CreatesUploads;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Storage::fake('private');
        $this->configureDeliveryCharge();
    }

    /**
     * @return array<string, string>
     */
    private function accountDetails(array $overrides = []): array
    {
        return [
            'label' => 'Main LKR',
            'bank_name' => 'Example Bank',
            'account_name' => 'Aviation Club',
            'account_number' => '1112223334',
            'branch' => 'Colombo 03',
            'currency' => 'LKR',
            'activate' => '1',
            ...$overrides,
        ];
    }

    public function test_an_admin_adds_and_activates_an_account_with_an_audit_trail(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('admin.bank-accounts.index'))->assertOk()->assertSee('No active LKR account');
        $this->actingAs($admin)->post(route('admin.bank-accounts.store'), $this->accountDetails())->assertRedirect(route('admin.bank-accounts.index'));

        $account = PaymentBankAccount::query()->sole();
        $this->assertTrue($account->is_active);
        $this->assertSame($admin->id, $account->created_by_user_id);
        $this->assertDatabaseHas('audit_logs', ['event' => 'bank_account.created', 'subject_type' => 'payment_bank_account', 'subject_id' => $account->id, 'user_id' => $admin->id]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'bank_account.activated', 'subject_id' => $account->id]);
        $this->assertStringNotContainsString('1112223334', (string) DB::table('audit_logs')->where('subject_type', 'payment_bank_account')->pluck('new_values')->implode(' '), 'Account numbers are masked in the audit trail.');
    }

    public function test_activating_a_new_account_atomically_replaces_the_old_one(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('admin.bank-accounts.store'), $this->accountDetails());
        $old = PaymentBankAccount::query()->sole();

        $this->actingAs($admin)->post(route('admin.bank-accounts.store'), $this->accountDetails(['label' => 'New', 'account_number' => '5556667778', 'activate' => '0']));
        $new = PaymentBankAccount::query()->where('label', 'New')->sole();
        $this->assertFalse($new->is_active, 'Not shown to customers until activated.');

        $this->actingAs($admin)->post(route('admin.bank-accounts.activate', $new))->assertRedirect();

        $this->assertFalse($old->fresh()->is_active);
        $this->assertTrue($new->fresh()->is_active);
        $this->assertSame(1, PaymentBankAccount::query()->where('currency', 'LKR')->where('is_active', true)->count());
    }

    public function test_a_deactivated_account_is_never_shown_to_customers_and_history_is_kept(): void
    {
        $account = $this->eshopBankAccount();
        $order = $this->placeEshopOrder($this->stockedProduct());
        $payment = Payment::query()->where('order_id', $order->id)->sole();
        $this->assertSame($account->id, $payment->bank_account_id);

        $this->actingAs($this->admin())->post(route('admin.bank-accounts.deactivate', $account), ['reason' => 'Account compromised'])->assertRedirect();
        auth()->logout();

        $this->get(URL::signedRoute('payment.show', $order))
            ->assertDontSee('9876543210')
            ->assertSee('Bank details are not available');
        $this->assertSame($account->id, $payment->fresh()->bank_account_id, 'The historical link is kept for audit.');
        $this->assertDatabaseHas('audit_logs', ['event' => 'bank_account.deactivated', 'subject_id' => $account->id]);
    }

    public function test_customers_see_the_replacement_account_and_their_transfer_links_to_it(): void
    {
        $old = $this->eshopBankAccount();
        $order = $this->placeEshopOrder($this->stockedProduct());
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('admin.bank-accounts.store'), $this->accountDetails(['account_number' => '5556667778']));
        auth()->logout();

        $this->get(URL::signedRoute('payment.show', $order))->assertSee('5556667778')->assertDontSee('9876543210');

        $this->post(URL::signedRoute('payment.bank-transfer', $order), ['reference' => 'TRX', 'evidence' => [$this->pdfUpload()]])->assertSessionHasNoErrors();

        $new = PaymentBankAccount::query()->where('account_number', '5556667778')->sole();
        $this->assertSame($new->id, Payment::query()->where('order_id', $order->id)->sole()->bank_account_id);
        $this->assertFalse($old->fresh()->is_active);
    }

    public function test_a_transfer_cannot_be_submitted_without_an_active_account(): void
    {
        $account = $this->eshopBankAccount();
        $order = $this->placeEshopOrder($this->stockedProduct());
        $account->forceFill(['is_active' => false])->save();

        $this->post(URL::signedRoute('payment.bank-transfer', $order), ['reference' => 'TRX', 'evidence' => [$this->pdfUpload()]])
            ->assertSessionHasErrors('payment');

        $this->assertSame(Payment::STATUS_PENDING, Payment::query()->where('order_id', $order->id)->sole()->status);
        $this->assertSame(Order::STATUS_PENDING_PAYMENT, $order->fresh()->status);
    }

    public function test_the_membership_page_never_shows_a_deactivated_account(): void
    {
        $membership = $this->renewableMembership();
        $term = app(StartRenewal::class)->handle($membership);
        $account = $term->payment->bankAccount;
        $account->forceFill(['is_active' => false])->save();

        $this->actingAs($membership->user)->get(route('member.membership.show'))
            ->assertOk()
            ->assertDontSee($account->account_number)
            ->assertSee('Bank details are not available');
    }

    public function test_only_settings_managers_can_manage_accounts(): void
    {
        $account = $this->eshopBankAccount();

        foreach ([$this->editor(), $this->member(), $this->dev()] as $user) {
            $this->actingAs($user)->get(route('admin.bank-accounts.index'))->assertForbidden();
            $this->actingAs($user)->post(route('admin.bank-accounts.store'), $this->accountDetails())->assertForbidden();
            $this->actingAs($user)->post(route('admin.bank-accounts.deactivate', $account), ['reason' => 'x'])->assertForbidden();
        }

        $this->assertTrue($account->fresh()->is_active);
        $this->assertSame(1, PaymentBankAccount::query()->count());
    }

    public function test_account_details_are_validated(): void
    {
        $this->actingAs($this->admin())->post(route('admin.bank-accounts.store'), $this->accountDetails([
            'account_number' => '<script>',
            'swift_bic' => 'BAD',
            'currency' => 'USD',
            'bank_name' => '',
        ]))->assertSessionHasErrors(['account_number', 'swift_bic', 'currency', 'bank_name']);

        $this->assertSame(0, PaymentBankAccount::query()->count());
    }
}

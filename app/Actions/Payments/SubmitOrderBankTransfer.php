<?php

namespace App\Actions\Payments;

use App\Actions\Orders\RecordOrderAudit;
use App\Exceptions\PaymentCannotBeSubmittedException;
use App\Models\Document;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentBankAccount;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * The customer's bank-transfer reference and evidence for an e-shop order.
 * Moves the attempt to `processing` (awaiting admin verification). Allowed
 * from `pending`, or from `failed` after a rejection — the same payment row
 * is resubmitted, and earlier evidence and the audit trail are kept.
 *
 * Refused unless the order is still payable and an active receiving account
 * exists; the attempt is linked to that account (the one shown to the
 * customer), preserving which account the transfer was made to. Evidence is
 * stored privately (StorePaymentEvidence). Lock order: order → payment.
 */
class SubmitOrderBankTransfer
{
    public function __construct(
        private readonly StorePaymentEvidence $storeEvidence,
        private readonly RecordOrderAudit $audit,
    ) {}

    /**
     * @param  list<UploadedFile>  $evidence
     *
     * @throws PaymentCannotBeSubmittedException
     */
    public function handle(Payment $payment, ?User $customer, string $reference, array $evidence): Payment
    {
        $storedPaths = [];

        try {
            return DB::transaction(function () use ($payment, $customer, $reference, $evidence, &$storedPaths): Payment {
                $orderId = Payment::query()->whereKey($payment->id)->value('order_id');
                $order = $orderId === null ? null : Order::query()->lockForUpdate()->find($orderId);
                $locked = Payment::query()->lockForUpdate()->find($payment->id);

                if ($order === null || $locked === null || $locked->gateway !== Payment::GATEWAY_MANUAL_BANK_TRANSFER) {
                    throw new PaymentCannotBeSubmittedException('This payment could not be found.');
                }

                if (! in_array($locked->status, [Payment::STATUS_PENDING, Payment::STATUS_FAILED], true)) {
                    throw new PaymentCannotBeSubmittedException('This payment is not currently awaiting transfer details.');
                }

                if (! $order->isPayable()) {
                    throw new PaymentCannotBeSubmittedException('This order is no longer awaiting payment.');
                }

                $account = PaymentBankAccount::query()->activeForCurrency($locked->currency)->first();

                if ($account === null) {
                    throw new PaymentCannotBeSubmittedException('Bank transfer details are not available at the moment. Please contact us before transferring.');
                }

                foreach ($evidence as $file) {
                    $this->storeEvidence->handle($locked, $customer, $file, $storedPaths);
                }

                $resubmission = $locked->status === Payment::STATUS_FAILED;

                $locked->forceFill([
                    'transaction_reference' => $reference,
                    'bank_account_id' => $account->id,
                    'status' => Payment::STATUS_PROCESSING,
                    'submitted_at' => now(),
                ])->save();

                $this->audit->handle($locked, $resubmission ? 'payment.evidence_resubmitted' : 'payment.evidence_submitted', $customer, [
                    'transaction_reference' => $reference,
                    'files' => count($evidence),
                    'bank_account_id' => $account->id,
                ]);

                return $locked->refresh();
            }); // No automatic retry: files are written inside; a failure removes them below.
        } catch (Throwable $exception) {
            foreach ($storedPaths as $path) {
                Storage::disk(Document::DISK_PRIVATE)->delete($path);
            }

            throw $exception;
        }
    }
}

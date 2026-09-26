<?php

namespace App\Http\Controllers\Member;

use App\Actions\Membership\DeliverMemberNotification;
use App\Actions\Membership\StartRenewal;
use App\Actions\Payments\SubmitPaymentEvidence;
use App\Exceptions\PaymentCannotBeSubmittedException;
use App\Exceptions\RenewalCannotBeStartedException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Member\SubmitPaymentEvidenceRequest;
use App\Models\Membership;
use App\Notifications\Membership\RenewalPaymentInstructions;
use App\Notifications\Payments\ConfirmationSubmitted;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class RenewalController extends Controller
{
    /**
     * Start a renewal for the signed-in member's own membership. Ownership
     * comes only from the authenticated user's id; the request accepts no
     * membership id at all.
     */
    public function store(Request $request, StartRenewal $startRenewal, DeliverMemberNotification $deliver): RedirectResponse
    {
        $membership = $this->ownMembership($request);

        try {
            $term = $startRenewal->handle($membership);
        } catch (RenewalCannotBeStartedException $exception) {
            return redirect()
                ->route('member.membership.show')
                ->withErrors(['renewal' => $exception->getMessage()]);
        }

        $deliver->handle($request->user(), new RenewalPaymentInstructions($term->load(['payment.bankAccount', 'membership'])));

        return redirect()
            ->route('member.membership.show')
            ->with('status', 'Your renewal has started. Payment instructions have been emailed to you.');
    }

    /**
     * Submit bank-transfer reference and evidence for the signed-in member's
     * own pending renewal. Ownership comes only from the authenticated user's
     * id — the payment is looked up as "my membership's pending renewal
     * payment", never by an id from the request.
     */
    public function submitEvidence(SubmitPaymentEvidenceRequest $request, SubmitPaymentEvidence $submit, DeliverMemberNotification $deliver): RedirectResponse
    {
        $membership = $this->ownMembership($request);
        $term = $membership->terms()->where('status', 'pending_payment')->with('payment')->first();

        if ($term === null || $term->payment === null) {
            return redirect()
                ->route('member.membership.show')
                ->withErrors(['evidence' => 'There is no renewal awaiting payment.']);
        }

        try {
            $payment = $submit->handle($term->payment, $request->user(), $request->validated('reference'), $request->file('evidence'));
        } catch (PaymentCannotBeSubmittedException $exception) {
            return redirect()
                ->route('member.membership.show')
                ->withErrors(['evidence' => $exception->getMessage()]);
        }

        $deliver->handle($request->user(), new ConfirmationSubmitted($payment));

        return redirect()
            ->route('member.membership.show')
            ->with('status', 'Your payment evidence has been submitted for review.');
    }

    private function ownMembership(Request $request): Membership
    {
        $membership = Membership::query()->where('user_id', $request->user()->id)->firstOrFail();

        return $membership;
    }
}

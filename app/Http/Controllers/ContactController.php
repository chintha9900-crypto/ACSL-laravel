<?php

namespace App\Http\Controllers;

use App\Actions\Contact\StoreContactEnquiry;
use App\Actions\Contact\VerifyRecaptchaToken;
use App\Http\Requests\StoreContactEnquiryRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class ContactController extends Controller
{
    public function show(): View
    {
        return view('contact');
    }

    /**
     * Validates the form, verifies the reCAPTCHA token server-side, then
     * persists the enquiry as `new`. The throttle on this route
     * (`throttle:6,1`) is unchanged. No email is sent here.
     */
    public function store(StoreContactEnquiryRequest $request, VerifyRecaptchaToken $verifyRecaptcha, StoreContactEnquiry $storeEnquiry): RedirectResponse
    {
        if (! $verifyRecaptcha->handle($request->input('g-recaptcha-response'), $request->ip())) {
            return back()
                ->withErrors(['g-recaptcha-response' => 'The reCAPTCHA check could not be verified. Please complete it and try again.'])
                ->withInput($request->except('g-recaptcha-response'));
        }

        $storeEnquiry->handle($request->safe()->only(['name', 'email', 'phone', 'subject', 'message']));

        return redirect()->route('contact.submitted');
    }

    public function submitted(): View
    {
        return view('contact-submitted');
    }
}

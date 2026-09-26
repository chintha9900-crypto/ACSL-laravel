<?php

namespace App\Http\Controllers;

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
     * Validates the General Inquiry form and shows a confirmation. Nothing is
     * stored or emailed yet: this project has no approved destination for a
     * public enquiry — no `contact_enquiries` table/model exists, and no
     * confirmed admin recipient address exists either (see contact.blade.php
     * for why no contact details are invented). Wiring an actual
     * storage/delivery mechanism is a separate, approved task; for now the
     * message is validated and the applicant sees a clean success page.
     */
    public function store(StoreContactEnquiryRequest $request): RedirectResponse
    {
        return redirect()->route('contact.submitted');
    }

    public function submitted(): View
    {
        return view('contact-submitted');
    }
}

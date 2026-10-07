<?php

namespace App\Actions\Contact;

use App\Models\ContactEnquiry;

/**
 * Persists a validated Contact form submission. Every enquiry starts as
 * `new`; nothing here sends email or changes status afterwards — admin
 * handling and the admin alert are separate, later phases.
 */
class StoreContactEnquiry
{
    /**
     * @param  array<string, mixed>  $data  Validated: name, email, phone, subject, message.
     */
    public function handle(array $data): ContactEnquiry
    {
        $data['status'] = ContactEnquiry::STATUS_NEW;

        return ContactEnquiry::create($data);
    }
}

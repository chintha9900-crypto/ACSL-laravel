<?php

namespace App\Models;

use Database\Factories\ContactEnquiryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A public Contact form submission (docs/database/10 §13). Written only by
 * `Actions\Contact\StoreContactEnquiry` from validated form input.
 */
class ContactEnquiry extends Model
{
    /** @use HasFactory<ContactEnquiryFactory> */
    use HasFactory;

    public const STATUS_NEW = 'new';

    public const STATUS_REPLIED = 'replied';

    public const STATUS_CLOSED = 'closed';

    /**
     * @var list<string>
     */
    public const STATUSES = [self::STATUS_NEW, self::STATUS_REPLIED, self::STATUS_CLOSED];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'phone',
        'subject',
        'message',
        'status',
    ];
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Metadata for a private file (docs/database/07 §3). The bytes live on the
 * `private` disk; the storage location is never exposed.
 */
class Document extends Model
{
    use HasUlids;

    public const KIND_AVIATION_PROOF = 'aviation_proof';

    public const DISK_PRIVATE = 'private';

    /**
     * Written only by server-side actions, never from request input.
     *
     * @var list<string>
     */
    protected $fillable = [
        'kind',
        'membership_application_id',
        'uploaded_by_user_id',
        'disk',
        'storage_path',
        'original_filename',
        'mime_type',
        'size_bytes',
        'checksum_sha256',
    ];

    /**
     * The storage location is internal and must never be serialised.
     *
     * @var list<string>
     */
    protected $hidden = [
        'disk',
        'storage_path',
    ];

    /**
     * The public identifier is the ULID column; the auto-increment `id` stays internal.
     *
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /**
     * @return BelongsTo<MembershipApplication, $this>
     */
    public function application(): BelongsTo
    {
        return $this->belongsTo(MembershipApplication::class, 'membership_application_id');
    }
}

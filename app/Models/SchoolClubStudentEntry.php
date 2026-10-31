<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One student row of a School Club student batch. `membership_id` is set
 * only when the batch is approved and the student's membership is created.
 */
class SchoolClubStudentEntry extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
        ];
    }

    /**
     * @return BelongsTo<SchoolClubStudentBatch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(SchoolClubStudentBatch::class, 'school_club_student_batch_id');
    }

    /**
     * @return BelongsTo<Membership, $this>
     */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(Membership::class);
    }
}

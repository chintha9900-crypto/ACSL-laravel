<?php

namespace App\Notifications\Membership;

use App\Models\SchoolClubStudentBatch;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to the School Club representative when an admin approves, returns
 * (more details) or declines a student batch. Carries the applicant-facing
 * request message only — never the admin's internal decision note.
 */
class StudentBatchReviewed extends Notification
{
    /**
     * @var array<string, string>
     */
    private const SUBJECTS = [
        SchoolClubStudentBatch::STATUS_APPROVED => 'Your School Club student members have been approved',
        SchoolClubStudentBatch::STATUS_MORE_DETAILS_REQUIRED => 'More information needed for your School Club student list',
        SchoolClubStudentBatch::STATUS_REJECTED => 'Your School Club student list',
    ];

    public function __construct(private readonly SchoolClubStudentBatch $batch) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(self::SUBJECTS[$this->batch->status])
            ->view(
                ['emails.membership.student-batch-reviewed', 'emails.membership.student-batch-reviewed-text'],
                [
                    'name' => $notifiable->name,
                    'status' => $this->batch->status,
                    'count' => $this->batch->students_count,
                    'submittedOn' => $this->batch->submitted_at->format('j F Y'),
                    'requestMessage' => $this->batch->request_message,
                    'url' => route('member.school-club.students.index'),
                ],
            );
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => self::SUBJECTS[$this->batch->status],
            'message' => match ($this->batch->status) {
                SchoolClubStudentBatch::STATUS_APPROVED => $this->batch->students_count.' student member(s) approved. Each student receives their own account setup link.',
                SchoolClubStudentBatch::STATUS_MORE_DETAILS_REQUIRED => 'Please review the request and resubmit your student list.',
                default => 'Your student list was not approved.',
            },
            'action_url' => route('member.school-club.students.index'),
            'related_public_id' => $this->batch->public_id,
        ];
    }
}

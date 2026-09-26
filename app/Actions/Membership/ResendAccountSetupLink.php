<?php

namespace App\Actions\Membership;

use App\Actions\Auth\IssueAccountSetupToken;
use App\Exceptions\SetupLinkCannotBeResentException;
use App\Models\AccountSetupToken;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Str;

class ResendAccountSetupLink
{
    public function __construct(
        private IssueAccountSetupToken $issueSetupToken,
        private DeliverAccountSetupLink $deliver,
    ) {}

    /**
     * Issue a fresh one-time setup token (superseding any live one) and email it.
     *
     * Only for a member still pending setup. Issuing and auditing commit first; the
     * email is sent afterwards. Returns whether the email was handed to the mailer.
     * The token is never returned, audited or shown to the admin.
     *
     * @throws SetupLinkCannotBeResentException
     */
    public function handle(Membership $membership, User $admin): bool
    {
        try {
            [$user, $token] = DB::transaction(function () use ($membership, $admin): array {
                // Read inside the transaction: the member may have finished setup meanwhile.
                $user = User::query()->find($membership->user_id);

                if ($user === null || $user->status !== 'pending_setup') {
                    throw new SetupLinkCannotBeResentException('A setup link can only be sent while the member\'s account is waiting for setup.');
                }

                $token = $this->issueSetupToken->handle($user, $membership->id, $admin);

                DB::table('audit_logs')->insert([
                    'user_id' => $admin->id,
                    'actor_type' => 'user',
                    'event' => 'membership.setup_link_resent',
                    'subject_type' => 'membership',
                    'subject_id' => $membership->id,
                    'new_values' => json_encode([
                        'user_id' => $user->id,
                        'token_expires_at' => AccountSetupToken::query()
                            ->where('token_hash', AccountSetupToken::hashFor($token))
                            ->value('expires_at')?->toIso8601String(),
                    ]),
                    'ip_address' => Request::ip(),
                    'user_agent' => Str::limit((string) Request::userAgent(), 512, ''),
                    'created_at' => now(),
                ]);

                return [$user, $token];
            });
        } catch (UniqueConstraintViolationException) {
            // Two resends raced for the single live-token slot; the other one won.
            throw new SetupLinkCannotBeResentException('A setup link was just issued for this member. Wait a moment and try again.');
        }

        return $this->deliver->handle($user, $token);
    }
}

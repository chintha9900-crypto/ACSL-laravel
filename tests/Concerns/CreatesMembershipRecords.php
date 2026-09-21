<?php

namespace Tests\Concerns;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Inserts the minimum approved-application + membership rows that an account
 * setup token must reference. Rows live only inside the test's transaction.
 */
trait CreatesMembershipRecords
{
    protected function createMembershipFor(User $user): int
    {
        $categoryId = DB::table('membership_categories')->where('code', 'P')->value('id')
            ?? DB::table('membership_categories')->insertGetId([
                'code' => 'P',
                'name' => 'Professional',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        $adminId = User::factory()->active()->create(['role' => 'admin'])->id;

        $applicationId = DB::table('membership_applications')->insertGetId([
            'public_id' => strtolower((string) Str::ulid()),
            'membership_category_id' => $categoryId,
            'status' => 'approved',
            'full_name' => $user->name,
            'email' => $user->email,
            'mobile' => '0000000000',
            'address' => 'Test address',
            'aviation_role' => 'Pilot',
            'aviation_organisation' => 'Test Airline',
            'submitted_at' => now(),
            'proof_reviewed_at' => now(),
            'proof_reviewed_by_user_id' => $adminId,
            'decided_at' => now(),
            'decided_by_user_id' => $adminId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $sequence = random_int(1, 9999);

        return DB::table('memberships')->insertGetId([
            'membership_application_id' => $applicationId,
            'user_id' => $user->id,
            'membership_category_id' => $categoryId,
            'membership_number' => 'P2607'.sprintf('%04d', $sequence),
            'number_year' => 2026,
            'number_sequence' => $sequence,
            'activated_at' => now(),
            'activated_on' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

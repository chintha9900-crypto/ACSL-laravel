<?php

namespace App\Http\Controllers\Member;

use App\Actions\Member\UpdateAvatar;
use App\Http\Controllers\Controller;
use App\Http\Requests\Member\UpdateProfileRequest;
use App\Models\Membership;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProfileController extends Controller
{
    /**
     * The signed-in member's own profile. Ownership comes only from the
     * authenticated user's id; there is no user/membership id in the route.
     */
    public function show(Request $request): View
    {
        $user = $request->user();

        return view('member.profile.edit', [
            'user' => $user,
            'membership' => Membership::query()->where('user_id', $user->id)->with('category:id,name')->first(),
        ]);
    }

    /**
     * Update the approved profile fields, and the avatar if one was uploaded.
     * `UpdateProfileRequest` authorizes via `UserPolicy::update` before this runs.
     */
    public function update(UpdateProfileRequest $request, UpdateAvatar $updateAvatar): RedirectResponse
    {
        $user = $request->user();

        // Explicit whitelist assignment — User::$fillable is never widened for this.
        $user->forceFill($request->safe()->only(UpdateProfileRequest::EDITABLE_FIELDS))->save();

        if ($request->hasFile('avatar')) {
            $updateAvatar->handle($user, $request->file('avatar'));
        }

        return redirect()->route('member.profile.show')->with('status', 'Your profile has been updated.');
    }
}

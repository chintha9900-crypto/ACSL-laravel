<?php

use App\Http\Controllers\Admin\DocumentController;
use App\Http\Controllers\Admin\MembershipActivationController;
use App\Http\Controllers\Admin\MembershipApplicationController as AdminMembershipApplicationController;
use App\Http\Controllers\Admin\MembershipApplicationReviewController;
use App\Http\Controllers\Admin\MembershipSetupLinkController;
use App\Http\Controllers\Member\DashboardController;
use App\Http\Controllers\Member\ProfileController;
use App\Http\Controllers\Membership\ApplicationStatusController;
use App\Http\Controllers\Membership\MembershipApplicationController;
use App\Http\Controllers\Membership\MoreDetailsResponseController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::controller(MembershipApplicationController::class)->group(function () {
    Route::get('membership/apply', 'create')->name('membership.apply');
    Route::post('membership/apply', 'store')->middleware('throttle:6,1')->name('membership.apply.store');
    Route::get('membership/apply/submitted/{application}', 'show')
        ->middleware('signed')
        ->name('membership.apply.submitted');
});

Route::get('applications/{application}', [ApplicationStatusController::class, 'show'])
    ->middleware(['signed', 'throttle:60,1'])
    ->name('applications.show');

// Named "member.dashboard", not "dashboard": Laravel's guest middleware treats a
// route literally named "dashboard" as its default post-login redirect target,
// which would silently change the existing sign-in redirect for every user.
Route::get('dashboard', [DashboardController::class, 'show'])
    ->middleware(['auth', 'active'])
    ->name('member.dashboard');

// "dashboard/profile" does not collide with the "dashboard" auto-redirect (that
// check matches the exact URI "dashboard", not a prefix) — see the note above.
Route::get('dashboard/profile', [ProfileController::class, 'show'])
    ->middleware(['auth', 'active'])
    ->name('member.profile.show');
Route::patch('dashboard/profile', [ProfileController::class, 'update'])
    ->middleware(['auth', 'active'])
    ->name('member.profile.update');

Route::middleware(['auth', 'active'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('membership-applications', [AdminMembershipApplicationController::class, 'index'])
        ->name('membership-applications.index');
    Route::get('membership-applications/{application}', [AdminMembershipApplicationController::class, 'show'])
        ->name('membership-applications.show');
    Route::post('membership-applications/{application}/review', [MembershipApplicationReviewController::class, 'store'])
        ->name('membership-applications.review');
    Route::post('membership-applications/{application}/activation', [MembershipActivationController::class, 'store'])
        ->name('membership-applications.activate');
    Route::post('membership-applications/{application}/setup-link', [MembershipSetupLinkController::class, 'store'])
        ->middleware('throttle:setup-link')
        ->name('membership-applications.setup-link');
    Route::get('documents/{document}', [DocumentController::class, 'show'])->name('documents.show');
});

Route::post('applications/{application}/respond', [MoreDetailsResponseController::class, 'store'])
    ->middleware(['signed', 'throttle:6,1'])
    ->name('applications.respond');

require __DIR__.'/auth.php';

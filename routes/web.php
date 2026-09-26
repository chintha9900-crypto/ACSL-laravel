<?php

use App\Http\Controllers\Admin\DocumentController;
use App\Http\Controllers\Admin\MembershipActivationController;
use App\Http\Controllers\Admin\MembershipApplicationController as AdminMembershipApplicationController;
use App\Http\Controllers\Admin\MembershipApplicationReviewController;
use App\Http\Controllers\Admin\MembershipSetupLinkController;
use App\Http\Controllers\Admin\PaymentReviewController;
use App\Http\Controllers\Member\DashboardController;
use App\Http\Controllers\Member\DocumentController as MemberDocumentController;
use App\Http\Controllers\Member\MembershipCardController;
use App\Http\Controllers\Member\MembershipController as MemberMembershipController;
use App\Http\Controllers\Member\NotificationController;
use App\Http\Controllers\Member\ProfileController;
use App\Http\Controllers\Member\RenewalController;
use App\Http\Controllers\Member\SecurityController;
use App\Http\Controllers\Membership\ApplicationStatusController;
use App\Http\Controllers\Membership\MembershipApplicationController;
use App\Http\Controllers\Membership\MembershipBenefitsController;
use App\Http\Controllers\Membership\MoreDetailsResponseController;
use App\Http\Controllers\Membership\VerificationController;
use App\Models\MembershipSetting;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('home');
})->name('home');

Route::get('about', function () {
    return view('about');
})->name('about');

Route::get('membership/benefits', [MembershipBenefitsController::class, 'show'])->name('membership.benefits');

Route::get('rules', function () {
    return view('rules');
})->name('rules');

// Static Q&A content today; the one dynamic fact (the introductory period's
// length) is read from membership settings, never hard-coded.
Route::get('faq', function () {
    return view('faq', [
        'introductoryMonths' => (int) MembershipSetting::current()->introductory_period_months,
    ]);
})->name('faq');

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

Route::get('dashboard/membership', [MemberMembershipController::class, 'show'])
    ->middleware(['auth', 'active'])
    ->name('member.membership.show');
Route::post('dashboard/membership/renewal', [RenewalController::class, 'store'])
    ->middleware(['auth', 'active'])
    ->name('member.membership.renewal.start');
Route::post('dashboard/membership/renewal/evidence', [RenewalController::class, 'submitEvidence'])
    ->middleware(['auth', 'active'])
    ->name('member.membership.renewal.evidence');
Route::get('dashboard/membership/card', [MembershipCardController::class, 'show'])
    ->middleware(['auth', 'active'])
    ->name('member.membership.card');

Route::get('dashboard/documents/{document}', [MemberDocumentController::class, 'show'])
    ->middleware(['auth', 'active'])
    ->name('member.documents.show');

Route::get('dashboard/security', [SecurityController::class, 'show'])
    ->middleware(['auth', 'active'])
    ->name('member.security.show');
// Same throttle as forgot-password/reset-password: a sensitive action that takes
// a password guess as input must not be brute-forceable.
Route::patch('dashboard/security', [SecurityController::class, 'update'])
    ->middleware(['auth', 'active', 'throttle:6,1'])
    ->name('member.security.update');

Route::get('dashboard/notifications', [NotificationController::class, 'show'])
    ->middleware(['auth', 'active'])
    ->name('member.notifications.show');
Route::post('dashboard/notifications/read-all', [NotificationController::class, 'markAllRead'])
    ->middleware(['auth', 'active'])
    ->name('member.notifications.mark-all-read');
Route::post('dashboard/notifications/{notification}/read', [NotificationController::class, 'markRead'])
    ->middleware(['auth', 'active'])
    ->name('member.notifications.mark-read');

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

    Route::get('payments', [PaymentReviewController::class, 'index'])->name('payments.index');
    Route::post('payments/{payment}/confirm', [PaymentReviewController::class, 'confirm'])->name('payments.confirm');
    Route::post('payments/{payment}/reject', [PaymentReviewController::class, 'reject'])->name('payments.reject');
});

Route::post('applications/{application}/respond', [MoreDetailsResponseController::class, 'store'])
    ->middleware(['signed', 'throttle:6,1'])
    ->name('applications.respond');

// Public QR/card verification — no account required, so it is rate-limited
// like the other public, unauthenticated membership endpoints.
Route::get('verify/{token}', [VerificationController::class, 'show'])
    ->middleware('throttle:30,1')
    ->name('verification.show');

require __DIR__.'/auth.php';

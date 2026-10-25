<?php

use App\Http\Controllers\Admin\BankAccountController;
use App\Http\Controllers\Admin\BlogCategoryController;
use App\Http\Controllers\Admin\BlogCommentController as AdminBlogCommentController;
use App\Http\Controllers\Admin\BlogPostController as AdminBlogPostController;
use App\Http\Controllers\Admin\CommercialPartnerController as AdminCommercialPartnerController;
use App\Http\Controllers\Admin\CsrController as AdminCsrController;
use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\DocumentController;
use App\Http\Controllers\Admin\EditorInvitationController;
use App\Http\Controllers\Admin\EshopReportController;
use App\Http\Controllers\Admin\EshopSettingsController;
use App\Http\Controllers\Admin\EventController as AdminEventController;
use App\Http\Controllers\Admin\InventoryController;
use App\Http\Controllers\Admin\MembershipActivationController;
use App\Http\Controllers\Admin\MembershipApplicationController as AdminMembershipApplicationController;
use App\Http\Controllers\Admin\MembershipApplicationReviewController;
use App\Http\Controllers\Admin\MembershipSetupLinkController;
use App\Http\Controllers\Admin\NewsController as AdminNewsController;
use App\Http\Controllers\Admin\OrderController as AdminOrderController;
use App\Http\Controllers\Admin\OrderPaymentReviewController;
use App\Http\Controllers\Admin\PaymentRefundController;
use App\Http\Controllers\Admin\PaymentReviewController;
use App\Http\Controllers\Admin\PaymentReviewQueueController;
use App\Http\Controllers\Admin\ProductCategoryController;
use App\Http\Controllers\Admin\ProductController as AdminProductController;
use App\Http\Controllers\Admin\SchoolClubStudentBatchController;
use App\Http\Controllers\BlogCommentController;
use App\Http\Controllers\BlogController;
use App\Http\Controllers\CartController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\CommercialPartnerController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\CsrController;
use App\Http\Controllers\Dev\DashboardController as DevDashboardController;
use App\Http\Controllers\DummyPaymentController;
use App\Http\Controllers\Editor\DashboardController as EditorDashboardController;
use App\Http\Controllers\EshopController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\Member\DashboardController;
use App\Http\Controllers\Member\DocumentController as MemberDocumentController;
use App\Http\Controllers\Member\MembershipCardController;
use App\Http\Controllers\Member\MembershipController as MemberMembershipController;
use App\Http\Controllers\Member\NotificationController;
use App\Http\Controllers\Member\OrderController as MemberOrderController;
use App\Http\Controllers\Member\ProfileController;
use App\Http\Controllers\Member\RenewalController;
use App\Http\Controllers\Member\SchoolClubStudentController;
use App\Http\Controllers\Member\SecurityController;
use App\Http\Controllers\Membership\ApplicationStatusController;
use App\Http\Controllers\Membership\MembershipApplicationController;
use App\Http\Controllers\Membership\MembershipBenefitsController;
use App\Http\Controllers\Membership\MoreDetailsResponseController;
use App\Http\Controllers\Membership\VerificationController;
use App\Http\Controllers\NewsController;
use App\Http\Controllers\NewsEventsController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PayHereNotificationController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\ReceiptController;
use App\Models\MembershipSetting;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('home');
})->name('home');

Route::get('about', function () {
    return view('about');
})->name('about');

Route::get('membership/benefits', [MembershipBenefitsController::class, 'show'])->name('membership.benefits');

// Phase 1.4C — real listing of active commercial partners, same route
// name/URL as the original placeholder.
Route::get('commercial-partners', [CommercialPartnerController::class, 'show'])->name('commercial-partners');

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

Route::controller(ContactController::class)->group(function () {
    Route::get('contact', 'show')->name('contact');
    Route::post('contact', 'store')->middleware('throttle:6,1')->name('contact.store');
    Route::get('contact/submitted', 'submitted')->name('contact.submitted');
});

Route::get('privacy', function () {
    return view('privacy');
})->name('privacy');

Route::get('terms', function () {
    return view('terms');
})->name('terms');

Route::controller(BlogController::class)->prefix('blog')->name('blog.')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::get('{post:slug}', 'show')->name('show');
});

// Member comments (pending until an admin approves them).
Route::post('blog/{post:slug}/comments', [BlogCommentController::class, 'store'])
    ->middleware(['auth', 'active', 'role:member', 'throttle:10,1'])
    ->name('blog.comments.store');

// News & Events Stage 1 — public pages only (docs/database/10 §6-7).
Route::controller(NewsController::class)->prefix('news')->name('news.')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::get('{news:slug}', 'show')->name('show');
});

Route::controller(EventController::class)->prefix('events')->name('events.')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::get('{event:slug}', 'show')->name('show');
});

// A short combined preview of both, linking out to the full `news.index` /
// `events.index` lists above — not a replacement for either.
Route::get('news-events', [NewsEventsController::class, 'show'])->name('news-events');

Route::controller(CsrController::class)->prefix('csr')->name('csr.')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::get('{project:slug}', 'show')->name('show');
});

// Public storefront (E-Shop Step 4) — product browsing only; no auth
// middleware, since guests may view/purchase PUBLIC products. Access to
// MEMBER_ONLY products is enforced inside EshopController itself, not here.
Route::controller(EshopController::class)->prefix('eshop')->name('eshop.')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::get('{product}', 'show')->name('show');
});

// E-Shop Step 5 — cart only. No auth middleware: guests may have and use a
// cart, identified by a guest-token cookie rather than a session login.
Route::controller(CartController::class)->prefix('cart')->name('cart.')->group(function () {
    Route::get('/', 'show')->name('show');
    Route::post('items', 'store')->name('items.store');
    Route::patch('items/{item}', 'update')->name('items.update');
    Route::delete('items/{item}', 'destroy')->name('items.destroy');
    Route::delete('/', 'clear')->name('clear');
});

// E-Shop Step 6 — checkout and order creation only (no real payment
// gateway/webhooks/receipts yet). No auth middleware: guests may check out
// PUBLIC products. `orders.show` needs no blanket `signed` middleware the
// way the membership-application status pages do, because an authenticated
// owner must also be able to reach it unsigned — the signature requirement
// for a guest order is enforced inside OrderController::show() itself.
Route::controller(CheckoutController::class)->prefix('checkout')->name('checkout.')->group(function () {
    Route::get('/', 'show')->name('show');
    Route::post('/', 'store')->name('store');
});

Route::get('orders/{order}', [OrderController::class, 'show'])
    ->middleware('throttle:60,1')
    ->name('orders.show');

Route::get('orders/{order}/receipt', [ReceiptController::class, 'show'])
    ->middleware('throttle:60,1')
    ->name('orders.receipt');

// Payment for one order — reachable only by whoever can already view the
// order (`AuthorizesOrderAccess`), same as `orders.show`. Nothing here marks
// a payment paid: bank transfers are verified by an admin, PayHere by its
// verified notify callback, and the dummy method is development-only.
Route::controller(PaymentController::class)->prefix('payment')->name('payment.')->group(function () {
    Route::get('{order}', 'show')->middleware('throttle:60,1')->name('show');
    Route::post('{order}/retry', 'retry')->middleware('throttle:10,1')->name('retry');
    Route::post('{order}/bank-transfer', 'submitBankTransfer')->middleware('throttle:10,1')->name('bank-transfer');
    Route::get('{order}/payhere/return', 'payHereReturn')->middleware('throttle:30,1')->name('payhere.return');
    Route::get('{order}/payhere/cancel', 'payHereCancel')->middleware('throttle:30,1')->name('payhere.cancel');
});

Route::post('payment/{order}/dummy/{outcome}', DummyPaymentController::class)
    ->whereIn('outcome', ['success', 'failure', 'cancel'])
    ->middleware('throttle:10,1')
    ->name('payment.dummy');

// PayHere server-to-server notify callback (CSRF-exempt in bootstrap/app.php;
// every request is verified by md5sig inside the controller).
Route::post('payments/payhere/notify', PayHereNotificationController::class)
    ->middleware('throttle:120,1')
    ->name('payments.payhere.notify');

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
//
// RBAC foundation — member-only, same as the admin group's own `role:` gate:
// an admin/editor/dev may land on *their* admin dashboard, never this one.
// `role:member` is coarse, route-level defence in depth; the ownership
// checks each action already performs (resolving only the signed-in user's
// own membership/orders/documents/etc., backed by Policies) are unchanged.
Route::middleware(['auth', 'active', 'role:member'])->group(function () {
    Route::get('dashboard', [DashboardController::class, 'show'])
        ->name('member.dashboard');

    // "dashboard/profile" does not collide with the "dashboard" auto-redirect
    // (that check matches the exact URI "dashboard", not a prefix) — see the
    // note above.
    Route::get('dashboard/profile', [ProfileController::class, 'show'])
        ->name('member.profile.show');
    Route::patch('dashboard/profile', [ProfileController::class, 'update'])
        ->name('member.profile.update');

    Route::get('dashboard/membership', [MemberMembershipController::class, 'show'])
        ->name('member.membership.show');
    Route::post('dashboard/membership/renewal', [RenewalController::class, 'store'])
        ->name('member.membership.renewal.start');
    Route::post('dashboard/membership/renewal/evidence', [RenewalController::class, 'submitEvidence'])
        ->name('member.membership.renewal.evidence');
    Route::get('dashboard/membership/card', [MembershipCardController::class, 'show'])
        ->name('member.membership.card');

    Route::get('dashboard/documents/{document}', [MemberDocumentController::class, 'show'])
        ->name('member.documents.show');

    // School Club — "Manage Student Members" (main School Club only; the
    // controller and request enforce ownership).
    Route::get('dashboard/school-club/students', [SchoolClubStudentController::class, 'index'])
        ->name('member.school-club.students.index');
    Route::post('dashboard/school-club/students', [SchoolClubStudentController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('member.school-club.students.store');
    Route::post('dashboard/school-club/students/{batch}/resubmit', [SchoolClubStudentController::class, 'resubmit'])
        ->middleware('throttle:6,1')
        ->name('member.school-club.students.resubmit');

    Route::get('dashboard/security', [SecurityController::class, 'show'])
        ->name('member.security.show');
    // Same throttle as forgot-password/reset-password: a sensitive action that
    // takes a password guess as input must not be brute-forceable.
    Route::patch('dashboard/security', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('member.security.update');

    Route::get('dashboard/orders', [MemberOrderController::class, 'index'])
        ->name('member.orders.index');

    Route::get('dashboard/notifications', [NotificationController::class, 'show'])
        ->name('member.notifications.show');
    Route::post('dashboard/notifications/read-all', [NotificationController::class, 'markAllRead'])
        ->name('member.notifications.mark-all-read');
    Route::post('dashboard/notifications/{notification}/read', [NotificationController::class, 'markRead'])
        ->name('member.notifications.mark-read');
});

// Role dashboards — every staff role signs in through the one public login
// page and lands on its own dashboard (RoleRedirect). Admin may open every one.
Route::middleware(['auth', 'active', 'role:editor,admin'])->group(function () {
    Route::get('editor', [EditorDashboardController::class, 'show'])->name('editor.dashboard');
});

Route::middleware(['auth', 'active', 'role:dev,admin'])->group(function () {
    Route::get('dev', [DevDashboardController::class, 'show'])->name('dev.dashboard');
});

// The operational sections the admin and editor dashboards link to. `role` is
// coarse, route-level defence in depth (member, dev and any unrecognised role
// never reach a single controller here), not a replacement for each action's
// own Policy check below, which is what distinguishes admin from editor.
Route::middleware(['auth', 'active', 'role:admin,editor'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', [AdminDashboardController::class, 'show'])->middleware('role:admin')->name('dashboard');

    // Admin-only: invite an editor (one-time setup link, no password sent).
    Route::post('editor-invitations', [EditorInvitationController::class, 'store'])
        ->middleware(['role:admin', 'throttle:10,1'])
        ->name('editor-invitations.store');

    // Admin-only: blog comment moderation (BlogCommentPolicy::moderate).
    Route::middleware('role:admin')->group(function () {
        Route::get('blog-comments', [AdminBlogCommentController::class, 'index'])->name('blog-comments.index');
        Route::post('blog-comments/{comment}/approve', [AdminBlogCommentController::class, 'approve'])->name('blog-comments.approve');
        Route::post('blog-comments/{comment}/reject', [AdminBlogCommentController::class, 'reject'])->name('blog-comments.reject');
    });

    // E-shop financial summary (read-only), gated like order management.
    Route::get('reports/eshop', [EshopReportController::class, 'show'])->name('reports.eshop');

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

    // E-shop bank-transfer verification (separate from membership renewals).
    Route::get('order-payments', [OrderPaymentReviewController::class, 'index'])->name('order-payments.index');
    Route::post('order-payments/{payment}/approve', [OrderPaymentReviewController::class, 'approve'])->name('order-payments.approve');
    Route::post('order-payments/{payment}/reject', [OrderPaymentReviewController::class, 'reject'])->name('order-payments.reject');

    // E-shop payment conflicts needing a human decision, and manual refunds
    // (performed in the PayHere merchant portal or by bank, recorded here).
    Route::get('payment-reviews', [PaymentReviewQueueController::class, 'index'])->name('payment-reviews.index');
    Route::post('payment-reviews/{paymentReview}/resolve', [PaymentReviewQueueController::class, 'resolve'])->name('payment-reviews.resolve');
    Route::post('order-payments/{payment}/refunds', [PaymentRefundController::class, 'store'])->name('payment-refunds.store');

    // Delivery charge (commerce.manage) and receiving bank accounts (settings.manage).
    Route::get('eshop-settings', [EshopSettingsController::class, 'edit'])->name('eshop-settings.edit');
    Route::put('eshop-settings', [EshopSettingsController::class, 'update'])->name('eshop-settings.update');
    Route::get('bank-accounts', [BankAccountController::class, 'index'])->name('bank-accounts.index');
    Route::get('bank-accounts/create', [BankAccountController::class, 'create'])->name('bank-accounts.create');
    Route::post('bank-accounts', [BankAccountController::class, 'store'])->name('bank-accounts.store');
    Route::post('bank-accounts/{bankAccount}/activate', [BankAccountController::class, 'activate'])->name('bank-accounts.activate');
    Route::post('bank-accounts/{bankAccount}/deactivate', [BankAccountController::class, 'deactivate'])->name('bank-accounts.deactivate');

    // School Club student batches — review (Policy-gated: application review; approve also activation).
    Route::get('school-club-batches', [SchoolClubStudentBatchController::class, 'index'])->name('school-club-batches.index');
    Route::get('school-club-batches/{batch}', [SchoolClubStudentBatchController::class, 'show'])->name('school-club-batches.show');
    Route::post('school-club-batches/{batch}/review', [SchoolClubStudentBatchController::class, 'review'])->name('school-club-batches.review');

    Route::controller(AdminBlogPostController::class)->prefix('blog')->name('blog.')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('create', 'create')->name('create');
        Route::post('/', 'store')->name('store');
        Route::get('{post}/edit', 'edit')->name('edit');
        Route::patch('{post}', 'update')->name('update');
        Route::delete('{post}', 'destroy')->name('destroy');
        Route::post('{post}/publish', 'publish')->name('publish');
        Route::post('{post}/unpublish', 'unpublish')->name('unpublish');
    });

    Route::controller(BlogCategoryController::class)->prefix('blog-categories')->name('blog-categories.')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/', 'store')->name('store');
        Route::patch('{category}', 'update')->name('update');
        Route::delete('{category}', 'destroy')->name('destroy');
    });

    Route::controller(AdminNewsController::class)->prefix('news')->name('news.')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('create', 'create')->name('create');
        Route::post('/', 'store')->name('store');
        Route::get('{news}/edit', 'edit')->name('edit');
        Route::get('{news}', 'show')->name('show');
        Route::patch('{news}', 'update')->name('update');
        Route::delete('{news}', 'destroy')->name('destroy');
        Route::post('{news}/publish', 'publish')->name('publish');
        Route::post('{news}/unpublish', 'unpublish')->name('unpublish');
    });

    Route::controller(AdminEventController::class)->prefix('events')->name('events.')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('create', 'create')->name('create');
        Route::post('/', 'store')->name('store');
        Route::get('{event}/edit', 'edit')->name('edit');
        Route::get('{event}', 'show')->name('show');
        Route::patch('{event}', 'update')->name('update');
        Route::delete('{event}', 'destroy')->name('destroy');
        Route::post('{event}/publish', 'publish')->name('publish');
        Route::post('{event}/unpublish', 'unpublish')->name('unpublish');
    });

    Route::controller(AdminCsrController::class)->prefix('csr')->name('csr.')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('create', 'create')->name('create');
        Route::post('/', 'store')->name('store');
        Route::get('{project}/edit', 'edit')->name('edit');
        Route::get('{project}', 'show')->name('show');
        Route::patch('{project}', 'update')->name('update');
        Route::delete('{project}', 'destroy')->name('destroy');
        Route::post('{project}/publish', 'publish')->name('publish');
        Route::post('{project}/unpublish', 'unpublish')->name('unpublish');
    });

    Route::controller(AdminCommercialPartnerController::class)->prefix('commercial-partners')->name('commercial-partners.')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('create', 'create')->name('create');
        Route::post('/', 'store')->name('store');
        Route::get('{partner}/edit', 'edit')->name('edit');
        Route::get('{partner}', 'show')->name('show');
        Route::patch('{partner}', 'update')->name('update');
        Route::delete('{partner}', 'destroy')->name('destroy');
        Route::post('{partner}/activate', 'activate')->name('activate');
        Route::post('{partner}/deactivate', 'deactivate')->name('deactivate');
    });

    Route::controller(AdminProductController::class)->prefix('products')->name('products.')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('create', 'create')->name('create');
        Route::post('/', 'store')->name('store');
        Route::get('{product}', 'show')->name('show');
        Route::get('{product}/edit', 'edit')->name('edit');
        Route::patch('{product}', 'update')->name('update');
        Route::delete('{product}', 'destroy')->name('destroy');
        Route::post('{product}/activate', 'activate')->name('activate');
        Route::post('{product}/deactivate', 'deactivate')->name('deactivate');
    });

    Route::controller(ProductCategoryController::class)->prefix('product-categories')->name('product-categories.')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/', 'store')->name('store');
        Route::patch('{category}', 'update')->name('update');
        Route::delete('{category}', 'destroy')->name('destroy');
        Route::post('{category}/activate', 'activate')->name('activate');
        Route::post('{category}/deactivate', 'deactivate')->name('deactivate');
    });

    Route::controller(InventoryController::class)->prefix('inventory')->name('inventory.')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::patch('{inventory}/threshold', 'updateThreshold')->name('update-threshold');
        Route::post('{inventory}/add', 'addStock')->name('add-stock');
        Route::post('{inventory}/remove', 'removeStock')->name('remove-stock');
    });

    Route::controller(AdminOrderController::class)->prefix('orders')->name('orders.')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('{order}', 'show')->name('show');
        Route::patch('{order}/status', 'updateStatus')->name('update-status');
    });
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

<?php

use App\Http\Controllers\Api\{
    AccountController, AuthController, AvatarController, BackupController,
    BookController, BookReviewController, BorrowController, CategoryController,
    DashboardController, EbookController, EbookFavoriteController, FineController,
    LibrarianController, MemberController, MembershipTypeController, NotificationController, PreferenceController,
    ReportController,
    ReservationController, ScanController, SettingController
};
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// 1. AUTH
Route::prefix('auth')->group(function () {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login']);

    Route::post('login/qr', [AuthController::class, 'loginWithQr']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
        Route::post('change-password', [AuthController::class, 'changePassword']);
        Route::put('profile/account', [AccountController::class, 'update']);
        Route::post('profile/avatar', [AvatarController::class, 'update']);
        Route::delete('profile/avatar', [AvatarController::class, 'destroy']);

        Route::get('profile/preferences', [PreferenceController::class, 'show']);
        Route::put('profile/preferences', [PreferenceController::class, 'update']);

        Route::post('qr-token/generate', [AuthController::class, 'generateQrToken']);
        Route::post('qr-token/revoke', [AuthController::class, 'revokeQrToken']);

        Route::middleware('role:admin,librarian')
            ->post('users/{user}/qr-token/generate', [AuthController::class, 'generateQrTokenFor']);

        Route::middleware('role:admin,librarian')
            ->get('pending-cards', [AuthController::class, 'pendingCards']);

        Route::middleware('role:admin')->post('create-librarian', [AuthController::class, 'createLibrarian']);
    });
});

// 2. CATALOG & REVIEWS
Route::middleware('auth:sanctum')->group(function () {
    Route::prefix('books')->group(function () {
        Route::get('popular', [BookController::class, 'popular']);
        Route::get('/', [BookController::class, 'index']);
        Route::get('{book}', [BookController::class, 'show']);

        Route::get('{book}/reviews', [BookReviewController::class, 'index']);
        Route::post('{book}/reviews', [BookReviewController::class, 'store']);

        Route::middleware('role:admin,librarian')->group(function () {
            Route::post('lookup-isbn', [BookController::class, 'lookupIsbn']);
            Route::post('/', [BookController::class, 'store']);
            Route::put('{book}', [BookController::class, 'update']);
            Route::post('{book}/add-copies', [BookController::class, 'addCopies']);
            Route::delete('{book}', [BookController::class, 'destroy']);
        });
    });

    Route::prefix('categories')->group(function () {
        Route::get('/', [CategoryController::class, 'index']);
        Route::middleware('role:admin,librarian')->group(function () {
            Route::post('/', [CategoryController::class, 'store']);
            Route::put('{category}', [CategoryController::class, 'update']);
            Route::delete('{category}', [CategoryController::class, 'destroy']);
        });
    });
});

// 3. CIRCULATION: BORROWS
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/my-borrows', [BorrowController::class, 'myBorrows']);
    Route::post('/borrows/{borrow}/renew', [BorrowController::class, 'renew']);

    Route::middleware('role:admin,librarian')->prefix('borrows')->group(function () {
        Route::get('/', [BorrowController::class, 'index']);
        Route::get('overdue', [BorrowController::class, 'overdue']);
        Route::post('/', [BorrowController::class, 'store']);
        Route::get('{borrow}', [BorrowController::class, 'show']);
        Route::post('{borrow}/return', [BorrowController::class, 'returnBook']);
    });
});

// 4. CIRCULATION: RESERVATIONS
Route::middleware('auth:sanctum')->prefix('reservations')->group(function () {
    Route::get('/my-list', [ReservationController::class, 'myReservations']);
    Route::post('/', [ReservationController::class, 'store']);
    Route::post('{reservation}/cancel', [ReservationController::class, 'cancel']);

    Route::middleware('role:admin,librarian')->group(function () {
        Route::get('/', [ReservationController::class, 'index']);
        Route::get('{reservation}', [ReservationController::class, 'show']);
        Route::post('{reservation}/fulfill', [ReservationController::class, 'fulfill']);
    });
});

// 4.5. DIGITAL LIBRARY: EBOOKS
Route::middleware('auth:sanctum')->prefix('ebooks')->group(function () {
    Route::get('/', [EbookController::class, 'index']);
    Route::get('{ebook}', [EbookController::class, 'show']);
    Route::post('{ebook}/download', [EbookController::class, 'download']);
    Route::get('{ebook}/progress', [EbookController::class, 'getProgress']);
    Route::put('{ebook}/progress', [EbookController::class, 'updateProgress']);
    Route::post('{ebook}/favorite', [EbookFavoriteController::class, 'toggle']);

    Route::middleware('role:admin,librarian')->group(function () {
        Route::post('/', [EbookController::class, 'store']);
        Route::put('{ebook}', [EbookController::class, 'update']);
        Route::delete('{ebook}', [EbookController::class, 'destroy']);
    });
});

Route::get('ebooks/{ebook}/stream', [EbookController::class, 'stream'])
    ->name('ebooks.stream')
    ->middleware('signed');

Route::middleware('auth:sanctum')->get('/my-favorites', [EbookFavoriteController::class, 'index']);

// 5. USER MANAGEMENT
Route::middleware(['auth:sanctum', 'role:admin,librarian'])->prefix('members')->group(function () {
    Route::get('/', [MemberController::class, 'index']);
    Route::post('/', [MemberController::class, 'store']);
    Route::get('{member}', [MemberController::class, 'show']);
    Route::put('{member}', [MemberController::class, 'update']);
    Route::delete('{member}', [MemberController::class, 'destroy']);
    Route::post('{id}/restore', [MemberController::class, 'restore']);
});

Route::middleware('auth:sanctum')->get('/member/profile', [MemberController::class, 'myProfile']);

// 5.6. MEMBERSHIP TYPES (master data for Member forms)
// FIX: new — was previously hardcoded as Rule::in(['student','teacher',
// 'external']) inside StoreMemberRequest/UpdateMemberRequest. Now backed
// by a real table so admin can add/rename/retire types from the
// Membership Types settings page without a code change or deploy.
Route::middleware('auth:sanctum')->group(function () {
    Route::get('membership-types', [MembershipTypeController::class, 'index']);

    Route::middleware('role:admin')->group(function () {
        Route::post('membership-types', [MembershipTypeController::class, 'store']);
        Route::put('membership-types/{membershipType}', [MembershipTypeController::class, 'update']);
        Route::delete('membership-types/{membershipType}', [MembershipTypeController::class, 'destroy']);
    });
});

Route::middleware(['auth:sanctum', 'role:admin'])->prefix('librarians')->group(function () {
    Route::get('/', [LibrarianController::class, 'index']);
    Route::post('/', [LibrarianController::class, 'store']);
    Route::get('{librarian}', [LibrarianController::class, 'show']);
    Route::put('{librarian}', [LibrarianController::class, 'update']);
    Route::delete('{librarian}', [LibrarianController::class, 'destroy']);
});

// 6. FINES
Route::middleware(['auth:sanctum', 'role:admin,librarian'])->prefix('fines')->group(function () {
    Route::get('/', [FineController::class, 'index']);
    Route::get('summary', [FineController::class, 'summary']);
    Route::post('/', [FineController::class, 'store']);
    Route::get('{fine}', [FineController::class, 'show']);
    Route::post('{fine}/pay', [FineController::class, 'pay']);
    Route::post('{fine}/waive', [FineController::class, 'waive']);
});

Route::middleware('auth:sanctum')->get('/my-fines', [FineController::class, 'myFines']);

// 6.5. SCANNER
Route::middleware('auth:sanctum')->prefix('scan')->group(function () {
    Route::post('/', [ScanController::class, 'store']);

    Route::middleware('role:admin,librarian')->group(function () {
        Route::get('history', [ScanController::class, 'index']);
        Route::get('summary', [ScanController::class, 'summary']);
    });
});

// 7. SETTINGS & MISC
Route::middleware('auth:sanctum')->prefix('settings')->group(function () {
    Route::middleware('role:admin,librarian')->group(function () {
        Route::get('/', [SettingController::class, 'index']);
        Route::get('backup/history', [BackupController::class, 'index']);
    });

    Route::middleware('role:admin')->group(function () {
        Route::post('/', [SettingController::class, 'store']);
        Route::put('{setting}', [SettingController::class, 'update']);
        Route::delete('{setting}', [SettingController::class, 'destroy']);

        Route::get('backup/create', [BackupController::class, 'create']);
        Route::post('backup/restore', [BackupController::class, 'restore']);
    });
});

// 8. DASHBOARD
// FIX: was a single Route::get('dashboard', ...) line only covering the
// stats endpoint. Expanded into a prefix('dashboard') group with the two
// additional endpoints (recent-activities, revenue-chart) that
// dashboardStore.js's fetchRecentActivities() and fetchRevenueChart()
// already call, backed now by DashboardController::recentActivities()
// and DashboardController::revenueChart().
Route::middleware(['auth:sanctum', 'role:admin,librarian'])->prefix('dashboard')->group(function () {
    Route::get('/', [DashboardController::class, 'index']);
    Route::get('recent-activities', [DashboardController::class, 'recentActivities']);
    Route::get('revenue-chart', [DashboardController::class, 'revenueChart']);
});

// 9. NOTIFICATIONS
Route::middleware('auth:sanctum')->prefix('notifications')->group(function () {
    Route::get('/', [NotificationController::class, 'index']);
    Route::get('unread-count', [NotificationController::class, 'unreadCount']);
    Route::post('mark-all-read', [NotificationController::class, 'markAllRead']);
    Route::get('{notification}', [NotificationController::class, 'show']);
    Route::post('{notification}/read', [NotificationController::class, 'markRead']);
    Route::delete('{notification}', [NotificationController::class, 'destroy']);

    Route::middleware('role:admin,librarian')->post('/', [NotificationController::class, 'store']);
});

// 10. ADMIN REPORTS
Route::middleware(['auth:sanctum', 'role:admin,librarian'])
    ->prefix('admin/reports')
    ->group(function () {
        Route::get('/dashboard', [ReportController::class, 'dashboard']);
        Route::get('/borrow',    [ReportController::class, 'borrow']);
        Route::get('/fine',      [ReportController::class, 'fine']);
        Route::get('/user',      [ReportController::class, 'user']);
        Route::get('/revenue',   [ReportController::class, 'revenue']);
        Route::get('/stock',     [ReportController::class, 'stock']);
        Route::post('/export',   [ReportController::class, 'export']);
        Route::get('/',          [ReportController::class, 'index']);
        Route::delete('/{id}',   [ReportController::class, 'destroy']);
    });
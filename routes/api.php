<?php

use App\Http\Controllers\Api\{
    AccountController, AuthController, AvatarController, BackupController,
    BookController, BookReviewController, BorrowController, CategoryController,
    DashboardController, EbookController, EbookFavoriteController, FineController,
    LibrarianController, MemberController, NotificationController, PreferenceController,
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
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
        Route::post('change-password', [AuthController::class, 'changePassword']);
        Route::put('profile/account', [AccountController::class, 'update']);
        Route::post('profile/avatar', [AvatarController::class, 'update']);
        Route::delete('profile/avatar', [AvatarController::class, 'destroy']);

        // Personal preferences (dark mode, notification toggles) — any authenticated user
        Route::get('profile/preferences', [PreferenceController::class, 'show']);
        Route::put('profile/preferences', [PreferenceController::class, 'update']);

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
    // Member routes
    Route::get('/my-list', [ReservationController::class, 'myReservations']);
    Route::post('/', [ReservationController::class, 'store']);
    Route::post('{reservation}/cancel', [ReservationController::class, 'cancel']);

    // Admin/Librarian routes
    Route::middleware('role:admin,librarian')->group(function () {
        Route::get('/', [ReservationController::class, 'index']);
        Route::get('{reservation}', [ReservationController::class, 'show']);
        Route::post('{reservation}/fulfill', [ReservationController::class, 'fulfill']);
    });
});

// 4.5. DIGITAL LIBRARY: EBOOKS
Route::middleware('auth:sanctum')->prefix('ebooks')->group(function () {
    // Shared (any authenticated user — member/librarian/admin)
    Route::get('/', [EbookController::class, 'index']);
    Route::get('{ebook}', [EbookController::class, 'show']);
    Route::post('{ebook}/download', [EbookController::class, 'download']);
    Route::get('{ebook}/progress', [EbookController::class, 'getProgress']);
    Route::put('{ebook}/progress', [EbookController::class, 'updateProgress']);
    Route::post('{ebook}/favorite', [EbookFavoriteController::class, 'toggle']);


    // Admin/Librarian only — manage e-book catalog entries
    Route::middleware('role:admin,librarian')->group(function () {
        Route::post('/', [EbookController::class, 'store']);
        Route::put('{ebook}', [EbookController::class, 'update']);
        Route::delete('{ebook}', [EbookController::class, 'destroy']);
    });
});

// Signed-URL protected file streaming — NOT nested under the ebooks prefix
// group above because it must NOT require 'auth:sanctum'. Access control
// instead comes entirely from the temporary signature (see
// EbookController::download(), which generates this URL and expires it
// after 10 minutes). Kept outside the prefix() group intentionally.
Route::get('ebooks/{ebook}/stream', [EbookController::class, 'stream'])
    ->name('ebooks.stream')
    ->middleware('signed');

// Member's own favorited e-books list.
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

// 5.5. MEMBER SELF-SERVICE PROFILE
// FIX: was completely missing. MyProfilePage.vue on the frontend already
// calls store.fetchMyProfile(), but there was no backend route for it to
// hit. Kept deliberately outside the admin/librarian-only group above —
// any authenticated member can view their OWN profile here, read-only.
// No PUT route exists on purpose: MyProfilePage.vue is read-only by
// design (tells the member to contact a librarian to change details),
// so there is no self-edit surface to secure/validate here at all.
Route::middleware('auth:sanctum')->get('/member/profile', [MemberController::class, 'myProfile']);

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

// Member's own fines — kept outside the admin/librarian-only group above
// so a plain authenticated member can view their own fine history.
Route::middleware('auth:sanctum')->get('/my-fines', [FineController::class, 'myFines']);

// 6.5. SCANNER
Route::middleware('auth:sanctum')->prefix('scan')->group(function () {
    // Any authenticated user (staff scanning at desk uses their own login)
    Route::post('/', [ScanController::class, 'store']);

    // Admin/Librarian only — view scan audit log + summary stats
    Route::middleware('role:admin,librarian')->group(function () {
        Route::get('history', [ScanController::class, 'index']);
        Route::get('summary', [ScanController::class, 'summary']);
    });
});

// 7. SETTINGS & MISC
Route::middleware('auth:sanctum')->prefix('settings')->group(function () {

    // Admin + Librarian — view settings (librarian is filtered inside the controller)
    Route::middleware('role:admin,librarian')->group(function () {
        Route::get('/', [SettingController::class, 'index']);
        Route::get('backup/history', [BackupController::class, 'index']);
    });

    // Admin only — mutate settings & perform backup operations
    Route::middleware('role:admin')->group(function () {
        Route::post('/', [SettingController::class, 'store']);
        Route::put('{setting}', [SettingController::class, 'update']);
        Route::delete('{setting}', [SettingController::class, 'destroy']);

        Route::get('backup/create', [BackupController::class, 'create']);
        Route::post('backup/restore', [BackupController::class, 'restore']);
    });
});

Route::middleware(['auth:sanctum', 'role:admin,librarian'])->get('dashboard', [DashboardController::class, 'index']);

// 8. NOTIFICATIONS
Route::middleware('auth:sanctum')->prefix('notifications')->group(function () {
    Route::get('/', [NotificationController::class, 'index']);
    Route::get('unread-count', [NotificationController::class, 'unreadCount']);
    Route::post('mark-all-read', [NotificationController::class, 'markAllRead']);
    Route::get('{notification}', [NotificationController::class, 'show']);
    Route::post('{notification}/read', [NotificationController::class, 'markRead']);
    Route::delete('{notification}', [NotificationController::class, 'destroy']);

    Route::middleware('role:admin,librarian')->post('/', [NotificationController::class, 'store']);
});

// 9. ADMIN REPORTS 
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
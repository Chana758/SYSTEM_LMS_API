<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ActivateAccountRequest;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Models\Librarian;
use App\Models\Member;
use App\Models\Role;
use App\Models\User;
use App\Services\AccountStatusService;
use App\Services\LoginLogger;
use App\Support\VisitSettings;
use App\Support\AuthSettings;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    private const QR_TOKEN_LIFETIME_DAYS = 90;

    public function __construct(private AccountStatusService $accountStatus)
    {
    }

    /** GET /auth/config (public): what the Login page and staff screen lock need (B3.1). */
    public function config()
    {
        return response()->json(AuthSettings::publicConfig());
    }

    /*
     | Registration & login
     */

    /** Register a new member account (user + member profile) and return a token. */
    public function register(RegisterRequest $request)
    {
        if (! AuthSettings::registrationEnabled()) {
            return response()->json([
                'code'    => 'REGISTRATION_DISABLED',
                'message' => __('account.REGISTRATION_DISABLED'),
            ], 403);
        }

        $validated = $request->validated();
        $memberRole = Role::firstOrCreate(['name' => 'member']);

        // A1: new accounts wait for staff approval (can browse, cannot borrow/reserve).
        $status = AuthSettings::registrationRequiresApproval() ? 'pending' : 'active';

        $user = DB::transaction(function () use ($validated, $memberRole, $status) {
            $user = User::create([
                'name'     => $validated['name'],
                'email'    => strtolower($validated['email']),
                'password' => Hash::make($validated['password']),
                'phone'    => $validated['phone'] ?? null,
                'role_id'  => $memberRole->id,
                'status'   => $status,
            ]);

            Member::create([
                'user_id'          => $user->id,
                'membership_no'    => $this->generateMembershipNo(),
                'membership_type'  => 'student', // staff change it after checking ID
                'max_borrow_limit' => 3,
                'join_date'        => now(),
                'status'           => 'active',
            ]);

            return $user;
        });

        $message = $status === 'pending' ? __('account.REGISTERED_PENDING') : 'User registered successfully';

        return $this->tokenResponse($user, $message, 'auth_token', 201);
    }

    /**
     * Log in with email OR student ID / membership number (B2.4) and password.
     * Refusals explain why (A3); pending/expired accounts may log in but get a notice.
     */
    public function login(LoginRequest $request)
    {
        $identifier = trim((string) ($request->input('login') ?? $request->input('email')));

        $user = $this->findByIdentifier($identifier);

        if (! $user || ! Hash::check($request->input('password'), $user->password)) {
            LoginLogger::record('login_failed', $user, false, 'INVALID_LOGIN', $identifier);

            return response()->json([
                'code'    => 'INVALID_LOGIN',
                'message' => __('account.INVALID_LOGIN'),
            ], 401);
        }

        if ($block = $this->accountStatus->loginBlock($user)) {
            LoginLogger::record('login_blocked', $user, false, $block['code'] ?? null, $identifier);

            return response()->json($block, 403);
        }

        LoginLogger::record('login', $user, true, null, $identifier);

        return $this->tokenResponse($user, 'Login successful', 'auth_token');
    }

    /**
     * POST /auth/activate (public, B2.2): an imported student activates their own account
     * with student ID + date of birth, then sets a password and a 4-digit PIN.
     */
    public function activate(ActivateAccountRequest $request)
    {
        if (! AuthSettings::activationEnabled()) {
            return response()->json([
                'code'    => 'ACTIVATION_DISABLED',
                'message' => __('account.ACTIVATION_DISABLED'),
            ], 403);
        }

        $member = Member::with('user')
            ->where('student_id', trim($request->student_id))
            ->whereDate('date_of_birth', $request->date_of_birth)
            ->first();

        // Same answer for "not found" and "already active" so IDs can't be probed.
        if (! $member || ! $member->user || $member->user->status !== 'inactive') {
            throw ValidationException::withMessages([
                'student_id' => __('account.ACTIVATION_FAILED'),
            ]);
        }

        $member->user->forceFill([
            'password'             => Hash::make($request->password),
            'pin'                  => Hash::make($request->pin),
            'pin_failed_attempts'  => 0,
            'pin_locked_until'     => null,
            'must_change_password' => false,
            'status'               => 'active',
        ])->save();

        LoginLogger::record('activate', $member->user, true, null, $member->student_id);

        return response()->json([
            'message' => __('account.ACTIVATED'),
            'login'   => $member->student_id,
        ]);
    }

    /** POST /auth/verify-password: unlock the staff screen after the idle lock (B2.7). */
    public function verifyPassword(Request $request)
    {
        $request->validate(['password' => ['required', 'string']]);

        if (! Hash::check($request->password, $request->user()->password)) {
            throw ValidationException::withMessages([
                'password' => __('account.WRONG_PASSWORD'),
            ]);
        }

        return response()->json(['ok' => true]);
    }

    /** Log out by deleting only the current access token. */
    public function logout(Request $request)
    {
        LoginLogger::record('logout', $request->user());
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logout successfully'], 200);
    }

    /** POST /auth/logout-all (B2.8): delete every token, e.g. when a phone is lost. */
    public function logoutAll(Request $request)
    {
        LoginLogger::record('logout_all', $request->user());
        $request->user()->tokens()->delete();

        return response()->json(['message' => __('account.LOGGED_OUT_ALL')], 200);
    }

    /** Return the logged-in user with role, member and librarian profiles. */
    public function me(Request $request)
    {
        $user = $request->user()->load('role', 'member', 'librarian');

        return response()->json([
            'user'           => $user,
            'account_notice' => $this->accountStatus->loginNotice($user),
        ], 200);
    }

    /*
     | QR cards (issued by staff only; see routes/api.php for middleware)
     */

    /** POST /auth/users/{user}/qr-token/generate (admin/librarian): issue a new QR card token. */
    public function generateQrTokenFor(Request $request, User $user)
    {
        $actor = $request->user();

        // Librarians may only issue cards for members.
        if ($actor->role?->name === 'librarian' && $user->role?->name !== 'member') {
            return response()->json([
                'message' => 'Librarians can only issue QR cards for members.',
            ], 403);
        }

        $rawToken = Str::random(48);

        $user->update([
            'qr_login_token'            => $this->hashQrToken($rawToken),
            'qr_login_token_expires_at' => now()->addDays(self::QR_TOKEN_LIFETIME_DAYS),
        ]);

        return response()->json([
            'message'    => 'QR login token generated successfully',
            'qr_token'   => $rawToken, // shown once; only the hash is stored
            'expires_at' => $user->qr_login_token_expires_at,
            'user'       => $user->only('id', 'name'),
        ], 200);
    }

    /** GET /auth/pending-cards (admin/librarian): list users who have no QR card yet. */
    public function pendingCards(Request $request)
    {
        $isLibrarian = $request->user()->role?->name === 'librarian';

        $query = User::with(['role', 'member', 'librarian'])
            ->whereNull('qr_login_token')
            ->whereHas('role', function ($q) use ($isLibrarian) {
                $isLibrarian
                    ? $q->where('name', 'member')
                    : $q->whereIn('name', ['member', 'librarian']);
            });

        return response()->json(
            $query->latest()->paginate($request->get('per_page', 15))
        );
    }

    /** GET /auth/qr-token/status (self): check whether my QR card is still valid. */
    public function qrCardStatus(Request $request)
    {
        $user = $request->user();

        $hasCard = (bool) $user->qr_login_token
            && $user->qr_login_token_expires_at
            && $user->qr_login_token_expires_at->isFuture();

        return response()->json([
            'has_card'   => $hasCard,
            'expires_at' => $hasCard ? $user->qr_login_token_expires_at : null,
        ]);
    }

    /** POST /auth/qr-token/revoke (self): delete my QR card token. */
    public function revokeQrToken(Request $request)
    {
        $request->user()->update([
            'qr_login_token'            => null,
            'qr_login_token_expires_at' => null,
        ]);

        return response()->json(['message' => 'QR login token revoked successfully'], 200);
    }

    /* ----------------
     | Staff management
     * --------------- */

    /** Admin only: create a librarian account (user + librarian profile). */
    public function createLibrarian(RegisterRequest $request)
    {
        $validated = $request->validated();
        $librarianRole = Role::firstOrCreate(['name' => 'librarian']);

        $user = DB::transaction(function () use ($validated, $librarianRole) {
            $user = User::create([
                'name'     => $validated['name'],
                'email'    => $validated['email'],
                'password' => Hash::make($validated['password']),
                'phone'    => $validated['phone'] ?? null,
                'role_id'  => $librarianRole->id,
                'status'   => 'active',
            ]);

            // B2.6: the admin chose this password, so the librarian must replace it.
            $user->forceFill(['must_change_password' => true])->save();

            Librarian::create([
                'user_id'     => $user->id,
                'employee_id' => $this->generateEmployeeId(),
                'status'      => 'active',
            ]);

            return $user;
        });

        return response()->json([
            'message' => 'Librarian created successfully',
            'user'    => $user->load('role', 'librarian'),
        ], 201);
    }

    /* ------------------
     | Password management
     * ----------------- */

    /** POST /auth/change-password: set a new password and sign out all other devices. */
    public function changePassword(ChangePasswordRequest $request)
    {
        $user = $request->user();

        $user->forceFill([
            'password'             => Hash::make($request->password),
            'must_change_password' => false,
        ])->save();

        // Sign out every OTHER device, keep the current session alive.
        $current = $user->currentAccessToken();

        $user->tokens()
            ->when(
                $current instanceof PersonalAccessToken,
                fn ($q) => $q->where('id', '!=', $current->id)
            )
            ->delete();

        return response()->json(['message' => 'Password updated successfully.']);
    }

    /**
     * POST /auth/forgot-password (public): email a reset link.
     * Always 200 for unknown emails so the endpoint can't be used
     * to probe which addresses are registered.
     */
    public function forgotPassword(ForgotPasswordRequest $request)
    {
        $status = Password::sendResetLink($request->only('email'));

        // RESET_THROTTLED is included too: a different answer would reveal that the email exists.
        if (in_array($status, [Password::RESET_LINK_SENT, Password::INVALID_USER, Password::RESET_THROTTLED], true)) {
            return response()->json([
                'message' => 'If an account exists for that email, a password reset link has been sent.',
            ], 200);
        }

        return response()->json(['message' => __($status)], 422);
    }

    /** POST /auth/reset-password (public): reset the password with an emailed token. */
    public function resetPassword(ResetPasswordRequest $request)
    {
        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill([
                    'password'                  => Hash::make($password),
                    'must_change_password'      => false,
                    // A reset means "maybe compromised": revoke the QR card too.
                    'qr_login_token'            => null,
                    'qr_login_token_expires_at' => null,
                ])->setRememberToken(Str::random(60));

                $user->save();

                // Force logout on every device.
                $user->tokens()->delete();

                event(new PasswordReset($user));
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return response()->json(['message' => 'Your password has been reset successfully.'], 200);
        }

        return response()->json(['message' => __($status)], 422);
    }

    /* ---------
     | Helpers
     * -------- */

    /** Build the standard auth response (user + Bearer token) and record last login. */
    private function tokenResponse(User $user, string $message, string $tokenName, int $status = 200)
    {
        // Registration has no last-login yet; login paths do.
        if ($status === 200) {
            $user->update(['last_login_at' => now()]);
        }

        $user->load('role', 'member', 'librarian');
        $expiresAt = $this->tokenExpiry($user);

        return response()->json([
            'message'        => $message,
            'user'           => $user,
            'account_notice' => $this->accountStatus->loginNotice($user),
            'token_type'     => 'Bearer',
            'access_token'   => $user->createToken($tokenName, ['*'], $expiresAt)->plainTextToken,
            'expires_at'     => $expiresAt,
        ], $status);
    }

    /** A2: members stay logged in for N days, staff for one shift (N hours). */
    private function tokenExpiry(User $user)
    {
        // A kiosk tablet stays logged in for months; admins can reset it any time.
        if ($user->role?->name === 'kiosk') {
            return now()->addDays(VisitSettings::kioskDays());
        }

        return in_array($user->role?->name, ['admin', 'librarian'], true)
            ? now()->addHours(AuthSettings::sessionStaffHours())
            : now()->addDays(AuthSettings::sessionMemberDays());
    }

    /** Find a user by email, student ID or membership number (B2.4). */
    private function findByIdentifier(string $identifier): ?User
    {
        if ($identifier === '') {
            return null;
        }

        if (str_contains($identifier, '@')) {
            return User::whereRaw('LOWER(email) = ?', [strtolower($identifier)])->first();
        }

        $member = Member::with('user')
            ->where('student_id', $identifier)
            ->orWhereRaw('UPPER(membership_no) = ?', [strtoupper($identifier)])
            ->first();

        return $member?->user;
    }

    /** Hash a raw QR token with SHA-256 so only the hash is stored. */
    private function hashQrToken(string $rawToken): string
    {
        return hash('sha256', $rawToken);
    }

    /** Generate a unique membership number like MEM-2026-000123. */
    private function generateMembershipNo(): string
    {
        do {
            $no = 'MEM-' . date('Y') . '-' . str_pad(random_int(1, 999999), 6, '0', STR_PAD_LEFT);
        } while (Member::where('membership_no', $no)->exists());

        return $no;
    }

    /** Generate a unique librarian employee ID like EMP-2026-0042. */
    private function generateEmployeeId(): string
    {
        do {
            $no = 'EMP-' . date('Y') . '-' . str_pad(random_int(1, 9999), 4, '0', STR_PAD_LEFT);
        } while (Librarian::where('employee_id', $no)->exists());

        return $no;
    }
}
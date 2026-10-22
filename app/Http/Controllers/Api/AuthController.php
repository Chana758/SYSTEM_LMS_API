<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\QrLoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Models\Librarian;
use App\Models\Member;
use App\Models\Role;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    private const QR_TOKEN_LIFETIME_DAYS = 90;

    /*
     | Registration & login
     */

    /** Register a new member account (user + member profile) and return a token. */
    public function register(RegisterRequest $request)
    {
        $validated = $request->validated();
        $memberRole = Role::firstOrCreate(['name' => 'member']);

        $user = DB::transaction(function () use ($validated, $memberRole) {
            $user = User::create([
                'name'     => $validated['name'],
                'email'    => $validated['email'],
                'password' => Hash::make($validated['password']),
                'phone'    => $validated['phone'] ?? null,
                'role_id'  => $memberRole->id,
                'status'   => 'active',
            ]);

            Member::create([
                'user_id'          => $user->id,
                'membership_no'    => $this->generateMembershipNo(),
                'membership_type'  => $validated['membership_type'] ?? 'student',
                'max_borrow_limit' => 3,
                'join_date'        => now(),
                'status'           => 'active',
            ]);

            return $user;
        });

        return $this->tokenResponse($user, 'User registered successfully', 'auth_token', 201);
    }

    /** Log in with email and password; only active accounts get a token. */
    public function login(LoginRequest $request)
    {
        $credentials = $request->validated();

        $user = User::where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            return response()->json(['message' => 'Invalid email or password'], 401);
        }

        if ($user->status !== 'active') {
            return response()->json(['message' => 'Your account is not active (inactive/suspended)'], 403);
        }

        return $this->tokenResponse($user, 'Login successful', 'auth_token');
    }

    /** Log in by scanning a QR card; rejects unknown, expired, or inactive accounts. */
    public function loginWithQr(QrLoginRequest $request)
    {
        $hashedToken = $this->hashQrToken($request->validated()['qr_token']);
        $user = User::where('qr_login_token', $hashedToken)->first();

        if (! $user) {
            return response()->json(['message' => 'This QR code is invalid or no longer recognized.'], 401);
        }

        if (! $user->qr_login_token_expires_at || $user->qr_login_token_expires_at->isPast()) {
            return response()->json(['message' => 'This QR code has expired. Please request a new one.'], 401);
        }

        if ($user->status !== 'active') {
            return response()->json(['message' => 'Your account is not active (inactive/suspended)'], 403);
        }

        return $this->tokenResponse($user, 'Login successful', 'auth_token_qr');
    }

    /** Log out by deleting only the current access token. */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logout successfully'], 200);
    }

    /** Return the logged-in user with role, member and librarian profiles. */
    public function me(Request $request)
    {
        return response()->json([
            'user' => $request->user()->load('role', 'member', 'librarian'),
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

        $user->forceFill(['password' => Hash::make($request->password)])->save();

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

        if (in_array($status, [Password::RESET_LINK_SENT, Password::INVALID_USER], true)) {
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

        return response()->json([
            'message'      => $message,
            'user'         => $user->load('role', 'member', 'librarian'),
            'token_type'   => 'Bearer',
            'access_token' => $user->createToken($tokenName)->plainTextToken,
        ], $status);
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
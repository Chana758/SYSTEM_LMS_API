<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

class AuthController extends Controller
{
    private const QR_TOKEN_LIFETIME_DAYS = 90;

    public function register(RegisterRequest $request)
    {
        $validated = $request->validated();

        $memberRole = Role::firstOrCreate(['name' => 'member']);

        $user = DB::transaction(function () use ($validated, $memberRole) {
            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
                'phone' => $validated['phone'] ?? null,
                'role_id' => $memberRole->id,
                'status' => 'active',
            ]);

            Member::create([
                'user_id' => $user->id,
                'membership_no' => $this->generateMembershipNo(),
                'membership_type' => $validated['membership_type'] ?? 'student',
                'max_borrow_limit' => 3,
                'join_date' => now(),
                'status' => 'active',
            ]);

            return $user;
        });

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'User registered successfully',
            'user' => $user->load('role', 'member', 'librarian'),
            'token_type' => 'Bearer',
            'access_token' => $token,
        ], 201);
    }

    public function login(LoginRequest $request)
    {
        $credentials = $request->validated();

        if (! Auth::attempt($credentials)) {
            return response()->json(['message' => 'Invalid email or password'], 401);
        }

        $user = User::where('email', $credentials['email'])->firstOrFail();

        if ($user->status !== 'active') {
            return response()->json(['message' => 'Your account is not active (inactive/suspended)'], 403);
        }

        $user->update(['last_login_at' => now()]);

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Login successful',
            'user' => $user->load('role', 'member', 'librarian'),
            'token_type' => 'Bearer',
            'access_token' => $token,
        ], 200);
    }

    public function loginWithQr(QrLoginRequest $request)
    {
        $validated = $request->validated();
        $hashedToken = $this->hashQrToken($validated['qr_token']);
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

        $user->update(['last_login_at' => now()]);
        $token = $user->createToken('auth_token_qr')->plainTextToken;

        return response()->json([
            'message' => 'Login successful',
            'user' => $user->load('role', 'member', 'librarian'),
            'token_type' => 'Bearer',
            'access_token' => $token,
        ], 200);
    }

    /**
     * REMOVED — public function generateQrToken(Request $request)
     *
     * This used to let ANY authenticated user issue themselves a brand
     * new QR login card with no staff involvement at all — the route
     * had no role middleware. That directly bypassed the Card Approvals
     * Queue: a self-registered member could call this endpoint straight
     * from the browser console and get a working password-bypass card
     * that no one ever reviewed or recorded as "issued".
     *
     * Card issuance (first-time AND lost/reissue) now only happens
     * through generateQrTokenFor() below, which is admin/librarian-only
     * and requires the staff member to act on a specific user — giving
     * every card an actor, a timestamp, and (for first-time cards) a
     * prior appearance in pendingCards().
     *
     * If you need this route to still exist for backward compatibility,
     * do NOT just restore the old body — at minimum wrap it with the
     * same role check as generateQrTokenFor() and require it to target
     * $request->user()->id only (i.e. staff re-issuing their own card),
     * never a bare self-service action available to members.
     */

    /**
     * POST /auth/users/{user}/qr-token/generate
     * Admin/Librarian only (enforced by route middleware AND here, since
     * this is the only path that may create/replace a QR card for
     * anyone, including staff acting on their own account).
     */
    public function generateQrTokenFor(Request $request, User $user)
    {
        $actor = $request->user();

        if ($actor->role->name === 'librarian' && $user->role->name !== 'member') {
            return response()->json([
                'message' => 'Librarians can only issue QR cards for members.',
            ], 403);
        }

        $rawToken = Str::random(48);

        $user->update([
            'qr_login_token' => $this->hashQrToken($rawToken),
            'qr_login_token_expires_at' => now()->addDays(self::QR_TOKEN_LIFETIME_DAYS),
        ]);

        return response()->json([
            'message' => 'QR login token generated successfully',
            'qr_token' => $rawToken,
            'expires_at' => $user->qr_login_token_expires_at,
            'user' => $user->only('id', 'name'),
        ], 200);
    }

    /**
     * GET /auth/pending-cards
     * Admin/Librarian only. Lists every member/librarian whose account
     * exists but has NO active QR card yet (has_qr_card = false) —
     * primarily self-registered members from RegisterPage.vue, since
     * CreateMemberModal.vue/CreateLibrarianModal.vue already issue a
     * card immediately as part of the admin/librarian's own action.
     *
     * This is the "approval queue" the front desk works through: for
     * each row here, staff click "Issue Card" (which calls
     * generateQrTokenFor() above) to finally activate a printable QR
     * card for that person.
     *
     * Librarians only see members here (same scoping as
     * generateQrTokenFor()'s 403 rule) — they can never approve/issue
     * a card for another librarian or admin account.
     */
    public function pendingCards(Request $request)
    {
        $actor = $request->user();

        $query = User::with(['role', 'member', 'librarian'])
            ->whereNull('qr_login_token')
            ->whereHas('role', function ($q) use ($actor) {
                if ($actor->role->name === 'librarian') {
                    $q->where('name', 'member');
                } else {
                    $q->whereIn('name', ['member', 'librarian']);
                }
            });

        return response()->json(
            $query->latest()->paginate($request->get('per_page', 15))
        );
    }

    /**
     * NEW — GET /auth/qr-token/status
     * Any authenticated user — self only. Returns whether the caller
     * currently has an active QR card and, if so, when it expires.
     *
     * Deliberately does NOT return qr_login_token (it's a hash anyway,
     * not the raw token, but there's no reason to expose it at all) or
     * let the caller regenerate it — this is read-only status, matching
     * the "staff issues, user can only view/revoke" policy.
     */
    public function qrCardStatus(Request $request)
    {
        $user = $request->user();

        $hasCard = (bool) $user->qr_login_token
            && $user->qr_login_token_expires_at
            && $user->qr_login_token_expires_at->isFuture();

        return response()->json([
            'has_card' => $hasCard,
            'expires_at' => $hasCard ? $user->qr_login_token_expires_at : null,
        ]);
    }

    /**
     * POST /auth/qr-token/revoke
     * Any authenticated user — self only. Reporting a card lost/revoking
     * it yourself is fine to self-service; only *issuing* a replacement
     * requires staff (via generateQrTokenFor(), reached through the
     * Card Approvals Queue or the Member/Librarian detail page).
     */
    public function revokeQrToken(Request $request)
    {
        $request->user()->update([
            'qr_login_token' => null,
            'qr_login_token_expires_at' => null,
        ]);

        return response()->json(['message' => 'QR login token revoked successfully'], 200);
    }

    private function hashQrToken(string $rawToken): string
    {
        return hash('sha256', $rawToken);
    }

    public function logout()
    {
        Auth()->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Logout successfully'], 200);
    }

    public function me()
    {
        return response()->json(['user' => Auth()->user()->load('role', 'member', 'librarian')], 200);
    }

    public function createLibrarian(RegisterRequest $request)
    {
        $validated = $request->validated();
        $librarianRole = Role::firstOrCreate(['name' => 'librarian']);

        $user = DB::transaction(function () use ($validated, $librarianRole) {
            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
                'phone' => $validated['phone'] ?? null,
                'role_id' => $librarianRole->id,
                'status' => 'active',
            ]);

            Librarian::create([
                'user_id' => $user->id,
                'employee_id' => $this->generateEmployeeId(),
                'status' => 'active',
            ]);

            return $user;
        });

        return response()->json([
            'message' => 'Librarian created successfully',
            'user' => $user->load('role', 'librarian'),
        ], 201);
    }

    private function generateMembershipNo(): string
    {
        do {
            $no = 'MEM-' . date('Y') . '-' . str_pad(random_int(1, 999999), 6, '0', STR_PAD_LEFT);
        } while (Member::where('membership_no', $no)->exists());
        return $no;
    }

    private function generateEmployeeId(): string
    {
        do {
            $no = 'EMP-' . date('Y') . '-' . str_pad(random_int(1, 9999), 4, '0', STR_PAD_LEFT);
        } while (Librarian::where('employee_id', $no)->exists());
        return $no;
    }

    public function changePassword(Request $request)
    {
        $request->validate([
            'current_password' => ['required'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)],
        ]);

        $user = $request->user();

        if (! Hash::check($request->current_password, $user->password)) {
            return response()->json([
                'message' => 'The provided password does not match your current password.',
                'errors' => ['current_password' => ['Current password is incorrect.']],
            ], 422);
        }

        $user->update(['password' => $request->password]);

        return response()->json(['message' => 'Password updated successfully.']);
    }

    /**
     * POST /auth/forgot-password
     * Public. Sends a password-reset email containing a signed token +
     * link to the given address, if an account with that email exists.
     * Always returns 200 with a generic message so this endpoint can't
     * be used to probe which emails are registered.
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

    /**
     * POST /auth/reset-password
     * Public. Called from ResetPasswordPage.vue with the token + email
     * pulled out of the reset-link URL, plus the new password.
     */
    public function resetPassword(ResetPasswordRequest $request)
    {
        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill([
                    'password' => Hash::make($password),
                ])->setRememberToken(Str::random(60));

                $user->save();

                // Revoke any existing QR login card too — a password
                // reset is a "something may be compromised" event, so we
                // don't want a still-valid physical/QR card left active.
                $user->update([
                    'qr_login_token' => null,
                    'qr_login_token_expires_at' => null,
                ]);

                event(new PasswordReset($user));
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return response()->json(['message' => 'Your password has been reset successfully.'], 200);
        }

        return response()->json(['message' => __($status)], 422);
    }
}
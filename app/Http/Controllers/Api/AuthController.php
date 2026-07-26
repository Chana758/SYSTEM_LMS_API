<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\QrLoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\Librarian;
use App\Models\Member;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

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

        // NOTE: no QR token is generated here anymore. Self-registered
        // users start with qr_login_token = null (has_qr_card = false)
        // and appear in pendingCards() below until an admin/librarian
        // issues their card via generateQrTokenFor(). This is the core
        // of the new "admin approves first" requirement — regardless of
        // whether an account was created by registering here or by
        // CreateMemberModal.vue, the card itself is only ever issued
        // through generateQrToken()/generateQrTokenFor(), and the ONLY
        // path that used to bypass staff involvement (auto-issuing right
        // after self-registration) has been removed on the frontend
        // (see RegisterPage.vue).
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

    public function generateQrToken(Request $request)
    {
        $user = $request->user();
        $rawToken = Str::random(48);

        $user->update([
            'qr_login_token' => $this->hashQrToken($rawToken),
            'qr_login_token_expires_at' => now()->addDays(self::QR_TOKEN_LIFETIME_DAYS),
        ]);

        return response()->json([
            'message' => 'QR login token generated successfully',
            'qr_token' => $rawToken,
            'expires_at' => $user->qr_login_token_expires_at,
        ], 200);
    }

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
     * NEW — GET /auth/pending-cards
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
            'password' => ['required', 'confirmed', Password::min(8)],
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
}
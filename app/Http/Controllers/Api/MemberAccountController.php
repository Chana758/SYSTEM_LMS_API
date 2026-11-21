<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\MembershipType;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Concerns\ToArray;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Block 1 staff tools (admin, librarian):
 * B2.3 approve/reject new registrations, B2.5 temporary password reset,
 * B2.1 import students/teachers from Excel.
 */
class MemberAccountController extends Controller
{
    /** GET /member-accounts/pending: registrations waiting for approval. */
    public function pending(Request $request)
    {
        $members = Member::with('user')
            ->whereHas('user', fn ($q) => $q->where('status', 'pending'))
            ->oldest()
            ->paginate($request->input('per_page', 15));

        return response()->json($members);
    }

    /** POST /member-accounts/{member}/approve: staff checked the ID card and chose the type. */
    public function approve(Request $request, Member $member)
    {
        $data = $request->validate([
            'membership_type' => ['required', 'string', Rule::exists('membership_types', 'code')->where('is_active', true)],
            'student_id'      => ['nullable', 'string', 'max:30', Rule::unique('members', 'student_id')->ignore($member->id)],
            'expiry_date'     => ['nullable', 'date', 'after:today'],
        ]);

        if ($member->user?->status !== 'pending') {
            return response()->json(['message' => __('account.NOT_PENDING')], 422);
        }

        $type = MembershipType::where('code', $data['membership_type'])->first();

        DB::transaction(function () use ($member, $data, $type) {
            $member->update(array_filter([
                'membership_type'  => $type->code,
                'max_borrow_limit' => $type->max_books,
                'student_id'       => $data['student_id'] ?? null,
                'expiry_date'      => $data['expiry_date'] ?? null,
                'status'           => 'active',
            ], fn ($v) => ! is_null($v)));

            $member->user->forceFill(['status' => 'active'])->save();
        });

        return response()->json([
            'message' => __('account.APPROVED'),
            'member'  => $member->fresh()->load('user'),
        ]);
    }

    /** POST /member-accounts/{member}/reject: remove a registration that can't be verified. */
    public function reject(Request $request, Member $member)
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        if ($member->user?->status !== 'pending') {
            return response()->json(['message' => __('account.NOT_PENDING')], 422);
        }

        DB::transaction(function () use ($member, $data) {
            $member->update(['remarks' => trim('Rejected: ' . ($data['reason'] ?? ''))]);
            $member->user->tokens()->delete();
            $member->delete();
            $member->user->delete();
        });

        return response()->json(['message' => __('account.REJECTED')]);
    }

    /**
     * POST /auth/users/{user}/reset-credentials (B2.5): make a temporary password,
     * clear the PIN, log out every device, and force a password change at next login.
     */
    public function resetCredentials(Request $request, User $user)
    {
        if ($request->user()->role?->name === 'librarian' && $user->role?->name !== 'member') {
            return response()->json(['message' => __('account.RESET_ONLY_MEMBERS')], 403);
        }

        $temporary = $this->temporaryPassword();

        $user->forceFill([
            'password'             => Hash::make($temporary),
            'must_change_password' => true,
            'pin'                  => null,
            'pin_failed_attempts'  => 0,
            'pin_locked_until'     => null,
            // A staff-reset imported account becomes usable straight away.
            'status'               => $user->status === 'inactive' ? 'active' : $user->status,
        ])->save();

        $user->tokens()->delete();

        return response()->json([
            'message'            => __('account.CREDENTIALS_RESET'),
            'temporary_password' => $temporary, // shown once, never stored in plain text
            'login'              => $user->member?->student_id ?? $user->email,
        ]);
    }

    /**
     * POST /member-accounts/import (B2.1): Excel/CSV with columns
     * student_id, name, class_name, date_of_birth, membership_type, phone, email.
     * dry_run=1 only checks the rows and returns errors, so staff can fix the file first.
     */
    public function import(Request $request)
    {
        $request->validate([
            'file'    => ['required', 'file', 'mimes:xlsx,xls,csv,txt', 'max:5120'],
            'dry_run' => ['nullable', 'boolean'],
        ]);

        $sheet = Excel::toArray(new class implements ToArray, WithHeadingRow {
            public function array(array $array): void
            {
            }
        }, $request->file('file'))[0] ?? [];

        $types = MembershipType::where('is_active', true)->pluck('max_books', 'code');
        $seenIds = [];
        $rows = [];

        foreach ($sheet as $index => $raw) {
            $row = $this->normalizeRow($raw);

            if ($row['student_id'] === '' && $row['name'] === '') {
                continue; // empty line
            }

            $errors = [];

            if ($row['student_id'] === '') {
                $errors[] = 'student_id is required';
            } elseif (isset($seenIds[$row['student_id']])) {
                $errors[] = 'student_id appears twice in the file';
            } elseif (Member::withTrashed()->where('student_id', $row['student_id'])->exists()) {
                $errors[] = 'student_id already exists';
            }

            if ($row['name'] === '') {
                $errors[] = 'name is required';
            }

            if (! $row['date_of_birth']) {
                $errors[] = 'date_of_birth is missing or not a date';
            }

            if (! $types->has($row['membership_type'])) {
                $errors[] = "membership_type \"{$row['membership_type']}\" does not exist";
            }

            if ($row['email'] !== '') {
                if (! filter_var($row['email'], FILTER_VALIDATE_EMAIL)) {
                    $errors[] = 'email is not valid';
                } elseif (User::withTrashed()->whereRaw('LOWER(email) = ?', [$row['email']])->exists()) {
                    $errors[] = 'email already exists';
                }
            }

            $seenIds[$row['student_id']] = true;
            $rows[] = ['line' => $index + 2, 'data' => $row, 'errors' => $errors];
        }

        $invalid = array_values(array_filter($rows, fn ($r) => $r['errors']));

        if ($request->boolean('dry_run') || $invalid) {
            return response()->json([
                'total'   => count($rows),
                'valid'   => count($rows) - count($invalid),
                'invalid' => $invalid,
                'preview' => array_slice(array_map(fn ($r) => $r['data'], $rows), 0, 20),
                'imported'=> 0,
            ], $invalid && ! $request->boolean('dry_run') ? 422 : 200);
        }

        $memberRole = Role::firstOrCreate(['name' => 'member']);

        DB::transaction(function () use ($rows, $memberRole, $types) {
            foreach ($rows as $r) {
                $d = $r['data'];

                // Status "inactive": the student activates it with student ID + date of birth (B2.2).
                $user = User::create([
                    'name'     => $d['name'],
                    'email'    => $d['email'] ?: null,
                    'password' => Hash::make(Str::random(40)),
                    'phone'    => $d['phone'] ?: null,
                    'role_id'  => $memberRole->id,
                    'status'   => 'inactive',
                ]);

                Member::create([
                    'user_id'          => $user->id,
                    'membership_no'    => $this->generateMembershipNo(),
                    'student_id'       => $d['student_id'],
                    'date_of_birth'    => $d['date_of_birth'],
                    'class_name'       => $d['class_name'] ?: null,
                    'membership_type'  => $d['membership_type'],
                    'max_borrow_limit' => $types[$d['membership_type']],
                    'join_date'        => now(),
                    'status'           => 'active',
                ]);
            }
        });

        return response()->json([
            'message'  => __('account.IMPORT_DONE', ['count' => count($rows)]),
            'total'    => count($rows),
            'valid'    => count($rows),
            'invalid'  => [],
            'imported' => count($rows),
        ], 201);
    }

    /** Clean one sheet row: trim text, lower-case email/type, read Excel or text dates. */
    private function normalizeRow(array $raw): array
    {
        $get = fn (string $key) => trim((string) ($raw[$key] ?? ''));

        return [
            'student_id'      => $get('student_id'),
            'name'            => $get('name'),
            'class_name'      => $get('class_name'),
            'date_of_birth'   => $this->parseDate($raw['date_of_birth'] ?? null),
            'membership_type' => strtolower($get('membership_type')) ?: 'student',
            'phone'           => $get('phone'),
            'email'           => strtolower($get('email')),
        ];
    }

    private function parseDate($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            if (is_numeric($value)) {
                return Carbon::instance(ExcelDate::excelToDateTimeObject((float) $value))->toDateString();
            }

            foreach (['d/m/Y', 'Y-m-d', 'd-m-Y', 'm/d/Y'] as $format) {
                $date = Carbon::createFromFormat('!' . $format, trim($value));
                if ($date && $date->format($format) === trim($value)) {
                    return $date->toDateString();
                }
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }

    /** 10 characters, no look-alike letters, so it is easy to read out at the desk. */
    private function temporaryPassword(): string
    {
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';
        $out = '';
        for ($i = 0; $i < 10; $i++) {
            $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $out;
    }

    private function generateMembershipNo(): string
    {
        do {
            $no = 'MEM-' . date('Y') . '-' . str_pad(random_int(1, 999999), 6, '0', STR_PAD_LEFT);
        } while (Member::withTrashed()->where('membership_no', $no)->exists());

        return $no;
    }
}

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
            // B6.2: staff took the deposit in cash while approving
            'deposit_paid_cash' => ['nullable', 'boolean'],
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

        // B6.2: external (or any type with a deposit) must pay before borrowing.
        $deposits = app(\App\Services\DepositService::class);
        $member = $deposits->require($member->fresh());
        if ($request->boolean('deposit_paid_cash') && $member->deposit_status === 'unpaid') {
            $deposits->recordCash($member, $request->user());
        }

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

        // A remote database (Neon) costs a round trip per query, so look up
        // existing IDs and emails once for the whole file, not once per row.
        @set_time_limit(300);

        $types = MembershipType::where('is_active', true)->pluck('max_books', 'code');
        $normalized = array_map(fn ($raw) => $this->normalizeRow($raw), $sheet);
        $fileIds = array_values(array_filter(array_map(fn ($r) => strtoupper($r['student_id']), $normalized)));
        $fileEmails = array_values(array_filter(array_map(fn ($r) => $r['email'], $normalized)));
        $takenIds = $fileIds
            ? Member::withTrashed()->whereIn(DB::raw('UPPER(student_id)'), $fileIds)->pluck('student_id')
                ->map(fn ($id) => strtoupper($id))->flip()->all()
            : [];
        $takenEmails = $fileEmails
            ? User::withTrashed()->whereIn(DB::raw('LOWER(email)'), $fileEmails)->pluck('email')
                ->map(fn ($e) => strtolower($e))->flip()->all()
            : [];
        $seenIds = [];
        $rows = [];

        foreach ($normalized as $index => $row) {
            if ($row['student_id'] === '' && $row['name'] === '') {
                continue; // empty line
            }

            $errors = [];

            if ($row['student_id'] === '') {
                $errors[] = 'student_id is required';
            } elseif (isset($seenIds[$row['student_id']])) {
                $errors[] = 'student_id appears twice in the file';
            } elseif (isset($takenIds[strtoupper($row['student_id'])])) {
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
                } elseif (isset($takenEmails[$row['email']])) {
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

        // Imported accounts cannot log in until the student activates (B2.2), so one
        // hash of a random secret serves every row; bcrypt per row would cost seconds.
        $lockedPassword = Hash::make(Str::random(40));
        $numbers = $this->generateMembershipNos(count($rows));

        DB::transaction(function () use ($rows, $memberRole, $types, $lockedPassword, $numbers) {
            foreach ($rows as $i => $r) {
                $d = $r['data'];

                // Status "inactive": the student activates it with student ID + date of birth (B2.2).
                $user = User::create([
                    'name'     => $d['name'],
                    'email'    => $d['email'] ?: null,
                    'password' => $lockedPassword,
                    'phone'    => $d['phone'] ?: null,
                    'role_id'  => $memberRole->id,
                    'status'   => 'inactive',
                ]);

                Member::create([
                    'user_id'          => $user->id,
                    'membership_no'    => $numbers[$i],
                    'student_id'       => $d['student_id'],
                    'name_en'          => $d['name_en'] ?: null,
                    'gender'           => $d['gender'],
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
            'name_en'         => $get('name_en'),
            'gender'          => $this->parseGender($get('gender')),
            'class_name'      => $get('class_name'),
            'date_of_birth'   => $this->parseDate($raw['date_of_birth'] ?? null),
            'membership_type' => strtolower($get('membership_type')) ?: 'student',
            'phone'           => $get('phone'),
            'email'           => strtolower($get('email')),
        ];
    }

    /** ប្រុស/ស្រី, M/F, male/female, boy/girl -> male | female | null */
    private function parseGender(string $value): ?string
    {
        $v = mb_strtolower(trim($value));

        return match (true) {
            in_array($v, ['ប្រុស', 'm', 'male', 'boy', 'b'], true)    => 'male',
            in_array($v, ['ស្រី', 'f', 'female', 'girl', 'g'], true) => 'female',
            default                                                   => null,
        };
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

    /** $count unique membership numbers, checked against the database in one query. */
    private function generateMembershipNos(int $count): array
    {
        $numbers = [];
        while (count($numbers) < $count) {
            $batch = [];
            while (count($batch) + count($numbers) < $count) {
                $no = 'MEM-' . date('Y') . '-' . str_pad(random_int(1, 999999), 6, '0', STR_PAD_LEFT);
                if (! isset($numbers[$no])) {
                    $batch[$no] = true;
                }
            }
            $taken = Member::withTrashed()->whereIn('membership_no', array_keys($batch))->pluck('membership_no')->flip();
            foreach (array_keys($batch) as $no) {
                if (! isset($taken[$no])) {
                    $numbers[$no] = true;
                }
            }
        }

        return array_keys($numbers);
    }

    private function generateMembershipNo(): string
    {
        do {
            $no = 'MEM-' . date('Y') . '-' . str_pad(random_int(1, 999999), 6, '0', STR_PAD_LEFT);
        } while (Member::withTrashed()->where('membership_no', $no)->exists());

        return $no;
    }
}

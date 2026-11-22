<?php

namespace App\Services;

use App\Models\BookCopy;
use App\Models\BorrowTransaction;
use App\Models\KioskDevice;
use App\Models\Member;
use App\Models\MembershipType;
use App\Models\Notification;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * B4.16/17: a member borrows alone at the self-checkout kiosk.
 *
 * 1. start():  card + 4-digit PIN  -> a short session (3 min, renewed on every book)
 * 2. borrow(): one scanned copy    -> a loan, with the same rules as the desk (LoanPolicy)
 * 3. finish(): the receipt
 *
 * Only membership types with "self_checkout" may use it (students/teachers by default,
 * externals go to the desk). Five wrong PINs lock the PIN for 15 minutes.
 */
class SelfCheckoutService
{
    private const SESSION_SECONDS = 180;
    private const MAX_PIN_TRIES   = 5;
    private const LOCK_MINUTES    = 15;

    public function __construct(
        private MemberCardService $cards,
        private LoanPolicy $policy,
    ) {}

    /** @return array{ok:bool,status:int,body:array} */
    public function start(KioskDevice $device, string $code, string $pin): array
    {
        $card = $this->cards->resolve($code);
        if (isset($card['error'])) {
            return $this->fail($card['error'], __('visits.' . $card['error']));
        }

        /** @var User $user */
        $user = $card['user'];
        $member = $user->member;

        if ($user->role?->name !== 'member' || ! $member) {
            return $this->fail('SELF_NOT_MEMBER', __('loan.SELF_NOT_MEMBER'));
        }

        $type = MembershipType::where('code', $member->membership_type)->first();
        if (! $type?->self_checkout) {
            return $this->fail('SELF_NOT_ALLOWED', __('loan.SELF_NOT_ALLOWED', ['type' => $type?->label ?? $member->membership_type]));
        }

        if (! $user->pin) {
            return $this->fail('SELF_NO_PIN', __('loan.SELF_NO_PIN'));
        }

        if ($user->pin_locked_until && $user->pin_locked_until->isFuture()) {
            return $this->fail('SELF_PIN_LOCKED', __('loan.SELF_PIN_LOCKED', ['minutes' => (int) ceil(now()->diffInSeconds($user->pin_locked_until) / 60)]), 423);
        }

        if (! Hash::check($pin, $user->pin)) {
            $tries = (int) $user->pin_failed_attempts + 1;
            if ($tries >= self::MAX_PIN_TRIES) {
                $user->forceFill(['pin_failed_attempts' => 0, 'pin_locked_until' => now()->addMinutes(self::LOCK_MINUTES)])->save();
                LoginLogger::record('self_checkout_pin_locked', $user, false, 'SELF_PIN_LOCKED', null, $device->name);

                return $this->fail('SELF_PIN_LOCKED', __('loan.SELF_PIN_LOCKED', ['minutes' => self::LOCK_MINUTES]), 423);
            }
            $user->forceFill(['pin_failed_attempts' => $tries])->save();

            return $this->fail('SELF_PIN_WRONG', __('loan.SELF_PIN_WRONG', ['left' => self::MAX_PIN_TRIES - $tries]));
        }

        if ($user->pin_failed_attempts || $user->pin_locked_until) {
            $user->forceFill(['pin_failed_attempts' => 0, 'pin_locked_until' => null])->save();
        }

        $summary = $this->policy->summary($member);
        if ($summary['refusal']) {
            return $this->fail($summary['refusal']['code'], $summary['refusal']['message']);
        }

        $token = Str::random(40);
        Cache::put($this->key($token), [
            'member_id' => $member->id,
            'device_id' => $device->id,
            'borrowed'  => [],
        ], self::SESSION_SECONDS);

        LoginLogger::record('self_checkout_start', $user, true, null, null, $device->name);

        return ['ok' => true, 'status' => 200, 'body' => [
            'token'      => $token,
            'expires_in' => self::SESSION_SECONDS,
            'person'     => [
                'name'          => $user->name,
                'name_en'       => $member->name_en,
                'avatar'        => $user->avatar,
                'membership_no' => $member->membership_no,
                'type'          => $member->membership_type,
            ],
            'summary'    => [
                'active_loans' => $summary['active_loans'],
                'max_books'    => $summary['max_books'],
                'loan_days'    => $summary['loan_days'],
                'can_borrow'   => max(0, $summary['max_books'] - $summary['active_loans']),
            ],
        ]];
    }

    public function borrow(KioskDevice $device, string $token, string $barcode): array
    {
        $session = Cache::get($this->key($token));
        if (! $session || $session['device_id'] !== $device->id) {
            return $this->fail('SELF_SESSION_EXPIRED', __('loan.SELF_SESSION_EXPIRED'), 410);
        }

        $barcode = trim($barcode);
        $copyId = BookCopy::where('barcode', $barcode)->value('id')
            ?? BookCopy::whereRaw('UPPER(barcode) = ?', [strtoupper($barcode)])->value('id');

        if (! $copyId) {
            return $this->fail('BOOK_NOT_FOUND', __('loan.SELF_BOOK_NOT_FOUND'));
        }

        $result = DB::transaction(function () use ($session, $copyId) {
            $member = Member::with('user')->lockForUpdate()->findOrFail($session['member_id']);

            if ($refusal = $this->policy->refusal($member)) {
                return $this->fail($refusal['code'], $refusal['message']);
            }

            $copy = BookCopy::with('book')->lockForUpdate()->findOrFail($copyId);

            if ($copy->status !== 'available') {
                return $this->fail('COPY_NOT_AVAILABLE', __('loan.SELF_COPY_NOT_AVAILABLE', ['title' => $copy->book?->title]));
            }

            $alreadyHas = BorrowTransaction::where('member_id', $member->id)
                ->whereIn('status', ['borrowed', 'overdue'])
                ->whereHas('bookCopy', fn ($q) => $q->where('book_id', $copy->book_id))
                ->exists();
            if ($alreadyHas) {
                return $this->fail('SELF_ALREADY_HAS', __('loan.SELF_ALREADY_HAS', ['title' => $copy->book?->title]));
            }

            $own = Reservation::where('book_id', $copy->book_id)->where('member_id', $member->id)->where('status', 'ready')->first();
            $heldForOther = Reservation::where('book_id', $copy->book_id)->where('status', 'ready')->where('member_id', '!=', $member->id)->exists();
            if ($heldForOther && ! $own) {
                return $this->fail('SELF_RESERVED', __('loan.SELF_RESERVED', ['title' => $copy->book?->title]));
            }

            $days = (int) $member->loanRules()['loan_days'];

            $borrow = BorrowTransaction::create([
                'member_id'     => $member->id,
                'book_copy_id'  => $copy->id,
                'librarian_id'  => null,
                'borrow_date'   => now(),
                'due_date'      => now()->addDays($days),
                'status'        => 'borrowed',
                'renewed_count' => 0,
                'notes'         => 'self_checkout',
            ]);

            $copy->update(['status' => 'borrowed']);
            $copy->book()->decrement('available_qty');
            $own?->update(['status' => 'fulfilled']);

            Notification::create([
                'user_id' => $member->user_id,
                'title'   => 'Book Borrowed Successfully',
                'message' => "You borrowed \"{$copy->book?->title}\" at the self-checkout kiosk. Due date: " . Carbon::parse($borrow->due_date)->format('M d, Y'),
                'type'    => 'system',
                'link'    => "/my-borrows/{$borrow->id}",
                'is_read' => false,
                'sent_at' => now(),
            ]);

            return ['ok' => true, 'status' => 201, 'body' => [
                'item' => [
                    'borrow_id'   => $borrow->id,
                    'title'       => $copy->book?->title,
                    'author'      => $copy->book?->author,
                    'cover_image' => $copy->book?->cover_image,
                    'barcode'     => $copy->barcode,
                    'due_date'    => Carbon::parse($borrow->due_date)->toDateString(),
                ],
                'summary' => $this->policy->summary($member->fresh()),
            ]];
        });

        if ($result['ok']) {
            $session['borrowed'][] = $result['body']['item'];
        }
        // Every scan (ok or not) keeps the session alive.
        Cache::put($this->key($token), $session, self::SESSION_SECONDS);

        if ($result['ok']) {
            $s = $result['body']['summary'];
            $result['body']['summary'] = [
                'active_loans' => $s['active_loans'],
                'max_books'    => $s['max_books'],
                'loan_days'    => $s['loan_days'],
                'can_borrow'   => max(0, $s['max_books'] - $s['active_loans']),
            ];
            $result['body']['message'] = __('loan.SELF_BORROWED', ['title' => $result['body']['item']['title']]);
        }

        return $result;
    }

    public function finish(KioskDevice $device, string $token): array
    {
        $session = Cache::pull($this->key($token));
        if (! $session || $session['device_id'] !== $device->id) {
            return $this->fail('SELF_SESSION_EXPIRED', __('loan.SELF_SESSION_EXPIRED'), 410);
        }

        return ['ok' => true, 'status' => 200, 'body' => [
            'borrowed' => $session['borrowed'],
            'message'  => count($session['borrowed'])
                ? __('loan.SELF_DONE', ['count' => count($session['borrowed'])])
                : __('loan.SELF_DONE_NONE'),
        ]];
    }

    /** Loans this member took out today (for the exit kiosk). */
    public function borrowedToday(?Member $member): array
    {
        if (! $member) {
            return [];
        }

        return BorrowTransaction::with('bookCopy.book:id,title,author,cover_image')
            ->where('member_id', $member->id)
            ->whereIn('status', ['borrowed', 'overdue'])
            ->whereDate('borrow_date', today())
            ->latest('id')
            ->limit(10)
            ->get()
            ->map(fn ($b) => [
                'title'       => $b->bookCopy?->book?->title,
                'author'      => $b->bookCopy?->book?->author,
                'cover_image' => $b->bookCopy?->book?->cover_image,
                'barcode'     => $b->bookCopy?->barcode,
                'due_date'    => Carbon::parse($b->due_date)->toDateString(),
                'self'        => $b->notes === 'self_checkout',
            ])->values()->all();
    }

    private function fail(string $code, string $message, int $status = 422): array
    {
        return ['ok' => false, 'status' => $status, 'body' => ['code' => $code, 'message' => $message]];
    }

    private function key(string $token): string
    {
        return 'self_checkout:' . hash('sha256', $token);
    }
}

<?php

namespace App\Services;

use App\Models\Member;
use App\Models\User;
use App\Support\VisitSettings;

/**
 * Reads what a kiosk scanned. Two kinds of code are accepted:
 *  - printed card: the 48-character token issued by staff (only its SHA-256 is stored);
 *  - digital card: "SNX1.<user id>.<expires unix>.<signature>", shown in the app and
 *    renewed every minute, so a photo or screenshot stops working quickly;
 *  - school ID card (when turned on in settings): the QR the school already prints,
 *    holding the student ID as plain text or inside a link. Anyone who knows a student
 *    ID can copy it, so it is accepted for entry and desk loans (staff see the photo),
 *    never for logging in.
 */
class MemberCardService
{
    public const DIGITAL_PREFIX = 'SNX1';
    public const DIGITAL_LIFETIME = 60;   // seconds a digital code stays valid
    private const MAX_FUTURE = 120;       // reject codes that claim to live longer

    /** @return array{user: User, method: string}|array{error: string} */
    public function resolve(string $code): array
    {
        $code = trim($code);

        if (str_starts_with($code, self::DIGITAL_PREFIX . '.')) {
            return $this->resolveDigital($code);
        }

        $user = (strlen($code) >= 20 && strlen($code) <= 100)
            ? User::with('role', 'member')->where('qr_login_token', hash('sha256', $code))->first()
            : null;

        if (! $user) {
            return $this->resolveSchoolCard($code) ?? ['error' => 'CARD_UNKNOWN'];
        }

        if (! $user->qr_login_token_expires_at || $user->qr_login_token_expires_at->isPast()) {
            return ['error' => 'CARD_EXPIRED', 'user' => $user];
        }

        return ['user' => $user, 'method' => 'card'];
    }

    /**
     * The student ID inside a school card QR: plain "ST-2024-0012", or a link such as
     * "https://school.edu.kh/student/ST-2024-0012" or "...?student_id=ST-2024-0012".
     */
    public static function schoolId(string $code): ?string
    {
        $code = trim($code);

        if (preg_match('#^https?://#i', $code)) {
            $parts = parse_url($code) ?: [];
            parse_str($parts['query'] ?? '', $query);
            $code = (string) ($query['student_id'] ?? $query['sid'] ?? $query['id'] ?? '');
            if ($code === '') {
                $segments = array_values(array_filter(explode('/', $parts['path'] ?? '')));
                $code = (string) (end($segments) ?: '');
            }
            $code = rawurldecode($code);
        }

        return preg_match('/^[A-Za-z0-9][A-Za-z0-9._\-\/]{2,39}$/', $code) ? $code : null;
    }

    private function resolveSchoolCard(string $code): ?array
    {
        if (! VisitSettings::schoolCardEnabled() || ! ($id = self::schoolId($code))) {
            return null;
        }

        $member = Member::with('user.role', 'user.member')
            ->whereRaw('UPPER(student_id) = ?', [strtoupper($id)])
            ->first();

        return $member?->user ? ['user' => $member->user, 'method' => 'school_card'] : null;
    }

    /** A fresh digital code for the member's phone. */
    public function digitalCode(User $user): array
    {
        $expires = now()->addSeconds(self::DIGITAL_LIFETIME)->timestamp;
        $payload = self::DIGITAL_PREFIX . '.' . $user->id . '.' . $expires;

        return [
            'code'       => $payload . '.' . $this->sign($payload),
            'expires_at' => $expires,
            'lifetime'   => self::DIGITAL_LIFETIME,
        ];
    }

    private function resolveDigital(string $code): array
    {
        $parts = explode('.', $code);
        if (count($parts) !== 4 || ! ctype_digit($parts[1]) || ! ctype_digit($parts[2])) {
            return ['error' => 'CARD_UNKNOWN'];
        }

        [$prefix, $userId, $expires, $signature] = $parts;
        $payload = $prefix . '.' . $userId . '.' . $expires;

        if (! hash_equals($this->sign($payload), $signature)) {
            return ['error' => 'CARD_UNKNOWN'];
        }

        $now = now()->timestamp;
        if ((int) $expires < $now) {
            return ['error' => 'DIGITAL_CODE_EXPIRED'];
        }
        if ((int) $expires > $now + self::MAX_FUTURE) {
            return ['error' => 'CARD_UNKNOWN'];
        }

        $user = User::with('role', 'member')->find((int) $userId);

        return $user ? ['user' => $user, 'method' => 'digital_card'] : ['error' => 'CARD_UNKNOWN'];
    }

    private function sign(string $payload): string
    {
        return substr(hash_hmac('sha256', $payload, (string) config('app.key')), 0, 32);
    }
}

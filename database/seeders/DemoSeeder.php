<?php

namespace Database\Seeders;

use App\Models\Book;
use App\Models\BookCopy;
use App\Models\BorrowTransaction;
use App\Models\Category;
use App\Models\Fine;
use App\Models\Librarian;
use App\Models\Member;
use App\Models\Reservation;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\Demo\DemoChatSeeder;
use Database\Seeders\Demo\DemoEbookSeeder;
use Database\Seeders\Demo\DemoNotificationSeeder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DemoSeeder extends Seeder
{
    private const FINE_PER_DAY = 500;

    private const ISBN_PREFIX = 'DEMO-LMS-ISBN-';

    private const BARCODE_PREFIX = 'DEMO-LMS-COPY-';

    public function run(): void
    {
        $this->guardDatabase();

        $password = config('demo.password');

        if (! $password || strlen($password) < 8) {
            throw new \RuntimeException(
                'DEMO_PASSWORD must contain at least 8 characters.'
            );
        }

        DB::transaction(function () use ($password) {

            $this->call(MembershipTypeSeeder::class);

            // Restore existing roles or create new ones.
            $roles = [];

            foreach (['admin', 'librarian', 'member'] as $roleName) {
                $role = Role::withTrashed()
                    ->where('name', $roleName)
                    ->first();

                if ($role) {
                    if ($role->trashed()) {
                        $role->restore();
                    }
                } else {
                    $role = Role::create([
                        'name' => $roleName,
                    ]);
                }

                $roles[$roleName] = $role;
            }

            $this->seedAdmin($roles['admin'], $password);

            $librarian = $this->seedLibrarian(
                $roles['librarian'],
                $password
            );

            $members = $this->seedMembers(
                $roles['member'],
                $password
            );

            $books = $this->seedCatalog();

            $this->seedCirculation(
                $members,
                $librarian,
                $books
            );

            $this->seedReservations($members, $books);

            foreach ($books as $book) {
                $book->syncQty();
            }

            $this->call([
                DemoEbookSeeder::class,
                DemoNotificationSeeder::class,
                DemoChatSeeder::class,
            ]);
        });
    }

    // --------------------------------------------------
    // Database Guard
    // --------------------------------------------------

    private function guardDatabase(): void
    {
        $expectedDatabase = config('demo.database_name');

        $currentDatabase = DB::connection()->getDatabaseName();

        if (! config('demo.enabled')) {
            throw new \RuntimeException(
                'DEMO_MODE must be true.'
            );
        }

        if (! app()->environment('production')) {
            throw new \RuntimeException(
                'Demo Seeder is only allowed in production environment.'
            );
        }

        if (! $expectedDatabase) {
            throw new \RuntimeException(
                'DEMO_DATABASE_NAME is required.'
            );
        }

        if ($currentDatabase !== $expectedDatabase) {
            throw new \RuntimeException(
                "Wrong database. Expected {$expectedDatabase}, got {$currentDatabase}."
            );
        }
    }

    // --------------------------------------------------
    // Users
    // --------------------------------------------------

    private function upsertUser(
        string $name,
        string $email,
        Role $role,
        string $password
    ): User {
        $user = User::withTrashed()
            ->where('email', $email)
            ->first();

        if ($user && ! $user->is_demo) {
            throw new \RuntimeException(
                "Email belongs to a real user: {$email}"
            );
        }

        if (! $user) {
            $user = new User();
            $user->email = $email;
        }

        $user->fill([
            'name' => $name,
            'password' => Hash::make($password),
            'role_id' => $role->id,
            'status' => 'active',
            'language_preference' => 'en',
        ]);

        $user->is_demo = true;
        $user->deleted_at = null;
        $user->save();

        return $user;
    }

    private function seedAdmin(
        Role $role,
        string $password
    ): void {
        $domain = config('demo.email_domain');

        $this->upsertUser(
            'Demo Admin',
            "admin@{$domain}",
            $role,
            $password
        );
    }

    private function seedLibrarian(
        Role $role,
        string $password
    ): Librarian {
        $domain = config('demo.email_domain');

        $user = $this->upsertUser(
            'Demo Librarian',
            "librarian@{$domain}",
            $role,
            $password
        );

        $employeeId = 'DEMO-EMP-9001';

        $existing = Librarian::withTrashed()
            ->where('employee_id', $employeeId)
            ->first();

        if ($existing && $existing->user_id !== $user->id) {
            throw new \RuntimeException(
                "Employee ID already belongs to another user: {$employeeId}"
            );
        }

        $librarian = Librarian::withTrashed()
            ->where('user_id', $user->id)
            ->first();

        if (
            $librarian &&
            ! str_starts_with($librarian->employee_id, 'DEMO-')
        ) {
            throw new \RuntimeException(
                'Existing librarian record is not a Demo record.'
            );
        }

        $librarian ??= new Librarian();

        $librarian->fill([
            'user_id' => $user->id,
            'employee_id' => $employeeId,
            'status' => 'active',
            'shift' => 'full_day',
            'department' => 'Demo Library',
            'position' => 'Librarian',
        ]);

        $librarian->deleted_at = null;
        $librarian->save();

        return $librarian;
    }

    /**
     * @return array<string, Member>
     */
    private function seedMembers(
        Role $role,
        string $password
    ): array {
        $domain = config('demo.email_domain');

        $people = [
            'demo' => ['Demo Member', 'member', 'student'],
            'sophea' => ['Sophea Chan', 'sophea.chan', 'student'],
            'dara' => ['Dara Keo', 'dara.keo', 'student'],
            'vanna' => ['Vanna Sok', 'vanna.sok', 'teacher'],
            'srey' => ['Srey Neath', 'srey.neath', 'student'],
            'rithy' => ['Rithy Pich', 'rithy.pich', 'external'],
            'maly' => ['Maly Heng', 'maly.heng', 'student'],
            'sokha' => ['Sokha Lim', 'sokha.lim', 'teacher'],
            'bopha' => ['Bopha Ouk', 'bopha.ouk', 'student'],
            'kosal' => ['Kosal Meas', 'kosal.meas', 'external'],
        ];

        $members = [];

        $i = 0;

        foreach ($people as $key => [$name, $local, $type]) {

            $user = $this->upsertUser(
                $name,
                "{$local}@{$domain}",
                $role,
                $password
            );

            $i++;

            $membershipNo = 'DEMO-MEM-' . str_pad(
                (string) $i,
                4,
                '0',
                STR_PAD_LEFT
            );

            $member = Member::withTrashed()
                ->where('user_id', $user->id)
                ->first();

            if (
                $member &&
                ! str_starts_with($member->membership_no, 'DEMO-MEM-')
            ) {
                throw new \RuntimeException(
                    "Existing member is not a Demo record: {$user->email}"
                );
            }

            $member ??= new Member();

            $member->fill([
                'user_id' => $user->id,
                'membership_no' => $membershipNo,
                'membership_type' => $type,
                'max_borrow_limit' => 3,
                'join_date' => now()->subMonths(6)->toDateString(),
                'expiry_date' => now()->addYear()->toDateString(),
                'status' => 'active',
            ]);

            $member->deleted_at = null;
            $member->save();

            $members[$key] = $member;
        }

        return $members;
    }

    // --------------------------------------------------
    // Catalog
    // --------------------------------------------------

    /**
     * @return array<string, Book>
     */
    private function seedCatalog(): array
    {
        $categories = [];

        $categoryData = [
            'Fiction' => 'Novels and short stories.',
            'Science' => 'Natural sciences and discoveries.',
            'Technology' => 'Computing and engineering.',
            'History' => 'World and regional history.',
            'Biography' => 'Life stories of notable people.',
            'Philosophy' => 'Ideas, ethics and thought.',
            'Design' => 'Art, architecture and design.',
            'Self-Help' => 'Personal development.',
        ];

        foreach ($categoryData as $name => $description) {

            $demoName = "Demo - {$name}";

            $category = Category::firstOrNew([
                'name' => $demoName,
            ]);

            if (
                $category->exists &&
                ! str_starts_with(
                    $category->description ?? '',
                    '[DEMO]'
                )
            ) {
                throw new \RuntimeException(
                    "Category name is already used by non-Demo data: {$demoName}"
                );
            }

            $category->description = "[DEMO] {$description}";
            $category->save();

            $categories[$name] = $category;
        }

        // [code, title, author, publisher, category, year, copies]

        $catalog = [
            ['001', 'Sapiens: A Brief History of Humankind', 'Yuval Noah Harari', 'Harper', 'History', 2015, 5],
            ['002', 'In a Sunburned Country', 'Bill Bryson', 'Broadway Books', 'History', 2001, 4],
            ['003', 'Steve Jobs', 'Walter Isaacson', 'Simon & Schuster', 'Biography', 2011, 4],
            ['004', 'The Prophet', 'Kahlil Gibran', 'Knopf', 'Philosophy', 1923, 4],
            ['005', 'Clean Code', 'Robert C. Martin', 'Prentice Hall', 'Technology', 2008, 3],
            ['006', 'Design Patterns', 'Erich Gamma et al.', 'Addison-Wesley', 'Technology', 1994, 2],
            ['007', 'A Brief History of Time', 'Stephen Hawking', 'Bantam', 'Science', 1988, 3],
            ['008', 'Pride and Prejudice', 'Jane Austen', 'Penguin Classics', 'Fiction', 1813, 3],
            ['009', 'To Kill a Mockingbird', 'Harper Lee', 'Harper Perennial', 'Fiction', 1960, 3],
            ['010', 'The Great Gatsby', 'F. Scott Fitzgerald', 'Scribner', 'Fiction', 1925, 2],
            ['011', 'Meditations', 'Marcus Aurelius', 'Penguin Classics', 'Philosophy', 180, 2],
            ['012', 'Eloquent JavaScript', 'Marijn Haverbeke', 'No Starch Press', 'Technology', 2018, 3],
            ['013', 'Atomic Habits', 'James Clear', 'Avery', 'Self-Help', 2018, 4],
            ['014', 'Cosmos', 'Carl Sagan', 'Ballantine', 'Science', 1980, 2],
            ['015', 'The Elements of Style in Design', 'Demo Publisher', 'Demo Press', 'Design', 2020, 1],
        ];

        $books = [];

        foreach (
            $catalog as $index =>
            [$code, $title, $author, $publisher, $cat, $year, $copies]
        ) {

            $isbn = self::ISBN_PREFIX . $code;

            $book = Book::withTrashed()
                ->where('isbn', $isbn)
                ->first();

            if (
                $book &&
                $book->category_id !== $categories[$cat]->id
            ) {
                throw new \RuntimeException(
                    "Demo ISBN belongs to an unexpected category: {$isbn}"
                );
            }

            $book ??= new Book();

            $book->fill([
                'isbn' => $isbn,
                'title' => $title,
                'author' => $author,
                'publisher' => $publisher,
                'category_id' => $categories[$cat]->id,
                'language' => 'English',
                'publish_year' => $year,
                'description' => '[DEMO] Demo catalog entry for portfolio purposes.',
                'shelf_location' => 'DEMO-' . str_pad(
                    (string) ($index + 1),
                    2,
                    '0',
                    STR_PAD_LEFT
                ),
                'acquired_date' => now()->subMonths(8)->toDateString(),
                'status' => 'available',
            ]);

            $book->deleted_at = null;
            $book->save();

            for ($copyNumber = 1; $copyNumber <= $copies; $copyNumber++) {

                $barcode = self::BARCODE_PREFIX
                    . $code . '-'
                    . str_pad(
                        (string) $copyNumber,
                        2,
                        '0',
                        STR_PAD_LEFT
                    );

                $copy = BookCopy::withTrashed()
                    ->where('barcode', $barcode)
                    ->first();

                if (
                    $copy &&
                    $copy->book_id !== $book->id
                ) {
                    throw new \RuntimeException(
                        "Demo barcode belongs to another book: {$barcode}"
                    );
                }

                $copy ??= new BookCopy();

                $copy->fill([
                    'book_id' => $book->id,
                    'barcode' => $barcode,
                    'status' => 'available',
                    'condition' => 'good',
                    'shelf_location' => $book->shelf_location,
                    'acquired_date' => $book->acquired_date,
                ]);

                $copy->deleted_at = null;
                $copy->save();
            }

            $books[$code] = $book;
        }

        return $books;
    }

    // --------------------------------------------------
    // Circulation
    // --------------------------------------------------

    private function copyOf(
    Book $book,
    int $number
    ): BookCopy {
        $code = substr(
            (string) $book->isbn,
            strlen(self::ISBN_PREFIX)
        );

        $barcode = self::BARCODE_PREFIX
            . $code . '-'
            . str_pad(
                (string) $number,
                2,
                '0',
                STR_PAD_LEFT
            );

        return BookCopy::where('book_id', $book->id)
            ->where('barcode', $barcode)
            ->firstOrFail();
    }

    private function borrow(
        BookCopy $copy,
        Member $member,
        Librarian $librarian,
        int $daysAgo,
        int $loanDays,
        ?int $returnedDaysAgo = null
    ): BorrowTransaction {

        if (
            ! str_starts_with($copy->barcode, self::BARCODE_PREFIX) ||
            ! str_starts_with($member->membership_no, 'DEMO-MEM-') ||
            ! str_starts_with($librarian->employee_id, 'DEMO-')
        ) {
            throw new \RuntimeException(
                'Refusing to create a transaction with non-Demo records.'
            );
        }

        $borrowDate = now()->subDays($daysAgo)->startOfDay();
        $dueDate = $borrowDate->copy()->addDays($loanDays);

        $status = $returnedDaysAgo !== null
            ? 'returned'
            : ($dueDate->lt(now()->startOfDay()) ? 'overdue' : 'borrowed');

        $marker = 'DEMO-SEED:borrow:' . $copy->barcode;

        $tx = BorrowTransaction::firstOrNew([
            'notes' => $marker,
        ]);

        if (
            $tx->exists &&
            (
                $tx->book_copy_id !== $copy->id ||
                $tx->member_id !== $member->id
            )
        ) {
            throw new \RuntimeException(
                "Borrow marker collision: {$marker}"
            );
        }

        $tx->fill([
            'book_copy_id' => $copy->id,
            'member_id' => $member->id,
            'librarian_id' => $librarian->id,
            'borrow_date' => $borrowDate->toDateString(),
            'due_date' => $dueDate->toDateString(),
            'return_date' => $returnedDaysAgo !== null
                ? now()->subDays($returnedDaysAgo)->toDateString()
                : null,
            'status' => $status,
            'renewed_count' => 0,
        ]);

        $tx->forceFill([
            'fine_amount' => 0,
            'fine_status' => 'none',
        ]);

        $tx->save();

        $copy->forceFill([
            'status' => $returnedDaysAgo !== null
                ? 'available'
                : 'borrowed',
        ])->save();

        return $tx;
    }

    private function fine(
        BorrowTransaction $tx,
        int $daysLate,
        string $status
    ): void {

        $amount = $daysLate * self::FINE_PER_DAY;

        $fine = Fine::firstOrNew([
            'borrow_id' => $tx->id,
        ]);

        $fine->fill([
            'amount' => $amount,
            'reason' => 'overdue',
            'status' => $status,
            'paid_at' => $status === 'paid'
                ? now()->subDays(2)
                : null,
            'payment_method' => $status === 'paid'
                ? 'cash'
                : null,
            'notes' => "[DEMO] Overdue by {$daysLate} day(s)",
        ]);

        $fine->save();

        $tx->forceFill([
            'fine_amount' => $status === 'waived'
                ? 0
                : $amount,
            'fine_status' => $status === 'unpaid'
                ? 'unpaid'
                : ($status === 'paid' ? 'paid' : 'none'),
        ])->save();
    }

    private function seedCirculation(
        array $members,
        Librarian $librarian,
        array $books
    ): void {

        $this->borrow(
            $this->copyOf($books['001'], 1),
            $members['demo'],
            $librarian,
            3,
            14
        );

        $this->borrow(
            $this->copyOf($books['013'], 1),
            $members['sophea'],
            $librarian,
            5,
            14
        );

        $this->borrow(
            $this->copyOf($books['005'], 1),
            $members['vanna'],
            $librarian,
            2,
            14
        );

        $o1 = $this->borrow(
            $this->copyOf($books['008'], 1),
            $members['dara'],
            $librarian,
            25,
            14
        );

        $o2 = $this->borrow(
            $this->copyOf($books['009'], 1),
            $members['srey'],
            $librarian,
            30,
            14
        );

        $o3 = $this->borrow(
            $this->copyOf($books['007'], 1),
            $members['rithy'],
            $librarian,
            21,
            14
        );

        $this->fine($o1, 11, 'unpaid');
        $this->fine($o2, 16, 'unpaid');
        $this->fine($o3, 7, 'unpaid');

        $this->borrow(
            $this->copyOf($books['004'], 1),
            $members['maly'],
            $librarian,
            40,
            14,
            30
        );

        $this->borrow(
            $this->copyOf($books['003'], 1),
            $members['sokha'],
            $librarian,
            35,
            14,
            25
        );

        $r3 = $this->borrow(
            $this->copyOf($books['010'], 1),
            $members['bopha'],
            $librarian,
            50,
            14,
            30
        );

        $this->fine($r3, 6, 'paid');

        $r4 = $this->borrow(
            $this->copyOf($books['012'], 1),
            $members['kosal'],
            $librarian,
            45,
            14,
            28
        );

        $this->fine($r4, 3, 'waived');

        $this->borrow(
            $this->copyOf($books['001'], 2),
            $members['sophea'],
            $librarian,
            60,
            14,
            50
        );

        $this->borrow(
            $this->copyOf($books['001'], 3),
            $members['dara'],
            $librarian,
            55,
            14,
            45
        );

        $this->borrow(
            $this->copyOf($books['014'], 1),
            $members['demo'],
            $librarian,
            70,
            14,
            60
        );

        $this->borrow(
            $this->copyOf($books['015'], 1),
            $members['maly'],
            $librarian,
            4,
            14
        );
    }

    // --------------------------------------------------
    // Reservations
    // --------------------------------------------------

    private function seedReservations(
        array $members,
        array $books
    ): void {
        $items = [
            ['demo', '015', Reservation::PENDING, 1, 2],
            ['sophea', '015', Reservation::PENDING, 2, 1],
            ['srey', '005', Reservation::READY, 1, 4],
            ['vanna', '006', Reservation::FULFILLED, 1, 20],
            ['rithy', '010', Reservation::CANCELLED, 1, 15],
            ['kosal', '011', Reservation::EXPIRED, 1, 40],
        ];

        foreach (
            $items as [$memberKey, $bookCode, $status, $priority, $daysAgo]
        ) {

            $member = $members[$memberKey];
            $book = $books[$bookCode];

            $reservation = Reservation::firstOrNew([
                'member_id' => $member->id,
                'book_id' => $book->id,
                'status' => $status,
            ]);

            $reservation->fill([
                'reserved_date' => now()->subDays($daysAgo)->toDateString(),
                'expire_date' => now()->subDays($daysAgo)->addDays(30)->toDateString(),
                'priority_order' => $priority,
                'ready_at' => $status === Reservation::READY
                    ? now()->subDay()
                    : null,
                'fulfilled_at' => $status === Reservation::FULFILLED
                    ? now()->subDays(5)
                    : null,
                'cancelled_at' => $status === Reservation::CANCELLED
                    ? now()->subDays(3)
                    : null,
                'expired_at' => $status === Reservation::EXPIRED
                    ? now()->subDay()
                    : null,
            ]);

            $reservation->save();
        }
    }
}

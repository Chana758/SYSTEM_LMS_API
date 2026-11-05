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
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DemoSeeder extends Seeder
{
    private const FINE_PER_DAY = 500;

    public function run(): void
    {
        if (! config('demo.enabled')) {
            throw new \RuntimeException('Refusing to seed demo data: DEMO_MODE is not true.');
        }
        $password = config('demo.password');
        if (! $password || strlen($password) < 8) {
            throw new \RuntimeException('Set DEMO_PASSWORD (min 8 chars) before seeding demo data.');
        }

        DB::transaction(function () use ($password) {
            $roles = [
                'admin'     => Role::firstOrCreate(['name' => 'admin']),
                'librarian' => Role::firstOrCreate(['name' => 'librarian']),
                'member'    => Role::firstOrCreate(['name' => 'member']),
            ];

            $this->seedAdmin($roles['admin'], $password);
            $librarian = $this->seedLibrarian($roles['librarian'], $password);
            $members = $this->seedMembers($roles['member'], $password);
            $books = $this->seedCatalog();
            $this->seedCirculation($members, $librarian, $books);
            $this->seedReservations($members, $books);

            foreach ($books as $book) {
                $book->syncQty();
            }
        });
    }

    /* ---------- Users ---------- */

    private function upsertUser(string $name, string $email, Role $role, string $password): User
    {
        $user = User::withTrashed()->firstOrNew(['email' => $email]);
        $user->fill([
            'name'                => $name,
            'password'            => Hash::make($password),
            'role_id'             => $role->id,
            'status'              => 'active',
            'language_preference' => 'en',
        ]);
        $user->is_demo = true; // not mass-assignable on purpose
        $user->deleted_at = null;
        $user->save();

        return $user;
    }

    private function seedAdmin(Role $role, string $password): void
    {
        $domain = config('demo.email_domain');
        $this->upsertUser('Demo Admin', "admin@{$domain}", $role, $password);
    }

    private function seedLibrarian(Role $role, string $password): Librarian
    {
        $domain = config('demo.email_domain');
        $user = $this->upsertUser('Demo Librarian', "librarian@{$domain}", $role, $password);

        return Librarian::firstOrCreate(
            ['user_id' => $user->id],
            ['employee_id' => 'EMP-' . date('Y') . '-9001', 'status' => 'active', 'shift' => 'full_day']
        );
    }

    /** @return Member[] keyed by short name */
    private function seedMembers(Role $role, string $password): array
    {
        $domain = config('demo.email_domain');
        $people = [
            'demo'   => ['Demo Member',     'member',        'student'],
            'sophea' => ['Sophea Chan',     'sophea.chan',   'student'],
            'dara'   => ['Dara Keo',        'dara.keo',      'student'],
            'vanna'  => ['Vanna Sok',       'vanna.sok',     'teacher'],
            'srey'   => ['Srey Neath',      'srey.neath',    'student'],
            'rithy'  => ['Rithy Pich',      'rithy.pich',    'external'],
            'maly'   => ['Maly Heng',       'maly.heng',     'student'],
            'sokha'  => ['Sokha Lim',       'sokha.lim',     'teacher'],
            'bopha'  => ['Bopha Ouk',       'bopha.ouk',     'student'],
            'kosal'  => ['Kosal Meas',      'kosal.meas',    'external'],
        ];

        $members = [];
        $i = 0;
        foreach ($people as $key => [$name, $local, $type]) {
            $user = $this->upsertUser($name, "{$local}@{$domain}", $role, $password);
            $i++;
            $members[$key] = Member::withTrashed()->firstOrCreate(
                ['user_id' => $user->id],
                [
                    'membership_no'    => 'MEM-' . date('Y') . '-' . str_pad((string) (900000 + $i), 6, '0', STR_PAD_LEFT),
                    'membership_type'  => $type,
                    'max_borrow_limit' => 3,
                    'join_date'        => now()->subMonths(6),
                    'status'           => 'active',
                ]
            );
        }

        return $members;
    }

    /* ---------- Catalog ---------- */

    /** @return Book[] keyed by ISBN */
    private function seedCatalog(): array
    {
        $categories = [];
        foreach ([
            'Fiction'      => 'Novels and short stories.',
            'Science'      => 'Natural sciences and discoveries.',
            'Technology'   => 'Computing and engineering.',
            'History'      => 'World and regional history.',
            'Biography'    => 'Life stories of notable people.',
            'Philosophy'   => 'Ideas, ethics and thought.',
            'Design'       => 'Art, architecture and design.',
            'Self-Help'    => 'Personal development.',
        ] as $name => $desc) {
            $categories[$name] = Category::firstOrCreate(['name' => $name], ['description' => $desc]);
        }

        // [isbn, title, author, publisher, category, year, copies]
        $catalog = [
            ['9780062316097', 'Sapiens: A Brief History of Humankind', 'Yuval Noah Harari', 'Harper', 'History', 2015, 5],
            ['9780767919388', 'In a Sunburned Country', 'Bill Bryson', 'Broadway Books', 'History', 2001, 4],
            ['9781982115401', 'Steve Jobs', 'Walter Isaacson', 'Simon & Schuster', 'Biography', 2011, 4],
            ['9780394494159', 'The Prophet', 'Kahlil Gibran', 'Knopf', 'Philosophy', 1923, 4],
            ['9780132350884', 'Clean Code', 'Robert C. Martin', 'Prentice Hall', 'Technology', 2008, 3],
            ['9780201633610', 'Design Patterns', 'Erich Gamma et al.', 'Addison-Wesley', 'Technology', 1994, 2],
            ['9780553380163', 'A Brief History of Time', 'Stephen Hawking', 'Bantam', 'Science', 1988, 3],
            ['9780141439518', 'Pride and Prejudice', 'Jane Austen', 'Penguin Classics', 'Fiction', 1813, 3],
            ['9780061120084', 'To Kill a Mockingbird', 'Harper Lee', 'Harper Perennial', 'Fiction', 1960, 3],
            ['9780743273565', 'The Great Gatsby', 'F. Scott Fitzgerald', 'Scribner', 'Fiction', 1925, 2],
            ['9780140449334', 'Meditations', 'Marcus Aurelius', 'Penguin Classics', 'Philosophy', 180, 2],
            ['9781593279288', 'Eloquent JavaScript', 'Marijn Haverbeke', 'No Starch Press', 'Technology', 2018, 3],
            ['9780735211292', 'Atomic Habits', 'James Clear', 'Avery', 'Self-Help', 2018, 4],
            ['9780679783268', 'Cosmos', 'Carl Sagan', 'Ballantine', 'Science', 1980, 2],
            ['9781250301697', 'The Elements of Style in Design', 'Demo Publisher', 'Demo Press', 'Design', 2020, 1],
        ];

        $books = [];
        foreach ($catalog as $n => [$isbn, $title, $author, $publisher, $cat, $year, $copies]) {
            $book = Book::withTrashed()->firstOrCreate(
                ['isbn' => $isbn],
                [
                    'title'          => $title,
                    'author'         => $author,
                    'publisher'      => $publisher,
                    'category_id'    => $categories[$cat]->id,
                    'language'       => 'English',
                    'publish_year'   => $year,
                    'description'    => 'Demo catalog entry for portfolio purposes.',
                    'total_qty'      => $copies,
                    'available_qty'  => $copies,
                    'shelf_location' => 'A-' . str_pad((string) ($n + 1), 2, '0', STR_PAD_LEFT),
                    'acquired_date'  => now()->subMonths(8)->toDateString(),
                    'status'         => 'available',
                ]
            );
            for ($c = 1; $c <= $copies; $c++) {
                BookCopy::withTrashed()->firstOrCreate(
                    ['barcode' => 'DEMO-' . $isbn . '-' . $c],
                    [
                        'book_id'        => $book->id,
                        'status'         => 'available',
                        'condition'      => 'good',
                        'shelf_location' => $book->shelf_location,
                        'acquired_date'  => $book->acquired_date,
                    ]
                );
            }
            $books[$isbn] = $book;
        }

        return $books;
    }

    /* ---------- Circulation ---------- */

    private function copyOf(Book $book, int $n): BookCopy
    {
        return BookCopy::where('book_id', $book->id)->orderBy('id')->skip($n - 1)->firstOrFail();
    }

    private function borrow(BookCopy $copy, Member $member, Librarian $lib, int $daysAgo, int $loanDays, ?int $returnedDaysAgo = null): BorrowTransaction
    {
        $borrowDate = now()->subDays($daysAgo)->startOfDay();

        $tx = BorrowTransaction::firstOrCreate(
            ['book_copy_id' => $copy->id, 'member_id' => $member->id, 'borrow_date' => $borrowDate->toDateString()],
            [
                'librarian_id'  => $lib->id,
                'due_date'      => $borrowDate->copy()->addDays($loanDays)->toDateString(),
                'return_date'   => $returnedDaysAgo !== null ? now()->subDays($returnedDaysAgo)->toDateString() : null,
                'status'        => $returnedDaysAgo !== null ? 'returned' : 'borrowed',
                'renewed_count' => 0,
            ]
        );

        // returned copies are available again; borrowed ones are not
        $copy->forceFill(['status' => $returnedDaysAgo !== null ? 'available' : 'borrowed'])->save();

        return $tx;
    }

    private function fine(BorrowTransaction $tx, int $daysLate, string $status): void
    {
        $amount = $daysLate * self::FINE_PER_DAY;

        Fine::firstOrCreate(
            ['borrow_id' => $tx->id],
            [
                'amount'         => $amount,
                'reason'         => 'overdue',
                'status'         => $status, // unpaid | paid | waived
                'paid_at'        => $status === 'paid' ? now()->subDays(2) : null,
                'payment_method' => $status === 'paid' ? 'cash' : null,
                'notes'          => "Overdue by {$daysLate} day(s)",
            ]
        );

        $tx->forceFill([
            'fine_amount' => $status === 'waived' ? 0 : $amount,
            'fine_status' => $status === 'unpaid' ? 'unpaid' : ($status === 'paid' ? 'paid' : 'none'),
        ])->save();
    }
        private function seedCirculation(array $m, Librarian $lib, array $b): void
    {
        $isbn = fn (string $i) => $b[$i];

        // Currently borrowed, on time (due in the future)
        $this->borrow($this->copyOf($isbn('9780062316097'), 1), $m['demo'],   $lib, 3, 14);
        $this->borrow($this->copyOf($isbn('9780735211292'), 1), $m['sophea'], $lib, 5, 14);
        $this->borrow($this->copyOf($isbn('9780132350884'), 1), $m['vanna'],  $lib, 2, 14);

        // Overdue (still borrowed, past due date)
        $o1 = $this->borrow($this->copyOf($isbn('9780141439518'), 1), $m['dara'],  $lib, 25, 14);
        $o2 = $this->borrow($this->copyOf($isbn('9780061120084'), 1), $m['srey'],  $lib, 30, 14);
        $o3 = $this->borrow($this->copyOf($isbn('9780553380163'), 1), $m['rithy'], $lib, 21, 14);
        $this->fine($o1, 11, 'unpaid');
        $this->fine($o2, 16, 'unpaid');
        $this->fine($o3, 7,  'unpaid');

        // Returned history (some late with paid / waived fines)
        $this->borrow($this->copyOf($isbn('9780394494159'), 1), $m['maly'],   $lib, 40, 14, 30);
        $this->borrow($this->copyOf($isbn('9781982115401'), 1), $m['sokha'],  $lib, 35, 14, 25);
        $r3 = $this->borrow($this->copyOf($isbn('9780743273565'), 1), $m['bopha'], $lib, 50, 14, 30);
        $this->fine($r3, 6, 'paid');
        $r4 = $this->borrow($this->copyOf($isbn('9781593279288'), 1), $m['kosal'], $lib, 45, 14, 28);
        $this->fine($r4, 3, 'waived');
        $this->borrow($this->copyOf($isbn('9780062316097'), 2), $m['sophea'], $lib, 60, 14, 50);
        $this->borrow($this->copyOf($isbn('9780062316097'), 3), $m['dara'],   $lib, 55, 14, 45);
        $this->borrow($this->copyOf($isbn('9780679783268'), 1), $m['demo'],   $lib, 70, 14, 60);

        // Fully checked out title (shows up in Stock Warning): the 1-copy design book
        $this->borrow($this->copyOf($isbn('9781250301697'), 1), $m['maly'], $lib, 4, 14);
    }

    private function seedReservations(array $m, array $b): void
    {
        $make = function (Member $member, Book $book, string $status, int $priority, int $daysAgo) {
            Reservation::firstOrCreate(
                ['member_id' => $member->id, 'book_id' => $book->id, 'status' => $status],
                [
                    'reserved_date'  => now()->subDays($daysAgo)->toDateString(),
                    'expire_date'    => now()->subDays($daysAgo)->addDays(30)->toDateString(),
                    'priority_order' => $priority,
                    'ready_at'       => $status === Reservation::READY ? now()->subDay() : null,
                    'fulfilled_at'   => $status === Reservation::FULFILLED ? now()->subDays(5) : null,
                    'cancelled_at'   => $status === Reservation::CANCELLED ? now()->subDays(3) : null,
                    'expired_at'     => $status === Reservation::EXPIRED ? now()->subDays(1) : null,
                ]
            );
        };

        // Queue on the fully checked-out title
        $make($m['demo'],   $b['9781250301697'], Reservation::PENDING, 1, 2);
        $make($m['sophea'], $b['9781250301697'], Reservation::PENDING, 2, 1);
        $make($m['srey'],   $b['9780132350884'], Reservation::READY,   1, 4);
        $make($m['vanna'],  $b['9780201633610'], Reservation::FULFILLED, 1, 20);
        $make($m['rithy'],  $b['9780743273565'], Reservation::CANCELLED, 1, 15);
        $make($m['kosal'],  $b['9780140449334'], Reservation::EXPIRED,   1, 40);
    }
}
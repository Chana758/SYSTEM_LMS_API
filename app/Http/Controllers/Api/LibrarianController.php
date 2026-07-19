<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Librarian\StoreLibrarianRequest;
use App\Http\Requests\Librarian\UpdateLibrarianRequest;
use App\Models\Librarian;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class LibrarianController extends Controller
{
    /**
     * List all librarians — supports search and filtering.
     * GET /api/librarians?search=&status=&department=
     */
    public function index(Request $request)
    {
        $query = Librarian::query()->with('user');

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('employee_id', 'ilike', "%{$search}%")
                  ->orWhereHas('user', function ($uq) use ($search) {
                      $uq->where('name', 'ilike', "%{$search}%")
                         ->orWhere('email', 'ilike', "%{$search}%");
                  });
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('department')) {
            $query->where('department', $request->input('department'));
        }

        return response()->json($query->latest()->paginate($request->input('per_page', 15)));
    }

    /**
     * Create a new librarian.
     * POST /api/librarians
     */
    public function store(StoreLibrarianRequest $request)
    {
        $validated = $request->validated();
        $librarianRole = Role::firstOrCreate(['name' => 'librarian']);

        $librarian = DB::transaction(function () use ($validated, $librarianRole) {
            $user = User::create([
                'name'     => $validated['name'],
                'email'    => $validated['email'],
                'password' => Hash::make($validated['password']),
                'phone'    => $validated['phone'] ?? null,
                'role_id'  => $librarianRole->id,
                'status'   => 'active',
            ]);

            return Librarian::create([
                'user_id'      => $user->id,
                'employee_id'  => $this->generateEmployeeId(),
                'department'   => $validated['department'] ?? null,
                'position'     => $validated['position'] ?? null,
                'shift'        => $validated['shift'] ?? null,
                'phone_number' => $validated['phone'] ?? null,
                'hire_date'    => $validated['hire_date'] ?? now(),
                'status'       => 'active',
            ]);
        });

        return response()->json([
            'message'   => 'Librarian created successfully.',
            'librarian' => $librarian->load('user'),
        ], 201);
    }

    /**
     * Display librarian details.
     * GET /api/librarians/{librarian}
     */
    public function show(Librarian $librarian)
    {
        return response()->json($librarian->load('user'));
    }

    /**
     * Update librarian information.
     * PUT /api/librarians/{librarian}
     */
    public function update(UpdateLibrarianRequest $request, Librarian $librarian)
    {
        $validated = $request->validated();

        DB::transaction(function () use ($validated, $librarian) {
            $userFields = array_intersect_key($validated, array_flip(['name', 'email']));
            if (! empty($userFields)) {
                $librarian->user->update($userFields);
            }

            $librarianFields = array_diff_key($validated, array_flip(['name', 'email']));
            if (! empty($librarianFields)) {
                $librarian->update($librarianFields);
            }
        });

        return response()->json([
            'message'   => 'Librarian updated successfully.',
            'librarian' => $librarian->fresh()->load('user'),
        ]);
    }

    /**
     * Soft delete a librarian.
     * DELETE /api/librarians/{librarian}
     */
    public function destroy(Librarian $librarian)
    {
        $librarian->delete();
        return response()->json(['message' => 'Librarian deleted successfully.']);
    }

    /**
     * Restore a deleted librarian.
     * POST /api/librarians/{id}/restore
     */
    public function restore($id)
    {
        $librarian = Librarian::withTrashed()->findOrFail($id);
        $librarian->restore();

        return response()->json([
            'message'   => 'Librarian restored successfully.',
            'librarian' => $librarian->load('user'),
        ]);
    }

    private function generateEmployeeId(): string
    {
        do {
            $no = 'EMP-' . date('Y') . '-' . str_pad(random_int(1, 9999), 4, '0', STR_PAD_LEFT);
        } while (Librarian::withTrashed()->where('employee_id', $no)->exists());

        return $no;
    }
}
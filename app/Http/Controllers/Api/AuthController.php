<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\Librarian;
use App\Models\Member;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function register(RegisterRequest $request)
    {
        // Validate all request data
        $validated=$request->validated();

        // Find the "member" role or create it if it does not exist
        $memberRole= Role::firstOrCreate(['name'=>'member']);

        // Create User and Member in a single database transaction
        $user=DB::transaction(function() use($validated,$memberRole){

             // Create a new user account
            $user=User::create([
                'name'=>$validated['name'],// get data from validated request
                'email'=>$validated['email'],
                'password'=>Hash::make($validated['password']),
                'phone'=>$validated['phone']?? null,
                'role_id'=>$memberRole->id,
                'status'=>'active',
            ]);

            // create the member profile
            Member::create([
                'user_id'=>$user->id,
                'membership_no'=>$this->generateMembershipNo(),
                'membership_type'=>$validated['membership_type'] ?? 'student',
                'max_borrow_limit'=>3,
                'join_date'=>now(),
                'status'=>'active',
            ]);

            
            return $user;
        });

        // Generate laravel sanctum token for the user
        $token = $user->createToken('auth_token')->plainTextToken;

        // Return succrssful response with user data and token
        return response()->json([
            'message' => 'User registered successfully',
            'user' => $user->load('role','member','librarian'),
            'token_type' => 'Bearer',
            'access_token' => $token,
        ], 201);
    }

   /**
     * Log in an existing user.
     * Public endpoint — works the same for member, librarian, and admin.
     */
    public function login(LoginRequest $request)
    {
        // validate tyhe login request (*email and password )
        $credentials=$request->validated();

        // Check if the provided creadentials are valid
        if(!Auth::attempt($credentials)){
            return response()->json([
                'message'=>'Invalid email or password',
            ],401);
        }

        // Retrive the authenticated user by email
        $user=User::where('email',$credentials['email'])->firstOrFail();
        
        // Ensure the user account is active before allowing login
        if($user->status !== 'active'){
            return response()->json([
                'message'=>'Your account is not active (inactive/suspended)',
            ],403);
        }

        // Update the user's last login timestamp to the current time
        $user->update(['last_login_at'=>now()]);

        // Generate laravel sanctum token for the user
        $token = $user->createToken('auth_token')->plainTextToken;

        // Return authenticated user information and access token in the response
        return response()->json([
            'message' => 'Login successful',
            'user' => $user->load('role','member','librarian'),
            'token_type' => 'Bearer',
            'access_token' => $token,
        ], 200);
    }
    
    /**
     * Log out the current user.
     * Requires a valid token (auth:sanctum) — deletes only the current access token.
     */
    public function logout()
    {
        // delete the current user's active access token
        Auth()->user()->currentAccessToken()->delete();

        // Return a successful logout response
        return response()->json([
            'message' => 'Logout successfully',
        ], 200);
    }

    /**
     * Get the currently authenticated user's info.
     * Requires a valid token (auth:sanctum).
     */
    public function me(){

     // Return the authenticated user with related role, member, and librarian data
        return response()->json([
            'user'=>Auth()->user()->load('role','member','librarian'),
        ],200);
    }


    /**
     * Admin creates a new Librarian account.
     * Requires a valid token AND role = admin
     * (protected by the 'role:admin' middleware in api.php).
     */
    public function createLibrarian(RegisterRequest $request)
    {
        // Validate all incoming request data
        $validated = $request->validated();
        
         // Find the "librarian" role or create it if it does not exist
        $librarianRole = Role::firstOrCreate(['name' => 'librarian']);

        // Create User and Librarian profile in a single database transaction
        // If any step fails, all changes will be rolled back
        $user =DB::transaction(function() use($validated,$librarianRole){

            // Create a new user account for the librarian
            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
                'phone' => $validated['phone'] ?? null,
                'role_id' => $librarianRole->id,
                'status' => 'active',
            ]);

            // Create the librarian profile associated with the user
            Librarian::create([
                'user_id' => $user->id,
                'employee_id' => $this->generateEmployeeId(),
                'status' => 'active',
            ]);

            
            return $user;
        });

        // Generate laravel sanctum token for the new librarian user
        return response()->json([
            'message' => 'Librarian created successfully',
            'user' => $user->load('role', 'librarian'),
        ], 201);
    }
     /**
     * Auto-generate a unique membership number, e.g. MEM-2026-000123
     */
    private function generateMembershipNo(): string
    {
        // Generate a membership number and check if it already exists
        do {
            // Create format: MEM-YEAR-RANDOM_NUMBER
            $no = 'MEM-' . date('Y') . '-' . str_pad(random_int(1, 999999), 6, '0', STR_PAD_LEFT);
        
            // Repeat generating a new number if the membership number already exists
        } while (Member::where('membership_no', $no)->exists());

       
        return $no;
    }

    /**
     * Auto-generate a unique employee ID, e.g. EMP-2026-0042
     */
    private function generateEmployeeId(): string
    {
        // Generate an employee ID and check if it already exists
        do {
            // Create format: EMP-YEAR-RANDOM_NUMBER
            $no = 'EMP-' . date('Y') . '-' . str_pad(random_int(1, 9999), 4, '0', STR_PAD_LEFT);

            // Repeat generating a new ID if the employee ID already exists
        } while (Librarian::where('employee_id', $no)->exists());

        
        return $no;
    }
}
 
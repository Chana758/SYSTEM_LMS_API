<?php

return [
    'custom' => [
        // Shared / generic fields
        'email' => [
            'required' => 'Please enter your email address.',
            'email' => 'Invalid email format.',
            'unique' => 'This email is already registered.',
        ],
        'password' => [
            'required' => 'Please enter your password.',
            'min' => 'Password must be at least :min characters.',
            'confirmed' => 'Password confirmation does not match.',
        ],
        'name' => [
            'required' => 'Please enter your name.',
            'max' => 'Name must not exceed :max characters.',
        ],
        'phone' => [
            'required' => 'Please enter your phone number.',
            'max' => 'Phone number must not exceed :max characters.',
        ],
        'membership_type' => [
            'in' => 'Please select a valid membership type.',
        ],
        'qr_token' => [
            'required' => 'QR token is missing or invalid.',
        ],

        // Catalog — Category
        'category' => [
            'name' => [
                'required' => 'The category name is required.',
                'unique' => 'This category name already exists.',
                'max' => 'The category name cannot exceed 100 characters.',
            ],
            'description' => [
                'max' => 'The description cannot exceed 500 characters.',
            ],
        ],

        // Catalog — Book
        'book' => [
            'isbn' => [
                'unique' => 'This ISBN is already registered to another book.',
            ],
        ],

        // Circulation — Borrow
        'borrow' => [
            'member_id' => [
                'required' => 'Please select a member.',
                'exists' => 'The selected member could not be found.',
            ],
            'book_id' => [
                'required' => 'Please select a book.',
                'exists' => 'The selected book could not be found.',
            ],
        ],

        // Circulation — Reservation
        'reservation' => [
            'book_id' => [
                'required' => 'Please select the book you wish to reserve.',
                'exists' => 'The selected book could not be found.',
            ],
            'member_id' => [
                'required' => 'Please select a member.',
                'exists' => 'The selected member could not be found.',
            ],
        ],

        // Fine
        'fine' => [
            'amount' => [
                'max' => 'Fine amount cannot exceed $500. If this book genuinely costs more to replace, contact an administrator to record it manually.',
            ],
        ],

        // Librarian
        'librarian' => [
            'email' => [
                'unique' => 'This email is already registered.',
            ],
            'password' => [
                'confirmed' => 'Password confirmation does not match.',
            ],
            'status' => [
                'in' => 'The selected status is invalid.',
            ],
        ],

        // Member
        'member' => [
            'email' => [
                'unique' => 'This email address has already been taken.',
            ],
            'membership_type' => [
                'exists' => 'The selected membership type is invalid or no longer available.',
            ],
            'status' => [
                'in' => 'The selected status is invalid.',
            ],
            'expiry_date' => [
                'after' => 'The expiry date must be a date after today.',
            ],
        ],

        // Digital Library - Ebook
        'ebook' => [
            'book_id' => [
                'required' => 'Please select a book.',
                'exists' => 'The selected book could not be found.',
            ],
            'file' => [
                'required' => 'Please choose a file.',
                'mimes' => 'File must be PDF, EPUB, or MOBI format.',
                'max' => 'File size must not exceed 50MB.',
            ],
        ],

        // Settings
        'setting' => [
            'key' => [
                'required' => 'Setting key is required.',
                'unique' => 'This setting key already exists.',
                'regex' => 'Key must contain only lowercase letters, numbers, and underscores.',
            ],
            'type' => [
                'required' => 'Please select a value type.',
                'in' => 'Invalid value type selected.',
            ],
        ],

        // Membership Type
        'membershipType' => [
            'code' => [
                'required' => 'Code is required.',
                'unique' => 'This code already exists.',
                'regex' => 'Code must contain only lowercase letters, numbers, and underscores.',
            ],
            'label' => [
                'required' => 'Label is required.',
            ],
        ],

        // Return Book
        'return' => [
            'condition' => [
                'required' => 'Please select the book condition.',
                'in' => 'Invalid book condition selected.',
            ],
            'damage_fee' => [
                'required_if' => 'Please enter the damage fee amount.',
            ],
            'lost_fee' => [
                'required_if' => 'Please enter the lost book fee amount.',
            ],
        ],

        // Notifications
        'notification' => [
            'title' => [
                'required' => 'Please enter a title.',
            ],
            'message' => [
                'required' => 'Please enter a message.',
            ],
            'user_id' => [
                'required_if' => 'Please select a member to send this to.',
                'exists' => 'The selected member could not be found.',
            ],
        ],

        // Change Password
        'changePassword' => [
            'current_password' => [
                'required' => 'Please enter your current password.',
                'invalid' => 'The current password you entered is incorrect.',
            ],
        ],
    ],
];
<?php

return [
    'UNPAID_FINES'      => 'Unpaid fines of :amount៛. Please pay before borrowing more.',
    'MAX_BOOKS'         => 'Already borrowing :max books. Please return some first.',
    'MEMBER_NOT_FOUND'  => 'Member not found.',
    'NOT_A_MEMBER'      => 'This card does not belong to a member.',
    'COPY_NOT_AVAILABLE' => 'This copy is not on the shelf.',

    // Self-checkout kiosk (B4.16/17)
    'SELF_NOT_HERE'           => 'This kiosk is not set up for self-checkout.',
    'SELF_NOT_MEMBER'         => 'This is not a member card. Please go to the desk.',
    'SELF_NOT_ALLOWED'        => '":type" members cannot borrow alone. Please borrow at the desk.',
    'SELF_NO_PIN'             => 'You have no PIN yet. Set a 4-digit PIN in the app (Profile) first.',
    'SELF_PIN_WRONG'          => 'Wrong PIN. :left tries left.',
    'SELF_PIN_LOCKED'         => 'PIN locked for :minutes minutes after too many wrong tries. Please go to the desk.',
    'SELF_SESSION_EXPIRED'    => 'Time is up. Please scan your card again.',
    'SELF_BOOK_NOT_FOUND'     => 'Book not found. Please scan the barcode on the book again.',
    'SELF_COPY_NOT_AVAILABLE' => '":title" cannot be borrowed (already on loan or missing). Please go to the desk.',
    'SELF_ALREADY_HAS'        => 'You already have ":title".',
    'SELF_RESERVED'           => '":title" is held for another member.',
    'SELF_BORROWED'           => 'Borrowed ":title"',
    'SELF_DONE'               => ':count book(s) borrowed. Thank you!',
    'SELF_DONE_NONE'          => 'No books borrowed.',
];

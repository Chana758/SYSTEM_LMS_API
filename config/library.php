<?php

return [

    'reservation' => [
        // Pending reservations expire after N days.
        'pending_expiry_days' => (int) env('RESERVATION_PENDING_EXPIRY_DAYS', 30),

        // Members have N days to collect a reservation once the book is ready.
        'pickup_window_days' => (int) env('RESERVATION_PICKUP_WINDOW_DAYS', 3),

        // Maximum number of active reservations allowed per member.
        'max_active_per_member' => (int) env('RESERVATION_MAX_ACTIVE_PER_MEMBER', 5),
    ],

];

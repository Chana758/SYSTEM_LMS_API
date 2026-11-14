# Smart Nexus LMS: Library Management REST API

REST API for **Smart Nexus**, a bilingual (Khmer / English) library management system.
It handles books and copies, borrowing and returns, reservation queues, fines, members, e-books, reports, real-time notifications and an AI assistant.

Built with **Laravel 13**, **PostgreSQL**, **Sanctum** and **Laravel Reverb**.

- Frontend (Vue 3): [Chana758/SYSTEM_LMS](https://github.com/Chana758/SYSTEM_LMS)

---

## Features

- **Auth:** register and log in with Sanctum tokens (rate limited), forgot and reset password, profile and avatar.
- **Roles:** `admin`, `librarian` and `member`, checked on every route. Policies check ownership, for example a member can only cancel their own reservation.
- **Catalog:** books, categories, physical copies and book reviews.
- **Borrowing:** borrow and return copies, track overdue books.
- **Reservations:** a queue per book. An expired reservation passes the book to the next member in line.
- **Fines** for late returns, and a "my fines" page for members.
- **Members and staff:** member management, membership types, librarian accounts and role changes (admin).
- **Scanning:** look up books and members by code. A phone can pair with the desktop using a 6-digit PIN (3 attempts) and send scans to it.
- **Digital library:** e-books with categories and favourites.
- **Dashboard and reports:** borrowing, fines, members, stock and revenue, exported as PDF (dompdf) or Excel.
- **Notifications** pushed in real time with Laravel Reverb (WebSockets).
- **AI assistant and book recommendations** through the Groq API (OpenAI-compatible). The chatbot route is rate limited.
- **Settings** and **database backup / restore** (admin only).
- **Demo mode** that fills the database with sample data for a public demo.

---

## Tech stack

| Area | Tools |
|---|---|
| Language and framework | PHP 8.3+, Laravel 13 |
| Database | PostgreSQL |
| Auth | Laravel Sanctum |
| Real time | Laravel Reverb |
| PDF and Excel | barryvdh/laravel-dompdf, maatwebsite/excel |
| AI | Groq API via Laravel HTTP client |

---

## API overview

All routes are in `routes/api.php` and start with `/api`. Almost every route needs `Authorization: Bearer <token>`.

| Area | Prefix | Who |
|---|---|---|
| Auth | `/auth` | Guest, then logged in |
| Books, categories, copies | `/books`, `/categories`, `/book-copies` | Everyone reads, staff edit |
| Borrowing | `/borrows` | Staff |
| Reservations | `/reservations` | Members (own), staff (all) |
| E-books | `/ebooks`, `/my-favorites` | Everyone reads, staff edit |
| Members and staff | `/members`, `/librarians`, `/users/{user}/role` | Staff, admin |
| Fines | `/fines`, `/my-fines` | Staff, member (own) |
| Scanning | `/scan` | Staff |
| Settings | `/settings` | Staff, admin |
| Dashboard and reports | `/dashboard`, `/reports` | Staff, admin for revenue and export |
| Notifications | `/notifications` | Everyone, staff send |
| AI | `/chatbot`, `/recommendations` | Logged in |

"Staff" means `admin` or `librarian`.

---

## Getting started

### Requirements
- PHP 8.3+ and Composer
- PostgreSQL 14+

### Install

```bash
git clone https://github.com/Chana758/SYSTEM_LMS_API.git
cd SYSTEM_LMS_API
composer install
cp .env.example .env
php artisan key:generate
```

Create a PostgreSQL database named `lms`, then fill in `.env`:

| Variable | What to put |
|---|---|
| `DB_*` | Your PostgreSQL connection |
| `ADMIN_NAME`, `ADMIN_EMAIL`, `ADMIN_PASSWORD` | The first admin account, created by the seeder |
| `REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET` | Any values; the frontend needs the same app key |
| `GROQ_API_KEY` | Optional, only for the AI assistant |
| `FRONTEND_URL` | The frontend address, for CORS and links |

```bash
php artisan migrate --seed     # tables, membership types and the admin account
php artisan storage:link       # public URLs for covers, avatars and e-books
php artisan serve              # http://127.0.0.1:8000
php artisan reverb:start       # real-time server on port 8080 (second terminal)
```

### Demo mode

Set `DEMO_MODE=true` and `DEMO_PASSWORD` in `.env`, then run `php artisan migrate:fresh --seed` to load sample data instead of the single admin account.

---

## Commands

| Command | What it does |
|---|---|
| `php artisan reservations:expire` | Expire old reservations and give the book to the next member in the queue |
| `php artisan notifications:overdue` | Send overdue reminders to members |

Both commands run by hand for now. The next step is to add them to the Laravel scheduler so they run every day.

---

## Author

**Sam Channa**, ITE student at the Royal University of Phnom Penh

- GitHub: [@Chana758](https://github.com/Chana758)
- Email: csam26176@gmail.com

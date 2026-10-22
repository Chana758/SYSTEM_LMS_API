<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ebook;
use App\Models\EbookReadingProgress;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

class EbookController extends Controller
{
    /** List e-books with search, category and format filters, based on access rules. */
    public function index(Request $request)
    {
        $user = $request->user();
        $isStaff = in_array($user->role, ['admin', 'librarian']);

        $query = Ebook::with('book.category');

        if (!$isStaff) {
            $query->whereIn('access_type', ['public', 'member_only']);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->whereHas('book', fn ($q) => $q->where('title', 'like', "%{$search}%"));
        }

        if ($request->filled('category_id')) {
            $query->whereHas('book', fn ($q) => $q->where('category_id', $request->category_id));
        }

        if ($request->filled('format')) {
            $query->where('format', $request->format);
        }

        return response()->json($query->paginate($request->get('per_page', 12)));
    }

    /** Show one e-book with the user's reading progress, without exposing the file path. */
    public function show(Request $request, Ebook $ebook)
    {
        $ebook->increment('view_count');

        $data = $ebook->load('book.category')->toArray();

        // Never leak the raw storage path to the client.
        unset($data['file_url']);

        // Attach current user's reading progress (if any) for convenience.
        $progress = EbookReadingProgress::where('user_id', $request->user()->id)
            ->where('ebook_id', $ebook->id)
            ->first();

        $data['reading_progress'] = $progress ? [
            'last_page'    => $progress->last_page,
            'percentage'   => (float) $progress->percentage,
            'last_read_at' => $progress->last_read_at,
        ] : null;

        return response()->json($data);
    }

    /** Issue a 10-minute signed URL to stream the file, if downloads are allowed. */
    public function download(Request $request, Ebook $ebook)
    {
        if (!$ebook->is_downloadable) {
            return response()->json(['message' => 'This e-book is not available for download.'], 403);
        }

        $ebook->increment('download_count');

        $signedUrl = URL::temporarySignedRoute(
            'ebooks.stream',
            now()->addMinutes(10),
            ['ebook' => $ebook->id]
        );

        return response()->json([
            'file_url' => $signedUrl,
            'format'   => $ebook->format,
            'expires_in_minutes' => 10,
        ]);
    }

    /** Serve the actual file; only reachable through a valid signed URL. */
    public function stream(Request $request, Ebook $ebook)
    {
        if (!$request->hasValidSignature()) {
            abort(403, 'Invalid or expired link.');
        }

        if (!Storage::disk('local')->exists($ebook->file_url)) {
            abort(404, 'File not found.');
        }

        return Storage::disk('local')->response($ebook->file_url);
    }

    /** Get the logged-in user's saved reading progress (defaults to page 1, 0%). */
    public function getProgress(Request $request, Ebook $ebook)
    {
        $progress = EbookReadingProgress::where('user_id', $request->user()->id)
            ->where('ebook_id', $ebook->id)
            ->first();

        return response()->json($progress ?: [
            'last_page'  => 1,
            'percentage' => 0,
        ]);
    }

    /** Save or update the logged-in user's reading progress. */
    public function updateProgress(Request $request, Ebook $ebook)
    {
        $validated = $request->validate([
            'last_page'  => ['required', 'integer', 'min:1'],
            'percentage' => ['required', 'numeric', 'min:0', 'max:100'],
        ]);

        $progress = EbookReadingProgress::updateOrCreate(
            ['user_id' => $request->user()->id, 'ebook_id' => $ebook->id],
            [
                'last_page'    => $validated['last_page'],
                'percentage'   => $validated['percentage'],
                'last_read_at' => now(),
            ]
        );

        return response()->json(['message' => 'Progress saved.', 'data' => $progress]);
    }

    /** Upload and create a new e-book, stored privately. */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'book_id'         => ['required', 'exists:books,id'],
            'file'            => ['required', 'file', 'mimes:pdf,epub,mobi', 'max:51200'], // 50MB max
            'format'          => ['required', 'in:pdf,epub,mobi'],
            'is_downloadable' => ['boolean'],
            'access_type'     => ['required', 'in:public,member_only'],
        ]);

        // Store privately — NOT the public disk, so files can't be guessed/accessed directly.
        $path = $request->file('file')->store('ebooks', 'local');

        $ebook = Ebook::create([
            'book_id'         => $validated['book_id'],
            'file_url'        => $path,
            'format'          => $validated['format'],
            'file_size'       => $request->file('file')->getSize(),
            'is_downloadable' => $validated['is_downloadable'] ?? true,
            'access_type'     => $validated['access_type'],
        ]);

        return response()->json([
            'message' => 'E-book added successfully.',
            'data'    => $ebook->load('book.category'),
        ], 201);
    }

    /** Update an e-book and replace its file if a new one is uploaded. */
    public function update(Request $request, Ebook $ebook)
    {
        $validated = $request->validate([
            'file'            => ['sometimes', 'file', 'mimes:pdf,epub,mobi', 'max:51200'],
            'format'          => ['sometimes', 'in:pdf,epub,mobi'],
            'is_downloadable' => ['boolean'],
            'access_type'     => ['sometimes', 'in:public,member_only'],
        ]);

        if ($request->hasFile('file')) {
            if ($ebook->file_url) {
                Storage::disk('local')->delete($ebook->file_url);
            }
            $validated['file_url']  = $request->file('file')->store('ebooks', 'local');
            $validated['file_size'] = $request->file('file')->getSize();
            unset($validated['file']);
        }

        $ebook->update($validated);

        return response()->json([
            'message' => 'E-book updated successfully.',
            'data'    => $ebook->fresh()->load('book.category'),
        ]);
    }

    /** Remove an e-book (soft delete). */
    public function destroy(Ebook $ebook)
    {
        $ebook->delete();

        return response()->json(['message' => 'E-book removed successfully.']);
    }
}
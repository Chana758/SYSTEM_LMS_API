<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Backup;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Process;

class BackupController extends Controller
{
    /**
     * GET /settings/backup/history
     * Accessible by: admin, librarian
     */
    public function index(Request $request)
    {
        $backups = Backup::with('performer:id,name')
            ->orderByDesc('created_at')
            ->paginate(15);

        return response()->json($backups);
    }

    /**
     * GET /settings/backup/create
     * Accessible by: admin only
     * Creates a PostgreSQL dump and streams it for download.
     */
    public function create(Request $request)
    {
        $filename = 'backup-' . now()->format('Y-m-d_His') . '.sql';
        $path = storage_path('app/backups/' . $filename);

        if (!is_dir(storage_path('app/backups'))) {
            mkdir(storage_path('app/backups'), 0755, true);
        }

        $host = config('database.connections.pgsql.host');
        $port = config('database.connections.pgsql.port');
        $db   = config('database.connections.pgsql.database');
        $user = config('database.connections.pgsql.username');
        $pass = config('database.connections.pgsql.password');

        // FIX: escape EVERY variable interpolated into the shell command,
        // not just $path and $db. Even though host/port/user come from
        // config() rather than direct user input, treating all shell
        // arguments as untrusted is the correct defensive default —
        // config values can still change (multi-tenant setups, env
        // injection, etc.) and cost of escaping is zero.
        $result = Process::env(['PGPASSWORD' => $pass])->run(
            'pg_dump -h ' . escapeshellarg($host) .
            ' -p ' . escapeshellarg($port) .
            ' -U ' . escapeshellarg($user) .
            ' --no-owner --no-privileges' .
            ' -f ' . escapeshellarg($path) .
            ' ' . escapeshellarg($db)
        );

        // Log the attempt regardless of outcome
        if ($result->failed() || !file_exists($path)) {
            Backup::create([
                'performed_by' => $request->user()->id,
                'file_name' => $filename,
                'file_path' => $path,
                'type' => 'manual',
                'status' => 'failed',
                'error_message' => $result->errorOutput() ?: 'Backup file was not created.',
            ]);

            return response()->json([
                'message' => 'Failed to create backup: ' . $result->errorOutput(),
            ], 500);
        }

        Backup::create([
            'performed_by' => $request->user()->id,
            'file_name' => $filename,
            'file_path' => $path,
            'file_size' => filesize($path),
            'type' => 'manual',
            'status' => 'success',
        ]);

        return response()->download($path, $filename)->deleteFileAfterSend(true);
    }

    /**
     * POST /settings/backup/restore
     * Accessible by: admin only
     */
    public function restore(Request $request)
    {
        $request->validate([
            'backup_file' => ['required', 'file', 'mimes:sql,txt', 'max:51200'], // Max 50MB
            // FIX: require an explicit typed confirmation string from the client
            // so this destructive action can never fire from a stray/duplicate
            // request. The Vue layer pairs this with a JS confirm dialog (see
            // BackupSettings.vue below) — belt and suspenders.
            'confirm' => ['required', 'in:RESTORE'],
        ]);

        $file = $request->file('backup_file');
        $tempPath = $file->getRealPath();

        // FIX: basic sanity check on file content before executing it via
        // psql. This is NOT full SQL validation (that's not realistically
        // possible/desirable here) — it's a cheap guard against obviously
        // wrong uploads (empty file, binary garbage, non-SQL text) so we
        // don't silently wreck the DB connection on garbage input.
        $preview = file_get_contents($tempPath, false, null, 0, 4096);

        if (trim($preview) === '') {
            return response()->json([
                'message' => 'Failed to restore backup: the uploaded file is empty.',
            ], 422);
        }

        // Reject files that don't look like plain SQL text at all
        // (e.g. accidental binary/image upload renamed to .sql).
        if (!mb_check_encoding($preview, 'UTF-8') && !mb_check_encoding($preview, 'ASCII')) {
            Backup::create([
                'performed_by' => $request->user()->id,
                'file_name' => $file->getClientOriginalName(),
                'file_path' => '(restore - not stored)',
                'type' => 'manual',
                'status' => 'failed',
                'error_message' => 'Uploaded file does not appear to be a valid SQL text file.',
            ]);

            return response()->json([
                'message' => 'Failed to restore backup: file does not look like a valid SQL dump.',
            ], 422);
        }

        $host = config('database.connections.pgsql.host');
        $port = config('database.connections.pgsql.port');
        $db   = config('database.connections.pgsql.database');
        $user = config('database.connections.pgsql.username');
        $pass = config('database.connections.pgsql.password');

        // FIX: same full-escaping treatment as create()
        $result = Process::env(['PGPASSWORD' => $pass])->run(
            'psql -h ' . escapeshellarg($host) .
            ' -p ' . escapeshellarg($port) .
            ' -U ' . escapeshellarg($user) .
            ' -d ' . escapeshellarg($db) .
            ' -f ' . escapeshellarg($tempPath)
        );

        if ($result->failed()) {
            Backup::create([
                'performed_by' => $request->user()->id,
                'file_name' => $file->getClientOriginalName(),
                'file_path' => '(restore - not stored)',
                'type' => 'manual',
                'status' => 'failed',
                'error_message' => $result->errorOutput(),
            ]);

            return response()->json([
                'message' => 'Failed to restore backup: ' . $result->errorOutput(),
            ], 500);
        }

        Backup::create([
            'performed_by' => $request->user()->id,
            'file_name' => $file->getClientOriginalName(),
            'file_path' => '(restore - not stored)',
            'file_size' => $file->getSize(),
            'type' => 'manual',
            'status' => 'success',
        ]);

        return response()->json([
            'message' => 'Database restored successfully.',
        ]);
    }
}
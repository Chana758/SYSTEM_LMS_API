<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Backup;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Process;

class BackupController extends Controller
{
    /** List backup history, newest first. */
    public function index(Request $request)
    {
        $backups = Backup::with('performer:id,name')
            ->orderByDesc('created_at')
            ->paginate(15);

        return response()->json($backups);
    }

    /** Create a PostgreSQL dump, log it, and send it as a download. */
    public function create(Request $request)
    {
        $filename = 'backup-' . now()->format('Y-m-d_His') . '.sql';
        $dir = storage_path('app/backups');
        $path = $dir . '/' . $filename;

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $conn = config('database.connections.pgsql');

        $result = Process::timeout(300)
            ->env(['PGPASSWORD' => (string) ($conn['password'] ?? '')])
            ->run([
                config('database.pg_tools.dump'),
                '-h', (string) $conn['host'],
                '-p', (string) $conn['port'],
                '-U', (string) $conn['username'],
                '--no-owner',
                '--no-privileges',
                '--clean',        // so the dump can be restored over an existing DB
                '--if-exists',
                '-f', $path,
                (string) $conn['database'],
            ]);

        if ($result->failed() || !file_exists($path)) {
            $error = trim($result->errorOutput()) ?: 'Backup file was not created.';

            Backup::create([
                'performed_by' => $request->user()->id,
                'file_name' => $filename,
                'file_path' => $path,
                'type' => 'manual',
                'status' => 'failed',
                'error_message' => $error,
            ]);

            return response()->json([
                'message' => 'Failed to create backup: ' . $error,
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

    /** Restore the database from an uploaded .sql file (requires typing RESTORE to confirm). */
    public function restore(Request $request)
    {
        $request->validate([
            'backup_file' => ['required', 'file', 'mimes:sql,txt', 'max:51200'], // Max 50MB
            // Explicit typed confirmation so this destructive action can't fire by accident.
            'confirm' => ['required', 'in:RESTORE'],
        ]);

        $file = $request->file('backup_file');
        $tempPath = $file->getRealPath();

        // Cheap sanity check: reject empty or obviously non-text uploads.
        $preview = file_get_contents($tempPath, false, null, 0, 4096);

        if ($preview === false || trim($preview) === '') {
            return response()->json([
                'message' => 'Failed to restore backup: the uploaded file is empty.',
            ], 422);
        }

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

        $conn = config('database.connections.pgsql');

        $result = Process::timeout(600)
            ->env(['PGPASSWORD' => (string) ($conn['password'] ?? '')])
            ->run([
                config('database.pg_tools.psql'),
                '-h', (string) $conn['host'],
                '-p', (string) $conn['port'],
                '-U', (string) $conn['username'],
                '-d', (string) $conn['database'],
                '-v', 'ON_ERROR_STOP=1',
                '-f', $tempPath,
            ]);

        if ($result->failed()) {
            $error = trim($result->errorOutput()) ?: 'Restore failed.';

            Backup::create([
                'performed_by' => $request->user()->id,
                'file_name' => $file->getClientOriginalName(),
                'file_path' => '(restore - not stored)',
                'type' => 'manual',
                'status' => 'failed',
                'error_message' => $error,
            ]);

            return response()->json([
                'message' => 'Failed to restore backup: ' . $error,
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
<?php

namespace Database\Seeders\Demo;

use App\Models\Book;
use App\Models\Ebook;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class DemoEbookSeeder extends Seeder
{
    public function run(): void
    {
        $this->guardDatabase();

        DB::transaction(function () {

            $items = [
                [
                    'isbn' => 'DEMO-LMS-ISBN-001',
                    'path' => 'ebooks/demo/demo-ebook-001.pdf',
                    'title' => 'Introduction to Demo Library',
                ],
                [
                    'isbn' => 'DEMO-LMS-ISBN-005',
                    'path' => 'ebooks/demo/demo-ebook-002.pdf',
                    'title' => 'Introduction to Clean Code',
                ],
            ];

            foreach ($items as $item) {

                $book = Book::where('isbn', $item['isbn'])->first();

                if (! $book) {
                    throw new \RuntimeException(
                        "Demo book not found: {$item['isbn']}"
                    );
                }

                $path = $item['path'];

                $ebook = Ebook::withTrashed()
                    ->where('file_url', $path)
                    ->first();

                if ($ebook && $ebook->book_id !== $book->id) {
                    throw new \RuntimeException(
                        "Demo ebook path belongs to another book: {$path}"
                    );
                }

                $pdf = $this->makePdf($item['title']);

                if (Storage::disk('local')->exists($path)) {

                    $existingContent = Storage::disk('local')->get($path);

                    if (! str_starts_with($existingContent, "%PDF-1.4\n% DEMO-LMS-SEED")) {
                        throw new \RuntimeException(
                            "Refusing to overwrite a non-Demo file: {$path}"
                        );
                    }

                } else {
                    Storage::disk('local')->put($path, $pdf);
                }

                $ebook ??= new Ebook();

                $ebook->fill([
                    'book_id' => $book->id,
                    'file_url' => $path,
                    'format' => 'pdf',
                    'file_size' => Storage::disk('local')->size($path),
                    'download_count' => 0,
                    'view_count' => 0,
                    'is_downloadable' => true,
                    'access_type' => 'public',
                ]);

                $ebook->deleted_at = null;
                $ebook->save();
            }
        });
    }

    private function makePdf(string $title): string
    {
        $title = preg_replace('/[^A-Za-z0-9 ]/', '', $title);

        $stream = "BT\n"
            . "/F1 20 Tf\n"
            . "72 720 Td\n"
            . "({$title}) Tj\n"
            . "0 -40 Td\n"
            . "/F1 12 Tf\n"
            . "(This is a sample PDF created for Demo LMS.) Tj\n"
            . "0 -25 Td\n"
            . "(No real book content is included.) Tj\n"
            . "ET";

        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            "<< /Length " . strlen($stream) . " >>\nstream\n{$stream}\nendstream",
        ];

        $pdf = "%PDF-1.4\n% DEMO-LMS-SEED\n";

        $offsets = [0];

        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);

            $pdf .= ($index + 1) . " 0 obj\n";
            $pdf .= $object . "\n";
            $pdf .= "endobj\n";
        }

        $xrefOffset = strlen($pdf);

        $pdf .= "xref\n";
        $pdf .= "0 6\n";
        $pdf .= "0000000000 65535 f \n";

        foreach (array_slice($offsets, 1) as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        $pdf .= "trailer\n";
        $pdf .= "<< /Size 6 /Root 1 0 R >>\n";
        $pdf .= "startxref\n";
        $pdf .= $xrefOffset . "\n";
        $pdf .= "%%EOF";

        return $pdf;
    }

    private function guardDatabase(): void
    {
        $expected = config('demo.database_name');
        $actual = DB::connection()->getDatabaseName();

        if (
            ! config('demo.enabled') ||
            ! app()->environment('production') ||
            ! $expected ||
            $actual !== $expected
        ) {
            throw new \RuntimeException(
                'Demo Ebook Seeder: database guard failed.'
            );
        }
    }
}

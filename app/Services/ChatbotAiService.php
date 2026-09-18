<?php

namespace App\Services;

use App\Models\Book;
use App\Models\BorrowTransaction;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ChatbotAiService
{
    private string $apiKey;
    private string $model;
    private $user;

    /** Safety cap on the tool-calling loop below. */
    private const MAX_TOOL_ROUNDS = 5;

    /** Keep responses short and cheap; raise if you want longer answers. */
    private const MAX_TOKENS = 600;

    public function __construct()
    {
        // Groq's API is OpenAI-compatible, so this is the only place
        // that changed compared to the OpenAI version: different config
        // keys and a model that Groq actually hosts.
        $this->apiKey = config('services.groq.key');
        $this->model = config('services.groq.model', 'openai/gpt-oss-120b');
    }

    public function ask(string $userMessage, $user): string
    {
        $this->user = $user;

        $messages = [
            ['role' => 'system', 'content' => $this->systemPrompt()],
            ['role' => 'user', 'content' => $userMessage],
        ];

        for ($i = 0; $i < self::MAX_TOOL_ROUNDS; $i++) {
            $response = $this->callOpenAi($messages);

            $choice = $response['choices'][0]['message'] ?? null;
            if (! $choice) {
                Log::error('OpenAI chatbot: no choice in response', ['response' => $response]);
                return 'សូមទោស មានបញ្ហាបច្ចេកទេសកើតឡើង។ សូមសាកល្បងម្តងទៀត។';
            }

            if (empty($choice['tool_calls'])) {
                return trim($choice['content'] ?? 'សូមទោស ខ្ញុំមិនអាចឆ្លើយបានពេលនេះទេ។');
            }

            $messages[] = $choice;

            foreach ($choice['tool_calls'] as $toolCall) {
                $result = $this->executeTool(
                    $toolCall['function']['name'],
                    json_decode($toolCall['function']['arguments'] ?? '{}', true) ?? []
                );

                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $toolCall['id'],
                    'content' => json_encode($result, JSON_UNESCAPED_UNICODE),
                ];
            }
        }

        return 'សូមទោស សំណួរនេះស្មុគស្មាញពេក។ សូមសាកល្បងសួរម្តងទៀតតាមរបៀបផ្សេង។';
    }

    private function systemPrompt(): string
    {
        return <<<PROMPT
អ្នកគឺជា "Library Assistant" — ជំនួយការឆ្លាតវៃរបស់ប្រព័ន្ធគ្រប់គ្រងបណ្ណាល័យ ដែលមានសមត្ថភាពដូច ChatGPT ឬ Claude ក្នុងការឆ្លើយសំណួរទូទៅផ្សេងៗបានដោយឆ្លាតវៃ មិនកំណត់ត្រឹមតែប្រធានបទបណ្ណាល័យទេ។

ច្បាប់ភាសា:
- ត្រូវឆ្លើយជាភាសាដូចគ្នានឹងអ្នកប្រើប្រាស់សរសេរមក។ ខ្មែរ→ខ្មែរ, English→English, ភាសាផ្សេងទៀត→ភាសានោះដែរ។ កុំបកប្រែដោយស្វ័យប្រវត្តិ។

ច្បាប់ទម្រង់ (សំខាន់):
- កុំប្រើ markdown formatting ដូចជា **bold**, # headers, ឬ bullet list ដែលចាប់ផ្តើមដោយ "- " ព្រោះកន្លែងបង្ហាញមិនអាច render markdown បានទេ។ សរសេរជាអត្ថបទធម្មតា ចែកជាកថាខណ្ឌខ្លីៗ ឬប្រើលេខ (1, 2, 3) បើត្រូវការរាយបញ្ជី។

ច្បាប់ទិន្នន័យ:
- ចំពោះសំណួរទាក់ទងទិន្នន័យផ្ទាល់ខ្លួន (ថ្ងៃត្រូវសង, ប្រាក់ពិន័យ, ស្វែងរកសៀវភៅក្នុងស្តុក) ត្រូវហៅ tool ដែលមានឲ្យជានិច្ច កុំសន្មត់ចម្លើយដោយខ្លួនឯង។
- ចំពោះសំណួរទូទៅ (មិនទាក់ទងទិន្នន័យបណ្ណាល័យ ដូចជា គន្លឹះអាន, ការណែនាំសៀវភៅតាមប្រភេទ, ចំណេះដឹងទូទៅ) អាចឆ្លើយផ្ទាល់ពីចំណេះដឹងរបស់អ្នកបាន។
- ប្រាក់ពិន័យទាំងអស់គិតជារៀល (KHR) មិនមែន USD ទេ។

ព័ត៌មានទូទៅ:
- ម៉ោងបើកបណ្ណាល័យ: 8:00 ព្រឹក ដល់ 6:00 ល្ងាច ចន្ទ ដល់ សុក្រ។
- និយាយសុភាព រួសរាយ និងខ្លីល្មម។
PROMPT;
    }

    private function tools(): array
    {
        return [
            [
                'type' => 'function',
                'function' => [
                    'name' => 'get_due_dates',
                    'description' => 'Get the current user\'s currently-borrowed books and their due dates.',
                    'parameters' => ['type' => 'object', 'properties' => new \stdClass()],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'get_fines',
                    'description' => 'Get the current user\'s total unpaid fine amount, in KHR.',
                    'parameters' => ['type' => 'object', 'properties' => new \stdClass()],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'search_books',
                    'description' => 'Search the library catalog by title or author keyword.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'keyword' => ['type' => 'string', 'description' => 'Title or author keyword to search for'],
                        ],
                        'required' => ['keyword'],
                    ],
                ],
            ],
        ];
    }

    private function executeTool(string $name, array $args): mixed
    {
        return match ($name) {
            'get_due_dates' => $this->toolGetDueDates(),
            'get_fines' => $this->toolGetFines(),
            'search_books' => $this->toolSearchBooks($args['keyword'] ?? ''),
            default => ['error' => 'Unknown tool'],
        };
    }

    private function toolGetDueDates(): array
    {
        $member = $this->user->member;
        if (! $member) {
            return ['error' => 'This account has no linked member profile.'];
        }

        $borrows = BorrowTransaction::where('member_id', $member->id)
            ->where('status', 'borrowed')
            ->with('bookCopy.book')
            ->orderBy('due_date')
            ->get();

        if ($borrows->isEmpty()) {
            return ['borrowed_books' => [], 'message' => 'No books currently borrowed.'];
        }

        return [
            'borrowed_books' => $borrows->map(fn ($b) => [
                'title' => $b->bookCopy?->book?->title,
                'due_date' => \Carbon\Carbon::parse($b->due_date)->format('Y-m-d'),
            ])->values(),
        ];
    }

    private function toolGetFines(): array
    {
        $member = $this->user->member;
        if (! $member) {
            return ['error' => 'This account has no linked member profile.'];
        }

        $unpaid = $member->fines()->where('status', 'unpaid')->sum('amount');

        return ['unpaid_total' => round($unpaid, 0), 'currency' => 'KHR'];
    }

    private function toolSearchBooks(string $keyword): array
    {
        if (trim($keyword) === '') {
            return ['error' => 'No keyword provided.'];
        }

        $books = Book::where('title', 'like', "%{$keyword}%")
            ->orWhere('author', 'like', "%{$keyword}%")
            ->limit(5)
            ->get(['title', 'author', 'available_qty']);

        return ['results' => $books];
    }

    private function callOpenAi(array $messages): array
    {
        // Only the host changed vs. the OpenAI version — Groq speaks the
        // same chat-completions + tool-calling JSON shape.
        $response = Http::withToken($this->apiKey)
            ->timeout(20)
            ->post('https://api.groq.com/openai/v1/chat/completions', [
                'model' => $this->model,
                'messages' => $messages,
                'tools' => $this->tools(),
                'tool_choice' => 'auto',
                'temperature' => 0.4,
                'max_tokens' => self::MAX_TOKENS,
            ]);

        if ($response->failed()) {
            Log::error('Groq chatbot call failed', ['status' => $response->status(), 'body' => $response->body()]);
            return [];
        }

        return $response->json();
    }
}
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChatHistory;
use App\Services\ChatbotAiService;
use Illuminate\Http\Request;

class ChatbotController extends Controller
{
    public function __construct(private ChatbotAiService $ai) {}

    public function index(Request $request)
    {
        $history = ChatHistory::where('user_id', $request->user()->id)
            ->latest()
            ->paginate($request->per_page ?? 20);

        return response()->json($history);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:500'],
        ]);

        $user = $request->user();
        $reply = $this->ai->ask($validated['message'], $user);

        $chat = ChatHistory::create([
            'user_id' => $user->id,
            'message' => $validated['message'],
            'response' => $reply,
        ]);

        return response()->json(['data' => $chat], 201);
    }

    public function clear(Request $request)
    {
        ChatHistory::where('user_id', $request->user()->id)->delete();

        return response()->json(['message' => 'Chat history cleared.']);
    }
}
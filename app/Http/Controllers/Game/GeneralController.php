<?php

namespace App\Http\Controllers\Game;

use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Models\GameComment;
use App\Models\GameVote;
use Illuminate\Http\Request;

class GeneralController extends Controller
{
    public function index()
    {
        $query = request()->query('query', '');
        $genre = request()->query('genre', '');
        $sort = request()->query('sort', 'popular');

        $gamesQuery = Game::with('creator');

        if ($query) {
            $gamesQuery->where('title', 'like', "%{$query}%")
                ->orWhere('description', 'like', "%{$query}%");
        }

        if ($genre) {
            $gamesQuery->where('genre', $genre);
        }

        switch ($sort) {
            case 'newest':
                $gamesQuery->orderBy('created_at', 'desc');
                break;
            case 'rated':
                $gamesQuery->orderBy('likes_count', 'desc');
                break;
            case 'played':
                $gamesQuery->orderBy('plays_count', 'desc');
                break;
            default:
                $gamesQuery->orderBy('plays_count', 'desc');
                break;
        }

        $games = $gamesQuery->paginate(12);

        return response()->json($games);
    }

    public function show($id)
    {
        $user = app('token_user');
        
        $game = Game::where('id', $id)
            ->with('creator')
            ->with(['comments' => function ($query) {
                $query->orderBy('created_at', 'desc');
            }, 'comments.user'])
            ->first();

        if (!$game) {
            return response()->json([
                'error' => 'Game not found'
            ], 404);
        }

        $userVote = null;
        if ($user) {
            $userVote = GameVote::where('game_id', $game->id)
                ->where('user_id', $user->id)
                ->value('vote');
        }

        $game->user_vote = $userVote;

        return response()->json([
            'data' => $game
        ]);
    }

    public function comments($id)
    {
        $game = Game::where('id', $id)->first();

        if (!$game) {
            return response()->json([
                'error' => 'Game not found'
            ], 404);
        }

        $comments = $game->comments()->with('user')->orderBy('created_at', 'desc')->get();

        return response()->json($comments);
    }

    public function comment($id, Request $request)
    {
        $game = Game::where('id', $id)->first();

        if (!$game) {
            return response()->json([
                'error' => 'Game not found'
            ], 404);
        }

        $user = app('token_user');

        $comment = GameComment::create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'content' => $request->input('content')
        ]);

        return response()->json($comment, 201);
    }

    public function like($id)
    {
        $game = Game::where('id', $id)->first();

        if (!$game) {
            return response()->json([
                'error' => 'Game not found'
            ], 404);
        }

        $user = app('token_user');

        $existingVote = GameVote::where('game_id', $game->id)
            ->where('user_id', $user->id)
            ->first();

        if ($existingVote) {
            if ($existingVote->vote === 'like') {
                // User already liked, remove the like
                $existingVote->delete();
                $game->likes_count = $game->likes_count - 1;
            } else {
                // User had disliked, change to like
                $existingVote->update(['vote' => 'like']);
                $game->likes_count = $game->likes_count + 1;
                $game->dislikes_count = $game->dislikes_count - 1;
            }
        } else {
            // New like
            GameVote::create([
                'game_id' => $game->id,
                'user_id' => $user->id,
                'vote' => 'like'
            ]);
            $game->likes_count = $game->likes_count + 1;
        }

        $game->save();

        return response()->json([
            'likes_count' => $game->likes_count,
            'dislikes_count' => $game->dislikes_count,
            'like_ratio' => $game->like_ratio,
            'user_vote' => $existingVote ? ($existingVote->vote === 'like' ? null : 'like') : 'like'
        ]);
    }

    public function create(Request $request)
    {
        $user = app('token_user');

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'required|string',
            'genre' => 'required|string|max:50',
            'max_players' => 'required|integer|min:1|max:100',
        ]);

        $game = Game::create([
            'title' => $validated['title'],
            'description' => $validated['description'],
            'creator_id' => $user->id,
            'genre' => $validated['genre'],
            'max_players' => $validated['max_players'],
            'plays_count' => 0,
            'likes_count' => 0,
            'dislikes_count' => 0,
        ]);

        // Handle thumbnail upload if provided
        if ($request->hasFile('thumbnail')) {
            $path = $request->file('thumbnail')->store('games/thumbnails', 'public');
            $game->thumbnail_url = $path;
            $game->save();
        }

        return response()->json([
            'data' => $game
        ], 201);
    }

    public function dislike($id)
    {
        $game = Game::where('id', $id)->first();

        if (!$game) {
            return response()->json([
                'error' => 'Game not found'
            ], 404);
        }

        $user = app('token_user');

        $existingVote = GameVote::where('game_id', $game->id)
            ->where('user_id', $user->id)
            ->first();

        if ($existingVote) {
            if ($existingVote->vote === 'dislike') {
                // User already disliked, remove the dislike
                $existingVote->delete();
                $game->dislikes_count = $game->dislikes_count - 1;
            } else {
                // User had liked, change to dislike
                $existingVote->update(['vote' => 'dislike']);
                $game->dislikes_count = $game->dislikes_count + 1;
                $game->likes_count = $game->likes_count - 1;
            }
        } else {
            // New dislike
            GameVote::create([
                'game_id' => $game->id,
                'user_id' => $user->id,
                'vote' => 'dislike'
            ]);
            $game->dislikes_count = $game->dislikes_count + 1;
        }

        $game->save();

        return response()->json([
            'likes_count' => $game->likes_count,
            'dislikes_count' => $game->dislikes_count,
            'like_ratio' => $game->like_ratio,
            'user_vote' => $existingVote ? ($existingVote->vote === 'dislike' ? null : 'dislike') : 'dislike'
        ]);
    }
}
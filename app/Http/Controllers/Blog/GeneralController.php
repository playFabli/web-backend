<?php

namespace App\Http\Controllers\Blog;

use App\Http\Controllers\Controller;
use App\Http\Requests\Blog\CreatePostRequest;
use App\Http\Requests\Blog\UpdatePostRequest;
use App\Models\BlogPost;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class GeneralController extends Controller
{
    /**
     * List published blog posts: featured post first, then the rest.
     */
    public function index()
    {
        $posts = Cache::remember('blog:posts:published', 60, function () {
            return BlogPost::where('is_published', true)
                ->where('is_deleted', false)
                ->with('user:id,username,avatar_frame_id')
                ->orderBy('is_featured', 'desc')
                ->orderBy('created_at', 'desc')
                ->get()
                ->toArray();
        });

        return response()->json([
            'data' => $posts,
        ], 200);
    }

    /**
     * Show a single published blog post.
     */
    public function show($id)
    {
        $post = Cache::remember("blog:post:{$id}", 60, function () use ($id) {
            return BlogPost::where('id', $id)
                ->where('is_published', true)
                ->where('is_deleted', false)
                ->with('user:id,username,avatar_frame_id')
                ->first()?->toArray();
        });

        if (! $post) {
            return response()->json([
                'message' => 'Post not found',
            ], 404);
        }

        return response()->json([
            'data' => $post,
        ], 200);
    }

    /**
     * Admin: create a new blog post.
     */
    public function create(CreatePostRequest $request)
    {
        $data = $request->validated();
        $user = app('token_user');

        $bannerPath = $this->handleBannerUpload($request, $user->id);

        $post = BlogPost::create([
            'title' => $data['title'],
            'body' => $data['body'],
            'banner_path' => $bannerPath,
            'user_id' => $user->id,
            'is_published' => true,
            'is_featured' => $data['is_featured'] ?? false,
        ]);

        $this->clearBlogCache();

        return response()->json([
            'data' => $post,
        ], 201);
    }

    /**
     * Admin: update an existing blog post.
     */
    public function update($id, UpdatePostRequest $request)
    {
        $post = BlogPost::where('id', $id)->first();
        if (! $post) {
            return response()->json([
                'message' => 'Post not found',
            ], 404);
        }

        $data = $request->validated();

        // Handle banner file upload
        if ($request->hasFile('banner')) {
            // Remove old banner if it exists
            if ($post->banner_path) {
                $oldFile = config('app.storage_directory').'/'.$post->banner_path;
                if (file_exists($oldFile)) {
                    @unlink($oldFile);
                }
            }
            $bannerPath = $this->handleBannerUpload($request, $post->user_id);
            $data['banner_path'] = $bannerPath;
        } elseif (! empty($data['remove_banner'])) {
            // Remove the existing banner
            if ($post->banner_path) {
                $oldFile = config('app.storage_directory').'/'.$post->banner_path;
                if (file_exists($oldFile)) {
                    @unlink($oldFile);
                }
            }
            $data['banner_path'] = null;
        }

        // remove_banner is not a database column
        unset($data['remove_banner']);

        $post->update($data);

        $this->clearBlogCache();

        return response()->json([
            'data' => $post->fresh()->load('user:id,username,avatar_frame_id'),
        ], 200);
    }

    /**
     * Admin: toggle the published state of a blog post.
     */
    public function publish($id)
    {
        $post = BlogPost::where('id', $id)->first();
        if (! $post) {
            return response()->json([
                'message' => 'Post not found',
            ], 404);
        }

        $post->is_published = ! $post->is_published;
        $post->save();

        $this->clearBlogCache();

        return response()->json([
            'data' => $post,
        ], 200);
    }

    /**
     * Admin: toggle the featured state of a blog post.
     * Only one post can be featured at a time.
     */
    public function feature($id)
    {
        $post = BlogPost::where('id', $id)->first();
        if (! $post) {
            return response()->json([
                'message' => 'Post not found',
            ], 404);
        }

        if (! $post->is_featured) {
            BlogPost::where('is_featured', true)->update(['is_featured' => false]);
        }

        $post->is_featured = ! $post->is_featured;
        $post->save();

        $this->clearBlogCache();

        return response()->json([
            'data' => $post,
        ], 200);
    }

    /**
     * Admin: soft-delete a blog post by toggling is_deleted.
     */
    public function delete($id)
    {
        $post = BlogPost::where('id', $id)->first();
        if (! $post) {
            return response()->json([
                'message' => 'Post not found',
            ], 404);
        }

        $post->is_deleted = ! $post->is_deleted;
        $post->save();

        $this->clearBlogCache();

        return response()->json([], 200);
    }

    /**
     * Handle banner image upload and return the relative storage path.
     * Returns null if no file was uploaded.
     */
    private function handleBannerUpload(UpdatePostRequest|CreatePostRequest $request, int $userId): ?string
    {
        if (! $request->hasFile('banner')) {
            return null;
        }

        $file = $request->file('banner');
        $filename = 'blog_'.time().'_'.$userId.'.'.$file->getClientOriginalExtension();

        $storageDir = rtrim(config('app.storage_directory'), '/\\').DIRECTORY_SEPARATOR.'banners';
        if (! is_dir($storageDir)) {
            mkdir($storageDir, 0755, true);
        }

        $file->move($storageDir, $filename);

        return 'banners/'.$filename;
    }

    /**
     * Clear all blog-related cache keys.
     */
    private function clearBlogCache(): void
    {
        DB::table('cache')
            ->where('key', 'like', config('cache.prefix', '').'blog:%')
            ->delete();
    }
}

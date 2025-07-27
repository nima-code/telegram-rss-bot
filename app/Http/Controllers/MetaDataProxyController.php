<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MetadataProxyController extends Controller
{
    public function proxy(Request $request)
    {
        $url = $request->query('url', '#');
        $title = $request->query('title', 'بدون عنوان');
        $description = $request->query('description', 'بدون توضیحات');
        $image = $request->query('image', 'https://telegram-rss-bot-kmgj.onrender.com/default-image.jpg');

        Log::info("Serving proxy metadata", [
            'url' => $url,
            'title' => $title,
            'description' => $description,
            'image' => $image
        ]);

        return view('proxy', [
            'url' => $url,
            'title' => htmlspecialchars($title, ENT_QUOTES, 'UTF-8'),
            'description' => htmlspecialchars($description, ENT_QUOTES, 'UTF-8'),
            'image' => htmlspecialchars($image, ENT_QUOTES, 'UTF-8')
        ]);
    }
}
?>
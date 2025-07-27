<!DOCTYPE html>
<html lang="fa">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title }}</title>
    <meta property="og:title" content="{{ $title }}">
    <meta property="og:description" content="{{ $description }}">
    <meta property="og:image" content="{{ $image }}">
    <meta property="og:type" content="article">
    <meta property="og:url" content="{{ $url }}">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $title }}">
    <meta name="twitter:description" content="{{ $description }}">
    <meta name="twitter:image" content="{{ $image }}">
    <script>
        window.location.href = "{{ $url }}";
    </script>
</head>
<body>
    <h1>{{ $title }}</h1>
    <p>{{ $description }}</p>
    <img src="{{ $image }}" alt="خبر">
    <p><a href="{{ $url }}">مشاهده خبر کامل</a></p>
</body>
</html>
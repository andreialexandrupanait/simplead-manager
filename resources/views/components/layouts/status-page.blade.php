<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'Status' }}</title>
    <style>
        :root {
            --primary-color: {{ $primaryColor ?? '#7C3AED' }};
        }
    </style>
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-screen bg-gray-50">
    {{ $slot }}
</body>
</html>

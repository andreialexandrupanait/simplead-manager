<!DOCTYPE html>
<html lang="{{ $language ?? 'ro' }}">
<head>
    <meta charset="utf-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
    @include('reports.styles')
</head>
<body>
    @php
        $lang = $language ?? 'ro';
    @endphp

    @include('reports.partials.closing')
</body>
</html>

<?php

require '/var/www/html/vendor/autoload.php';
$app = require_once '/var/www/html/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Site;
use App\Models\PerformanceTest;
use Illuminate\Support\Facades\Storage;

$sites = Site::whereNull('screenshot_path')->get();
$saved = 0;
$noData = 0;

foreach ($sites as $site) {
    $test = PerformanceTest::where('site_id', $site->id)
        ->where('device', 'desktop')
        ->where('status', 'completed')
        ->whereNotNull('screenshot_final')
        ->latest('tested_at')
        ->first();

    if (!$test || !str_contains($test->screenshot_final, 'base64,')) {
        $noData++;
        echo "No data: {$site->domain}\n";
        continue;
    }

    try {
        $base64 = explode('base64,', $test->screenshot_final, 2)[1];
        $imageData = base64_decode($base64);
        $src = imagecreatefromstring($imageData);
        if (!$src) { $noData++; continue; }

        $origW = imagesx($src);
        $origH = imagesy($src);
        $newW = 800;
        $newH = (int) round($origH * ($newW / $origW));

        $dst = imagecreatetruecolor($newW, $newH);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $origW, $origH);

        ob_start();
        imagejpeg($dst, null, 80);
        $jpeg = ob_get_clean();

        imagedestroy($src);
        imagedestroy($dst);

        $path = "screenshots/{$site->id}.jpg";
        Storage::disk('public')->put($path, $jpeg);
        $site->update(['screenshot_path' => $path]);
        $saved++;
        echo "Saved: {$site->domain} ({$origW}x{$origH})\n";
    } catch (\Exception $e) {
        echo "Error: {$site->domain} - " . $e->getMessage() . "\n";
    }
}

echo "\nSaved: {$saved}, No data: {$noData}\n";
echo "Total with screenshots: " . Site::whereNotNull('screenshot_path')->count() . " / " . Site::count() . "\n";

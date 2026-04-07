<?php

/**
 * One-off: builds config/category_image_map.php from categories.json + verified Icons8 URLs.
 * Run: php database/scripts/generate_category_image_map.php
 */

declare(strict_types=1);

$base = dirname(__DIR__, 2);
$jsonPath = $base.'/database/seeders/categories.json';
$outPath = $base.'/config/category_image_map.php';

$candidates = [
    'home', 'maintenance', 'dog', 'shopping-cart', 'cosmetic-brush', 'car', 'laptop', 'paint-palette',
    'megaphone', 'engineering', 'heart-health', 'graduation-cap', 'office', 'money-bag', 'delivery',
    'airplane-take-off', 'more', 'briefcase', 'broom', 'washing-machine', 'package', 'wrench', 'flower',
    'sofa', 'hammer', 'paint-brush', 'truck', 'van', 'book', 'calculator', 'pie-chart', 'map', 'ticket',
    'calendar', 'yoga', 'dumbbell', 'massage', 'apple', 'clipboard', 'pen', 'headset', 'keyboard',
    'microphone', 'scissors', 'chef-hat', 'smartphone', 'wifi', 'shield', 'bar-chart', 'money',
    'sprout', 'leaf', 'rake', 'toolbox', 'lock', 'star', 'gift', 'idea', 'globe', 'compass', 'video',
    'camera', 'music', 'tie', 'lipstick', 'hand', 'nail-polish', 'tire', 'water', 'brain', 'workflow',
    'ruler', 'pencil', 'box', 'iron', 'electric-plug', 'light-bulb', 'cpu', 'droplets', 'paint-bucket',
    'roller-brush', 'vacuum-cleaner', 'dishwasher', 'fridge', 'bed', 'bathtub', 'toilet', 'window',
    'door', 'fence', 'wheelbarrow', 'chainsaw', 'drill', 'pliers', 'screwdriver', 'tape-measure',
    'level-tool', 'safety-hat', 'hard-hat', 'fire-extinguisher', 'first-aid-kit', 'stethoscope',
    'pill', 'syringe', 'hospital', 'ambulance', 'bank', 'credit-card', 'coins', 'safe', 'chart',
    'presentation', 'whiteboard', 'projector', 'printer', 'fax', 'router', 'server', 'database',
    'cloud', 'usb', 'hdmi', 'bluetooth', 'nfc', 'qr-code', 'barcode', 'pdf', 'excel', 'word',
];

$pool = [];
foreach ($candidates as $n) {
    $url = "https://img.icons8.com/color/96/{$n}.png";
    $ctx = stream_context_create(['http' => ['timeout' => 3]]);
    $h = @get_headers($url, false, $ctx);
    if ($h && str_contains($h[0] ?? '', '200') && ! in_array($url, $pool, true)) {
        $pool[] = $url;
    }
    if (count($pool) >= 120) {
        break;
    }
}

if (count($pool) < 100) {
    fwrite(STDERR, 'Not enough verified Icons8 URLs: '.count($pool)."\n");

    exit(1);
}

$data = json_decode((string) file_get_contents($jsonPath), true, 512, JSON_THROW_ON_ERROR);

$lines = [];
$lines[] = '<?php';
$lines[] = '';
$lines[] = '/**';
$lines[] = ' * Auto-generated: parent + subcategory name (lowercase) → remote PNG source.';
$lines[] = ' * Each subcategory gets a distinct icon; run `php artisan categories:download-images` after changes.';
$lines[] = ' */';
$lines[] = '';
$norm = static function (string $name): string {
    $name = trim(preg_replace('/\s+/', ' ', $name));

    return mb_strtolower($name);
};

$entries = [];
foreach ($data as $block) {
    $entries[$norm($block['title'])] = true;
    foreach ($block['lists'] ?? [] as $sub) {
        $entries[$norm($sub['title'])] = true;
    }
}

ksort($entries, SORT_STRING);

if (count($entries) > count($pool)) {
    fwrite(STDERR, 'Pool too small: need '.count($entries).' have '.count($pool)."\n");

    exit(1);
}

$lines[] = 'return [';

$i = 0;
foreach (array_keys($entries) as $key) {
    $lines[] = "    '".addslashes($key)."' => '{$pool[$i]}',";
    $i++;
}

$lines[] = '];';
$lines[] = '';

file_put_contents($outPath, implode("\n", $lines));

echo "Wrote {$outPath} with {$i} entries using ".count($pool)." unique pool URLs.\n";

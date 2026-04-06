<?php

use App\Models\User;
use App\Models\Notification;
use Illuminate\Support\Facades\Schema;

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$user = User::first();
if (!$user) {
    echo "No user found to test.\n";
    exit(1);
}

// Ensure there are some notifications
Notification::create(['user_id' => $user->id, 'title' => 'Delete Test 1', 'body' => 'Body 1']);
Notification::create(['user_id' => $user->id, 'title' => 'Delete Test 2', 'body' => 'Body 2']);

$notif1 = Notification::where('title', 'Delete Test 1')->first();
$notif2 = Notification::where('title', 'Delete Test 2')->first();

echo "Testing single delete...\n";
$controller = new \App\Http\Controllers\Api\NotificationController();
// Mocking auth() is complex in a script, but we can call the method if we set the user
auth()->login($user);
$controller->destroy($notif1->id);

if (!Notification::find($notif1->id)) {
    echo "Single notification deleted successfully.\n";
} else {
    echo "Failed to delete single notification.\n";
}

echo "Testing delete all...\n";
$controller->deleteAll();
$count = Notification::where('user_id', $user->id)->count();
if ($count === 0) {
    echo "All notifications deleted successfully.\n";
} else {
    echo "Failed to delete all notifications. Count: $count\n";
}

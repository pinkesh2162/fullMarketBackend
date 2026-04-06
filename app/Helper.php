<?php

if (! function_exists('getUserImageInitial')) {
    function getUserImageInitial($userId, $name)
    {
        return getAvatarUrl() . "?name=" . urlencode($name) . "&size=64&rounded=true&color=fff&background=" . getRandomColor($userId);
    }
}

if (! function_exists('getAvatarUrl')) {
    function getAvatarUrl()
    {
        return 'https://ui-avatars.com/api/';
    }
}

if (! function_exists('getRandomColor')) {
    function getRandomColor($userId)
    {
        return 'ff0000';
        // $colors = ['329af0', 'fc6369', 'ffaa2e', '42c9af', '7d68f0'];
        // $index = $userId % 5;

        // return $colors[$index];
    }
}

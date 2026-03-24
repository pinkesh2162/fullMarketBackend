<?php

if (! function_exists('getUserImageInitial')) {
    function getUserImageInitial($userId, $name)
    {
        return getAvatarUrl()."?name=$name&size=64&rounded=true&color=fff&background=".getRandomColor($userId);
    }
}

if (! function_exists('getAvatarUrl')) {
    function getAvatarUrl()
    {
        return 'https://ui-avatars.com/api/';
    }
}

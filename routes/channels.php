<?php

use Illuminate\Support\Facades\Broadcast;

// User private channel
Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Game public channels (anyone can listen)
Broadcast::channel('game.{gameId}', function ($user, $gameId) {
    return true; // Public channel for game updates
});

// Admin dashboard channel (admin only)
Broadcast::channel('admin.dashboard', function ($user) {
    return $user !== null; // Add proper admin check
});

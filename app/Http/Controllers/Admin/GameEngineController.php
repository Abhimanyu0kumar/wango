<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\GameEngines\GameEngineStateManager;
use App\Jobs\RunGameEngine;
use Illuminate\Http\Request;

class GameEngineController extends Controller
{
    private GameEngineStateManager $stateManager;

    public function __construct(GameEngineStateManager $stateManager)
    {
        $this->stateManager = $stateManager;
    }

    /**
     * Display a listing of all game engines.
     */
    public function index()
    {
        return response()->json([
            'data' => $this->stateManager->getAllEngineStatuses()
        ]);
    }

    /**
     * Display the specified game engine.
     */
    public function show($gameId)
    {
        return response()->json([
            'data' => $this->stateManager->getEngineInfo((int) $gameId)
        ]);
    }

    /**
     * Start an engine.
     */
    public function start($gameId)
    {
        $gameId = (int) $gameId;
        $this->stateManager->start($gameId);
        
        // If not alive, dispatch the background job as a fallback
        if (!$this->stateManager->isAlive($gameId)) {
            RunGameEngine::dispatch($gameId);
        }

        return response()->json([
            'message' => 'Engine start signal sent',
            'data' => $this->stateManager->getEngineInfo($gameId)
        ]);
    }

    /**
     * Stop an engine gracefully.
     */
    public function stop($gameId)
    {
        $gameId = (int) $gameId;
        $this->stateManager->stop($gameId);

        return response()->json([
            'message' => 'Engine stop signal sent',
            'data' => $this->stateManager->getEngineInfo($gameId)
        ]);
    }

    /**
     * Pause an engine.
     */
    public function pause($gameId)
    {
        $gameId = (int) $gameId;
        $this->stateManager->pause($gameId);

        return response()->json([
            'message' => 'Engine pause signal sent',
            'data' => $this->stateManager->getEngineInfo($gameId)
        ]);
    }

    /**
     * Resume a paused engine.
     */
    public function resume($gameId)
    {
        $gameId = (int) $gameId;
        $this->stateManager->resume($gameId);

        return response()->json([
            'message' => 'Engine resume signal sent',
            'data' => $this->stateManager->getEngineInfo($gameId)
        ]);
    }

    /**
     * Force stop an engine.
     */
    public function forceStop($gameId)
    {
        $gameId = (int) $gameId;
        $this->stateManager->forceStop($gameId);

        return response()->json([
            'message' => 'Engine force stopped',
            'data' => $this->stateManager->getEngineInfo($gameId)
        ]);
    }

    /**
     * Restart an engine.
     */
    public function restart($gameId)
    {
        $gameId = (int) $gameId;
        $this->stateManager->forceStop($gameId);
        
        // Small delay to ensure previous process can cleanup if it was alive
        $this->stateManager->start($gameId);
        RunGameEngine::dispatch($gameId);

        return response()->json([
            'message' => 'Engine restart signal sent',
            'data' => $this->stateManager->getEngineInfo($gameId)
        ]);
    }
}

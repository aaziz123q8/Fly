<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Request;
use App\Core\Response;
use App\Middleware\AuthMiddleware;
use App\Services\WalletService;

class WalletController
{
    private WalletService $walletService;

    public function __construct()
    {
        $this->walletService = new WalletService();
    }

    public function getBalance(Request $request): void
    {
        $user = AuthMiddleware::currentUser();
        if ($user === null) Response::unauthorized();

        $wallet = $this->walletService->getWallet((int) $user['id']);
        Response::json([
            'balance'  => number_format((float)$wallet['balance'], 2, '.', ''),
            'currency' => $wallet['currency'] ?? 'GBP',
        ]);
    }

    public function getTransactions(Request $request): void
    {
        $user = AuthMiddleware::currentUser();
        if ($user === null) Response::unauthorized();

        $txs = $this->walletService->getTransactions((int) $user['id'], 100);
        Response::json(['transactions' => $txs]);
    }
}

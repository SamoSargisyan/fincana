<?php

namespace Payer;

use RuntimeException;
use JsonException;

class TradePayer
{
    public const WRONG_OPERATION_TYPE_EXCEPTION = 'Action may be only sell or buy';
    public const AMOUNT_LESS_OR_EQUAL_ZERO = 'Amount cannot be less or equal zero';
    public const PRICE_LESS_OR_EQUAL_ZERO = 'Price cannot be less or equal zero';

    private array $arError;
    private string $key;
    private string $apiId;

    public function __construct(string $apiId, string $key)
    {
        $this->apiId = $apiId;
        $this->key = $key;
    }

    /**
     * @throws RuntimeException|JsonException
     */
    private function request(array $req)
    {
        $milliSec = round(microtime(true) * 1000);
        $req['post']['ts'] = $milliSec;

        $post = json_encode($req['post'], JSON_THROW_ON_ERROR);

        $sign = hash_hmac('sha256', $req['method'] . $post, $this->key);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://payeer.com/api/trade/" . $req['method']);

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, false);

        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post);

        curl_setopt(
            $ch, CURLOPT_HTTPHEADER, [
                "Content-Type: application/json",
                "API-ID: " . $this->apiId,
                "API-SIGN: " . $sign
            ]
        );

        $response = curl_exec($ch);
        curl_close($ch);

        $arResponse = json_decode($response, true, 512, JSON_THROW_ON_ERROR);

        if ($arResponse['success'] !== true) {
            $this->arError = $arResponse['error'];
            throw new RuntimeException($arResponse['error']['code']);
        }

        return $arResponse;
    }

    public function getError(): array
    {
        return $this->arError;
    }

    /**
     * Get limits and available pairs
     *
     * @param string $pair
     * @return mixed
     * @throws JsonException
     */
    public function info(string $pair): mixed
    {
        $request = [
            'method' => 'info',
        ];

        if (!empty($pair)) {
            $request['post'] = [
                'pair' => $pair,
            ];
        }

        return $this->request($request);
    }

    /**
     * Price statistics for latest 24 hours
     *
     * @param string $pair
     * @return mixed
     * @throws JsonException
     */
    public function ticker(string $pair): mixed
    {
        $request = [
            'method' => 'ticker',
        ];
        if (!empty($pair)) {
            $request['post'] = [
                'pair' => $pair,
            ];
        }
        $res = $this->request($request);

        return $res['pairs'];
    }

    /**
     * Get available orders for selected pair(s)
     *
     * @param string $pair
     * @return mixed
     * @throws RuntimeException|JsonException
     */
    public function orders(string $pair): mixed
    {
        $res = $this->request([
            'method' => 'orders',
            'post' => [
                'pair' => $pair,
            ],
        ]);

        return $res['pairs'];
    }

    /**
     * Get all trades for current pair
     *
     * @param string $pair
     * @return mixed
     * @throws RuntimeException|JsonException
     */
    public function trades(string $pair): mixed
    {
        $res = $this->request([
            'method' => 'trades',
            'post' => [
                'pair' => $pair,
            ],
        ]);

        return $res['pairs'];
    }

    /**
     * Get balance of wallets
     *
     * @return mixed
     * @throws RuntimeException|JsonException
     */
    public function account(): mixed
    {
        $res = $this->request([
            'method' => 'account',
        ]);

        return $res['balances'];
    }

    /**
     * Order request
     *
     * @param array $req
     * @return mixed
     * @throws RuntimeException|JsonException
     */
    private function orderCreate(array $req): mixed
    {
        return $this->request([
            'method' => 'order_create',
            'post' => $req,
        ]);
    }


    /**
     * Get order status by id
     *
     * @param int $id
     * @return mixed
     * @throws RuntimeException|JsonException
     */
    public function orderStatus(int $id): mixed
    {
        $res = $this->request([
            'method' => 'order_status',
            'post' => [
                'order_id' => $id
            ],
        ]);

        return $res['order'];
    }

    /**
     * Get current time in milliseconds timestamp
     *
     * @return int
     * @throws RuntimeException|JsonException
     */
    public function time(): int
    {
        $res = $this->request([
            'method' => 'time',
        ]);

        return $res['time'];
    }

    /**
     * Make limit order
     *
     * @param string $pair
     * @param string $action
     * @param float $amount
     * @param float $price
     * @return array
     * @throws RuntimeException|JsonException
     */
    public function limitOrder(string $pair, string $action, float $amount, float $price): array
    {
        if (!in_array($action, ['sell', 'buy'])) {
            throw new RuntimeException(self::WRONG_OPERATION_TYPE_EXCEPTION);
        }
        if ($amount <= 0) {
            throw new RuntimeException(self::AMOUNT_LESS_OR_EQUAL_ZERO);
        }
        if ($price <= 0) {
            throw new RuntimeException(self::PRICE_LESS_OR_EQUAL_ZERO);
        }
        return $this->orderCreate([
            'type' => 'limit',
            'pair' => $pair,
            'action' => $action,
            'amount' => $amount,
            'price' => $price,
        ]);
    }

    /**
     * Make market order
     *
     * @param string $pair
     * @param string $action
     * @param float $amount
     * @param float $value
     * @return array
     * @throws RuntimeException|JsonException
     */
    public function marketOrder(string $pair, string $action, float $amount = 0, float $value = 0): array
    {
        if (!in_array($action, ['sell', 'buy'])) {
            throw new RuntimeException(self::WRONG_OPERATION_TYPE_EXCEPTION);
        }

        if ($amount <= 0 && $value <= 0) {
            throw new RuntimeException('Amount and value cannot be less or equal zero simultaneously');
        }

        if ($amount > 0 && $value > 0) {
            throw new RuntimeException('Please use only one of: Amount or Value. Another must be equal zero');
        }

        $req = [
            'type' => 'market',
            'pair' => $pair,
            'action' => $action,
        ];

        if ($value > 0) {
            $req['value'] = $value;
        }

        if ($amount > 0) {
            $req['amount'] = $amount;
        }

        return $this->orderCreate($req);
    }

    /**
     * Make stop limit order
     *
     * @param string $pair
     * @param string $action
     * @param float $amount
     * @param float $price
     * @param float $stopPrice
     * @return array
     * @throws RuntimeException|JsonException
     */
    public function stopLimitOrder(string $pair, string $action, float $amount, float $price, float $stopPrice): array
    {
        if (!in_array($action, ['sell', 'buy'])) {
            throw new RuntimeException(self::WRONG_OPERATION_TYPE_EXCEPTION);
        }

        if ($amount <= 0) {
            throw new RuntimeException(self::AMOUNT_LESS_OR_EQUAL_ZERO);
        }

        if ($price <= 0) {
            throw new RuntimeException(self::PRICE_LESS_OR_EQUAL_ZERO);
        }

        if ($stopPrice <= 0) {
            throw new RuntimeException('Stop price cannot be less or equal zero');
        }

        return $this->orderCreate([
            'type' => 'stop_limit',
            'pair' => $pair,
            'action' => $action,
            'amount' => $amount,
            'price' => $price,
            'stop_price' => $stopPrice,
        ]);
    }

    /**
     * Cancel order by id
     *
     * @param int $id
     * @return mixed
     * @throws RuntimeException|JsonException
     */
    public function cancelOrder(int $id): mixed
    {
        $res = $this->request([
            'method' => 'order_cancel',
            'post' => [
                'order_id' => $id
            ],
        ]);

        return $res['success'];
    }

    /**
     * Cancel multiple orders
     *
     * @param string|null $pair
     * @param string|null $action
     * @return mixed
     * @throws JsonException
     */
    public function cancelOrders(string $pair = null, string $action = null): mixed
    {
        $req = $this->getRequirementsForOrders($action, $pair);
        $res = $this->request([
            'method' => 'orders_cancel',
            'post' => $req,
        ]);

        return $res['items'];
    }

    /**
     * Get my orders
     *
     * @param string|null $pair
     * @param string|null $action
     * @return mixed
     * @throws JsonException
     */
    public function myOrders(string $pair = null, string $action = null): mixed
    {
        $req = $this->getRequirementsForOrders($action, $pair);
        $res = $this->request([
            'method' => 'my_orders',
            'post' => $req,
        ]);

        return $res['items'];
    }

    /**
     * Get my trades
     *
     * @param string|null $pair
     * @param string|null $action
     * @param int|null $dateFrom Unix Timestamp of begin date
     * @param int|null $dateTo Unix Timestamp of end date
     * @param int|null $lastId
     * @param int $limit
     * @return mixed
     * @throws JsonException
     */
    public function myTrades(string $pair = null, string $action = null, int $dateFrom = null, int $dateTo = null, int $lastId = null, int $limit = 50): mixed
    {
        if (!empty($action) && !in_array($action, ['sell', 'buy'])) {
            throw new RuntimeException(self::WRONG_OPERATION_TYPE_EXCEPTION);
        }

        $req = $this->getRequirementsForTradesAndHistory($limit, $pair, $action, $dateFrom, $dateTo, $lastId);
        $res = $this->request([
            'method' => 'my_trades',
            'post' => $req,
        ]);

        return $res['items'];
    }

    /**
     * Get my trades
     *
     * @param string|null $pair
     * @param string|null $action
     * @param string|null $status
     * @param int|null $dateFrom Unix Timestamp of begin date
     * @param int|null $dateTo Unix Timestamp of end date
     * @param int|null $lastId
     * @param int $limit
     * @return mixed
     * @throws JsonException
     */
    public function myHistory(string $pair = null, string $action = null, string $status = null, int $dateFrom = null, int $dateTo = null, int $lastId = null, int $limit = 50): mixed
    {
        if (!empty($action) && !in_array($action, ['sell', 'buy'])) {
            throw new RuntimeException(self::WRONG_OPERATION_TYPE_EXCEPTION);
        }

        if (!empty($status) && !in_array($action, ['success', 'processing', 'waiting', 'canceled'])) {
            throw new RuntimeException('Status may be only one of: success, processing, waiting, canceled');
        }

        $req = $this->getRequirementsForTradesAndHistory($limit, $pair, $action, $dateFrom, $dateTo, $lastId);
        $res = $this->request([
            'method' => 'my_history',
            'post' => $req,
        ]);

        return $res['items'];
    }

    /**
     * @param string|null $action
     * @param string|null $pair
     * @return array
     */
    public function getRequirementsForOrders(?string $action, ?string $pair): array
    {
        if (!empty($action) && !in_array($action, ['sell', 'buy'])) {
            throw new RuntimeException(self::WRONG_OPERATION_TYPE_EXCEPTION);
        }

        $req = [];
        if (!empty($pair)) {
            $req['pair'] = $pair;
        }

        if (!empty($action)) {
            $req['action'] = $action;
        }

        return $req;
    }

    /**
     * @param int $limit
     * @param string|null $pair
     * @param string|null $action
     * @param int|null $dateFrom
     * @param int|null $dateTo
     * @param int|null $lastId
     * @return array
     */
    public function getRequirementsForTradesAndHistory(int $limit, ?string $pair, ?string $action, ?int $dateFrom, ?int $dateTo, ?int $lastId): array
    {
        if ($limit <= 0) {
            throw new RuntimeException('Limit cannot be less or equal zero');
        }

        $req = [];

        if (!empty($pair)) {
            $req['pair'] = $pair;
        }

        if (!empty($action)) {
            $req['action'] = $action;
        }

        if ($dateFrom !== null) {
            $req['date_from'] = $dateFrom;
        }

        if ($dateTo !== null) {
            $req['date_to'] = $dateTo;
        }

        if ($lastId !== null) {
            $req['append'] = $lastId;
        }

        if (!empty($limit)) {
            $req['limit'] = $limit;
        }

        return $req;
    }

}
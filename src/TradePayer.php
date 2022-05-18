<?php

class TradePayer
{
    private array $arParams;
    private array $arError;


    public function __construct($params)
    {
        $this->arParams = $params;
    }

    /**
     * @throws JsonException
     */
    private function request(array $req)
    {
        $milliSec = round(microtime(true) * 1000);
        $req['post']['ts'] = $milliSec;

        $post = json_encode($req['post'], JSON_THROW_ON_ERROR);

        $sign = hash_hmac('sha256', $req['method'] . $post, $this->arParams['key']);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://payeer.com/api/trade/" . $req['method']);

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, false);
        //curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post);

        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            "Content-Type: application/json",
            "API-ID: " . $this->arParams['id'],
            "API-SIGN: " . $sign
        ));

        $response = curl_exec($ch);
        curl_close($ch);

        $arResponse = json_decode($response, true, 512, JSON_THROW_ON_ERROR);

        if ($arResponse['success'] !== true) {
            $this->arError = $arResponse['error'];
            throw new \RuntimeException($arResponse['error']['code']);
        }

        return $arResponse;
    }


    public function getError(): array
    {
        return $this->arError;
    }


    /**
     * @throws JsonException
     */
    public function info()
    {
        return $this->request([
            'method' => 'info',
        ]);
    }


    /**
     * @throws JsonException
     */
    public function orders($pair = 'BTC_USDT')
    {
        $res = $this->request([
            'method' => 'orders',
            'post' => array(
                'pair' => $pair,
            ),
        ]);

        return $res['pairs'];
    }


    /**
     * @throws JsonException
     */
    public function account()
    {
        $res = $this->request([
            'method' => 'account',
        ]);

        return $res['balances'];
    }


    /**
     * @throws JsonException
     */
    public function orderCreate(array $req)
    {
        return $this->request([
            'method' => 'order_create',
            'post' => $req,
        ]);
    }


    /**
     * @throws JsonException
     */
    public function orderStatus(array $req)
    {
        $res = $this->request([
            'method' => 'order_status',
            'post' => $req,
        ]);

        return $res['order'];
    }


    /**
     * @throws JsonException
     */
    public function myOrders(array $req)
    {
        $res = $this->request([
            'method' => 'my_orders',
            'post' => $req,
        ]);

        return $res['items'];
    }
}
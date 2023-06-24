<?php

namespace ChargeWorker;

use Exception;
use Workerman\Mqtt\Client;
use Workerman\Worker;

class chargeWorker extends Worker
{
    /**
     * 事件处理类，默认是 Event 类
     * @var string
     */
    public $eventHandler = 'Events';

    /**
     * 设备查询类
     * @var null
     */
    public $deviceHandler = null;


    /**
     * 注释:运行worker
     * 创建者:JSL
     * 时间:2023/06/24 024 下午 04:03
     * @throws Exception
     */
    public function run()
    {
        $this->onWorkerStart = array($this, 'onWorkerStart');
        parent::run();
    }

    protected function onWorkerStart()
    {
        if (function_exists('opcache_reset')) {
            opcache_reset();
        }
        try {
            $address         = 'mqtt://' . config('charge.ip') . ':' . config('charge.port');
            $mqtt            = new Client($address, [
                'username'  => config('charge.username'),
                'password'  => config('charge.password'),
                'client_id' => config('charge.rx_client_id'),
                'debug'     => config('charge.debug'),
            ]);
            $mqtt->onConnect = function ($mqtt) {
                $mqtt->subscribe('/v1/device/+/rx');
            };
            $mqtt->onMessage = function ($topic, $content, $mqtt) {
                //截取imei
                preg_match('/(device\/)(.*)(?)(\/rx)/', $topic, $result);
                $imei = $result[2];
                //解析报文
                $message = bin2hex($content);
                //转换16进制数组
                $hex = str_split($message, 2);
                $dec = [];
                //生成十进制数组
                foreach ($hex as $value) {
                    $dec[] = hexdec($value);
                }
                $cmd           = $dec[2];
                $data          = [
                    'imei' => $imei,
                    'hex'  => $hex,
                    'dec'  => $dec,
                ];
                $function_name = "onMessage";
                $device        = null;
                if ($this->deviceHandler instanceof \Closure) {
                    $device = call_user_func($this->deviceHandler, $imei);
                }
                switch ($cmd) {
                    case 0xA2:
                        $data['param'] = [
                            'signal' => $dec[4],
                            'log'    => "当前信号值：{$dec[4]}",
                            'device' => $device
                        ];
                        $function_name = "onSignal";
                        break;
                    case 0x01:

                        break;
                }

                if (is_callable($this->eventHandler . '::' . $function_name)) {
                    call_user_func($this->eventHandler . '::' . $function_name, $data);
                }
            };
            $mqtt->connect();

        } catch (Exception $e) {
        }


    }

}
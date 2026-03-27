<?php

namespace ChargeWorker;

use Exception;
use Workerman\Mqtt\Client;
use Workerman\Worker;
use Workerman\Timer;

class chargeWorker extends Worker
{
    /**
     * 事件处理类
     * @var string
     */
    public $eventHandler = 'Events';

    /**
     * 设备查询类
     * @var null
     */
    public $deviceHandler = null;

    /**
     * MQTT客户端实例
     * @var Client|null
     */
    protected $mqtt = null;

    /**
     * 重连定时器ID
     * @var int
     */
    protected $reconnectTimer = 0;

    /**
     * 重连间隔（秒）
     * @var int
     */
    protected $reconnectInterval = 5;

    /**
     * 运行worker
     * @throws Exception
     */
    public function run()
    {
        $this->onWorkerStart = array($this, 'onWorkerStart');
        parent::run();
    }

    /**
     * Worker启动时连接MQTT
     */
    protected function onWorkerStart()
    {
        if (function_exists('opcache_reset')) {
            opcache_reset();
        }
        $this->connectMqtt();
    }

    /**
     * 建立MQTT连接
     */
    protected function connectMqtt()
    {
        try {
            $address = 'mqtt://' . config('charge.ip') . ':' . config('charge.port');
            $this->mqtt = new Client($address, [
                'username'  => config('charge.username'),
                'password'  => config('charge.password', ''),
                'client_id' => config('charge.rx_client_id'),
                'debug'     => config('charge.debug', false),
            ]);

            $this->mqtt->onConnect = function ($mqtt) {
                // 连接成功，清除重连定时器
                if ($this->reconnectTimer) {
                    Timer::del($this->reconnectTimer);
                    $this->reconnectTimer = 0;
                }
                $mqtt->subscribe('/v1/device/+/rx');
            };

            $this->mqtt->onMessage = function ($topic, $content, $mqtt) {
                $this->handleMessage($topic, $content);
            };

            $this->mqtt->onError = function ($connection, $code, $message) {
                logger()->error("MQTT错误 [{$code}]: {$message}");
            };

            $this->mqtt->onClose = function () {
                $this->reconnect();
            };

            $this->mqtt->connect();

        } catch (Exception $e) {
            logger()->error('MQTT连接异常: ' . $e->getMessage());
            $this->reconnect();
        }
    }

    /**
     * 断线重连
     */
    protected function reconnect()
    {
        if ($this->reconnectTimer) {
            return;
        }
        logger()->warning("MQTT断开连接，{$this->reconnectInterval}秒后重连...");
        $this->reconnectTimer = Timer::add($this->reconnectInterval, function () {
            $this->reconnectTimer = 0;
            $this->connectMqtt();
        }, [], false);
    }

    /**
     * 解析MQTT消息
     * @param string $topic 消息主题
     * @param string $content 消息内容
     */
    protected function handleMessage($topic, $content)
    {
        // 从topic提取IMEI: /v1/device/{imei}/rx
        $imei = $this->extractImei($topic);
        if ($imei === null) {
            logger()->warning("无法从topic提取IMEI: {$topic}");
            return;
        }

        // 解析报文
        $hex = str_split(bin2hex($content), 2);
        $dec = array_map('hexdec', $hex);
        $cmd = $dec[2] ?? null;

        $data = [
            'imei' => $imei,
            'hex'  => $hex,
            'dec'  => $dec,
        ];

        $functionName = 'onMessage';
        $device = null;

        if ($this->deviceHandler instanceof \Closure) {
            $device = call_user_func($this->deviceHandler, $imei);
        }

        // 根据命令码分发处理
        switch ($cmd) {
            case 0xA2: // 信号上报
                $data['param'] = [
                    'signal' => $dec[4] ?? null,
                    'log'    => "当前信号值：" . ($dec[4] ?? '未知'),
                    'device' => $device,
                ];
                $functionName = 'onSignal';
                break;

            case 0x01: // 心跳包
                $data['param'] = [
                    'log'    => "收到心跳包",
                    'device' => $device,
                ];
                $functionName = 'onHeartbeat';
                break;

            default:
                logger()->info("收到未处理的命令: 0x" . strtoupper(dechex($cmd ?? 0)));
                break;
        }

        // 调用事件处理
        if (is_callable($this->eventHandler . '::' . $functionName)) {
            call_user_func($this->eventHandler . '::' . $functionName, $data);
        }
    }

    /**
     * 从topic中提取IMEI
     * @param string $topic
     * @return string|null
     */
    protected function extractImei($topic)
    {
        if (preg_match('/device\/(.+?)\/rx/', $topic, $matches)) {
            return $matches[1];
        }
        return null;
    }
}
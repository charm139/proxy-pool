<?php

namespace App\Spiders;

use App\Models\Proxy;
use App\Models\StableProxy;
use App\Utils\CommonUtil;
use Carbon\Carbon;
use GuzzleHttp\Client;
use \Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class Tester
{
    static private $instance;

    private $time_out;

    private function __construct()
    {
    }

    private function __clone()
    {
    }

    /**
     * Get Instance
     * @return Tester
     */
    static public function getInstance()
    {
        if (!self::$instance instanceof self) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Handle
     * @param Proxy|StableProxy $proxy
     * @throws \Exception
     */
    public function handle($proxy)
    {
        self::check($proxy);
    }

    /**
     * Check Proxy
     * @param Proxy|StableProxy $proxy
     * @return bool
     * @throws \Exception
     */
    public static function check($proxy)
    {
        try {
            $client = new Client();
            $check_url = config('proxy.check_url');
            $check_keyword = config('proxy.check_keyword');
            $begin_seconds = CommonUtil::mSecondTime();
            $proxy_url = $proxy->protocol . '://' . $proxy->ip . ':' . $proxy->port;
            $response = $client->request('GET', $check_url, [
                'proxy' => $proxy_url,
                'verify' => false,
                'connect_timeout' => config('proxy.connect_timeout'),
                'timeout' => config('proxy.timeout')
            ]);
            if (strpos($response->getBody()->getContents(), $check_keyword) !== false) {
                $end_seconds = CommonUtil::mSecondTime();
                $speed = intval($end_seconds - $begin_seconds);
                //代理更新
                $proxy->update([
                    'speed' => $speed,
                    'checked_times' => ++$proxy->checked_times,
                    'last_checked_at' => Carbon::now(),
                ]);
                if ($proxy instanceof StableProxy) {
                    if (Redis::llen('proxy') > 1000) {
                        Redis::ltrim('proxy', 1, 100);
                    }
                    Redis::lpush('proxy', json_encode($proxy));
                }
                Log::info("代理检测成功[{$proxy_url}]：$speed ms[{$response->getStatusCode()}]");
                return true;
            } else {
                throw new \Exception('检测结果不包含关键字');
            }
        } catch (\Exception $exception) {
            //代理删除
            $proxy->delete();
            Log::error("代理测试失败[{$proxy_url}]：" . $exception->getMessage());
            return false;
        }
    }
}
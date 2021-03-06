<?php
/**
 * Created by PhpStorm.
 * User: liangchen
 * Date: 2018/2/11
 * Time: 下午3:50.
 */

namespace Tars\core;

use Tars\Consts;
use Tars\config\ConfigServant;
use Tars\monitor\PropertyFWrapper;
use Tars\monitor\StatFWrapper;
use Tars\protocol\ProtocolFactory;
use Tars\report\ServerFSync;
use Tars\report\ServerInfo;
use Tars\Utils;

class Server
{
    protected $tarsConfig;
    protected $sw;
    protected $masterPidFile;
    protected $managerPidFile;

    protected $application;
    protected $serverName = '';
    protected $protocolName = 'tars';

    protected $host = '0.0.0.0';
    protected $port = '8088';
    protected $worker_num = 4;
    protected $servType = 'tcp';

    protected $setting;

    protected $dataPath;
    protected $basePath;
    protected $entrance;
    protected $servicesInfo;
    protected static $paramInfos;
    protected $namespaceName;

    protected static $impl;
    protected $protocol;
    protected $timers;

    public function __construct($conf, $table = null)
    {
        $tarsServerConf = $conf['tars']['application']['server'];
        $tarsClientConf = $conf['tars']['application']['client'];

        $this->dataPath = $tarsServerConf['datapath'];
        $this->basePath = $tarsServerConf['basepath'];
        $this->entrance = $tarsServerConf['entrance'];

        $this->servicesInfo = $tarsServerConf['servicesInfo'];

        $this->tarsConfig = $conf;
        $this->application = $tarsServerConf['app'];
        $this->serverName = $tarsServerConf['server'];

        $this->host = $tarsServerConf['listen'][0]['bIp'];
        $this->port = $tarsServerConf['listen'][0]['iPort'];

        $this->setting = $tarsServerConf['setting'];

        $this->protocolName = $tarsServerConf['protocolName'];
        $this->servType = $tarsServerConf['servType'];
        $this->table = $table;
        $this->worker_num = $this->setting['worker_num'];
    }

    public function start()
    {
        $swooleServerName = ($this->servType == 'http') ? '\swoole_http_server' : '\swoole_server';

        $sw = new $swooleServerName($this->host, $this->port, SWOOLE_PROCESS, SWOOLE_SOCK_TCP);
        $this->sw = $sw;
        $this->sw->servType = $this->servType;

        if ($this->servType == 'http') {
            $this->sw->on('Request', array($this, 'onRequest'));

            // 判断是否是timer服务
            if (isset($this->tarsConfig['tars']['application']['server']['isTimer']) &&
                $this->tarsConfig['tars']['application']['server']['isTimer'] == true) {
                $timerDir = $this->basePath . 'src/timer/';
                if (is_dir($timerDir)) {
                    $files = scandir($timerDir);
                    foreach ($files as $f) {
                        $fileName = $timerDir . $f;
                        if (is_file($fileName) && strrchr($fileName, '.php') == '.php') {
                            $this->timers[] = $fileName;
                        }
                    }
                } else {
                    error_log('timer dir missing');
                }
            }
        }

        $this->sw->set($this->setting);

        $this->sw->on('Start', array($this, 'onMasterStart'));
        $this->sw->on('ManagerStart', array($this, 'onManagerStart'));
        $this->sw->on('WorkerStart', array($this, 'onWorkerStart'));
        $this->sw->on('Connect', array($this, 'onConnect'));
        $this->sw->on('Receive', array($this, 'onReceive'));
        $this->sw->on('Close', array($this, 'onClose'));
        $this->sw->on('WorkerStop', array($this, 'onWorkerStop'));

        $this->sw->on('Task', array($this, 'onTask'));
        $this->sw->on('Finish', array($this, 'onFinish'));
        if (!is_null($this->table)) {
            $this->sw->table = $this->table;
        }

        $this->masterPidFile = $this->dataPath . '/master.pid';
        $this->managerPidFile = $this->dataPath . '/manager.pid';

        $this->protocol = ProtocolFactory::getProtocol($this->protocolName);

        require_once $this->entrance;

        $this->sw->start();
    }

    public function stop()
    {
    }

    public function restart()
    {
    }

    public function reload()
    {
    }

    private function _setProcessName($name)
    {
        if (function_exists('cli_set_process_title')) {
            cli_set_process_title($name);
        } elseif (function_exists('swoole_set_process_name')) {
            swoole_set_process_name($name);
        } else {
            trigger_error(__METHOD__ . ' failed. require cli_set_process_title or swoole_set_process_name.');
        }
    }

    public function onMasterStart($server)
    {
        $this->_setProcessName($this->application . '.' . $this->serverName . ': master process');
        file_put_contents($this->masterPidFile, $server->master_pid);
        file_put_contents($this->managerPidFile, $server->manager_pid);

        // 初始化的一次上报
        $this->keepaliveinit();

        //拉取配置
        $this->loadTarsConfig();
    }

    public function onManagerStart($server)
    {
        // rename manager process
        $this->_setProcessName($this->application . '.' . $this->serverName . ': manager process');
    }

    public function onWorkerStart($server, $workerId)
    {
        // 找第一个worker 启动一个timer,进行定时上报
        //         这里只不过是一个启动的初始动作,需要注册好encoder和decoder
        //         如果nothing special, 这里就不需要特殊处理了
        //         其实不用,因为impl的construct就是一个天然的执行的地方
        // 下面的上报逻辑,接入平台的时候进行调试

        if ($this->servType === 'tcp') {
            $className = $this->servicesInfo['home-class'];
            self::$impl = new $className();
            $interface = new \ReflectionClass($this->servicesInfo['home-api']);
            $methods = $interface->getMethods();
            foreach ($methods as $method) {
                $docblock = $method->getDocComment();
                // 对于注释也应该有自己的定义和解析的方式
                self::$paramInfos[$method->name]
                    = $this->protocol->parseAnnotation($docblock);
            }
        } else {
            $this->namespaceName = $this->servicesInfo['namespaceName'];
        }

        if ($workerId >= $this->worker_num) {
            $this->_setProcessName($this->application . '.' . $this->serverName . ': task worker process');
        } else {
            $this->_setProcessName($this->application . '.' . $this->serverName . ': event worker process');
            if (isset($this->timers[$workerId])) {
                $runnable = $this->timers[$workerId];
                require_once $runnable;
                $className = $this->namespaceName . 'timer\\' . basename($runnable, '.php');

                $obj = new $className();
                if (method_exists($obj, 'execute')) {
                    swoole_timer_tick($obj->interval, function () use ($workerId, $runnable, $obj) {
                        try {
                            $funcName = 'execute';
                            $obj->$funcName();
                        } catch (\Exception $e) {
                            error_log("error in runnable: $runnable, worker id: $workerId, e: " . print_r($e, true));
                        }
                    });
                }
            }
        }

        if ($workerId == 0) {
            // 将定时上报的任务投递到task worker 0,只需要投递一次
            $result = Utils::parseNodeInfo($this->tarsConfig['tars']['application']['server']['node']);
            $this->sw->task(
                [
                    'application' => $this->application,
                    'serverName' => $this->serverName,
                    'masterPid' => $server->master_pid,
                    'adapter' => $this->tarsConfig['tars']['application']['server']['adapters'][0]['adapterName'],
                    'mode' => $result['mode'],
                    'host' => $result['host'],
                    'port' => $result['port'],
                    'timeout' => $result['timeout'],
                    'objName' => $result['objName'],
                    'client' => $this->tarsConfig['tars']['application']['client']
                ], 0);
        }
    }

    public function onConnect($server, $fd, $fromId)
    {
    }

    public function onTask($server, $taskId, $fromId, $data)
    {
        switch ($taskId) {
            // 进行定时上报
            case 0:
                {
                    $application = $data['application'];
                    $serverName = $data['serverName'];
                    $masterPid = $data['masterPid'];
                    $adapter = $data['adapter'];
                    $mode = $data['mode'];
                    $host = $data['host'];
                    $port = $data['port'];
                    $timeout = $data['timeout'];
                    $objName = $data['objName'];

                    \swoole_timer_tick(10000, function () use (
                        $application,
                        $serverName,
                        $masterPid,
                        $adapter,
                        $mode,
                        $host,
                        $port,
                        $timeout,
                        $objName
                    ) {
                        //获取当前存活的worker数目
                        $processName = $application.'.'.$serverName;
                        $cmd = "ps aux | grep '" . $processName . "' | grep 'event worker process' | grep -v grep  | awk '{ print $2}'";
                        exec($cmd, $ret);
                        $workerNum = count($ret);
                        if( $workerNum<1 ){
                            //worker全挂，不上报存活 等tars重启
                        }else{
                            // 进行一次上报
                            $serverInfo = new ServerInfo();
                            $serverInfo->adapter = $adapter;
                            $serverInfo->application = $application;
                            $serverInfo->serverName = $serverName;
                            $serverInfo->pid = $masterPid;

                            $serverF = new ServerFSync($host, $port, $objName);
                            $serverF->keepAlive($serverInfo);

                            $adminServerInfo = new ServerInfo();
                            $adminServerInfo->adapter = 'AdminAdapter';
                            $adminServerInfo->application = $application;
                            $adminServerInfo->serverName = $serverName;
                            $adminServerInfo->pid = $masterPid;
                            $serverF->keepAlive($adminServerInfo);
                        }
                    });

                    //主调定时上报
                    $locator = $data['client']['locator'];
                    $socketMode = 2;
                    $statServantName = $data['client']['stat'];
                    $reportInterval = $data['client']['report-interval'];
                    \swoole_timer_tick($reportInterval,
                        function () use ($locator, $socketMode, $statServantName, $serverName, $reportInterval) {
                            try {
                                $statFWrapper = new StatFWrapper($locator, $socketMode, $statServantName, $serverName,
                                    $reportInterval);
                                $statFWrapper->sendStat();
                            } catch (\Exception $e) {
                                var_dump((string)$e);
                            }
                        });

                    \swoole_timer_tick($reportInterval, function () use ($locator, $application, $serverName) {
                        try {
                            $this->basePropertyMonitor($locator, $application, $serverName);
                        } catch (\Exception $exception) {
                            var_dump((string)$exception);
                        }
                    });

                    break;
                }
            default:
                {
                    break;
                }
        }
    }

    public function onFinish($server, $taskId, $data)
    {
    }

    public function onClose($server, $fd, $fromId)
    {
    }

    public function onWorkerStop($server, $workerId)
    {
    }

    public function onTimer($server, $interval)
    {
    }

    // 这里应该找到对应的解码协议类型,执行解码,并在收到逻辑处理回复后,进行编码和发送数据 todo
    public function onReceive($server, $fd, $fromId, $data)
    {
        $request = new Request();
        $request->reqBuf = $data;
        $request->paramInfos = self::$paramInfos;
        $request->impl = self::$impl;

        // 把全局对象带入到请求中,在多个worker之间共享
        $request->server = $this->sw;
        $request->setGlobal();

        $response = new Response();
        $response->fd = $fd;
        $response->fromFd = $fromId;
        $response->server = $server;


        // 处理管理端口的特殊逻辑
        $unpackResult = \TUPAPI::decodeReqPacket($data);
        $sServantName = $unpackResult['sServantName'];
        $sFuncName = $unpackResult['sFuncName'];

        // 处理管理端口相关的逻辑
        if ($sServantName === 'AdminObj') {
            $this->processAdmin($unpackResult, $sFuncName, $response);
        }


        $event = new Event();
        $event->setProtocol(ProtocolFactory::getProtocol($this->protocolName));
        $event->setBasePath($this->basePath);
        $event->setTarsConfig($this->tarsConfig);
        // 预先对impl和paramInfos进行处理,这样可以速度更快
        $event->onReceive($request, $response);

        $request->unsetGlobal();
    }

    public function onRequest($request, $response)
    {
        $req = new Request();
        $req->data = get_object_vars($request);
        if (isset($req->data['zcookie'])) {
            $req->data['cookie'] = $req->data['zcookie'];
            unset($req->data['zcookie']);
        }
        if (empty($req->data['post'])) {
            $req->data['post'] = $request->rawContent();
        }
        $req->servType = $this->servType;
        $req->namespaceName = $this->namespaceName;

        $req->server = $this->sw;
        $req->setGlobal();

        $resp = new Response();
        $resp->servType = $this->servType;
        $resp->resource = $response;


        $event = new Event();
        $event->setProtocol(ProtocolFactory::getProtocol($this->protocolName));
        $event->onRequest($req, $resp);

        $req->unsetGlobal();
    }

    //拉取配置到指定目录
    public function loadTarsConfig()
    {
        try {
            $servicesInfo = $this->servicesInfo;
            if (!empty($servicesInfo) && isset($servicesInfo['saveTarsConfigFileDir']) && isset($servicesInfo['saveTarsConfigFileName'])) {
                $fileNameArr = array_filter($servicesInfo['saveTarsConfigFileName']);
                $app = $this->tarsConfig['tars']['application']['server']['app'];
                $server = $this->tarsConfig['tars']['application']['server']['server'];

                if (!empty($fileNameArr) && $app != '' && $server != '') {
                    $config = new \Tars\client\CommunicatorConfig();
                    $locator = $this->tarsConfig['tars']['application']['client']['locator'];
                    $moduleName = $this->tarsConfig['tars']['application']['client']['modulename'];
                    $config->setLocator($locator);
                    $config->setModuleName($moduleName);
                    $config->setSocketMode(2);

                    $conigServant = new \Tars\config\ConfigServant($config);

                    foreach ($fileNameArr as $filename) {
                        $savefile = $filename;
                        if (substr($filename, 0, 1) != DIRECTORY_SEPARATOR) {
                            //相对路径转绝对路径
                            $basePath = $this->tarsConfig['tars']['application']['server']['basepath'];
                            $savefile = $basePath . $servicesInfo['saveTarsConfigFileDir'] . $filename;
                        }

                        $configStr = '';
                        $conigServant->loadConfig($app, $server, $filename, $configStr);
                        if ($configStr != '') { //保存文件
                            $file = fopen($savefile, "w");
                            fwrite($file, $configStr);
                            fclose($file);
                            var_dump("loadTarsConfig success : " . $savefile);
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            var_dump((string)$e);
            return false;
        }
    }

    // 这个东西需要单列出去
    public function keepaliveinit()
    {
        // 加载tars需要的文件 - 最好是通过autoload来加载
        // 初始化的上报
        $serverInfo = new ServerInfo();
        $serverInfo->adapter = $this->tarsConfig['tars']['application']['server']['adapters'][0]['adapterName'];
        $serverInfo->application = $this->tarsConfig['tars']['application']['server']['app'];
        $serverInfo->serverName = $this->tarsConfig['tars']['application']['server']['server'];
        $serverInfo->pid = $this->sw->master_pid;

        // 解析出node上报的配置 tars.tarsnode.ServerObj@tcp -h 127.0.0.1 -p 2345 -t 10000
        $result = Utils::parseNodeInfo($this->tarsConfig['tars']['application']['server']['node']);
        $objName = $result['objName'];
        $host = $result['host'];
        $port = $result['port'];

        $serverF = new ServerFSync($host, $port, $objName);
        $serverF->keepAlive($serverInfo);
        $serverInfo->adapter = 'AdminAdapter';
        $serverF->keepAlive($serverInfo);
    }

    //基础特性上报，上报：内存/cpu使用情况，worker进程数，连接数
    public function basePropertyMonitor($locator, $application, $serverName)
    {
        $localIP = swoole_get_local_ip();
        $ip = isset($localIP['eth0'])
            ? $localIP['eth0']
            : (isset(array_values($localIP)[0]) ? array_values($localIP)[0] : '127.0.0.1');
        $msgHeadArr = [];
        $msgBodyArr = [];

        //系统内存使用情况
        exec("free -m", $sysMemInfo);
        preg_match_all("/\d+/s", $sysMemInfo[2], $matches);
        if (isset($matches[0][0])) {
            $msgHead = [
                'ip' => $ip,
                'propertyName' => 'system.memoryUsage'
            ];
            $msgBody = [
                'policy' => 'Avg',
                'value' => $matches[0][0]
            ];
            $msgHeadArr[] = $msgHead;
            $msgBodyArr[] = $msgBody;
        }
        //服务内存使用情况
        exec("ps -e -o 'rsz,cmd' | grep {$serverName} | grep -v grep | awk '{count += $1}; END {print count}'",
            $serverMemInfo);
        if (isset($serverMemInfo)) {
            $msgHead = [
                'ip' => $ip,
                'propertyName' => $serverName . '.memoryUsage'
            ];
            $msgBody = [
                'policy' => 'Avg',
                'value' => $serverMemInfo[0]
            ];
            $msgHeadArr[] = $msgHead;
            $msgBodyArr[] = $msgBody;
        }
        //系统cpu使用情况
        exec("command -v mpstat > /dev/null && mpstat -P ALL | awk '{if($12!=\"\") print $12}' | tail -n +3",
            $cpusInfo);
        if (isset($cpusInfo)) {
            foreach ($cpusInfo as $key => $cpuInfo) {
                $cpuUsage = 100 - $cpuInfo;
                $msgHead = [
                    'ip' => $ip,
                    'propertyName' => "system.cpu{$key}Usage"
                ];
                $msgBody = [
                    'policy' => 'Avg',
                    'value' => $cpuUsage
                ];
                $msgHeadArr[] = $msgHead;
                $msgBodyArr[] = $msgBody;
            }
        }
        //swoole特性
        exec("ps aux | grep {$serverName} | grep 'event worker process' | grep -v grep | wc -l",
            $swooleWorkerNum);
        if (isset($swooleWorkerNum)) {
            $msgHead = [
                'ip' => $ip,
                'propertyName' => $serverName . '.swooleWorkerNum'
            ];
            $msgBody = [
                'policy' => 'Avg',
                'value' => $swooleWorkerNum[0]
            ];
            $msgHeadArr[] = $msgHead;
            $msgBodyArr[] = $msgBody;
        }
        //连接数
        exec("ps -ef | grep {$serverName} | grep -v grep | awk '{print $2}'", $serverPidInfo);
        $tmpId = [];
        foreach ($serverPidInfo as $pid) {
            $tmpId[] = $pid . "/";
        }
        $grepPidInfo = implode("|", $tmpId);
        $command = "command -v netstat > /dev/null && netstat -anlp | grep tcp | grep -E '{$grepPidInfo}' | awk '{print $6}' | awk -F: '{print $1}'|sort|uniq -c|sort -nr";
        exec($command, $netStatInfo);
        foreach ($netStatInfo as $statInfo) {
            $statArr = explode(' ', trim($statInfo));
            $msgHead = [
                'ip' => $ip,
                'propertyName' => $serverName . '.netStat.' . $statArr[1]
            ];
            $msgBody = [
                'policy' => 'Avg',
                'value' => $statArr[0]
            ];
            $msgHeadArr[] = $msgHead;
            $msgBodyArr[] = $msgBody;
        }
        $propertyFWrapper = new PropertyFWrapper($locator, 2, $application . '.' . $serverName);
        $propertyFWrapper->monitorPropertyBatch($msgHeadArr, $msgBodyArr);
    }

    private function processAdmin($unpackResult, $sFuncName, $response)
    {
        $app = $this->application;
        $server = $this->serverName;

        $sBuffer = $unpackResult['sBuffer'];
        $iVersion = $unpackResult['iVersion'];
        $iRequestId = $unpackResult['iRequestId'];
        switch ($sFuncName) {
            case 'shutdown':
                {
                    $cmd = "kill -15 " . $server->master_pid;
                    exec($cmd, $output, $r);
                    break;
                }
            case 'notify':
                {
                    $iVersion = $unpackResult['iVersion'];

                    if ($iVersion === Consts::TUPVERSION) {
                        $cmd = \TUPAPI::getString('cmd', $sBuffer, false, $iVersion);

                    } elseif ($iVersion === Consts::TARSVERSION) {
                        $cmd = \TUPAPI::getString(1, $sBuffer, false, $iVersion);
                    }

                    $returnStr = '';
                    // 查看服务状态
                    if ($cmd == "tars.viewstatus") {
                        $returnStr = "[1]:==================================================\n[proxy config]:\n";
                        foreach ($this->tarsConfig['tars']['application']['client'] as $key => $value) {
                            $returnStr .= "$key      " . $value;
                            $returnStr .= "\n";
                        }
                        $returnStr .= "--------------------------------------------------\n[server config]:\n";
                        foreach ($this->tarsConfig['tars']['application']['server'] as $key => $value) {
                            if ($key == "adapters") {
                                continue;
                            }
                            $returnStr .= "$key      " . $value;
                            $returnStr .= "\n";
                        }

                        foreach ($this->tarsConfig['tars']['application']['server']['adapters'] as $adapter) {
                            $returnStr .= "--------------------------------------------------\n";
                            foreach ($adapter as $key => $value) {
                                $returnStr .= "$key      " . $value;
                                $returnStr .= "\n";
                            }
                        }
                        $returnStr .= "--------------------------------------------------\n";
                    } // 加载服务配置
                    else {
                        if (strstr($cmd, "tars.loadconfig")) {
                            // 这个事,最好是起一个task worker去干比较好
                            $parts = explode(' ', $cmd);
                            $fileName = $parts[1];

                            $config = new \Tars\client\CommunicatorConfig();
                            $locator = $this->tarsConfig['tars']['application']['client']['locator'];
                            $moduleName = $this->tarsConfig['tars']['application']['client']['modulename'];
                            $config->setLocator($locator);
                            $config->setModuleName($moduleName);
                            $config->setSocketMode(2);
                            $configF = new ConfigServant($config);
                            $configContent = '';
                            $configF->loadConfig($app, $server, $fileName, $configContent);

                            $basePath = $this->tarsConfig['tars']['application']['server']['basepath'];
                            file_put_contents($basePath . '/src/conf/' . $fileName, $configContent);

                            $returnStr = '[notify file num:1][located in {ServicePath}/bin/conf]';
                        }
                    }

                    $str = \TUPAPI::putString(0, $returnStr, 1);
                    $cPacketType = 0;
                    $iMessageType = 0;

                    $rspBuf = \TUPAPI::encodeRspPacket($iVersion, $cPacketType,
                        $iMessageType, $iRequestId, 0, '', [$str], []);
                    $response->send($rspBuf);

                    return null;
                }
        }
    }
}

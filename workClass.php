<?php
/**
 * Технический класс управляющий процессами
 *
 * @author Dmitrij Kulagin <dima@zero-day.net>
 */
class workClass {
    
    public $process_title = 'main_worker';
    
    public $process_count = 3;
    
    private $taskManager;
    
    private $env;

    private $redis;


    public function __construct($env='prod') {
        $this->env = $env;
        $this->redis = redisdb::getInstance(0, 10);
        $this->taskManager = new taskManager();
    }
    
    public function executionMainProcess($p) {
        if (!$this->checkRedisCache()){
            throw new Exception('checkRedisCache fail');
        }
        if ($p == 2){
            $this->checkFilledQueue();
        }
        $this->saveRadisCache();

        try { //@note: подумать на счёт конфигурации Event notification
            $this->redis->subscribe($this->taskManager->worker_channels, [$this->taskManager, 'doTask']);
            $this->redis->conn(0);
        } catch (RedisException $e) {
            Logger::errorLog(0, $e->getMessage());
        }
        $this->delRedisCache();
    }  
    
    public function restart() {
        $process_count = $this->getCountRedisCache();
        $this->shutdown();
        $this->start($process_count);
    }
    
    public function start($process_count=0) {  
        if (!$this->startPreparation($process_count)){
            exit(0);
        }
        $p=1;
        while ($p<=$this->process_count){
            switch ($child_pid = pcntl_fork()) {
                case -1:
                    die('Не удалось создать дочерний процесс');
                    break;
                case 0:           
                    cli_set_process_title($this->process_title);    
                    $this->redis->conn(0);
                    $this->executionMainProcess($p);      
                    exit(0);
                    break;
                default:
                    $p++;
                    usleep(100000);
                    break;
            }
        }
    }
    
    public function shutdown() {
        if (empty($this->getPIDS())){
            $this->redis->del('workers');
        }
        $this->redis->publish('system::shutdown', 'stop');
        sleep(5);
        $res = $this->redis->publish('system::shutdown', 'stop');        
        return $this->checkShutdownWorkers($res);
    }
    
    private function startPreparation($process_count) {     
        if ($this->env != 'prod'){
            return false;
        }         
        $this->process_count = $process_count ? $process_count : $this->process_count;
        if ($this->getCountRedisCache() >= $this->process_count){
            return false;
        }
        $this->redis->close();
        
        pcntl_signal_dispatch();
        pcntl_signal(SIGHUP, [$this, 'handleSignal']);
        pcntl_signal(SIGTERM, [$this, 'handleSignal']);
        pcntl_signal(SIGTTIN, [$this, 'handleSignal']);
        pcntl_signal(SIGINT,  [$this, 'handleSignal']);
        
        pcntl_signal(SIGCHLD, SIG_IGN);        
        return true;
    }
    
    public function handleSignal($signo, $pid = null, $status = null) {
        echo 1;
        file_put_contents('/var/log/zd_proc_signal', date('[Y-m-d H:i:s]').' '.$signo.' '.$pid.' '.$status.PHP_EOL, FILE_APPEND);
        exit(1);
    }    
    
    private function checkShutdownWorkers($publish_res) {        
        if ($publish_res != 0){
            throw new Exception('shutdown:redis timeout');
        }        
        if ($this->getCountRedisCache() != 0){
            echo implode(',', array_values($this->redis->hKeys('workers'))).PHP_EOL;
            throw new Exception('shutdown:redis bad shutdown');
        }  
        if (count($this->getPIDS()) > 0){
            throw new Exception('shutdown:cmd bad shutdown');
        }
        return true;
    }
    
    private function checkFilledQueue() {
        $this->checkSubscriber($this->taskManager->worker_channels);
        foreach ($this->taskManager->worker_channels as $task) {
            $count = $this->redis->lSize($task);
            if (is_numeric($count) && $count > 0){
                $this->publishQueue($task, $count);
            }    
        }        
    }
    
    private function checkSubscriber($task, $n=1) {
        if ($n>15){
            throw new Exception('redis:checkSubscriber fail');
        }
        if (!taskManager::checkSubscriber(redisdb::getInstance(0, 11), $task)){
            usleep(300000);
            $n++;
            $this->checkSubscriber($task, $n);
        }
        redisdb::getInstance(0, 11)->close();
        return true;
    }
    
    private function publishQueue($task, $count) {
        for ($i=0; $i<$count; $i++) {
            $this->redis->publish($task, 'process');
        }        
    }
    
    private function checkRedisCache() {
        return empty($this->redis->hGet('workers', getmypid()));
    }
    
    private function getPIDS() {
        $res = [];
        exec('ps ax | grep '.$this->process_title.' | grep -v grep | awk \'{ print $1":"$6 }\'', $res);
        return $res;        
    }
    
    private function saveRadisCache() {        
        $this->redis->hSet('workers', getmypid(), time());
    }
    
    private function delRedisCache($pid=false) {
        $this->redis->hDel('workers', $pid ? $pid : getmypid());
    }
    
    private function getCountRedisCache() {
        return count($this->redis->hKeys('workers'));
    }    
}

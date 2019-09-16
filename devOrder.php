<?php

/**
 * dev template order
 * шаблон, регулирующий действия заданного типа order-ов
 *
 * @author Dmitrij Kulagin <dima@zero-day.net>
 */
class devOrder extends baseOrder{
    use Singleton;
    
    const processing_delay = 10;
    
    private $plugin_url='';

    public function __construct() {
        parent::__construct();
        $this->archive_model = new archiveModel();
    }

    public function take($state, $data) {        
        $this->{$state}($data);
        $this->progress($state, $data);
    }    
    
    public function batchSale($orders) {
        foreach ($orders as $order) {
            $this->terminalTransaction($order, $order['terminal_request_type']);
        }
    }    

    private function regain($worker_queue, $worker_data) {
        $this->setQueue($worker_queue, $worker_data);       
    }

    private function externalInquiry($arr) {
        switch ($arr['back_status']) {
            case 'new':
                $this->executeNewOrder($arr);          
            break;
            case 'send_sms':
                $this->setQueue('AF::sendSMS', $arr);
                $this->progressionTransaction($arr['session_id'], 'rm_res', ['rm_data' => $arr['rm_data']], 'PrepareSMSCode'); 
            break;
            case 'smscode_successfully':
                $this->progressionTransaction($arr['session_id'], 'sms_check', 'success', 'RequestSMSCode');
                $arr['order_id'] = $this->redis->get($arr['request_id'].'/order_id');
                $this->terminalTransaction($arr);
                return;  
            case 'restored':
                $arr['order_id'] = $this->redis->get($arr['request_id'].'/order_id');
                $this->executeRestoreOrder($arr);
                break;
        }
    }
    
    private function executeNewOrder($arr) {
        if (!$this->checkUniqOrder($arr['merchant_id'], $arr['merchant_order_id'])){
            $this->regain('transfer::requestResumption',
                ['session_id' => $arr['session_id'], 'status' => 'dublicate', 'request_id' => $arr['request_id']]);
            return true;
        }
        if ($arr['merchant_id'] == 'e8ca8dff5065f7edaabc33ede250a559'){ //@todo: dev
            $this->redis2->set($arr['order_id'].'/card', $arr['card_data']);
            unset($arr['card_data']);
            $this->archive_model->saveTransaction($arr);
            $this->setQueue('AF::checkingAF', $arr);   
        } else {
            $arr['order_id'] = $this->addNewOrder($arr); // смена merchant_order_id на собственный order_id
            $this->redis2->set($arr['order_id'].'/card', $arr['card_data']);
            $this->redis->set($arr['order_id'].'/amount', [$arr['order_amount'], $arr['currency']]);   
            $this->redis->set($arr['request_id'].'/order_id', $arr['order_id']);
            $this->redis->set($arr['order_id'].'/last_session', $arr['session_id']);
            $this->redis->set($arr['request_id'].'/ip', $arr['ip']); //@note: убрать
            unset($arr['card_data']);
            $this->archive_model->saveTransaction($arr);
        }
        $this->setQueue('AF::checkingAF', $arr);
    }
    
    private function executeRestoreOrder($arr) {
        $this->redis2->set($arr['order_id'].'/card', $arr['card_data']);      
        $this->redis->set($arr['order_id'].'/last_session', $arr['session_id']);  
        $this->redis->set($arr['request_id'].'/ip', $arr['ip']); 
        unset($arr['card_data']);
        $this->saveTransaction($arr);

        $arr['order_id'] = $this->redis->get($arr['request_id'].'/order_id');
        $this->setQueue('AF::checkingAF', $arr);        
    }
    
    private function AFcheckResult($data) {
        $transfer = 'result_session';
        $mark = [];
        if ($data['action'] == 'decline'){
            $mark = ['AF_WARNING' => 'af check status: decline'];
        }        
        if ($data['action'] != 'blacklist'){
            $data['action'] = $this->getActionByScore($data['rm_data']['riskmonitor']['report'], $this->redis->get($data['request_id'].'/ip'));
        }
        switch ($data['action']) {
            case 'verify':
                $status = 'requestPhone';
                break;
            case 'approve':
                $status = 'AF_successfully';
                $transfer = 'payment_processing';
                $this->terminalTransaction($data);
                break;
            case 'decline':
                if (!$this->redis->get('request/'.$data['request_id'].'/restore/count')){
                    $this->redis->set('request/'.$data['request_id'].'/restore/count', '1'); 
                    $status = 'restore';
                } else {
                    $status = 'canceled';
                }
                break;
            case 'blacklist':
                //@TODO
                $status = 'decline';
                break;
        }
        $this->progressionTransaction($data['session_id'], 'rm_res', ['rm_data' => $data['rm_data']], $status);
        if ($transfer == 'result_session'){
            $this->regain('transfer::requestResumption', array_merge(['session_id' => $data['session_id'], 'status' => $status,
                'transfer' => $transfer, 'request_id' => $data['request_id']], $mark));
        }
    }
    
    private function AFSMSResult($data) {
        $this->progressionTransaction($data['session_id'], 'rm_send_sms',
                [
                    'rm_data' => $data['rm_data'], 'rm_id' => $data['rm_id'], 'rm_verif_id' => $data['rm_verif_id'], 'request_sms_code' => $data['request_sms_code']
                ],
                'requestCode');
        
        $this->regain('transfer::requestResumption',
                ['session_id' => $data['session_id'], 'status' => 'requestCode', 'request_sms_code' => $data['request_sms_code']]);  
    }
    
    private function terminalTransaction($data, $type='authorization') {
        $terminal_data = [
            'url' => $this->plugin_url,
            'type' => $type,
            'params' => $this->getTerminalParams($data, $type),
            'session_id' => $data['session_id'],
            'request_id' => $data['request_id'],
            'order_id' => $data['order_id'],
            'orderTemplate' => __CLASS__
        ];
        if ($type != 'authorization'){
            $terminal_data['terminal_order_id'] = $data['terminal_order_id'];
        }
        $this->setQueue('terminal::newRequest', $terminal_data);
    }
    
    private function terminalResult($data) {
        $data['terminal_status'] = $this->getTerminalStatus($data['terminal_result']);
        $this->model->updateOrderStatus($data['order_id'], $data['type'].'_'.$data['terminal_status']);
        $this->progressionTransaction($data['session_id'], $data['type'].'_terminal_result', $data['terminal_result'], $data['terminal_status']);  
        if ($data['type'] == 'authorization'){
            $this->regain('transfer::requestResumption',
                        ['session_id' => $data['session_id'], 'status' => $data['terminal_status'], 'request_id' => $data['request_id']]);
        }        
    }
    
    private function getTerminalStatus($res) {
        if ($res['status'] == 'done'){
            return 'successfully';
        }
        switch ($res['error_class']) {
            case '1':
                return 'decline';
            default:
                return 'canceled';
        }
    }
    
    private function terminalInProgress($data) {
        $this->setQueue('transfer::requestResumption', ['session_id' => $data['session_id'], 'status' => 'progress']);
    }
    
    private function getTerminalParams($data, $type) {
        $common_part = [$data['order_amount'], $data['currency']];
        if ($type == 'authorization'){
            return array_merge($this->getCardData($data), $common_part);
        }
        return $common_part;
    }
    
    /*
     * Получение данных карты из временного хранилизща
     */
    private function getCardData($data){
        $arr = $this->redis2->get($data['order_id'].'/card');      
        return [$arr['pin'], $arr['cvv'], $arr['exp']];
    }
}

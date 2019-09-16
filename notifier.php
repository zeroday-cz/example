<?php

/**
 * notifier module
 *
 * @author Dmitrij Kulagin <dima@zero-day.net>
 */
class notifier extends baseModule{
    
    /**
     * Очереди постбека
     * @var array
     */
    public $queue_list = [
        'session' => 'notifier/merchant/request-status',
        'exchange' => 'notifier/merchant/exchange_request-status'
        ];
    
    /**
     * Доступные сущности
     * @var array
     */
    public $id_name_list = ['session' => 'session_id', 'exchange' => 'request_id'];
    
    /**
     * Максимальное количество обрабатываемых элементов
     * @var int
     */
    public $count = 20;
    
    //служебные свойства класса
    private $entity_id_name;
    
    private $entity_name;

    private $repeat;    
    
    private $model;   

    /**
     * запуск модуля
     */
    public function merchantNotifier() {
        do {
            $this->executeQueue();
        } while ($this->repeat);        
    }
    
    /**
     * чтение очереди и инициирование отправки постбека
     */
    private function executeQueue() {
        foreach ($this->queue_list as $type => $queue_key) {            
            $queue = $this->getNotifierQueue($queue_key);            
            if ($queue){           
                $this->entity_name = $type;
                $this->entity_id_name = $this->id_name_list[$type];
                $entities = $this->prepareEntities($queue);
                $this->sendToMerchant($entities);
            }                    
        }    
    }
    
    /**
     * отправка постбека
     * @param array $data
     */
    private function sendToMerchant($data) {
        $merchants = [];
        foreach ($data as $merchant_rid => $note_entities) {
            if (!isset($merchants[$merchant_rid])){
                $merchant = $this->model()->getMerchantByRid($merchant_rid); //@note: check $merchant['url_postBack']
                $merchants[$merchant_rid] = $merchant;
            } else {
                $merchant = $merchants[$merchant_rid];
            }
            $response = transport::curl_send($merchant['url_postBack'], $this->getMerchantPostData($note_entities, $merchant), 'x-www-form-urlencoded', true, true);
            $this->saveNotice($response, $merchant);
            $this->processingResponse($response, $note_entities);
        }        
    }
    
    /**
     * Формирование полей POST запроса
     * @param array $note_entities
     * @param array $merchant
     * @return array
     */
    private function getMerchantPostData($note_entities, $merchant) {        
        $requests = [];
        foreach ($note_entities as $entity) {
            $requests[$entity['request_id']]['status'] = $entity['status'];
        }
        return ['requests_result' => $requests, 'signature' => sha1($merchant['merchant_id'].$merchant['api_key'])];
    }
    
    /**
     * Обработка ответа
     * @param array $response
     * @param array $note_entities
     */
    private function processingResponse($response, $note_entities) {
        if (isset($response['body'])){
            $notify_status = $this->getNotifyStatus($response['body']);
        } else {
            $notify_status = 0;
        }
        foreach ($note_entities as $entity_id => $entity) {
            if ($entity['status'] == 'successfully' && !$notify_status){ //@TODO: multiple status
                $entity['status'] = 'confirmation_error';
            }
            $ids[$entity_id] = $entity['status'];
        }
        $upd_data = [
            'entity_name' => $this->entity_name,
            'id_entity_name' => $this->entity_id_name
        ];
        $this->model()->notifiedUpdateByIds($upd_data['entity_name'], $ids, $notify_status);
    }
    
    /**
     * Статус уведомления на основе полученного ответа
     * @param string $response
     * @return boolean
     */
    private function getNotifyStatus($response) {
        return ($response == 'OK') ? 1 : 0;
    }
    
    private function prepareResponse($response) {
        if (isset($response['error'])){
            $response['body'] = 'curl error: '.$response['error'];
            return $response;
        }
        if ($response['response_code'] != '200'){
            $response['body'] = 'http error: '.$response['response_code'];
            return $response;
        }        
        return $response;
    }
    
    /**
     * Формирование сущности
     * @param array $arr
     * @return array
     */
    private function prepareEntities($arr) {
        foreach ($arr as $entity) {
            if (!isset($entity['data'])){     
                //@NOTE: на будущее, можно не привязываться к request_id меняя его здесь и ниже, сейчас он основной идентификатор
                //$this->entity_id_name = $this->id_name_list[$this->entity_name];
                $request[] = $entity[$this->entity_id_name];
                $status[$entity[$this->entity_id_name]] = $entity['status'];
            } else {
                //@NOTE: смена request_id в этом условии
                //$this->entity_id_name = $entity['data']['id_entity_name'];
                $note_entities[$entity['data']['merchant_rid']][$entity['data']['request_id']] = $entity['data'];
            }
        }
        if (isset($request)){
            //запрос данных сущности, если они отсутсвуют
            foreach ($this->getEntityData($request) as $fulldata) {
                $fulldata['status'] = $status[$fulldata[$this->entity_id_name]];
                $note_entities[$fulldata['merchant_rid']][$fulldata[$this->entity_id_name]] = $fulldata;
            }
        }
        return isset($note_entities) ? $note_entities : false;
    }
    
    /**
     * Сохранение
     * @param string $response
     * @param array $merchant
     */
    private function saveNotice($response, $merchant) {
        $response['date'] = date('Y-m-d H:i:s');
        $response['merchant_id'] = $merchant['merchant_id'];
        $response['url_postBack'] = $merchant['url_postBack'];
        $this->model()->saveLog($this->prepareResponse($response));
    }
    
    /**
     * Получение данных сущности
     * @param array $ids
     * @return array
     */
    private function getEntityData($ids) {
        if ($this->entity_name == 'session'){
            return $this->model()->getSessionsById($ids);
        } else if ($this->entity_name == 'exchange') {        
            return $this->model()->getExchangesById($ids);
        }
    }
    
    /**
     * Получение элементов очереди
     * @param string $queue_key
     * @return mixed
     */
    private function getNotifierQueue($queue_key) {
        usleep(mt_rand(1000,100000)); //pause
        $count = $this->redis->lSize($queue_key);
        if ($count > $this->count){
            $this->repeat = true;
            $count = $this->count;
        } else {
            $this->repeat = false;
        }        
        $queue = $this->redis->lRange($queue_key, 0, $count-1);
        $this->redis->lTrim($queue_key, $count, -1);
        return $queue;
    }
    
    /**
     * Модель класса
     * @return object
     */
    private function model() {
        if (!isset($this->model)){
            $this->model = new notifierModel();
        }
        return $this->model;
    }    
}

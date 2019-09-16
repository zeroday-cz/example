<?php
/**
 * informHelper
 *
 * @author Dmitrij Kulagin <dima@zero-day.net>
 */
class informHelper {
    
    private $db;
    
    private $archive_model;
    

    public function __construct() {
        $this->db = mysql::getInstance();
        $this->archive_model = new archiveModel();
    }
    
    public function getMerchants() {
        return $this->db->select('SELECT ID, NAME, API_KEY, FORM_FIELDS FROM BUSINESS');
    }
    
    public function getOrderList($b_id) {
        $res = $this->db->select('SELECT ORDER_BUSINESS_ID AS SELF_ID, ORDER_CUSTOMER_ID AS CUSTOMER_ID, CREATION_TIME, AMOUNT_TOTAL, PROGRESS FROM PAYMENT_ORDER WHERE BUSINESS_ID="'.$b_id.'"');
        if (empty($res)){
            return [];
        }
        foreach ($res as $row) {
            if ($row['PROGRESS'] == 'new'){
                continue;
            }            
            if ($row['PROGRESS'] == 'sale_successfully' || $row['PROGRESS'] == 'authorization_successfully'){
                $row['STATUS'] = 'success';
            } else {
                $row['STATUS'] = 'decline';
            }
            unset($row['PROGRESS']);
            settype($row['AMOUNT_TOTAL'], 'string');
            $row['FEE_TOTAL'] = '0.00'; //@TODO: addfee
            $result['ORDERS'][] = $row;
        }
        return $result;
    }
    
    public function getOrder($b_id) {
        $data = $this->db->select('SELECT ORDER_BUSINESS_ID AS SELF_ID, ORDER_CUSTOMER_ID AS CUSTOMER_ID, REQUEST_ID, CREATION_TIME, AMOUNT_TOTAL, PROGRESS FROM PAYMENT_ORDER WHERE ORDER_BUSINESS_ID="'.$b_id.'"');
        if ($data['PROGRESS'] == 'sale_successfully' || $data['PROGRESS'] == 'authorization_successfully'){
            $data['STATUS'] = 'success';
        } else {
            $data['STATUS'] = 'decline';
        }        
        $full_data = $this->archive_model->getRequestFromArchive($data['REQUEST_ID']);
        unset($data['PROGRESS'], $data['REQUEST_ID']);
        $name = explode(' ', $full_data['sessions'][$full_data['last_session']]['customer_card_name']);
        $data['USER_FIRST_NAME'] = $name[0];
        $data['USER_LAST_NAME'] = isset($name[1]) ? $name[1] : '';
        $data['REBILL'] = "0";
        $data['FEE_TOTAL'] = "0.00";
        $data['CARD_BIN'] = $full_data['sessions'][$full_data['last_session']]['bin'];
        $data['CARD_LAST4'] = $full_data['sessions'][$full_data['last_session']]['last4'];
        $data['COUNTRY'] = $full_data['sessions'][$full_data['last_session']]['customer_billing_country'];
        $data['PHONE'] = $full_data['sessions'][$full_data['last_session']]['customer_billing_telephone'];
        $data['IP'] = $full_data['sessions'][$full_data['last_session']]['ip'];
        $data['AF_DATA'] = $full_data['sessions'][$full_data['last_session']]['rm_res']['rm_data']['riskmonitor'];
        return $data;
    }
}

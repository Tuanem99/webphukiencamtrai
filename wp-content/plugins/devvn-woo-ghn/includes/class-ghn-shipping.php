<?php
defined( 'ABSPATH' ) or die( 'No script kiddies please!' );
/*
 * Author Name: Le Van Toan
 * Author URI: https://levantoan.com
 */
function ghn_shipping_method_init() {
    if ( ! class_exists( 'WC_GHN_Shipping_Method' ) ) {
        class WC_GHN_Shipping_Method extends WC_Shipping_Method {
            public $ghn_mess = '';
            /**
             * Constructor for your shipping class
             *
             * @access public
             * @return void
             */
            public function __construct() {

                $this->id                 = 'ghn_shipping_method';
                $this->method_title       = __( 'Giao hàng nhanh (GHN)' );
                $this->method_description = __( 'Tính phí vận chuyển và đồng bộ đơn hàng với giao hàng nhanh (GHN)' );

                $this->init();

                $this->enabled            = $this->settings['enabled'];
                $this->title              = $this->settings['title'];

            }

            /**
             * Init your settings
             *
             * @access public
             * @return void
             */
            function init() {
                // Load the settings API
                $this->init_form_fields();
                $this->init_settings();

                // Save settings in admin if you have any defined
                add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
            }

            function init_form_fields() {
                $this->form_fields = array(
                    'enabled' => array(
                        'title'     => __( 'Kích hoạt', 'devvn-ghn' ),
                        'type'      => 'checkbox',
                        'label'     => __( 'Kích hoạt tính phí vận chuyển bằng GHN', 'devvn-ghn' ),
                        'default'   => 'yes',
                    ),
                    'title' => array(
                        'title' => __( 'Tiêu đề', 'devvn-ghn' ),
                        'type' => 'text',
                        'description' => __( 'Mô tả cho phương thức vận chuyển', 'devvn-ghn' ),
                        'default' => __( 'Vận chuyển qua GHN', 'devvn-ghn' )
                    ),
                );
            } // End init_form_fields()

            /**
             * calculate_shipping function.
             *
             * @access public
             * @param mixed $package
             * @return void
             */
            public function calculate_shipping( $package = array() ) {

                $rates = ghn_api()->findAvailableServices($package);

                if($rates && !empty($rates)) {
                    $HubID = end($rates);
                    $HubID = isset($HubID['HubID']) ? $HubID['HubID'] : '';
                    foreach($rates as $methob) {
                        $ServiceID =  isset($methob['ServiceID']) ? intval($methob['ServiceID']) : '';
                        if($ServiceID) {
                            $rate = array(
                                'id' => $this->id . '_' . $ServiceID,
                                'label' => isset($methob['Name']) ? esc_attr($methob['Name']) : '',
                                'cost' => isset($methob['ServiceFee']) ? (float)$methob['ServiceFee'] : 0,
                                'calc_tax' => 'per_item',
                                'meta_data' => array(
                                    'ExpectedDeliveryTime' => isset($methob['ExpectedDeliveryTime']) ? date('d/m/Y', strtotime($methob['ExpectedDeliveryTime'])) : '',
                                    'HubID' => $HubID,
                                    'ServiceID' => $ServiceID,
                                )
                            );
                            $this->add_rate($rate);
                        }
                    }
                }
            }

            function devvn_no_shipping_cart(){
                return $this->ghn_mess;
            }
        }
    }
}

add_action( 'woocommerce_shipping_init', 'ghn_shipping_method_init' );

function add_ghn_shipping_method( $methods ) {
    $methods['ghn_shipping_method'] = 'WC_GHN_Shipping_Method';
    return $methods;
}

add_filter( 'woocommerce_shipping_methods', 'add_ghn_shipping_method' );

class DevVN_GHN_API{

    protected static $_instance = null;
    private $token = '';
    private $url_remote = '';

    public static function instance() {
        if ( is_null( self::$_instance ) ) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    public $_allhubs = 'ghn_allhubs';
    public $_allhubs_group = 'ghn_allhubs_option';
    public $_defaultHubsOptions = array();

    public function __construct() {
        $this->token = ghn_class()->get_options('token_key');
        if(!$this->token){
            $this->url_remote = 'http://api.serverapi.host/api/v1/apiv3/';
            $this->token = 'TokenTest';
        }else{
            $this->url_remote = 'https://console.ghn.vn/api/v1/apiv3/';
        }

        add_action( 'add_meta_boxes', array($this, 'ghn_order_action') );
        add_action( 'save_post', array($this, 'ghn_save_meta_box'), 10, 2 );
        add_action('woocommerce_admin_order_data_after_order_details', array($this, 'ghn_update_order_html') );

        add_action( 'wp_ajax_update_hubs', array($this, 'devvn_update_hubs') );
        add_action( 'wp_ajax_add_hubs', array($this, 'devvn_add_hubs') );
        add_action( 'wp_ajax_add_hubdistrict', array($this, 'devvn_add_hubdistrict') );
        add_action( 'wp_ajax_ghn_change_hub', array($this, 'devvn_ghn_change_hub') );
        add_action( 'wp_ajax_ghn_creat_order', array($this, 'devvn_ghn_creat_order') );
        add_action( 'wp_ajax_ghn_update_order', array($this, 'devvn_ghn_update_order') );
        add_action( 'wp_ajax_ghn_tracking_order', array($this, 'devvn_ghn_tracking_order') );
        add_action( 'wp_ajax_ghn_cancel_order', array($this, 'devvn_ghn_cancel_order') );

        add_action( 'admin_init', array( $this, 'register_mysettings') );
        add_option( $this->_allhubs, $this->_defaultHubsOptions );

    }

    function register_mysettings(){
        register_setting( $this->_allhubs_group, $this->_allhubs );
    }

    function delete_cache(){
        delete_transient($this->token . '_allhubs');
    }

    function get_hubs_near($city_customer_id = '', $field = 'DistrictID'){
        if(!$city_customer_id) return false;
        $all_hub_district = get_option(ghn_api()->_allhubs);
        $main_hub = $this->get_main_hubs();
        $hub_near = ghn_class()->search_in_array_value($all_hub_district, $city_customer_id);

        if(!empty($hub_near) || in_array($main_hub, $hub_near)) {
            $hub_near = $hub_near[0];
        }else{
            $hub_near = $main_hub;
        }

        $allHubs = $this->getHubs();
        $hub_near = ghn_class()->search_in_array($allHubs, 'HubID', $hub_near);
        if($field == 'all') {
            $hub_near = isset($hub_near[0]) ? $hub_near[0] : '';
        }else{
            $hub_near = isset($hub_near[0]) ? $hub_near[0][$field] : '';
        }
        return $hub_near;
    }

    function get_main_hubs($field = 'HubID'){
        $allHubs = $this->getHubs();
        $mainHub = ghn_class()->search_in_array($allHubs, 'IsMain', 1);
        if(isset($mainHub[0])) {
            if ($field == 'all') {
                return $mainHub[0];
            } else {
                return $mainHub[0][$field];
            }
        }else{
            return false;
        }
    }

    function get_hub_by_id($hubID = '', $field = 'DistrictID'){
        $allHubs = $this->getHubs();
        $mainHub = ghn_class()->search_in_array($allHubs, 'HubID', $hubID);
        if(isset($mainHub[0])) {
            if ($field == 'all') {
                return $mainHub[0];
            } else {
                return $mainHub[0][$field];
            }
        }else{
            return false;
        }
    }

    function get_cURL($args = array()){

        if(empty($args)) return false;

        $data = isset($args['data']) ? $args['data'] : '';
        $action = isset($args['action']) ? $args['action'] : 'CalculateFee';

        $curl = curl_init();

        curl_setopt_array($curl, array(
            CURLOPT_URL => $this->url_remote . $action,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_SSL_VERIFYPEER => is_ssl(),
            CURLOPT_CUSTOMREQUEST => "POST",
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => array(
                "Content-Type: application/json"
            ),
        ));

        $response = curl_exec($curl);
        curl_close($curl);

        $result = json_decode($response,true);

        return $result;
    }

    public function calcShippingFee($package = array())
    {

        $args = array(
            'data'  =>  array(
                "token"	=> $this->token
            ),
            'action'    =>  'CalculateFee'

        );
        $result = $this->get_cURL($args);
        if($result && is_array($result) && !empty($result)){
            if(isset($result['code']) && $result['code'] == 1){
                $data = isset($result['data']) ? $result['data'] : '';
                return $data;
            }else{
                return false;
            }

        }
        return false;
    }

    public function findAvailableServices($package = array(), $hubid = '')
    {
        $ToDistrictID = isset($package['destination']['state']) ? ghn_class()->get_district_id_from_string($package['destination']['state']) : '';
        $state = isset($package['destination']['state']) ? ghn_class()->get_state_id_from_string($package['destination']['state']) : '';
        if($hubid){
            $DistrictID = $this->get_hub_by_id($hubid);
        }
        $data = array(
            "token"	=> $this->token,
            "Weight" => (float) ghn_class()->get_cart_contents_weight($package),
            "Length" => (float) ghn_class()->get_cart_dimension_package($package, 'length'),
            "Width" => (float) ghn_class()->get_cart_dimension_package($package, 'width'),
            "Height" => (float) ghn_class()->get_cart_dimension_package($package),
            "FromDistrictID" => isset($DistrictID) ? (float) $DistrictID : $this->get_hubs_near($state),
            "ToDistrictID" => $ToDistrictID,
            "CouponCode" => "",
            "InsuranceFee" => isset($package['cart_subtotal']) ? $package['cart_subtotal'] : '',
        );
        $args = array(
            'data'  =>  $data,
            'action'    =>  'FindAvailableServices'

        );
        $result = $this->get_cURL($args);

        if($result && is_array($result) && !empty($result)){
            if(isset($result['code']) && $result['code'] == 1){
                $data = isset($result['data']) ? $result['data'] : '';
                if($data) {
                    $data[]['HubID'] = ($hubid) ? $hubid : $this->get_hubs_near($state, 'HubID');
                }
                return $data;
            }else{
                return false;
            }
        }
        return false;
    }
    public function order_findAvailableServices($order = '', $hubid = '')
    {
        if(!$order) return false;
        $customer_infor = ghn_class()->get_customer_address_shipping($order);
        $ToDistrictID = $customer_infor['disrict'];
        $state = $customer_infor['province'];
        if($hubid){
            $DistrictID = $this->get_hub_by_id($hubid);
        }
        $data = array(
            "token"	=> $this->token,
            "Weight" =>  (float) ghn_class()->convert_weight_to_gram(ghn_class()->get_order_weight($order)),
            "Length" => (float) ghn_class()->convert_dimension_to_cm(ghn_class()->get_order_weight($order, 'length')),
            "Width" => (float) ghn_class()->convert_dimension_to_cm(ghn_class()->get_order_weight($order, 'width')),
            "Height" => (float) ghn_class()->convert_dimension_to_cm(ghn_class()->get_order_weight($order, 'height')),
            "FromDistrictID" => isset($DistrictID) ? (float) $DistrictID : $this->get_hubs_near($state),
            "ToDistrictID" => $ToDistrictID,
            "CouponCode" => "",
            "InsuranceFee" => ghn_class()->order_get_total($order)
        );
        $args = array(
            'data'  =>  $data,
            'action'    =>  'FindAvailableServices'

        );
        $result = $this->get_cURL($args);

        if($result && is_array($result) && !empty($result)){
            if(isset($result['code']) && $result['code'] == 1){
                $data = isset($result['data']) ? $result['data'] : '';
                if($data) {
                    $data[]['HubID'] = ($hubid) ? $hubid : $this->get_hubs_near($state, 'HubID');
                }
                return $data;
            }else{
                return false;
            }
        }
        return false;
    }
    public function getHubs()
    {
        if ( false === ( $allhubs = get_transient( $this->token . '_allhubs' ) ) ) {
            $args = array(
                'data' => array(
                    "token" => $this->token
                ),
                'action' => 'GetHubs'

            );
            $result = $this->get_cURL($args);

            if ($result && is_array($result) && !empty($result)) {
                if (isset($result['code']) && $result['code'] == 1) {
                    $data = isset($result['data']) ? devvn_sort_desc_array($result['data'], 'IsMain') : '';
                    set_transient($this->token . '_allhubs', $data);
                    return $data;
                }
            }
            $this->delete_cache();
            return false;
        }else{
            return $allhubs;
        }
    }
    public function updateHubs($data = array())
    {
        if(!is_array($data) || empty($data)) return false;
        $args = array(
            'data'  =>  array(
                "token"	=> $this->token,
                "Latitude" => 0,
                "Longitude" => 0,
                "IsMain"    =>  false
            ),
            'action'    =>  'UpdateHubs'
        );
        foreach($data as $k=>$v){
            if($k == 'HubID'){
                $args['data'][$k] = (int) $v;
            }elseif($k == 'IsMain'){
                $args['data']['IsMain'] = ($v == 1) ? true : false;
            }elseif( $k == 'DistrictID') {
                $args['data'][$k] = ghn_class()->get_district_id_from_string($v);
            }else{
                $args['data'][$k] = $v;
            }
        }
        $result = $this->get_cURL($args);

        if($result && is_array($result) && !empty($result)){
            if(isset($result['code']) && $result['code'] == 1){
                $this->delete_cache();
                return true;
            }else{
                return (isset($result['msg'])) ? $result['msg'] : false;
            }
        }
        return false;
    }

    public function addHubs($data = array())
    {
        if(!is_array($data) || empty($data)) return false;
        $args = array(
            'data'  =>  array(
                "token"	=> $this->token,
                "Latitude" => 0,
                "Longitude" => 0,
                "IsMain"    =>  false
            ),
            'action'    =>  'AddHubs'
        );
        foreach($data as $k=>$v){
            if($k == 'IsMain'){
                $args['data']['IsMain'] = ($v == 1) ? true : false;
            }elseif( $k == 'DistrictID') {
                $args['data'][$k] = ghn_class()->get_district_id_from_string($v);
            }else{
                $args['data'][$k] = $v;
            }
        }
        $result = $this->get_cURL($args);

        if($result && is_array($result) && !empty($result)){
            if(isset($result['code']) && $result['code'] == 1){
                $this->delete_cache();
                return true;
            }else{
                return (isset($result['msg'])) ? $result['msg'] : false;
            }
        }
        return false;
    }

    function devvn_update_hubs(){
        if ( !wp_verify_nonce( $_REQUEST['nonce'], "action_nonce_update")) {
            wp_send_json_error();
            die();
        }

        $data = isset($_POST['data']) ? $_POST['data'] : array();
        if(true === ($results = $this->updateHubs($data))){
            wp_send_json_success();
        }else{
            wp_send_json_error($results);
        }
        die();
    }

    function devvn_add_hubs(){
        if ( !wp_verify_nonce( $_REQUEST['nonce'], "action_nonce_add")) {
            wp_send_json_error();
            die();
        }
        $data = isset($_POST['data']) ? $_POST['data'] : array();
        if(true === ($results = $this->addHubs($data))){
            wp_send_json_success(__('Thêm cửa hàng/kho thành công! Đang làm mới...'));
        }else{
            wp_send_json_error($results);
        }
        die();
    }

    function devvn_add_hubdistrict(){
        if ( !wp_verify_nonce( $_REQUEST['nonce'], "action_nonce_update")) {
            wp_send_json_error('Check nonce failed');
            die();
        }
        $hubid = isset($_POST['hubid']) ? $_POST['hubid'] : 0;
        $districtID = isset($_POST['districtID']) ? $_POST['districtID'] : array();

        if($hubid){
            $old_hub_district = get_option(ghn_api()->_allhubs);
            $old_hub_district[$hubid] = $districtID;
            if(update_option( $this->_allhubs, $old_hub_district)){
                wp_send_json_success('Update thành công');
            }else{
                wp_send_json_error('Có lỗi khi update');
            }
        }
        wp_send_json_error('Không tồn tại HubID');
        die();
    }

    public function createOrder($data = array())
    {
        if(!is_array($data) || empty($data)) return false;
        $args = array(
            'data'  =>  array(
                "token"	=> $this->token,
                "PaymentTypeID" => (isset($data['PaymentTypeID']) && $data['PaymentTypeID']) ? intval($data['PaymentTypeID']) : 1,
                "FromDistrictID" => (isset($data['FromDistrictID']) && $data['FromDistrictID']) ? intval($data['FromDistrictID']) : 0,
                "FromWardCode" => (isset($data['FromWardCode']) && $data['FromWardCode']) ? sanitize_text_field($data['FromWardCode']) : "",
                "ToDistrictID" => (isset($data['ToDistrictID']) && $data['ToDistrictID']) ? intval($data['ToDistrictID']) : 0,
                "ToWardCode" => isset($data['ToWardCode']) ? sanitize_text_field($data['ToWardCode']) : "",
                "Note" => isset($data['Note']) ? sanitize_textarea_field($data['Note']) : "",
                "SealCode" => isset($data['SealCode']) ? sanitize_text_field($data['SealCode']) : "",
                "ExternalCode" => isset($data['ExternalCode']) ? sanitize_text_field($data['ExternalCode']) : "",

                "ClientContactName" => isset($data['ClientContactName']) ? sanitize_text_field($data['ClientContactName']) : "",
                "ClientContactPhone" => isset($data['ClientContactPhone']) ? sanitize_text_field($data['ClientContactPhone']) : "",
                "ClientAddress" => isset($data['ClientAddress']) ? sanitize_text_field($data['ClientAddress']) : "",
                "ClientHubID" => (isset($data['ClientHubID']) && $data['ClientHubID']) ? intval($data['ClientHubID']) : 0,

                "CustomerName" => isset($data['CustomerName']) ? sanitize_text_field($data['CustomerName']) : "",
                "CustomerPhone" => isset($data['CustomerPhone']) ? sanitize_text_field($data['CustomerPhone']) : "",
                "ShippingAddress" => isset($data['ShippingAddress']) ? sanitize_text_field($data['ShippingAddress']) : "",

                "CoDAmount" => isset($data['CoDAmount']) ? (float) $data['CoDAmount'] : 0,
                "NoteCode" => (isset($data['NoteCode']) && $data['NoteCode']) ? sanitize_text_field($data['NoteCode']) : apply_filters('devvn_notecode_default', 'KHONGCHOXEMHANG'),

                "InsuranceFee" => isset($data['InsuranceFee']) ? (float) $data['InsuranceFee'] : 0,

                "ServiceID" => isset($data['ServiceID']) ? (int) $data['ServiceID'] : 0,

                "ToLatitude" => isset($data['ToLatitude']) ? (float) $data['ToLatitude'] : 0,
                "ToLongitude" => isset($data['ToLongitude']) ? (float) $data['ToLongitude'] : 0,
                "FromLat" => isset($data['FromLat']) ? (float) $data['FromLat'] : 0,
                "FromLng" => isset($data['FromLng']) ? (float) $data['FromLng'] : 0,

                "Content" => isset($data['Content']) ? sanitize_text_field($data['Content']) : "",
                "CouponCode" => isset($data['CouponCode']) ? sanitize_text_field($data['CouponCode']) : "",

                "Weight" => isset($data['Weight']) && $data['Weight'] ? (float) $data['Weight'] : 0,
                "Length" => isset($data['Length']) && $data['Length'] ? (float) $data['Length'] : 1,
                "Width" => isset($data['Width']) && $data['Width'] ? (float) $data['Width'] : 1,
                "Height" => isset($data['Height']) && $data['Height'] ? (float) $data['Height'] : 1,

                "ShippingOrderCosts" => isset($data['ShippingOrderCosts']) ? $data['ShippingOrderCosts'] : array(),

                "CheckMainBankAccount" => false,
                "ReturnContactName" => "",
                "ReturnContactPhone" => "",
                "ReturnAddress" => "",
                "ReturnDistrictCode" => "",
                "ExternalReturnCode" => "",
                "IsCreditCreate" => false,
                "AffiliateID"  =>  ghn_class()->myAffID()
            ),
            'action'    =>  'CreateOrder'
        );

        $result = $this->get_cURL($args);

        return $result;
    }

    public function updateOrder($data = array())
    {
        if(!is_array($data) || empty($data)) return false;
        $args = array(
            'data'  =>  array(
                "token"	=> $this->token,

                "ShippingOrderID" => (isset($data['ShippingOrderID']) && $data['ShippingOrderID']) ? intval($data['ShippingOrderID']) : 0,
                "OrderCode" => (isset($data['OrderCode']) && $data['OrderCode']) ? sanitize_text_field($data['OrderCode']) : 1,

                "PaymentTypeID" => (isset($data['PaymentTypeID']) && $data['PaymentTypeID']) ? intval($data['PaymentTypeID']) : 1,
                "FromDistrictID" => (isset($data['FromDistrictID']) && $data['FromDistrictID']) ? intval($data['FromDistrictID']) : 0,
                "FromWardCode" => (isset($data['FromWardCode']) && $data['FromWardCode']) ? sanitize_text_field($data['FromWardCode']) : "",
                "ToDistrictID" => (isset($data['ToDistrictID']) && $data['ToDistrictID']) ? intval($data['ToDistrictID']) : 0,
                "ToWardCode" => isset($data['ToWardCode']) ? sanitize_text_field($data['ToWardCode']) : "",
                "Note" => isset($data['Note']) ? sanitize_textarea_field($data['Note']) : "",
                "SealCode" => isset($data['SealCode']) ? sanitize_text_field($data['SealCode']) : "",
                "ExternalCode" => isset($data['ExternalCode']) ? sanitize_text_field($data['ExternalCode']) : "",

                "ClientContactName" => isset($data['ClientContactName']) ? sanitize_text_field($data['ClientContactName']) : "",
                "ClientContactPhone" => isset($data['ClientContactPhone']) ? sanitize_text_field($data['ClientContactPhone']) : "",
                "ClientAddress" => isset($data['ClientAddress']) ? sanitize_text_field($data['ClientAddress']) : "",
                "ClientHubID" => (isset($data['ClientHubID']) && $data['ClientHubID']) ? intval($data['ClientHubID']) : 0,

                "CustomerName" => isset($data['CustomerName']) ? sanitize_text_field($data['CustomerName']) : "",
                "CustomerPhone" => isset($data['CustomerPhone']) ? sanitize_text_field($data['CustomerPhone']) : "",
                "ShippingAddress" => isset($data['ShippingAddress']) ? sanitize_text_field($data['ShippingAddress']) : "",

                "CoDAmount" => isset($data['CoDAmount']) ? (float) $data['CoDAmount'] : 0,
                "NoteCode" => (isset($data['NoteCode']) && $data['NoteCode']) ? sanitize_text_field($data['NoteCode']) : apply_filters('devvn_notecode_default', 'KHONGCHOXEMHANG'),

                "InsuranceFee" => isset($data['InsuranceFee']) ? (float) $data['InsuranceFee'] : 0,

                "ServiceID" => isset($data['ServiceID']) ? (int) $data['ServiceID'] : 0,

                "ToLatitude" => isset($data['ToLatitude']) ? (float) $data['ToLatitude'] : 0,
                "ToLongitude" => isset($data['ToLongitude']) ? (float) $data['ToLongitude'] : 0,
                "FromLat" => isset($data['FromLat']) ? (float) $data['FromLat'] : 0,
                "FromLng" => isset($data['FromLng']) ? (float) $data['FromLng'] : 0,

                "Content" => isset($data['Content']) ? sanitize_text_field($data['Content']) : "",
                "CouponCode" => isset($data['CouponCode']) ? sanitize_text_field($data['CouponCode']) : "",

                "Weight" => isset($data['Weight']) && $data['Weight'] ? (float) $data['Weight'] : 0,
                "Length" => isset($data['Length']) && $data['Length'] ? (float) $data['Length'] : 1,
                "Width" => isset($data['Width']) && $data['Width'] ? (float) $data['Width'] : 1,
                "Height" => isset($data['Height']) && $data['Height'] ? (float) $data['Height'] : 1,

                "ShippingOrderCosts" => isset($data['ShippingOrderCosts']) ? $data['ShippingOrderCosts'] : array(),

                "CheckMainBankAccount" => false,
                "ReturnContactName" => "",
                "ReturnContactPhone" => "",
                "ReturnAddress" => "",
                "ReturnDistrictCode" => "",
                "ExternalReturnCode" => "",
                "IsCreditCreate" => false,
                "AffiliateID"  =>  ghn_class()->myAffID()
            ),
            'action'    =>  'UpdateOrder'
        );

        $result = $this->get_cURL($args);

        return $result;
    }

    function ghn_order_action(){
        add_meta_box(
            'ghn-action-id',
            __( 'Giao Hàng NHANH (GHN)', 'devvn-ghn' ),
            array($this, 'ghn_order_action_callback'),
            'shop_order',
            'side',
            'high'
        );
    }

    function ghn_order_action_callback($post){
        wp_nonce_field( 'ghn_action_nonce_action', 'ghn_action_nonce' );
        $ghn_order_fullinfor = get_post_meta($post->ID, '_ghn_order_fullinfor', true);
        $ghn_ordercode = get_post_meta($post->ID, '_ghn_ordercode', true);
        $ghn_order_status = get_post_meta($post->ID, '_ghn_order_status', true);
        $ghn_order_submited = get_post_meta($post->ID, '_ghn_order_submited', true);
        ?>
        <?php if($ghn_ordercode):?>
            <p><?php printf(__('<strong>Mã vận đơn:</strong> %s', 'devvn-ghn'), $ghn_ordercode);?></p>
            <?php if($ghn_order_status):?>
            <p><?php printf(__('<strong>Trạng thái:</strong> %s', 'devvn-ghn'), $this->get_status_text($ghn_order_status));?></p>
            <?php endif;?>
            <p><a href="#" class="button button-primary ghn_update_order" data-ordercode="<?php echo $ghn_ordercode;?>"><?php _e('Chỉnh sửa đơn hàng', 'devvn-ghn')?></a></p>
            <p><a href="#" class="button button-primary ghn_tracking_order" data-ordercode="<?php echo $ghn_ordercode;?>"><?php _e('Kiểm tra đơn hàng', 'devvn-ghn')?></a></p>
            <p><a href="#" class="button button-link-delete ghn_cancel_order" data-ordercode="<?php echo $ghn_ordercode;?>"><?php _e('Hủy đơn hàng', 'devvn-ghn')?></a></p>
        <?php else:?>
            <a href="#" class="button button-primary ghn_creat_order_popup"><?php _e('Tạo vận đơn', 'devvn-ghn')?></a>
        <?php endif;?>
        <?php
    }
    function devvn_woocommerce_admin_order_data_after_order_details($order){
        $customer_infor = ghn_class()->get_customer_address_shipping($order);
        extract($customer_infor);

        $shipping_methods = $order->get_shipping_methods();
        $HubID_Order = '';
        $method_id = '';
        foreach ( $shipping_methods as $shipping_method ) {
            foreach($shipping_method->get_formatted_meta_data() as $meta_data){
                if($meta_data->key && $meta_data->key == 'HubID' && !$HubID_Order){
                    $HubID_Order = $meta_data->value;
                }
            }
            foreach($shipping_method->get_formatted_meta_data() as $meta_data){
                if($meta_data->key && $meta_data->key == 'ServiceID' && !$method_id){
                    $method_id = $meta_data->value;
                }
            }
        }

        $product_list = ghn_class()->get_product_args($order);
        ?>
        <div class="ghn_popup_style ghn_creat_popup devvn_options_style">
            <div class="devvn_option_box">
                <table class="devvn_hubs_table widefat" cellspacing="0">
                    <thead>
                    <tr>
                        <th colspan="2"><h2><?php _e('Đăng đơn hàng lên GHN', 'devvn-ghn'); ?></h2></th>
                    </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>Chọn cửa hàng/kho</td>
                            <td>
                                <select name="ghn_creatorder_hub" id="ghn_creatorder_hub">
                                    <option class="">Chọn cửa hàng/kho</option>
                                    <?php
                                    $all_hubs = $this->getHubs();
                                    if(!empty($all_hubs) && is_array($all_hubs)){
                                        foreach($all_hubs as $hub){
                                            $hubID = isset($hub['HubID']) ? $hub['HubID'] : '';
                                            $Address = isset($hub['Address']) ? $hub['Address'] : '';
                                            $ContactName = isset($hub['ContactName']) ? $hub['ContactName'] : '';
                                            ?>
                                            <option value="<?php echo $hubID;?>" <?php selected($hubID, $HubID_Order)?>><?php echo '#'.$hubID . ' - '. $ContactName .' - ' . $Address;?></option>
                                            <?php
                                        }
                                    }
                                    ?>
                                </select><br>
                                <small>Phần này là tự động. Trong trường hợp thay đổi chi nhanh có thể sẽ làm phí vận chuyển thay đổi</small>
                            </td>
                        </tr>
                    <tr>
                        <td colspan="2">
                            <div class="devvn_option_2col ghn_order_customerinfor">
                                <div class="devvn_option_col">
                                    <strong>Thông tin khách hàng</strong>
                                    <div class="ghn_customer_infor">
                                        <div class="ghn_customer_row">
                                            <div class="ghn_customer_col">
                                                <?php _e('Họ và tên', 'devvn-ghn');?>
                                            </div>
                                            <div class="ghn_customer_col">
                                                <?php echo $name;?>
                                            </div>
                                        </div>
                                        <div class="ghn_customer_row">
                                            <div class="ghn_customer_col">
                                                <?php _e('Số điện thoại', 'devvn-ghn');?>
                                            </div>
                                            <div class="ghn_customer_col">
                                                <?php echo $phone;?>
                                            </div>
                                        </div>
                                        <div class="ghn_customer_row">
                                            <div class="ghn_customer_col">
                                                <?php _e('Địa chỉ', 'devvn-ghn');?>
                                            </div>
                                            <div class="ghn_customer_col">
                                                <?php echo $address;?>
                                            </div>
                                        </div>
                                        <div class="ghn_customer_row">
                                            <div class="ghn_customer_col">
                                                <?php _e('Phường/Xã', 'devvn-ghn');?>
                                            </div>
                                            <div class="ghn_customer_col">
                                                <?php echo ghn_class()->get_name_ward($ward);?>
                                            </div>
                                        </div>
                                        <div class="ghn_customer_row">
                                            <div class="ghn_customer_col">
                                                <?php _e('Khu vực', 'devvn-ghn');?>
                                            </div>
                                            <div class="ghn_customer_col">
                                                <?php echo ghn_class()->get_name_city($province.'_'.$disrict);?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="devvn_option_col">
                                    <strong>Thông tin sản phẩm</strong>
                                    <table class="prod_table">
                                        <thead>
                                        <tr>
                                            <th>Tên sp</th>
                                            <th>Weight</th>
                                            <th>SL</th>
                                        </tr>
                                        </thead>
                                        <tbody>
                                        <?php
                                        $content_order = '';
                                        if($product_list && !is_wp_error($product_list) && !empty($product_list)):
                                            foreach($product_list as $product):
                                                $content_order .= $product['name'] .' x '. $product['quantity'] . ' | ';
                                                ?>
                                                <tr>
                                                    <td><?php echo $product['name']?></td>
                                                    <td><?php echo $product['weight']?></td>
                                                    <td><?php echo $product['quantity']?></td>
                                                </tr>
                                            <?php endforeach;?>
                                        <?php endif;?>
                                        </tbody>
                                    </table>
                                    <textarea name="ghn_contentOrder" id="ghn_contentOrder"><?php echo esc_textarea($content_order);?></textarea>
                                </div>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <td colspan="2">
                            <div class="devvn_option_2col ghn_order_customerinfor">
                                <div class="devvn_option_col">
                                    <strong><?php _e('Gói hàng', 'devvn-ghn');?></strong>
                                    <table class="goihang_table">
                                        <tbody>
                                        <tr>
                                            <td><?php _e('Giá trị gói hàng', 'devvn-ghn')?></td>
                                            <td>
                                                <input type="text" readonly name="ghn_InsuranceFee" id="ghn_InsuranceFee" value="<?php echo ghn_class()->order_get_total($order)?>"/> <?php echo get_woocommerce_currency_symbol();?>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td><?php _e('Khối lượng', 'devvn-ghn')?></td>
                                            <td>
                                                <?php $all_weight = ghn_class()->convert_weight_to_gram(ghn_class()->get_order_weight($order));?>
                                                <input type="text" readonly name="ghn_order_weight" id="ghn_order_weight" value="<?php echo $all_weight;?>"> gram
                                            </td>
                                        </tr>
                                        <tr>
                                            <td><?php _e('Kích thước (cm)', 'devvn-ghn')?></td>
                                            <td class="input_inline">
                                                <?php $all_width = ghn_class()->convert_dimension_to_cm(ghn_class()->get_order_weight($order, 'width'));?>
                                                <?php $all_height = ghn_class()->convert_dimension_to_cm(ghn_class()->get_order_weight($order, 'height'));?>
                                                <?php $all_length = ghn_class()->convert_dimension_to_cm(ghn_class()->get_order_weight($order, 'length'));?>
                                                <input type="text" readonly name="ghn_order_length" id="ghn_order_length" value="<?php echo $all_length;?>"> dài
                                                <input type="text" readonly name="ghn_order_width" id="ghn_order_width" value="<?php echo $all_width;?>"> rộng
                                                <input type="text" readonly name="ghn_order_height" id="ghn_order_height" value="<?php echo $all_height;?>"> cao
                                            </td>
                                        </tr>
                                        <tr>
                                            <td><?php _e('Ghi chú bắt buộc', 'devvn-ghn')?> <span class="required">*</span></td>
                                            <td>
                                                <select name="ghn_ghichu_required" id="ghn_ghichu_required">
                                                    <option value="CHOXEMHANGKHONGTHU" selected="selected"><?php _e('Cho xem hàng, không cho thử','devvn-ghn');?></option>
                                                    <option value="CHOTHUHANG"><?php _e('Cho thử hàng','devvn-ghn');?></option>
                                                    <option value="KHONGCHOXEMHANG"><?php _e('Không cho thử hàng','devvn-ghn');?></option>
                                                </select>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td><?php _e('Ghi chú', 'devvn-ghn')?></td>
                                            <td><textarea name="ghn_ghichu" id="ghn_ghichu"></textarea></td>
                                        </tr>
                                        </tbody>
                                    </table>
                                </div>
                                <div class="devvn_option_col">
                                    <strong>Gói cước</strong>
                                    <?php
                                    $rates = ghn_api()->order_findAvailableServices($order);
                                    ?>
                                    <div class="ghn_all_goicuoc">
                                    <?php
                                    if($rates && !empty($rates)) {
                                        foreach ($rates as $methob) {
                                            $ServiceID = isset($methob['ServiceID']) ? intval($methob['ServiceID']) : '';
                                            $ExpectedDeliveryTime = isset($methob['ExpectedDeliveryTime']) ? date('d/m/Y', strtotime($methob['ExpectedDeliveryTime'])) : '';
                                            $Name = isset($methob['Name']) ? esc_attr($methob['Name']) : '';
                                            $ServiceFee = isset($methob['ServiceFee']) ? $methob['ServiceFee'] : '';
                                            $Extras = isset($methob['Extras']) ? $methob['Extras'] : array();
                                            if($ServiceID) {
                                                ?>
                                                <div class="ghn_all_goicuoc_list" data-extras="<?php echo esc_attr(json_encode($Extras));?>">
                                                    <div class="ghn_all_goicuoc_col">
                                                        <label><input type="radio" name="ghn_services" data-fee="<?php echo $ServiceFee;?>" value="<?php echo $ServiceID; ?>" <?php checked($ServiceID, $method_id)?>> <?php echo $Name . ' - ' . wc_price($ServiceFee);?></label>
                                                    </div>
                                                    <div class="ghn_all_goicuoc_col"><?php _e('Dự kiến giao', 'devvn-ghn')?> <?php echo $ExpectedDeliveryTime; ?></div>
                                                </div>
                                                <?php
                                            }
                                        }
                                    }
                                    ?>
                                    </div>
                                    <div class="ghn_all_phuphi">
                                        <strong>Phụ phí</strong>
                                        <div class="ghn_all_phuphi_list"></div>
                                    </div>
                                    <div class="ghn_tienthuho">
                                        <?php _e('Tiền thu hộ (COD):','devvn-ghn');?>
                                        <input type="number" name="ghn_tienthuho" id="ghn_tienthuho" data-total="<?php echo $order->get_total();?>" data-subtotal="<?php echo ghn_class()->order_get_total($order);?>" value="<?php echo $order->get_total()?>"/> <?php echo get_woocommerce_currency_symbol();?>
                                        <label class="ghn_free_ship" style="display: none"><input type="checkbox" name="ghn_free_ship" id="ghn_free_ship" value="1"> <?php _e('Free ship cho khách','devvn-ghn');?></label>
                                    </div>
                                    <div class="ghn_nguoithanhtoan">
                                        <?php _e('Người thanh toán:','devvn-ghn');?>
                                        <label><input type="radio" name="ghn_PaymentTypeID" class="ghn_PaymentTypeID"  value="1" checked="checked"> <?php _e('Người gửi','devvn-ghn');?></label>
                                        <label><input type="radio" name="ghn_PaymentTypeID" class="ghn_PaymentTypeID" value="2"> <?php _e('Người nhận','devvn-ghn');?></label>
                                    </div>
                                    <div class="ghn_makhuyenmai">
                                        <?php _e('Mã khuyến mại:','devvn-ghn');?>
                                        <input type="text" name="ghn_CouponCode" id="ghn_CouponCode"  value="">
                                    </div>
                                    <div class="ghn_nguoithanhtoan">
                                        <?php _e('Gửi hàng tại điểm giao dịch:','devvn-ghn');?>
                                        <label><input type="radio" name="ghn_isPickAtStation" class="ghn_isPickAtStation"  value="1"> <?php _e('Có','devvn-ghn');?></label>
                                        <label><input type="radio" name="ghn_isPickAtStation" class="ghn_isPickAtStation" value="2" checked="checked"> <?php _e('Không','devvn-ghn');?></label>
                                    </div>
                                </div>
                            </div>
                        </td>
                    </tr>
                    </tbody>
                    <tfoot>
                    <tr>
                        <td colspan="2" class="text_alignright">
                            <div class="ghn_msg"></div>
                            <a href="#" class="button button-primary devvn_float_right devvn_ghn_creat_order"><?php _e('Tạo đơn hàng', 'devvn-ghn'); ?></a>
                            <a href="#" class="button close_popup devvn_float_right"><?php _e('Hủy tạo', 'devvn-ghn'); ?></a>
                            <span class="spinner"></span>
                        </td>
                    </tr>
                    </tfoot>
                </table>
            </div>
        </div>
        <?php
    }

    function ghn_update_order_html($order){
        $ghn_order_submited = get_post_meta($order->get_id() , '_ghn_order_submited', true );
        $ghn_order_fullinfor = get_post_meta($order->get_id() , '_ghn_order_fullinfor', true );
        $OrderID = isset($ghn_order_fullinfor['data']['OrderID']) ? $ghn_order_fullinfor['data']['OrderID'] : 0;
        $OrderCode = isset($ghn_order_fullinfor['data']['OrderCode']) ? $ghn_order_fullinfor['data']['OrderCode'] : '';
        if(!empty($ghn_order_submited)) {
            if($OrderID){
                $ghn_order_submited['ShippingOrderID'] = $OrderID;
            }
            if($OrderCode){
                $ghn_order_submited['OrderCode'] = $OrderCode;
            }
            $this->devvn_form_creat_order_html($ghn_order_submited, $order);
        }else{
            $this->devvn_woocommerce_admin_order_data_after_order_details($order);
        }
    }

    function devvn_form_creat_order_html($data = array(), $order){
        if(empty($data)) return false;
        $data = wp_parse_args($data, array(

            'ShippingOrderID'   =>  0,
            'OrderCode' =>  '',

            'PaymentTypeID' => 1,

            "ClientContactName" => "",
            "ClientContactPhone" => "",
            "ClientAddress" => "",
            "ClientHubID" => "",

            "FromDistrictID" => "",

            "ToDistrictID" => 0,
            "ToWardCode" => "",

            "Note" => "",

            "CustomerName" => "",
            "CustomerPhone" => "",
            "ShippingAddress" => "",

            "CoDAmount" => 0,
            "NoteCode" => "",

            "InsuranceFee" => 0,

            "ServiceID" => 0,

            "Content" => "",
            "CouponCode" => "",

            "Weight" => 0,
            "Length" => 0,
            "Width" => 0,
            "Height" => 0,

            "ShippingOrderCosts" => array(),
        ));
        $customer_infor = ghn_class()->get_customer_address_shipping($order);
        extract($customer_infor);

        $product_list = ghn_class()->get_product_args($order);
        ?>
        <div class="ghn_popup_style ghn_creat_popup devvn_options_style">
            <div class="devvn_option_box">
                <table class="devvn_hubs_table widefat" cellspacing="0">
                    <thead>
                    <tr>
                        <th colspan="2"><h2><?php _e('Đăng đơn hàng lên GHN', 'devvn-ghn'); ?></h2></th>
                    </tr>
                    </thead>
                    <tbody>
                    <tr>
                        <td><?php _e('Chọn cửa hàng/kho','devvn-ghn');?></td>
                        <td>
                            <select name="ghn_creatorder_hub" id="ghn_creatorder_hub">
                                <option class=""><?php _e('Chọn cửa hàng/kho','devvn-ghn');?></option>
                                <?php
                                $all_hubs = $this->getHubs();
                                if(!empty($all_hubs) && is_array($all_hubs)){
                                    foreach($all_hubs as $hub){
                                        $hubID = isset($hub['HubID']) ? $hub['HubID'] : '';
                                        $Address = isset($hub['Address']) ? $hub['Address'] : '';
                                        $ContactName = isset($hub['ContactName']) ? $hub['ContactName'] : '';
                                        ?>
                                        <option value="<?php echo $hubID;?>" <?php selected($hubID, $data['ClientHubID'])?>><?php echo '#'.$hubID . ' - '. $ContactName .' - ' . $Address;?></option>
                                        <?php
                                    }
                                }
                                ?>
                            </select><br>
                            <small><?php _e('Phần này là tự động. Trong trường hợp thay đổi chi nhanh có thể sẽ làm phí vận chuyển thay đổi.','devvn-ghn');?></small>
                        </td>
                    </tr>
                    <tr>
                        <td colspan="2">
                            <div class="devvn_option_2col ghn_order_customerinfor">
                                <div class="devvn_option_col">
                                    <strong><?php _e('Thông tin khách hàng','devvn-ghn')?></strong>
                                    <div class="ghn_customer_infor">
                                        <div class="ghn_customer_row">
                                            <div class="ghn_customer_col">
                                                <?php _e('Họ và tên', 'devvn-ghn');?>
                                            </div>
                                            <div class="ghn_customer_col">
                                                <?php echo $name;?>
                                            </div>
                                        </div>
                                        <div class="ghn_customer_row">
                                            <div class="ghn_customer_col">
                                                <?php _e('Số điện thoại', 'devvn-ghn');?>
                                            </div>
                                            <div class="ghn_customer_col">
                                                <?php echo $phone;?>
                                            </div>
                                        </div>
                                        <div class="ghn_customer_row">
                                            <div class="ghn_customer_col">
                                                <?php _e('Địa chỉ', 'devvn-ghn');?>
                                            </div>
                                            <div class="ghn_customer_col">
                                                <?php echo $address;?>
                                            </div>
                                        </div>
                                        <div class="ghn_customer_row">
                                            <div class="ghn_customer_col">
                                                <?php _e('Phường/Xã', 'devvn-ghn');?>
                                            </div>
                                            <div class="ghn_customer_col">
                                                <?php echo ghn_class()->get_name_ward($ward);?>
                                            </div>
                                        </div>
                                        <div class="ghn_customer_row">
                                            <div class="ghn_customer_col">
                                                <?php _e('Khu vực', 'devvn-ghn');?>
                                            </div>
                                            <div class="ghn_customer_col">
                                                <?php echo ghn_class()->get_name_city($province.'_'.$disrict);?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="devvn_option_col">
                                    <strong><?php _e('Thông tin sản phẩm','devvn-ghn');?></strong>
                                    <table class="prod_table">
                                        <thead>
                                        <tr>
                                            <th><?php _e('Tên sp','devvn-ghn');?></th>
                                            <th><?php _e('Weight','devvn-ghn');?></th>
                                            <th><?php _e('SL','devvn-ghn');?></th>
                                        </tr>
                                        </thead>
                                        <tbody>
                                        <?php
                                        $content_order = '';
                                        if($product_list && !is_wp_error($product_list) && !empty($product_list)):
                                            foreach($product_list as $product):
                                                $content_order .= $product['name'] .' x '. $product['quantity'] . ' | ';
                                                ?>
                                                <tr>
                                                    <td><?php echo $product['name']?></td>
                                                    <td><?php echo $product['weight']?></td>
                                                    <td><?php echo $product['quantity']?></td>
                                                </tr>
                                            <?php endforeach;?>
                                        <?php endif;?>
                                        </tbody>
                                    </table>
                                    <textarea name="ghn_contentOrder" id="ghn_contentOrder"><?php echo esc_textarea($content_order);?></textarea>
                                </div>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <td colspan="2">
                            <div class="devvn_option_2col ghn_order_customerinfor">
                                <div class="devvn_option_col">
                                    <strong><?php _e('Gói hàng', 'devvn-ghn');?></strong>
                                    <table class="goihang_table">
                                        <tbody>
                                        <tr>
                                            <td><?php _e('Giá trị gói hàng', 'devvn-ghn')?></td>
                                            <td>
                                                <input type="text" readonly name="ghn_InsuranceFee" id="ghn_InsuranceFee" value="<?php echo ghn_class()->order_get_total($order)?>"/> <?php echo get_woocommerce_currency_symbol();?>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td><?php _e('Khối lượng', 'devvn-ghn')?></td>
                                            <td>
                                                <?php $all_weight = ghn_class()->convert_weight_to_gram(ghn_class()->get_order_weight($order));?>
                                                <input type="text" readonly name="ghn_order_weight" id="ghn_order_weight" value="<?php echo $all_weight;?>"> gram
                                            </td>
                                        </tr>
                                        <tr>
                                            <td><?php _e('Kích thước (cm)', 'devvn-ghn')?></td>
                                            <td class="input_inline">
                                                <?php $all_width = ghn_class()->convert_dimension_to_cm(ghn_class()->get_order_weight($order, 'width'));?>
                                                <?php $all_height = ghn_class()->convert_dimension_to_cm(ghn_class()->get_order_weight($order, 'height'));?>
                                                <?php $all_length = ghn_class()->convert_dimension_to_cm(ghn_class()->get_order_weight($order, 'length'));?>
                                                <input type="text" readonly name="ghn_order_length" id="ghn_order_length" value="<?php echo $all_length;?>"> <?php _e('dài','devvn-ghn');?>
                                                <input type="text" readonly name="ghn_order_width" id="ghn_order_width" value="<?php echo $all_width;?>"> <?php _e('rộng','devvn-ghn');?>
                                                <input type="text" readonly name="ghn_order_height" id="ghn_order_height" value="<?php echo $all_height;?>"> <?php _e('cao','devvn-ghn');?>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td><?php _e('Ghi chú bắt buộc', 'devvn-ghn')?> <span class="required">*</span></td>
                                            <td>
                                                <select name="ghn_ghichu_required" id="ghn_ghichu_required">
                                                    <option value="CHOXEMHANGKHONGTHU" <?php selected('CHOXEMHANGKHONGTHU',$data['NoteCode']);?>><?php _e('Cho xem hàng, không cho thử','devvn-ghn');?></option>
                                                    <option value="CHOTHUHANG" <?php selected('CHOTHUHANG',$data['NoteCode']);?>><?php _e('Cho thử hàng','devvn-ghn');?></option>
                                                    <option value="KHONGCHOXEMHANG" <?php selected('KHONGCHOXEMHANG',$data['NoteCode']);?>><?php _e('Không cho thử hàng','devvn-ghn');?></option>
                                                </select>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td><?php _e('Ghi chú', 'devvn-ghn')?></td>
                                            <td><textarea name="ghn_ghichu" id="ghn_ghichu"><?php echo esc_textarea($data['Note'])?></textarea></td>
                                        </tr>
                                        </tbody>
                                    </table>
                                </div>
                                <div class="devvn_option_col">
                                    <strong><?php _e('Gói cước','devvn-ghn');?></strong>
                                    <?php
                                    $rates = ghn_api()->order_findAvailableServices($order);
                                    ?>
                                    <div class="ghn_all_goicuoc">
                                        <?php
                                        if($rates && !empty($rates)) {
                                            foreach ($rates as $methob) {
                                                $ServiceID = isset($methob['ServiceID']) ? intval($methob['ServiceID']) : '';
                                                $ExpectedDeliveryTime = isset($methob['ExpectedDeliveryTime']) ? date('d/m/Y', strtotime($methob['ExpectedDeliveryTime'])) : '';
                                                $Name = isset($methob['Name']) ? esc_attr($methob['Name']) : '';
                                                $ServiceFee = isset($methob['ServiceFee']) ? $methob['ServiceFee'] : '';
                                                $Extras = isset($methob['Extras']) ? $methob['Extras'] : array();
                                                if($ServiceID) {
                                                    ?>
                                                    <div class="ghn_all_goicuoc_list" data-extras="<?php echo esc_attr(json_encode($Extras));?>">
                                                        <div class="ghn_all_goicuoc_col">
                                                            <label><input type="radio" name="ghn_services" data-fee="<?php echo $ServiceFee;?>" value="<?php echo $ServiceID; ?>" <?php checked($ServiceID, $data['ServiceID'])?>> <?php echo $Name . ' - ' . wc_price($ServiceFee);?></label>
                                                        </div>
                                                        <div class="ghn_all_goicuoc_col"><?php _e('Dự kiến giao', 'devvn-ghn')?> <?php echo $ExpectedDeliveryTime; ?></div>
                                                    </div>
                                                    <?php
                                                }
                                            }
                                        }
                                        ?>
                                    </div>
                                    <div class="ghn_all_phuphi">
                                        <strong>Phụ phí</strong>
                                        <div class="ghn_all_phuphi_list"></div>
                                    </div>
                                    <div class="ghn_tienthuho">
                                        <?php _e('Tiền thu hộ (COD):','devvn-ghn');?>
                                        <input type="number" name="ghn_tienthuho" id="ghn_tienthuho" data-total="<?php echo $order->get_total();?>" data-subtotal="<?php echo ghn_class()->order_get_total($order);?>" data-codamount="<?php echo $data['CoDAmount']?>" value="<?php echo $data['CoDAmount']?>"/> <?php echo get_woocommerce_currency_symbol();?>
                                        <label class="ghn_free_ship" style="display: none"><input type="checkbox" name="ghn_free_ship" id="ghn_free_ship" value="1"> <?php _e('Free ship cho khách','devvn-ghn');?></label>
                                    </div>
                                    <div class="ghn_nguoithanhtoan">
                                        <?php _e('Người thanh toán:','devvn-ghn');?>
                                        <label><input type="radio" name="ghn_PaymentTypeID" class="ghn_PaymentTypeID"  value="1" <?php checked(1,$data['PaymentTypeID'])?>> <?php _e('Người gửi','devvn-ghn');?></label>
                                        <label><input type="radio" name="ghn_PaymentTypeID" class="ghn_PaymentTypeID" value="2" <?php checked(2,$data['PaymentTypeID'])?>> <?php _e('Người nhận','devvn-ghn');?></label>
                                    </div>
                                    <div class="ghn_makhuyenmai">
                                        <?php _e('Mã khuyến mại:','devvn-ghn');?>
                                        <input type="text" name="ghn_CouponCode" id="ghn_CouponCode"  value="<?php echo $data['CouponCode']?>">
                                    </div>
                                    <?php
                                    $isPickAtStation = 2;
                                    foreach ($data['ShippingOrderCosts'] as $item) {
                                        if($item['ServiceID'] == 53337){
                                            $isPickAtStation = 1;
                                            break;
                                        }
                                    }
                                    ?>
                                    <div class="ghn_nguoithanhtoan">
                                        <?php _e('Gửi hàng tại điểm giao dịch:','devvn-ghn');?>
                                        <label><input type="radio" name="ghn_isPickAtStation" class="ghn_isPickAtStation"  value="1" <?php checked(1, $isPickAtStation)?>> <?php _e('Có','devvn-ghn');?></label>
                                        <label><input type="radio" name="ghn_isPickAtStation" class="ghn_isPickAtStation" value="2" <?php checked(2, $isPickAtStation)?>> <?php _e('Không','devvn-ghn');?></label>
                                    </div>
                                </div>
                            </div>
                        </td>
                    </tr>
                    </tbody>
                    <tfoot>
                    <tr>
                        <td colspan="2" class="text_alignright">
                            <div class="ghn_msg"></div>
                            <a href="#" class="button button-primary devvn_float_right devvn_ghn_update_order"><?php _e('Cập nhật', 'devvn-ghn'); ?></a>
                            <a href="#" class="button close_popup devvn_float_right"><?php _e('Hủy chỉnh sửa', 'devvn-ghn'); ?></a>
                            <span class="spinner"></span>
                            <input type="hidden" name="ghn_ShippingOrderID" id="ghn_ShippingOrderID" value="<?php echo $data['ShippingOrderID']?>">
                            <input type="hidden" name="ghn_OrderCode" id="ghn_OrderCode" value="<?php echo $data['OrderCode']?>">
                        </td>
                    </tr>
                    </tfoot>
                </table>
            </div>
        </div>
        <?php
    }
     function ghn_save_meta_box($post_id, $post){
         $nonce_name   = isset( $_POST['ghn_action_nonce'] ) ? $_POST['ghn_action_nonce'] : '';
         $nonce_action = 'ghn_action_nonce_action';

         // Check if nonce is set.
         if ( ! isset( $nonce_name ) ) {
             return;
         }

         // Check if nonce is valid.
         if ( ! wp_verify_nonce( $nonce_name, $nonce_action ) ) {
             return;
         }

         // Check if user has permissions to save data.
         if ( ! current_user_can( 'edit_post', $post_id ) ) {
             return;
         }

         // Check if not an autosave.
         if ( wp_is_post_autosave( $post_id ) ) {
             return;
         }

         // Check if not a revision.
         if ( wp_is_post_revision( $post_id ) ) {
             return;
         }

     }

     function devvn_ghn_change_hub(){
         if ( !wp_verify_nonce( $_REQUEST['nonce'], "ghn_action_nonce_action")) {
             wp_send_json_error('Check nonce failed!');
         }
         $hubid = isset($_POST['hubid']) ? intval($_POST['hubid']) : '';
         $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : '';

         if(!$hubid || !$order_id) wp_send_json_error('Kiểm tra lại dữ liệu gửi vào');

         $order = wc_get_order($order_id);
         if($order && !is_wp_error($order)) {
             $rates = ghn_api()->order_findAvailableServices($order, $hubid);
             ob_start();
             if($rates && !empty($rates)) {
                 foreach ($rates as $methob) {
                     $ServiceID = isset($methob['ServiceID']) ? intval($methob['ServiceID']) : '';
                     $ExpectedDeliveryTime = isset($methob['ExpectedDeliveryTime']) ? date('d/m/Y', strtotime($methob['ExpectedDeliveryTime'])) : '';
                     $Name = isset($methob['Name']) ? esc_attr($methob['Name']) : '';
                     $ServiceFee = isset($methob['ServiceFee']) ? $methob['ServiceFee'] : '';
                     $Extras = isset($methob['Extras']) ? $methob['Extras'] : array();
                     if($ServiceID) {
                         ?>
                         <div class="ghn_all_goicuoc_list" data-extras="<?php echo esc_attr(json_encode($Extras));?>">
                             <div class="ghn_all_goicuoc_col">
                                 <label><input type="radio" name="ghn_services" data-fee="<?php echo $ServiceFee;?>" value="<?php echo $ServiceID; ?>"> <?php echo $Name . ' - ' . wc_price($ServiceFee);?></label>
                             </div>
                             <div class="ghn_all_goicuoc_col"><?php _e('Dự kiến giao', 'devvn-ghn')?> <?php echo $ExpectedDeliveryTime; ?></div>
                         </div>
                         <?php
                     }
                 }
             }
             wp_send_json_success(ob_get_clean());
         }
         wp_send_json_error('Có lỗi xảy ra');
     }

     function devvn_ghn_creat_order(){
         if ( !wp_verify_nonce( $_REQUEST['nonce'], "ghn_action_nonce_action")) {
             wp_send_json_error('Check nonce failed!');
         }
         $hubID = isset($_POST['hubID']) ? intval($_POST['hubID']) : 0;
         $order_ID = isset($_POST['post_ID']) ? intval($_POST['post_ID']) : 0;
         $order = wc_get_order($order_ID);
         if(!$order_ID || is_wp_error($order)){
             wp_send_json_error('Không tìm thấy Order');
         }
         if(!$hubID){
             wp_send_json_error('Hãy chọn 1 cửa hàng/kho');
         }
         $customer_infor = ghn_class()->get_customer_address_shipping($order);

         $extras_all = array();
         $extras = (isset($_POST['ghn_extras']) && !empty($_POST['ghn_extras'])) ? $_POST['ghn_extras'] : array();
         if(!empty($extras)){
             foreach($extras as $ext){
                 $extras_all[] = array(
                     "ServiceID" =>  intval($ext)
                 );
             }
         }

         $data = array(
             'PaymentTypeID' => isset($_POST['PaymentTypeID']) ? intval($_POST['PaymentTypeID']) : 1,

             "ClientContactName" => $this->get_hub_by_id($hubID, 'ContactName'),
             "ClientContactPhone" => $this->get_hub_by_id($hubID, 'ContactPhone'),
             "ClientAddress" => $this->get_hub_by_id($hubID, 'Address'),
             "ClientHubID" => $hubID,

             "FromDistrictID" => $this->get_hub_by_id($hubID, 'DistrictID'),

             "ToDistrictID" => isset($customer_infor['disrict']) ? intval($customer_infor['disrict']) : 0,
             "ToWardCode" => isset($customer_infor['ward']) ? sanitize_text_field($customer_infor['ward']) : "",

             "Note" => isset($_POST['noteOrder']) ? sanitize_textarea_field($_POST['noteOrder']) : "",

             "CustomerName" => isset($customer_infor['name']) ? sanitize_text_field($customer_infor['name']) : "",
             "CustomerPhone" => isset($customer_infor['phone']) ? sanitize_text_field($customer_infor['phone']) : "",
             "ShippingAddress" => isset($customer_infor['address']) ? sanitize_text_field($customer_infor['address']) : "",

             "CoDAmount" => isset($_POST['CoDAmount']) ? (float) $_POST['CoDAmount'] : 0,
             "NoteCode" => isset($_POST['noteCode']) ? sanitize_text_field($_POST['noteCode']) : '',

             "InsuranceFee" => isset($_POST['InsuranceFee']) ? (float) $_POST['InsuranceFee'] : 0,

             "ServiceID" => isset($_POST['ghn_services']) ? (int) $_POST['ghn_services'] : 0,

             "Content" => isset($_POST['ghn_contentOrder']) ? sanitize_textarea_field($_POST['ghn_contentOrder']) : "",
             "CouponCode" => isset($_POST['ghn_CouponCode']) ? sanitize_text_field($_POST['ghn_CouponCode']) : "",

             "Weight" => (isset($_POST['ghn_order_weight']) && $_POST['ghn_order_weight']) ? (float) $_POST['ghn_order_weight'] : 0,
             "Length" => isset($_POST['ghn_order_length']) && $_POST['ghn_order_length'] ? (float) $_POST['ghn_order_length'] : 1,
             "Width" => isset($_POST['ghn_order_width']) && $_POST['ghn_order_width'] ? (float) $_POST['ghn_order_width'] : 1,
             "Height" => isset($_POST['ghn_order_height']) && $_POST['ghn_order_height'] ? (float) $_POST['ghn_order_height'] : 1,

             "ShippingOrderCosts" => $extras_all,

         );

         $result = $this->createOrder($data);
         $data_args = isset($result['data']) ? $result['data'] : array();
         $msg = isset($result['msg']) ? $result['msg'] : '';
         if(isset($result['code']) && $result['code'] == 0){
             $data_msg = $msg . '\n';
             foreach($data_args as $k=>$v){
                 $data_msg .= $v . '\n';
             }
             wp_send_json_error($data_msg);
         }elseif(isset($result['code']) && $result['code'] == 1){

             $ghn_ordercode = isset($result['data']['OrderCode']) ? $result['data']['OrderCode'] : '';

             if($ghn_ordercode){
                 update_post_meta( $order_ID , '_ghn_order_fullinfor', $result );
                 update_post_meta( $order_ID , '_ghn_ordercode', $ghn_ordercode );
                 update_post_meta( $order_ID , '_ghn_order_submited', $data );
                 wp_send_json_success(__('Đăng đơn thành công! Đang tải lại...'));
             }
         }
         wp_send_json_error(__('Lỗi không xác định', 'devvn-ghn'));
         die();
     }

     function devvn_ghn_update_order(){
         if ( !wp_verify_nonce( $_REQUEST['nonce'], "ghn_action_nonce_action")) {
             wp_send_json_error('Check nonce failed!');
         }
         $hubID = isset($_POST['hubID']) ? intval($_POST['hubID']) : 0;
         $ghn_ShippingOrderID = isset($_POST['ghn_ShippingOrderID']) ? intval($_POST['ghn_ShippingOrderID']) : 0;
         $ghn_OrderCode = isset($_POST['ghn_OrderCode']) ? sanitize_text_field($_POST['ghn_OrderCode']) : '';
         $order_ID = isset($_POST['post_ID']) ? intval($_POST['post_ID']) : 0;
         $order = wc_get_order($order_ID);
         if(!$order_ID || is_wp_error($order)){
             wp_send_json_error('Không tìm thấy Order');
         }
         if(!$hubID){
             wp_send_json_error('Hãy chọn 1 cửa hàng/kho');
         }
         $customer_infor = ghn_class()->get_customer_address_shipping($order);

         $extras_all = array();
         $extras = (isset($_POST['ghn_extras']) && !empty($_POST['ghn_extras'])) ? $_POST['ghn_extras'] : array();
         if(!empty($extras)){
             foreach($extras as $ext){
                 $extras_all[] = array(
                     "ServiceID" =>  intval($ext)
                 );
             }
         }

         $data = array(
             'ShippingOrderID'  =>  $ghn_ShippingOrderID,
             'OrderCode'  =>  $ghn_OrderCode,

             'PaymentTypeID' => isset($_POST['PaymentTypeID']) ? intval($_POST['PaymentTypeID']) : 1,

             "ClientContactName" => $this->get_hub_by_id($hubID, 'ContactName'),
             "ClientContactPhone" => $this->get_hub_by_id($hubID, 'ContactPhone'),
             "ClientAddress" => $this->get_hub_by_id($hubID, 'Address'),
             "ClientHubID" => $hubID,

             "FromDistrictID" => $this->get_hub_by_id($hubID, 'DistrictID'),

             "ToDistrictID" => isset($customer_infor['disrict']) ? intval($customer_infor['disrict']) : 0,
             "ToWardCode" => isset($customer_infor['ward']) ? sanitize_text_field($customer_infor['ward']) : "",

             "Note" => isset($_POST['noteOrder']) ? sanitize_textarea_field($_POST['noteOrder']) : "",

             "CustomerName" => isset($customer_infor['name']) ? sanitize_text_field($customer_infor['name']) : "",
             "CustomerPhone" => isset($customer_infor['phone']) ? sanitize_text_field($customer_infor['phone']) : "",
             "ShippingAddress" => isset($customer_infor['address']) ? sanitize_text_field($customer_infor['address']) : "",

             "CoDAmount" => isset($_POST['CoDAmount']) ? (float) $_POST['CoDAmount'] : 0,
             "NoteCode" => isset($_POST['noteCode']) ? sanitize_text_field($_POST['noteCode']) : '',

             "InsuranceFee" => isset($_POST['InsuranceFee']) ? (float) $_POST['InsuranceFee'] : 0,

             "ServiceID" => isset($_POST['ghn_services']) ? (int) $_POST['ghn_services'] : 0,

             "Content" => isset($_POST['ghn_contentOrder']) ? sanitize_textarea_field($_POST['ghn_contentOrder']) : "",
             "CouponCode" => isset($_POST['ghn_CouponCode']) ? sanitize_text_field($_POST['ghn_CouponCode']) : "",

             "Weight" => (isset($_POST['ghn_order_weight']) && $_POST['ghn_order_weight']) ? (float) $_POST['ghn_order_weight'] : 0,
             "Length" => isset($_POST['ghn_order_length']) && $_POST['ghn_order_length'] ? (float) $_POST['ghn_order_length'] : 1,
             "Width" => isset($_POST['ghn_order_width']) && $_POST['ghn_order_width'] ? (float) $_POST['ghn_order_width'] : 1,
             "Height" => isset($_POST['ghn_order_height']) && $_POST['ghn_order_height'] ? (float) $_POST['ghn_order_height'] : 1,

             "ShippingOrderCosts" => $extras_all,

         );

         $result = $this->updateOrder($data);
         $data_args = isset($result['data']) ? $result['data'] : array();
         $msg = isset($result['msg']) ? $result['msg'] : '';
         if(isset($result['code']) && $result['code'] == 0){
             $data_msg = $msg . '\n';
             foreach($data_args as $k=>$v){
                 $data_msg .= $v . '\n';
             }
             wp_send_json_error($data_msg);
         }elseif(isset($result['code']) && $result['code'] == 1){
             update_post_meta( $order_ID , '_ghn_order_fullinfor', $result );
             update_post_meta( $order_ID , '_ghn_order_submited', $data );
             wp_send_json_success(__('Cập nhật thành công! Đang tải lại...'));
         }
         wp_send_json_error(__('Lỗi không xác định', 'devvn-ghn'));
         die();
     }
    function devvn_ghn_cancel_order(){
        if ( !wp_verify_nonce( $_REQUEST['nonce'], "ghn_action_nonce_action")) {
            wp_send_json_error('Check nonce failed!');
        }
        $ordercode = isset($_POST['ordercode']) ? sanitize_text_field($_POST['ordercode']) : '';
        $post_ID = isset($_POST['post_ID']) ? sanitize_text_field($_POST['post_ID']) : '';
        if($ordercode){
            $args = array(
                'data'  =>  array(
                    "token"	=> $this->token,
                    "OrderCode" => $ordercode
                ),
                'action'    =>  'CancelOrder'
            );

            $result = $this->get_cURL($args);
            $msg = isset($result['msg']) ? sanitize_text_field($result['msg']) : '';
            $data_args = isset($result['data']) ? $result['data'] : array();
            if(isset($result['code']) && $result['code'] == 1){
                delete_post_meta($post_ID,'_ghn_ordercode');
                delete_post_meta($post_ID,'_ghn_order_submited');
                delete_post_meta($post_ID,'_ghn_order_fullinfor');
                wp_send_json_success(__('Đã hủy đơn hàng thành công', 'devvn-ghn'));
            }else{
                $data_msg = $msg . '\n';
                foreach($data_args as $k=>$v){
                    $data_msg .= $v . '\n';
                }
                wp_send_json_error($data_msg);
            }
        }
        die();
    }
    function get_status_text($CurrentStatus){
        $text = __('Không xác định','devvn-ghn');
        switch ($CurrentStatus){
            case 'ReadyToPick':
                $text = __('Đơn hàng mới tạo','devvn-ghn');
                break;
            case 'Picking':
                $text = __('Đang đi lấy hàng','devvn-ghn');
                break;
            case 'Storing':
                $text = __('Đã nhận được và chuyển hàng hóa về kho lưu trữ','devvn-ghn');
                break;
            case 'Delivering':
                $text = __('Đang đi giao hàng','devvn-ghn');
                break;
            case 'Delivered':
                $text = __('Đã giao hàng thành công','devvn-ghn');
                break;
            case 'Return':
                $text = __('Trả lại sau 3 lần giao thất bại','devvn-ghn');
                break;
            case 'Returned':
                $text = __('Đã trả lại','devvn-ghn');
                break;
            case 'WaitingToFinish':
                $text = __('Đang được xử lý để hoàn thành','devvn-ghn');
                break;
            case 'Finish':
                $text = __('Đơn hàng đã hoàn thành','devvn-ghn');
                break;
            case 'Cancel':
                $text = __('Đã hủy','devvn-ghn');
                break;
            case 'LostOrder':
                $text = __('Thất lạc hàng','devvn-ghn');
                break;
        }
        return $text;
    }
    function devvn_ghn_tracking_order(){
        if ( !wp_verify_nonce( $_REQUEST['nonce'], "ghn_action_nonce_action")) {
            wp_send_json_error('Check nonce failed!');
        }
        $ordercode = isset($_POST['ordercode']) ? sanitize_text_field($_POST['ordercode']) : '';
        $post_ID = isset($_POST['post_ID']) ? sanitize_text_field($_POST['post_ID']) : '';
        if($ordercode){
            $args = array(
                'data'  =>  array(
                    "token"	=> $this->token,
                    "OrderCode" => $ordercode
                ),
                'action'    =>  'OrderInfo'
            );

            $result = $this->get_cURL($args);
            $msg = isset($result['msg']) ? sanitize_text_field($result['msg']) : '';
            $data_args = isset($result['data']) ? $result['data'] : array();
            if(isset($result['code']) && $result['code'] == 1){
                $CurrentStatus = isset($result['data']['CurrentStatus']) ? $result['data']['CurrentStatus'] : '';
                $name = $this->get_status_text($CurrentStatus);
                update_post_meta($post_ID, '_ghn_order_status', $CurrentStatus);
                wp_send_json_success(sprintf(__('Trạng thái đơn hàng: %s', 'devvn-ghn'), $name));
            }else{
                $data_msg = $msg . '\n';
                foreach($data_args as $k=>$v){
                    $data_msg .= $v . '\n';
                }
                wp_send_json_error($data_msg);
            }
        }
        die();
    }

}

function ghn_api(){
    return DevVN_GHN_API::instance();
}
ghn_api();
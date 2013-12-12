<?php
/*
Copyright (c) 2012 John Atkinson (jga)

Permission is hereby granted, free of charge, to any person obtaining a copy of this 
software and associated documentation files (the "Software"), to deal in the Software 
without restriction, including without limitation the rights to use, copy, modify, 
merge, publish, distribute, sublicense, and/or sell copies of the Software, and to 
permit persons to whom the Software is furnished to do so, subject to the following 
conditions:

The above copyright notice and this permission notice shall be included in all copies 
or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, 
INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR 
PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE 
FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR 
OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER 
DEALINGS IN THE SOFTWARE.
*/

class ControllerPaymentLitecoin extends Controller {

    private $payment_module_name  = 'litecoin';
	protected function index() {
        $this->language->load('payment/'.$this->payment_module_name);
    	$this->data['button_litecoin_confirm'] = $this->language->get('button_litecoin_confirm');
		$this->data['error_msg'] = $this->language->get('error_msg');
				
		$this->checkUpdate();
	
        $this->load->model('checkout/order');
		$order_id = $this->session->data['order_id'];
		$order = $this->model_checkout_order->getOrder($order_id);

		$current_default_currency =  $this->config->get('config_currency');
		
		$this->data['litecoin_total'] = round($this->currency->convert($order['total'], $current_default_currency, "LTC"),4);
		
		require_once('jsonRPCClient.php');
		
		$litecoin = new jsonRPCClient('http://'.$this->config->get('litecoin_rpc_username').':'.$this->config->get('litecoin_rpc_password').'@'.$this->config->get('litecoin_rpc_address').':'.$this->config->get('litecoin_rpc_port').'/');
		
		$this->data['error'] = false;
		try {
			$litecoin_info = $litecoin->getinfo();
		} catch (Exception $e) {
			$this->data['error'] = true;
			if (file_exists(DIR_TEMPLATE . $this->config->get('config_template') . '/template/payment/litecoin.tpl')) {
				$this->template = $this->config->get('config_template') . '/template/payment/litecoin.tpl';
			} else {
				$this->template = 'default/template/payment/litecoin.tpl';
			}	
			$this->render();
			return;
		}
		$this->data['error'] = false;
		
		$this->data['litecoin_send_address'] = $litecoin->getaccountaddress($this->config->get('litecoin_prefix').'_'.$order_id);
		$this->db->query("UPDATE `" . DB_PREFIX . "order` SET bitcoin_address = '" . $this->data['litecoin_send_address'] . "', date_modified = NOW() WHERE order_id = '" . (int)$order_id . "'");

		if (file_exists(DIR_TEMPLATE . $this->config->get('config_template') . '/template/payment/litecoin.tpl')) {
			$this->template = $this->config->get('config_template') . '/template/payment/litecoin.tpl';
		} else {
			$this->template = 'default/template/payment/litecoin.tpl';
		}	
		
		$this->render();
	}
	
	
	public function confirm_sent() {
        $this->load->model('checkout/order');
		$order_id = $this->session->data['order_id'];
        $order = $this->model_checkout_order->getOrder($order_id);
		$current_default_currency = $this->config->get('config_currency');		
		$litecoin_total = round($this->currency->convert($order['total'], $current_default_currency, "LTC"),4);
		require_once('jsonRPCClient.php');
		$litecoin = new jsonRPCClient('http://'.$this->config->get('litecoin_rpc_username').':'.$this->config->get('litecoin_rpc_password').'@'.$this->config->get('litecoin_rpc_address').':'.$this->config->get('litecoin_rpc_port').'/');
	
		try {
			$litecoin_info = $litecoin->getinfo();
		} catch (Exception $e) {
			$this->data['error'] = true;
		}

		$received_amount = $litecoin->getreceivedbyaccount($this->config->get('litecoin_prefix').'_'.$order_id,0);
		if(round((float)$received_amount,4) >= round((float)$litecoin_total,4)) {
			$order = $this->model_checkout_order->getOrder($order_id);
			$this->model_checkout_order->confirm($order_id, $this->config->get('litecoin_order_status_id'));
			echo true;
		}
		else {
			echo false;
		}
	}
	
	public function checkUpdate() {
		if (extension_loaded('curl')) {
			$data = array();
			$query = $this->db->query("SELECT * FROM " . DB_PREFIX . "currency WHERE code = 'LTC'");
						
			if(!$query->row) {
				$this->db->query("INSERT INTO " . DB_PREFIX . "currency (title, code, symbol_right, decimal_place, status) VALUES ('litecoin', 'LTC', ' LTC', '4', ".$this->config->get('litecoin_show_ltc').")");
				$query = $this->db->query("SELECT * FROM " . DB_PREFIX . "currency WHERE code = 'LTC'");
			}
			
			$format = '%Y-%m-%d %H:%M:%S';
			$last_string = $query->row['date_modified'];
			$current_string = strftime($format);
			$last_time = strptime($last_string,$format);
			$current_time = strptime($current_string,$format);
		
			$num_seconds = 60; //every [this many] seconds, the update should run.
			
			if($last_time['tm_year'] != $current_time['tm_year']) {
				$this->runUpdate();
			}
			else if($last_time['tm_yday'] != $current_time['tm_yday']) {
				$this->runUpdate();
			}
			else if($last_time['tm_hour'] != $current_time['tm_hour']) {
				$this->runUpdate();
			}
			else if(($last_time['tm_min']*60)+$last_time['tm_sec'] + $num_seconds < ($current_time['tm_min'] * 60) + $current_time['tm_sec']) {
				$this->runUpdate();
			}
		}
	}
	
	public function runUpdate() {
		$path = "/2/ltc_usd/ticker";
		$req = array();
		
		// API settings
		$key = '';
		$secret = '';
	 
		// generate a nonce as microtime, with as-string handling to avoid problems with 32bits systems
		$mt = explode(' ', microtime());
		$req['nonce'] = $mt[1].substr($mt[0], 2, 6);
	 
		// generate the POST data string
		$post_data = http_build_query($req, '', '&');
	 
		// generate the extra headers
		$headers = array(
			'Rest-Key: '.$key,
			'Rest-Sign: '.base64_encode(hash_hmac('sha512', $post_data, base64_decode($secret), true)),
		);
	 
		// our curl handle (initialize if required)
		static $ch = null;
		if (is_null($ch)) {
			$ch = curl_init();
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/4.0 (compatible; MtGox PHP client; '.php_uname('s').'; PHP/'.phpversion().')');
		}
		curl_setopt($ch, CURLOPT_URL, 'https://btc-e.com/api'.$path);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
	 
		// run the query
		$res = curl_exec($ch);
		if ($res === false) throw new Exception('Could not get reply: '.curl_error($ch));
		$dec = json_decode($res, true);
		if (!$dec) throw new Exception('Invalid data received, please make sure connection is working and requested API exists');
		$ltcdata = $dec;
		
		$currency = "LTC";
		$value = $ltcdata['ticker']['avg'];
		
		if ((float)$value) {
			$value = 1/$value;
			$this->db->query("UPDATE " . DB_PREFIX . "currency SET value = '" . (float)$value . "', date_modified = '" .  $this->db->escape(date('Y-m-d H:i:s')) . "' WHERE code = '" . $this->db->escape($currency) . "'");
		}
		
		$this->db->query("UPDATE " . DB_PREFIX . "currency SET value = '1.00000', date_modified = '" .  $this->db->escape(date('Y-m-d H:i:s')) . "' WHERE code = '" . $this->db->escape($this->config->get('config_currency')) . "'");
		$this->cache->delete('currency');
	}
}
?>

<?php

class ControllerExtensionPaymentCoinToPayFiat extends Controller
{
    public function index()
    {
        $data['button_confirm'] = $this->language->get('button_confirm');

        $this->load->model('checkout/order');

        $order_info = "Session empty, please redo your order to proceed.";

        if (isset($this->session->data['order_id'])) {
            $order_info = $this->model_checkout_order->getOrder($this->session->data['order_id']);
        } else {
            echo $order_info;
            return;
        }
        try {

            if (($this->request->server['REQUEST_METHOD'] == 'POST')) {
                if (empty($this->config->get('payment_cointopay_fiat_merchantID')) || empty($this->config->get('payment_cointopay_fiat_securitycode'))) {
                    echo 'CredentialsMissing';
                    exit();
                }
                $formData = $this->request->post;
                $currencyOutput = $this->getInputCurrencyList();
                if (null !== $this->config->get('payment_cointopay_fiat_merchantID')) {
                    if (!in_array($this->config->get('config_currency'), $currencyOutput['currency'])) {
                        echo 'Your Store currency ' . $this->config->get('config_currency') . ' not supported. Please contact <a href="mailto:support@cointopay.com">support@cointopay.com</a> to resolve this issue.';
                        exit();
                    }
                }

                $url = trim($this->c2pCreateInvoice($this->request->post));
                if (is_string(json_decode($url))) {
                    echo json_decode($url);
                    exit();
                }
                $url_components = parse_url(json_encode($url));
                if (isset($url_components['query'])) {
                    parse_str($url_components['query'], $params);
                    if ($params['MerchantID'] == 'null') {
                        echo "Your MerchantID did not result in a correct transaction order, please update your plugin MerchantID";
                        exit();
                    }
                }
                $php_arr = json_decode($url);

                if (!isset($php_arr->TransactionID) || !isset($php_arr->QRCodeURL)) {
                    echo "Transaction not completed, please check your cointopay settings.";
                    exit();
                }

                $data1 = array();

                $this->load->language('extension/payment/cointopay_fiat_invoice');

                if ($php_arr->error == '' || empty($php_arr->error)) {
                    $this->model_checkout_order->addOrderHistory($php_arr->CustomerReferenceNr, $this->config->get('payment_cointopay_fiat_order_status_id'));

                    //print_r($php_arr);

                    $data1['TransactionID'] = $php_arr->TransactionID;
                    $data1['AltCoinID'] = $php_arr->AltCoinID;
                    $data1['coinAddress'] = $php_arr->coinAddress;
                    $data1['Amount'] = $php_arr->Amount;
                    $data1['OriginalAmount'] = $php_arr->OriginalAmount;
                    $data1['inputCurrency'] = $php_arr->inputCurrency;
                    $data1['CoinName'] = $php_arr->CoinName;
                    $data1['QRCodeURL'] = $php_arr->QRCodeURL;
                    $data1['PaymentDetail'] = $php_arr->PaymentDetail;
                    $data1['RedirectURL'] = $php_arr->RedirectURL;
                    $data1['ExpiryTime'] = $php_arr->ExpiryTime;
                    $data1['CalExpiryTime'] = date("m/d/Y h:i:s T", strtotime($php_arr->ExpiryTime));
                    $data1['OrderID'] = $this->session->data['order_id'];
                    $data1['CustomerReferenceNr'] = $php_arr->CustomerReferenceNr;
                    $data1['status'] = $php_arr->Status;
                    $data1['text_title'] = $this->language->get('text_title');
                    $data1['text_transaction_id'] = $this->language->get('text_transaction_id');
                    $data1['text_address'] = $this->language->get('text_address');
                    $data1['text_amount'] = $this->language->get('text_amount');
                    $data1['text_coinname'] = $this->language->get('text_coinname');
                    $data1['text_checkout_number'] = $this->language->get('text_checkout_number');
                    $data1['text_expiry'] = $this->language->get('text_expiry');
                    $data1['text_pay_with_other'] = $this->language->get('text_pay_with_other');
                    $data1['text_fiat_payment_detail'] = $this->language->get('text_fiat_payment_detail');
                    $data1['text_clickhere'] = $this->language->get('text_clickhere');
                } else {
                    $data1['error'] = $php_arr->error;
                }
                if (isset($this->session->data['order_id'])) {
                    $query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "order` WHERE order_id = '" . (int)$this->session->data['order_id'] . "' AND order_status_id > 0");

                    if ($query->num_rows) {
                        $this->cart->clear();

                        unset($this->session->data['shipping_method']);
                        unset($this->session->data['shipping_methods']);
                        unset($this->session->data['payment_method']);
                        unset($this->session->data['payment_methods']);
                        unset($this->session->data['guest']);
                        unset($this->session->data['comment']);
                        unset($this->session->data['order_id']);
                        unset($this->session->data['coupon']);
                        unset($this->session->data['reward']);
                        unset($this->session->data['voucher']);
                        unset($this->session->data['vouchers']);
                    }
                }
                $data1['footer'] = $this->load->controller('common/footer');
                $data1['header'] = $this->load->controller('common/header');
                if (file_exists(DIR_TEMPLATE . $this->config->get('config_template') . '/template/extension/payment/cointopay_fiat_invoice')) {
                    $this->response->setOutput($this->load->view($this->config->get('config_template') . '/template/extension/payment/cointopay_fiat_invoice', $data1));
                } else {
                    $this->response->setOutput($this->load->view('extension/payment/cointopay_fiat_invoice', $data1));
                }
            } else {
                $this->load->language('extension/payment/cointopay_fiat');

                $data['action'] = $this->url->link('extension/payment/cointopay_fiat', '', true);

                $data['price'] = $this->currency->format($order_info['total'], $order_info['currency_code'], $order_info['currency_value'], false);
                $data['key'] = $this->config->get('payment_cointopay_fiat_api_key');
                $data['AltCoinID'] = $this->config->get('payment_cointopay_fiat_crypto_coin');
                $data['crypto_coins'] = $this->getMerchantCoins($this->config->get('payment_cointopay_fiat_merchantID'));
                $data['OrderID'] = $this->session->data['order_id'];
                $data['currency'] = $order_info['currency_code'];

                $data['text_crypto_coin_label'] = $this->language->get('text_crypto_coin_label');

                if (file_exists(DIR_TEMPLATE . $this->config->get('config_template') . '/template/extension/payment/cointopay_fiat')) {
                    return $this->load->view($this->config->get('config_template') . '/template/extension/payment/cointopay_fiat', $data);
                } else {
                    return $this->load->view('extension/payment/cointopay_fiat', $data);
                }
            }

        } catch (Exception $e) {
            echo 'Caught exception: ', $e->getMessage(), "\n";
            return;
        }
    }

    function getInputCurrencyList()
    {
        $merchantId = $this->config->get('payment_cointopay_fiat_merchantID');
        $url = 'https://cointopay.com/v2REAPI?MerchantID=' . $merchantId . '&Call=inputCurrencyList&output=json&APIKey=_';
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_URL, $url);
        $output = curl_exec($ch);

        curl_close($ch);

        $php_arr = json_decode($output);
        $new_php_arr = array();

        if (!empty($php_arr)) {
            foreach ($php_arr as $c) {
                if (property_exists($c, 'ShortName')) {
                    $new_php_arr['currency'][] = $c->ShortName;
                }

            }
        }

        return $new_php_arr;
    }

    function c2pCreateInvoice($data)
    {
        $merchantid = $this->config->get('payment_cointopay_fiat_merchantID');
        $payment_cointopay_securitycode = $this->config->get('payment_cointopay_fiat_securitycode');
        $response = $this->c2pCurl('SecurityCode=' . $payment_cointopay_securitycode . '&MerchantID=' . $merchantid . '&Amount=' . $data['price'] . '&AltCoinID=' . $data['AltCoinID'] . '&inputCurrency=' . $data['currency'] . '&output=json&CustomerReferenceNr=' . $data['OrderID'] . '&returnurl=' . $this->url->link('extension/payment/cointopay_fiat/callback') . '&transactionconfirmurl=' . $this->url->link('extension/payment/cointopay_fiat/callback') . '&transactionfailurl=' . $this->url->link('extension/payment/cointopay_fiat/callback'), $data['key']);
        return $response;
    }

    public function c2pCurl($data, $apiKey, $post = false)
    {
        //$curl = curl_init($url);
        $length = 0;
        if ($post) {
            $formData = $post;
            $formData['transactionconfirmurl'] = $this->url->link('extension/payment/cointopay_fiat/callback');
            $formData['transactionfailurl'] = $this->url->link('extension/payment/cointopay_fiat/callback');
            curl_setopt($curl, CURLOPT_POSTFIELDS, $formData);
            $length = strlen($post);
        }


        $params = array(
            "authentication:1",
            'cache-control: no-cache',
        );

        $ch = curl_init();
        curl_setopt_array($ch, array(
                //CURLOPT_URL => 'https://app.cointopay.com/REAPI',
                CURLOPT_URL => 'https://app.cointopay.com/MerchantAPI?Checkout=true',
                //CURLOPT_USERPWD => $this->apikey,
                CURLOPT_POSTFIELDS => $data,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_HTTPHEADER => $params,
                CURLOPT_USERAGENT => 1,
                CURLOPT_HTTPAUTH => CURLAUTH_BASIC
            )
        );
        $output = curl_exec($ch);

        //curl_close($ch);

        $php_arr = json_decode($output);

        if ($output == false) {
            $response = curl_error($ch);
        } else {
            $response = $output;//json_decode($responseString, true);
        }
        curl_close($ch);
        return $response;
    }

    function getMerchantCoins($merchantId)
    {
        $url = 'https://app.cointopay.com/CloneMasterTransaction?MerchantID=' . $merchantId . '&output=json';
        $ch = curl_init($url);

        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_URL, $url);
        $output = curl_exec($ch);
        curl_close($ch);

        $php_arr = json_decode($output);
        $new_php_arr = array();

        if (count($php_arr) > 0) {
            for ($i = 0; $i < count($php_arr) - 1; $i++) {
                if (($i % 2) == 0) {
                    $new_php_arr[$php_arr[$i + 1]] = $php_arr[$i];
                }
            }
        }
        return $new_php_arr;
    }

    public function callback()
    {
        $data = array();
        $this->load->language('extension/payment/cointopay_fiat_invoice');
        $this->load->model('checkout/order');
        if (isset($_REQUEST['status'])) {

            $data = [
                'mid' => $this->config->get('payment_cointopay_fiat_merchantID'),
                'TransactionID' => $_GET['TransactionID'],
                'ConfirmCode' => $_GET['ConfirmCode']
            ];
            $transactionData = $this->validateOrder($data);
            $order = $this->model_checkout_order->getOrder($_GET['ConfirmCode']);

            if (200 !== $transactionData['status_code']) {
                echo $transactionData['message'];
                exit;
            } else if (!$order || $order['total'] != $transactionData['OriginalAmount']) {
                echo "Fraud detected";
                exit;
            } else {
                if ($transactionData['data']['Security'] != $_GET['ConfirmCode']) {
                    echo "Data mismatch! ConfirmCode doesn\'t match";
                    exit;
                } elseif ($transactionData['data']['CustomerReferenceNr'] != $_GET['CustomerReferenceNr']) {
                    echo "Data mismatch! CustomerReferenceNr doesn\'t match";
                    exit;
                } elseif ($transactionData['data']['TransactionID'] != $_GET['TransactionID']) {
                    echo "Data mismatch! TransactionID doesn\'t match";
                    exit;
                } elseif ($transactionData['data']['AltCoinID'] != $_GET['AltCoinID']) {
                    echo "Data mismatch! AltCoinID doesn\'t match";
                    exit;
                } elseif ($transactionData['data']['MerchantID'] != $_GET['MerchantID']) {
                    echo "Data mismatch! MerchantID doesn\'t match";
                    exit;
                } elseif ($transactionData['data']['coinAddress'] != $_GET['CoinAddressUsed']) {
                    echo "Data mismatch! coinAddress doesn\'t match";
                    exit;
                } elseif ($transactionData['data']['SecurityCode'] != $_GET['SecurityCode']) {
                    echo "Data mismatch! SecurityCode doesn\'t match";
                    exit;
                } elseif ($transactionData['data']['inputCurrency'] != $_GET['inputCurrency']) {
                    echo "Data mismatch! inputCurrency doesn\'t match";
                    exit;
                } elseif ($transactionData['data']['Status'] != $_GET['status']) {
                    echo "Data mismatch! status doesn\'t match. Your order status is " . $transactionData['data']['Status'];
                    exit;
                } else {
                    if ($_REQUEST['status'] == 'paid') {

                        $this->model_checkout_order->addOrderHistory($_REQUEST['CustomerReferenceNr'], $this->config->get('payment_cointopay_fiat_callback_success_order_status_id', 'Successfully Paid'));
                        $data['text_success'] = $this->language->get('text_success');
                        $data['footer'] = $this->load->controller('common/footer');
                        $data['header'] = $this->load->controller('common/header');

                        if (file_exists(DIR_TEMPLATE . $this->config->get('config_template') . '/template/extension/payment/cointopay_fiat_success')) {
                            $this->response->setOutput($this->load->view($this->config->get('config_template') . '/template/extension/payment/cointopay_fiat_success', $data));
                        } else {
                            $this->response->setOutput($this->load->view('extension/payment/cointopay_fiat_success', $data));
                        }
                    }
                    elseif ($_REQUEST['status'] == 'failed') {
                        $this->model_checkout_order->addOrderHistory($_REQUEST['CustomerReferenceNr'], $this->config->get('payment_cointopay_fiat_callback_failed_order_status_id', 'Transaction payment failed'));

                        $data['text_failed'] = $this->language->get('text_failed');
                        $data['footer'] = $this->load->controller('common/footer');
                        $data['header'] = $this->load->controller('common/header');

                        if (file_exists(DIR_TEMPLATE . $this->config->get('config_template') . '/template/extension/payment/cointopay_fiat_failed')) {
                            $this->response->setOutput($this->load->view($this->config->get('config_template') . '/template/extension/payment/cointopay_fiat_failed', $data));
                        } else {
                            $this->response->setOutput($this->load->view('extension/payment/cointopay_fiat_failed', $data));
                        }
                    } elseif ($_REQUEST['status'] == 'expired') {
                        $this->model_checkout_order->addOrderHistory($_REQUEST['CustomerReferenceNr'], $this->config->get('payment_cointopay_fiat_callback_failed_order_status_id', 'Transaction payment failed'));

                        $data['text_failed'] = $this->language->get('text_expired');
                        $data['footer'] = $this->load->controller('common/footer');
                        $data['header'] = $this->load->controller('common/header');

                        if (file_exists(DIR_TEMPLATE . $this->config->get('config_template') . '/template/extension/payment/cointopay_fiat_failed')) {
                            $this->response->setOutput($this->load->view($this->config->get('config_template') . '/template/extension/payment/cointopay_fiat_failed', $data));
                        } else {
                            $this->response->setOutput($this->load->view('extension/payment/cointopay_fiat_failed', $data));
                        }
                    }
                }
            }

        }
    }

    function validateOrder($data)
    {

        $params = array(
            "authentication:1",
            'cache-control: no-cache',
        );
        $ch = curl_init();
        curl_setopt_array($ch, array(
                CURLOPT_URL => 'https://app.cointopay.com/v2REAPI?',
                CURLOPT_POSTFIELDS => 'MerchantID=' . $data['mid'] . '&Call=Transactiondetail&APIKey=a&output=json&ConfirmCode=' . $data['ConfirmCode'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_HTTPHEADER => $params,
                CURLOPT_USERAGENT => 1,
                CURLOPT_HTTPAUTH => CURLAUTH_BASIC
            )
        );
        $response = curl_exec($ch);
        $results = json_decode($response, true);
        return $results;
    }

    public function getCoinsPaymentUrl()
    {
        $data = array();
        $this->load->language('extension/payment/cointopay_fiat_invoice');
        if (isset($_REQUEST['TransactionID'])) {
            $url = 'https://app.cointopay.com/CloneMasterTransaction?MerchantID=' . $this->config->get("payment_cointopay_fiat_merchantID") . '&TransactionID=' . $_REQUEST["TransactionID"] . '&output=json';
            $ch = curl_init($url);


            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_URL, $url);
            $output = curl_exec($ch);
            curl_close($ch);
            $decoded = json_decode($output);
            echo $output;
        }
    }
}


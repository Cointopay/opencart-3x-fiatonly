<?php

class ModelExtensionPaymentCoinToPayFiat extends Model
{
    public function getMethod($address, $total)
    {
        $this->load->language('extension/payment/cointopay_fiat');

        $method_data = array(
            'code' => 'cointopay_fiat',
            'title' => $this->config->get('payment_cointopay_fiat_display_name'),
            'terms' => '',
            'sort_order' => $this->config->get('payment_cointopay_fiat_sort_order')
        );

        return $method_data;
    }

    public function confirm($order_id, $order_status_id, $comment = '', $notify = false)
    {
        $this->load->model('checkout/order');
        $order_info = $this->model_checkout_order->getOrder($order_id);

        if ($order_info && $order_info['order_status_id']) {
            // Fraud Detection
            if ($this->config->get('config_fraud_detection')) {
                $this->load->model('checkout/fraud');

                $risk_score = $this->model_checkout_fraud->getFraudScore($order_info);

                if ($risk_score > $this->config->get('config_fraud_score')) {
                    $order_status_id = $this->config->get('config_fraud_status_id');
                }
            }

            // Ban IP
            $status = false;

            $this->load->model('account/customer');

            if ($order_info['customer_id']) {
                $results = $this->model_account_customer->getIps($order_info['customer_id']);

                foreach ($results as $result) {
                    if ($this->model_account_customer->isBanIp($result['ip'])) {
                        $status = true;

                        break;
                    }
                }
            } else {
                $status = $this->model_account_customer->isBanIp($order_info['ip']);
            }

            if ($status) {
                $order_status_id = $this->config->get('config_order_status_id');
            }

            $this->db->query("UPDATE `" . DB_PREFIX . "order` SET order_status_id = '" . (int)$order_status_id . "', date_modified = NOW() WHERE order_id = '" . (int)$order_id . "'");

            $this->db->query("INSERT INTO " . DB_PREFIX . "order_history SET order_id = '" . (int)$order_id . "', order_status_id = '" . (int)$order_status_id . "', notify = '1', comment = '" . $this->db->escape(($comment && $notify) ? $comment : '') . "', date_added = NOW()");

            $order_product_query = $this->db->query("SELECT * FROM " . DB_PREFIX . "order_product WHERE order_id = '" . (int)$order_id . "'");

            foreach ($order_product_query->rows as $order_product) {
                $this->db->query("UPDATE " . DB_PREFIX . "product SET quantity = (quantity - " . (int)$order_product['quantity'] . ") WHERE product_id = '" . (int)$order_product['product_id'] . "' AND subtract = '1'");

                $order_option_query = $this->db->query("SELECT * FROM " . DB_PREFIX . "order_option WHERE order_id = '" . (int)$order_id . "' AND order_product_id = '" . (int)$order_product['order_product_id'] . "'");

                foreach ($order_option_query->rows as $option) {
                    $this->db->query("UPDATE " . DB_PREFIX . "product_option_value SET quantity = (quantity - " . (int)$order_product['quantity'] . ") WHERE product_option_value_id = '" . (int)$option['product_option_value_id'] . "' AND subtract = '1'");
                }
            }

            if (!isset($passArray) || empty($passArray)) {
                $passArray = null;
            }
            $this->openbay->orderNew((int)$order_id);

            $this->cache->delete('product');

            // Downloads
            $order_download_query = $this->db->query("SELECT * FROM " . DB_PREFIX . "order_download WHERE order_id = '" . (int)$order_id . "'");

            // Gift Voucher
            $this->load->model('checkout/voucher');

            $order_voucher_query = $this->db->query("SELECT * FROM " . DB_PREFIX . "order_voucher WHERE order_id = '" . (int)$order_id . "'");

            foreach ($order_voucher_query->rows as $order_voucher) {
                $voucher_id = $this->model_checkout_voucher->addVoucher($order_id, $order_voucher);

                $this->db->query("UPDATE " . DB_PREFIX . "order_voucher SET voucher_id = '" . (int)$voucher_id . "' WHERE order_voucher_id = '" . (int)$order_voucher['order_voucher_id'] . "'");
            }

            // Send out any gift voucher mails
            if ($this->config->get('config_complete_status_id') == $order_status_id) {
                $this->model_checkout_voucher->confirm($order_id);
            }

            // Order Totals
            $order_total_query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "order_total` WHERE order_id = '" . (int)$order_id . "' ORDER BY sort_order ASC");
            //print_r($order_total_query);exit;
            foreach ($order_total_query->rows as $order_total) {
                $this->load->model('total/' . $order_total['code']);

                if (method_exists($this->{'model_total_' . $order_total['code']}, 'confirm')) {
                    $this->{'model_total_' . $order_total['code']}->confirm($order_info, $order_total);
                }
            }

            // Send out order confirmation mail
            $language = new Language($order_info['language_directory']);
            $language->load($order_info['language_filename']);
            $language->load('mail/order');

            $order_status_query = $this->db->query("SELECT * FROM " . DB_PREFIX . "order_status WHERE order_status_id = '" . (int)$order_status_id . "' AND language_id = '" . (int)$order_info['language_id'] . "'");

            if ($order_status_query->num_rows) {
                $order_status = $order_status_query->row['name'];
            } else {
                $order_status = '';
            }

            $subject = sprintf($language->get('text_new_subject'), $order_info['store_name'], $order_id);

            // HTML Mail
            $template = new Template();

            $template->data['title'] = sprintf($language->get('text_new_subject'), html_entity_decode($order_info['store_name'], ENT_QUOTES, 'UTF-8'), $order_id);

            $template->data['text_greeting'] = sprintf($language->get('text_new_greeting'), html_entity_decode($order_info['store_name'], ENT_QUOTES, 'UTF-8'));
            $template->data['text_link'] = $language->get('text_new_link');
            $template->data['text_download'] = $language->get('text_new_download');
            $template->data['text_order_detail'] = $language->get('text_new_order_detail');
            $template->data['text_instruction'] = $language->get('text_new_instruction');
            $template->data['text_order_id'] = $language->get('text_new_order_id');
            $template->data['text_date_added'] = $language->get('text_new_date_added');
            $template->data['text_payment_method'] = $language->get('text_new_payment_method');
            $template->data['text_shipping_method'] = $language->get('text_new_shipping_method');
            $template->data['text_email'] = $language->get('text_new_email');
            $template->data['text_telephone'] = $language->get('text_new_telephone');
            $template->data['text_ip'] = $language->get('text_new_ip');
            $template->data['text_payment_address'] = $language->get('text_new_payment_address');
            $template->data['text_shipping_address'] = $language->get('text_new_shipping_address');
            $template->data['text_product'] = $language->get('text_new_product');
            $template->data['text_model'] = $language->get('text_new_model');
            $template->data['text_quantity'] = $language->get('text_new_quantity');
            $template->data['text_price'] = $language->get('text_new_price');
            $template->data['text_total'] = $language->get('text_new_total');
            $template->data['text_footer'] = $language->get('text_new_footer');
            $template->data['text_powered'] = $language->get('text_new_powered');

            $template->data['logo'] = $this->config->get('config_url') . 'image/' . $this->config->get('config_logo');
            $template->data['store_name'] = $order_info['store_name'];
            $template->data['store_url'] = $order_info['store_url'];
            $template->data['customer_id'] = $order_info['customer_id'];
            $template->data['link'] = $order_info['store_url'] . 'index.php?route=account/order/info&order_id=' . $order_id;

            if ($order_download_query->num_rows) {
                $template->data['download'] = $order_info['store_url'] . 'index.php?route=account/download';
            } else {
                $template->data['download'] = '';
            }

            $template->data['order_id'] = $order_id;
            $template->data['date_added'] = date($language->get('date_format_short'), strtotime($order_info['date_added']));
            $template->data['payment_method'] = $order_info['payment_method'];
            $template->data['shipping_method'] = $order_info['shipping_method'];
            $template->data['email'] = $order_info['email'];
            $template->data['telephone'] = $order_info['telephone'];
            $template->data['ip'] = $order_info['ip'];

            if ($comment && $notify) {
                $template->data['comment'] = nl2br($comment);
            } else {
                $template->data['comment'] = '';
            }

            if ($order_info['payment_address_format']) {
                $format = $order_info['payment_address_format'];
            } else {
                $format = '{firstname} {lastname}' . "\n" . '{company}' . "\n" . '{address_1}' . "\n" . '{address_2}' . "\n" . '{city} {postcode}' . "\n" . '{zone}' . "\n" . '{country}';
            }

            $find = array(
                '{firstname}',
                '{lastname}',
                '{company}',
                '{address_1}',
                '{address_2}',
                '{city}',
                '{postcode}',
                '{zone}',
                '{zone_code}',
                '{country}'
            );

            $replace = array(
                'firstname' => $order_info['payment_firstname'],
                'lastname' => $order_info['payment_lastname'],
                'company' => $order_info['payment_company'],
                'address_1' => $order_info['payment_address_1'],
                'address_2' => $order_info['payment_address_2'],
                'city' => $order_info['payment_city'],
                'postcode' => $order_info['payment_postcode'],
                'zone' => $order_info['payment_zone'],
                'zone_code' => $order_info['payment_zone_code'],
                'country' => $order_info['payment_country']
            );

            $template->data['payment_address'] = str_replace(array("\r\n", "\r", "\n"), '<br />', preg_replace(array("/\s\s+/", "/\r\r+/", "/\n\n+/"), '<br />', trim(str_replace($find, $replace, $format))));

            if ($order_info['shipping_address_format']) {
                $format = $order_info['shipping_address_format'];
            } else {
                $format = '{firstname} {lastname}' . "\n" . '{company}' . "\n" . '{address_1}' . "\n" . '{address_2}' . "\n" . '{city} {postcode}' . "\n" . '{zone}' . "\n" . '{country}';
            }

            $find = array(
                '{firstname}',
                '{lastname}',
                '{company}',
                '{address_1}',
                '{address_2}',
                '{city}',
                '{postcode}',
                '{zone}',
                '{zone_code}',
                '{country}'
            );

            $replace = array(
                'firstname' => $order_info['shipping_firstname'],
                'lastname' => $order_info['shipping_lastname'],
                'company' => $order_info['shipping_company'],
                'address_1' => $order_info['shipping_address_1'],
                'address_2' => $order_info['shipping_address_2'],
                'city' => $order_info['shipping_city'],
                'postcode' => $order_info['shipping_postcode'],
                'zone' => $order_info['shipping_zone'],
                'zone_code' => $order_info['shipping_zone_code'],
                'country' => $order_info['shipping_country']
            );

            $template->data['shipping_address'] = str_replace(array("\r\n", "\r", "\n"), '<br />', preg_replace(array("/\s\s+/", "/\r\r+/", "/\n\n+/"), '<br />', trim(str_replace($find, $replace, $format))));

            // Products
            $template->data['products'] = array();

            foreach ($order_product_query->rows as $product) {
                $option_data = array();

                $order_option_query = $this->db->query("SELECT * FROM " . DB_PREFIX . "order_option WHERE order_id = '" . (int)$order_id . "' AND order_product_id = '" . (int)$product['order_product_id'] . "'");

                foreach ($order_option_query->rows as $option) {
                    if ($option['type'] != 'file') {
                        $value = $option['value'];
                    } else {
                        $value = utf8_substr($option['value'], 0, utf8_strrpos($option['value'], '.'));
                    }

                    $option_data[] = array(
                        'name' => $option['name'],
                        'value' => (utf8_strlen($value) > 20 ? utf8_substr($value, 0, 20) . '..' : $value)
                    );
                }

                $template->data['products'][] = array(
                    'name' => $product['name'],
                    'model' => $product['model'],
                    'option' => $option_data,
                    'quantity' => $product['quantity'],
                    'price' => $this->currency->format($product['price'] + ($this->config->get('config_tax') ? $product['tax'] : 0), $order_info['currency_code'], $order_info['currency_value']),
                    'total' => $this->currency->format($product['total'] + ($this->config->get('config_tax') ? ($product['tax'] * $product['quantity']) : 0), $order_info['currency_code'], $order_info['currency_value'])
                );
            }

            // Vouchers
            $template->data['vouchers'] = array();

            foreach ($order_voucher_query->rows as $voucher) {
                $template->data['vouchers'][] = array(
                    'description' => $voucher['description'],
                    'amount' => $this->currency->format($voucher['amount'], $order_info['currency_code'], $order_info['currency_value']),
                );
            }

            $template->data['totals'] = $order_total_query->rows;

            if (file_exists(DIR_TEMPLATE . $this->config->get('config_template') . '/template/mail/order.tpl')) {
                $html = $template->fetch($this->config->get('config_template') . '/template/mail/order.tpl');
            } else {
                $html = $template->fetch('default/template/mail/order.tpl');
            }

            // Can not send confirmation emails for CBA orders as email is unknown
            $this->load->model('payment/amazon_checkout');
            if (!$this->model_payment_amazon_checkout->isAmazonOrder($order_info['order_id'])) {
                // Text Mail
                $text = sprintf($language->get('text_new_greeting'), html_entity_decode($order_info['store_name'], ENT_QUOTES, 'UTF-8')) . "\n\n";
                $text .= $language->get('text_new_order_id') . ' ' . $order_id . "\n";
                $text .= $language->get('text_new_date_added') . ' ' . date($language->get('date_format_short'), strtotime($order_info['date_added'])) . "\n";
                $text .= $language->get('text_new_order_status') . ' ' . $order_status . "\n\n";

                if ($comment && $notify) {
                    $text .= $language->get('text_new_instruction') . "\n\n";
                    $text .= $comment . "\n\n";
                }

                // Products
                $text .= $language->get('text_new_products') . "\n";

                foreach ($order_product_query->rows as $product) {
                    $text .= $product['quantity'] . 'x ' . $product['name'] . ' (' . $product['model'] . ') ' . html_entity_decode($this->currency->format($product['total'] + ($this->config->get('config_tax') ? ($product['tax'] * $product['quantity']) : 0), $order_info['currency_code'], $order_info['currency_value']), ENT_NOQUOTES, 'UTF-8') . "\n";

                    $order_option_query = $this->db->query("SELECT * FROM " . DB_PREFIX . "order_option WHERE order_id = '" . (int)$order_id . "' AND order_product_id = '" . $product['order_product_id'] . "'");

                    foreach ($order_option_query->rows as $option) {
                        $text .= chr(9) . '-' . $option['name'] . ' ' . (utf8_strlen($option['value']) > 20 ? utf8_substr($option['value'], 0, 20) . '..' : $option['value']) . "\n";
                    }
                }

                foreach ($order_voucher_query->rows as $voucher) {
                    $text .= '1x ' . $voucher['description'] . ' ' . $this->currency->format($voucher['amount'], $order_info['currency_code'], $order_info['currency_value']);
                }

                $text .= "\n";

                $text .= $language->get('text_new_order_total') . "\n";

                foreach ($order_total_query->rows as $total) {
                    $text .= $total['title'] . ': ' . html_entity_decode($total['text'], ENT_NOQUOTES, 'UTF-8') . "\n";
                }

                $text .= "\n";

                if ($order_info['customer_id']) {
                    $text .= $language->get('text_new_link') . "\n";
                    $text .= $order_info['store_url'] . 'index.php?route=account/order/info&order_id=' . $order_id . "\n\n";
                }

                if ($order_download_query->num_rows) {
                    $text .= $language->get('text_new_download') . "\n";
                    $text .= $order_info['store_url'] . 'index.php?route=account/download' . "\n\n";
                }

                // Comment
                if ($order_info['comment']) {
                    $text .= $language->get('text_new_comment') . "\n\n";
                    $text .= $order_info['comment'] . "\n\n";
                }

                $text .= $language->get('text_new_footer') . "\n\n";

                $mail = new Mail();
                $mail->protocol = $this->config->get('config_mail_protocol');
                $mail->parameter = $this->config->get('config_mail_parameter');
                $mail->hostname = $this->config->get('config_smtp_host');
                $mail->username = $this->config->get('config_smtp_username');
                $mail->password = $this->config->get('config_smtp_password');
                $mail->port = $this->config->get('config_smtp_port');
                $mail->timeout = $this->config->get('config_smtp_timeout');
                $mail->setTo($order_info['email']);
                $mail->setFrom($this->config->get('config_email'));
                $mail->setSender($order_info['store_name']);
                $mail->setSubject(html_entity_decode($subject, ENT_QUOTES, 'UTF-8'));
                $mail->setHtml($html);
                $mail->setText(html_entity_decode($text, ENT_QUOTES, 'UTF-8'));
                $mail->send();
            }

            // Admin Alert Mail
            if ($this->config->get('config_alert_mail')) {
                $subject = sprintf($language->get('text_new_subject'), html_entity_decode($this->config->get('config_name'), ENT_QUOTES, 'UTF-8'), $order_id);

                // Text
                $text = $language->get('text_new_received') . "\n\n";
                $text .= $language->get('text_new_order_id') . ' ' . $order_id . "\n";
                $text .= $language->get('text_new_date_added') . ' ' . date($language->get('date_format_short'), strtotime($order_info['date_added'])) . "\n";
                $text .= $language->get('text_new_order_status') . ' ' . $order_status . "\n\n";
                $text .= $language->get('text_new_products') . "\n";

                foreach ($order_product_query->rows as $product) {
                    $text .= $product['quantity'] . 'x ' . $product['name'] . ' (' . $product['model'] . ') ' . html_entity_decode($this->currency->format($product['total'] + ($this->config->get('config_tax') ? ($product['tax'] * $product['quantity']) : 0), $order_info['currency_code'], $order_info['currency_value']), ENT_NOQUOTES, 'UTF-8') . "\n";

                    $order_option_query = $this->db->query("SELECT * FROM " . DB_PREFIX . "order_option WHERE order_id = '" . (int)$order_id . "' AND order_product_id = '" . $product['order_product_id'] . "'");

                    foreach ($order_option_query->rows as $option) {
                        if ($option['type'] != 'file') {
                            $value = $option['value'];
                        } else {
                            $value = utf8_substr($option['value'], 0, utf8_strrpos($option['value'], '.'));
                        }

                        $text .= chr(9) . '-' . $option['name'] . ' ' . (utf8_strlen($value) > 20 ? utf8_substr($value, 0, 20) . '..' : $value) . "\n";
                    }
                }

                foreach ($order_voucher_query->rows as $voucher) {
                    $text .= '1x ' . $voucher['description'] . ' ' . $this->currency->format($voucher['amount'], $order_info['currency_code'], $order_info['currency_value']);
                }

                $text .= "\n";

                $text .= $language->get('text_new_order_total') . "\n";

                foreach ($order_total_query->rows as $total) {
                    $text .= $total['title'] . ': ' . html_entity_decode($total['text'], ENT_NOQUOTES, 'UTF-8') . "\n";
                }

                $text .= "\n";

                if ($order_info['comment']) {
                    $text .= $language->get('text_new_comment') . "\n\n";
                    $text .= $order_info['comment'] . "\n\n";
                }

                $mail = new Mail();
                $mail->protocol = $this->config->get('config_mail_protocol');
                $mail->parameter = $this->config->get('config_mail_parameter');
                $mail->hostname = $this->config->get('config_smtp_host');
                $mail->username = $this->config->get('config_smtp_username');
                $mail->password = $this->config->get('config_smtp_password');
                $mail->port = $this->config->get('config_smtp_port');
                $mail->timeout = $this->config->get('config_smtp_timeout');
                $mail->setTo($this->config->get('config_email'));
                $mail->setFrom($this->config->get('config_email'));
                $mail->setSender($order_info['store_name']);
                $mail->setSubject(html_entity_decode($subject, ENT_QUOTES, 'UTF-8'));
                $mail->setText(html_entity_decode($text, ENT_QUOTES, 'UTF-8'));
                $mail->send();

                // Send to additional alert emails
                $emails = explode(',', $this->config->get('config_alert_emails'));

                foreach ($emails as $email) {
                    if ($email && preg_match('/^[^\@]+@.*\.[a-z]{2,6}$/i', $email)) {
                        $mail->setTo($email);
                        $mail->send();
                    }
                }
            }
        }
    }
}
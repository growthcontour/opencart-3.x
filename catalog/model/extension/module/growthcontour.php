<?php
class ModelExtensionModuleGrowthcontour extends Model
{

    // ─────────────────────────────────────────────────────────────────────
    //  АБАНДОН-КОШИК: захоплення та відправлення в CRM
    // ─────────────────────────────────────────────────────────────────────

    // Гарантуємо наявність таблиці-буфера (ідемпотентно)
    public function ensureCartTable()
    {
        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "growthcontour_cart` (
              `id` INT(11) NOT NULL AUTO_INCREMENT,
              `store_id` INT(11) NOT NULL DEFAULT 0,
              `session_id` VARCHAR(128) NOT NULL,
              `customer_id` INT(11) NOT NULL DEFAULT 0,
              `email` VARCHAR(96) DEFAULT NULL,
              `telephone` VARCHAR(32) DEFAULT NULL,
              `firstname` VARCHAR(64) DEFAULT NULL,
              `lastname` VARCHAR(64) DEFAULT NULL,
              `consent` TINYINT(1) NOT NULL DEFAULT 0,
              `currency` VARCHAR(3) DEFAULT NULL,
              `total_amount` DECIMAL(15,4) NOT NULL DEFAULT 0,
              `items_count` INT(11) NOT NULL DEFAULT 0,
              `cart` LONGTEXT DEFAULT NULL,
              `items_hash` CHAR(64) DEFAULT NULL,
              `close_pending` TINYINT(1) NOT NULL DEFAULT 0,
              `utm_source` VARCHAR(100) DEFAULT NULL,
              `utm_medium` VARCHAR(100) DEFAULT NULL,
              `utm_campaign` VARCHAR(100) DEFAULT NULL,
              `sync_hash` CHAR(64) DEFAULT NULL,
              `status` ENUM('open','sent','recovered','expired') NOT NULL DEFAULT 'open',
              `first_seen_at` DATETIME NOT NULL,
              `last_activity_at` DATETIME NOT NULL,
              `last_sent_at` DATETIME DEFAULT NULL,
              `date_added` DATETIME NOT NULL,
              `date_modified` DATETIME NOT NULL,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uq_store_session` (`store_id`,`session_id`),
              KEY `idx_status` (`status`),
              KEY `idx_activity` (`last_activity_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    // Головний цикл крону: закриття спорожнених → відправка змінених. Від буфера.
    public function runCartCron($opts)
    {
        $this->ensureCartTable();

        $threshold = max(1, (int) $opts['threshold_min']);
        $maxAgeH = max(1, (int) $opts['max_age_hours']);
        $batch = max(1, min(200, (int) $opts['batch']));
        $minAmount = (float) $opts['min_amount'];
        $guests = !empty($opts['guests']);
        $needContact = !empty($opts['require_contact']);
        $needConsent = !empty($opts['require_consent']);

        $store_id = (int) $this->config->get('config_store_id');
        $summary = array('sent' => 0, 'closed' => 0, 'skipped' => 0, 'failed' => 0);

        // 1) Відкладені закриття (спорожнені кошики)
        $pending = $this->db->query(
            "SELECT id, session_id, customer_id, email FROM `" . DB_PREFIX . "growthcontour_cart`
			 WHERE store_id = '" . $store_id . "' AND close_pending = 1 LIMIT " . $batch
        )->rows;

        foreach ($pending as $p) {
            if ($this->sendCartClose($store_id, $p['session_id'], $p['customer_id'], $p['email'])) {
                $this->db->query("UPDATE `" . DB_PREFIX . "growthcontour_cart` SET close_pending = 0 WHERE id = '" . (int) $p['id'] . "'");
                $summary['closed']++;
            } else {
                $summary['failed']++;
            }
        }

        // 2) Кандидати: змінені (sync_hash <> items_hash) і такі, що перейшли поріг
        $rows = $this->db->query(
            "SELECT * FROM `" . DB_PREFIX . "growthcontour_cart`
			 WHERE store_id = '" . $store_id . "'
			   AND status IN ('open','sent')
			   AND items_count > 0
			   AND (sync_hash IS NULL OR sync_hash <> items_hash)
			   AND last_activity_at <= (NOW() - INTERVAL " . $threshold . " MINUTE)
			   AND last_activity_at >= (NOW() - INTERVAL " . $maxAgeH . " HOUR)
			 ORDER BY last_activity_at ASC LIMIT " . $batch
        )->rows;

        foreach ($rows as $r) {
            if ((float) $r['total_amount'] < $minAmount) {
                $summary['skipped']++;
                continue;
            }
            if (((int) $r['customer_id'] === 0) && !$guests) {
                $summary['skipped']++;
                continue;
            }

            $hasContact = !empty($r['email']) || !empty($r['telephone']);
            if ($needContact && !$hasContact) {
                $summary['skipped']++;
                continue;
            }
            if ($needConsent && empty($r['consent'])) {
                $summary['skipped']++;
                continue;
            }

            $payload = $this->buildPayloadFromBuffer($store_id, $r);
            if ($this->sendCart($payload)) {
                $this->db->query(
                    "UPDATE `" . DB_PREFIX . "growthcontour_cart`
					 SET sync_hash = '" . $this->db->escape($r['items_hash']) . "', status = 'sent',
						 last_sent_at = NOW(), date_modified = NOW()
					 WHERE id = '" . (int) $r['id'] . "'"
                );
                $summary['sent']++;
            } else {
                $summary['failed']++;
            }
        }

        return $summary;
    }

    // Реконструкція позицій кошика з oc_cart (без важкого $this->cart, бо чужа сесія)
    private function buildCartLines($session_id, $language_id)
    {
        $store_url = rtrim($this->config->get('config_url'), '/');
        $items = array();
        $total_amount = 0.0;
        $items_count = 0;

        $lines = $this->db->query(
            "SELECT ct.product_id, ct.quantity, ct.option,
                    pd.name, p.model, p.image, p.price,
                    (SELECT ps.price FROM `" . DB_PREFIX . "product_special` ps
                       WHERE ps.product_id = p.product_id
                         AND ((ps.date_start = '0000-00-00' OR ps.date_start < NOW())
                          AND (ps.date_end = '0000-00-00' OR ps.date_end > NOW()))
                       ORDER BY ps.priority ASC, ps.price ASC LIMIT 1) AS special
             FROM `" . DB_PREFIX . "cart` ct
             LEFT JOIN `" . DB_PREFIX . "product` p ON p.product_id = ct.product_id
             LEFT JOIN `" . DB_PREFIX . "product_description` pd
                    ON pd.product_id = ct.product_id AND pd.language_id = '" . (int) $language_id . "'
             WHERE ct.session_id = '" . $this->db->escape($session_id) . "'
               AND p.product_id IS NOT NULL
               AND p.status = '1'
               AND p.date_available <= NOW()"
        )->rows;

        foreach ($lines as $ln) {
            $qty = (int) $ln['quantity'];
            if ($qty <= 0) {
                continue;
            }
            $price = ($ln['special'] !== null && $ln['special'] !== '') ? (float) $ln['special'] : (float) $ln['price'];
            $line_total = $price * $qty;

            $items[] = array(
                'product_id' => (string) $ln['product_id'],
                'sku' => $ln['model'] ? $ln['model'] : null,
                'name' => $ln['name'] ? $ln['name'] : ('#' . $ln['product_id']),
                'quantity' => $qty,
                'price' => round($price, 4),
                'total' => round($line_total, 4),
                'options' => $this->resolveCartOptions($ln['option'], $language_id),
                'image_url' => $ln['image'] ? ($store_url . '/image/' . $ln['image']) : null,
                'product_url' => $store_url . '/index.php?route=product/product&product_id=' . (int) $ln['product_id'],
            );

            $total_amount += $line_total;
            $items_count += $qty;
        }

        return array(
            'items' => $items,
            'total_amount' => round($total_amount, 4),
            'items_count' => $items_count,
            'currency' => $this->config->get('config_currency'),
        );
    }

    // Опції кошика → { "Назва опції": "Значення" }. Робастно до json/serialize.
    private function resolveCartOptions($raw, $language_id)
    {
        if (!$raw) {
            return new stdClass();
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            $data = @unserialize($raw);
        }
        if (!is_array($data)) {
            return new stdClass();
        }

        $out = array();
        foreach ($data as $product_option_id => $value) {
            // select/radio: value = product_option_value_id
            if (is_numeric($value)) {
                $r = $this->db->query(
                    "SELECT od.name AS opt_name, ovd.name AS val_name
                     FROM `" . DB_PREFIX . "product_option_value` pov
                     LEFT JOIN `" . DB_PREFIX . "option_description` od
                            ON od.option_id = pov.option_id AND od.language_id = '" . (int) $language_id . "'
                     LEFT JOIN `" . DB_PREFIX . "option_value_description` ovd
                            ON ovd.option_value_id = pov.option_value_id AND ovd.language_id = '" . (int) $language_id . "'
                     WHERE pov.product_option_value_id = '" . (int) $value . "' LIMIT 1"
                )->row;
                if (!empty($r['opt_name'])) {
                    $out[$r['opt_name']] = isset($r['val_name']) ? $r['val_name'] : '';
                }
            } elseif (is_array($value)) {
                // checkbox: масив product_option_value_id
                $names = array();
                foreach ($value as $vid) {
                    $r = $this->db->query(
                        "SELECT od.name AS opt_name, ovd.name AS val_name
                         FROM `" . DB_PREFIX . "product_option_value` pov
                         LEFT JOIN `" . DB_PREFIX . "option_description` od
                                ON od.option_id = pov.option_id AND od.language_id = '" . (int) $language_id . "'
                         LEFT JOIN `" . DB_PREFIX . "option_value_description` ovd
                                ON ovd.option_value_id = pov.option_value_id AND ovd.language_id = '" . (int) $language_id . "'
                         WHERE pov.product_option_value_id = '" . (int) $vid . "' LIMIT 1"
                    )->row;
                    if (!empty($r['val_name'])) {
                        $names[] = $r['val_name'];
                        $optName = $r['opt_name'];
                    }
                }
                if (!empty($names) && !empty($optName)) {
                    $out[$optName] = implode(', ', $names);
                }
            } else {
                // text/textarea тощо
                $out['option_' . $product_option_id] = (string) $value;
            }
        }

        return empty($out) ? new stdClass() : $out;
    }

    // Контакт: буфер (згода + контакт гостя) → перекриваємо контакт даними customer
    private function resolveCartContact($store_id, $session_id, $customer_id)
    {
        $c = array(
            'email' => null,
            'telephone' => null,
            'firstname' => null,
            'lastname' => null,
            'consent' => 0,
            'customer_id' => $customer_id ? $customer_id : null
        );

        $b = $this->db->query(
            "SELECT email, telephone, firstname, lastname, consent FROM `" . DB_PREFIX . "growthcontour_cart`
             WHERE store_id = '" . (int) $store_id . "' AND session_id = '" . $this->db->escape($session_id) . "' LIMIT 1"
        )->row;
        if ($b) {
            $c['email'] = $b['email'];
            $c['telephone'] = $b['telephone'];
            $c['firstname'] = $b['firstname'];
            $c['lastname'] = $b['lastname'];
            $c['consent'] = (int) $b['consent'];
        }

        if ($customer_id > 0) {
            $r = $this->db->query(
                "SELECT firstname, lastname, email, telephone FROM `" . DB_PREFIX . "customer`
                 WHERE customer_id = '" . (int) $customer_id . "' LIMIT 1"
            )->row;
            if ($r) {
                $c['firstname'] = $r['firstname'];
                $c['lastname'] = $r['lastname'];
                $c['email'] = $r['email'];
                $c['telephone'] = $r['telephone'];
            }
        }

        return $c;
    }

    private function resolveUtm($existing)
    {
        if ($existing && (!empty($existing['utm_source']) || !empty($existing['utm_medium']) || !empty($existing['utm_campaign']))) {
            return array('source' => $existing['utm_source'], 'medium' => $existing['utm_medium'], 'campaign' => $existing['utm_campaign']);
        }
        return array('source' => null, 'medium' => null, 'campaign' => null);
    }

    // Конверт для CRM /api/orders/abandoned-cart/receive/
    private function buildCartPayload($store_id, $session_id, $customer_id, $contact, $built, $utm)
    {
        return array(
            'source' => array(
                'id_integration' => $this->config->get('module_growthcontour_integration_id') ? (int) $this->config->get('module_growthcontour_integration_id') : null,
                'store_id' => (int) $store_id,
                'session_id' => (string) $session_id,
                'external_cart_id' => null,
                'channel' => 'opencart',
            ),
            'customer' => array(
                'customer_id' => $contact['customer_id'],
                'firstname' => $contact['firstname'],
                'lastname' => $contact['lastname'],
                'email' => $contact['email'],
                'telephone' => $contact['telephone'],
                'consent' => !empty($contact['consent']) ? 1 : 0,
            ),
            'currency' => $built['currency'],
            'items' => $built['items'],
            'totals' => array('total_amount' => $built['total_amount'], 'items_count' => $built['items_count']),
            'utm' => $utm,
            'context' => array('last_activity_at' => date('c')),
        );
    }

    // Відправка кошика в CRM (перевикористовує request())
    private function sendCart($payload)
    {
        $domain = $this->config->get('module_growthcontour_domain');
        $token = $this->config->get('module_growthcontour_token');
        if (!$domain || !$token) {
            return false;
        }

        $url = rtrim(trim($domain), '/') . '/api/orders/abandoned-cart/receive/';
        $res = $this->request($url, $token, $payload, $this->detectStoreOrigin(), 'cart', 0);
        $code = (int) $res['http_code'];
        return ($code === 202 || $code === 200);
    }

    // ─────────────────────────────────────────────────────────────────────
    //  RECOVERY: купили → закриваємо кошик локально і сигналимо в CRM.
    //  Первинний матч — session_id; резерв — customer_id.
    // ─────────────────────────────────────────────────────────────────────
    public function recoverCart($order_id, $session_id = '')
    {
        $order_id = (int) $order_id;
        if (!$order_id) {
            return;
        }

        $this->ensureCartTable();

        $this->load->model('checkout/order');
        $order = $this->model_checkout_order->getOrder($order_id);
        if (!$order) {
            return;
        }

        $store_id = (int) $this->config->get('config_store_id');
        $customer_id = (int) $order['customer_id'];
        $email = $order['email'];
        $now = date('Y-m-d H:i:s');

        // Локальний буфер: закриваємо (за сесією, інакше за customer_id)
        if ($session_id !== '') {
            $this->db->query(
                "UPDATE `" . DB_PREFIX . "growthcontour_cart`
				 SET status = 'recovered', date_modified = '" . $now . "'
				 WHERE store_id = '" . $store_id . "' AND session_id = '" . $this->db->escape($session_id) . "'
				   AND status IN ('open','sent')"
            );
        } elseif ($customer_id > 0) {
            $this->db->query(
                "UPDATE `" . DB_PREFIX . "growthcontour_cart`
				 SET status = 'recovered', date_modified = '" . $now . "'
				 WHERE store_id = '" . $store_id . "' AND customer_id = '" . $customer_id . "'
				   AND status IN ('open','sent')"
            );
        } elseif (!empty($email)) {
            $this->db->query(
                "UPDATE `" . DB_PREFIX . "growthcontour_cart`
				 SET status = 'recovered', date_modified = '" . $now . "'
				 WHERE store_id = '" . $store_id . "' AND email = '" . $this->db->escape($email) . "'
				   AND status IN ('open','sent')"
            );
        }

        $domain = $this->config->get('module_growthcontour_domain');
        $token = $this->config->get('module_growthcontour_token');
        if (!$domain || !$token) {
            return;
        }

        $payload = array(
            'source' => array(
                'id_integration' => $this->config->get('module_growthcontour_integration_id') ? (int) $this->config->get('module_growthcontour_integration_id') : null,
                'store_id' => $store_id,
                'session_id' => (string) $session_id,
            ),
            'order' => array(
                'external_id' => 'OC-' . $order_id,
                'customer_id' => $customer_id ? $customer_id : null,
                'email' => $email ? $email : null,
            ),
        );

        $url = rtrim(trim($domain), '/') . '/api/orders/abandoned-cart/recover/';
        $this->request($url, $token, $payload, $this->detectStoreOrigin(), 'cart_recover', $order_id);
    }

    // ─────────────────────────────────────────────────────────────────────
    //  RECONCILE: шлемо відкриті сесії oc_cart пачками зі спільним as_of.
    //  as_of фіксує CRM на 1-й пачці й повертає — echo-їмо його далі.
    // ─────────────────────────────────────────────────────────────────────
    public function reconcileCarts()
    {
        $domain = $this->config->get('module_growthcontour_domain');
        $token = $this->config->get('module_growthcontour_token');
        if (!$domain || !$token) {
            return array('ok' => false, 'message' => 'no credentials');
        }

        $store_id = (int) $this->config->get('config_store_id');
        $url = rtrim(trim($domain), '/') . '/api/orders/abandoned-cart/reconcile/';
        $origin = $this->detectStoreOrigin();

        $page = 1000;
        $offset = 0;
        $as_of = null;
        $total = 0;
        $id_integration = $this->config->get('module_growthcontour_integration_id')
            ? (int) $this->config->get('module_growthcontour_integration_id') : null;

        while (true) {
            $rows = $this->db->query(
                "SELECT session_id FROM `" . DB_PREFIX . "growthcontour_cart`
				 WHERE store_id = '" . $store_id . "' AND status IN ('open','sent')
				 ORDER BY id ASC LIMIT " . (int) $page . " OFFSET " . (int) $offset
            )->rows;

            $sessions = array();
            foreach ($rows as $r) {
                $sessions[] = $r['session_id'];
            }
            $total += count($sessions);

            $isLast = (count($sessions) < $page);

            $payload = array(
                'source' => array('id_integration' => $id_integration, 'store_id' => $store_id),
                'sessions' => $sessions,
                'final' => $isLast ? true : false,
            );
            if ($as_of !== null) {
                $payload['as_of'] = $as_of;
            }

            $res = $this->request($url, $token, $payload, $origin, 'cart_reconcile', 0);
            if (isset($res['data']['as_of'])) {
                $as_of = $res['data']['as_of'];
            }

            if ($isLast) {
                break;
            }
            $offset += $page;

            if ($offset > 1000000) {
                break;
            } // запобіжник від нескінченного циклу
        }

        return array('ok' => true, 'total' => $total);
    }

    // Upsert контактів/згоди/UTM у буфер поточної сесії (виклик із беакона)
    public function beaconUpsert($store_id, $session_id, $customer_id, $d)
    {
        $this->ensureCartTable();
        $now = date('Y-m-d H:i:s');

        $row = $this->db->query(
            "SELECT id FROM `" . DB_PREFIX . "growthcontour_cart`
			 WHERE store_id = '" . (int) $store_id . "' AND session_id = '" . $this->db->escape($session_id) . "' LIMIT 1"
        )->row;

        if ($row) {
            $this->db->query(
                "UPDATE `" . DB_PREFIX . "growthcontour_cart` SET
					customer_id = '" . (int) $customer_id . "',
					email = COALESCE(" . $this->nn($d['email']) . ", email),
					telephone = COALESCE(" . $this->nn($d['telephone']) . ", telephone),
					firstname = COALESCE(" . $this->nn($d['firstname']) . ", firstname),
					lastname = COALESCE(" . $this->nn($d['lastname']) . ", lastname),
                    consent = '" . (int) $d['consent'] . "',
					sync_hash = NULL,
					utm_source = COALESCE(utm_source, " . $this->nn($d['utm_source']) . "),
					utm_medium = COALESCE(utm_medium, " . $this->nn($d['utm_medium']) . "),
					utm_campaign = COALESCE(utm_campaign, " . $this->nn($d['utm_campaign']) . "),
					last_activity_at = '" . $now . "', date_modified = '" . $now . "'
				 WHERE id = '" . (int) $row['id'] . "'"
            );
        } else {
            $this->db->query(
                "INSERT INTO `" . DB_PREFIX . "growthcontour_cart`
					(store_id, session_id, customer_id, email, telephone, firstname, lastname, consent,
					 utm_source, utm_medium, utm_campaign, sync_hash, status,
					 first_seen_at, last_activity_at, date_added, date_modified)
				 VALUES ('" . (int) $store_id . "', '" . $this->db->escape($session_id) . "', '" . (int) $customer_id . "',
					 " . $this->nn($d['email']) . ", " . $this->nn($d['telephone']) . ",
					 " . $this->nn($d['firstname']) . ", " . $this->nn($d['lastname']) . ", '" . (int) $d['consent'] . "',
					 " . $this->nn($d['utm_source']) . ", " . $this->nn($d['utm_medium']) . ", " . $this->nn($d['utm_campaign']) . ",
					 NULL, 'open', '" . $now . "', '" . $now . "', '" . $now . "', '" . $now . "')"
            );
        }
    }

    // Захоплення живого кошика (з $this->cart) у буфер. Порожній → закриття.
    public function captureLiveCart($store_id, $session_id, $customer_id, $products)
    {
        $this->ensureCartTable();
        if ($session_id === '') {
            return;
        }

        $store_id = (int) $store_id;
        $now = date('Y-m-d H:i:s');

        if (empty($products)) {
            $this->closeCartLocal($store_id, $session_id, $customer_id, 'cleared');
            return;
        }

        $store_url = rtrim($this->config->get('config_url'), '/');
        $items = array();
        $total = 0.0;
        $count = 0;

        foreach ($products as $p) {
            $qty = (int) $p['quantity'];
            if ($qty <= 0) {
                continue;
            }

            $opts = array();
            $opts_raw = array(); // технічні id для надійного відновлення
            if (!empty($p['option'])) {
                foreach ($p['option'] as $o) {
                    if (isset($o['name'])) {
                        $opts[$o['name']] = isset($o['value']) ? $o['value'] : '';
                    }
                    // id опції та значення (OpenCart їх дає в $this->cart->getProducts())
                    if (isset($o['product_option_id'])) {
                        $opts_raw[] = array(
                            'product_option_id' => (string) $o['product_option_id'],
                            'product_option_value_id' => isset($o['product_option_value_id']) ? (string) $o['product_option_value_id'] : null,
                            'type' => isset($o['type']) ? $o['type'] : null,
                            'value' => isset($o['value']) ? $o['value'] : null,
                        );
                    }
                }
            }

            $img = $this->db->query("SELECT image FROM `" . DB_PREFIX . "product` WHERE product_id = '" . (int) $p['product_id'] . "' LIMIT 1")->row;

            $items[] = array(
                'product_id' => (string) $p['product_id'],
                'sku' => isset($p['model']) ? $p['model'] : null,
                'name' => isset($p['name']) ? $p['name'] : ('#' . $p['product_id']),
                'quantity' => $qty,
                'price' => round((float) $p['price'], 4),
                'total' => round((float) $p['total'], 4),
                'options' => empty($opts) ? new stdClass() : $opts,
                'options_raw' => $opts_raw, // технічні id для відновлення
                'image_url' => (!empty($img['image'])) ? ($store_url . '/image/' . $img['image']) : null,
                'product_url' => $store_url . '/index.php?route=product/product&product_id=' . (int) $p['product_id'],
            );

            $total += (float) $p['total'];
            $count += $qty;
        }

        if (empty($items)) {
            $this->closeCartLocal($store_id, $session_id, $customer_id, 'cleared');
            return;
        }

        $contact = $this->resolveCartContact($store_id, $session_id, (int) $customer_id);
        $snapshot = array(
            'customer' => array(
                'customer_id' => ((int) $customer_id) ?: null,
                'firstname' => $contact['firstname'],
                'lastname' => $contact['lastname'],
                'email' => $contact['email'],
                'telephone' => $contact['telephone'],
            ),
            'cart' => $items,
        );
        $currency = $this->config->get('config_currency');
        $material = array('customer' => $snapshot['customer'], 'cart' => $items, 'total' => round($total, 4), 'count' => $count, 'currency' => $currency);
        $items_hash = hash('sha256', json_encode($material, JSON_UNESCAPED_UNICODE));

        $existing = $this->db->query(
            "SELECT id, items_hash, status FROM `" . DB_PREFIX . "growthcontour_cart`
			 WHERE store_id = '" . $store_id . "' AND session_id = '" . $this->db->escape($session_id) . "' LIMIT 1"
        )->row;

        if ($existing) {
            if ($existing['items_hash'] === $items_hash && !in_array($existing['status'], array('expired', 'recovered'))) {
                $this->db->query("UPDATE `" . DB_PREFIX . "growthcontour_cart` SET last_activity_at = '" . $now . "', date_modified = '" . $now . "' WHERE id = '" . (int) $existing['id'] . "'");
                return;
            }
            $this->db->query(
                "UPDATE `" . DB_PREFIX . "growthcontour_cart` SET
					customer_id = '" . (int) $customer_id . "',
					email = COALESCE(email, " . $this->nn($contact['email']) . "),
					telephone = COALESCE(telephone, " . $this->nn($contact['telephone']) . "),
					firstname = COALESCE(" . $this->nn($contact['firstname']) . ", firstname),
					lastname = COALESCE(" . $this->nn($contact['lastname']) . ", lastname),
					currency = '" . $this->db->escape($currency) . "', total_amount = '" . round($total, 4) . "',
					items_count = '" . (int) $count . "',
					cart = '" . $this->db->escape(json_encode($snapshot, JSON_UNESCAPED_UNICODE)) . "',
					items_hash = '" . $items_hash . "', status = 'open', close_pending = 0,
					last_activity_at = '" . $now . "', date_modified = '" . $now . "'
				 WHERE id = '" . (int) $existing['id'] . "'"
            );
        } else {
            $this->db->query(
                "INSERT INTO `" . DB_PREFIX . "growthcontour_cart`
					(store_id, session_id, customer_id, email, telephone, firstname, lastname, consent,
					 currency, total_amount, items_count, cart, items_hash, sync_hash, status,
					 first_seen_at, last_activity_at, date_added, date_modified)
				 VALUES ('" . $store_id . "', '" . $this->db->escape($session_id) . "', '" . (int) $customer_id . "',
					 " . $this->nn($contact['email']) . ", " . $this->nn($contact['telephone']) . ",
					 " . $this->nn($contact['firstname']) . ", " . $this->nn($contact['lastname']) . ", '0',
					 '" . $this->db->escape($currency) . "', '" . round($total, 4) . "', '" . (int) $count . "',
					 '" . $this->db->escape(json_encode($snapshot, JSON_UNESCAPED_UNICODE)) . "', '" . $items_hash . "', NULL, 'open',
					 '" . $now . "', '" . $now . "', '" . $now . "', '" . $now . "')"
            );
        }
    }

    // Локальне закриття кошика (спорожнення) + позначка «сповістити CRM»
    public function closeCartLocal($store_id, $session_id, $customer_id, $reason = 'cleared')
    {
        $this->ensureCartTable();
        $now = date('Y-m-d H:i:s');

        $row = $this->db->query(
            "SELECT id, status FROM `" . DB_PREFIX . "growthcontour_cart`
			 WHERE store_id = '" . (int) $store_id . "' AND session_id = '" . $this->db->escape($session_id) . "' LIMIT 1"
        )->row;

        if (!$row || in_array($row['status'], array('expired', 'recovered'))) {
            return;
        }

        $this->db->query(
            "UPDATE `" . DB_PREFIX . "growthcontour_cart`
			 SET status = 'expired', close_pending = 1, items_count = 0, total_amount = 0, date_modified = '" . $now . "'
			 WHERE id = '" . (int) $row['id'] . "'"
        );
    }

    // Конверт для CRM з буферного знімка
    private function buildPayloadFromBuffer($store_id, $r)
    {
        $snapshot = json_decode($r['cart'], true);
        if (!is_array($snapshot)) {
            $snapshot = array('customer' => array(), 'cart' => array());
        }
        $customer = isset($snapshot['customer']) ? $snapshot['customer'] : array();
        $items = isset($snapshot['cart']) ? $snapshot['cart'] : array();

        return array(
            'source' => array(
                'id_integration' => $this->config->get('module_growthcontour_integration_id') ? (int) $this->config->get('module_growthcontour_integration_id') : null,
                'store_id' => (int) $store_id,
                'session_id' => (string) $r['session_id'],
                'external_cart_id' => null,
                'channel' => 'opencart',
            ),
            'customer' => array(
                'customer_id' => ((int) $r['customer_id']) ?: null,
                'firstname' => isset($customer['firstname']) ? $customer['firstname'] : $r['firstname'],
                'lastname' => isset($customer['lastname']) ? $customer['lastname'] : $r['lastname'],
                'email' => $r['email'],
                'telephone' => $r['telephone'],
                'consent' => !empty($r['consent']) ? 1 : 0,
            ),
            'currency' => $r['currency'] ?: $this->config->get('config_currency'),
            'items' => $items,
            'totals' => array('total_amount' => (float) $r['total_amount'], 'items_count' => (int) $r['items_count']),
            'utm' => array('source' => $r['utm_source'], 'medium' => $r['utm_medium'], 'campaign' => $r['utm_campaign']),
            'context' => array('last_activity_at' => date('c', strtotime($r['last_activity_at']))),
        );
    }

    // Сигнал закриття кошика в CRM
    private function sendCartClose($store_id, $session_id, $customer_id, $email)
    {
        $domain = $this->config->get('module_growthcontour_domain');
        $token = $this->config->get('module_growthcontour_token');
        if (!$domain || !$token) {
            return false;
        }

        $payload = array(
            'source' => array(
                'id_integration' => $this->config->get('module_growthcontour_integration_id') ? (int) $this->config->get('module_growthcontour_integration_id') : null,
                'store_id' => (int) $store_id,
                'session_id' => (string) $session_id,
            ),
            'reason' => 'cleared',
            'customer' => array('customer_id' => ((int) $customer_id) ?: null, 'email' => $email ?: null),
        );

        $url = rtrim(trim($domain), '/') . '/api/orders/abandoned-cart/close/';
        $res = $this->request($url, $token, $payload, $this->detectStoreOrigin(), 'cart_close', 0);
        $code = (int) $res['http_code'];
        return ($code === 200 || $code === 202);
    }

    // Хелпер: NULL або екранований рядок для інлайн-SQL
    private function nn($v)
    {
        return ($v === null || $v === '') ? 'NULL' : ("'" . $this->db->escape($v) . "'");
    }
    public function sendOrder($order_id)
    {
        $domain = $this->config->get('module_growthcontour_domain');
        $token = $this->config->get('module_growthcontour_token');

        if (!$domain || !$token) {
            return;
        }

        $this->load->model('checkout/order');

        $order_info = $this->model_checkout_order->getOrder($order_id);

        if (!$order_info) {
            return;
        }

        $payload = $this->buildEnvelope($order_info);

        $url = rtrim(trim($domain), '/') . '/api/orders/receive/';
        $origin = $this->detectStoreOrigin();

        $this->request($url, $token, $payload, $origin, 'send', $order_id);
    }

    private function buildEnvelope($order)
    {
        $this->load->model('checkout/order');

        $order_id = (int) $order['order_id'];

        // Items
        $items = array();

        foreach ($this->model_checkout_order->getOrderProducts($order_id) as $product) {
            $options = array();

            foreach ($this->model_checkout_order->getOrderOptions($order_id, $product['order_product_id']) as $option) {
                $options[$option['name']] = $option['value'];
            }

            $items[] = array(
                'external_product_id' => $product['product_id'],
                'sku' => $product['model'],
                'name' => $product['name'],
                'type' => 'product',
                'quantity' => (float) $product['quantity'],
                'unit_price' => (float) $product['price'],
                'unit_price_wt' => (float) $product['price'] + (float) $product['tax'],
                'discount' => 0.0,
                'tax_rate' => ((float) $product['price'] > 0) ? round((float) $product['tax'] / (float) $product['price'], 4) : 0.0,
                'total' => (float) $product['total'],
                'attributes' => $options ? $options : null,
                'meta' => null
            );
        }

        // Totals + summary
        $totals = array();
        $summary = array(
            'total_products' => 0.0,
            'total_discount' => 0.0,
            'total_shipping' => 0.0,
            'total_wrapping' => 0.0,
            'total_tax' => 0.0,
            'total_tips' => 0.0,
            'total' => (float) $order['total'],
            'total_paid' => 0.0
        );

        foreach ($this->model_checkout_order->getOrderTotals($order_id) as $total) {
            $totals[] = array(
                'code' => $total['code'],
                'title' => $total['title'],
                'value' => (float) $total['value'],
                'sort_order' => (int) $total['sort_order']
            );

            switch ($total['code']) {
                case 'sub_total':
                    $summary['total_products'] = (float) $total['value'];
                    break;
                case 'shipping':
                    $summary['total_shipping'] = (float) $total['value'];
                    break;
                case 'tax':
                    $summary['total_tax'] = (float) $total['value'];
                    break;
                case 'coupon':
                case 'voucher':
                case 'reward':
                    $summary['total_discount'] += abs((float) $total['value']);
                    break;
            }
        }

        // Addresses
        $addresses = array(
            $this->address('billing', $order, 'payment'),
            $this->address('shipping', $order, 'shipping')
        );

        // Доставка: розібрані служба / місто / відділення з ref (таблиця growthcontour_order_delivery) — в адресу доставки й блок delivery
        $deliveryBlock = $this->deliveryBlock($order_id);
        if ($deliveryBlock) {
            $pt = $deliveryBlock['point'];
            $addresses[1]['city_ref'] = $pt['city']['ref'];
            if (!$addresses[1]['city'] && $pt['city']['name']) {
                $addresses[1]['city'] = $pt['city']['name'];
            }
            if (!$addresses[1]['region'] && $pt['area']['name']) {
                $addresses[1]['region'] = $pt['area']['name'];
            }
            $addresses[1]['warehouse'] = $pt['warehouse']['name'] ? mb_substr($pt['warehouse']['name'], 0, 255) : null;
            $addresses[1]['warehouse_ref'] = $pt['warehouse']['ref'];
            $addresses[1]['meta'] = array('delivery' => array_diff_key($deliveryBlock, array('raw' => true)));
        }

        // Payments — з історії оплат замовлення (якщо гейтвей писав у order_total/custom).
        // OpenCart не має єдиної таблиці транзакцій, тож базово фіксуємо факт оплати за статусом.
        $payments = array();
        $is_paid = in_array((int) $order['order_status_id'], $this->paidStatuses(), true);
        if ($is_paid && (float) $order['total'] > 0) {
            $payments[] = array(
                'transaction_id' => null,
                'amount' => round((float) $order['total'], 4),
                'currency_iso' => $order['currency_code'],
                'status' => 'paid',
                'paid_at' => date('c', strtotime($order['date_modified'])),
                'raw' => array('payment_code' => $order['payment_code'])
            );
        }

        // Fees / discounts — з рядків order_total
        $fees = array();
        $discounts = array();

        foreach ($this->model_checkout_order->getOrderTotals($order_id) as $total) {
            switch ($total['code']) {
                case 'coupon':
                case 'voucher':
                case 'reward':
                    $discounts[] = array(
                        'code' => null,
                        'name' => $total['title'],
                        'type' => $total['code'],
                        'value' => round(abs((float) $total['value']), 4)
                    );
                    break;

                case 'handling':
                case 'low_order_fee':
                case 'fee':
                    $fees[] = array(
                        'type' => $total['code'],
                        'title' => $total['title'],
                        'amount' => round((float) $total['value'], 4)
                    );
                    break;
            }
        }

        $envelope = array(
            'source' => array(
                'id_integration' => (int) $this->config->get('module_growthcontour_integration_id'),
                'channel' => 'web',
                'external_id' => 'OC-' . $order_id,
                'external_number' => (string) $order_id,
                'external_cart_id' => null,
                'secure_key' => null,
                'date_order' => date('c', strtotime($order['date_added']))
            ),
            'currency' => array(
                'iso' => $order['currency_code'],
                'rate' => (float) $order['currency_value']
            ),
            'status' => array(
                'financial_status' => $is_paid ? 'paid' : 'pending',
                'fulfillment_status' => in_array((int) $order['order_status_id'], array_map('intval', (array) $this->config->get('config_complete_status')), true) ? 'fulfilled' : 'unfulfilled',
                'external_status' => isset($order['order_status_id']) ? (string) $order['order_status_id'] : null,
                'external_updated_at' => date('c')
            ),
            'client' => array(
                'firstname' => $order['firstname'],
                'lastname' => $order['lastname'],
                'email' => $order['email'],
                'phone' => $order['telephone'],
                'company' => $order['payment_company'] ? $order['payment_company'] : null,
                'vat' => null,
                'external_client_id' => $order['customer_id'] ? ('OC-CUST-' . $order['customer_id']) : null
            ),
            'addresses' => $addresses,
            'items' => $items,
            'totals' => $totals,
            'summary' => $summary,
            'payment' => array(
                'method_code' => $order['payment_code'],
                'method_title' => $order['payment_method']
            ),
            // Блок доставки: метод + служба, тип, точка з ref, адреса, стан розбору й сирий знімок (raw)
            'delivery' => array_merge(
                array(
                    'method_code' => $order['shipping_code'],
                    'method_title' => $order['shipping_method']
                ),
                $deliveryBlock ? $deliveryBlock : array()
            ),
            'payments' => $payments,
            'fees' => $fees,
            'discounts' => $discounts,
            'note' => $order['comment'] ? $order['comment'] : null,
            'is_gift' => false,
            'gift_message' => null,
            'custom_fields' => new stdClass(),
            'meta' => new stdClass(),
            'raw' => new stdClass()
        );

        return $envelope;
    }

    // ─── Доставка ───
    private function deliveryLib()
    {
        require_once(DIR_SYSTEM . 'library/growthcontour/delivery.php');
        return new GrowthcontourDelivery($this->registry);
    }

    // Знімок доставки в момент оформлення / зміни статусу: поля замовлення + ключі сесії й POST, що стосуються доставки
    public function captureDelivery($order_id, $stage)
    {
        try {
            $session = (isset($this->session) && is_array($this->session->data)) ? $this->session->data : array();
            $post = (isset($this->request) && is_array($this->request->post)) ? $this->request->post : array();
            $this->deliveryLib()->capture((int) $order_id, $stage, $session, $post);
        } catch (\Exception $e) {
            $this->log->write('Growth contour delivery #' . (int) $order_id . ': ' . $e->getMessage());
        }
    }

    // Блок delivery для системи. Немає знімка (старе замовлення) — розбір за полями замовлення
    private function deliveryBlock($order_id)
    {
        try {
            $lib = $this->deliveryLib();
            if (!$lib->get($order_id)) {
                $lib->reparse($order_id);
            }
            return $lib->block($order_id);
        } catch (\Exception $e) {
            $this->log->write('Growth contour delivery #' . (int) $order_id . ': ' . $e->getMessage());
            return null;
        }
    }

    // Які статуси OC вважати "оплачено" — з налаштувань модуля
    private function paidStatuses()
    {
        $statuses = $this->config->get('module_growthcontour_paid_statuses');
        if (!is_array($statuses)) {
            return array();
        }
        return array_map('intval', $statuses);
    }

    public function getOrdersBatch($after_id, $limit)
    {
        $after_id = (int) $after_id;
        $limit = (int) $limit;

        $query = $this->db->query(
            "SELECT order_id FROM `" . DB_PREFIX . "order`
             WHERE order_status_id > 0 AND order_id > " . $after_id . "
             ORDER BY order_id ASC
             LIMIT " . $limit
        );

        return $query->rows;
    }

    public function getTotalOrders()
    {
        $query = $this->db->query("SELECT COUNT(*) AS total FROM `" . DB_PREFIX . "order` WHERE order_status_id > 0");
        return (int) $query->row['total'];
    }

    private function address($type, $order, $prefix)
    {
        return array(
            'type' => $type,
            'firstname' => $order[$prefix . '_firstname'],
            'lastname' => $order[$prefix . '_lastname'],
            'company' => $order[$prefix . '_company'] ? $order[$prefix . '_company'] : null,
            'phone' => $order['telephone'],
            'email' => $order['email'],
            'country' => $order[$prefix . '_iso_code_2'],
            'region' => $order[$prefix . '_zone'],
            'city' => $order[$prefix . '_city'],
            'city_ref' => null,
            'address_1' => $order[$prefix . '_address_1'] ? $order[$prefix . '_address_1'] : null,
            'address_2' => $order[$prefix . '_address_2'] ? $order[$prefix . '_address_2'] : null,
            'warehouse' => null,
            'warehouse_ref' => null,
            'postcode' => $order[$prefix . '_postcode'],
            'meta' => null
        );
    }

    // Store's own domain with scheme: https://opencart3.skyneuron.com
    private function detectStoreOrigin()
    {
        // Priority 1: real host of the current request
        if (!empty($_SERVER['HTTP_HOST'])) {
            $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
            $scheme = $https ? 'https' : 'http';

            return $scheme . '://' . $_SERVER['HTTP_HOST'];
        }

        // Priority 2: configured OpenCart store URL
        if (defined('HTTPS_SERVER') && HTTPS_SERVER) {
            $base = HTTPS_SERVER;
        } elseif (defined('HTTP_SERVER') && HTTP_SERVER) {
            $base = HTTP_SERVER;
        } else {
            $base = $this->config->get('config_url');
        }

        if (!$base) {
            return '';
        }

        $parts = parse_url($base);

        if (empty($parts['scheme']) || empty($parts['host'])) {
            return '';
        }

        return $parts['scheme'] . '://' . $parts['host'];
    }

    private function request($url, $token, $body, $origin = '', $event = 'request', $order_id = 0)
    {
        if (!$origin) {
            $origin = $this->detectStoreOrigin();
        }

        $headers = array(
            'Content-Type: application/json; charset=utf-8',
            'Authorization: Bearer ' . trim($token),
            'Accept: application/json'
        );

        if ($origin) {
            $headers[] = 'Origin: ' . $origin;
        }

        $started = microtime(true);

        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

        $raw = curl_exec($ch);
        $http_code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        curl_close($ch);

        $duration_ms = (int) round((microtime(true) - $started) * 1000);

        $result = array(
            'http_code' => $http_code,
            'error' => $error,
            'raw' => $raw,
            'data' => json_decode($raw, true),
            'sent_origin' => $origin
        );

        $this->writeLog($event, $url, $origin, $duration_ms, $result, $order_id);

        return $result;
    }

    private function writeLog($event, $url, $origin, $duration_ms, $result, $order_id = 0)
    {
        $code = (int) $result['http_code'];

        if ($code == 200 || $code == 201 || $code == 202) {
            $outcome = 'success';
            $message = 'accepted';
        } elseif ($code == 409) {
            $outcome = 'duplicate';
            $message = 'already accepted';
        } elseif ($code == 422) {
            $outcome = 'error';
            $message = 'invalid payload';
        } elseif ($code == 401 || $code == 403) {
            $outcome = 'error';
            $message = 'token/scope/domain problem';
        } elseif ($code >= 500) {
            $outcome = 'error';
            $message = 'server error (retryable)';
        } elseif ($code == 0) {
            $outcome = 'error';
            $message = $result['error'] ? $result['error'] : 'connection error';
        } else {
            $outcome = 'error';
            $message = 'unexpected response';
        }

        if (isset($result['data']['message']) && $result['data']['message']) {
            $message .= ': ' . $result['data']['message'];
        }

        $entry = array(
            'ts' => date('c'),
            'event' => $event,
            'order_id' => (int) $order_id,
            'http_code' => $code,
            'result' => $outcome,
            'message' => mb_substr($message, 0, 500),
            'url' => $url,
            'origin' => $origin,
            'duration_ms' => $duration_ms
        );

        $file = DIR_LOGS . 'growthcontour.log';

        file_put_contents($file, json_encode($entry, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);

        $this->rotate($file, 1000);
    }

    private function rotate($file, $max)
    {
        if (!is_file($file)) {
            return;
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($lines && count($lines) > $max) {
            $lines = array_slice($lines, -$max);

            file_put_contents($file, implode("\n", $lines) . "\n", LOCK_EX);
        }
    }

    // Застосувати зміну статусу, що прийшла з CRM
    public function applyStatusChange($body)
    {
        $external_id = isset($body['external_id']) ? (string) $body['external_id'] : '';
        $data = isset($body['data']) && is_array($body['data']) ? $body['data'] : array();
        $crm_status_id = isset($data['id_status']) ? (int) $data['id_status'] : 0;

        // external_id у нас формату "OC-123" → дістаємо реальний order_id
        $order_id = 0;
        if (preg_match('/(\d+)$/', $external_id, $m)) {
            $order_id = (int) $m[1];
        }

        if (!$order_id || !$crm_status_id) {
            return array('ok' => false, 'code' => 422, 'message' => 'missing order or status');
        }

        // Мапа CRM-статус → OC-статус (зберігається в налаштуваннях модуля)
        // Формат: { "crm_id": "oc_status_id" }
        $map = $this->config->get('module_growthcontour_status_map_in');
        $oc_status_id = (is_array($map) && isset($map[$crm_status_id]) && $map[$crm_status_id] !== '')
            ? (int) $map[$crm_status_id]
            : 0;

        if (!$oc_status_id) {
            // Немає відповідності — приймаємо, але не міняємо (щоб CRM не ретраїла)
            return array('ok' => true, 'message' => 'no mapping, skipped');
        }

        $this->load->model('checkout/order');

        $order_info = $this->model_checkout_order->getOrder($order_id);
        if (!$order_info) {
            return array('ok' => false, 'code' => 404, 'message' => 'order not found');
        }

        // Уникаємо петлі: якщо статус уже такий — нічого не робимо
        if ((int) $order_info['order_status_id'] === $oc_status_id) {
            return array('ok' => true, 'message' => 'already in status');
        }

        $comment = isset($data['comment']) ? (string) $data['comment'] : '';

        // addOrderHistory змінює статус. notify=false, щоб не слати листи повторно.
        $this->model_checkout_order->addOrderHistory($order_id, $oc_status_id, $comment, false);

        return array('ok' => true, 'message' => 'status applied');
    }

    // ─────────────────────────────────────────────────────────────────────
    //  API СИНХРОНІЗАЦІЇ (виклики CRM sync-client через receive)
    // ─────────────────────────────────────────────────────────────────────

    private $gc_lang_map = null;
    private $gc_state_ready = false;

    // Остання активність по розділу — для таблиці у вкладці «Каталог»
    public function syncMark($entity, $items, $error)
    {
        if (!$this->gc_state_ready) {
            $this->db->query(
                "CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "growthcontour_sync_state` (
                  `entity` VARCHAR(32) NOT NULL,
                  `last_at` DATETIME NOT NULL,
                  `last_items` INT(11) NOT NULL DEFAULT 0,
                  `last_error` VARCHAR(255) DEFAULT NULL,
                  PRIMARY KEY (`entity`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
            $this->gc_state_ready = true;
        }
        $err = $error !== '' ? "'" . $this->db->escape(mb_substr($error, 0, 255)) . "'" : 'NULL';
        $this->db->query(
            "INSERT INTO `" . DB_PREFIX . "growthcontour_sync_state` (entity, last_at, last_items, last_error)
             VALUES ('" . $this->db->escape($entity) . "', NOW(), '" . (int) $items . "', " . $err . ")
             ON DUPLICATE KEY UPDATE last_at = NOW(), last_items = VALUES(last_items), last_error = VALUES(last_error)"
        );
    }

    // Код мови CRM ('uk', 'uk-ua', 'en') → language_id OpenCart
    private function langId($code)
    {
        if ($this->gc_lang_map === null) {
            $this->gc_lang_map = array();
            foreach ($this->db->query("SELECT language_id, code FROM `" . DB_PREFIX . "language`")->rows as $l) {
                $c = strtolower($l['code']);
                $this->gc_lang_map[$c] = (int) $l['language_id'];
                $short = explode('-', $c)[0];
                if (!isset($this->gc_lang_map[$short])) {
                    $this->gc_lang_map[$short] = (int) $l['language_id'];
                }
            }
        }
        $c = strtolower(trim((string) $code));
        if (isset($this->gc_lang_map[$c])) {
            return $this->gc_lang_map[$c];
        }
        $short = explode('-', $c)[0];
        return isset($this->gc_lang_map[$short]) ? $this->gc_lang_map[$short] : 0;
    }

    private function langCodes()
    {
        $out = array();
        foreach ($this->db->query("SELECT language_id, code FROM `" . DB_PREFIX . "language`")->rows as $l) {
            $out[(int) $l['language_id']] = $l['code'];
        }
        return $out;
    }

    // Оновити/створити рядок *_description. $fields: колонка => значення (null — не чіпати). $cols — усі текстові колонки для INSERT
    private function upsertDesc($table, $key, $id, $lid, $fields, $cols)
    {
        $set = array();
        foreach ($fields as $col => $v) {
            if ($v !== null) {
                $set[$col] = "`" . $col . "` = '" . $this->db->escape(htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8')) . "'";
            }
        }
        if (!$set) {
            return;
        }
        $where = "`" . $key . "` = '" . (int) $id . "' AND language_id = '" . (int) $lid . "'";
        if ($this->db->query("SELECT 1 FROM `" . DB_PREFIX . $table . "` WHERE " . $where . " LIMIT 1")->num_rows) {
            $this->db->query("UPDATE `" . DB_PREFIX . $table . "` SET " . implode(', ', $set) . " WHERE " . $where);
        } else {
            foreach ($cols as $col) {
                if (!isset($set[$col])) {
                    $set[$col] = "`" . $col . "` = ''";
                }
            }
            $this->db->query("INSERT INTO `" . DB_PREFIX . $table . "` SET `" . $key . "` = '" . (int) $id . "', language_id = '" . (int) $lid . "', " . implode(', ', $set));
        }
    }

    // Поля CRM → колонки OpenCart
    private function descFields($l, $with_description)
    {
        $f = array(
            'name' => isset($l['name']) ? $l['name'] : null,
            'meta_title' => isset($l['meta_title']) ? $l['meta_title'] : null,
            'meta_description' => isset($l['meta_description']) ? $l['meta_description'] : null,
            'meta_keyword' => isset($l['meta_keywords']) ? $l['meta_keywords'] : null,
        );
        if ($with_description) {
            $f['description'] = isset($l['description']) ? $l['description'] : null;
        }
        return $f;
    }

    private function idsPage($table, $key, $d, $max)
    {
        $limit = max(1, min($max, isset($d['limit']) ? (int) $d['limit'] : 500));
        $page = max(1, isset($d['page']) ? (int) $d['page'] : 1);
        $total = (int) $this->db->query("SELECT COUNT(*) AS t FROM `" . DB_PREFIX . $table . "`")->row['t'];
        return array($limit, $page, $total, ($page - 1) * $limit);
    }

    public function apiPing()
    {
        return array(
            'platform' => 'opencart',
            'version' => defined('VERSION') ? VERSION : null,
            // Версія модуля і що він уміє — система не шле часткові обміни старим модулям
            'module_version' => '2.2.0',
            'features' => array('partial', 'state', 'max_prune', 'site_categories', 'export'),
            'shop' => (string) $this->config->get('config_name'),
            'sync' => array(
                'products' => (bool) $this->config->get('module_growthcontour_sync_products'),
                'categories' => (bool) $this->config->get('module_growthcontour_sync_categories'),
                'attributes' => (bool) $this->config->get('module_growthcontour_sync_attributes'),
                'reviews' => (bool) $this->config->get('module_growthcontour_sync_reviews'),
            ),
        );
    }

    // ─── АВТОЗАПУСК (catalogCron) ───

    private function autoTable()
    {
        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "growthcontour_sync_auto` (
              `entity` VARCHAR(32) NOT NULL,
              `last_run` DATETIME NOT NULL,
              PRIMARY KEY (`entity`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    // Чи минуло $minutes хвилин з останнього автозапуску
    public function autoDue($entity, $minutes)
    {
        $this->autoTable();
        $q = $this->db->query(
            "SELECT 1 FROM `" . DB_PREFIX . "growthcontour_sync_auto`
              WHERE entity = '" . $this->db->escape($entity) . "' AND last_run > DATE_SUB(NOW(), INTERVAL " . (int) $minutes . " MINUTE)"
        );
        return !$q->num_rows;
    }

    public function autoMark($entity)
    {
        $this->db->query(
            "INSERT INTO `" . DB_PREFIX . "growthcontour_sync_auto` (entity, last_run) VALUES ('" . $this->db->escape($entity) . "', NOW())
             ON DUPLICATE KEY UPDATE last_run = NOW()"
        );
    }

    // Попросити CRM запустити синхронізацію розділу. Повертає status або error
    public function crmSync($entity)
    {
        $domain = rtrim(trim((string) $this->config->get('module_growthcontour_domain')), '/');
        $token = trim((string) $this->config->get('module_growthcontour_token'));
        if (!$domain || !$token) {
            return array('error' => 'CRM domain or token is not set');
        }

        $ch = curl_init($domain . '/api/integrations/v1/sync/');
        curl_setopt_array($ch, array(
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_HTTPHEADER => array('Content-Type: application/json', 'Authorization: Bearer ' . $token),
            CURLOPT_POSTFIELDS => json_encode(array('entity' => $entity)),
        ));
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        $data = json_decode((string) $raw, true);
        if ($code >= 200 && $code < 300 && isset($data['result'][$entity])) {
            return $data['result'][$entity];
        }
        return array('error' => $err ?: (isset($data['error']) ? $data['error'] : (isset($data['reason']) ? $data['reason'] : 'HTTP ' . $code)));
    }

    // ─── ТОВАРИ ───

    // catalog.list { page, limit } → { items:[{id, variant_id, sku, name}], pages }
    public function apiCatalogList($d)
    {
        list($limit, $page, $total, $offset) = $this->idsPage('product', 'product_id', $d, 1000);
        $lang = (int) $this->config->get('config_language_id');
        $first = $this->config->get('module_growthcontour_sync_products_sku') === 'sku' ? 'sku' : 'model';
        $second = $first === 'sku' ? 'model' : 'sku';

        $rows = $this->db->query(
            "SELECT p.product_id, p.model, p.sku, pd.name
               FROM `" . DB_PREFIX . "product` p
               LEFT JOIN `" . DB_PREFIX . "product_description` pd ON pd.product_id = p.product_id AND pd.language_id = '" . $lang . "'
              ORDER BY p.product_id ASC
              LIMIT " . $offset . ", " . $limit
        )->rows;

        $items = array();
        foreach ($rows as $r) {
            $sku = trim((string) $r[$first]) !== '' ? trim((string) $r[$first]) : trim((string) $r[$second]);
            $items[] = array('id' => (string) $r['product_id'], 'variant_id' => null, 'sku' => $sku, 'name' => html_entity_decode((string) $r['name'], ENT_QUOTES, 'UTF-8'));
        }

        return array('items' => $items, 'page' => $page, 'pages' => max(1, (int) ceil($total / $limit)), 'total' => $total);
    }

    // products.update { items:[{ref, id, price, track, quantity, status, languages[], categories[], attributes[]}] }
    public function apiProductsUpdate($d)
    {
        $items = (isset($d['items']) && is_array($d['items'])) ? array_slice($d['items'], 0, 500) : array();
        $allowed = $this->config->get('module_growthcontour_sync_products_fields');
        if (!is_array($allowed)) {
            $allowed = array();
        }
        $can = array_flip(array_filter($allowed));
        $results = array();

        foreach ($items as $it) {
            $ref = isset($it['ref']) ? $it['ref'] : null;
            $pid = isset($it['id']) ? (int) $it['id'] : 0;

            if (!$pid) {
                $results[] = array('ref' => $ref, 'ok' => false, 'error' => 'bad id');
                continue;
            }
            if (!empty($it['variant_id'])) {
                $results[] = array('ref' => $ref, 'ok' => false, 'error' => 'variants are not supported');
                continue;
            }
            if (!$this->db->query("SELECT product_id FROM `" . DB_PREFIX . "product` WHERE product_id = '" . $pid . "' LIMIT 1")->num_rows) {
                $results[] = array('ref' => $ref, 'ok' => false, 'error' => 'product not found');
                continue;
            }

            $set = array();
            if (isset($can['price']) && isset($it['price']) && is_numeric($it['price'])) {
                $set[] = "price = '" . (float) $it['price'] . "'";
            }
            if (isset($can['quantity']) && array_key_exists('track', $it)) {
                if (!empty($it['track']) && isset($it['quantity']) && is_numeric($it['quantity'])) {
                    $set[] = "quantity = '" . (int) $it['quantity'] . "'";
                    $set[] = "subtract = '1'";
                } elseif (empty($it['track'])) {
                    $set[] = "subtract = '0'";
                }
            }
            if (isset($can['status']) && isset($it['status']) && $it['status'] !== null) {
                $set[] = "status = '" . ((int) $it['status'] ? 1 : 0) . "'";
            }

            // Назва, опис, мета-теги
            if (isset($can['content']) && !empty($it['languages']) && is_array($it['languages'])) {
                foreach ($it['languages'] as $l) {
                    $lid = isset($l['code']) ? $this->langId($l['code']) : 0;
                    if ($lid) {
                        $this->upsertDesc(
                            'product_description',
                            'product_id',
                            $pid,
                            $lid,
                            $this->descFields($l, true),
                            array('name', 'description', 'tag', 'meta_title', 'meta_description', 'meta_keyword')
                        );
                    }
                }
                $set[] = "date_modified = NOW()";
            }

            // Категорії товару: приходять id CRM → перекладаємо в id сайту. Нічого не зіставилось — не чіпаємо
            if (isset($can['categories']) && !empty($it['categories']) && is_array($it['categories'])) {
                $oc = array();
                foreach (array_unique(array_map('intval', $it['categories'])) as $crm_cat) {
                    $c = $this->mapGet('category', $crm_cat);
                    if ($c) {
                        $oc[] = $c;
                    }
                }
                if ($oc) {
                    $main = !empty($it['main_category']) ? $this->mapGet('category', (int) $it['main_category']) : 0;
                    $has_main = $this->hasCol('product_to_category', 'main_category'); // ocStore
                    $this->db->query("DELETE FROM `" . DB_PREFIX . "product_to_category` WHERE product_id = '" . $pid . "'");
                    foreach ($oc as $c) {
                        $this->db->query(
                            "INSERT IGNORE INTO `" . DB_PREFIX . "product_to_category` SET product_id = '" . $pid . "', category_id = '" . $c . "'"
                            . ($has_main ? ", main_category = '" . ($c === $main ? 1 : 0) . "'" : "")
                        );
                    }
                }
            }

            // Характеристики товару: [{id, languages:[{code, text}]}], повна заміна
            if (isset($can['attributes']) && isset($it['attributes']) && is_array($it['attributes'])) {
                $this->db->query("DELETE FROM `" . DB_PREFIX . "product_attribute` WHERE product_id = '" . $pid . "'");
                foreach ($it['attributes'] as $a) {
                    $aid = isset($a['id']) ? (int) $a['id'] : 0;
                    if (!$aid || empty($a['languages']) || !is_array($a['languages'])) {
                        continue;
                    }
                    foreach ($a['languages'] as $l) {
                        $lid = isset($l['code']) ? $this->langId($l['code']) : 0;
                        if ($lid) {
                            $this->db->query(
                                "REPLACE INTO `" . DB_PREFIX . "product_attribute` SET product_id = '" . $pid . "', attribute_id = '" . $aid . "', language_id = '" . $lid . "',
                                 `text` = '" . $this->db->escape(htmlspecialchars((string) (isset($l['text']) ? $l['text'] : ''), ENT_QUOTES, 'UTF-8')) . "'"
                            );
                        }
                    }
                }
            }

            if ($set) {
                $set[] = "date_modified = NOW()";
                $this->db->query("UPDATE `" . DB_PREFIX . "product` SET " . implode(', ', array_unique($set)) . " WHERE product_id = '" . $pid . "'");
            }
            $results[] = array('ref' => $ref, 'ok' => true);
        }

        $this->cache->delete('product');

        return array('results' => $results);
    }

    // ─── ОБМІН CRM → САЙТ: службове ───

    private $gc_cols = array();
    private $gc_tables = array();
    private $gc_jobs = array();
    private static $gc_map_ready = false;

    private function hasCol($table, $col)
    {
        $k = $table . '.' . $col;
        if (!isset($this->gc_cols[$k])) {
            $this->gc_cols[$k] = (bool) $this->db->query("SHOW COLUMNS FROM `" . DB_PREFIX . $table . "` LIKE '" . $this->db->escape($col) . "'")->num_rows;
        }
        return $this->gc_cols[$k];
    }

    private function tableExists($table)
    {
        if (!isset($this->gc_tables[$table])) {
            $this->gc_tables[$table] = (bool) $this->db->query("SHOW TABLES LIKE '" . $this->db->escape(DB_PREFIX . $table) . "'")->num_rows;
        }
        return $this->gc_tables[$table];
    }

    private function opt($entity, $name, $default)
    {
        $v = $this->config->get('module_growthcontour_sync_' . $entity . '_' . $name);
        return ($v === null || $v === '') ? $default : $v;
    }

    private function entityKey($entity)
    {
        $keys = array('products' => 'product', 'categories' => 'category', 'attributes' => 'attribute', 'reviews' => 'review', 'customers' => 'customer');
        return isset($keys[$entity]) ? $keys[$entity] : '';
    }

    // Відповідність id CRM ↔ id сайту + відбиток даних + мітка обміну (для «відсутніх у системі»)
    private function mapTable()
    {
        if (self::$gc_map_ready) {
            return;
        }
        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "growthcontour_map` (
              `entity` VARCHAR(16) NOT NULL,
              `crm_id` INT(11) UNSIGNED NOT NULL,
              `oc_id` INT(11) NOT NULL,
              `hash` CHAR(32) DEFAULT NULL,
              `job` CHAR(16) DEFAULT NULL,
              `date_modified` DATETIME NOT NULL,
              PRIMARY KEY (`entity`, `crm_id`),
              KEY `idx_oc` (`entity`, `oc_id`),
              KEY `idx_job` (`entity`, `job`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        if (!$this->hasCol('growthcontour_map', 'job')) {
            $this->db->query("ALTER TABLE `" . DB_PREFIX . "growthcontour_map` ADD `job` CHAR(16) DEFAULT NULL AFTER `hash`, ADD KEY `idx_job` (`entity`, `job`)");
        }
        self::$gc_map_ready = true;
    }

    private function mapRow($key, $crm_id)
    {
        $q = $this->db->query("SELECT oc_id, hash FROM `" . DB_PREFIX . "growthcontour_map` WHERE entity = '" . $this->db->escape($key) . "' AND crm_id = '" . (int) $crm_id . "'");
        return $q->num_rows ? $q->row : null;
    }

    public function mapGet($key, $crm_id)
    {
        $r = $this->mapRow($key, $crm_id);
        return $r ? (int) $r['oc_id'] : 0;
    }

    // $job = null — не змінювати мітку обміну (довідник оновлюється побічно, з товару)
    private function mapSet($key, $crm_id, $oc_id, $hash, $job)
    {
        $j = $job === null ? "NULL" : "'" . $this->db->escape($job) . "'";
        $this->db->query(
            "INSERT INTO `" . DB_PREFIX . "growthcontour_map` SET entity = '" . $this->db->escape($key) . "', crm_id = '" . (int) $crm_id . "',
             oc_id = '" . (int) $oc_id . "', hash = '" . $this->db->escape($hash) . "', job = " . $j . ", date_modified = NOW()
             ON DUPLICATE KEY UPDATE oc_id = VALUES(oc_id), hash = VALUES(hash), job = " . ($job === null ? "job" : "VALUES(job)") . ", date_modified = NOW()"
        );
    }

    private function mapTouch($key, $crm_id, $job)
    {
        $this->db->query("UPDATE `" . DB_PREFIX . "growthcontour_map` SET job = '" . $this->db->escape($job) . "' WHERE entity = '" . $this->db->escape($key) . "' AND crm_id = '" . (int) $crm_id . "'");
    }

    private function storeIds()
    {
        $ids = array(0);
        foreach ($this->db->query("SELECT store_id FROM `" . DB_PREFIX . "store`")->rows as $s) {
            $ids[] = (int) $s['store_id'];
        }
        return $ids;
    }

    // SEO URL: якщо keyword зайнятий іншим записом — додаємо суфікс з id
    private function setSeoUrl($query, $lid, $keyword)
    {
        $keyword = trim((string) $keyword);
        if ($keyword === '') {
            return;
        }
        $q = $this->db->escape($query);
        foreach ($this->storeIds() as $sid) {
            $kw = $keyword;
            $taken = $this->db->query("SELECT 1 FROM `" . DB_PREFIX . "seo_url` WHERE store_id = '" . $sid . "' AND keyword = '" . $this->db->escape($kw) . "' AND `query` <> '" . $q . "'");
            if ($taken->num_rows) {
                $kw = $keyword . '-' . preg_replace('/\D/', '', $query) . ($lid > 1 ? '-' . $lid : '');
            }
            $this->db->query("DELETE FROM `" . DB_PREFIX . "seo_url` WHERE store_id = '" . $sid . "' AND language_id = '" . (int) $lid . "' AND `query` = '" . $q . "'");
            $this->db->query("INSERT INTO `" . DB_PREFIX . "seo_url` SET store_id = '" . $sid . "', language_id = '" . (int) $lid . "', `query` = '" . $q . "', keyword = '" . $this->db->escape($kw) . "'");
        }
    }

    // Картинка лише з домену CRM. Ім'я файлу в CRM = хеш вмісту: однаковий файл не тягнемо вдруге
    private function fetchImage($url, $dir)
    {
        $crm_host = parse_url((string) $this->config->get('module_growthcontour_domain'), PHP_URL_HOST);
        $host = parse_url((string) $url, PHP_URL_HOST);
        if (!$crm_host || strcasecmp($crm_host, (string) $host) !== 0) {
            return '';
        }
        $name = basename((string) parse_url($url, PHP_URL_PATH));
        if (!preg_match('/^[a-zA-Z0-9_-]{8,}\.(jpe?g|png|webp|gif)$/i', $name)) {
            return '';
        }
        $rel = 'catalog/growthcontour/' . $dir . '/' . strtolower(substr($name, 0, 2)) . '/' . $name;
        $abs = DIR_IMAGE . $rel;
        if (is_file($abs)) {
            return $rel;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 30, CURLOPT_FOLLOWLOCATION => false));
        $bin = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code !== 200 || !$bin || strlen($bin) > 10 * 1024 * 1024 || !@getimagesizefromstring($bin)) {
            return '';
        }
        if (!is_dir(dirname($abs))) {
            @mkdir(dirname($abs), 0755, true);
        }
        return @file_put_contents($abs, $bin) ? $rel : '';
    }

    // ─── ЗАВДАННЯ ОБМІНУ: прогрес, скасування, продовження ───

    private function jobTable()
    {
        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "growthcontour_sync_job` (
              `entity` VARCHAR(32) NOT NULL,
              `job` CHAR(16) NOT NULL DEFAULT '',
              `status` VARCHAR(16) NOT NULL DEFAULT 'queued',
              `total` INT(11) NOT NULL DEFAULT 0,
              `done` INT(11) NOT NULL DEFAULT 0,
              `created` INT(11) NOT NULL DEFAULT 0,
              `updated` INT(11) NOT NULL DEFAULT 0,
              `unchanged` INT(11) NOT NULL DEFAULT 0,
              `skipped` INT(11) NOT NULL DEFAULT 0,
              `errors` INT(11) NOT NULL DEFAULT 0,
              `pruned` INT(11) NOT NULL DEFAULT 0,
              `last_error` VARCHAR(512) DEFAULT NULL,
              `last_crm_id` INT(11) UNSIGNED NOT NULL DEFAULT 0,
              `force_all` TINYINT(1) NOT NULL DEFAULT 0,
              `resume` TINYINT(1) NOT NULL DEFAULT 0,
              `cancel` TINYINT(1) NOT NULL DEFAULT 0,
              `started_at` DATETIME DEFAULT NULL,
              `updated_at` DATETIME DEFAULT NULL,
              `finished_at` DATETIME DEFAULT NULL,
              PRIMARY KEY (`entity`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    private function jobForce($entity)
    {
        if (!isset($this->gc_jobs[$entity])) {
            $q = $this->db->query("SELECT force_all FROM `" . DB_PREFIX . "growthcontour_sync_job` WHERE entity = '" . $this->db->escape($entity) . "'");
            $this->gc_jobs[$entity] = $q->num_rows ? (int) $q->row['force_all'] === 1 : false;
        }
        return $this->gc_jobs[$entity];
    }

    // Обмін не зміг стартувати / завершитись — показати причину в таблиці прогресу
    public function jobFail($entity, $message)
    {
        $this->jobTable();
        $this->db->query(
            "UPDATE `" . DB_PREFIX . "growthcontour_sync_job` SET status = 'error', last_error = '" . $this->db->escape(mb_substr((string) $message, 0, 500)) . "',
             updated_at = NOW(), finished_at = NOW() WHERE entity = '" . $this->db->escape($entity) . "' AND status IN ('queued', 'running')"
        );
    }

    // sync.begin { entity, total } → { job, resume_after, batch_size }

    public function apiSyncBegin($d)
    {
        $this->jobTable();
        $e = $this->db->escape((string) $d['entity']);
        $total = isset($d['total']) ? (int) $d['total'] : 0;

        // Частковий обмін (лише змінені записи): окремий рядок «<розділ>_partial», без продовження і без прибирання відсутніх.
        // Повний обмін і його «продовжити перерваний» не зачіпаються.
        if (!empty($d['partial'])) {
            $job = bin2hex(random_bytes(8));
            $this->db->query(
                "REPLACE INTO `" . DB_PREFIX . "growthcontour_sync_job` SET entity = '" . $e . "_partial', job = '" . $job . "', status = 'running',
                 total = '" . $total . "', done = 0, created = 0, updated = 0, unchanged = 0, skipped = 0, errors = 0, pruned = 0,
                 last_error = NULL, last_crm_id = 0, force_all = 0, resume = 0, cancel = 0, started_at = NOW(), updated_at = NOW(), finished_at = NULL"
            );
            $batch = (int) $this->config->get('module_growthcontour_sync_batch');
            return array('job' => $job, 'partial' => true, 'resume_after' => 0, 'batch_size' => max(10, min(500, $batch ?: 100)));
        }
        $row = $this->db->query("SELECT * FROM `" . DB_PREFIX . "growthcontour_sync_job` WHERE entity = '" . $e . "'")->row;

        $resume = $row && (int) $row['resume'] === 1 && $row['job'] !== '';
        $job = $resume ? $row['job'] : bin2hex(random_bytes(8));
        $force = $row ? (int) $row['force_all'] : 0;

        if ($resume) {
            $this->db->query(
                "UPDATE `" . DB_PREFIX . "growthcontour_sync_job` SET status = 'running', total = '" . $total . "', resume = 0, cancel = 0,
                 updated_at = NOW(), finished_at = NULL WHERE entity = '" . $e . "'"
            );
        } else {
            $this->db->query(
                "REPLACE INTO `" . DB_PREFIX . "growthcontour_sync_job` SET entity = '" . $e . "', job = '" . $job . "', status = 'running',
                 total = '" . $total . "', done = 0, created = 0, updated = 0, unchanged = 0, skipped = 0, errors = 0, pruned = 0,
                 last_error = NULL, last_crm_id = 0, force_all = '" . $force . "', resume = 0, cancel = 0,
                 started_at = NOW(), updated_at = NOW(), finished_at = NULL"
            );
        }
        $this->gc_jobs = array();

        $batch = (int) $this->config->get('module_growthcontour_sync_batch');
        return array(
            'job' => $job,
            'resume_after' => $resume ? (int) $row['last_crm_id'] : 0,
            'batch_size' => max(10, min(500, $batch ?: 100)),
        );
    }

    // Лічильники пакета. Повертає true, якщо користувач натиснув «Скасувати»
    public function jobProgress($entity, $job, $results, $items)
    {
        $c = array('created' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0, 'errors' => 0);
        $err = '';
        foreach ($results as $r) {
            if (!empty($r['ok'])) {
                $a = (isset($r['action']) && isset($c[$r['action']])) ? $r['action'] : 'updated';
                $c[$a]++;
            } else {
                $c['errors']++;
                if ($err === '') {
                    $err = '#' . (isset($r['ref']) ? $r['ref'] : '?') . ': ' . (isset($r['error']) ? $r['error'] : 'error');
                }
            }
        }
        $last = 0;
        if ($items) {
            $l = end($items);
            $last = isset($l['crm_id']) ? (int) $l['crm_id'] : 0;
        }
        $e = $this->db->escape($entity);
        $j = $this->db->escape($job);
        $this->db->query(
            "UPDATE `" . DB_PREFIX . "growthcontour_sync_job` SET done = done + " . count($results) . ",
             created = created + " . $c['created'] . ", updated = updated + " . $c['updated'] . ", unchanged = unchanged + " . $c['unchanged'] . ",
             skipped = skipped + " . $c['skipped'] . ", errors = errors + " . $c['errors'] . ", last_crm_id = '" . $last . "',
             last_error = " . ($err !== '' ? "'" . $this->db->escape(mb_substr($err, 0, 500)) . "'" : "last_error") . ", updated_at = NOW()
             WHERE entity = '" . $e . "' AND job = '" . $j . "'"
        );
        $q = $this->db->query("SELECT cancel FROM `" . DB_PREFIX . "growthcontour_sync_job` WHERE entity = '" . $e . "' AND job = '" . $j . "'");
        return $q->num_rows && (int) $q->row['cancel'] === 1;
    }

    // sync.end { entity, job, cancelled?, error? }
    public function apiSyncEnd($d)
    {
        $this->jobTable();
        $entity = (string) $d['entity'];
        $e = $this->db->escape($entity);
        $j = $this->db->escape(isset($d['job']) ? (string) $d['job'] : '');

        // Частковий обмін: «відсутні в системі» — лише ті, що система явно назвала (gone), решту не чіпаємо
        if (!empty($d['partial'])) {
            $status = !empty($d['error']) ? 'error' : (!empty($d['cancelled']) ? 'cancelled' : 'done');
            $pruned = 0;
            if ($status === 'done' && !empty($d['gone']) && is_array($d['gone']) && $this->entityKey($entity)) {
                $ids = array_slice(array_filter(array_map('intval', $d['gone'])), 0, 5000);
                if ($ids) {
                    $pruned = $this->pruneBy($entity, "m.crm_id IN (" . implode(',', $ids) . ")");
                }
            }
            $this->db->query(
                "UPDATE `" . DB_PREFIX . "growthcontour_sync_job` SET status = '" . $status . "', pruned = '" . (int) $pruned . "',
                 last_error = " . (!empty($d['error']) ? "'" . $this->db->escape(mb_substr((string) $d['error'], 0, 500)) . "'" : "last_error") . ",
                 updated_at = NOW(), finished_at = NOW() WHERE entity = '" . $e . "_partial' AND job = '" . $j . "'"
            );
            return array('status' => $status, 'pruned' => $pruned, 'partial' => true);
        }

        $row = $this->db->query("SELECT * FROM `" . DB_PREFIX . "growthcontour_sync_job` WHERE entity = '" . $e . "' AND job = '" . $j . "'")->row;
        if (!$row) {
            return array('status' => 'unknown job');
        }

        $pruned = 0;
        $err = "last_error";
        if (!empty($d['error'])) {
            $status = 'error';
            $err = "'" . $this->db->escape(mb_substr((string) $d['error'], 0, 500)) . "'";
        } elseif (!empty($d['cancelled'])) {
            $status = 'cancelled';
        } else {
            $status = 'done';
            // «Відсутні в системі» — лише після повного обміну без помилок
            if ((int) $row['total'] > 0 && (int) $row['errors'] === 0 && $this->entityKey($entity)) {
                // Захист від масового зникнення: система передає межу; більше — нічого не прибираємо, чекаємо підтвердження
                $max = isset($d['max_prune']) && $d['max_prune'] !== null && $d['max_prune'] !== '' ? (int) $d['max_prune'] : -1;
                $would = $max >= 0 ? $this->countMissing($entity, $row['job']) : 0;
                if ($max >= 0 && $would > $max) {
                    $blocked = $would;
                    $err = "'" . $this->db->escape('prune blocked: ' . $would . ' > ' . $max) . "'";
                } else {
                    $pruned = $this->pruneMissing($entity, $row['job']);
                }
            }
        }

        $this->db->query(
            "UPDATE `" . DB_PREFIX . "growthcontour_sync_job` SET status = '" . $status . "', pruned = '" . (int) $pruned . "', last_error = " . $err . ",
             force_all = 0, resume = 0, updated_at = NOW(), finished_at = NOW() WHERE entity = '" . $e . "'"
        );
        return array('status' => $status, 'pruned' => $pruned, 'prune_blocked' => isset($blocked) ? $blocked : 0);
    }

    // Скільки записів прибрав би повний обмін (для захисту від масового зникнення)
    private function countMissing($entity, $job)
    {
        $this->mapTable();
        $key = $this->entityKey($entity);
        if (!$key || $this->opt($entity, 'missing', 'keep') === 'keep') {
            return 0;
        }
        $j = $this->db->escape($job);
        return (int) $this->db->query(
            "SELECT COUNT(*) AS n FROM `" . DB_PREFIX . "growthcontour_map` m WHERE m.entity = '" . $key . "' AND (m.job IS NULL OR m.job <> '" . $j . "')"
        )->row['n'];
    }

    private function pruneMissing($entity, $job)
    {
        return $this->pruneBy($entity, "(m.job IS NULL OR m.job <> '" . $this->db->escape($job) . "')");
    }

    // Політика «Відсутні в системі» для записів мапи, що підпадають під $where (аліас мапи — m)
    private function pruneBy($entity, $where_m)
    {
        $this->mapTable();
        $key = $this->entityKey($entity);
        $mode = $this->opt($entity, 'missing', 'keep');
        if ($mode === 'keep' || !$key) {
            return 0;
        }

        // Немає в наявності: товар лишається на сайті, кількість 0, статус складу «Немає в наявності» з налаштувань магазину
        if ($mode === 'out_of_stock') {
            if ($key !== 'product') {
                $mode = 'disable';
            } else {
                $where = "m.entity = 'product' AND " . $where_m;
                $n = (int) $this->db->query("SELECT COUNT(*) AS n FROM `" . DB_PREFIX . "growthcontour_map` m WHERE " . $where)->row['n'];
                $this->db->query(
                    "UPDATE `" . DB_PREFIX . "product` t JOIN `" . DB_PREFIX . "growthcontour_map` m ON m.oc_id = t.product_id AND " . $where . "
                        SET t.quantity = 0, t.subtract = 1, t.stock_status_id = '" . (int) $this->config->get('config_stock_status_id') . "', m.hash = NULL"
                );
                $this->db->query(
                    "UPDATE `" . DB_PREFIX . "product_option_value` pov JOIN `" . DB_PREFIX . "growthcontour_map` m ON m.oc_id = pov.product_id AND " . $where . " SET pov.quantity = 0"
                );
                $this->cache->delete('product');
                return $n;
            }
        }

        if ($mode === 'disable') {
            if (!$this->hasCol($key, 'status')) {
                return 0; // у характеристик немає статусу — вимкнути неможливо
            }
            $this->db->query(
                "UPDATE `" . DB_PREFIX . $key . "` t JOIN `" . DB_PREFIX . "growthcontour_map` m
                    ON m.oc_id = t." . $key . "_id AND m.entity = '" . $key . "' AND " . $where_m . "
                    SET t.status = 0, m.hash = NULL WHERE t.status = 1"
            );
            $n = (int) $this->db->countAffected();
            $this->cache->delete($key);
            return $n;
        }

        $n = 0;
        do {
            $rows = $this->db->query("SELECT m.crm_id, m.oc_id FROM `" . DB_PREFIX . "growthcontour_map` m WHERE m.entity = '" . $key . "' AND " . $where_m . " LIMIT 500")->rows;
            foreach ($rows as $r) {
                $id = (int) $r['oc_id'];
                if ($key === 'product') {
                    $this->deleteProduct($id);
                } elseif ($key === 'category') {
                    $this->deleteCategory($id);
                } elseif ($key === 'customer') {
                    $this->deleteCustomer($id);
                } elseif ($key === 'review') {
                    $this->db->query("DELETE FROM `" . DB_PREFIX . "review` WHERE review_id = '" . $id . "'");
                } elseif ($key === 'attribute') {
                    foreach (array('attribute', 'attribute_description', 'product_attribute') as $t) {
                        $this->db->query("DELETE FROM `" . DB_PREFIX . $t . "` WHERE attribute_id = '" . $id . "'");
                    }
                }
                $this->db->query("DELETE FROM `" . DB_PREFIX . "growthcontour_map` WHERE entity = '" . $key . "' AND crm_id = '" . (int) $r['crm_id'] . "'");
                $n++;
            }
        } while (count($rows) === 500);

        if ($key === 'category' && $n) {
            $this->repairCategories(0);
        }
        $this->cache->delete($key);
        return $n;
    }

    private function deleteCustomer($cid)
    {
        $cid = (int) $cid;
        foreach (array(
            'customer',
            'address',
            'customer_activity',
            'customer_affiliate',
            'customer_approval',
            'customer_history',
            'customer_ip',
            'customer_online',
            'customer_reward',
            'customer_search',
            'customer_transaction',
            'customer_wishlist'
        ) as $t) {
            if ($this->tableExists($t) && $this->hasCol($t, 'customer_id')) {
                $this->db->query("DELETE FROM `" . DB_PREFIX . $t . "` WHERE customer_id = '" . $cid . "'");
            }
        }
    }
    private function deleteProduct($pid)
    {
        $pid = (int) $pid;
        foreach (array(
            'product',
            'product_attribute',
            'product_description',
            'product_discount',
            'product_filter',
            'product_image',
            'product_option',
            'product_option_value',
            'product_reward',
            'product_special',
            'product_to_category',
            'product_to_download',
            'product_to_layout',
            'product_to_store',
            'product_recurring',
            'review',
            'coupon_product'
        ) as $t) {
            if ($this->tableExists($t)) {
                $this->db->query("DELETE FROM `" . DB_PREFIX . $t . "` WHERE product_id = '" . $pid . "'");
            }
        }
        if ($this->tableExists('product_related')) {
            $this->db->query("DELETE FROM `" . DB_PREFIX . "product_related` WHERE product_id = '" . $pid . "' OR related_id = '" . $pid . "'");
        }
        $this->db->query("DELETE FROM `" . DB_PREFIX . "seo_url` WHERE `query` = 'product_id=" . $pid . "'");
    }

    private function deleteCategory($cid)
    {
        $cid = (int) $cid;
        foreach (array(
            'category',
            'category_description',
            'category_filter',
            'category_path',
            'category_to_layout',
            'category_to_store',
            'product_to_category',
            'coupon_category'
        ) as $t) {
            if ($this->tableExists($t)) {
                $this->db->query("DELETE FROM `" . DB_PREFIX . $t . "` WHERE category_id = '" . $cid . "'");
            }
        }
        $this->db->query("DELETE FROM `" . DB_PREFIX . "seo_url` WHERE `query` = 'category_id=" . $cid . "'");
    }

    // ─── КАТЕГОРІЇ ───

    // Перебудова category_path (алгоритм адмінки OpenCart)
    private function repairCategories($parent_id = 0)
    {
        $rows = $this->db->query("SELECT category_id FROM `" . DB_PREFIX . "category` WHERE parent_id = '" . (int) $parent_id . "'")->rows;
        foreach ($rows as $c) {
            $cid = (int) $c['category_id'];
            $this->db->query("DELETE FROM `" . DB_PREFIX . "category_path` WHERE category_id = '" . $cid . "'");
            $level = 0;
            foreach ($this->db->query("SELECT path_id FROM `" . DB_PREFIX . "category_path` WHERE category_id = '" . (int) $parent_id . "' ORDER BY level ASC")->rows as $p) {
                $this->db->query("INSERT INTO `" . DB_PREFIX . "category_path` SET category_id = '" . $cid . "', path_id = '" . (int) $p['path_id'] . "', level = '" . $level++ . "'");
            }
            $this->db->query("REPLACE INTO `" . DB_PREFIX . "category_path` SET category_id = '" . $cid . "', path_id = '" . $cid . "', level = '" . $level . "'");
            $this->repairCategories($cid);
        }
    }

    // Наявна категорія з тією ж назвою під тим самим батьком (ще не прив'язана)
    private function findCategory($it, $parent)
    {
        foreach ((array) $it['languages'] as $l) {
            $lid = isset($l['code']) ? $this->langId($l['code']) : 0;
            if (!$lid || empty($l['name'])) {
                continue;
            }
            $q = $this->db->query(
                "SELECT c.category_id FROM `" . DB_PREFIX . "category` c
                   JOIN `" . DB_PREFIX . "category_description` cd ON cd.category_id = c.category_id AND cd.language_id = '" . $lid . "'
                  WHERE c.parent_id = '" . (int) $parent . "' AND cd.name = '" . $this->db->escape(htmlspecialchars(trim($l['name']), ENT_QUOTES, 'UTF-8')) . "'
                    AND c.category_id NOT IN (SELECT oc_id FROM `" . DB_PREFIX . "growthcontour_map` WHERE entity = 'category')
                  LIMIT 1"
            );
            if ($q->num_rows) {
                return (int) $q->row['category_id'];
            }
        }
        return 0;
    }

    private function categoryExists($id)
    {
        return (bool) $this->db->query("SELECT 1 FROM `" . DB_PREFIX . "category` WHERE category_id = '" . (int) $id . "'")->num_rows;
    }

    // id сайту для категорії CRM (з урахуванням режиму зіставлення)
    private function categoryOcId($crm, $match)
    {
        if ($match === 'id') {
            return $this->categoryExists($crm) ? (int) $crm : 0;
        }
        return $this->mapGet('category', $crm);
    }

    /**
     * categories.list { after, limit } — дерево категорій сайту для зіставлення в системі:
     * items [{ id, parent, name }] мовою адмінки, next, more.
     */
    public function apiCategoriesList($d)
    {
        $after = isset($d['after']) ? (int) $d['after'] : 0;
        $limit = isset($d['limit']) ? max(1, min(2000, (int) $d['limit'])) : 1000;
        $lid = (int) $this->config->get('config_language_id');
        $rows = $this->db->query(
            "SELECT c.category_id, c.parent_id, c.status, COALESCE(NULLIF(cd.name, ''), CONCAT('#', c.category_id)) AS name
               FROM `" . DB_PREFIX . "category` c
               LEFT JOIN `" . DB_PREFIX . "category_description` cd ON cd.category_id = c.category_id AND cd.language_id = '" . $lid . "'
              WHERE c.category_id > '" . $after . "'
              ORDER BY c.category_id LIMIT " . $limit
        )->rows;
        $out = array();
        foreach ($rows as $r) {
            $out[] = array('id' => (int) $r['category_id'], 'parent' => (int) $r['parent_id'], 'name' => html_entity_decode($r['name'], ENT_QUOTES, 'UTF-8'), 'status' => (int) $r['status']);
        }
        return array('items' => $out, 'next' => $out ? $out[count($out) - 1]['id'] : $after, 'more' => count($out) === $limit);
    }

    // categories.sync { job, items:[{crm_id, parent_crm_id, status, deleted, top, column, sort_order, image, languages}] }
    public function apiCategoriesSync($d)
    {
        $this->mapTable();
        $this->jobTable();
        $items = (isset($d['items']) && is_array($d['items'])) ? array_slice($d['items'], 0, 1000) : array();
        $o = array(
            'match' => $this->opt('categories', 'match', 'name'),
            'create' => (bool) $this->opt('categories', 'create', 0),
            'update' => (bool) $this->opt('categories', 'update', 0),
            'force' => $this->jobForce('categories'),
            'job' => isset($d['job']) ? (string) $d['job'] : '',
        );

        $results = array();
        $tree_changed = false;
        $pending = $items;

        // Кілька проходів: дитина чекає, поки з'явиться батько
        for ($pass = 0; $pass < 20 && $pending; $pass++) {
            $next = array();
            foreach ($pending as $it) {
                $pcrm = isset($it['parent_crm_id']) ? (int) $it['parent_crm_id'] : 0;
                $parent = $pcrm ? $this->categoryOcId($pcrm, $o['match']) : 0;
                // Корінь у системі, але на сайті — під указаною категорією сайту (режим «Змішаний»)
                if (!$pcrm && !empty($it['parent_site_id']) && $this->categoryExists((int) $it['parent_site_id'])) {
                    $parent = (int) $it['parent_site_id'];
                }
                if ($pcrm && !$parent) {
                    $next[] = $it;
                    continue;
                }
                $results[] = $this->syncCategory($it, $parent, $o, $tree_changed);
            }
            if (count($next) === count($pending)) {
                foreach ($next as $it) {
                    $results[] = array('ref' => (int) $it['crm_id'], 'ok' => false, 'error' => 'parent category is not on the site');
                }
                break;
            }
            $pending = $next;
        }

        if ($tree_changed) {
            $this->repairCategories(0);
        }
        $this->cache->delete('category');

        return array('results' => $results);
    }

    private function syncCategory($it, $parent, $o, &$tree_changed)
    {
        $crm = (int) $it['crm_id'];
        $hash = md5(json_encode($it) . '|' . $parent);
        $m = $this->mapRow('category', $crm);

        if ($o['match'] === 'id') {
            $cid = $this->categoryExists($crm) ? $crm : 0;
        } else {
            $cid = $m ? (int) $m['oc_id'] : 0;
            if ($cid && !$this->categoryExists($cid)) {
                $cid = 0;
            }
            if (!$cid && $o['match'] === 'name') {
                $cid = $this->findCategory($it, $parent);
            }
        }

        if ($cid && $m && (int) $m['oc_id'] === $cid && $m['hash'] === $hash && !$o['force']) {
            $this->mapTouch('category', $crm, $o['job']);
            return array('ref' => $crm, 'ok' => true, 'id' => (string) $cid, 'action' => 'unchanged');
        }
        if ($cid && !$o['update']) {
            $this->mapSet('category', $crm, $cid, '', $o['job']);
            return array('ref' => $crm, 'ok' => true, 'id' => (string) $cid, 'action' => 'skipped');
        }
        if (!$cid && (!empty($it['deleted']) || !$o['create'])) {
            return array('ref' => $crm, 'ok' => true, 'action' => 'skipped');
        }

        $fields = "parent_id = '" . (int) $parent . "', `top` = '" . (!empty($it['top']) && !$parent ? 1 : 0) . "', `column` = '" . max(1, (int) $it['column']) . "',
                   sort_order = '" . (int) $it['sort_order'] . "', status = '" . (!empty($it['status']) ? 1 : 0) . "', date_modified = NOW()";

        if ($cid) {
            $action = 'updated';
            $old = $this->db->query("SELECT parent_id FROM `" . DB_PREFIX . "category` WHERE category_id = '" . $cid . "'")->row;
            if ((int) $old['parent_id'] !== (int) $parent) {
                $tree_changed = true;
            }
            $this->db->query("UPDATE `" . DB_PREFIX . "category` SET " . $fields . " WHERE category_id = '" . $cid . "'");
        } else {
            $action = 'created';
            $idset = $o['match'] === 'id' ? "category_id = '" . $crm . "', " : "";
            $this->db->query("INSERT INTO `" . DB_PREFIX . "category` SET " . $idset . "image = '', " . $fields . ", date_added = NOW()");
            $cid = $o['match'] === 'id' ? $crm : (int) $this->db->getLastId();
            foreach ($this->storeIds() as $sid) {
                $this->db->query("INSERT IGNORE INTO `" . DB_PREFIX . "category_to_store` SET category_id = '" . $cid . "', store_id = '" . $sid . "'");
            }
            $tree_changed = true;
        }

        $cols = array('name', 'description', 'meta_title', 'meta_description', 'meta_keyword');
        $h1 = $this->hasCol('category_description', 'meta_h1');
        if ($h1) {
            $cols[] = 'meta_h1';
        }
        foreach ((array) $it['languages'] as $l) {
            $lid = isset($l['code']) ? $this->langId($l['code']) : 0;
            if (!$lid) {
                continue;
            }
            $f = $this->descFields($l, true);
            if ($h1) {
                $f['meta_h1'] = isset($l['h1']) ? $l['h1'] : null;
            }
            $this->upsertDesc('category_description', 'category_id', $cid, $lid, $f, $cols);
            if (!empty($l['slug'])) {
                $this->setSeoUrl('category_id=' . $cid, $lid, $l['slug']);
            }
        }

        if (!empty($it['image'])) {
            $img = $this->fetchImage($it['image'], 'categories');
            if ($img) {
                $this->db->query("UPDATE `" . DB_PREFIX . "category` SET image = '" . $this->db->escape($img) . "' WHERE category_id = '" . $cid . "'");
            }
        }

        $this->mapSet('category', $crm, $cid, $hash, $o['job']);
        return array('ref' => $crm, 'ok' => true, 'id' => (string) $cid, 'action' => $action);
    }

    // ─── ТОВАРИ ───

    private function findManufacturer($name)
    {
        $name = trim((string) $name);
        if ($name === '') {
            return 0;
        }
        $n = $this->db->escape(htmlspecialchars($name, ENT_QUOTES, 'UTF-8'));
        $q = $this->db->query("SELECT manufacturer_id FROM `" . DB_PREFIX . "manufacturer` WHERE name = '" . $n . "' LIMIT 1");
        if ($q->num_rows) {
            return (int) $q->row['manufacturer_id'];
        }
        $this->db->query("INSERT INTO `" . DB_PREFIX . "manufacturer` SET name = '" . $n . "', image = '', sort_order = '0'");
        $id = (int) $this->db->getLastId();
        foreach ($this->storeIds() as $sid) {
            $this->db->query("INSERT IGNORE INTO `" . DB_PREFIX . "manufacturer_to_store` SET manufacturer_id = '" . $id . "', store_id = '" . $sid . "'");
        }
        return $id;
    }

    private function findProductBySku($sku)
    {
        $sku = trim((string) $sku);
        if ($sku === '') {
            return 0;
        }
        $col = $this->config->get('module_growthcontour_sync_products_sku') === 'sku' ? 'sku' : 'model';
        $q = $this->db->query(
            "SELECT product_id FROM `" . DB_PREFIX . "product` WHERE `" . $col . "` = '" . $this->db->escape($sku) . "'
               AND product_id NOT IN (SELECT oc_id FROM `" . DB_PREFIX . "growthcontour_map` WHERE entity = 'product') LIMIT 1"
        );
        return $q->num_rows ? (int) $q->row['product_id'] : 0;
    }

    private function productExists($id)
    {
        return (bool) $this->db->query("SELECT 1 FROM `" . DB_PREFIX . "product` WHERE product_id = '" . (int) $id . "'")->num_rows;
    }

    // Категорії товару: id CRM → id сайту, плюс категорії сайту напряму (site_categories). Нічого не зіставилось — не чіпаємо
    private function setProductCategories($pid, $crm_cats, $crm_main, $match, $site_cats = array(), $site_main = 0)
    {
        $oc = array();
        foreach (array_unique(array_map('intval', (array) $crm_cats)) as $c) {
            $id = $this->categoryOcId($c, $match);
            if ($id) {
                $oc[] = $id;
            }
        }
        // Категорії сайту, вибрані в системі (режими «Дерево сайту» / «Змішаний») — лише наявні на сайті
        foreach (array_unique(array_map('intval', (array) $site_cats)) as $c) {
            if ($c > 0 && $this->categoryExists($c)) {
                $oc[] = $c;
            }
        }
        $oc = array_values(array_unique($oc));
        if (!$oc) {
            return;
        }
        $main = ((int) $site_main && in_array((int) $site_main, $oc, true)) ? (int) $site_main : ($crm_main ? $this->categoryOcId((int) $crm_main, $match) : 0);
        $has_main = $this->hasCol('product_to_category', 'main_category');
        $this->db->query("DELETE FROM `" . DB_PREFIX . "product_to_category` WHERE product_id = '" . (int) $pid . "'");
        foreach ($oc as $c) {
            $this->db->query(
                "INSERT IGNORE INTO `" . DB_PREFIX . "product_to_category` SET product_id = '" . (int) $pid . "', category_id = '" . $c . "'"
                . ($has_main ? ", main_category = '" . ($c === $main ? 1 : 0) . "'" : "")
            );
        }
    }

    // products.sync { job, items:[{crm_id, sku, ean, price, track, quantity, status, deleted, weight, brand, categories, main_category, images, languages}] }
    /**
     * products.state { after, limit, reset_modified: [crm_id] } — стан товарів на сайті для перевірки розбіжностей:
     * які товари системи є на сайті, чи змінювали їх вручну після обміну. reset_modified — скинути відбиток,
     * щоб наступний обмін перезаписав ручні правки.
     */
    public function apiProductsState($d)
    {
        $this->mapTable();
        if (!empty($d['reset_modified']) && is_array($d['reset_modified'])) {
            $ids = array_slice(array_filter(array_map('intval', $d['reset_modified'])), 0, 5000);
            if ($ids) {
                $this->db->query("UPDATE `" . DB_PREFIX . "growthcontour_map` SET hash = NULL WHERE entity = 'product' AND crm_id IN (" . implode(',', $ids) . ")");
            }
        }
        $after = isset($d['after']) ? (int) $d['after'] : 0;
        $limit = isset($d['limit']) ? max(1, min(2000, (int) $d['limit'])) : 1000;
        $rows = $this->db->query(
            "SELECT m.crm_id, m.oc_id, p.product_id IS NOT NULL AS present, p.status,
                    (p.date_modified > m.date_modified + INTERVAL 5 SECOND) AS modified
               FROM `" . DB_PREFIX . "growthcontour_map` m
               LEFT JOIN `" . DB_PREFIX . "product` p ON p.product_id = m.oc_id
              WHERE m.entity = 'product' AND m.crm_id > '" . $after . "'
              ORDER BY m.crm_id LIMIT " . $limit
        )->rows;
        $out = array();
        foreach ($rows as $r) {
            $out[] = array('crm_id' => (int) $r['crm_id'], 'oc_id' => (int) $r['oc_id'], 'present' => (int) $r['present'], 'status' => (int) $r['status'], 'modified' => (int) $r['modified']);
        }
        $res = array('items' => $out, 'next' => $out ? $out[count($out) - 1]['crm_id'] : $after, 'more' => count($out) === $limit);
        if (!$after) {
            // Товари сайту, не пов'язані із системою (створені вручну або не зіставлені)
            $res['unlinked'] = (int) $this->db->query(
                "SELECT COUNT(*) AS n FROM `" . DB_PREFIX . "product` p LEFT JOIN `" . DB_PREFIX . "growthcontour_map` m ON m.entity = 'product' AND m.oc_id = p.product_id WHERE m.crm_id IS NULL"
            )->row['n'];
        }
        return $res;
    }

    public function apiProductsSync($d)
    {
        $this->mapTable();
        $this->jobTable();
        $items = (isset($d['items']) && is_array($d['items'])) ? array_slice($d['items'], 0, 500) : array();
        $allowed = $this->config->get('module_growthcontour_sync_products_fields');
        if (!is_array($allowed)) {
            $allowed = array();
        }
        $o = array(
            'match' => $this->opt('products', 'match', 'sku'),
            'cat_match' => $this->opt('categories', 'match', 'name'),
            'create' => (bool) $this->opt('products', 'create', 0),
            'update' => (bool) $this->opt('products', 'update', 0),
            'force' => $this->jobForce('products'),
            'job' => isset($d['job']) ? (string) $d['job'] : '',
            'can' => array_flip(array_filter($allowed)),
        );

        $results = array();
        foreach ($items as $it) {
            try {
                $results[] = $this->syncProduct($it, $o);
            } catch (Exception $e) {
                $results[] = array('ref' => (int) $it['crm_id'], 'ok' => false, 'error' => mb_substr($e->getMessage(), 0, 300));
            }
        }
        $this->cache->delete('product');

        return array('results' => $results);
    }

    private function syncProduct($it, $o)
    {
        $crm = (int) $it['crm_id'];
        $hash = md5(json_encode($it));
        $can = $o['can'];
        $m = $this->mapRow('product', $crm);

        if ($o['match'] === 'id') {
            $pid = $this->productExists($crm) ? $crm : 0;
        } else {
            $pid = $m ? (int) $m['oc_id'] : 0;
            if ($pid && !$this->productExists($pid)) {
                $pid = 0;
            }
            if (!$pid && $o['match'] === 'sku') {
                $pid = $this->findProductBySku(isset($it['sku']) ? $it['sku'] : '');
            }
        }

        if ($pid && $m && (int) $m['oc_id'] === $pid && $m['hash'] === $hash && !$o['force']) {
            $this->mapTouch('product', $crm, $o['job']);
            return array('ref' => $crm, 'ok' => true, 'id' => (string) $pid, 'action' => 'unchanged');
        }
        $new = !$pid;
        if ($new && (!empty($it['deleted']) || !$o['create'])) {
            return array('ref' => $crm, 'ok' => true, 'action' => 'skipped');
        }
        if (!$new && !$o['update']) {
            $this->mapSet('product', $crm, $pid, '', $o['job']);
            return array('ref' => $crm, 'ok' => true, 'id' => (string) $pid, 'action' => 'skipped');
        }

        // Новий / повний перезапис — усе; оновлення — лише дозволені поля
        $all = $new || $o['force'];
        $content = $all || isset($can['content']);
        $sku = trim((string) (isset($it['sku']) ? $it['sku'] : ''));
        $f = array();
        if ($all || isset($can['price'])) {
            $f['price'] = (float) $it['price'];
        }
        if ($all || isset($can['quantity'])) {
            $f['quantity'] = (int) $it['quantity'];
            $f['subtract'] = !empty($it['track']) ? 1 : 0;
        }
        if ($all || isset($can['status'])) {
            $f['status'] = !empty($it['status']) ? 1 : 0;
        }
        if ($content) {
            $f['model'] = $sku !== '' ? $sku : 'CRM-' . $crm;
            $f['sku'] = $sku;
            $f['ean'] = (string) (isset($it['ean']) ? $it['ean'] : '');
            $f['weight'] = (float) (isset($it['weight']) ? $it['weight'] : 0);
            $f['manufacturer_id'] = $this->findManufacturer(isset($it['brand']) ? $it['brand'] : '');
        }
        if ($new) {
            if ($o['match'] === 'id') {
                $f['product_id'] = $crm;
            }
            $f += array(
                'upc' => '',
                'jan' => '',
                'isbn' => '',
                'mpn' => '',
                'location' => '',
                'minimum' => 1,
                'stock_status_id' => (int) $this->config->get('config_stock_status_id'),
                'shipping' => 1,
                'points' => 0,
                'length' => 0,
                'width' => 0,
                'height' => 0,
                'weight_class_id' => (int) $this->config->get('config_weight_class_id'),
                'length_class_id' => (int) $this->config->get('config_length_class_id'),
                'tax_class_id' => 0,
                'sort_order' => 0,
                'image' => '',
            );
        }

        $set = array();
        foreach ($f as $col => $v) {
            $set[] = "`" . $col . "` = '" . $this->db->escape((string) $v) . "'";
        }
        if ($new) {
            $this->db->query("INSERT INTO `" . DB_PREFIX . "product` SET " . implode(', ', $set) . ", date_available = CURDATE(), date_added = NOW(), date_modified = NOW()");
            $pid = $o['match'] === 'id' ? $crm : (int) $this->db->getLastId();
            foreach ($this->storeIds() as $sid) {
                $this->db->query("INSERT IGNORE INTO `" . DB_PREFIX . "product_to_store` SET product_id = '" . $pid . "', store_id = '" . $sid . "'");
            }
        } elseif ($set) {
            $this->db->query("UPDATE `" . DB_PREFIX . "product` SET " . implode(', ', $set) . ", date_modified = NOW() WHERE product_id = '" . $pid . "'");
        }

        if ($content) {
            $cols = array('name', 'description', 'tag', 'meta_title', 'meta_description', 'meta_keyword');
            $h1 = $this->hasCol('product_description', 'meta_h1');
            if ($h1) {
                $cols[] = 'meta_h1';
            }
            foreach ((array) $it['languages'] as $l) {
                $lid = isset($l['code']) ? $this->langId($l['code']) : 0;
                if (!$lid) {
                    continue;
                }
                $df = $this->descFields($l, true);
                if ($h1) {
                    $df['meta_h1'] = isset($l['h1']) ? $l['h1'] : null;
                }
                $this->upsertDesc('product_description', 'product_id', $pid, $lid, $df, $cols);
                if (!empty($l['slug'])) {
                    $this->setSeoUrl('product_id=' . $pid, $lid, $l['slug']);
                }
            }

            $imgs = array();
            foreach ((array) $it['images'] as $u) {
                $r = $this->fetchImage($u, 'products');
                if ($r) {
                    $imgs[] = $r;
                }
            }
            if ($imgs) {
                $this->db->query("UPDATE `" . DB_PREFIX . "product` SET image = '" . $this->db->escape($imgs[0]) . "' WHERE product_id = '" . $pid . "'");
                $this->db->query("DELETE FROM `" . DB_PREFIX . "product_image` WHERE product_id = '" . $pid . "'");
                foreach (array_slice($imgs, 1) as $i => $img) {
                    $this->db->query("INSERT INTO `" . DB_PREFIX . "product_image` SET product_id = '" . $pid . "', image = '" . $this->db->escape($img) . "', sort_order = '" . (int) $i . "'");
                }
            }
        }

        if ($all || isset($can['attributes'])) {
            $this->setProductAttributes($pid, isset($it['attributes']) ? $it['attributes'] : array());
        }

        if ($all || isset($can['options'])) {
            $this->setProductOptions($pid, isset($it['options']) ? $it['options'] : array(), (float) $it['price']);
        }

        if ($all || isset($can['categories'])) {
            $this->setProductCategories(
                $pid,
                isset($it['categories']) ? $it['categories'] : array(),
                isset($it['main_category']) ? $it['main_category'] : 0,
                $o['cat_match'],
                isset($it['site_categories']) ? $it['site_categories'] : array(),
                isset($it['site_main_category']) ? $it['site_main_category'] : 0
            );
        }

        $this->mapSet('product', $crm, $pid, $hash, $o['job']);
        return array('ref' => $crm, 'ok' => true, 'id' => (string) $pid, 'action' => $new ? 'created' : 'updated');
    }

    // ─── ХАРАКТЕРИСТИКИ (CRM — головна система) ───

    private $gc_attr_ids = array();
    private $gc_group_ids = array();

    // Наявний запис довідника за назвою (будь-якою мовою), ще не прив'язаний
    private function findByName($descTable, $idCol, $names, $mapKey, $extra = '')
    {
        foreach ((array) $names as $n) {
            $lid = isset($n['code']) ? $this->langId($n['code']) : 0;
            if (!$lid || trim((string) $n['name']) === '') {
                continue;
            }
            $q = $this->db->query(
                "SELECT `" . $idCol . "` AS id FROM `" . DB_PREFIX . $descTable . "`
                  WHERE language_id = '" . $lid . "' AND name = '" . $this->db->escape(htmlspecialchars(trim($n['name']), ENT_QUOTES, 'UTF-8')) . "'
                    AND `" . $idCol . "` NOT IN (SELECT oc_id FROM `" . DB_PREFIX . "growthcontour_map` WHERE entity = '" . $mapKey . "') " . $extra . "
                  LIMIT 1"
            );
            if ($q->num_rows) {
                return (int) $q->row['id'];
            }
        }
        return 0;
    }

    private function ensureAttrGroup($g, $match)
    {
        $crm = isset($g['crm_id']) ? (int) $g['crm_id'] : 0;
        if (isset($this->gc_group_ids[$crm])) {
            return $this->gc_group_ids[$crm];
        }
        $names = !empty($g['names']) ? $g['names'] : array(array('code' => (string) $this->config->get('config_language'), 'name' => 'Характеристики'));
        $sort = isset($g['sort_order']) ? (int) $g['sort_order'] : 0;
        $hash = md5(json_encode(array($sort, $names)));
        $by_id = $match === 'id' && $crm;

        $m = $this->mapRow('attribute_group', $crm);
        if ($by_id) {
            $gid = $this->db->query("SELECT 1 FROM `" . DB_PREFIX . "attribute_group` WHERE attribute_group_id = '" . $crm . "'")->num_rows ? $crm : 0;
        } else {
            $gid = $m ? (int) $m['oc_id'] : 0;
            if ($gid && !$this->db->query("SELECT 1 FROM `" . DB_PREFIX . "attribute_group` WHERE attribute_group_id = '" . $gid . "'")->num_rows) {
                $gid = 0;
            }
            if (!$gid && $match !== 'map') {
                $gid = $this->findByName('attribute_group_description', 'attribute_group_id', $names, 'attribute_group');
            }
        }

        if (!$gid || !$m || (int) $m['oc_id'] !== $gid || $m['hash'] !== $hash) {
            if ($gid) {
                $this->db->query("UPDATE `" . DB_PREFIX . "attribute_group` SET sort_order = '" . $sort . "' WHERE attribute_group_id = '" . $gid . "'");
            } else {
                $this->db->query("INSERT INTO `" . DB_PREFIX . "attribute_group` SET " . ($by_id ? "attribute_group_id = '" . $crm . "', " : "") . "sort_order = '" . $sort . "'");
                $gid = $by_id ? $crm : (int) $this->db->getLastId();
            }
            $this->writeNames('attribute_group_description', 'attribute_group_id', $gid, $names);
            $this->mapSet('attribute_group', $crm, $gid, $hash, null);
        }
        return $this->gc_group_ids[$crm] = $gid;
    }

    // Характеристика довідника. $o: match, create, update, force, job (null — побічно з товару)
    private function ensureAttribute($a, $o, &$action)
    {
        $crm = (int) $a['crm_id'];
        if (isset($this->gc_attr_ids[$crm])) {
            $action = 'unchanged';
            return $this->gc_attr_ids[$crm];
        }
        $gid = $this->ensureAttrGroup(isset($a['group']) ? $a['group'] : array(), $o['match']);
        $sort = isset($a['sort_order']) ? (int) $a['sort_order'] : 0;
        $hash = md5(json_encode(array($gid, $sort, $a['names'])));

        $m = $this->mapRow('attribute', $crm);
        if ($o['match'] === 'id') {
            $aid = $this->db->query("SELECT 1 FROM `" . DB_PREFIX . "attribute` WHERE attribute_id = '" . $crm . "'")->num_rows ? $crm : 0;
        } else {
            $aid = $m ? (int) $m['oc_id'] : 0;
            if ($aid && !$this->db->query("SELECT 1 FROM `" . DB_PREFIX . "attribute` WHERE attribute_id = '" . $aid . "'")->num_rows) {
                $aid = 0;
            }
            if (!$aid && $o['match'] === 'name') {
                $aid = $this->findByName(
                    'attribute_description',
                    'attribute_id',
                    $a['names'],
                    'attribute',
                    "AND attribute_id IN (SELECT attribute_id FROM `" . DB_PREFIX . "attribute` WHERE attribute_group_id = '" . (int) $gid . "')"
                );
            }
        }

        if ($aid && $m && (int) $m['oc_id'] === $aid && $m['hash'] === $hash && !$o['force']) {
            if ($o['job'] !== null) {
                $this->mapTouch('attribute', $crm, $o['job']);
            }
            $action = 'unchanged';
        } elseif ($aid && !$o['update']) {
            $this->mapSet('attribute', $crm, $aid, '', $o['job']);
            $action = 'skipped';
        } elseif (!$aid && !$o['create']) {
            $action = 'skipped';
            return 0;
        } else {
            if ($aid) {
                $action = 'updated';
                $this->db->query("UPDATE `" . DB_PREFIX . "attribute` SET attribute_group_id = '" . (int) $gid . "', sort_order = '" . $sort . "' WHERE attribute_id = '" . $aid . "'");
            } else {
                $action = 'created';
                $by_id = $o['match'] === 'id';
                $this->db->query("INSERT INTO `" . DB_PREFIX . "attribute` SET " . ($by_id ? "attribute_id = '" . $crm . "', " : "") . "attribute_group_id = '" . (int) $gid . "', sort_order = '" . $sort . "'");
                $aid = $by_id ? $crm : (int) $this->db->getLastId();
            }
            $this->writeNames('attribute_description', 'attribute_id', $aid, $a['names']);
            $this->mapSet('attribute', $crm, $aid, $hash, $o['job']);
        }
        return $this->gc_attr_ids[$crm] = $aid;
    }

    // attributes.sync { job, items:[{crm_id, sort_order, group:{crm_id, sort_order, names}, names}] }
    public function apiAttributesSync($d)
    {
        $this->mapTable();
        $this->jobTable();
        $items = (isset($d['items']) && is_array($d['items'])) ? array_slice($d['items'], 0, 1000) : array();
        $flag = $this->config->get('module_growthcontour_sync_attributes_create');
        $o = array(
            'match' => $this->opt('attributes', 'match', 'name'),
            'create' => (bool) $flag,
            'update' => (bool) $this->opt('attributes', 'update', 0),
            'force' => $this->jobForce('attributes'),
            'job' => isset($d['job']) ? (string) $d['job'] : '',
        );
        $results = array();
        foreach ($items as $a) {
            $action = '';
            $id = $this->ensureAttribute($a, $o, $action);
            $results[] = array('ref' => (int) $a['crm_id'], 'ok' => true, 'id' => (string) $id, 'action' => $action);
        }
        return array('results' => $results);
    }

    // Характеристики товару: повна заміна. Відсутні в довіднику створюються
    private function setProductAttributes($pid, $attrs)
    {
        $pid = (int) $pid;
        $this->db->query("DELETE FROM `" . DB_PREFIX . "product_attribute` WHERE product_id = '" . $pid . "'");
        $o = array('match' => $this->opt('attributes', 'match', 'name'), 'create' => true, 'update' => true, 'force' => false, 'job' => null);

        foreach ((array) $attrs as $a) {
            if (empty($a['crm_id'])) {
                continue;
            }
            $action = '';
            $aid = $this->ensureAttribute($a, $o, $action);
            if (!$aid) {
                continue;
            }
            foreach ((array) $a['values'] as $v) {
                $lid = isset($v['code']) ? $this->langId($v['code']) : 0;
                if ($lid && trim((string) $v['text']) !== '') {
                    $this->db->query(
                        "REPLACE INTO `" . DB_PREFIX . "product_attribute` SET product_id = '" . $pid . "', attribute_id = '" . $aid . "', language_id = '" . $lid . "',
                         `text` = '" . $this->db->escape(htmlspecialchars((string) $v['text'], ENT_QUOTES, 'UTF-8')) . "'"
                    );
                }
            }
        }
    }

    // ─── ВІДГУКИ (CRM → сайт) ───

    private function productOcId($crm)
    {
        if ($this->opt('products', 'match', 'sku') === 'id') {
            return $this->productExists($crm) ? (int) $crm : 0;
        }
        return $this->mapGet('product', $crm);
    }

    // reviews.sync { job, items:[{crm_id, product_crm_id, oc_id, own, author, text, rating, status, deleted, date_add}] }
    // own = відгук родом із цього сайту (оригінал). Інакше відгук опубліковано із системи: deleted — прибрати із сайту
    public function apiReviewsSync($d)
    {
        $this->mapTable();
        $this->jobTable();
        $items = (isset($d['items']) && is_array($d['items'])) ? array_slice($d['items'], 0, 1000) : array();
        $o = array(
            'match' => $this->opt('reviews', 'match', 'map'),
            'create' => (bool) $this->opt('reviews', 'create', 0),
            'update' => (bool) $this->opt('reviews', 'update', 0),
            'force' => $this->jobForce('reviews'),
            'job' => isset($d['job']) ? (string) $d['job'] : '',
        );
        $results = array();
        foreach ($items as $it) {
            $results[] = $this->syncReview($it, $o);
        }
        $this->cache->delete('product');
        return array('results' => $results);
    }

    private function syncReview($it, $o)
    {
        $crm = (int) $it['crm_id'];
        $hash = md5(json_encode($it));
        $exists = function ($id) {
            return $id && $this->db->query("SELECT 1 FROM `" . DB_PREFIX . "review` WHERE review_id = '" . (int) $id . "'")->num_rows;
        };

        $m = $this->mapRow('review', $crm);
        if ($o['match'] === 'id') {
            $rid = $exists($crm) ? $crm : 0;
        } else {
            $rid = $m ? (int) $m['oc_id'] : 0;
            if ($rid && !$exists($rid)) {
                $rid = 0;
            }
        }
        // Відгук прийшов з цього сайту — це той самий запис
        if (!$rid && !empty($it['oc_id']) && $exists((int) $it['oc_id'])) {
            $rid = (int) $it['oc_id'];
        }

        // Відгук системи зняли з публікації на цьому сайті — видаляємо його з сайту (оригінали сайту лише вимикаються)
        if (!empty($it['deleted']) && empty($it['own'])) {
            if ($rid) {
                $this->db->query("DELETE FROM `" . DB_PREFIX . "review` WHERE review_id = '" . $rid . "'");
            }
            $this->db->query("DELETE FROM `" . DB_PREFIX . "growthcontour_map` WHERE entity = 'review' AND crm_id = '" . $crm . "'");
            return array('ref' => $crm, 'crm_id' => $crm, 'ok' => true, 'action' => $rid ? 'updated' : 'skipped');
        }

        if ($rid && $m && (int) $m['oc_id'] === $rid && $m['hash'] === $hash && !$o['force']) {
            $this->mapTouch('review', $crm, $o['job']);
            return array('ref' => $crm, 'crm_id' => $crm, 'ok' => true, 'id' => (string) $rid, 'action' => 'unchanged');
        }
        if ($rid && !$o['update']) {
            $this->mapSet('review', $crm, $rid, '', $o['job']);
            return array('ref' => $crm, 'crm_id' => $crm, 'ok' => true, 'id' => (string) $rid, 'action' => 'skipped');
        }
        if (!$rid && (!empty($it['deleted']) || trim((string) $it['text']) === '')) {
            return array('ref' => $crm, 'crm_id' => $crm, 'ok' => true, 'action' => 'skipped');
        }
        // Відгук, який система публікує на цей сайт, а створення вимкнено — помилка, щоб у системі було видно причину
        if (!$rid && !$o['create']) {
            return array('ref' => $crm, 'crm_id' => $crm, 'ok' => false, 'error' => 'creating reviews is disabled in the shop module (Catalog → Reviews → Create New)');
        }

        $pid = $this->productOcId((int) $it['product_crm_id']);
        if (!$pid) {
            return array('ref' => $crm, 'crm_id' => $crm, 'ok' => false, 'error' => 'product #' . (int) $it['product_crm_id'] . ' is not on the site (sync products first)');
        }

        $date = preg_match('/^\d{4}-\d{2}-\d{2}/', (string) $it['date_add']) ? substr($it['date_add'], 0, 19) : date('Y-m-d H:i:s');
        $set = "product_id = '" . $pid . "',
                author = '" . $this->db->escape(mb_substr(htmlspecialchars((string) $it['author'], ENT_QUOTES, 'UTF-8'), 0, 64)) . "',
                `text` = '" . $this->db->escape(htmlspecialchars((string) $it['text'], ENT_QUOTES, 'UTF-8')) . "',
                rating = '" . max(1, min(5, (int) $it['rating'])) . "', status = '" . (!empty($it['status']) ? 1 : 0) . "',
                date_added = '" . $this->db->escape($date) . "', date_modified = NOW()";

        if ($rid) {
            $this->db->query("UPDATE `" . DB_PREFIX . "review` SET " . $set . " WHERE review_id = '" . $rid . "'");
            $action = 'updated';
        } else {
            $by_id = $o['match'] === 'id';
            $this->db->query("INSERT INTO `" . DB_PREFIX . "review` SET " . ($by_id ? "review_id = '" . $crm . "', " : "") . "customer_id = '0', " . $set);
            $rid = $by_id ? $crm : (int) $this->db->getLastId();
            $action = 'created';
        }

        $this->mapSet('review', $crm, $rid, $hash, $o['job']);
        return array('ref' => $crm, 'crm_id' => $crm, 'ok' => true, 'id' => (string) $rid, 'action' => $action);
    }

    // ─── КЛІЄНТИ (CRM → сайт) ───

    private $gc_countries = array();

    private function countryId($iso2)
    {
        $iso2 = strtoupper(trim((string) $iso2));
        if ($iso2 === '') {
            return (int) $this->config->get('config_country_id');
        }
        if (!isset($this->gc_countries[$iso2])) {
            $q = $this->db->query("SELECT country_id FROM `" . DB_PREFIX . "country` WHERE iso_code_2 = '" . $this->db->escape($iso2) . "' LIMIT 1");
            $this->gc_countries[$iso2] = $q->num_rows ? (int) $q->row['country_id'] : (int) $this->config->get('config_country_id');
        }
        return $this->gc_countries[$iso2];
    }

    private function zoneId($country_id, $region)
    {
        $region = trim((string) $region);
        if ($region === '') {
            return 0;
        }
        $q = $this->db->query(
            "SELECT zone_id FROM `" . DB_PREFIX . "zone` WHERE country_id = '" . (int) $country_id . "'
               AND (name = '" . $this->db->escape($region) . "' OR name LIKE '" . $this->db->escape($region) . "%') LIMIT 1"
        );
        return $q->num_rows ? (int) $q->row['zone_id'] : 0;
    }

    // customers.sync { job, items:[{crm_id, firstname, lastname, email, telephone, newsletter, status, deleted, addresses:[...]}] }
    public function apiCustomersSync($d)
    {
        $this->mapTable();
        $this->jobTable();
        $items = (isset($d['items']) && is_array($d['items'])) ? array_slice($d['items'], 0, 1000) : array();
        $o = array(
            'match' => $this->opt('customers', 'match', 'email'),
            'create' => (bool) $this->opt('customers', 'create', 0),
            'update' => (bool) $this->opt('customers', 'update', 0),
            'force' => $this->jobForce('customers'),
            'job' => isset($d['job']) ? (string) $d['job'] : '',
        );
        $results = array();
        foreach ($items as $it) {
            try {
                $results[] = $this->syncCustomer($it, $o);
            } catch (Exception $e) {
                $results[] = array('ref' => (int) $it['crm_id'], 'ok' => false, 'error' => mb_substr($e->getMessage(), 0, 300));
            }
        }
        return array('results' => $results);
    }

    private function syncCustomer($it, $o)
    {
        $crm = (int) $it['crm_id'];
        $hash = md5(json_encode($it));
        $email = mb_substr(trim((string) $it['email']), 0, 96);
        $exists = function ($id) {
            return $id && $this->db->query("SELECT 1 FROM `" . DB_PREFIX . "customer` WHERE customer_id = '" . (int) $id . "'")->num_rows;
        };

        $m = $this->mapRow('customer', $crm);
        if ($o['match'] === 'id') {
            $cid = $exists($crm) ? $crm : 0;
        } else {
            $cid = $m ? (int) $m['oc_id'] : 0;
            if ($cid && !$exists($cid)) {
                $cid = 0;
            }
            // Покупець уже є на сайті (CRM знає його ID із замовлень) — це той самий запис
            if (!$cid && !empty($it['oc_id']) && $exists((int) $it['oc_id'])) {
                $cid = (int) $it['oc_id'];
            }
            if (!$cid && $o['match'] === 'email' && $email !== '') {
                $q = $this->db->query(
                    "SELECT customer_id FROM `" . DB_PREFIX . "customer` WHERE LOWER(email) = '" . $this->db->escape(mb_strtolower($email)) . "'
                       AND customer_id NOT IN (SELECT oc_id FROM `" . DB_PREFIX . "growthcontour_map` WHERE entity = 'customer') LIMIT 1"
                );
                $cid = $q->num_rows ? (int) $q->row['customer_id'] : 0;
            }
        }

        if ($cid && $m && (int) $m['oc_id'] === $cid && $m['hash'] === $hash && !$o['force']) {
            $this->mapTouch('customer', $crm, $o['job']);
            return array('ref' => $crm, 'ok' => true, 'id' => (string) $cid, 'action' => 'unchanged');
        }
        if ($cid && !$o['update']) {
            $this->mapSet('customer', $crm, $cid, '', $o['job']);
            return array('ref' => $crm, 'ok' => true, 'id' => (string) $cid, 'action' => 'skipped');
        }
        if (!$cid && (!empty($it['deleted']) || !$o['create'])) {
            return array('ref' => $crm, 'ok' => true, 'action' => 'skipped');
        }

        $e = function ($v, $len) {
            return $this->db->escape(mb_substr(trim((string) $v), 0, $len));
        };
        $set = "firstname = '" . $e($it['firstname'], 32) . "', lastname = '" . $e($it['lastname'], 32) . "',
                email = '" . $this->db->escape($email) . "', telephone = '" . $e($it['telephone'], 32) . "',
                newsletter = '" . (!empty($it['newsletter']) ? 1 : 0) . "', status = '" . (!empty($it['status']) ? 1 : 0) . "'";

        if ($cid) {
            $this->db->query("UPDATE `" . DB_PREFIX . "customer` SET " . $set . " WHERE customer_id = '" . $cid . "'");
            $action = 'updated';
        } else {
            $by_id = $o['match'] === 'id';
            $this->db->query(
                "INSERT INTO `" . DB_PREFIX . "customer` SET " . ($by_id ? "customer_id = '" . $crm . "', " : "") . $set . ",
                 customer_group_id = '" . (int) $this->config->get('config_customer_group_id') . "', store_id = '0',
                 language_id = '" . (int) $this->config->get('config_language_id') . "', fax = '', password = '', salt = '',
                 custom_field = '', ip = '', safe = '0', token = '', code = '', address_id = '0', date_added = NOW()"
            );
            $cid = $by_id ? $crm : (int) $this->db->getLastId();
            $action = 'created';
        }

        // Адреси: повна заміна, перша — основна
        $this->db->query("DELETE FROM `" . DB_PREFIX . "address` WHERE customer_id = '" . $cid . "'");
        $first = 0;
        foreach ((array) $it['addresses'] as $a) {
            $country = $this->countryId(isset($a['country']) ? $a['country'] : '');
            $this->db->query(
                "INSERT INTO `" . DB_PREFIX . "address` SET customer_id = '" . $cid . "', firstname = '" . $e($a['firstname'], 32) . "',
                 lastname = '" . $e($a['lastname'], 32) . "', company = '" . $e($a['company'], 40) . "', address_1 = '" . $e($a['address_1'], 128) . "',
                 address_2 = '" . $e($a['address_2'], 128) . "', city = '" . $e($a['city'], 128) . "', postcode = '" . $e($a['postcode'], 10) . "',
                 country_id = '" . $country . "', zone_id = '" . $this->zoneId($country, isset($a['region']) ? $a['region'] : '') . "', custom_field = ''"
            );
            if (!$first) {
                $first = (int) $this->db->getLastId();
            }
        }
        $this->db->query("UPDATE `" . DB_PREFIX . "customer` SET address_id = '" . $first . "' WHERE customer_id = '" . $cid . "'");

        $this->mapSet('customer', $crm, $cid, $hash, $o['job']);
        return array('ref' => $crm, 'ok' => true, 'id' => (string) $cid, 'action' => $action);
    }

    // ─── ОПЦІЇ ───

    private $gc_opt_ids = array();
    private $gc_val_ids = array();

    private static $gc_option_types = array(
        'select' => 'select',
        'radio' => 'radio',
        'checkbox' => 'checkbox',
        'color' => 'radio',
        'text' => 'text',
        'textarea' => 'textarea',
        'number' => 'text',
        'file' => 'file',
        'date' => 'date',
        'time' => 'time',
        'datetime' => 'datetime',
    );

    private function ocOptionType($type)
    {
        return isset(self::$gc_option_types[$type]) ? self::$gc_option_types[$type] : 'text';
    }

    private function writeNames($table, $key, $id, $names, $extra = '')
    {
        foreach ((array) $names as $n) {
            $lid = isset($n['code']) ? $this->langId($n['code']) : 0;
            if ($lid) {
                $this->db->query(
                    "REPLACE INTO `" . DB_PREFIX . $table . "` SET `" . $key . "` = '" . (int) $id . "', language_id = '" . $lid . "'" . $extra . ",
                     name = '" . $this->db->escape(htmlspecialchars((string) $n['name'], ENT_QUOTES, 'UTF-8')) . "'"
                );
            }
        }
    }

    // Опція довідника: створити або оновити (лише якщо змінились тип, порядок чи назви)
    private function syncOptionDict($o)
    {
        $crm = (int) $o['crm_id'];
        if (isset($this->gc_opt_ids[$crm])) {
            return $this->gc_opt_ids[$crm];
        }
        $type = $this->ocOptionType($o['type']);
        $hash = md5(json_encode(array($type, (int) $o['sort_order'], $o['names'])));

        $m = $this->mapRow('option', $crm);
        $oid = $m ? (int) $m['oc_id'] : 0;
        if ($oid && !$this->db->query("SELECT 1 FROM `" . DB_PREFIX . "option` WHERE option_id = '" . $oid . "'")->num_rows) {
            $oid = 0;
        }
        if (!$oid || $m['hash'] !== $hash) {
            if ($oid) {
                $this->db->query("UPDATE `" . DB_PREFIX . "option` SET `type` = '" . $this->db->escape($type) . "', sort_order = '" . (int) $o['sort_order'] . "' WHERE option_id = '" . $oid . "'");
            } else {
                $this->db->query("INSERT INTO `" . DB_PREFIX . "option` SET `type` = '" . $this->db->escape($type) . "', sort_order = '" . (int) $o['sort_order'] . "'");
                $oid = (int) $this->db->getLastId();
            }
            $this->writeNames('option_description', 'option_id', $oid, $o['names']);
            $this->mapSet('option', $crm, $oid, $hash, '');
        }
        return $this->gc_opt_ids[$crm] = $oid;
    }

    // Значення опції довідника: створити або оновити (лише якщо змінились назви чи порядок)
    private function syncOptionValue($oid, $v)
    {
        $crm = (int) $v['crm_id'];
        if (isset($this->gc_val_ids[$crm])) {
            return $this->gc_val_ids[$crm];
        }
        $hash = md5(json_encode(array($oid, (int) $v['sort_order'], $v['names'])));

        $m = $this->mapRow('option_value', $crm);
        $vid = $m ? (int) $m['oc_id'] : 0;
        if ($vid && !$this->db->query("SELECT 1 FROM `" . DB_PREFIX . "option_value` WHERE option_value_id = '" . $vid . "' AND option_id = '" . (int) $oid . "'")->num_rows) {
            $vid = 0;
        }
        if (!$vid || $m['hash'] !== $hash) {
            if ($vid) {
                $this->db->query("UPDATE `" . DB_PREFIX . "option_value` SET sort_order = '" . (int) $v['sort_order'] . "' WHERE option_value_id = '" . $vid . "'");
            } else {
                $this->db->query("INSERT INTO `" . DB_PREFIX . "option_value` SET option_id = '" . (int) $oid . "', image = '', sort_order = '" . (int) $v['sort_order'] . "'");
                $vid = (int) $this->db->getLastId();
            }
            $this->writeNames('option_value_description', 'option_value_id', $vid, $v['names'], ", option_id = '" . (int) $oid . "'");
            $this->mapSet('option_value', $crm, $vid, $hash, '');
        }
        return $this->gc_val_ids[$crm] = $vid;
    }

    // Опції товару: повна заміна
    private function setProductOptions($pid, $options, $price)
    {
        $pid = (int) $pid;
        $this->db->query("DELETE FROM `" . DB_PREFIX . "product_option` WHERE product_id = '" . $pid . "'");
        $this->db->query("DELETE FROM `" . DB_PREFIX . "product_option_value` WHERE product_id = '" . $pid . "'");

        foreach ((array) $options as $o) {
            if (empty($o['crm_id'])) {
                continue;
            }
            $oid = $this->syncOptionDict($o);
            $has_values = in_array($o['type'], array('select', 'radio', 'checkbox', 'color'), true);

            $this->db->query(
                "INSERT INTO `" . DB_PREFIX . "product_option` SET product_id = '" . $pid . "', option_id = '" . $oid . "',
                 `value` = '" . $this->db->escape($has_values ? '' : (string) $o['default_value']) . "', required = '" . (!empty($o['required']) ? 1 : 0) . "'"
            );
            $poid = (int) $this->db->getLastId();
            if (!$has_values) {
                continue;
            }

            foreach ((array) $o['values'] as $v) {
                if (empty($v['crm_id'])) {
                    continue;
                }
                $vid = $this->syncOptionValue($oid, $v);
                // Відсоткова націнка CRM → сума від ціни товару (OpenCart знає лише +/− суму)
                $p = (isset($v['price_mode']) && $v['price_mode'] === 'percent') ? round($price * (float) $v['price'] / 100, 4) : (float) $v['price'];
                $pts = (int) $v['points'];
                $w = (float) $v['weight'];

                $this->db->query(
                    "INSERT INTO `" . DB_PREFIX . "product_option_value` SET product_option_id = '" . $poid . "', product_id = '" . $pid . "',
                     option_id = '" . $oid . "', option_value_id = '" . $vid . "', quantity = '" . (int) $v['quantity'] . "',
                     subtract = '" . (!empty($v['subtract']) ? 1 : 0) . "',
                     price = '" . abs($p) . "', price_prefix = '" . ($p < 0 ? '-' : '+') . "',
                     points = '" . abs($pts) . "', points_prefix = '" . ($pts < 0 ? '-' : '+') . "',
                     weight = '" . abs($w) . "', weight_prefix = '" . ($w < 0 ? '-' : '+') . "'"
                );
            }
        }
    }

    // ─── ХАРАКТЕРИСТИКИ ───

    // attributes.list { page, limit } → { items:[{id, group_id, sort_order, languages:[{code,name}], group:[{code,name}]}] }
    public function apiAttributesList($d)
    {
        list($limit, $page, $total, $offset) = $this->idsPage('attribute', 'attribute_id', $d, 2000);
        $rows = $this->db->query("SELECT attribute_id, attribute_group_id, sort_order FROM `" . DB_PREFIX . "attribute` ORDER BY attribute_id LIMIT " . $offset . ", " . $limit)->rows;

        $items = array();
        if ($rows) {
            $codes = $this->langCodes();
            $ids = implode(',', array_map(function ($r) {
                return (int) $r['attribute_id'];
            }, $rows));
            $gids = implode(',', array_unique(array_map(function ($r) {
                return (int) $r['attribute_group_id'];
            }, $rows)));

            $names = array();
            foreach ($this->db->query("SELECT attribute_id, language_id, name FROM `" . DB_PREFIX . "attribute_description` WHERE attribute_id IN (" . $ids . ")")->rows as $n) {
                if (isset($codes[(int) $n['language_id']])) {
                    $names[(int) $n['attribute_id']][] = array('code' => $codes[(int) $n['language_id']], 'name' => html_entity_decode($n['name'], ENT_QUOTES, 'UTF-8'));
                }
            }
            $groups = array();
            foreach ($this->db->query("SELECT attribute_group_id, language_id, name FROM `" . DB_PREFIX . "attribute_group_description` WHERE attribute_group_id IN (" . $gids . ")")->rows as $n) {
                if (isset($codes[(int) $n['language_id']])) {
                    $groups[(int) $n['attribute_group_id']][] = array('code' => $codes[(int) $n['language_id']], 'name' => html_entity_decode($n['name'], ENT_QUOTES, 'UTF-8'));
                }
            }
            foreach ($rows as $r) {
                $id = (int) $r['attribute_id'];
                $gid = (int) $r['attribute_group_id'];
                $items[] = array(
                    'id' => (string) $id,
                    'group_id' => (string) $gid,
                    'sort_order' => (int) $r['sort_order'],
                    'languages' => isset($names[$id]) ? $names[$id] : array(),
                    'group' => isset($groups[$gid]) ? $groups[$gid] : array(),
                );
            }
        }

        return array('items' => $items, 'page' => $page, 'pages' => max(1, (int) ceil($total / $limit)), 'total' => $total);
    }

    // attributes.update { items:[{ref, id?, group_id?, group:[{code,name}]?, sort_order?, languages:[{code,name}]}] }
    // Нова характеристика: група за group_id, інакше за назвою з group (знайти або створити)
    public function apiAttributesUpdate($d)
    {
        $items = (isset($d['items']) && is_array($d['items'])) ? array_slice($d['items'], 0, 500) : array();
        $can_create = (bool) $this->config->get('module_growthcontour_sync_attributes_create');
        $results = array();

        foreach ($items as $it) {
            $ref = isset($it['ref']) ? $it['ref'] : null;
            $aid = isset($it['id']) ? (int) $it['id'] : 0;

            if ($aid) {
                if (!$this->db->query("SELECT 1 FROM `" . DB_PREFIX . "attribute` WHERE attribute_id = '" . $aid . "'")->num_rows) {
                    $results[] = array('ref' => $ref, 'ok' => false, 'error' => 'attribute not found');
                    continue;
                }
                if (isset($it['sort_order'])) {
                    $this->db->query("UPDATE `" . DB_PREFIX . "attribute` SET sort_order = '" . (int) $it['sort_order'] . "' WHERE attribute_id = '" . $aid . "'");
                }
            } else {
                if (!$can_create) {
                    $results[] = array('ref' => $ref, 'ok' => false, 'error' => 'creating attributes is disabled in the shop module');
                    continue;
                }
                $gid = $this->resolveAttributeGroup($it);
                if (!$gid) {
                    $results[] = array('ref' => $ref, 'ok' => false, 'error' => 'attribute group not specified');
                    continue;
                }
                $this->db->query("INSERT INTO `" . DB_PREFIX . "attribute` SET attribute_group_id = '" . $gid . "', sort_order = '" . (isset($it['sort_order']) ? (int) $it['sort_order'] : 0) . "'");
                $aid = (int) $this->db->getLastId();
            }

            if (!empty($it['languages']) && is_array($it['languages'])) {
                foreach ($it['languages'] as $l) {
                    $lid = isset($l['code']) ? $this->langId($l['code']) : 0;
                    if ($lid && isset($l['name'])) {
                        $this->upsertDesc('attribute_description', 'attribute_id', $aid, $lid, array('name' => $l['name']), array('name'));
                    }
                }
            }

            $results[] = array('ref' => $ref, 'ok' => true, 'id' => (string) $aid);
        }

        return array('results' => $results);
    }

    private function resolveAttributeGroup($it)
    {
        $gid = isset($it['group_id']) ? (int) $it['group_id'] : 0;
        if ($gid && $this->db->query("SELECT 1 FROM `" . DB_PREFIX . "attribute_group` WHERE attribute_group_id = '" . $gid . "'")->num_rows) {
            return $gid;
        }
        if (empty($it['group']) || !is_array($it['group'])) {
            return 0;
        }

        // Шукаємо групу за назвою будь-якою мовою
        foreach ($it['group'] as $g) {
            $lid = isset($g['code']) ? $this->langId($g['code']) : 0;
            if (!$lid || !isset($g['name']) || trim($g['name']) === '') {
                continue;
            }
            $q = $this->db->query(
                "SELECT attribute_group_id FROM `" . DB_PREFIX . "attribute_group_description`
                  WHERE language_id = '" . $lid . "' AND name = '" . $this->db->escape(htmlspecialchars(trim($g['name']), ENT_QUOTES, 'UTF-8')) . "' LIMIT 1"
            );
            if ($q->num_rows) {
                return (int) $q->row['attribute_group_id'];
            }
        }

        // Не знайшли — створюємо
        $this->db->query("INSERT INTO `" . DB_PREFIX . "attribute_group` SET sort_order = '0'");
        $gid = (int) $this->db->getLastId();
        foreach ($it['group'] as $g) {
            $lid = isset($g['code']) ? $this->langId($g['code']) : 0;
            if ($lid && isset($g['name'])) {
                $this->upsertDesc('attribute_group_description', 'attribute_group_id', $gid, $lid, array('name' => $g['name']), array('name'));
            }
        }
        return $gid;
    }

    // ─── ВІДГУКИ ───

    // reviews.list { cursor, limit } → { items, next_cursor, has_more }. Курсор — останній review_id.
    // crm_id — відгук записала на сайт система (опублікований із системи або вже зв'язаний оригінал): система не створить дубль
    public function apiReviewsList($d)
    {
        $limit = max(1, min(500, isset($d['limit']) ? (int) $d['limit'] : 200));
        $cursor = isset($d['cursor']) ? (int) $d['cursor'] : 0;
        $lang = strtolower((string) $this->config->get('config_language'));

        $this->mapTable();
        $rows = $this->db->query(
            "SELECT r.review_id, r.product_id, r.author, r.text, r.rating, r.status, r.date_added, m.crm_id
               FROM `" . DB_PREFIX . "review` r
               LEFT JOIN `" . DB_PREFIX . "growthcontour_map` m ON m.entity = 'review' AND m.oc_id = r.review_id
              WHERE r.review_id > '" . $cursor . "'
              ORDER BY r.review_id ASC
              LIMIT " . ($limit + 1)
        )->rows;

        $has_more = count($rows) > $limit;
        if ($has_more) {
            $rows = array_slice($rows, 0, $limit);
        }

        $items = array();
        $next = $cursor;
        foreach ($rows as $r) {
            $items[] = array(
                'external_id' => (string) $r['review_id'],
                'crm_id' => $r['crm_id'] !== null ? (int) $r['crm_id'] : null,
                'external_product_id' => (string) $r['product_id'],
                'external_variant_id' => '',
                'rating' => (int) $r['rating'],
                'author_name' => html_entity_decode((string) $r['author'], ENT_QUOTES, 'UTF-8'),
                'text' => html_entity_decode((string) $r['text'], ENT_QUOTES, 'UTF-8'),
                'status' => ((int) $r['status'] === 1) ? 'approved' : 'pending',
                'verified' => false,
                'lang' => $lang,
                'date_add' => date('c', strtotime($r['date_added'])),
                'photos' => array(),
            );
            $next = (int) $r['review_id'];
        }

        return array('items' => $items, 'next_cursor' => (string) $next, 'has_more' => $has_more);
    }

    // reviews.update { items:[{external_id, status, deleted}] }. Якщо модерацію з CRM вимкнено — приймаємо без змін
    public function apiReviewsUpdate($d)
    {
        $mod = $this->config->get('module_growthcontour_sync_reviews_moderation');
        if ($mod !== null && !(int) $mod) {
            return array('updated' => 0, 'skipped' => 'moderation disabled in the shop module');
        }

        $items = (isset($d['items']) && is_array($d['items'])) ? array_slice($d['items'], 0, 500) : array();
        $n = 0;

        foreach ($items as $it) {
            $rid = isset($it['external_id']) ? (int) $it['external_id'] : 0;
            if (!$rid) {
                continue;
            }
            $on = (empty($it['deleted']) && isset($it['status']) && $it['status'] === 'approved') ? 1 : 0;
            $this->db->query("UPDATE `" . DB_PREFIX . "review` SET status = '" . $on . "', date_modified = NOW() WHERE review_id = '" . $rid . "'");
            $n++;
        }

        $this->cache->delete('product');

        return array('updated' => $n);
    }

    // ─────────────────────────────────────────────────────────────────────
    //  ЕКСПОРТ КАТАЛОГУ із сайту в систему (імпорт товарів у Growth contour)
    //  Лише читання. Виклики: export.meta, export.categories, export.manufacturers,
    //  export.products (курсор after_id), export.link (запис зв'язків id системи ↔ id сайту).
    // ─────────────────────────────────────────────────────────────────────

    // Абсолютна адреса картинки сайту (image/...) або null
    private function exportImageUrl($path)
    {
        $path = trim((string) $path);
        if ($path === '') {
            return null;
        }
        $base = defined('HTTPS_SERVER') && HTTPS_SERVER ? HTTPS_SERVER : (defined('HTTP_SERVER') ? HTTP_SERVER : rtrim((string) $this->config->get('config_url'), '/') . '/');
        $parts = array();
        foreach (explode('/', ltrim($path, '/')) as $seg) {
            $parts[] = rawurlencode($seg);
        }
        return rtrim($base, '/') . '/image/' . implode('/', $parts);
    }

    private function exportText($v)
    {
        return html_entity_decode((string) $v, ENT_QUOTES, 'UTF-8');
    }

    // SEO-адреси: query (product_id=1) → [language_code => keyword] для магазину 0
    private function exportSeo($queries)
    {
        $out = array();
        if (!$queries || !$this->tableExists('seo_url')) {
            return $out;
        }
        $codes = $this->langCodes();
        $in = array();
        foreach ($queries as $q) {
            $in[] = "'" . $this->db->escape($q) . "'";
        }
        $rows = $this->db->query(
            "SELECT `query`, language_id, keyword FROM `" . DB_PREFIX . "seo_url` WHERE store_id = '0' AND `query` IN (" . implode(',', $in) . ")"
        )->rows;
        foreach ($rows as $r) {
            $code = isset($codes[(int) $r['language_id']]) ? $codes[(int) $r['language_id']] : (string) $r['language_id'];
            $out[$r['query']][$code] = (string) $r['keyword'];
        }
        return $out;
    }

    // export.meta → мови, групи покупців, одиниці, статуси наявності, валюта, кількість записів
    public function apiExportMeta($d)
    {
        $langs = array();
        foreach ($this->db->query("SELECT language_id, code, name, status FROM `" . DB_PREFIX . "language` ORDER BY sort_order, language_id")->rows as $l) {
            $langs[] = array('id' => (int) $l['language_id'], 'code' => (string) $l['code'], 'name' => (string) $l['name'], 'status' => (int) $l['status']);
        }
        $lid = (int) $this->config->get('config_language_id');

        $groups = array();
        foreach ($this->db->query(
            "SELECT cg.customer_group_id, COALESCE(cgd.name, CONCAT('#', cg.customer_group_id)) AS name
               FROM `" . DB_PREFIX . "customer_group` cg
               LEFT JOIN `" . DB_PREFIX . "customer_group_description` cgd ON cgd.customer_group_id = cg.customer_group_id AND cgd.language_id = '" . $lid . "'
              ORDER BY cg.sort_order, cg.customer_group_id"
        )->rows as $g) {
            $groups[] = array('id' => (int) $g['customer_group_id'], 'name' => $this->exportText($g['name']));
        }

        $weight = array();
        foreach ($this->db->query("SELECT weight_class_id, unit FROM `" . DB_PREFIX . "weight_class_description` WHERE language_id = '" . $lid . "'")->rows as $w) {
            $weight[(int) $w['weight_class_id']] = $this->exportText($w['unit']);
        }
        $length = array();
        foreach ($this->db->query("SELECT length_class_id, unit FROM `" . DB_PREFIX . "length_class_description` WHERE language_id = '" . $lid . "'")->rows as $w) {
            $length[(int) $w['length_class_id']] = $this->exportText($w['unit']);
        }
        $stock = array();
        foreach ($this->db->query("SELECT stock_status_id, name FROM `" . DB_PREFIX . "stock_status` WHERE language_id = '" . $lid . "'")->rows as $s) {
            $stock[(int) $s['stock_status_id']] = $this->exportText($s['name']);
        }

        return array(
            'languages' => $langs,
            'default_language' => (string) $this->config->get('config_language'),
            'currency' => (string) $this->config->get('config_currency'),
            'customer_groups' => $groups,
            'default_customer_group' => (int) $this->config->get('config_customer_group_id'),
            'weight_units' => $weight,
            'length_units' => $length,
            'stock_statuses' => $stock,
            'counts' => array(
                'products' => (int) $this->db->query("SELECT COUNT(*) AS n FROM `" . DB_PREFIX . "product`")->row['n'],
                'categories' => (int) $this->db->query("SELECT COUNT(*) AS n FROM `" . DB_PREFIX . "category`")->row['n'],
                'manufacturers' => (int) $this->db->query("SELECT COUNT(*) AS n FROM `" . DB_PREFIX . "manufacturer`")->row['n'],
            ),
            'max_product_id' => (int) $this->db->query("SELECT COALESCE(MAX(product_id), 0) AS n FROM `" . DB_PREFIX . "product`")->row['n'],
        );
    }

    // export.categories { after, limit } → дерево порціями: назви й мета всіма мовами, картинка, SEO
    public function apiExportCategories($d)
    {
        $after = isset($d['after']) ? max(0, (int) $d['after']) : 0;
        $limit = isset($d['limit']) ? max(1, min(1000, (int) $d['limit'])) : 500;
        $codes = $this->langCodes();
        $h1 = $this->hasCol('category_description', 'meta_h1');

        $rows = $this->db->query(
            "SELECT category_id, parent_id, image, status, sort_order, top FROM `" . DB_PREFIX . "category`
              WHERE category_id > '" . $after . "' ORDER BY category_id LIMIT " . $limit
        )->rows;
        if (!$rows) {
            return array('items' => array(), 'next' => $after, 'more' => false);
        }
        $ids = array();
        foreach ($rows as $r) {
            $ids[] = (int) $r['category_id'];
        }
        $desc = array();
        foreach ($this->db->query("SELECT * FROM `" . DB_PREFIX . "category_description` WHERE category_id IN (" . implode(',', $ids) . ")")->rows as $r) {
            $code = isset($codes[(int) $r['language_id']]) ? $codes[(int) $r['language_id']] : (string) $r['language_id'];
            $desc[(int) $r['category_id']][$code] = array(
                'name' => $this->exportText($r['name']),
                'description' => $this->exportText($r['description']),
                'meta_title' => $this->exportText($r['meta_title']),
                'meta_description' => $this->exportText($r['meta_description']),
                'meta_keyword' => $this->exportText($r['meta_keyword']),
                'h1' => $h1 ? $this->exportText($r['meta_h1']) : null,
            );
        }
        $q = array();
        foreach ($ids as $id) {
            $q[] = 'category_id=' . $id;
        }
        $seo = $this->exportSeo($q);

        $items = array();
        foreach ($rows as $r) {
            $id = (int) $r['category_id'];
            $items[] = array(
                'id' => $id,
                'parent_id' => (int) $r['parent_id'],
                'status' => (int) $r['status'],
                'sort_order' => (int) $r['sort_order'],
                'top' => (int) $r['top'],
                'image' => $this->exportImageUrl($r['image']),
                'descriptions' => isset($desc[$id]) ? $desc[$id] : array(),
                'seo' => isset($seo['category_id=' . $id]) ? $seo['category_id=' . $id] : array(),
            );
        }
        return array('items' => $items, 'next' => $ids[count($ids) - 1], 'more' => count($rows) === $limit);
    }

    // export.manufacturers → усі виробники (зазвичай їх небагато)
    public function apiExportManufacturers($d)
    {
        $rows = $this->db->query("SELECT manufacturer_id, name, image, sort_order FROM `" . DB_PREFIX . "manufacturer` ORDER BY manufacturer_id LIMIT 20000")->rows;
        $q = array();
        foreach ($rows as $r) {
            $q[] = 'manufacturer_id=' . (int) $r['manufacturer_id'];
        }
        $seo = $this->exportSeo($q);
        $items = array();
        foreach ($rows as $r) {
            $id = (int) $r['manufacturer_id'];
            $items[] = array(
                'id' => $id,
                'name' => $this->exportText($r['name']),
                'image' => $this->exportImageUrl($r['image']),
                'sort_order' => (int) $r['sort_order'],
                'seo' => isset($seo['manufacturer_id=' . $id]) ? $seo['manufacturer_id=' . $id] : array(),
            );
        }
        return array('items' => $items);
    }

    // export.products { after_id, limit, ids? } → товари повністю, по зростанню product_id
    public function apiExportProducts($d)
    {
        $after = isset($d['after_id']) ? max(0, (int) $d['after_id']) : 0;
        $limit = isset($d['limit']) ? max(1, min(200, (int) $d['limit'])) : 100;
        $codes = $this->langCodes();
        $h1 = $this->hasCol('product_description', 'meta_h1');
        $mainCat = $this->hasCol('product_to_category', 'main_category');

        $where = "p.product_id > '" . $after . "'";
        if (!empty($d['ids']) && is_array($d['ids'])) {
            $only = array();
            foreach (array_slice($d['ids'], 0, 200) as $id) {
                if ((int) $id > 0) {
                    $only[] = (int) $id;
                }
            }
            $where = $only ? "p.product_id IN (" . implode(',', $only) . ")" : "0";
        }

        $rows = $this->db->query("SELECT p.* FROM `" . DB_PREFIX . "product` p WHERE " . $where . " ORDER BY p.product_id LIMIT " . $limit)->rows;
        if (!$rows) {
            return array('items' => array(), 'next_after' => $after, 'done' => true);
        }
        $ids = array();
        foreach ($rows as $r) {
            $ids[] = (int) $r['product_id'];
        }
        $in = implode(',', $ids);
        $by = function ($sql) {
            $out = array();
            foreach ($this->db->query($sql)->rows as $r) {
                $out[(int) $r['product_id']][] = $r;
            }
            return $out;
        };

        $desc = $by("SELECT * FROM `" . DB_PREFIX . "product_description` WHERE product_id IN (" . $in . ")");
        $images = $by("SELECT product_id, image, sort_order FROM `" . DB_PREFIX . "product_image` WHERE product_id IN (" . $in . ") ORDER BY sort_order, product_image_id");
        $cats = $by("SELECT * FROM `" . DB_PREFIX . "product_to_category` WHERE product_id IN (" . $in . ")");
        $attrs = $by(
            "SELECT pa.product_id, pa.attribute_id, pa.language_id, pa.text, a.attribute_group_id
               FROM `" . DB_PREFIX . "product_attribute` pa
               LEFT JOIN `" . DB_PREFIX . "attribute` a ON a.attribute_id = pa.attribute_id
              WHERE pa.product_id IN (" . $in . ")"
        );
        $specials = $by("SELECT * FROM `" . DB_PREFIX . "product_special` WHERE product_id IN (" . $in . ") ORDER BY priority, price");
        $discounts = $by("SELECT * FROM `" . DB_PREFIX . "product_discount` WHERE product_id IN (" . $in . ") ORDER BY quantity, priority");
        $related = $by("SELECT product_id, related_id FROM `" . DB_PREFIX . "product_related` WHERE product_id IN (" . $in . ")");
        $rewards = $by("SELECT product_id, customer_group_id, points FROM `" . DB_PREFIX . "product_reward` WHERE product_id IN (" . $in . ")");
        $stores = $by("SELECT product_id, store_id FROM `" . DB_PREFIX . "product_to_store` WHERE product_id IN (" . $in . ")");
        $pos = $by("SELECT * FROM `" . DB_PREFIX . "product_option` WHERE product_id IN (" . $in . ")");
        $povs = array();
        foreach ($this->db->query("SELECT * FROM `" . DB_PREFIX . "product_option_value` WHERE product_id IN (" . $in . ")")->rows as $r) {
            $povs[(int) $r['product_option_id']][] = $r;
        }
        $q = array();
        foreach ($ids as $id) {
            $q[] = 'product_id=' . $id;
        }
        $seo = $this->exportSeo($q);

        // Довідники атрибутів і опцій, що трапились у порції: назви всіма мовами
        $attrIds = array();
        foreach ($attrs as $list) {
            foreach ($list as $a) {
                $attrIds[(int) $a['attribute_id']] = true;
            }
        }
        $optIds = array();
        $valIds = array();
        foreach ($pos as $list) {
            foreach ($list as $o) {
                $optIds[(int) $o['option_id']] = true;
                if (isset($povs[(int) $o['product_option_id']])) {
                    foreach ($povs[(int) $o['product_option_id']] as $v) {
                        $valIds[(int) $v['option_value_id']] = true;
                    }
                }
            }
        }
        $dict = array('attributes' => array(), 'attribute_groups' => array(), 'options' => array(), 'option_values' => array());
        if ($attrIds) {
            $groupIds = array();
            foreach ($this->db->query(
                "SELECT a.attribute_id, a.attribute_group_id, a.sort_order, ad.language_id, ad.name
                   FROM `" . DB_PREFIX . "attribute` a LEFT JOIN `" . DB_PREFIX . "attribute_description` ad ON ad.attribute_id = a.attribute_id
                  WHERE a.attribute_id IN (" . implode(',', array_keys($attrIds)) . ")"
            )->rows as $r) {
                $id = (int) $r['attribute_id'];
                $groupIds[(int) $r['attribute_group_id']] = true;
                if (!isset($dict['attributes'][$id])) {
                    $dict['attributes'][$id] = array('id' => $id, 'group_id' => (int) $r['attribute_group_id'], 'sort_order' => (int) $r['sort_order'], 'names' => array());
                }
                if ($r['language_id'] !== null) {
                    $code = isset($codes[(int) $r['language_id']]) ? $codes[(int) $r['language_id']] : (string) $r['language_id'];
                    $dict['attributes'][$id]['names'][$code] = $this->exportText($r['name']);
                }
            }
            if ($groupIds) {
                foreach ($this->db->query(
                    "SELECT g.attribute_group_id, g.sort_order, gd.language_id, gd.name
                       FROM `" . DB_PREFIX . "attribute_group` g LEFT JOIN `" . DB_PREFIX . "attribute_group_description` gd ON gd.attribute_group_id = g.attribute_group_id
                      WHERE g.attribute_group_id IN (" . implode(',', array_keys($groupIds)) . ")"
                )->rows as $r) {
                    $id = (int) $r['attribute_group_id'];
                    if (!isset($dict['attribute_groups'][$id])) {
                        $dict['attribute_groups'][$id] = array('id' => $id, 'sort_order' => (int) $r['sort_order'], 'names' => array());
                    }
                    if ($r['language_id'] !== null) {
                        $code = isset($codes[(int) $r['language_id']]) ? $codes[(int) $r['language_id']] : (string) $r['language_id'];
                        $dict['attribute_groups'][$id]['names'][$code] = $this->exportText($r['name']);
                    }
                }
            }
        }
        if ($optIds) {
            foreach ($this->db->query(
                "SELECT o.option_id, o.type, o.sort_order, od.language_id, od.name
                   FROM `" . DB_PREFIX . "option` o LEFT JOIN `" . DB_PREFIX . "option_description` od ON od.option_id = o.option_id
                  WHERE o.option_id IN (" . implode(',', array_keys($optIds)) . ")"
            )->rows as $r) {
                $id = (int) $r['option_id'];
                if (!isset($dict['options'][$id])) {
                    $dict['options'][$id] = array('id' => $id, 'type' => (string) $r['type'], 'sort_order' => (int) $r['sort_order'], 'names' => array());
                }
                if ($r['language_id'] !== null) {
                    $code = isset($codes[(int) $r['language_id']]) ? $codes[(int) $r['language_id']] : (string) $r['language_id'];
                    $dict['options'][$id]['names'][$code] = $this->exportText($r['name']);
                }
            }
        }
        if ($valIds) {
            foreach ($this->db->query(
                "SELECT ov.option_value_id, ov.option_id, ov.image, ov.sort_order, ovd.language_id, ovd.name
                   FROM `" . DB_PREFIX . "option_value` ov LEFT JOIN `" . DB_PREFIX . "option_value_description` ovd ON ovd.option_value_id = ov.option_value_id
                  WHERE ov.option_value_id IN (" . implode(',', array_keys($valIds)) . ")"
            )->rows as $r) {
                $id = (int) $r['option_value_id'];
                if (!isset($dict['option_values'][$id])) {
                    $dict['option_values'][$id] = array('id' => $id, 'option_id' => (int) $r['option_id'], 'sort_order' => (int) $r['sort_order'], 'image' => $this->exportImageUrl($r['image']), 'names' => array());
                }
                if ($r['language_id'] !== null) {
                    $code = isset($codes[(int) $r['language_id']]) ? $codes[(int) $r['language_id']] : (string) $r['language_id'];
                    $dict['option_values'][$id]['names'][$code] = $this->exportText($r['name']);
                }
            }
        }

        $items = array();
        foreach ($rows as $p) {
            $id = (int) $p['product_id'];
            $descriptions = array();
            foreach (isset($desc[$id]) ? $desc[$id] : array() as $r) {
                $code = isset($codes[(int) $r['language_id']]) ? $codes[(int) $r['language_id']] : (string) $r['language_id'];
                $descriptions[$code] = array(
                    'name' => $this->exportText($r['name']),
                    'description' => $this->exportText($r['description']),
                    'tag' => $this->exportText($r['tag']),
                    'meta_title' => $this->exportText($r['meta_title']),
                    'meta_description' => $this->exportText($r['meta_description']),
                    'meta_keyword' => $this->exportText($r['meta_keyword']),
                    'h1' => $h1 ? $this->exportText($r['meta_h1']) : null,
                );
            }
            $gallery = array();
            foreach (isset($images[$id]) ? $images[$id] : array() as $r) {
                $u = $this->exportImageUrl($r['image']);
                if ($u) {
                    $gallery[] = $u;
                }
            }
            $categories = array();
            $main = 0;
            foreach (isset($cats[$id]) ? $cats[$id] : array() as $r) {
                $categories[] = (int) $r['category_id'];
                if ($mainCat && !empty($r['main_category'])) {
                    $main = (int) $r['category_id'];
                }
            }
            $attributes = array();
            foreach (isset($attrs[$id]) ? $attrs[$id] : array() as $r) {
                $aid = (int) $r['attribute_id'];
                $code = isset($codes[(int) $r['language_id']]) ? $codes[(int) $r['language_id']] : (string) $r['language_id'];
                if (!isset($attributes[$aid])) {
                    $attributes[$aid] = array('attribute_id' => $aid, 'texts' => array());
                }
                $attributes[$aid]['texts'][$code] = $this->exportText($r['text']);
            }
            $options = array();
            foreach (isset($pos[$id]) ? $pos[$id] : array() as $o) {
                $values = array();
                foreach (isset($povs[(int) $o['product_option_id']]) ? $povs[(int) $o['product_option_id']] : array() as $v) {
                    $values[] = array(
                        'option_value_id' => (int) $v['option_value_id'],
                        'quantity' => (float) $v['quantity'],
                        'subtract' => (int) $v['subtract'],
                        'price' => (float) $v['price'] * ($v['price_prefix'] === '-' ? -1 : 1),
                        'points' => (int) $v['points'] * ($v['points_prefix'] === '-' ? -1 : 1),
                        'weight' => (float) $v['weight'] * ($v['weight_prefix'] === '-' ? -1 : 1),
                    );
                }
                $options[] = array(
                    'product_option_id' => (int) $o['product_option_id'],
                    'option_id' => (int) $o['option_id'],
                    'required' => (int) $o['required'],
                    'value' => isset($o['value']) ? $this->exportText($o['value']) : '',
                    'values' => $values,
                );
            }
            $sp = array();
            foreach (isset($specials[$id]) ? $specials[$id] : array() as $r) {
                $sp[] = array('customer_group_id' => (int) $r['customer_group_id'], 'priority' => (int) $r['priority'], 'price' => (float) $r['price'], 'date_start' => $r['date_start'], 'date_end' => $r['date_end']);
            }
            $ds = array();
            foreach (isset($discounts[$id]) ? $discounts[$id] : array() as $r) {
                $ds[] = array('customer_group_id' => (int) $r['customer_group_id'], 'quantity' => (int) $r['quantity'], 'priority' => (int) $r['priority'], 'price' => (float) $r['price'], 'date_start' => $r['date_start'], 'date_end' => $r['date_end']);
            }
            $rel = array();
            foreach (isset($related[$id]) ? $related[$id] : array() as $r) {
                $rel[] = (int) $r['related_id'];
            }
            $rw = array();
            foreach (isset($rewards[$id]) ? $rewards[$id] : array() as $r) {
                $rw[] = array('customer_group_id' => (int) $r['customer_group_id'], 'points' => (int) $r['points']);
            }
            $st = array();
            foreach (isset($stores[$id]) ? $stores[$id] : array() as $r) {
                $st[] = (int) $r['store_id'];
            }

            $items[] = array(
                'id' => $id,
                'model' => $this->exportText($p['model']),
                'sku' => $this->exportText($p['sku']),
                'upc' => (string) $p['upc'],
                'ean' => (string) $p['ean'],
                'jan' => (string) $p['jan'],
                'isbn' => (string) $p['isbn'],
                'mpn' => $this->exportText($p['mpn']),
                'location' => $this->exportText($p['location']),
                'quantity' => (float) $p['quantity'],
                'stock_status_id' => (int) $p['stock_status_id'],
                'image' => $this->exportImageUrl($p['image']),
                'images' => $gallery,
                'manufacturer_id' => (int) $p['manufacturer_id'],
                'shipping' => (int) $p['shipping'],
                'price' => (float) $p['price'],
                'points' => (int) $p['points'],
                'tax_class_id' => (int) $p['tax_class_id'],
                'date_available' => $p['date_available'],
                'weight' => (float) $p['weight'],
                'weight_class_id' => (int) $p['weight_class_id'],
                'length' => (float) $p['length'],
                'width' => (float) $p['width'],
                'height' => (float) $p['height'],
                'length_class_id' => (int) $p['length_class_id'],
                'subtract' => (int) $p['subtract'],
                'minimum' => (int) $p['minimum'],
                'sort_order' => (int) $p['sort_order'],
                'status' => (int) $p['status'],
                'date_added' => $p['date_added'],
                'date_modified' => $p['date_modified'],
                'descriptions' => $descriptions,
                'seo' => isset($seo['product_id=' . $id]) ? $seo['product_id=' . $id] : array(),
                'categories' => $categories,
                'main_category_id' => $main,
                'attributes' => array_values($attributes),
                'options' => $options,
                'specials' => $sp,
                'discounts' => $ds,
                'related' => $rel,
                'rewards' => $rw,
                'stores' => $st,
            );
        }

        return array(
            'items' => $items,
            'dict' => array(
                'attributes' => array_values($dict['attributes']),
                'attribute_groups' => array_values($dict['attribute_groups']),
                'options' => array_values($dict['options']),
                'option_values' => array_values($dict['option_values']),
            ),
            'next_after' => $ids[count($ids) - 1],
            'done' => empty($d['ids']) ? count($rows) < $limit : true,
        );
    }

    // export.link { items:[{entity, crm_id, oc_id}] } → зв'язки імпортованих товарів і категорій (entity: product | category),
    // щоб обмін із системи оновлював їх, а не створював дублі
    public function apiExportLink($d)
    {
        $this->mapTable();
        $n = 0;
        $items = (isset($d['items']) && is_array($d['items'])) ? array_slice($d['items'], 0, 1000) : array();
        foreach ($items as $it) {
            $entity = isset($it['entity']) ? (string) $it['entity'] : 'product';
            $crm = isset($it['crm_id']) ? (int) $it['crm_id'] : 0;
            $oc = isset($it['oc_id']) ? (int) $it['oc_id'] : 0;
            if ($crm <= 0 || $oc <= 0) {
                continue;
            }
            if ($entity === 'product' && !$this->productExists($oc)) {
                continue;
            }
            if ($entity === 'category' && !$this->categoryExists($oc)) {
                continue;
            }
            if ($entity !== 'product' && $entity !== 'category') {
                continue;
            }
            // Порожній відбиток — наступний обмін із системи оновить запис повністю
            $this->mapSet($entity, $crm, $oc, '', null);
            $n++;
        }
        return array('updated' => $n);
    }

    // export.unlink { items:[{entity, crm_id}] } → прибрати зв'язки (відкат пробного імпорту в системі). Самі товари сайту не чіпаємо
    public function apiExportUnlink($d)
    {
        $this->mapTable();
        $n = 0;
        $items = (isset($d['items']) && is_array($d['items'])) ? array_slice($d['items'], 0, 1000) : array();
        foreach ($items as $it) {
            $entity = isset($it['entity']) ? (string) $it['entity'] : 'product';
            $crm = isset($it['crm_id']) ? (int) $it['crm_id'] : 0;
            if ($crm <= 0 || ($entity !== 'product' && $entity !== 'category')) {
                continue;
            }
            $this->db->query("DELETE FROM `" . DB_PREFIX . "growthcontour_map` WHERE entity = '" . $this->db->escape($entity) . "' AND crm_id = '" . $crm . "'");
            $n += $this->db->countAffected();
        }
        return array('updated' => $n);
    }
}
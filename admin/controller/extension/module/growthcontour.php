<?php
class ControllerExtensionModuleGrowthcontour extends Controller
{
    private $error = array();

    public function index()
    {
        $this->load->language('extension/module/growthcontour');

        $this->document->setTitle($this->language->get('heading_title'));

        $this->load->model('setting/setting');

        // Звичайна відправка форми (без JavaScript): зберегти й лишитись у модулі
        if (($this->request->server['REQUEST_METHOD'] == 'POST') && $this->validate()) {
            $this->saveSettings($this->request->post);

            $this->session->data['success'] = $this->language->get('text_success');

            $this->response->redirect($this->url->link('extension/module/growthcontour', 'user_token=' . $this->session->data['user_token'], true));
        }

        if (isset($this->error['warning'])) {
            $data['error_warning'] = $this->error['warning'];
        } else {
            $data['error_warning'] = '';
        }

        $data['breadcrumbs'] = array();

        $data['breadcrumbs'][] = array(
            'text' => $this->language->get('text_home'),
            'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'], true)
        );

        $data['breadcrumbs'][] = array(
            'text' => $this->language->get('text_extension'),
            'href' => $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=module', true)
        );

        $data['breadcrumbs'][] = array(
            'text' => $this->language->get('heading_title'),
            'href' => $this->url->link('extension/module/growthcontour', 'user_token=' . $this->session->data['user_token'], true)
        );

        $data['action'] = $this->url->link('extension/module/growthcontour', 'user_token=' . $this->session->data['user_token'], true);

        $data['cancel'] = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=module', true);

        $data['pull'] = $this->url->link('extension/module/growthcontour/pull', 'user_token=' . $this->session->data['user_token'], true);
        $data['sync_all'] = $this->url->link('extension/module/growthcontour/syncAll', 'user_token=' . $this->session->data['user_token'], true);
        $data['check_connection'] = $this->url->link('extension/module/growthcontour/checkConnection', 'user_token=' . $this->session->data['user_token'], true);
        $data['send_order'] = $this->url->link('extension/module/growthcontour/sendOrderOne', 'user_token=' . $this->session->data['user_token'], true);
        $data['logs'] = $this->url->link('extension/module/growthcontour/logs', 'user_token=' . $this->session->data['user_token'], true);
        $data['clear'] = $this->url->link('extension/module/growthcontour/clearLog', 'user_token=' . $this->session->data['user_token'], true);
        $data['regenerate'] = $this->url->link('extension/module/growthcontour/regenerateToken', 'user_token=' . $this->session->data['user_token'], true);
        $data['revoke_old'] = $this->url->link('extension/module/growthcontour/revokeOldToken', 'user_token=' . $this->session->data['user_token'], true);
        $data['catalog_stats'] = $this->url->link('extension/module/growthcontour/catalogStats', 'user_token=' . $this->session->data['user_token'], true);
        $data['catalog_run'] = $this->url->link('extension/module/growthcontour/catalogRun', 'user_token=' . $this->session->data['user_token'], true);
        $data['catalog_cancel'] = $this->url->link('extension/module/growthcontour/catalogCancel', 'user_token=' . $this->session->data['user_token'], true);
        $data['import_api'] = $this->url->link('extension/module/growthcontour/importApi', 'user_token=' . $this->session->data['user_token'], true);
        $data['delivery_list'] = $this->url->link('extension/module/growthcontour/deliveryList', 'user_token=' . $this->session->data['user_token'], true);
        $data['delivery_raw'] = $this->url->link('extension/module/growthcontour/deliveryRaw', 'user_token=' . $this->session->data['user_token'], true);
        $data['delivery_save'] = $this->url->link('extension/module/growthcontour/deliverySave', 'user_token=' . $this->session->data['user_token'], true);
        $data['delivery_test'] = $this->url->link('extension/module/growthcontour/deliveryTest', 'user_token=' . $this->session->data['user_token'], true);
        $data['delivery_reparse'] = $this->url->link('extension/module/growthcontour/deliveryReparse', 'user_token=' . $this->session->data['user_token'], true);
        $data['import_site_meta'] = $this->url->link('extension/module/growthcontour/importSiteMeta', 'user_token=' . $this->session->data['user_token'], true);
        $data['import_save'] = $this->url->link('extension/module/growthcontour/importSave', 'user_token=' . $this->session->data['user_token'], true);
        $data['import_options'] = $this->importOptions();
        $data['save'] = $this->url->link('extension/module/growthcontour/save', 'user_token=' . $this->session->data['user_token'], true);
        $data['orders_diag_json'] = json_encode($this->ordersDiag(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        // Simple string/int fields
        $fields = array(
            'module_growthcontour_status',
            'module_growthcontour_domain',
            'module_growthcontour_token',
            'module_growthcontour_integration_id',
            'module_growthcontour_orders_status',
            'module_growthcontour_inbound_token',
            'module_growthcontour_inbound_token_old',
            // Абандон-кошик
            'module_growthcontour_cart_enabled',
            'module_growthcontour_cart_threshold_min',
            'module_growthcontour_cart_max_age_hours',
            'module_growthcontour_cart_min_amount',
            'module_growthcontour_cart_batch',
            'module_growthcontour_cart_guests',
            'module_growthcontour_cart_require_contact',
            'module_growthcontour_cart_require_consent',
            // Каталог
            'module_growthcontour_sync_products',
            'module_growthcontour_sync_products_sku',
            'module_growthcontour_sync_products_fields',
            'module_growthcontour_sync_categories',
            'module_growthcontour_sync_categories_create',
            'module_growthcontour_sync_attributes',
            'module_growthcontour_sync_attributes_create',
            'module_growthcontour_sync_reviews',
            'module_growthcontour_sync_reviews_moderation',
            'module_growthcontour_sync_products_auto',
            'module_growthcontour_sync_categories_auto',
            'module_growthcontour_sync_attributes_auto',
            'module_growthcontour_sync_reviews_auto',
            'module_growthcontour_sync_products_match',
            'module_growthcontour_sync_products_create',
            'module_growthcontour_sync_products_update',
            'module_growthcontour_sync_products_missing',
            'module_growthcontour_sync_categories_match',
            'module_growthcontour_sync_categories_update',
            'module_growthcontour_sync_categories_missing',
            'module_growthcontour_sync_batch',
            'module_growthcontour_sync_attributes_match',
            'module_growthcontour_sync_attributes_update',
            'module_growthcontour_sync_attributes_missing',
            'module_growthcontour_sync_reviews_match',
            'module_growthcontour_sync_reviews_create',
            'module_growthcontour_sync_reviews_update',
            'module_growthcontour_sync_reviews_missing',
            'module_growthcontour_sync_customers',
            'module_growthcontour_sync_customers_auto',
            'module_growthcontour_sync_customers_match',
            'module_growthcontour_sync_customers_create',
            'module_growthcontour_sync_customers_update',
            'module_growthcontour_sync_customers_missing'
        );

        foreach ($fields as $field) {
            if (isset($this->request->post[$field])) {
                $data[$field] = $this->request->post[$field];
            } else {
                $data[$field] = $this->config->get($field);
            }
        }

        // Значення за замовчуванням (до першого збереження): усе вимкнено
        foreach ($this->defaults() as $k => $v) {
            if (!isset($data[$k]) || $data[$k] === null) {
                $data[$k] = $v;
            }
        }
        if (!is_array($data['module_growthcontour_sync_products_fields'])) {
            $data['module_growthcontour_sync_products_fields'] = array();
        }

        // Автосинхронізація каталогу: інтервали (хвилини) і URL крону
        $data['sync_intervals'] = array(
            0 => $this->language->get('text_auto_off'),
            15 => $this->language->get('text_auto_15'),
            60 => $this->language->get('text_auto_60'),
            360 => $this->language->get('text_auto_360'),
            1440 => $this->language->get('text_auto_1440'),
        );
        $this->load->model('extension/module/growthcontour');
        $store_url = rtrim($this->model_extension_module_growthcontour->getStoreBaseUrl(), '/');
        $data['catalog_cron_url'] = $store_url . '/index.php?route=extension/module/growthcontour/catalogCron';

        // URL крону кошика (той самий магазин; викликати за inbound-токеном)
        $data['cart_cron_url'] = $store_url . '/index.php?route=extension/module/growthcontour/cartCron';
        $data['cart_stats'] = $this->url->link('extension/module/growthcontour/cartStats', 'user_token=' . $this->session->data['user_token'], true);

        // Status map (OC status id => CRM status id)
        if (isset($this->request->post['module_growthcontour_status_map'])) {
            $data['module_growthcontour_status_map'] = $this->request->post['module_growthcontour_status_map'];
        } else {
            $data['module_growthcontour_status_map'] = $this->config->get('module_growthcontour_status_map');
        }

        if (!$data['module_growthcontour_status_map']) {
            $data['module_growthcontour_status_map'] = array();
        }

        // Reverse status map (CRM status id => OC status id) — для вхідних змін із CRM
        if (isset($this->request->post['module_growthcontour_status_map_in'])) {
            $data['module_growthcontour_status_map_in'] = $this->request->post['module_growthcontour_status_map_in'];
        } else {
            $data['module_growthcontour_status_map_in'] = $this->config->get('module_growthcontour_status_map_in');
        }

        if (!$data['module_growthcontour_status_map_in']) {
            $data['module_growthcontour_status_map_in'] = array();
        }

        // Paid statuses (які OC-статуси вважати оплаченими)
        if (isset($this->request->post['module_growthcontour_paid_statuses'])) {
            $data['module_growthcontour_paid_statuses'] = $this->request->post['module_growthcontour_paid_statuses'];
        } else {
            $data['module_growthcontour_paid_statuses'] = $this->config->get('module_growthcontour_paid_statuses');
        }

        if (!$data['module_growthcontour_paid_statuses']) {
            $data['module_growthcontour_paid_statuses'] = array();
        }

        // Cached CRM statuses (last pulled)
        if (isset($this->request->post['module_growthcontour_crm_statuses'])) {
            $data['module_growthcontour_crm_statuses'] = $this->request->post['module_growthcontour_crm_statuses'];
        } else {
            $data['module_growthcontour_crm_statuses'] = $this->config->get('module_growthcontour_crm_statuses');
        }

        if (!$data['module_growthcontour_crm_statuses']) {
            $data['module_growthcontour_crm_statuses'] = array();
        }

        // OpenCart order statuses for the mapping table
        $this->load->model('localisation/order_status');
        $data['order_statuses'] = $this->model_localisation_order_status->getOrderStatuses();

        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer'] = $this->load->controller('common/footer');

        $this->response->setOutput($this->load->view('extension/module/growthcontour', $data));
    }

    public function pull()
    {
        $this->load->language('extension/module/growthcontour');

        $json = array();

        if (!$this->user->hasPermission('modify', 'extension/module/growthcontour')) {
            $json['error'] = $this->language->get('error_permission');
        } else {
            $domain = isset($this->request->post['domain']) ? $this->request->post['domain'] : $this->config->get('module_growthcontour_domain');
            $token = isset($this->request->post['token']) ? $this->request->post['token'] : $this->config->get('module_growthcontour_token');

            if (!$domain || !$token) {
                $json['error'] = $this->language->get('error_credentials');
            } else {
                $this->load->model('extension/module/growthcontour');

                $origin = $this->getOrigin();

                $result = $this->model_extension_module_growthcontour->pullStatuses($domain, $token);

                if ($result['http_code'] >= 200 && $result['http_code'] < 300 && isset($result['data']['statuses'])) {
                    $statuses = array();

                    foreach ($result['data']['statuses'] as $status) {
                        $statuses[] = array(
                            'id' => $status['id'],
                            'name' => isset($status['name']) ? $status['name'] : ('#' . $status['id'])
                        );
                    }

                    $json['success'] = sprintf($this->language->get('text_pull_success'), count($statuses));
                    $json['statuses'] = $statuses;
                } else {
                    $message = isset($result['data']['message']) ? $result['data']['message'] : (isset($result['data']['reason']) ? $result['data']['reason'] : $result['error']);

                    $json['error'] = sprintf($this->language->get('error_pull'), $result['http_code'], $message);
                }
            }
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }

    public function logs()
    {
        $this->load->language('extension/module/growthcontour');

        $json = array();

        if (!$this->user->hasPermission('access', 'extension/module/growthcontour')) {
            $json['error'] = $this->language->get('error_permission');
        } else {
            $this->load->model('extension/module/growthcontour');

            $json['logs'] = $this->model_extension_module_growthcontour->getLogs(1000);
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }

    public function cartStats()
    {
        $this->load->language('extension/module/growthcontour');

        $json = array();

        if (!$this->user->hasPermission('access', 'extension/module/growthcontour')) {
            $json['error'] = $this->language->get('error_permission');
        } else {
            $this->load->model('extension/module/growthcontour');
            $json['stats'] = $this->model_extension_module_growthcontour->getCartStats();
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }

    public function syncAll()
    {
        $this->load->language('extension/module/growthcontour');

        $json = array();

        if (!$this->user->hasPermission('modify', 'extension/module/growthcontour')) {
            $json['error'] = $this->language->get('error_permission');
            $this->response->addHeader('Content-Type: application/json');
            $this->response->setOutput(json_encode($json));
            return;
        }

        $this->load->model('extension/module/growthcontour');
        $this->load->model('sale/order');

        $after = isset($this->request->get['after']) ? (int) $this->request->get['after'] : 0;
        $limit = 30; // розмір пачки (внутрішній, користувачу не показуємо)

        $total = $this->model_extension_module_growthcontour->getTotalOrders();

        // Пачка замовлень з id > $after (курсор), за зростанням
        $orders = $this->model_extension_module_growthcontour->getOrdersBatch($after, $limit);

        $sent = 0;
        $last_id = $after;

        // URL власного catalog-маршруту push (той самий сайт)
        $push_url = $this->model_extension_module_growthcontour->getStoreBaseUrl()
            . 'index.php?route=extension/module/growthcontour/push';
        $inbound_token = trim((string) $this->config->get('module_growthcontour_inbound_token'));

        foreach ($orders as $order) {
            $oid = (int) $order['order_id'];
            $this->model_extension_module_growthcontour->pushOrder($push_url, $inbound_token, $oid);
            $sent++;
            $last_id = $oid;
        }

        $done = (count($orders) < $limit);

        $json['total'] = (int) $total;
        $json['sent'] = (int) $sent;
        $json['after'] = (int) $last_id; // курсор для наступної пачки
        $json['done'] = (bool) $done;

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }

    // Перевірка з'єднання з системою: адреса, ключ і його права (поля з форми — можна до збереження)
    public function checkConnection()
    {
        $this->load->language('extension/module/growthcontour');
        $json = array();

        if (!$this->user->hasPermission('access', 'extension/module/growthcontour')) {
            $json['error'] = $this->language->get('error_permission');
        } else {
            $domain = trim(isset($this->request->post['domain']) ? (string) $this->request->post['domain'] : (string) $this->config->get('module_growthcontour_domain'));
            $token = trim(isset($this->request->post['token']) ? (string) $this->request->post['token'] : (string) $this->config->get('module_growthcontour_token'));

            if ($domain === '' || $token === '') {
                $json['error'] = $this->language->get('error_check_empty');
            } elseif (!preg_match('~^https?://[^/\s]+~i', $domain)) {
                $json['error'] = $this->language->get('error_check_domain');
            } else {
                $this->load->model('extension/module/growthcontour');
                $started = microtime(true);
                $r = $this->model_extension_module_growthcontour->pullStatuses($domain, $token);
                $ms = (int) round((microtime(true) - $started) * 1000);
                $code = (int) $r['http_code'];
                $d = is_array($r['data']) ? $r['data'] : array();
                $reason = isset($d['message']) ? $d['message'] : (isset($d['reason']) ? $d['reason'] : (isset($d['error']) ? $d['error'] : ''));

                if ($code >= 200 && $code < 300 && isset($d['statuses'])) {
                    $json['success'] = sprintf($this->language->get('text_check_ok'), $ms, count($d['statuses']));
                } elseif ($code === 0) {
                    $json['error'] = sprintf($this->language->get('error_check_network'), $domain, $r['error'] ? $r['error'] : '—');
                } elseif ($code === 401 || $code === 403) {
                    $json['error'] = sprintf($this->language->get('error_check_auth'), $code, $reason ? $reason : '—');
                } elseif ($code === 404) {
                    $json['error'] = sprintf($this->language->get('error_check_404'), $domain);
                } else {
                    $json['error'] = sprintf($this->language->get('error_check_http'), $code, $reason ? $reason : mb_substr(strip_tags((string) $r['raw']), 0, 200));
                }
            }
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json, JSON_UNESCAPED_UNICODE));
    }

    // Передати одне замовлення в систему за ID (той самий шлях, що й «Передати всі»)
    public function sendOrderOne()
    {
        $this->load->language('extension/module/growthcontour');
        $json = array();
        $id = isset($this->request->post['order_id']) ? (int) $this->request->post['order_id'] : 0;

        if (!$this->user->hasPermission('modify', 'extension/module/growthcontour')) {
            $json['error'] = $this->language->get('error_permission');
        } elseif (!trim((string) $this->config->get('module_growthcontour_domain')) || !trim((string) $this->config->get('module_growthcontour_token'))) {
            $json['error'] = $this->language->get('error_credentials');
        } elseif ($id < 1 || !$this->db->query("SELECT order_id FROM `" . DB_PREFIX . "order` WHERE order_id = '" . $id . "'")->num_rows) {
            $json['error'] = sprintf($this->language->get('error_send_order_missing'), $id);
        } else {
            $this->load->model('extension/module/growthcontour');
            $push_url = $this->model_extension_module_growthcontour->getStoreBaseUrl() . 'index.php?route=extension/module/growthcontour/push';
            $code = $this->model_extension_module_growthcontour->pushOrder($push_url, trim((string) $this->config->get('module_growthcontour_inbound_token')), $id);

            if ($code !== 200) {
                $json['error'] = sprintf($this->language->get('error_send_order_push'), $code, $this->model_extension_module_growthcontour->getPushError());
            } else {
                // Відповідь системи — з журналу (запис цієї передачі)
                $last = null;
                foreach ($this->model_extension_module_growthcontour->getLogs(50) as $e) {
                    if (isset($e['event'], $e['order_id']) && $e['event'] === 'send' && (int) $e['order_id'] === $id) {
                        $last = $e;
                        break;
                    }
                }
                if (!$last) {
                    $json['error'] = sprintf($this->language->get('error_send_order_nolog'), $id);
                } elseif ($last['result'] === 'success') {
                    $json['success'] = sprintf($this->language->get('text_send_order_ok'), $id);
                } elseif ($last['result'] === 'duplicate') {
                    $json['warning'] = sprintf($this->language->get('text_send_order_dup'), $id);
                } else {
                    $json['error'] = sprintf($this->language->get('error_send_order_failed'), $id, (int) $last['http_code'], isset($last['message']) ? $last['message'] : '');
                }
            }
        }

        $json['diag'] = $this->ordersDiag();

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json, JSON_UNESCAPED_UNICODE));
    }

    public function regenerateToken()
    {
        $this->load->language('extension/module/growthcontour');

        $json = array();

        if (!$this->user->hasPermission('modify', 'extension/module/growthcontour')) {
            $json['error'] = $this->language->get('error_permission');
        } else {
            $this->load->model('setting/setting');

            $settings = $this->model_setting_setting->getSetting('module_growthcontour');

            // Поточний токен стає "старим" (діятиме, поки не видалиш вручну)
            if (!empty($settings['module_growthcontour_inbound_token'])) {
                $settings['module_growthcontour_inbound_token_old'] = $settings['module_growthcontour_inbound_token'];
            }

            $settings['module_growthcontour_inbound_token'] = bin2hex(random_bytes(24));
            $this->model_setting_setting->editSetting('module_growthcontour', $settings);

            $json['success'] = $this->language->get('text_token_regenerated');
            $json['token'] = $settings['module_growthcontour_inbound_token'];
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }

    public function revokeOldToken()
    {
        $this->load->language('extension/module/growthcontour');

        $json = array();

        if (!$this->user->hasPermission('modify', 'extension/module/growthcontour')) {
            $json['error'] = $this->language->get('error_permission');
        } else {
            $this->load->model('setting/setting');

            $settings = $this->model_setting_setting->getSetting('module_growthcontour');
            $settings['module_growthcontour_inbound_token_old'] = '';
            $this->model_setting_setting->editSetting('module_growthcontour', $settings);

            $json['success'] = $this->language->get('text_old_revoked');
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }

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

    public function catalogStats()
    {
        $this->jobTable();
        $json = array('counts' => array(), 'jobs' => array(), 'now' => time());

        $tables = array('products' => 'product', 'categories' => 'category', 'attributes' => 'attribute', 'reviews' => 'review', 'customers' => 'customer');
        foreach ($tables as $entity => $table) {
            $json['counts'][$entity] = (int) $this->db->query("SELECT COUNT(*) AS n FROM `" . DB_PREFIX . $table . "`")->row['n'];
        }
        $rows = $this->db->query(
            "SELECT *, UNIX_TIMESTAMP(started_at) AS started_ts, UNIX_TIMESTAMP(updated_at) AS updated_ts, UNIX_TIMESTAMP(finished_at) AS finished_ts
               FROM `" . DB_PREFIX . "growthcontour_sync_job`"
        )->rows;
        foreach ($rows as $r) {
            $json['jobs'][$r['entity']] = $r;
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }

    // Ручний запуск: опції (повний перезапис / продовжити / очистити) → черга → просимо CRM запустити обмін
    public function catalogRun()
    {
        $this->load->language('extension/module/growthcontour');

        $json = array();
        $p = $this->request->post;
        $entity = isset($p['entity']) ? (string) $p['entity'] : '';
        $all = array('categories', 'attributes', 'products', 'reviews', 'customers');

        if (!$this->user->hasPermission('modify', 'extension/module/growthcontour')) {
            $json['error'] = $this->language->get('error_permission');
        } elseif ($entity !== 'all' && !in_array($entity, $all, true)) {
            $json['error'] = 'bad entity';
        } elseif (!empty($p['wipe']) && (isset($p['confirm']) ? $p['confirm'] : '') !== 'DELETE') {
            $json['error'] = $this->language->get('error_wipe_confirm');
        } else {
            $list = $entity === 'all' ? $all : array($entity);
            $this->jobTable();
            @set_time_limit(600);

            if (!empty($p['wipe'])) {
                foreach ($list as $e) {
                    $this->wipe($e);
                }
            }

            foreach ($list as $e) {
                $row = $this->db->query("SELECT status, job FROM `" . DB_PREFIX . "growthcontour_sync_job` WHERE entity = '" . $this->db->escape($e) . "'")->row;
                $resume = !empty($p['resume']) && empty($p['wipe']) && $row && $row['job'] !== '' && in_array($row['status'], array('cancelled', 'error', 'running'), true);
                $this->db->query(
                    "INSERT INTO `" . DB_PREFIX . "growthcontour_sync_job` SET entity = '" . $this->db->escape($e) . "', status = 'queued',
                     force_all = '" . (!empty($p['force']) ? 1 : 0) . "', resume = '" . ($resume ? 1 : 0) . "', cancel = 0, updated_at = NOW()
                     ON DUPLICATE KEY UPDATE status = 'queued', force_all = VALUES(force_all), resume = VALUES(resume), cancel = 0,
                     job = IF(VALUES(resume) = 1, job, ''), updated_at = NOW()"
                );
            }

            $domain = rtrim(trim((string) $this->config->get('module_growthcontour_domain')), '/');
            $token = trim((string) $this->config->get('module_growthcontour_token'));
            if (!$domain || !$token) {
                $json['error'] = $this->language->get('error_credentials');
            } else {
                $ch = curl_init($domain . '/api/integrations/v1/sync/');
                curl_setopt_array($ch, array(
                    CURLOPT_POST => true,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_CONNECTTIMEOUT => 10,
                    CURLOPT_TIMEOUT => 30,
                    CURLOPT_HTTPHEADER => array('Content-Type: application/json', 'Authorization: Bearer ' . $token),
                    CURLOPT_POSTFIELDS => json_encode(array('entity' => $entity)),
                ));
                $raw = curl_exec($ch);
                $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $err = curl_error($ch);
                curl_close($ch);

                $data = json_decode((string) $raw, true);
                if ($code >= 200 && $code < 300 && !empty($data['ok'])) {
                    $json['result'] = $data['result'];
                } else {
                    $json['error'] = $err ?: (isset($data['error']) ? $data['error'] : (isset($data['reason']) ? $data['reason'] : 'HTTP ' . $code));
                }
            }

            // Що не стартувало — не лишаємо «в черзі»
            foreach ($list as $e) {
                $st = isset($json['result'][$e]['status']) ? $json['result'][$e]['status'] : 'error';
                if ($st !== 'started') {
                    $msg = isset($json['error']) ? $json['error'] : $st;
                    $this->db->query(
                        "UPDATE `" . DB_PREFIX . "growthcontour_sync_job` SET status = '" . ($st === 'busy' ? 'running' : 'error') . "',
                         last_error = '" . $this->db->escape(mb_substr($msg, 0, 500)) . "', updated_at = NOW()
                         WHERE entity = '" . $this->db->escape($e) . "' AND status = 'queued'"
                    );
                }
            }
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }

    public function catalogCancel()
    {
        $this->load->language('extension/module/growthcontour');
        $json = array();
        $entity = isset($this->request->post['entity']) ? (string) $this->request->post['entity'] : '';

        if (!$this->user->hasPermission('modify', 'extension/module/growthcontour')) {
            $json['error'] = $this->language->get('error_permission');
        } else {
            $this->jobTable();
            $where = $entity === 'all' ? "1" : "entity = '" . $this->db->escape($entity) . "'";
            $this->db->query("UPDATE `" . DB_PREFIX . "growthcontour_sync_job` SET status = 'cancelled', finished_at = NOW() WHERE " . $where . " AND status = 'queued'");
            $this->db->query("UPDATE `" . DB_PREFIX . "growthcontour_sync_job` SET cancel = 1 WHERE " . $where . " AND status = 'running'");
            $json['success'] = 1;
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }

    private function wipeTable($table, $where = '')
    {
        if (!$this->db->query("SHOW TABLES LIKE '" . $this->db->escape(DB_PREFIX . $table) . "'")->num_rows) {
            return;
        }
        if ($where) {
            $this->db->query("DELETE FROM `" . DB_PREFIX . $table . "` WHERE " . $where);
            return;
        }
        try {
            $this->db->query("TRUNCATE TABLE `" . DB_PREFIX . $table . "`");
        } catch (Exception $e) {
            $this->db->query("DELETE FROM `" . DB_PREFIX . $table . "`");
        }
    }

    // Повне очищення розділу на сайті перед завантаженням із системи (демо-дані тощо)
    private function wipe($entity)
    {
        $map = array();
        if ($entity === 'products') {
            foreach (array('product', 'product_attribute', 'product_description', 'product_discount', 'product_filter', 'product_image', 'product_option',
                           'product_option_value', 'product_related', 'product_reward', 'product_special', 'product_to_category', 'product_to_download',
                           'product_to_layout', 'product_to_store', 'product_recurring', 'review', 'coupon_product') as $t) {
                $this->wipeTable($t);
            }
            foreach (array('option', 'option_description', 'option_value', 'option_value_description') as $t) {
                $this->wipeTable($t);
            }
            $this->wipeTable('seo_url', "`query` LIKE 'product\\_id=%'");
            $map = array('product', 'option', 'option_value');
        } elseif ($entity === 'categories') {
            foreach (array('category', 'category_description', 'category_filter', 'category_path', 'category_to_layout', 'category_to_store',
                           'product_to_category', 'coupon_category') as $t) {
                $this->wipeTable($t);
            }
            $this->wipeTable('seo_url', "`query` LIKE 'category\\_id=%'");
            $map = array('category');
        } elseif ($entity === 'attributes') {
            foreach (array('attribute', 'attribute_description', 'attribute_group', 'attribute_group_description', 'product_attribute') as $t) {
                $this->wipeTable($t);
            }
            $map = array('attribute', 'attribute_group');
        } elseif ($entity === 'reviews') {
            $this->wipeTable('review');
            $map = array('review');
        } elseif ($entity === 'customers') {
            foreach (array('customer', 'address', 'customer_activity', 'customer_affiliate', 'customer_approval', 'customer_history', 'customer_ip',
                           'customer_login', 'customer_online', 'customer_reward', 'customer_search', 'customer_transaction', 'customer_wishlist') as $t) {
                $this->wipeTable($t);
            }
            $map = array('customer');
        }

        if ($map && $this->db->query("SHOW TABLES LIKE '" . DB_PREFIX . "growthcontour_map'")->num_rows) {
            foreach ($map as $k) {
                $this->db->query("DELETE FROM `" . DB_PREFIX . "growthcontour_map` WHERE entity = '" . $k . "'");
            }
        }
        $this->db->query("DELETE FROM `" . DB_PREFIX . "growthcontour_sync_job` WHERE entity = '" . $this->db->escape($entity) . "'");
        $this->cache->delete('product');
        $this->cache->delete('category');
    }

    public function clearLog()
    {
        $this->load->language('extension/module/growthcontour');

        $json = array();

        if (!$this->user->hasPermission('modify', 'extension/module/growthcontour')) {
            $json['error'] = $this->language->get('error_permission');
        } else {
            $this->load->model('extension/module/growthcontour');

            $this->model_extension_module_growthcontour->clearLog();

            $json['success'] = $this->language->get('text_clear_success');
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }

    // ─── Імпорт у систему: проксі до API системи (вкладка «Імпорт у систему») ───
    // op: options | start | status | items | cancel | rollback. Запуск, скасування й відкат — лише з правом «змінювати»
    public function importApi()
    {
        $this->load->language('extension/module/growthcontour');
        $json = array();
        $ops = array('options', 'start', 'status', 'items', 'cancel', 'rollback');
        $op = isset($this->request->post['op']) ? (string) $this->request->post['op'] : '';
        $write = in_array($op, array('start', 'cancel', 'rollback'), true);

        if (!in_array($op, $ops, true)) {
            $json['error'] = 'bad op';
        } elseif (!$this->user->hasPermission($write ? 'modify' : 'access', 'extension/module/growthcontour')) {
            $json['error'] = $this->language->get('error_permission');
        } else {
            $domain = rtrim(trim((string) $this->config->get('module_growthcontour_domain')), '/');
            $token = trim((string) $this->config->get('module_growthcontour_token'));
            if (!$domain || !$token) {
                $json['error'] = $this->language->get('error_credentials');
            } else {
                // Тіло запиту — JSON-рядок із форми (html_entity_decode: OpenCart екранує POST)
                $raw = isset($this->request->post['payload']) ? html_entity_decode((string) $this->request->post['payload'], ENT_QUOTES, 'UTF-8') : '{}';
                $payload = json_decode($raw, true);
                if (!is_array($payload)) {
                    $payload = array();
                }

                $ch = curl_init($domain . '/api/integrations/v1/import/' . $op . '/');
                curl_setopt_array($ch, array(
                    CURLOPT_POST => true,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_CONNECTTIMEOUT => 10,
                    CURLOPT_TIMEOUT => 30,
                    CURLOPT_HTTPHEADER => array('Content-Type: application/json', 'Accept: application/json', 'Authorization: Bearer ' . $token),
                    CURLOPT_POSTFIELDS => json_encode($payload ? $payload : new stdClass()),
                ));
                $res = curl_exec($ch);
                $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $err = curl_error($ch);
                curl_close($ch);

                $data = json_decode((string) $res, true);
                if ($code >= 200 && $code < 300 && !empty($data['ok'])) {
                    $json = $data;
                } else {
                    $reason = isset($data['error']) ? $data['error'] : (isset($data['reason']) ? $data['reason'] : '');
                    if ($reason === 'no_scope') {
                        $reason = $this->language->get('error_import_scope');
                        // Рядка немає в мовному файлі (інша мова адмінки) — зрозумілий текст замість ключа
                        if ($reason === 'error_import_scope') {
                            $reason = 'Ключ доступу не має права «Імпортувати товари з сайту». Увімкніть його в системі: Налаштування → Токени → редагування токена цього сайту.';
                        }
                    }
                    $json['error'] = $err ? $err : ($reason ? $reason : 'HTTP ' . $code);
                }
            }
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }

    // ─── Налаштування імпорту: окремий ключ у таблиці setting (code = growthcontour_import),
    // щоб «Зберегти» налаштувань модуля (editSetting module_growthcontour) їх не стирало ───
    private function importOptions()
    {
        $q = $this->db->query("SELECT `value` FROM `" . DB_PREFIX . "setting` WHERE store_id = '0' AND `code` = 'growthcontour_import' AND `key` = 'growthcontour_import_options' LIMIT 1");
        return $q->num_rows ? (string) $q->row['value'] : '';
    }

    private function importStore($options)
    {
        $value = json_encode($options, JSON_UNESCAPED_UNICODE);
        $this->db->query("DELETE FROM `" . DB_PREFIX . "setting` WHERE store_id = '0' AND `code` = 'growthcontour_import' AND `key` = 'growthcontour_import_options'");
        $this->db->query("INSERT INTO `" . DB_PREFIX . "setting` SET store_id = '0', `code` = 'growthcontour_import', `key` = 'growthcontour_import_options', `value` = '" . $this->db->escape($value) . "', serialized = '0'");
    }

    public function importSave()
    {
        $this->load->language('extension/module/growthcontour');
        $json = array();
        if (!$this->user->hasPermission('modify', 'extension/module/growthcontour')) {
            $json['error'] = $this->language->get('error_permission');
        } else {
            $raw = isset($this->request->post['options']) ? html_entity_decode((string) $this->request->post['options'], ENT_QUOTES, 'UTF-8') : '';
            $options = json_decode($raw, true);
            if (!is_array($options)) {
                $json['error'] = $this->language->get('error_generic');
            } else {
                $this->importStore($options);
                $json['success'] = $this->language->get('text_imp_saved');
            }
        }
        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }

    // Довідники сайту для форми імпорту: мови, групи покупців, кількість товарів
    public function importSiteMeta()
    {
        $json = array('languages' => array(), 'customer_groups' => array());
        if ($this->user->hasPermission('access', 'extension/module/growthcontour')) {
            foreach ($this->db->query("SELECT language_id, code, name FROM `" . DB_PREFIX . "language` ORDER BY sort_order, language_id")->rows as $l) {
                $json['languages'][] = array('id' => (int) $l['language_id'], 'code' => $l['code'], 'name' => $l['name']);
            }
            $lid = (int) $this->config->get('config_language_id');
            foreach ($this->db->query(
                "SELECT cg.customer_group_id, COALESCE(cgd.name, CONCAT('#', cg.customer_group_id)) AS name
                   FROM `" . DB_PREFIX . "customer_group` cg
                   LEFT JOIN `" . DB_PREFIX . "customer_group_description` cgd ON cgd.customer_group_id = cg.customer_group_id AND cgd.language_id = '" . $lid . "'
                  ORDER BY cg.sort_order, cg.customer_group_id"
            )->rows as $g) {
                $json['customer_groups'][] = array('id' => (int) $g['customer_group_id'], 'name' => html_entity_decode($g['name'], ENT_QUOTES, 'UTF-8'));
            }
            $json['default_customer_group'] = (int) $this->config->get('config_customer_group_id');
            $json['products'] = (int) $this->db->query("SELECT COUNT(*) AS n FROM `" . DB_PREFIX . "product`")->row['n'];
        }
        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }

    // ─── Доставка: правила розбору й останні знімки (вкладка «Доставка») ───
    private function deliveryLib()
    {
        require_once(DIR_SYSTEM . 'library/growthcontour/delivery.php');
        $lib = new GrowthcontourDelivery($this->registry);
        $lib->install();
        return $lib;
    }

    private function deliveryJson($json)
    {
        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR));
    }

    // Останні замовлення з розбором доставки (фільтр за станом)
    public function deliveryList()
    {
        if (!$this->user->hasPermission('access', 'extension/module/growthcontour')) {
            return $this->deliveryJson(array('error' => 'permission'));
        }
        $lib = $this->deliveryLib();
        $status = isset($this->request->get['status']) ? preg_replace('/[^a-z]/', '', $this->request->get['status']) : '';
        $where = $status ? " WHERE d.status = '" . $this->db->escape($status) . "'" : '';
        $rows = $this->db->query(
            "SELECT d.order_id, d.method_code, d.carrier, d.service, d.status, d.city, d.city_ref, d.point, d.point_ref, d.point_number, d.street, d.building,
                    d.found_by, d.date_modified, o.shipping_method
               FROM `" . DB_PREFIX . "growthcontour_order_delivery` d
               LEFT JOIN `" . DB_PREFIX . "order` o ON o.order_id = d.order_id" . $where . "
              ORDER BY d.order_id DESC LIMIT 50"
        )->rows;
        $counts = array();
        foreach ($this->db->query("SELECT status, COUNT(*) AS n FROM `" . DB_PREFIX . "growthcontour_order_delivery` GROUP BY status")->rows as $r) {
            $counts[$r['status']] = (int) $r['n'];
        }
        $this->deliveryJson(array('rows' => $rows, 'counts' => $counts, 'rules' => $lib->rulesText()));
    }

    // Знімок одного замовлення (що модуль доставки залишив у замовленні, сесії, POST)
    public function deliveryRaw()
    {
        if (!$this->user->hasPermission('access', 'extension/module/growthcontour')) {
            return $this->deliveryJson(array('error' => 'permission'));
        }
        $row = $this->deliveryLib()->get(isset($this->request->get['order_id']) ? (int) $this->request->get['order_id'] : 0);
        $this->deliveryJson(array('raw' => $row ? json_decode($row['raw'], true) : null));
    }

    // Зберегти правила
    public function deliverySave()
    {
        $this->load->language('extension/module/growthcontour');
        if (!$this->user->hasPermission('modify', 'extension/module/growthcontour')) {
            return $this->deliveryJson(array('error' => $this->language->get('error_permission')));
        }
        $text = isset($this->request->post['rules']) ? html_entity_decode((string) $this->request->post['rules'], ENT_QUOTES, 'UTF-8') : '';
        $err = $this->deliveryLib()->saveRules($text);
        $this->deliveryJson($err ? array('error' => sprintf($this->language->get('error_delivery_rules'), $err)) : array('success' => $this->language->get('text_delivery_saved')));
    }

    // Перевірити правила (ще не збережені) на замовленні — нічого не записує
    public function deliveryTest()
    {
        $this->load->language('extension/module/growthcontour');
        if (!$this->user->hasPermission('access', 'extension/module/growthcontour')) {
            return $this->deliveryJson(array('error' => $this->language->get('error_permission')));
        }
        $lib = $this->deliveryLib();
        $text = isset($this->request->post['rules']) ? html_entity_decode((string) $this->request->post['rules'], ENT_QUOTES, 'UTF-8') : '';
        $rules = json_decode($text, true);
        if (!is_array($rules)) {
            return $this->deliveryJson(array('error' => sprintf($this->language->get('error_delivery_rules'), 'JSON: ' . json_last_error_msg())));
        }
        $res = $lib->reparse(isset($this->request->post['order_id']) ? (int) $this->request->post['order_id'] : 0, false, $rules);
        $this->deliveryJson($res ? array('result' => $res) : array('error' => $this->language->get('error_delivery_order')));
    }

    // Перерахувати збережені знімки за поточними правилами: останні N замовлень (без знімка — за полями замовлення)
    public function deliveryReparse()
    {
        $this->load->language('extension/module/growthcontour');
        if (!$this->user->hasPermission('modify', 'extension/module/growthcontour')) {
            return $this->deliveryJson(array('error' => $this->language->get('error_permission')));
        }
        @set_time_limit(300);
        $lib = $this->deliveryLib();
        $n = isset($this->request->post['count']) ? max(1, min(5000, (int) $this->request->post['count'])) : 200;
        $ids = $this->db->query("SELECT order_id FROM `" . DB_PREFIX . "order` WHERE order_status_id > 0 ORDER BY order_id DESC LIMIT " . $n)->rows;
        $stat = array();
        foreach ($ids as $r) {
            $res = $lib->reparse((int) $r['order_id']);
            $s = $res ? $res['status'] : 'none';
            $stat[$s] = isset($stat[$s]) ? $stat[$s] + 1 : 1;
        }
        $this->deliveryJson(array('success' => sprintf($this->language->get('text_delivery_reparsed'), count($ids)), 'stat' => $stat));
    }

    // ─── Значення за замовчуванням: усе вимкнено. Нічого не передається і не змінюється на сайті, доки ви самі не ввімкнете ───
    private function defaults()
    {
        return array(
            'module_growthcontour_status' => 0,
            'module_growthcontour_orders_status' => 0,
            'module_growthcontour_cart_enabled' => 0,
            'module_growthcontour_sync_batch' => 100,
            'module_growthcontour_sync_products' => 0,
            'module_growthcontour_sync_products_auto' => 0,
            'module_growthcontour_sync_products_match' => 'sku',
            'module_growthcontour_sync_products_create' => 0,
            'module_growthcontour_sync_products_update' => 0,
            'module_growthcontour_sync_products_missing' => 'keep',
            'module_growthcontour_sync_products_sku' => 'model',
            'module_growthcontour_sync_products_fields' => array(),
            'module_growthcontour_sync_categories' => 0,
            'module_growthcontour_sync_categories_auto' => 0,
            'module_growthcontour_sync_categories_match' => 'name',
            'module_growthcontour_sync_categories_create' => 0,
            'module_growthcontour_sync_categories_update' => 0,
            'module_growthcontour_sync_categories_missing' => 'keep',
            'module_growthcontour_sync_attributes' => 0,
            'module_growthcontour_sync_attributes_auto' => 0,
            'module_growthcontour_sync_attributes_match' => 'name',
            'module_growthcontour_sync_attributes_create' => 0,
            'module_growthcontour_sync_attributes_update' => 0,
            'module_growthcontour_sync_attributes_missing' => 'keep',
            'module_growthcontour_sync_reviews' => 0,
            'module_growthcontour_sync_reviews_auto' => 0,
            'module_growthcontour_sync_reviews_match' => 'map',
            'module_growthcontour_sync_reviews_create' => 0,
            'module_growthcontour_sync_reviews_update' => 0,
            'module_growthcontour_sync_reviews_missing' => 'keep',
            'module_growthcontour_sync_reviews_moderation' => 0,
            'module_growthcontour_sync_customers' => 0,
            'module_growthcontour_sync_customers_auto' => 0,
            'module_growthcontour_sync_customers_match' => 'email',
            'module_growthcontour_sync_customers_create' => 0,
            'module_growthcontour_sync_customers_update' => 0,
            'module_growthcontour_sync_customers_missing' => 'keep',
        );
    }

    // ─── Кнопка «Зберегти» вгорі: усі налаштування модуля, імпорту й правила доставки — без перезавантаження сторінки ───
    public function save()
    {
        $this->load->language('extension/module/growthcontour');
        $json = array();

        if (!$this->user->hasPermission('modify', 'extension/module/growthcontour')) {
            $json['error'] = $this->language->get('error_permission');
        } else {
            $p = $this->request->post;
            $warnings = array();

            // Налаштування імпорту (лише якщо вкладку відкривали)
            if (isset($p['gc_import_options'])) {
                $options = json_decode(html_entity_decode((string) $p['gc_import_options'], ENT_QUOTES, 'UTF-8'), true);
                if (is_array($options)) {
                    $this->importStore($options);
                }
            }

            // Правила доставки (лише якщо вкладку відкривали); помилка в правилах не заважає зберегти решту
            if (isset($p['gc_delivery_rules']) && is_file(DIR_SYSTEM . 'library/growthcontour/delivery.php')) {
                $err = $this->deliveryLib()->saveRules(html_entity_decode((string) $p['gc_delivery_rules'], ENT_QUOTES, 'UTF-8'));
                if ($err) {
                    $warnings[] = sprintf($this->language->get('error_delivery_rules'), $err);
                }
            }

            $this->saveSettings($p);

            $json['success'] = $this->language->get('text_success');
            if ($warnings) {
                $json['warning'] = implode(' ', $warnings);
            }
        }

        $json['diag'] = $this->ordersDiag();

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json, JSON_UNESCAPED_UNICODE));
    }

    // Зберегти поля форми (module_growthcontour_*) і перевірити події
    private function saveSettings($post)
    {
        $this->load->model('setting/setting');
        $old = $this->model_setting_setting->getSetting('module_growthcontour');

        $settings = array();
        foreach ($post as $k => $v) {
            if (strpos($k, 'module_growthcontour_') === 0) {
                $settings[$k] = $v;
            }
        }

        // Ключі доступу системи до сайту змінюються лише кнопками біля поля — збереження форми їх не чіпає
        foreach (array('module_growthcontour_inbound_token', 'module_growthcontour_inbound_token_old') as $k) {
            $settings[$k] = isset($old[$k]) ? $old[$k] : '';
        }

        // Невідмічені прапорці браузер не надсилає — зберігаємо порожній список (а не «за замовчуванням»)
        foreach (array('module_growthcontour_paid_statuses', 'module_growthcontour_sync_products_fields', 'module_growthcontour_status_map', 'module_growthcontour_status_map_in', 'module_growthcontour_crm_statuses') as $k) {
            if (!isset($settings[$k]) || !is_array($settings[$k])) {
                $settings[$k] = array();
            }
        }
        $settings['module_growthcontour_sync_products_fields'] = array_values(array_filter($settings['module_growthcontour_sync_products_fields']));
        $settings['module_growthcontour_paid_statuses'] = array_values(array_filter($settings['module_growthcontour_paid_statuses']));

        $this->model_setting_setting->editSetting('module_growthcontour', $settings);

        $this->ensureEvents();
    }

    // Події OpenCart, через які працює модуль
    private function events()
    {
        return array(
            'catalog/model/checkout/order/addOrder/after' => 'extension/module/growthcontour/orderAdd',
            'catalog/model/checkout/order/addOrderHistory/after' => 'extension/module/growthcontour/orderHistory',
            'catalog/view/common/footer/after' => 'extension/module/growthcontour/footerTracker',
            'catalog/controller/common/cart/after' => 'extension/module/growthcontour/cartTouch',
        );
    }

    // Відсутні події — додати, вимкнені — увімкнути (без них замовлення не передаються)
    private function ensureEvents()
    {
        $this->load->model('setting/event');

        foreach ($this->events() as $trigger => $action) {
            $q = $this->db->query(
                "SELECT event_id, status FROM `" . DB_PREFIX . "event`
                  WHERE `code` = 'growthcontour' AND `trigger` = '" . $this->db->escape($trigger) . "' AND `action` = '" . $this->db->escape($action) . "'"
            );
            if (!$q->num_rows) {
                $this->model_setting_event->addEvent('growthcontour', $trigger, $action);
            } elseif (!(int) $q->row['status']) {
                $this->db->query("UPDATE `" . DB_PREFIX . "event` SET status = '1' WHERE event_id = '" . (int) $q->row['event_id'] . "'");
            }
        }
    }

    // Перевірка передачі замовлень: що саме заважає (вкладка «Замовлення»)
    private function ordersDiag()
    {
        $this->load->language('extension/module/growthcontour');
        $this->load->model('setting/setting');
        $s = $this->model_setting_setting->getSetting('module_growthcontour');
        $get = function ($k) use ($s) {
            return isset($s['module_growthcontour_' . $k]) ? $s['module_growthcontour_' . $k] : '';
        };
        $L = $this->language;
        $out = array();

        $out[] = array('ok' => (bool) $get('status'), 'text' => $L->get($get('status') ? 'diag_module_ok' : 'diag_module_bad'));
        $out[] = array('ok' => (bool) $get('orders_status'), 'text' => $L->get($get('orders_status') ? 'diag_orders_ok' : 'diag_orders_bad'));
        $creds = trim((string) $get('domain')) !== '' && trim((string) $get('token')) !== '';
        $out[] = array('ok' => $creds, 'text' => $L->get($creds ? 'diag_creds_ok' : 'diag_creds_bad'));

        // Події замовлень
        $bad = array();
        foreach (array_slice($this->events(), 0, 2, true) as $trigger => $action) {
            $q = $this->db->query(
                "SELECT status FROM `" . DB_PREFIX . "event`
                  WHERE `code` = 'growthcontour' AND `trigger` = '" . $this->db->escape($trigger) . "' AND `action` = '" . $this->db->escape($action) . "'"
            );
            if (!$q->num_rows || !(int) $q->row['status']) {
                $bad[] = $trigger;
            }
        }
        $out[] = array('ok' => !$bad, 'text' => $bad ? sprintf($L->get('diag_events_bad'), implode(', ', $bad)) : $L->get('diag_events_ok'));

        // Файли сайту: є і з одного пакета
        $ctl = DIR_CATALOG . 'controller/extension/module/growthcontour.php';
        $mdl = DIR_CATALOG . 'model/extension/module/growthcontour.php';
        $missing = array();
        foreach (array($ctl, $mdl) as $f) {
            if (!is_file($f)) {
                $missing[] = str_replace(DIR_CATALOG, 'catalog/', $f);
            }
        }
        if ($missing) {
            $out[] = array('ok' => false, 'text' => sprintf($L->get('diag_files_missing'), implode(', ', $missing)));
        } else {
            $c = (string) file_get_contents($ctl);
            $m = (string) file_get_contents($mdl);
            if (strpos($c, 'captureDelivery(') !== false && strpos($m, 'function captureDelivery') === false) {
                $out[] = array('ok' => false, 'text' => $L->get('diag_files_mismatch'));
            } elseif (strpos($m, 'growthcontour/delivery.php') !== false && !is_file(DIR_SYSTEM . 'library/growthcontour/delivery.php')) {
                $out[] = array('ok' => false, 'text' => sprintf($L->get('diag_files_missing'), 'system/library/growthcontour/delivery.php'));
            } else {
                $out[] = array('ok' => true, 'text' => $L->get('diag_files_ok'));
            }
        }

        // Остання передача замовлення
        $this->load->model('extension/module/growthcontour');
        $last = null;
        foreach ($this->model_extension_module_growthcontour->getLogs(500) as $e) {
            if (isset($e['event']) && $e['event'] === 'send') {
                $last = $e;
                break;
            }
        }
        if (!$last) {
            $out[] = array('ok' => null, 'text' => $L->get('diag_last_none'));
        } elseif (isset($last['result']) && ($last['result'] === 'success' || $last['result'] === 'duplicate')) {
            $out[] = array('ok' => true, 'text' => sprintf($L->get('diag_last_ok'), $last['ts'], isset($last['order_id']) ? $last['order_id'] : ''));
        } else {
            $out[] = array('ok' => false, 'text' => sprintf($L->get('diag_last_bad'), $last['ts'], isset($last['order_id']) ? $last['order_id'] : '', 'HTTP ' . (isset($last['http_code']) ? $last['http_code'] : 0) . ' — ' . (isset($last['message']) ? $last['message'] : '')));
        }

        return $out;
    }

    public function install()
    {
        // Події: замовлення, зміни статусу, трекер контактів у чекауті, живий кошик
        $this->ensureEvents();

        // Таблиця-буфер абандон-кошика
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

        $this->load->model('setting/setting');
        $settings = $this->model_setting_setting->getSetting('module_growthcontour');
        // Після встановлення все вимкнено: значення записуються явно, щоб сайт не брав «увімкнені» значення за замовчуванням
        foreach ($this->defaults() as $k => $v) {
            if (!array_key_exists($k, $settings)) {
                $settings[$k] = $v;
            }
        }
        // Автогенерація вхідного токена (яким система автентифікується на сайті)
        if (empty($settings['module_growthcontour_inbound_token'])) {
            $settings['module_growthcontour_inbound_token'] = bin2hex(random_bytes(24));
        }
        $this->model_setting_setting->editSetting('module_growthcontour', $settings);
    }

    public function uninstall()
    {
        $this->load->model('setting/event');

        $this->model_setting_event->deleteEventByCode('growthcontour');
    }

    protected function validate()
    {
        if (!$this->user->hasPermission('modify', 'extension/module/growthcontour')) {
            $this->error['warning'] = $this->language->get('error_permission');
        }

        return !$this->error;
    }

    private function getOrigin()
    {
        $https = (isset($this->request->server['HTTPS']) && ($this->request->server['HTTPS'] == 'on' || $this->request->server['HTTPS'] == '1'));

        $scheme = $https ? 'https://' : 'http://';

        $host = isset($this->request->server['HTTP_HOST']) ? $this->request->server['HTTP_HOST'] : '';

        return $host ? ($scheme . $host) : '';
    }
}
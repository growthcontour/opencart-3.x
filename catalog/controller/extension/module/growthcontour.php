<?php
class ControllerExtensionModuleGrowthcontour extends Controller
{

	// Fired on: catalog/model/checkout/order/addOrder/after
	public function orderAdd(&$route, &$args, &$output)
	{
		$order_id = (int) $output;

		if (!$order_id) {
			return;
		}

		$this->load->model('extension/module/growthcontour');

		// Знімок доставки — до відправки замовлення (сесія з вибором відділення ще жива)
		$this->model_extension_module_growthcontour->captureDelivery($order_id, 'add');

		// Абандон-кошик: закриваємо кошик як відновлений (незалежно від надсилання замовлень)
		if ($this->config->get('module_growthcontour_cart_enabled')) {
			$session_id = (isset($this->session) && method_exists($this->session, 'getId')) ? $this->session->getId() : '';
			$this->model_extension_module_growthcontour->recoverCart($order_id, $session_id);
		}

		// Замовлення НЕ надсилаємо тут: addOrder спрацьовує до оплати (статус 0), коли модулі доставки
		// ще не дописали відділення. Повні дані йдуть з addOrderHistory — коли замовлення отримує статус.
	}

	// Fired on: catalog/model/checkout/order/addOrderHistory/after
	public function orderHistory(&$route, &$args, &$output)
	{
		if (!$this->enabled()) {
			return;
		}

		$order_id = isset($args[0]) ? (int) $args[0] : 0;

		if (!$order_id) {
			return;
		}

		$this->load->model('extension/module/growthcontour');
		// Модуль доставки міг дописати дані в замовлення пізніше — оновлюємо знімок
		$this->model_extension_module_growthcontour->captureDelivery($order_id, 'history');
		$this->model_extension_module_growthcontour->sendOrder($order_id);
	}

	// Публічний тригер відправки одного замовлення в CRM (для bulk із адмінки).
	// Захищено inbound-токеном, щоб ніхто сторонній не смикав.
	public function push()
	{
		if ($this->request->server['REQUEST_METHOD'] !== 'POST') {
			http_response_code(405);
			return;
		}

		if ($this->inboundToken() === '') {
			http_response_code(401);
			return;
		}

		$body = json_decode(file_get_contents('php://input'), true);
		$order_id = isset($body['order_id']) ? (int) $body['order_id'] : 0;

		if (!$order_id) {
			http_response_code(422);
			return;
		}

		$this->load->model('extension/module/growthcontour');
		$this->model_extension_module_growthcontour->sendOrder($order_id);

		http_response_code(200);
		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode(array('status' => 'ok')));
	}

	// ─────────────────────────────────────────────────────────────────────
	//  CRON абандон-кошика. Захищено inbound-токеном (як push).
	//  Зовнішній виклик:
	//    curl -H "Authorization: Bearer <inbound_token>" \
	//         "https://STORE/index.php?route=extension/module/growthcontour/cartCron"
	// ─────────────────────────────────────────────────────────────────────
	public function cartCron()
	{
		$token_ok = $this->inboundToken() !== '';
		if (!$token_ok && isset($this->request->get['token'])) {
			// токен у URL для простого GET-крону (лише поточний)
			$q = trim((string) $this->request->get['token']);
			$cur = trim((string) $this->config->get('module_growthcontour_inbound_token'));
			$token_ok = ($q !== '' && $cur !== '' && hash_equals($cur, $q));
		}

		if (!$token_ok) {
			http_response_code(401);
			$this->response->addHeader('Content-Type: application/json');
			$this->response->setOutput(json_encode(array('error' => 'unauthorized')));
			return;
		}

		if (!$this->config->get('module_growthcontour_cart_enabled')) {
			$this->response->addHeader('Content-Type: application/json');
			$this->response->setOutput(json_encode(array('status' => 'disabled')));
			return;
		}

		$opts = array(
			'threshold_min' => $this->config->get('module_growthcontour_cart_threshold_min') ?: 60,
			'max_age_hours' => $this->config->get('module_growthcontour_cart_max_age_hours') ?: 168,
			'min_amount' => $this->config->get('module_growthcontour_cart_min_amount') ?: 0,
			'batch' => $this->config->get('module_growthcontour_cart_batch') ?: 50,
			'guests' => $this->config->get('module_growthcontour_cart_guests') ? 1 : 0,
			'require_contact' => $this->config->get('module_growthcontour_cart_require_contact') ? 1 : 0,
			'require_consent' => $this->config->get('module_growthcontour_cart_require_consent') ? 1 : 0,
		);

		$this->load->model('extension/module/growthcontour');
		$summary = $this->model_extension_module_growthcontour->runCartCron($opts);

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode(array('status' => 'ok', 'summary' => $summary)));
	}

	// ─────────────────────────────────────────────────────────────────────
	//  RECONCILE кошиків (mark-and-sweep). Захищено inbound-токеном.
	//  Планувальник (рідше за cartCron, напр. щогодини):
	//    curl -H "Authorization: Bearer <inbound_token>" \
	//         "https://STORE/index.php?route=extension/module/growthcontour/cartReconcile"
	// ─────────────────────────────────────────────────────────────────────
	public function cartReconcile()
	{
		if ($this->inboundToken() === '') {
			http_response_code(401);
			$this->response->addHeader('Content-Type: application/json');
			$this->response->setOutput(json_encode(array('error' => 'unauthorized')));
			return;
		}

		if (!$this->config->get('module_growthcontour_cart_enabled')) {
			$this->response->addHeader('Content-Type: application/json');
			$this->response->setOutput(json_encode(array('status' => 'disabled')));
			return;
		}

		$this->load->model('extension/module/growthcontour');
		$summary = $this->model_extension_module_growthcontour->reconcileCarts();

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode(array('status' => 'ok', 'summary' => $summary)));
	}

	// ─────────────────────────────────────────────────────────────────────
	//  BEACON: захоплення контактів гостя в чекауті (session-scoped, без токена).
	//  Пише лише в буфер власної сесії; читання назад немає.
	// ─────────────────────────────────────────────────────────────────────
	public function beacon()
	{
		$this->response->addHeader('Content-Type: application/json; charset=utf-8');

		if ($this->request->server['REQUEST_METHOD'] !== 'POST') {
			http_response_code(405);
			$this->response->setOutput(json_encode(array('status' => 'error')));
			return;
		}
		if (!$this->config->get('module_growthcontour_cart_enabled')) {
			$this->response->setOutput(json_encode(array('status' => 'disabled')));
			return;
		}

		$session_id = (isset($this->session) && method_exists($this->session, 'getId')) ? $this->session->getId() : '';
		if ($session_id === '') {
			http_response_code(400);
			$this->response->setOutput(json_encode(array('status' => 'no_session')));
			return;
		}

		$body = json_decode(file_get_contents('php://input'), true);
		if (!is_array($body)) {
			$body = array();
		}

		$email = isset($body['email']) ? trim((string) $body['email']) : '';
		$phone = isset($body['telephone']) ? trim((string) $body['telephone']) : '';
		if ($email === '' && $phone === '') {
			$this->response->setOutput(json_encode(array('status' => 'empty')));
			return;
		}
		if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
			$email = '';
		}

		$data = array(
			'email' => $email !== '' ? mb_substr($email, 0, 96) : null,
			'telephone' => $phone !== '' ? mb_substr(preg_replace('/[^\d+]/', '', $phone), 0, 32) : null,
			'firstname' => isset($body['firstname']) ? mb_substr(trim((string) $body['firstname']), 0, 64) : null,
			'lastname' => isset($body['lastname']) ? mb_substr(trim((string) $body['lastname']), 0, 64) : null,
			'consent' => !empty($body['consent']) ? 1 : 0,
			'utm_source' => isset($body['utm_source']) ? mb_substr(trim((string) $body['utm_source']), 0, 100) : null,
			'utm_medium' => isset($body['utm_medium']) ? mb_substr(trim((string) $body['utm_medium']), 0, 100) : null,
			'utm_campaign' => isset($body['utm_campaign']) ? mb_substr(trim((string) $body['utm_campaign']), 0, 100) : null,
		);

		$this->load->model('extension/module/growthcontour');
		$this->model_extension_module_growthcontour->beaconUpsert(
			(int) $this->config->get('config_store_id'),
			$session_id,
			(int) $this->customer->getId(),
			$data
		);

		$this->response->setOutput(json_encode(array('status' => 'ok')));
	}

	// Fired on: catalog/view/common/footer/after — інжект трекера лише в чекауті
	public function footerTracker(&$route, &$args, &$output)
	{
		if (!$this->config->get('module_growthcontour_cart_enabled')) {
			return;
		}

		$cur = isset($this->request->get['route']) ? (string) $this->request->get['route'] : '';
		if (strpos($cur, 'checkout/') !== 0) {
			return;
		}

		$url = str_replace('&amp;', '&', $this->url->link('extension/module/growthcontour/beacon', '', true));

		$js = "\n<script>(function(){"
			. "var U='" . $url . "';"
			. "function q(n){var m=new RegExp('[?&]'+n+'=([^&]+)').exec(location.search);return m?decodeURIComponent(m[1]):'';}"
			. "var t=null;function grab(){"
			. "var g=function(s){var e=document.querySelector(s);return e?e.value:'';};"
			. "var email=g(\"input[name='email']\")||g('#input-payment-email')||g(\"input[type='email']\");"
			. "var phone=g(\"input[name='telephone']\")||g('#input-payment-telephone');"
			. "var fn=g(\"input[name='firstname']\")||g('#input-payment-firstname');"
			. "var ln=g(\"input[name='lastname']\")||g('#input-payment-lastname');"
			. "var cs=document.querySelector(\"input[name='newsletter'],input[name='consent'],#input-newsletter\");"
			. "var consent=cs?(cs.type==='checkbox'?(cs.checked?1:0):(cs.value?1:0)):0;"
			. "if(!email&&!phone)return;"
			. "clearTimeout(t);t=setTimeout(function(){"
			. "fetch(U,{method:'POST',headers:{'Content-Type':'application/json'},credentials:'same-origin',"
			. "body:JSON.stringify({email:email,telephone:phone,firstname:fn,lastname:ln,consent:consent,"
			. "utm_source:q('utm_source'),utm_medium:q('utm_medium'),utm_campaign:q('utm_campaign')})}).catch(function(){});"
			. "},1200);}"
			. "document.addEventListener('change',function(e){var n=(e.target&&e.target.name)||'';"
			. "if(['email','telephone','firstname','lastname','newsletter','consent'].indexOf(n)>=0)grab();},true);"
			. "document.addEventListener('blur',function(e){var n=(e.target&&e.target.name)||'';"
			. "if(['email','telephone'].indexOf(n)>=0)grab();},true);"
			. "})();</script>\n";

		if (stripos($output, '</body>') !== false) {
			$output = str_ireplace('</body>', $js . '</body>', $output);
		} else {
			$output .= $js;
		}
	}

	// Публічне відновлення кошика: GET index.php?route=extension/module/growthcontour/restore&token=<64hex>
	public function restore()
	{
		$token = isset($this->request->get['token']) ? (string) $this->request->get['token'] : '';

		// 1) Валідація формату токена
		if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
			$this->response->redirect($this->url->link('checkout/cart', '', true));
			return;
		}

		$domain = rtrim(trim((string) $this->config->get('module_growthcontour_domain')), '/');
		$token_out = trim((string) $this->config->get('module_growthcontour_token'));
		if (!$domain || !$token_out) {
			$this->response->redirect($this->url->link('checkout/cart', '', true));
			return;
		}

		// 2) Запит складу кошика у CRM за токеном
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $domain . '/api/orders/abandoned-cart/recover-verify/');
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(array('token' => $token)));
		curl_setopt($ch, CURLOPT_HTTPHEADER, array(
			'Content-Type: application/json',
			'Authorization: Bearer ' . $token_out
		));
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
		curl_setopt($ch, CURLOPT_TIMEOUT, 15);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
		$raw = curl_exec($ch);
		$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		$data = json_decode((string) $raw, true);

		// 3a) CRM недоступна або відповіла помилкою → повідомлення, не порожній кошик
		if ($code !== 200 || !is_array($data)) {
			$this->session->data['success'] = 'Не вдалося відновити кошик — спробуйте пізніше або поверніться на сайт.';
			$this->response->redirect($this->url->link('checkout/cart', '', true));
			return;
		}

		// 3b) Клієнт уже оформив замовлення — не відновлюємо
		if (isset($data['status']) && $data['status'] === 'already_recovered') {
			$this->session->data['success'] = 'Ваше замовлення вже оформлено. Дякуємо за покупку!';
			$this->response->redirect($this->url->link('common/home', '', true));
			return;
		}

		// 3c) Немає товарів у відповіді
		if (empty($data['items']) || !is_array($data['items'])) {
			$this->session->data['success'] = 'На жаль, товари з вашого кошика вже недоступні.';
			$this->response->redirect($this->url->link('checkout/cart', '', true));
			return;
		}

		// 4) Очищаємо поточну корзину й наповнюємо зі складу
		$this->cart->clear();

		$added = 0;
		$skipped = 0;

		foreach ($data['items'] as $it) {
			$product_id = (int) $it['product_id'];
			$quantity = (int) $it['quantity'];
			if ($product_id <= 0 || $quantity <= 0) {
				$skipped++;
				continue;
			}

			// Перевірка, що товар існує, активний і доступний
			$exists = $this->db->query(
				"SELECT product_id FROM `" . DB_PREFIX . "product`
             WHERE product_id = '" . $product_id . "'
               AND status = '1' AND date_available <= NOW() LIMIT 1"
			)->num_rows;

			if (!$exists) {
				$skipped++;
				continue;
			}

			// Пріоритет: точні id (options_raw). Fallback: мапінг за назвою.
			if (!empty($it['options_raw']) && is_array($it['options_raw'])) {
				$option = $this->resolveRestoreOptionsById($it['options_raw']);
			} else {
				$option = $this->resolveRestoreOptions($product_id, isset($it['options']) ? $it['options'] : array());
			}
			$this->cart->add($product_id, $quantity, $option);
			$added++;
		}

		// 5) Перевіряємо, що після наповнення в корзині реально щось є (збій №2)
		if ($added === 0 || count($this->cart->getProducts()) === 0) {
			$this->session->data['success'] = 'На жаль, товари з вашого кошика вже недоступні.';
			$this->response->redirect($this->url->link('checkout/cart', '', true));
			return;
		}

		// Частину товарів не вдалось додати — мʼяко повідомляємо
		if ($skipped > 0) {
			$this->session->data['success'] = 'Ваш кошик відновлено. Деякі товари стали недоступні й були пропущені.';
		}

		// 6) Ведемо клієнта в кошик з відновленими товарами
		$this->response->redirect($this->url->link('checkout/cart', '', true));
	}

	// Перетворення { "Назва опції":"Значення" } → [ product_option_id => product_option_value_id ]
	private function resolveRestoreOptions($product_id, $options)
	{
		$result = array();
		if (empty($options) || !is_array($options)) {
			return $result;
		}

		$language_id = (int) $this->config->get('config_language_id');

		foreach ($options as $opt_name => $opt_value) {
			// знайти product_option_id за назвою опції для цього товару
			$row = $this->db->query(
				"SELECT po.product_option_id, po.option_id
             FROM `" . DB_PREFIX . "product_option` po
             LEFT JOIN `" . DB_PREFIX . "option_description` od
                    ON od.option_id = po.option_id AND od.language_id = '" . $language_id . "'
             WHERE po.product_id = '" . (int) $product_id . "'
               AND od.name = '" . $this->db->escape($opt_name) . "' LIMIT 1"
			)->row;

			if (empty($row)) {
				continue;
			}

			// знайти product_option_value_id за значенням
			$val = $this->db->query(
				"SELECT pov.product_option_value_id
             FROM `" . DB_PREFIX . "product_option_value` pov
             LEFT JOIN `" . DB_PREFIX . "option_value_description` ovd
                    ON ovd.option_value_id = pov.option_value_id AND ovd.language_id = '" . $language_id . "'
             WHERE pov.product_id = '" . (int) $product_id . "'
               AND pov.option_id = '" . (int) $row['option_id'] . "'
               AND ovd.name = '" . $this->db->escape($opt_value) . "' LIMIT 1"
			)->row;

			if (!empty($val['product_option_value_id'])) {
				$result[$row['product_option_id']] = $val['product_option_value_id'];
			}
		}

		return $result;
	}

	// Відновлення опцій за технічними id (надійно, не залежить від назв).
	// options_raw: [ { product_option_id, product_option_value_id, type, value }, ... ]
	private function resolveRestoreOptionsById($options_raw)
	{
		$result = array();
		foreach ($options_raw as $o) {
			$poid = isset($o['product_option_id']) ? (int) $o['product_option_id'] : 0;
			if (!$poid) {
				continue;
			}
			$type = isset($o['type']) ? $o['type'] : null;

			// select / radio: значення = product_option_value_id
			if (!empty($o['product_option_value_id'])) {
				// checkbox зберігається як масив value_id
				if ($type === 'checkbox') {
					$result[$poid][] = (int) $o['product_option_value_id'];
				} else {
					$result[$poid] = (int) $o['product_option_value_id'];
				}
			} elseif (isset($o['value']) && $o['value'] !== null && $o['value'] !== '') {
				// text / textarea / date тощо — вільне значення
				$result[$poid] = $o['value'];
			}
		}
		return $result;
	}

	// Fired on: catalog/controller/common/cart/after
	//   Захоплення ЖИВОГО кошика поточної сесії (guest-safe). Порожній → закриття.
	public function cartTouch(&$route, &$args, &$output)
	{
		if (!$this->config->get('module_growthcontour_cart_enabled')) {
			return;
		}

		$session_id = (isset($this->session) && method_exists($this->session, 'getId')) ? $this->session->getId() : '';
		if ($session_id === '') {
			return;
		}

		$products = $this->cart->getProducts();

		$this->load->model('extension/module/growthcontour');
		$this->model_extension_module_growthcontour->captureLiveCart(
			(int) $this->config->get('config_store_id'),
			$session_id,
			(int) $this->customer->getId(),
			$products
		);
	}

	// Публічний приймач CRM: POST index.php?route=extension/module/growthcontour/receive
	//  A) { action, data } + HMAC — синхронізація каталогу/відгуків (sync-client)
	//  B) { event, ... }          — зміни замовлень (outbox)
	public function receive()
	{
		if ($this->request->server['REQUEST_METHOD'] !== 'POST') {
			$this->respond(405, array('ok' => false, 'status' => 'error', 'error' => 'method not allowed', 'message' => 'method not allowed'));
			return;
		}

		$token = $this->inboundToken();
		if ($token === '') {
			$why = $this->bearerToken() === '' ? 'no token in request (header stripped by server)' : 'token mismatch: CRM key differs from module inbound token';
			$this->respond(401, array('ok' => false, 'status' => 'error', 'error' => 'unauthorized: ' . $why, 'message' => 'unauthorized'));
			return;
		}

		$raw = file_get_contents('php://input');

		// HMAC: якщо CRM прислала підпис — перевіряємо (вікно 5 хв проти повторів)
		$sig = isset($this->request->server['HTTP_X_GC_SIGNATURE']) ? trim((string) $this->request->server['HTTP_X_GC_SIGNATURE']) : '';
		if ($sig !== '') {
			$ts = isset($this->request->server['HTTP_X_GC_TIMESTAMP']) ? (int) $this->request->server['HTTP_X_GC_TIMESTAMP'] : 0;
			$calc = 'sha256=' . hash_hmac('sha256', $ts . '.' . $raw, $token);
			if (!$ts || abs(time() - $ts) > 300 || !hash_equals($calc, $sig)) {
				$this->respond(401, array('ok' => false, 'status' => 'error', 'error' => 'bad signature', 'message' => 'bad signature'));
				return;
			}
		}

		$body = json_decode($raw, true);
		if (!is_array($body)) {
			$this->respond(422, array('ok' => false, 'status' => 'error', 'error' => 'bad payload', 'message' => 'bad payload'));
			return;
		}

		$this->load->model('extension/module/growthcontour');
		$m = $this->model_extension_module_growthcontour;

				// A) API синхронізації
		if (!empty($body['action'])) {
			$action = (string) $body['action'];
			$data = (isset($body['data']) && is_array($body['data'])) ? $body['data'] : array();

			if ($action === 'ping') {
				$this->respond(200, array('ok' => true) + $m->apiPing());
				return;
			}

			// action => [розділ, метод моделі]
			$routes = array(
				'catalog.list'      => array('products', 'apiCatalogList'),
				'products.update'   => array('products', 'apiProductsUpdate'),
				'products.sync'     => array('products', 'apiProductsSync'),
				'products.state'    => array('products', 'apiProductsState'),
				// Дерево сайту — під розділом товарів: потрібне й тоді, коли обмін категорій вимкнено («Дерево сайту»)
				'categories.list'   => array('products', 'apiCategoriesList'),
				'attributes.sync'   => array('attributes', 'apiAttributesSync'),
				'reviews.sync'      => array('reviews', 'apiReviewsSync'),
				'customers.sync'    => array('customers', 'apiCustomersSync'),
				'categories.sync'   => array('categories', 'apiCategoriesSync'),
				'attributes.list'   => array('attributes', 'apiAttributesList'),
				'attributes.update' => array('attributes', 'apiAttributesUpdate'),
				'reviews.list'      => array('reviews', 'apiReviewsList'),
				'reviews.update'    => array('reviews', 'apiReviewsUpdate'),
				// Експорт каталогу в систему (імпорт товарів). Лише читання + запис зв'язків у growthcontour_map
				'export.meta'          => array('export', 'apiExportMeta'),
				'export.categories'    => array('export', 'apiExportCategories'),
				'export.manufacturers' => array('export', 'apiExportManufacturers'),
				'export.products'      => array('export', 'apiExportProducts'),
				'export.link'          => array('export', 'apiExportLink'),
				'export.unlink'        => array('export', 'apiExportUnlink'),
			);

			if ($action === 'sync.begin' || $action === 'sync.end') {
				$entity = isset($data['entity']) ? (string) $data['entity'] : '';
				if (!in_array($entity, array('products', 'categories', 'attributes', 'reviews', 'customers'), true)) {
					$this->respond(422, array('ok' => false, 'error' => 'unknown entity'));
					return;
				}
				$method = $action === 'sync.begin' ? 'apiSyncBegin' : 'apiSyncEnd';
			} elseif (isset($routes[$action])) {
				list($entity, $method) = $routes[$action];
			} else {
				$this->respond(422, array('ok' => false, 'error' => 'unknown action'));
				return;
			}

			// Не збережено ні разу (null) = увімкнено для товарів і відгуків, вимкнено для решти
			if ($entity === 'export') {
				// Експорт не залежить від прапорців обміну — лише від увімкненого модуля
				if (!$this->config->get('module_growthcontour_status')) {
					$this->respond(403, array('ok' => false, 'error' => 'module is disabled'));
					return;
				}
				$on = true;
			} else {
				$flag = $this->config->get('module_growthcontour_sync_' . $entity);
				$on = ($flag === null) ? in_array($entity, array('products', 'reviews')) : (bool) $flag;
			}
			if (!$on) {
				$msg = $entity . ' sync is disabled in the shop module (enable it and press Save)';
				$m->jobFail($entity, $msg); // щоб причину було видно в таблиці прогресу
				$this->respond(403, array('ok' => false, 'error' => $msg));
				return;
			}

			try {
				$out = $m->$method($data);
				if (!empty($data['job']) && isset($out['results'])) {
					$out['cancel'] = $m->jobProgress($entity, (string) $data['job'], $out['results'], isset($data['items']) ? $data['items'] : array());
				}
			} catch (Exception $e) {
				$m->syncMark($entity, 0, $e->getMessage());
				if ($action === 'sync.begin' || $action === 'sync.end') {
					$m->jobFail($entity, $e->getMessage());
				}
				$this->respond(500, array('ok' => false, 'error' => mb_substr($e->getMessage(), 0, 300)));
				return;
			}

			$n = isset($out['items']) ? count($out['items']) : (isset($out['results']) ? count($out['results']) : (isset($out['updated']) ? (int) $out['updated'] : 0));
			$err = '';
			if (isset($out['results'])) {
				$fails = 0;
				foreach ($out['results'] as $r) {
					if (empty($r['ok'])) {
						if (!$fails) {
							$err = (isset($r['error']) ? $r['error'] : 'error');
						}
						$fails++;
					}
				}
				if ($fails) {
					$err = $fails . ' err: ' . $err;
				}
			}
			$m->syncMark($entity, $n, $err);

			$this->respond(200, array('ok' => true) + $out);
			return;
		}

		// B) Події замовлень
		if (empty($body['event'])) {
			$this->respond(422, array('status' => 'error', 'message' => 'bad payload'));
			return;
		}

		switch ($body['event']) {
			case 'status_change':
				$result = $m->applyStatusChange($body);
				break;

			case 'order_updated':
				// склад назад поки не застосовуємо — приймаємо, щоб CRM не ретраїла
				$result = array('ok' => true, 'message' => 'accepted (no-op)');
				break;

			default:
				$this->respond(422, array('status' => 'error', 'message' => 'unknown event'));
				return;
		}

		if (!empty($result['ok'])) {
			$this->respond(200, array('ok' => true, 'status' => 'success', 'message' => $result['message']));
		} else {
			$this->respond(isset($result['code']) ? $result['code'] : 500, array('ok' => false, 'status' => 'error', 'message' => isset($result['message']) ? $result['message'] : 'error'));
		}
	}

	// ─────────────────────────────────────────────────────────────────────
	//  CRON каталогу: запускає в CRM ті розділи, в яких настав час автосинхронізації.
	//    */5 * * * * curl -H "Authorization: Bearer <inbound_token>" "https://STORE/index.php?route=extension/module/growthcontour/catalogCron"
	// ─────────────────────────────────────────────────────────────────────
	public function catalogCron()
	{
		$token_ok = $this->inboundToken() !== '';
		if (!$token_ok && isset($this->request->get['token'])) {
			$q = trim((string) $this->request->get['token']);
			$cur = trim((string) $this->config->get('module_growthcontour_inbound_token'));
			$token_ok = ($q !== '' && $cur !== '' && hash_equals($cur, $q));
		}

		$this->response->addHeader('Content-Type: application/json');

		if (!$token_ok) {
			http_response_code(401);
			$this->response->setOutput(json_encode(array('error' => 'unauthorized')));
			return;
		}

		$this->load->model('extension/module/growthcontour');
		$m = $this->model_extension_module_growthcontour;

		$ran = array();
		foreach (array('categories', 'attributes', 'products', 'reviews', 'customers') as $e) {
			$every = (int) $this->config->get('module_growthcontour_sync_' . $e . '_auto');
			if ($every > 0 && $m->autoDue($e, $every)) {
				$ran[$e] = $m->crmSync($e);
				$m->autoMark($e);
			}
		}

		$this->response->setOutput(json_encode(array('status' => 'ok', 'ran' => $ran)));
	}

	private function bearerToken()
	{
		$s = $this->request->server;
		$h = '';
		if (!empty($s['HTTP_AUTHORIZATION'])) {
			$h = $s['HTTP_AUTHORIZATION'];
		} elseif (!empty($s['REDIRECT_HTTP_AUTHORIZATION'])) {
			$h = $s['REDIRECT_HTTP_AUTHORIZATION']; // CGI/FPM за .htaccess
		} elseif (function_exists('getallheaders')) {
			foreach (getallheaders() as $k => $v) {
				if (strcasecmp($k, 'Authorization') === 0) {
					$h = $v;
					break;
				}
			}
		}
		if (preg_match('/^Bearer\s+(.+)$/i', trim((string) $h), $mm)) {
			return trim($mm[1]);
		}
		// Запасний заголовок: хостинги з CGI/FPM часто відрізають Authorization
		if (!empty($s['HTTP_X_GC_TOKEN'])) {
			return trim((string) $s['HTTP_X_GC_TOKEN']);
		}
		return '';
	}

	// Повертає токен, яким пройшла автентифікація (поточний або старий під час ротації), або ''
	private function inboundToken()
	{
		$got = $this->bearerToken();
		if ($got === '') {
			return '';
		}
		foreach (array('module_growthcontour_inbound_token', 'module_growthcontour_inbound_token_old') as $key) {
			$t = trim((string) $this->config->get($key));
			if ($t !== '' && hash_equals($t, $got)) {
				return $t;
			}
		}
		return '';
	}

	private function respond($code, $data)
	{
		http_response_code($code);
		$this->response->addHeader('Content-Type: application/json; charset=utf-8');
		$this->response->setOutput(json_encode($data, JSON_UNESCAPED_UNICODE));
	}

	private function enabled()
	{
		return $this->config->get('module_growthcontour_status')
			&& $this->config->get('module_growthcontour_orders_status')
			&& $this->config->get('module_growthcontour_domain')
			&& $this->config->get('module_growthcontour_token');
	}
}
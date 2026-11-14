<?php
class ModelExtensionModuleGrowthcontour extends Model
{

	public function pullStatuses($domain, $token, $origin = '', $id_lang = 2)
	{
		$url = $this->buildUrl($domain, '/api/orders/statuses/pull/');

		return $this->request($url, $token, array('id_lang' => (int) $id_lang), $origin, 'pull');
	}

	public function sendOrder($domain, $token, $payload, $origin = '')
	{
		$url = $this->buildUrl($domain, '/api/orders/receive/');

		return $this->request($url, $token, $payload, $origin, 'send');
	}

	private function buildUrl($domain, $path)
	{
		return rtrim(trim($domain), '/') . $path;
	}

	private function request($url, $token, $body, $origin = '', $event = 'request')
	{
		// If origin was not passed explicitly — detect the store's own domain
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

		$this->writeLog($event, $url, $origin, $duration_ms, $result);

		return $result;
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
		$store = $this->config->get('config_url');

		if ($store) {
			$parts = parse_url($store);

			if (!empty($parts['host'])) {
				$scheme = isset($parts['scheme']) ? $parts['scheme'] : 'https';

				return $scheme . '://' . $parts['host'];
			}
		}

		return '';
	}

	private function writeLog($event, $url, $origin, $duration_ms, $result)
	{
		$code = (int) $result['http_code'];

		if ($code == 200 || $code == 201) {
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
			'order_id' => 0,
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

	public function getLogs($limit = 1000)
	{
		$file = DIR_LOGS . 'growthcontour.log';

		if (!is_file($file)) {
			return array();
		}

		$lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

		if (!$lines) {
			return array();
		}

		$lines = array_slice(array_reverse($lines), 0, (int) $limit);

		$logs = array();

		foreach ($lines as $line) {
			$entry = json_decode($line, true);

			if (is_array($entry)) {
				$logs[] = $entry;
			}
		}

		return $logs;
	}

	public function clearLog()
	{
		$file = DIR_LOGS . 'growthcontour.log';

		if (is_file($file)) {
			file_put_contents($file, '');
		}
	}

	public function getTotalOrders()
	{
		$query = $this->db->query("SELECT COUNT(*) AS total FROM `" . DB_PREFIX . "order` WHERE order_status_id > 0");
		return (int) $query->row['total'];
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

	// Адреса сайту (вітрини). В адмінці config_url порожній — беремо HTTPS_CATALOG / HTTP_CATALOG з admin/config.php
	public function getStoreBaseUrl()
	{
		$store = defined('HTTPS_CATALOG') && HTTPS_CATALOG ? HTTPS_CATALOG : (defined('HTTP_CATALOG') ? HTTP_CATALOG : '');
		if (!$store) {
			$store = (string) $this->config->get('config_url');
		}
		return $store ? rtrim($store, '/') . '/' : '';
	}

	// Статичне поле: у OpenCart 3 кожен метод моделі викликається на своєму екземплярі
	private static $push_error = '';

	// Помилка останнього pushOrder (текст curl), для повідомлення в адмінці
	public function getPushError()
	{
		return self::$push_error;
	}

	/**
	 * Запуск передачі замовлення через власний сайт (маршрут push).
	 * Спершу — напряму на цей сервер (127.0.0.1 з іменем сайту): так запит не йде через Cloudflare / зовнішній DNS,
	 * які часто не пускають сервер сам до себе (HTTP 0). Сертифікат тут не перевіряється — з'єднання не виходить за межі сервера.
	 * Не вдалося — звичайний запит за адресою сайту.
	 */
	public function pushOrder($url, $token, $order_id)
	{
		self::$push_error = '';
		$parts = parse_url($url);
		$host = isset($parts['host']) ? $parts['host'] : '';
		$https = isset($parts['scheme']) && strtolower($parts['scheme']) === 'https';
		$port = isset($parts['port']) ? (int) $parts['port'] : ($https ? 443 : 80);

		$errors = array();
		$attempts = $host ? array(true, false) : array(false);
		foreach ($attempts as $local) {
			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL, $url);
			curl_setopt($ch, CURLOPT_POST, true);
			curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(array('order_id' => (int) $order_id)));
			curl_setopt($ch, CURLOPT_HTTPHEADER, array(
				'Content-Type: application/json',
				'Authorization: Bearer ' . trim($token)
			));
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
			curl_setopt($ch, CURLOPT_TIMEOUT, 25);
			if ($local) {
				curl_setopt($ch, CURLOPT_RESOLVE, array($host . ':' . $port . ':127.0.0.1'));
				curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
				curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
			} else {
				curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
				curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
			}

			curl_exec($ch);
			$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
			$err = curl_error($ch);
			curl_close($ch);

			if ($code === 200) {
				return 200;
			}
			$errors[] = ($local ? '127.0.0.1' : $host) . ': ' . ($code ? 'HTTP ' . $code : ($err ? $err : 'no response'));
		}

		self::$push_error = implode('; ', $errors);
		return $code;
	}

	public function getCartStats()
	{
		$stats = array('open' => 0, 'sent' => 0, 'recovered' => 0, 'expired' => 0, 'total' => 0, 'recovery_rate' => 0);

		// На свіжій установці таблиці ще може не бути
		$exists = $this->db->query("SHOW TABLES LIKE '" . DB_PREFIX . "growthcontour_cart'")->num_rows;
		if (!$exists) {
			return $stats;
		}

		$rows = $this->db->query(
			"SELECT status, COUNT(*) AS c FROM `" . DB_PREFIX . "growthcontour_cart` GROUP BY status"
		)->rows;

		foreach ($rows as $r) {
			if (isset($stats[$r['status']])) {
				$stats[$r['status']] = (int) $r['c'];
			}
			$stats['total'] += (int) $r['c'];
		}

		// Recovery rate серед кошиків, що дійшли до термінального/відправленого стану
		$denom = $stats['sent'] + $stats['recovered'] + $stats['expired'];
		$stats['recovery_rate'] = $denom > 0 ? round($stats['recovered'] * 100 / $denom, 1) : 0;

		return $stats;
	}
}
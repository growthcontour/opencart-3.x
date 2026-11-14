<?php
/**
 * Growth contour — доставка замовлення: знімок + розбір за правилами.
 *
 * Не залежить від модуля доставки (свій, чужий, зашифрований):
 *  1. capture(): у момент оформлення зберігає знімок — поля замовлення, ключі сесії й POST, що стосуються доставки;
 *  2. parse(): за правилами (код доставки → служба, тип, звідки брати кожне значення) розбирає знімок у структуровані поля;
 *     автоматично знаходить ref відділення / міста / області: UUID у знімку або номер «№123» + місто — за таблицями НП на сайті;
 *  3. get(): дані для блоку delivery, що йде в систему.
 *
 * Використовується і з вітрини (catalog), і з адмінки (перерахунок, перевірка правил).
 */
class GrowthcontourDelivery
{
    const FIELDS = 'area,area_ref,city,city_ref,point,point_ref,point_number,point_type,street,building,flat';
    const KEY_RE = '/nova|poshta|posta|np_|_np|^np$|shipping|warehouse|department|poshtomat|postomat|parcel|city|area|region|street|house|flat|delivery|carrier|branch|otdel|viddil/i';
    const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    // Правила за замовчуванням: коди найпоширеніших модулів НП для OpenCart
    const DEFAULT_RULES = '[
  {"match": "novaposhta.department", "carrier": "novaposhta", "service": "warehouse"},
  {"match": "novaposhta.poshtomat",  "carrier": "novaposhta", "service": "postomat"},
  {"match": "novaposhta.doors",      "carrier": "novaposhta", "service": "address",
   "fields": {"street": "order.shipping_address_1", "building": "order.shipping_address_2"}},
  {"match": "/^novaposhta\\\\./",     "carrier": "novaposhta", "service": "warehouse"},
  {"match": "/^(np|nova_poshta|novapost)/i", "carrier": "novaposhta", "service": "warehouse"}
]';

    private $db;
    private $config;
    private $tables = array();

    public function __construct($registry)
    {
        $this->db = $registry->get('db');
        $this->config = $registry->get('config');
    }

    /* ─── Таблиця ─── */

    public function install()
    {
        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "growthcontour_order_delivery` (
              `order_id` INT(11) NOT NULL,
              `method_code` VARCHAR(128) DEFAULT NULL,
              `carrier` VARCHAR(32) DEFAULT NULL,
              `service` VARCHAR(16) DEFAULT NULL,
              `area_ref` VARCHAR(64) DEFAULT NULL,
              `area` VARCHAR(255) DEFAULT NULL,
              `city_ref` VARCHAR(64) DEFAULT NULL,
              `city` VARCHAR(255) DEFAULT NULL,
              `point_ref` VARCHAR(64) DEFAULT NULL,
              `point` VARCHAR(512) DEFAULT NULL,
              `point_number` VARCHAR(32) DEFAULT NULL,
              `point_type` VARCHAR(16) DEFAULT NULL,
              `street` VARCHAR(255) DEFAULT NULL,
              `building` VARCHAR(64) DEFAULT NULL,
              `flat` VARCHAR(64) DEFAULT NULL,
              `status` VARCHAR(16) NOT NULL DEFAULT 'empty',
              `found_by` VARCHAR(255) DEFAULT NULL,
              `raw` MEDIUMTEXT,
              `date_added` DATETIME NOT NULL,
              `date_modified` DATETIME NOT NULL,
              PRIMARY KEY (`order_id`),
              KEY `idx_status` (`status`),
              KEY `idx_modified` (`date_modified`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    private function tableExists($name)
    {
        if (!isset($this->tables[$name])) {
            $this->tables[$name] = (bool) $this->db->query("SHOW TABLES LIKE '" . $this->db->escape(DB_PREFIX . $name) . "'")->num_rows;
        }
        return $this->tables[$name];
    }

    /* ─── Правила ─── */

    public function rulesText()
    {
        $q = $this->db->query("SELECT `value` FROM `" . DB_PREFIX . "setting` WHERE store_id = '0' AND `code` = 'growthcontour_delivery' AND `key` = 'growthcontour_delivery_rules' LIMIT 1");
        return $q->num_rows && trim($q->row['value']) !== '' ? $q->row['value'] : self::DEFAULT_RULES;
    }

    public function rules()
    {
        $r = json_decode($this->rulesText(), true);
        return is_array($r) ? $r : json_decode(self::DEFAULT_RULES, true);
    }

    /** Перевірити й зберегти правила. Повертає текст помилки або null */
    public function saveRules($text)
    {
        $text = trim((string) $text);
        if ($text === '') {
            $text = self::DEFAULT_RULES;
        }
        $r = json_decode($text, true);
        if (!is_array($r)) {
            return 'JSON: ' . json_last_error_msg();
        }
        foreach ($r as $i => $rule) {
            if (!is_array($rule) || empty($rule['match'])) {
                return '#' . ($i + 1) . ': "match"';
            }
            if (!empty($rule['service']) && !in_array($rule['service'], array('warehouse', 'postomat', 'address', 'pickup'), true)) {
                return '#' . ($i + 1) . ': "service" — warehouse | postomat | address | pickup';
            }
            if (isset($rule['fields']) && !is_array($rule['fields'])) {
                return '#' . ($i + 1) . ': "fields"';
            }
        }
        $this->db->query("DELETE FROM `" . DB_PREFIX . "setting` WHERE store_id = '0' AND `code` = 'growthcontour_delivery' AND `key` = 'growthcontour_delivery_rules'");
        $this->db->query("INSERT INTO `" . DB_PREFIX . "setting` SET store_id = '0', `code` = 'growthcontour_delivery', `key` = 'growthcontour_delivery_rules', `value` = '" . $this->db->escape($text) . "', serialized = '0'");
        return null;
    }

    private function ruleFor($code, $rules)
    {
        $code = (string) $code;
        foreach ($rules as $rule) {
            $m = (string) $rule['match'];
            if ($m !== '' && $m[0] === '/' && @preg_match($m, '') !== false) {
                if (preg_match($m, $code)) {
                    return $rule;
                }
            } elseif (strcasecmp($m, $code) === 0) {
                return $rule;
            }
        }
        return null;
    }

    /* ─── Знімок ─── */

    /** Лише ключі, що стосуються доставки (рекурсивно), без об'єктів і довгих рядків */
    private function filter($data, $depth = 0, $all = false)
    {
        if ($depth > 6 || !is_array($data)) {
            return null;
        }
        $out = array();
        foreach ($data as $k => $v) {
            $match = $all || preg_match(self::KEY_RE, (string) $k);
            if (is_array($v)) {
                $sub = $this->filter($v, $depth + 1, $match);
                if ($sub) {
                    $out[$k] = $sub;
                }
            } elseif ($match && (is_scalar($v) || $v === null)) {
                $out[$k] = is_string($v) ? mb_substr($v, 0, 1000) : $v;
            }
        }
        return $out;
    }

    /**
     * Зберегти знімок і розібрати. $stage — add | history | backfill.
     * $session / $post — масиви сесії й запиту (на вітрині), у фоні — порожні.
     */
    public function capture($order_id, $stage, $session = array(), $post = array())
    {
        $order_id = (int) $order_id;
        $order = $this->db->query("SELECT * FROM `" . DB_PREFIX . "order` WHERE order_id = '" . $order_id . "'")->row;
        if (!$order) {
            return null;
        }
        $this->install();
        $cur = $this->db->query("SELECT raw FROM `" . DB_PREFIX . "growthcontour_order_delivery` WHERE order_id = '" . $order_id . "'")->row;
        $raw = $cur ? json_decode($cur['raw'], true) : array();
        if (!is_array($raw)) {
            $raw = array();
        }

        $orderPart = array();
        foreach ($order as $k => $v) {
            if (strpos($k, 'shipping_') === 0 || in_array($k, array('custom_field', 'payment_custom_field', 'shipping_custom_field'), true)) {
                $orderPart[$k] = $v;
            }
        }
        foreach (array('custom_field', 'shipping_custom_field', 'payment_custom_field') as $k) {
            if (isset($orderPart[$k]) && is_string($orderPart[$k]) && $orderPart[$k] !== '') {
                $j = json_decode($orderPart[$k], true);
                if (is_array($j)) {
                    $orderPart[$k] = $j;
                }
            }
        }
        $snap = array('at' => date('c'), 'order' => $orderPart);
        $s = $this->filter(is_array($session) ? $session : array());
        if ($s) {
            $snap['session'] = $s;
        }
        $p = $this->filter(is_array($post) ? $post : array());
        if ($p) {
            $snap['post'] = $p;
        }
        // Знімок кожного етапу зберігаємо окремо: модуль доставки міг дописати дані пізніше
        $raw[$stage] = $snap;
        $json = json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        if (strlen($json) > 200000) {
            unset($raw[$stage]['session'], $raw[$stage]['post']);
            $json = json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        }
        $now = date('Y-m-d H:i:s');
        $this->db->query(
            "INSERT INTO `" . DB_PREFIX . "growthcontour_order_delivery` SET order_id = '" . $order_id . "', raw = '" . $this->db->escape($json) . "',
             date_added = '" . $now . "', date_modified = '" . $now . "'
             ON DUPLICATE KEY UPDATE raw = VALUES(raw), date_modified = VALUES(date_modified)"
        );
        return $this->reparse($order_id);
    }

    /** Розібрати збережений знімок за поточними правилами. $save = false — лише показати результат */
    public function reparse($order_id, $save = true, $rules = null)
    {
        $order_id = (int) $order_id;
        $this->install();
        $row = $this->db->query("SELECT raw FROM `" . DB_PREFIX . "growthcontour_order_delivery` WHERE order_id = '" . $order_id . "'")->row;
        $order = $this->db->query("SELECT * FROM `" . DB_PREFIX . "order` WHERE order_id = '" . $order_id . "'")->row;
        if (!$order) {
            return null;
        }
        $raw = $row ? json_decode($row['raw'], true) : array();
        if (!is_array($raw) || !$raw) {
            // Немає знімка (старе замовлення): лише поля замовлення
            $raw = array('backfill' => array('order' => $order));
        }
        $res = $this->parse($order, $raw, $rules === null ? $this->rules() : $rules);
        if ($save) {
            $set = array();
            foreach (array_merge(array('method_code', 'carrier', 'service', 'status', 'found_by'), explode(',', self::FIELDS)) as $f) {
                $v = isset($res[$f]) ? $res[$f] : null;
                $set[] = "`" . $f . "` = " . ($v === null || $v === '' ? "NULL" : "'" . $this->db->escape(mb_substr((string) $v, 0, $f === 'point' ? 512 : 255)) . "'");
            }
            $now = date('Y-m-d H:i:s');
            $this->db->query(
                "INSERT INTO `" . DB_PREFIX . "growthcontour_order_delivery` SET order_id = '" . $order_id . "', " . implode(', ', $set) . ",
                 raw = '" . $this->db->escape(json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR)) . "', date_added = '" . $now . "', date_modified = '" . $now . "'
                 ON DUPLICATE KEY UPDATE " . implode(', ', $set) . ", date_modified = '" . $now . "'"
            );
        }
        return $res;
    }

    /* ─── Розбір ─── */

    /** Значення за шляхом: order.shipping_city, session.novaposhta.ref, post.x; останній етап, де воно є */
    private function pick($raw, $path)
    {
        $parts = explode('.', $path);
        $stages = array_reverse(array_keys($raw));
        foreach ($stages as $st) {
            $node = $raw[$st];
            $ok = true;
            foreach ($parts as $p) {
                if (is_array($node) && array_key_exists($p, $node)) {
                    $node = $node[$p];
                } else {
                    $ok = false;
                    break;
                }
            }
            if ($ok && (is_scalar($node)) && trim((string) $node) !== '') {
                return trim((string) $node);
            }
        }
        return null;
    }

    /**
     * Джерело: "order.shipping_address_1" | "session.np.ref" | "шлях | /регулярка/" (1-ша група) |
     * кілька через " || " — перше непорожнє | "=текст" — стала
     */
    private function source($raw, $expr)
    {
        foreach (explode('||', (string) $expr) as $alt) {
            $alt = trim($alt);
            if ($alt === '') {
                continue;
            }
            if ($alt[0] === '=') {
                return substr($alt, 1);
            }
            $re = null;
            if (strpos($alt, '|') !== false) {
                list($alt, $re) = array_map('trim', explode('|', $alt, 2));
            }
            $v = $this->pick($raw, $alt);
            if ($v !== null && $re) {
                $v = (@preg_match($re, $v, $m) && isset($m[1])) ? trim($m[1]) : null;
            }
            if ($v !== null && $v !== '') {
                return $v;
            }
        }
        return null;
    }

    private function strings($node, &$out)
    {
        if (is_array($node)) {
            foreach ($node as $v) {
                $this->strings($v, $out);
            }
        } elseif (is_string($node) && $node !== '') {
            $out[] = $node;
        }
    }

    public function parse($order, $raw, $rules)
    {
        $code = isset($order['shipping_code']) ? (string) $order['shipping_code'] : '';
        $res = array('method_code' => $code, 'carrier' => null, 'service' => null, 'status' => 'none', 'found_by' => null);
        foreach (explode(',', self::FIELDS) as $f) {
            $res[$f] = null;
        }
        $rule = $this->ruleFor($code, $rules);
        if (!$rule) {
            return $res; // не служба доставки (самовивіз, кур'єр магазину тощо) — нічого не розбираємо
        }
        $res['carrier'] = isset($rule['carrier']) ? (string) $rule['carrier'] : null;
        $res['service'] = isset($rule['service']) ? (string) $rule['service'] : 'warehouse';
        $how = array();

        // 1. Явні джерела з правила
        if (!empty($rule['fields']) && is_array($rule['fields'])) {
            foreach ($rule['fields'] as $f => $expr) {
                if (in_array($f, explode(',', self::FIELDS), true)) {
                    $v = $this->source($raw, $expr);
                    if ($v !== null) {
                        $res[$f] = $v;
                        $how[] = $f . '←rule';
                    }
                }
            }
        }
        // Місто й текст відділення з полів замовлення — якщо правило не задало
        if ($res['city'] === null) {
            $res['city'] = $this->pick($raw, 'order.shipping_city');
        }
        if ($res['point'] === null && in_array($res['service'], array('warehouse', 'postomat'), true)) {
            $res['point'] = $this->pick($raw, 'order.shipping_address_1');
        }

        // 2. Автоматично — за довідником НП на сайті (якщо таблиці є і правило не вимкнуло)
        $auto = !isset($rule['auto']) || $rule['auto'];
        if ($auto && $res['carrier'] === 'novaposhta' && $this->tableExists('novaposhta_departments')) {
            $this->autoNovaposhta($res, $raw, $how);
        }

        // Номер відділення з тексту
        if ($res['point_number'] === null && $res['point']) {
            if (preg_match('/№\s*(\d+)/u', $res['point'], $m)) {
                $res['point_number'] = $m[1];
            }
        }

        if (in_array($res['service'], array('warehouse', 'postomat'), true)) {
            $res['status'] = $res['point_ref'] ? 'ok' : (($res['point'] || $res['point_number'] || $res['city']) ? 'partial' : 'empty');
        } elseif ($res['service'] === 'address') {
            $res['status'] = ($res['city_ref'] || $res['city']) && $res['street'] ? ($res['city_ref'] ? 'ok' : 'partial') : (($res['city'] || $res['street']) ? 'partial' : 'empty');
        } else {
            $res['status'] = 'ok';
        }
        $res['found_by'] = $how ? implode(', ', array_unique($how)) : null;
        return $res;
    }

    private function autoNovaposhta(&$res, $raw, &$how)
    {
        $D = DB_PREFIX . 'novaposhta_departments';
        $C = DB_PREFIX . 'novaposhta_cities';
        $R = DB_PREFIX . 'novaposhta_regions';
        $strings = array();
        $this->strings($raw, $strings);

        // а) UUID у знімку, що є у довіднику відділень / міст / областей
        $uuids = array();
        foreach ($strings as $s) {
            if (preg_match(self::UUID_RE, trim($s))) {
                $uuids[strtolower(trim($s))] = true;
            }
        }
        if ($uuids) {
            $in = "'" . implode("','", array_map(array($this->db, 'escape'), array_keys($uuids))) . "'";
            if (!$res['point_ref']) {
                $q = $this->db->query("SELECT Ref FROM `" . $D . "` WHERE LOWER(Ref) IN (" . $in . ") LIMIT 2");
                if ($q->num_rows === 1) {
                    $res['point_ref'] = $q->row['Ref'];
                    $how[] = 'point_ref←uuid';
                }
            }
            if (!$res['city_ref'] && $this->tableExists('novaposhta_cities')) {
                $q = $this->db->query("SELECT Ref FROM `" . $C . "` WHERE LOWER(Ref) IN (" . $in . ") LIMIT 2");
                if ($q->num_rows === 1) {
                    $res['city_ref'] = $q->row['Ref'];
                    $how[] = 'city_ref←uuid';
                }
            }
        }

        // б) Місто за назвою (укр / рос), якщо однозначно
        if (!$res['city_ref'] && $res['city'] && $this->tableExists('novaposhta_cities')) {
            $c = $this->db->escape($res['city']);
            $q = $this->db->query("SELECT Ref FROM `" . $C . "` WHERE Description = '" . $c . "' OR DescriptionRu = '" . $c . "' LIMIT 2");
            if ($q->num_rows === 1) {
                $res['city_ref'] = $q->row['Ref'];
                $how[] = 'city_ref←name';
            }
        }

        // в) Відділення за номером «№123» із тексту відділення або будь-якого рядка знімка + місто
        if (!$res['point_ref'] && in_array($res['service'], array('warehouse', 'postomat'), true)) {
            $nums = array();
            foreach (array_merge(array((string) $res['point_number'], (string) $res['point']), $strings) as $s) {
                if ($s !== '' && ctype_digit($s) === false && preg_match('/№\s*(\d+)/u', $s, $m)) {
                    $nums[$m[1]] = true;
                }
            }
            if ($res['point_number'] && ctype_digit((string) $res['point_number'])) {
                $nums = array($res['point_number'] => true) + $nums;
            }
            foreach (array_keys($nums) as $n) {
                $where = "Number = '" . (int) $n . "'";
                if ($res['city_ref']) {
                    $where .= " AND CityRef = '" . $this->db->escape($res['city_ref']) . "'";
                } elseif ($res['city']) {
                    $c = $this->db->escape($res['city']);
                    $where .= " AND (CityDescription = '" . $c . "' OR CityDescriptionRu = '" . $c . "')";
                }
                $q = $this->db->query("SELECT Ref FROM `" . $D . "` WHERE " . $where . " LIMIT 2");
                if ($q->num_rows === 1) {
                    $res['point_ref'] = $q->row['Ref'];
                    $how[] = 'point_ref←number';
                    break;
                }
            }
        }

        // г) Є відділення — заповнюємо решту з довідника (назва, номер, тип, місто, область)
        if ($res['point_ref']) {
            $d = $this->db->query("SELECT * FROM `" . $D . "` WHERE Ref = '" . $this->db->escape($res['point_ref']) . "' LIMIT 1")->row;
            if ($d) {
                $res['point'] = $d['Description'];
                $res['point_number'] = (string) $d['Number'];
                $res['point_type'] = $d['CategoryOfWarehouse'] === 'Postomat' ? 'postomat' : 'warehouse';
                if ($res['point_type'] === 'postomat') {
                    $res['service'] = 'postomat';
                }
                $res['city_ref'] = $d['CityRef'];
                $res['city'] = $d['CityDescription'];
                if (!$res['area'] && $d['SettlementAreaDescription']) {
                    $res['area'] = $d['SettlementAreaDescription'];
                }
            }
        }
        // ґ) Область: з міста
        if ($res['city_ref'] && !$res['area_ref'] && $this->tableExists('novaposhta_cities')) {
            $c = $this->db->query("SELECT Area, AreaDescription FROM `" . $C . "` WHERE Ref = '" . $this->db->escape($res['city_ref']) . "' LIMIT 1")->row;
            if ($c) {
                $res['area_ref'] = $c['Area'];
                $res['area'] = $res['area'] ? $res['area'] : $c['AreaDescription'];
            }
        }
        if (!$res['area_ref'] && $res['area'] && $this->tableExists('novaposhta_regions')) {
            $a = $this->db->escape($res['area']);
            $q = $this->db->query("SELECT Ref FROM `" . $R . "` WHERE Description = '" . $a . "' OR DescriptionRu = '" . $a . "' LIMIT 2");
            if ($q->num_rows === 1) {
                $res['area_ref'] = $q->row['Ref'];
            }
        }
    }

    /* ─── Для системи ─── */

    public function get($order_id)
    {
        $this->install();
        $r = $this->db->query("SELECT * FROM `" . DB_PREFIX . "growthcontour_order_delivery` WHERE order_id = '" . (int) $order_id . "'")->row;
        return $r ? $r : null;
    }

    /** Блок delivery для замовлення в систему (без raw, якщо його не просять) */
    public function block($order_id, $withRaw = true)
    {
        $r = $this->get($order_id);
        if (!$r || !$r['carrier']) {
            return null;
        }
        $n = function ($v) {
            return $v === null || $v === '' ? null : $v;
        };
        $out = array(
            'carrier' => $r['carrier'],
            'service' => $r['service'],
            'status' => $r['status'],
            'point' => array(
                'area' => array('ref' => $n($r['area_ref']), 'name' => $n($r['area'])),
                'city' => array('ref' => $n($r['city_ref']), 'name' => $n($r['city'])),
                'warehouse' => array('ref' => $n($r['point_ref']), 'name' => $n($r['point']), 'number' => $n($r['point_number']), 'type' => $n($r['point_type'])),
            ),
            'address' => array('street' => $n($r['street']), 'building' => $n($r['building']), 'flat' => $n($r['flat'])),
            'found_by' => $n($r['found_by']),
        );
        if ($withRaw) {
            $raw = json_decode($r['raw'], true);
            $out['raw'] = is_array($raw) ? $raw : null;
        }
        return $out;
    }
}
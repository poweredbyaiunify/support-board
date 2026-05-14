<?php
use Swoole\Http\Response;

/*
 * ==========================================================
 * FUNCTIONS.PHP
 * ==========================================================
 *
 * Main PHP functions file. © 2017-2026 board.support. All rights reserved.
 *
 */

define('SB_VERSION', '3.8.9');

if (!defined('SB_PATH')) {
    $path = dirname(__DIR__, 1);
    define('SB_PATH', $path ? $path : dirname(__DIR__));
}
if (!defined('JSON_INVALID_UTF8_IGNORE')) {
    define('JSON_INVALID_UTF8_IGNORE', 0);
}
if (isset($_COOKIE['sb-cloud'])) {
    $_POST['cloud'] = $_COOKIE['sb-cloud'];
}

require_once(SB_PATH . '/config.php');
require_once(SB_PATH . '/include/users.php');
require_once(SB_PATH . '/include/messages.php');
require_once(SB_PATH . '/include/settings.php');
require_once(SB_PATH . '/include/email.php');
require_once(SB_PATH . '/include/reports.php');

global $SB_CONNECTION;
global $SB_SETTINGS;
global $SB_LOGIN;
global $SB_LANGUAGE;
global $SB_TRANSLATIONS;
const SELECT_FROM_USERS = 'SELECT id, first_name, last_name, email, profile_image, user_type, creation_time, last_activity, department, token';

class SBError {
    public $error;

    function __construct($error_code, $function = '', $message = '', $response = '') {
        $this->error = ['message' => $message, 'function' => $function, 'code' => $error_code, 'response' => $response];
    }

    public function __toString() {
        return $this->code() . ' ' . $this->message();
    }

    function message() {
        return $this->error['message'];
    }

    function code() {
        return $this->error['code'];
    }

    function response() {
        return $this->error['response'];
    }

    function function_name() {
        return $this->error['function'];
    }
}

class SBValidationError {
    public $error;

    function __construct($error_code) {
        $this->error = $error_code;
    }

    public function __toString() {
        return $this->error;
    }

    function code() {
        return $this->error;
    }
}

$sb_apps = ['dialogflow', 'slack', 'wordpress', 'tickets', 'woocommerce', 'ump', 'perfex', 'whmcs', 'aecommerce', 'messenger', 'whatsapp', 'armember', 'viber', 'telegram', 'line', 'wechat', 'zalo', 'twitter', 'zendesk', 'martfury', 'opencart'];
if (defined('SB_CUSTOM_APPS') && !empty(SB_CUSTOM_APPS)) {
    $sb_apps = array_merge($sb_apps, array_column(SB_CUSTOM_APPS, 0));
}
for ($i = 0; $i < count($sb_apps); $i++) {
    $file = SB_PATH . '/apps/' . $sb_apps[$i] . '/functions.php';
    if (file_exists($file)) {
        require_once($file);
    }
}

/*
 * -----------------------------------------------------------
 * DATABASE
 * -----------------------------------------------------------
 *
 * 1. Connection to the database
 * 2. Get database values
 * 3. Insert or update database values
 * 4. Escape and sanatize values prior to databse insertion
 * 5. Escape a JSON string prior to databse insertion
 * 6. Set default database environment settings
 * 7. Database error function
 *
 */

function sb_db_connect() {
    global $SB_CONNECTION;
    if (!defined('SB_DB_NAME') || !SB_DB_NAME) {
        return false;
    }
    if ($SB_CONNECTION) {
        sb_db_init_settings();
        return true;
    }
    $SB_CONNECTION = new mysqli();
    $certificate_path = sb_defined('SB_DB_CERTIFICATE_PATH');
    $port = defined('SB_DB_PORT') && SB_DB_PORT ? intval(SB_DB_PORT) : ini_get('mysqli.default_port');
    $socket = sb_defined('SB_DB_SOCKET', null);
    if ($certificate_path) {
        $SB_CONNECTION->ssl_set(SB_PATH . $certificate_path . SB_DB_CERTIFICATE_CLIENT_KEY, SB_PATH . $certificate_path . SB_DB_CERTIFICATE_CLIENT, SB_PATH . $certificate_path . SB_DB_CERTIFICATE_CA, null, null);
    }
    try {
        if (!$SB_CONNECTION->real_connect(SB_DB_HOST, SB_DB_USER, SB_DB_PASSWORD, SB_DB_NAME, $socket ? null : $port, $socket, $certificate_path ? MYSQLI_CLIENT_SSL : 0)) {
            echo 'Connection error. Visit the admin area for more details or open the config.php file and check the database information. Message: ' . $SB_CONNECTION->connect_error . '.';
            return false;
        }
    } catch (Exception $exception) {
        if (isset($_COOKIE['sb-cloud'])) {
            setcookie('sb-cloud', '', 0, '/');
            setcookie('sb-login', '', 0, '/');
            die('<script>localStorage.removeItem("support-board"); location.reload();</script>');
        }
    }
    sb_db_init_settings();
    return true;
}

function sb_db_get($query, $single = true) {
    global $SB_CONNECTION;
    $status = sb_db_connect();
    $value = ($single ? '' : []);
    if ($status) {
        $result = $SB_CONNECTION->query($query);
        if ($result) {
            if ($result->num_rows > 0) {
                while ($row = $result->fetch_assoc()) {
                    if ($single) {
                        $value = $row;
                    } else {
                        array_push($value, $row);
                    }
                }
            }
        } else {
            return sb_db_error('sb_db_get');
        }
    } else {
        return $status;
    }
    return $value;
}

function sb_db_query($query, $return = false) {
    global $SB_CONNECTION;
    $status = sb_db_connect();
    if ($status) {
        $result = $SB_CONNECTION->query($query);
        if ($result) {
            if ($return) {
                if (isset($SB_CONNECTION->insert_id) && $SB_CONNECTION->insert_id > 0) {
                    return $SB_CONNECTION->insert_id;
                } else {
                    return sb_db_error('sb_db_query');
                }
            } else {
                return true;
            }
        } else {
            return sb_db_error('sb_db_query');
        }
    } else {
        return $status;
    }
}

function sb_db_escape($value, $numeric = -1) {
    if (is_numeric($value)) {
        return $value;
    } else if ($numeric === true) {
        if (is_bool($numeric)) {
            return false;
        }
        die(sb_error('value-not-numeric', 'sb_db_escape', 'Value not numeric', true));
    }
    global $SB_CONNECTION;
    sb_db_connect();
    if ($SB_CONNECTION && $value) {
        $value = $SB_CONNECTION->real_escape_string($value);
    }
    $value = str_replace(['\"', '"'], ['"', '\"'], $value);
    $value = sb_sanatize_string($value);
    $value = htmlspecialchars($value, ENT_NOQUOTES | ENT_SUBSTITUTE, 'utf-8', false);
    $value = str_replace('&amp;lt;', '&lt;', $value);
    return $value;
}

function sb_db_json_escape($array) {
    if (empty($array)) {
        return '';
    }
    global $SB_CONNECTION;
    sb_db_connect();
    $value = str_replace(['"false"', '"true"'], ['false', 'true'], json_encode($array, JSON_INVALID_UTF8_IGNORE | JSON_UNESCAPED_UNICODE));
    $value = sb_sanatize_string($value);
    return $SB_CONNECTION ? $SB_CONNECTION->real_escape_string($value) : $value;
}

function sb_json_escape($value) {
    return str_replace(['"', "\'"], ['\"', "'"], $value);
}

function sb_db_error($function) {
    global $SB_CONNECTION;
    return sb_error('db-error', $function, $SB_CONNECTION->error);
}

function sb_db_check_connection($name = false, $user = false, $password = false, $host = false, $port = false) {
    global $SB_CONNECTION;
    $response = true;
    if ($name === false && defined('SB_DB_NAME')) {
        $name = SB_DB_NAME;
        $user = SB_DB_USER;
        $password = SB_DB_PASSWORD;
        $host = SB_DB_HOST;
        $port = defined('SB_DB_PORT') && SB_DB_PORT ? SB_DB_PORT : ini_get('mysqli.default_port');
    }
    if ($name === false || !$name) {
        return 'installation';
    }
    try {
        set_error_handler(function () {}, E_ALL);
        $SB_CONNECTION = new mysqli();
        $certificate_path = sb_defined('SB_DB_CERTIFICATE_PATH');
        $socket = sb_defined('SB_DB_SOCKET', null);
        if ($certificate_path) {
            $SB_CONNECTION->ssl_set(SB_PATH . $certificate_path . SB_DB_CERTIFICATE_CLIENT_KEY, SB_PATH . $certificate_path . SB_DB_CERTIFICATE_CLIENT, SB_PATH . $certificate_path . SB_DB_CERTIFICATE_CA, null, null);
        }
        $SB_CONNECTION->real_connect($host, $user, $password, $name, $socket ? null : intval($port), $socket, $certificate_path ? MYSQLI_CLIENT_SSL : 0);
        sb_db_init_settings();
    } catch (Exception $e) {
        $response = $e->getMessage();
    }
    if ($SB_CONNECTION->connect_error) {
        $response = $SB_CONNECTION->connect_error;
    }
    restore_error_handler();
    return $response;
}

function sb_db_init_settings() {
    if (sb_is_cloud()) {
        return;
    }
    global $SB_CONNECTION;
    $SB_CONNECTION->set_charset('utf8mb4');
    $SB_CONNECTION->query("SET SESSION sql_mode=(SELECT REPLACE(@@sql_mode,'ONLY_FULL_GROUP_BY',''))");
}

function sb_external_db($action, $name, $query = '', $extra = false) {
    $NAME = strtoupper($name);
    $name = strtolower($name);
    switch ($action) {
        case 'connect':
            $connection = sb_isset($GLOBALS, 'SB_' . $NAME . '_CONNECTION');
            $defined = defined('SB_' . $NAME . '_DB_NAME');
            if (!empty($connection) && $connection->ping()) {
                return true;
            }
            if (!$defined) {
                $prefix = '';
                $database = sb_get_setting($name . '-db');
                if (empty($database[$name . '-db-name'])) {
                    return sb_error('db-error', 'sb_external_db', 'Missing database details in ' . $name . ' settings area.');
                }
                define('SB_' . $NAME . '_DB_HOST', $database[$name . '-db-host']);
                define('SB_' . $NAME . '_DB_USER', $database[$name . '-db-user']);
                define('SB_' . $NAME . '_DB_PASSWORD', $database[$name . '-db-password']);
                define('SB_' . $NAME . '_DB_NAME', $database[$name . '-db-name']);
                if ($name == 'perfex' || $name == 'whmcs') {
                    define('SB_' . $NAME . '_DB_PREFIX', empty($database[$name . '-db-prefix']) ? 'tbl' : $database[$name . '-db-prefix']);
                    $prefix = PHP_EOL . 'define(\'SB_' . $NAME . '_DB_PREFIX\', \'' . sb_isset($database, $name . '-db-prefix', 'tbl') . '\');';
                }
                sb_write_config_extra('/* ' . $NAME . ' CRM  */' . PHP_EOL . 'define(\'SB_' . $NAME . '_DB_HOST\', \'' . $database[$name . '-db-host'] . '\');' . PHP_EOL . 'define(\'SB_' . $NAME . '_DB_USER\', \'' . $database[$name . '-db-user'] . '\');' . PHP_EOL . 'define(\'SB_' . $NAME . '_DB_PASSWORD\', \'' . $database[$name . '-db-password'] . '\');' . PHP_EOL . 'define(\'SB_' . $NAME . '_DB_NAME\', \'' . $database[$name . '-db-name'] . '\');' . $prefix);
            }
            $connection = new mysqli(constant('SB_' . $NAME . '_DB_HOST'), constant('SB_' . $NAME . '_DB_USER'), constant('SB_' . $NAME . '_DB_PASSWORD'), constant('SB_' . $NAME . '_DB_NAME'));
            if ($connection->connect_error) {
                if ($defined) {
                    $database = sb_get_setting($name . '-db');
                    if (constant('SB_' . $NAME . '_DB_HOST') != $database[$name . '-db-host'] || constant('SB_' . $NAME . '_DB_USER') != $database[$name . '-db-user'] || constant('SB_' . $NAME . '_DB_PASSWORD') != $database[$name . '-db-password'] || constant('SB_' . $NAME . '_DB_NAME') != $database[$name . '-db-name'] || (defined('SB_' . $NAME . '_DB_PREFIX') && constant('SB_' . $NAME . '_DB_PREFIX') != $database[$name . '-db-prefix'])) {
                        $raw = file_get_contents(SB_PATH . '/config.php');
                        sb_file(SB_PATH . '/config.php', str_replace(['/* Perfex CRM  */', 'define(\'SB_' . $NAME . '_DB_HOST\', \'' . constant('SB_' . $NAME . '_DB_HOST') . '\');', 'define(\'SB_' . $NAME . '_DB_USER\', \'' . constant('SB_' . $NAME . '_DB_USER') . '\');', 'define(\'SB_' . $NAME . '_DB_PASSWORD\', \'' . constant('SB_' . $NAME . '_DB_PASSWORD') . '\');', 'define(\'SB_' . $NAME . '_DB_NAME\', \'' . constant('SB_' . $NAME . '_DB_NAME') . '\');', defined('SB_' . $NAME . '_DB_PREFIX') ? 'define(\'SB_' . $NAME . '_DB_PREFIX\', \'' . constant('SB_' . $NAME . '_DB_PREFIX') . '\');' : ''], '', $raw));
                    }
                }
                die($connection->connect_error);
            }
            $connection->set_charset('utf8mb4');
            $connection->query("SET SESSION sql_mode=(SELECT REPLACE(@@sql_mode,'ONLY_FULL_GROUP_BY',''))");
            $GLOBALS['SB_' . $NAME . '_CONNECTION'] = $connection;
            return true;
        case 'read':
            $status = sb_external_db('connect', $name);
            $value = $extra ? '' : [];
            if ($status === true) {
                $result = $GLOBALS['SB_' . strtoupper($name) . '_CONNECTION']->query($query);
                if ($result) {
                    if ($result->num_rows > 0) {
                        while ($row = $result->fetch_assoc()) {
                            if ($extra) {
                                $value = $row;
                            } else {
                                array_push($value, $row);
                            }
                        }
                    }
                } else {
                    return sb_error('db-error', 'sb_external_db', $GLOBALS['SB_' . strtoupper($name) . '_CONNECTION']->error);
                }
            } else {
                return $status;
            }
            return $value;
        case 'write':
            $status = sb_external_db('connect', $name);
            if ($status === true) {
                $connection = $GLOBALS['SB_' . $NAME . '_CONNECTION'];
                $result = $connection->query($query);
                if ($result) {
                    if ($extra) {
                        if (isset($connection->insert_id) && $connection->insert_id > 0) {
                            return $connection->insert_id;
                        } else {
                            return sb_db_error('sb_db_query');
                        }
                    } else {
                        return true;
                    }
                } else {
                    return sb_error('db-error', 'sb_external_db', $connection->error);
                }
            }
            return $status;
    }
    return false;
}

function sb_is_error($object) {
    return is_a($object, 'SBError');
}

function sb_is_validation_error($object) {
    return is_a($object, 'SBValidationError');
}

/*
 * -----------------------------------------------------------
 * TRANSLATIONS
 * -----------------------------------------------------------
 *
 * 1. Return the translation of a string
 * 2. Echo the translation of a string
 * 3. Return the translation of a setting
 * 4. Echos the translations of as setting
 * 5. Translate using AI multilingual translation if active
 * 6. Initialize the translations
 * 7. Return the current translations array
 * 8. Return all the translations of both admin and front areas of all languages
 * 9. Return the translations of a language
 * 10. Save a translation langauge file and a copy of it as backup
 * 11. Restore a translation language file from a backup
 * 12. Return the user langauge code
 * 13. Return the langauge code of the admin area relative to the active agent
 * 14. Translate a string in the given language
 * 15. Get language code
 * 16. Return the language code of the given language name
 * 17. Check if the language is RTL
 *
 */

function sb_($string) {
    if ($string) {
        global $SB_TRANSLATIONS;
        if (!isset($SB_TRANSLATIONS)) {
            sb_init_translations();
        }
        return empty($SB_TRANSLATIONS[$string]) ? $string : $SB_TRANSLATIONS[$string];
    }
    return $string;
}

function sb_e($string) {
    echo sb_($string);
}

function sb_s($string, $disabled = false) {
    if ($disabled) {
        return $string;
    }
    global $SB_TRANSLATIONS_SETTINGS;
    if (!isset($SB_TRANSLATIONS_SETTINGS)) {
        $language = sb_get_admin_language();
        if ($language && $language != 'en') {
            $file_path = SB_PATH . '/resources/languages/admin/settings/' . $language . '.json';
            if (file_exists($file_path)) {
                $SB_TRANSLATIONS_SETTINGS = json_decode(file_get_contents($file_path), true);
                $custom_apps = sb_custom_apps_get_active();
                foreach ($custom_apps as $app) {
                    $path_app = SB_PATH . '/apps/' . $app[0] . '/languages/admin/settings/' . $language . '.json';
                    if (file_exists($path_app)) {
                        $app_translations = json_decode(file_get_contents($path_app), true);
                        if ($app_translations) {
                            $SB_TRANSLATIONS_SETTINGS = array_merge($SB_TRANSLATIONS_SETTINGS, $app_translations);
                        }
                    }
                }
            }
        }
    }
    return empty($SB_TRANSLATIONS_SETTINGS[$string]) ? $string : $SB_TRANSLATIONS_SETTINGS[$string];
}

function sb_se($string) {
    echo sb_s($string);
}

function sb_t($string, $language_code = false) {
    global $SB_LANGUAGE;
    global $SB_TRANSLATIONS;
    if (!$language_code || $language_code == -1) {
        $language_code = sb_get_user_language(sb_get_active_user_ID());
    }
    if (!$language_code || $language_code == -1 || $language_code == 'en') {
        return $string;
    }
    if (empty($SB_LANGUAGE) || empty($SB_TRANSLATIONS) || $SB_LANGUAGE[0] != $language_code) {
        if (!empty($_POST['init.php']) && !sb_get_setting('front-auto-translations')) {
            $SB_LANGUAGE = [sb_defined('SB_CLOUD_DEFAULT_LANGUAGE_CODE', 'en'), 'front'];
        } else {
            $path = SB_PATH . '/resources/languages/front/' . $language_code . '.json';
            if (sb_is_cloud()) {
                $cloud_path = SB_PATH . '/uploads/cloud/languages/' . sb_isset(sb_cloud_account(), 'user_id') . '/front/' . $language_code . '.json';
                if (file_exists($cloud_path)) {
                    $path = $cloud_path;
                }
            }
            if (file_exists($path)) {
                $SB_TRANSLATIONS = json_decode(file_get_contents($path), true);
                $SB_LANGUAGE = [$language_code, 'front'];
            }
        }
    }
    if (empty($SB_TRANSLATIONS[$string])) {
        if (defined('SB_DIALOGFLOW') && (sb_get_setting('dialogflow-multilingual-translation') || sb_get_multi_setting('google', 'google-multilingual-translation'))) { // Deprecated: sb_get_setting('dialogflow-multilingual-translation')
            $response = sb_google_translate([$string], $language_code);
            if (!empty($response)) {
                $translations_to_save = $SB_TRANSLATIONS;
                if (empty($translations_to_save)) {
                    $path = SB_PATH . '/resources/languages/front/' . $language_code . '.json';
                    if (file_exists($path)) {
                        $translations_to_save = json_decode(file_get_contents($path), true);
                    }
                }
                $translation = $response[0];
                if ($translations_to_save) {
                    $translations_to_save[$string] = $translation;
                    $translations_file = [];
                    $translations_file[$language_code] = ['front' => $translations_to_save];
                    sb_save_translations($translations_file);
                }
                return $translation;
            }
        }
        return $string;
    }
    return $SB_TRANSLATIONS[$string];
}

function sb_init_translations() {
    global $SB_TRANSLATIONS;
    global $SB_LANGUAGE;
    $SB_CLOUD_DEFAULT_LANGUAGE_CODE = sb_defined('SB_CLOUD_DEFAULT_LANGUAGE_CODE');
    if (!empty($SB_LANGUAGE) && ($SB_LANGUAGE[0] != 'en' || $SB_CLOUD_DEFAULT_LANGUAGE_CODE)) {
        $path = SB_PATH . '/resources/languages/' . $SB_LANGUAGE[1] . '/' . $SB_LANGUAGE[0] . '.json';
        $cloud_user_id = sb_is_cloud() ? sb_isset(sb_cloud_account(), 'user_id') : False;
        if ($cloud_user_id) {
            $cloud_path = SB_PATH . '/uploads/cloud/languages/' . $cloud_user_id . '/' . $SB_LANGUAGE[1] . '/' . $SB_LANGUAGE[0] . '.json';
            if (file_exists($cloud_path)) {
                $path = $cloud_path;
            }
        }
        if (file_exists($path)) {
            $SB_TRANSLATIONS = json_decode(file_get_contents($path), true);
            $custom_apps = sb_custom_apps_get_active();
            foreach ($custom_apps as $app) {
                $path_app = SB_PATH . '/apps/' . $app[0] . '/languages/' . $SB_LANGUAGE[1] . '/' . $SB_LANGUAGE[0] . '.json';
                if (file_exists($path_app)) {
                    $app_translations = json_decode(file_get_contents($path_app), true);
                    if ($app_translations) {
                        $SB_TRANSLATIONS = array_merge($SB_TRANSLATIONS, $app_translations);
                    }
                    if ($cloud_user_id) {
                        $app_translation_path = SB_PATH . '/uploads/cloud/languages/' . $cloud_user_id . '/' . $SB_LANGUAGE[1] . '/' . $SB_LANGUAGE[0] . '-' . $app[0] . '.json';
                        if (file_exists($app_translation_path)) {
                            $app_translations = json_decode(file_get_contents($app_translation_path), true);
                            if ($app_translations) {
                                $SB_TRANSLATIONS = array_merge($SB_TRANSLATIONS, $app_translations);
                            }
                        }
                    }
                }
            }
        } else {
            $SB_TRANSLATIONS = false;
        }
    } else if (!isset($SB_LANGUAGE)) {
        $SB_TRANSLATIONS = false;
        $SB_LANGUAGE = false;
        $is_init = !empty($_POST['init.php']);
        if ($is_init && !sb_get_setting('front-auto-translations')) {
            $SB_LANGUAGE = [!empty($_POST['language']) && $_POST['language'] != 'false' ? strtolower($_POST['language'][0]) : ($SB_CLOUD_DEFAULT_LANGUAGE_CODE ? $SB_CLOUD_DEFAULT_LANGUAGE_CODE : 'en'), 'front'];
            if ($SB_LANGUAGE[0] != 'en') {
                sb_init_translations();
            } else {
                return;
            }
        }
        $is_admin = sb_is_agent();
        $language = $is_admin ? sb_get_admin_language() : sb_get_user_language(sb_get_active_user_ID());
        if (($language && $language != 'en') && ($is_admin || isset($_GET['lang']) || $is_init || (sb_get_setting('dialogflow-multilingual-translation') || sb_get_multi_setting('google', 'google-multilingual-translation')))) { // Deprecated: sb_get_setting('dialogflow-multilingual-translation')
            switch ($language) {
                case 'nn':
                case 'nb':
                    $language = 'no';
                    break;
            }
            $area = $is_admin ? 'admin' : 'front';
            $SB_LANGUAGE = [$language, $area];
            if ($SB_LANGUAGE[0] != 'en') {
                sb_init_translations();
            } else {
                return;
            }
        }
    }
}

function sb_get_current_translations() {
    global $SB_TRANSLATIONS;
    if (!isset($SB_TRANSLATIONS)) {
        sb_init_translations();
    }
    return $SB_TRANSLATIONS;
}

function sb_get_translations($is_user = false, $language_code = false) {
    $translations = [];
    $cloud_path = false;
    if ($is_user && !file_exists(SB_PATH . '/uploads/languages')) {
        return [];
    }
    $path = $is_user ? '/uploads' : '/resources';
    $language_codes = sb_get_json_resource('languages/language-codes.json');
    $paths = ['front', 'admin', 'admin/js', 'admin/settings'];
    if (sb_is_cloud()) {
        $cloud = sb_cloud_account();
        $cloud_path = SB_PATH . '/uploads/cloud/languages/' . $cloud['user_id'];
    }
    for ($i = 0; $i < count($paths); $i++) {
        $files = scandir(SB_PATH . $path . '/languages/' . $paths[$i]);
        for ($j = 0; $j < count($files); $j++) {
            $file = $files[$j];
            if (strpos($file, '.json')) {
                $code = substr($file, 0, -5);
                if (!isset($language_codes[$code]) || ($language_code && $language_code != $code)) {
                    continue;
                }
                if (!isset($translations[$code])) {
                    $translations[$code] = ['name' => $language_codes[$code]];
                }
                $translation_strings = json_decode(file_get_contents($cloud_path && file_exists($cloud_path . '/' . $paths[$i] . '/' . $file) ? ($cloud_path . '/' . $paths[$i] . '/' . $file) : (SB_PATH . $path . '/languages/' . $paths[$i] . '/' . $file)), true);
                $translations[$code][$paths[$i]] = $translation_strings;
            }
        }
    }
    return $translations;
}

function sb_get_translation($language_code) {
    return sb_isset(sb_get_translations(false, $language_code), $language_code);
}

function sb_save_translations($translations) {
    $is_cloud = sb_is_cloud();
    $cloud_path = false;
    if (!$is_cloud && !file_exists(SB_PATH . '/uploads/languages')) {
        mkdir(SB_PATH . '/uploads/languages', 0755, true);
        mkdir(SB_PATH . '/uploads/languages/front', 0755, true);
        mkdir(SB_PATH . '/uploads/languages/admin', 0755, true);
        mkdir(SB_PATH . '/uploads/languages/admin/js', 0755, true);
        mkdir(SB_PATH . '/uploads/languages/admin/settings', 0755, true);
    }
    if ($is_cloud) {
        $cloud = sb_cloud_account();
        $cloud_path = SB_PATH . '/uploads/cloud/languages/' . $cloud['user_id'];
        if (!file_exists(SB_PATH . '/uploads/cloud')) {
            mkdir(SB_PATH . '/uploads/cloud', 0755, true);
            mkdir(SB_PATH . '/uploads/cloud/languages', 0755, true);
        }
        if (!file_exists($cloud_path)) {
            mkdir($cloud_path, 0755, true);
            mkdir($cloud_path . '/front', 0755, true);
            mkdir($cloud_path . '/admin', 0755, true);
            mkdir($cloud_path . '/admin/js', 0755, true);
            mkdir($cloud_path . '/admin/settings', 0755, true);
        }
    }
    if (is_string($translations)) {
        $translations = json_decode($translations, true);
    }
    foreach ($translations as $key => $translation) {
        foreach ($translation as $key_area => $translations_list) {
            $json = str_replace('\\\n', '\n', html_entity_decode(json_encode($translations_list, JSON_INVALID_UTF8_IGNORE, JSON_UNESCAPED_UNICODE)));
            if ($json) {
                if ($is_cloud) {
                    sb_file($cloud_path . '/' . $key_area . '/' . $key . '.json', $json);
                } else {
                    $paths = ['resources', 'uploads'];
                    for ($i = 0; $i < 2; $i++) {
                        sb_file(SB_PATH . '/' . $paths[$i] . '/languages/' . $key_area . '/' . $key . '.json', $json);
                    }
                }
            }
        }
    }
    return true;
}

function sb_restore_user_translations() {
    $translations_all = sb_get_translations();
    $translations_user = sb_get_translations(true);
    $paths = ['front', 'admin', 'admin/js', 'admin/settings'];
    foreach ($translations_user as $key => $translations) {
        for ($i = 0; $i < count($paths); $i++) {
            $path = $paths[$i];
            if (isset($translations_all[$key]) && isset($translations_all[$key][$path])) {
                foreach ($translations_all[$key][$path] as $key_two => $translation) {
                    if (!isset($translations[$path][$key_two])) {
                        $translations[$path][$key_two] = $translations_all[$key][$path][$key_two];
                    }
                }
            }
            sb_file(SB_PATH . '/resources/languages/' . $path . '/' . $key . '.json', json_encode($translations[$path], JSON_INVALID_UTF8_IGNORE));
        }
    }
}

function sb_get_user_language($user_id = false, $allow_browser_language = false) {
    global $SB_USER_LANGUAGES;
    $slug = $user_id ? $user_id : 'default';
    if (isset($SB_USER_LANGUAGES) && isset($SB_USER_LANGUAGES[$slug])) {
        return $SB_USER_LANGUAGES[$slug];
    }
    $setting_language = sb_is_agent() && (!$user_id || sb_get_active_user_ID() == $user_id) ? false : sb_get_setting('front-auto-translations');
    if ($setting_language && $setting_language != 'auto') {
        $SB_USER_LANGUAGES[$slug] = $setting_language;
        return $setting_language;
    }
    global $SB_LANGUAGE;
    $language = false;
    $post_language = sb_isset($_POST, 'language');
    if ($user_id && is_numeric($user_id)) {
        $language = sb_get_user_extra($user_id, 'language');
        if (!$language && (!$post_language || sb_isset($post_language, 0) == 'en') && ($allow_browser_language || $setting_language == 'auto')) {
            $language = sb_get_user_extra($user_id, 'browser_language');
        }
    }
    if (!$language && $post_language && $post_language != 'false') {
        $language = strtolower(sb_isset($post_language, 0));
    }
    if ($language) {
        $SB_USER_LANGUAGES[$slug] = sb_language_code(strtolower($language));
        return $SB_USER_LANGUAGES[$slug];
    }
    if (empty($SB_LANGUAGE)) {
        $language_code = strtolower(sb_isset($_SERVER, 'HTTP_ACCEPT_LANGUAGE'));
        $SB_USER_LANGUAGES[$slug] = $language_code ? sb_language_code($language_code) : '';
        return $SB_USER_LANGUAGES[$slug];
    }
    return $SB_LANGUAGE[0];
}

function sb_get_admin_language($user_id = false, $default = false) {
    $language = defined('SB_ADMIN_LANG') ? trim(strtolower(SB_ADMIN_LANG)) : (sb_get_setting('admin-auto-translations') ? trim(strtolower(sb_get_user_language($user_id ? $user_id : sb_get_active_user_ID()))) : false);
    return $language && ($language != 'en' || defined('SB_CLOUD_DEFAULT_LANGUAGE_CODE')) ? $language : sb_defined('SB_CLOUD_DEFAULT_LANGUAGE_CODE', $language ? $language : $default);
}

function sb_language_code($language_code_full) {
    switch (strtolower($language_code_full)) {
        case 'pt_br';
            return 'br';
        case 'zh_cn';
            return 'zh';
        case 'zh_tw';
            return 'tw';
    }
    return substr($language_code_full, 0, 2);
}

function sb_get_language_code_by_name($language_name, &$language_codes = false, $is_name_by_code = false) {
    $language_codes = empty($language_codes) ? sb_get_json_resource('languages/language-codes.json') : $language_codes;
    if ((!$is_name_by_code && strlen($language_name) > 2) || ($is_name_by_code && strlen($language_name) == 2)) {
        if ($is_name_by_code) {
            return sb_isset($language_codes, strtolower($language_name));
        }
        $language_code = ucfirst($language_name);
        foreach ($language_codes as $key => $value) {
            if ($language_code == $value) {
                return $key;
            }
        }
    }
    return $language_name;
}

function sb_get_language_name_by_code($language_code) {
    $false = false;
    return sb_get_language_code_by_name($language_code, $false, true);
}

function sb_is_rtl($language_code = false) {
    return in_array($language_code ? $language_code : sb_get_user_language(sb_get_active_user_ID()), ['ar', 'he', 'ku', 'fa', 'ur']);
}

/*
 * ----------------------------------------------------------
 * APPS, UPDATES, INSTALLATION
 * ----------------------------------------------------------
 *
 * 1. Get the plugin and apps versions and install, activate and update apps
 * 2. Check if the app license is valid and install or update it
 * 3. Install or update an app
 * 4. Update Support Board and all apps
 * 5. Compatibility function for new versions
 * 6. Check if there are updates available
 * 7. Get installed app versions array
 * 8. Plugin installation function
 * 9. Update the config.php file
 * 10. Return the upload path or url
 * 11. Return the installation directory name
 *
 */

function sb_get_versions() {
    return json_decode(sb_download('https://board.support/synch/versions.json'), true);
}

function sb_app_get_key($app_name) {
    $keys = sb_get_external_setting('app-keys');
    return isset($keys[$app_name]) ? $keys[$app_name] : '';
}

function sb_app_activation($app_name, $key) {
    if (sb_is_cloud()) {
        $active_apps = sb_get_external_setting('active_apps', []);
        array_push($active_apps, $app_name);
        return sb_save_external_setting('active_apps', $active_apps);
    }
    $envato_code = sb_get_setting('envato-purchase-code');
    if (!$envato_code) {
        return new SBValidationError('envato-purchase-code-not-found');
    }
    $key = trim($key);
    $response = sb_download('https://board.support/synch/updates.php?sb=' . trim($envato_code) . '&' . $app_name . '=' . $key . '&domain=' . SB_URL);
    if ($response == 'purchase-code-limit-exceeded') {
        return new SBValidationError('purchase-code-limit-exceeded');
    }
    $response = json_decode($response, true);
    if (empty($response[$app_name])) {
        return new SBValidationError('invalid-key');
    }
    if ($response[$app_name] == 'purchase-code-limit-exceeded') {
        return new SBValidationError('app-purchase-code-limit-exceeded');
    }
    if ($response[$app_name] == 'expired') {
        return new SBValidationError('expired');
    }
    return sb_app_update($app_name, $response[$app_name], $key);
}

function sb_app_disable($app_name) {
    $active_apps = sb_get_external_setting('active_apps', []);
    $index = array_search($app_name, $active_apps);
    if ($index !== false) {
        array_splice($active_apps, $index, 1);
        return sb_save_external_setting('active_apps', $active_apps);
    }
    return false;
}

function sb_app_update($app_name, $file_name, $key = false) {
    if (!$file_name) {
        return new SBValidationError('temporary-file-name-not-found');
    }
    $key = trim($key);
    $error = '';
    $zip = sb_download('https://board.support/synch/temp/' . $file_name);
    if ($zip) {
        $file_path = SB_PATH . '/uploads/' . $app_name . '.zip';
        if (!file_exists(dirname($file_path))) {
            mkdir(dirname($file_path), 0755, true);
        }
        file_put_contents($file_path, $zip);
        if (file_exists($file_path)) {
            $zip = new ZipArchive;
            if ($zip->open($file_path) === true) {
                $zip->extractTo($app_name == 'sb' ? (defined('SB_WP') ? substr(SB_PATH, 0, -13) : SB_PATH) : SB_PATH . '/apps');
                $zip->close();
                unlink($file_path);
                if ($app_name == 'sb') {
                    sb_restore_user_translations();
                    sb_file(SB_PATH . '/sw.js', str_replace('sb-' . str_replace('.', '-', SB_VERSION), 'sb-' . str_replace('.', '-', sb_get_versions()['sb']), file_get_contents(SB_PATH . '/sw.js')));
                    return 'success';
                }
                if (file_exists(SB_PATH . '/apps/' . $app_name)) {
                    if (!empty($key)) {
                        $keys = sb_get_external_setting('app-keys');
                        $keys[$app_name] = $key;
                        sb_save_external_setting('app-keys', $keys);
                    }
                    return 'success';
                } else {
                    $error = 'zip-extraction-error';
                }
            } else {
                $error = 'zip-error';
            }
        } else {
            $error = 'file-not-found';
        }
    } else {
        $error = 'download-error';
    }
    return $error ? new SBValidationError($error) : false;
}

function sb_update() {
    $envato_code = sb_get_setting('envato-purchase-code');
    if (!$envato_code) {
        return new SBValidationError('envato-purchase-code-not-found');
    }
    $latest_versions = sb_get_versions();
    $installed_apps_versions = sb_get_installed_apps_version();
    $keys = sb_get_external_setting('app-keys');
    $result = [];
    $link = (SB_VERSION != $latest_versions['sb'] ? 'sb=' : 'sbcode=') . trim($envato_code) . '&';
    foreach ($installed_apps_versions as $key => $value) {
        if ($value && $value != $latest_versions[$key]) {
            if (isset($keys[$key])) {
                $link .= $key . '=' . trim($keys[$key]) . '&';
            } else {
                $result[$key] = 'license-key-not-found';
            }
        }
    }
    if (isset($_POST['domain'])) {
        $link .= 'domain=' . $_POST['domain'] . '&';
    }
    $downloads = sb_download('https://board.support/synch/updates.php?' . substr($link, 0, -1));
    if (empty($downloads)) {
        return new SBValidationError('empty-or-null');
    }
    if (in_array($downloads, ['invalid-envato-purchase-code', 'purchase-code-limit-exceeded', 'banned', 'missing-arguments'])) {
        return new SBValidationError($downloads);
    }
    $downloads = json_decode($downloads, true);
    foreach ($downloads as $key => $value) {
        if ($value) {
            $result[$key] = !$value || $value == 'expired' ? $value : sb_app_update($key, $value);
        }
    }
    return $result;
}

function sb_updates_validation() {
    if (sb_isset($_COOKIE, 'sb-updates') != SB_VERSION && !sb_is_debug()) {
        sb_cloud_load();
        $save = false;
        $settings = false;
        try {
            $settings = sb_get_settings();

            //3.8.8
            if (sb_isset($settings, ['open-ai', 0, 'open-ai-rewrite', 0])) {
                $settings['open-ai-rewrite'][0]['open-ai-rewrite-active'][0] = true;
                $save = true;
            }
            $value = sb_isset($settings, ['open-ai', 0, 'open-ai-prompt-message-rewrite', 0]);
            if ($value) {
                $settings['open-ai-rewrite'][0]['open-ai-rewrite-prompt'][0] = $value;
                $save = true;
            }
            $value = sb_get_external_setting('emails');
            if ($value && !sb_isset($value, ['email-template', 0])) {
                $value_ = [sb_isset($value, ['email-header', 0], ''), sb_isset($value, ['email-signature', 0], '')];
                if ($value_[0] || $value_[1]) {
                    $value['email-template'] = [$value_[0] . '{content}' . $value_[1], 'textarea'];
                    sb_save_external_setting('emails', $value);
                }
            }

            //3.8.7
            $delay = sb_isset($settings, ['welcome-message', 0, 'welcome-delay', 0]);
            if ($delay && $delay > 1000) {
                $settings['welcome-message'][0]['welcome-delay'][0] = $delay / 1000;
                $save = true;
            }
            $delay = sb_isset($settings, ['dialogflow-bot-delay', 0, 'follow-delay', 0]);
            if ($delay && $delay > 1000) {
                $settings['follow-message'][0]['follow-delay'][0] = $delay / 1000;
                $save = true;
            }
            $delay = sb_isset($settings, ['dialogflow-bot-delay', 0]);
            if ($delay && $delay > 1000) {
                $settings['dialogflow-bot-delay'][0] = $delay / 1000;
                $save = true;
            }

            if (!headers_sent()) {
                setcookie('sb-updates', SB_VERSION, time() + 31556926, '/');
            }
            if ($save && $settings) {
                sb_save_settings($settings);
            }
        } catch (Exception $e) {
            if ($save && $settings) {
                sb_save_settings($settings);
            }
        }
    }
}

function sb_updates_available() {
    $latest_versions = sb_get_versions();
    if (SB_VERSION != $latest_versions['sb']) {
        return true;
    }
    $installed_apps_versions = sb_get_installed_apps_version();
    foreach ($installed_apps_versions as $key => $value) {
        if ($value && $value != $latest_versions[$key]) {
            return true;
        }
    }
    return false;
}

function sb_get_installed_apps_version() {
    $is_not_cloud = !sb_is_cloud();
    return ['dialogflow' => sb_defined('SB_DIALOGFLOW'), 'slack' => sb_defined('SB_SLACK'), 'tickets' => sb_defined('SB_TICKETS'), 'woocommerce' => $is_not_cloud ? sb_defined('SB_WOOCOMMERCE') : false, 'ump' => $is_not_cloud ? sb_defined('SB_UMP') : false, 'perfex' => $is_not_cloud ? sb_defined('SB_PERFEX') : false, 'whmcs' => $is_not_cloud ? sb_defined('SB_WHMCS') : false, 'aecommerce' => $is_not_cloud ? sb_defined('SB_AECOMMERCE') : false, 'messenger' => sb_defined('SB_MESSENGER'), 'whatsapp' => sb_defined('SB_WHATSAPP'), 'armember' => $is_not_cloud ? sb_defined('SB_ARMEMBER') : false, 'telegram' => sb_defined('SB_TELEGRAM'), 'viber' => sb_defined('SB_VIBER'), 'line' => sb_defined('SB_LINE'), 'wechat' => sb_defined('SB_WECHAT'), 'zalo' => sb_defined('SB_ZALO'), 'twitter' => sb_defined('SB_TWITTER'), 'zendesk' => sb_defined('SB_ZENDESK'), 'martfury' => $is_not_cloud ? sb_defined('SB_MARTFURY') : false, 'opencart' => $is_not_cloud ? sb_defined('SB_OPENCART') : false];
}


function sb_installation($details, $force = false) {
    $database = [];
    if (sb_db_check_connection() === true && !$force) {
        return true;
    }
    if (empty($details['envato-purchase-code']) && defined('SB_WP')) {
        return false;
    }
    if (!isset($details['db-name']) || !isset($details['db-user']) || !isset($details['db-password']) || !isset($details['db-host'])) {
        return ['error' => 'Missing database details.'];
    } else {
        $database = ['name' => $details['db-name'][0], 'user' => $details['db-user'][0], 'password' => $details['db-password'][0], 'host' => $details['db-host'][0], 'port' => (isset($details['db-port']) && $details['db-port'][0] ? intval($details['db-port'][0]) : ini_get('mysqli.default_port'))];
    }
    if (!sb_is_cloud()) {
        if (!isset($details['url'])) {
            return ['error' => 'Support Board cannot get the plugin URL.'];
        } else if (substr($details['url'], -1) == '/') {
            $details['url'] = substr($details['url'], 0, -1);
        }
    }
    $connection_check = sb_db_check_connection($database['name'], $database['user'], $database['password'], $database['host'], $database['port']);
    $response = [];
    if ($connection_check === true) {

        // Create the database
        $response = sb_installation_db($database['host'], $database['user'], $database['password'], $database['name'], $database['port'], $details['envato-purchase-code'][0], $details['url'], $details);

        // Create the config.php file and other files
        if (!sb_is_cloud()) {
            $raw = file_get_contents(SB_PATH . '/resources/config-source.php');
            $raw = str_replace(['[url]', '[name]', '[user]', '[password]', '[host]', '[port]'], [$details['url'], $database['name'], $database['user'], $database['password'], $database['host'], (isset($details['db-port']) && $details['db-port'][0] ? $database['port'] : '')], $raw);
            $path = SB_PATH . '/sw.js';
            if (defined('SB_WP')) {
                $raw = str_replace('/* [extra] */', sb_wp_config(), $raw);
            }
            sb_file(SB_PATH . '/config.php', $raw);
            if (!file_exists($path)) {
                copy(SB_PATH . '/resources/sw.js', $path);
            }
        }

        // Return
        if ($response && isset($response['error'])) {
            return $response;
        }
        sb_get('https://board.support/synch/index.php?site=' . urlencode($details['url']));
        return $response;
    } else {
        return ['error' => $connection_check == 'connection-error' ? 'Support Board cannot connect to the database. Please check the database information and try again.' : $connection_check];
    }
}

function sb_installation_db($host, $user, $password, $name, $port, $envato_purchase_code, $url, $user_details) {
    $response = [];
    $connection = new mysqli($host, $user, $password, $name, $port ? $port : null);
    if (!sb_is_cloud()) {
        $connection->set_charset('utf8mb4');
    }
    $sql_database = sb_get('https://board.support/synch/updates.php?db=' . $envato_purchase_code . '&domain=' . urlencode($url));
    if (!empty($sql_database) && str_contains($sql_database, 'CREATE TABLE')) {
        $sql_database = explode(';', $sql_database);
        foreach ($sql_database as $query) {
            if (str_contains($query, 'CREATE TABLE')) {
                $response_ = $connection->query($query);
                if ($response_ !== true) {
                    $response['error'] = $response_;
                }
            }
        }
        $response['cv'] = password_hash('VGC' . 'KME' . 'N' . 'S', PASSWORD_DEFAULT);
    } else {
        return ['error' => 'Invalid Envato purchase code.'];
    }

    // Create the admin user
    if (isset($user_details['first-name']) && isset($user_details['last-name']) && isset($user_details['email']) && isset($user_details['password'])) {
        $now = sb_gmt_now();
        $token = bin2hex(openssl_random_pseudo_bytes(20));
        $response_ = $connection->query('INSERT IGNORE INTO sb_users(first_name, last_name, password, email, profile_image, user_type, creation_time, token, last_activity) VALUES ("' . sb_db_escape($user_details['first-name'][0]) . '", "' . sb_db_escape($user_details['last-name'][0]) . '", "' . (defined('SB_WP') ? $user_details['password'][0] : password_hash($user_details['password'][0], PASSWORD_DEFAULT)) . '", "' . sb_db_escape($user_details['email'][0]) . '", "' . (empty($user_details['profile_image']) || $user_details['profile_image'] == 'false' ? sb_db_escape(sb_isset($user_details, 'url', $url) . '/media/user.svg') : $user_details['profile_image']) . '", "admin", "' . $now . '", "' . $token . '", "' . $now . '")');
        if ($response_ !== true) {
            $response['error'] = $response_;
        } else {
            $connection->query('INSERT IGNORE INTO sb_settings (name, value) VALUES ("settings", "{\"envato-purchase-code\":[\"' . $envato_purchase_code . '\",\"password\"]}")');
        }
    }
    return $response;
}

function sb_write_config_extra($content) {
    $raw = file_get_contents(SB_PATH . '/config.php');
    sb_file(SB_PATH . '/config.php', str_replace('?>', $content . PHP_EOL . PHP_EOL . '?>', $raw));
}

function sb_upload_path($url = false, $date = false) {
    return (defined('SB_UPLOAD_PATH') && SB_UPLOAD_PATH && defined('SB_UPLOAD_URL') && SB_UPLOAD_URL ? ($url ? SB_UPLOAD_URL : SB_UPLOAD_PATH) : ($url ? ((defined('SB_URL') ? SB_URL : CLOUD_URL . '/script') . '/') : (SB_PATH . '/')) . 'uploads') . ($date ? ('/' . date('d-m-y')) : '');
}

function sb_dir_name() {
    return substr(SB_URL, strrpos(SB_URL, '/') + 1);
}

/*
 * ----------------------------------------------------------
 * PUSHER AND PUSH NOTIFICATIONS
 * ----------------------------------------------------------
 *
 * 1. Send a Push notification
 * 2. Trigger a event on a channel
 * 3. Get all online users including admins and agents
 * 4. Check if there is at least one agent online
 * 5. Check if pusher is active
 * 6. Initialize the Pusher PHP SDK
 * 7. Pusher curl
 * 8. OneSignal curl
 *
 */

function sb_push_notification($title = '', $message = '', $icon = '', $interest = false, $conversation_id = false, $user_id = false, $attachments = false) {
    $recipient_agent = false;
    if (!$user_id) {
        $user_id = sb_get_active_user_ID();
    }
    if ($interest == 'agents' || (is_string($interest) && str_contains($interest, 'department-'))) {
        $interest = sb_get_notification_agent_ids($interest);
        $recipient_agent = true;
    } else if (is_numeric($interest) || is_array($interest)) {
        $agents_ids = sb_get_agents_ids();
        $is_user = !sb_is_agent();
        if (is_numeric($interest)) {
            if (!in_array(intval($interest), $agents_ids) && $interest != $user_id) {
                if ($is_user && empty($GLOBALS['SB_FORCE_ADMIN'])) {
                    return sb_error('security-error', 'sb_push_notification');
                }
            } else {
                $recipient_agent = true;
            }
        } else {
            for ($i = 0; $i < count($interest); $i++) {
                if (!in_array(intval($interest[$i]), $agents_ids)) {
                    if ($is_user && empty($GLOBALS['SB_FORCE_ADMIN'])) {
                        return sb_error('security-error', 'sb_push_notification');
                    }
                } else {
                    $recipient_agent = true;
                }
            }
        }
    }
    if (empty($interest)) {
        return false;
    }
    if (empty($icon) || strpos($icon, 'user.svg')) {
        $icon = sb_is_cloud() ? SB_CLOUD_BRAND_ICON_PNG : sb_get_setting('notifications-icon', SB_URL . '/media/icon.png');
    }
    if (sb_is_agent() && !$recipient_agent) {
        $link = $conversation_id ? sb_isset(sb_db_get('SELECT B.value FROM sb_conversations A, sb_users_data B WHERE A.id = ' . sb_db_escape($conversation_id, true) . ' AND A.user_id = B.user_id AND B.slug = "current_url" LIMIT 1'), 'value', '') : false;
    } else {
        $link = (sb_is_cloud() ? CLOUD_URL : SB_URL . '/admin.php') . ($conversation_id ? '?conversation=' . $conversation_id : '');
    }
    if (strlen($message) > 500) {
        $message = mb_substr($message, 0, 497) . '...';
    }
    if (defined('SB_DIALOGFLOW') && (is_numeric($interest) || (is_array($interest) && is_numeric($interest[0])))) {
        $message_translated = sb_google_translate_auto($message, is_numeric($interest) ? $interest : $interest[0]);
        $message = empty($message_translated) ? $message : $message_translated;
    }
    $is_pusher = !sb_is_cloud() && sb_get_multi_setting('push-notifications', 'push-notifications-provider') == 'pusher';
    $image = $attachments && count($attachments) && in_array(pathinfo($attachments[0][1], PATHINFO_EXTENSION), ['jpeg', 'jpg', 'png', 'gif']) ? $attachments[0][1] : false;
    $title = str_replace('"', '', $title);
    $message = str_replace('"', '', sb_clear_text_formatting(trim(preg_replace('/\s+/', ' ', $message))));
    $response = false;
    $query_data = ['conversation_id' => $conversation_id, 'user_id' => $user_id, 'image' => $image ? $image : '', 'badge' => SB_URL . '/media/badge.png'];
    if ($is_pusher) {
        $query = ['web' => ['notification' => ['title' => $title, 'body' => $message, 'icon' => $icon, 'hide_notification_if_site_has_focus' => true,], 'data' => $query_data]];
        if ($link) {
            $query['web']['notification']['deep_link'] = $link;
        }
        if (is_array($interest) && count($interest) > 100) {
            $interests = [];
            $index = 0;
            $count = count($interest);
            for ($i = 0; $i < $count; $i++) {
                array_push($interests, $interest[$i]);
                $index++;
                if ($index == 100 || $i == $count - 1) {
                    $query['interests'] = $interests;
                    $response = sb_pusher_curl('publishes', json_encode($query, JSON_INVALID_UTF8_IGNORE | JSON_UNESCAPED_UNICODE));
                    $interests = [];
                    $index = 0;
                }
            }
        } else {
            $query['interests'] = is_array($interest) ? $interest : [str_replace(' ', '', $interest)];
            $response = sb_pusher_curl('publishes', json_encode($query, JSON_INVALID_UTF8_IGNORE | JSON_UNESCAPED_UNICODE));
        }
    } else {
        $query = ['headings' => ['en' => $title], 'contents' => ['en' => $message], 'chrome_web_badge' => SB_URL . '/media/badge.png', 'firefox_icon' => $icon, 'chrome_web_icon' => $icon, 'priority' => 10, 'data' => $query_data];
        if ($link) {
            $query['url'] = $link;
        }
        if ($conversation_id) {
            $query['collapse_id'] = $conversation_id;
        }
        if ($image) {
            $query['chrome_web_image'] = $image;
            $query['chrome_big_picture'] = $image;
            if (!$message) {
                $query['contents']['en'] = $image;
            }
        }
        if (is_numeric($interest) || is_array($interest)) {
            $cloud_id = sb_is_cloud() ? sb_cloud_account()['user_id'] . '-' : '';
            if (is_numeric($interest)) {
                $interest = [$interest];
            }
            for ($i = 0; $i < count($interest); $i++) {
                $interest[$i] = $cloud_id . strval($interest[$i]);
                if ($interest[$i] == 1) {
                    $interest[$i] = 'SB-1';
                }
            }
            $query['include_aliases'] = ['external_id' => $interest];
            $query['target_channel'] = ['push'];
        }
        $response = sb_onesignal_curl('notifications', $query);
    }
    return isset($response['error']) ? trigger_error($response['description']) : $response;
}

function sb_pusher_trigger($channel, $event, $data = []) {
    $pusher = sb_pusher_init();
    $user_id = sb_get_active_user_ID();
    $data['user_id'] = $user_id;
    $security = sb_isset($GLOBALS, 'SB_FORCE_ADMIN');
    $count = is_array($channel) ? count($channel) : false;
    if (!$security) {
        switch ($event) {
            case 'message-status-update':
            case 'set-agent-status':
            case 'agent-active-conversation-changed':
            case 'add-user-presence':
            case 'init':
            case 'new-message':
            case 'new-conversation':
            case 'client-typing':
            case 'close-notifications':
            case 'close-notifications-received':
            case 'typing':
                $security = sb_is_agent() || $channel == ('private-user-' . $user_id);
                break;
            case 'update-conversations':
                if ($user_id) {
                    $security = true;
                }
                break;
        }
    }
    if (sb_is_cloud()) {
        $account_id = sb_isset(sb_cloud_account(), 'user_id');
        if ($account_id) {
            if ($count) {
                for ($i = 0; $i < $count; $i++) {
                    $channel[$i] .= '-' . $account_id;
                }
            } else {
                $channel .= '-' . $account_id;
            }
        }
    }
    if ($security) {
        if ($count > 100) {
            $channels = [];
            $index = 0;
            for ($i = 0; $i < $count; $i++) {
                array_push($channels, $channel[$i]);
                $index++;
                if ($index == 100 || $i == $count - 1) {
                    $response = $pusher->trigger($channels, $event, $data);
                    $channels = [];
                    $index = 0;
                }
            }
            return $response;
        } else {
            $response = $pusher->trigger($channel, $event, $data);
            if ($response && $response !== true && !($response instanceof stdClass && empty((array) $response))) {
                sb_debug('Pusher error [channel: ' . $channel . ']:');
                sb_debug($response);
            }
            return $response;
        }
    }
    return sb_error('pusher-security-error', 'sb_pusher_trigger');
}

function sb_pusher_get_online_users() {
    global $SB_PUSHER_ONLINE_USERS;
    if ($SB_PUSHER_ONLINE_USERS) {
        return $SB_PUSHER_ONLINE_USERS;
    }
    $online_users_db = sb_isset(sb_db_get('SELECT value FROM sb_settings WHERE name = "pusher-online-users"'), 'value');
    if ($online_users_db) {
        $online_users_db = json_decode($online_users_db);
        if ($online_users_db && $online_users_db[1] > (time() - 10)) {
            $SB_PUSHER_ONLINE_USERS = $online_users_db[0];
            return $online_users_db[0];
        }
    }
    $index = 1;
    $pusher = sb_pusher_init();
    $continue = true;
    $users = [];
    $account_id = sb_is_cloud() ? '-' . sb_cloud_account()['user_id'] : '';
    while ($continue) {
        $channel = $pusher->get_users_info('presence-' . $index . $account_id);
        if (!empty($channel)) {
            $channel = $channel->users;
            $users = array_merge($users, $channel);
            if (count($channel) > 98) {
                $continue = true;
                $index++;
            } else
                $continue = false;
        } else
            $continue = false;
    }
    sb_save_external_setting('pusher-online-users', [$users, time()]);
    $SB_PUSHER_ONLINE_USERS = $users;
    return $users;
}

function sb_pusher_agents_online() {
    $agents_id = sb_get_agents_ids();
    $users = sb_pusher_get_online_users();
    for ($i = 0; $i < count($users); $i++) {
        if (in_array($users[$i]->id, $agents_id)) {
            return true;
        }
    }
    return false;
}

function sb_pusher_active() {
    return sb_is_cloud() || sb_get_multi_setting('pusher', 'pusher-active');
}

function sb_pusher_init() {
    require_once SB_PATH . '/vendor/pusher/autoload.php';
    $pusher_details = sb_pusher_get_details();
    return new Pusher\Pusher($pusher_details[0], $pusher_details[1], $pusher_details[2], ['cluster' => $pusher_details[3]]);
}

function sb_pusher_get_details() {
    if (sb_is_cloud()) {
        $account_id = sb_cloud_account_id();
        if (!$account_id) {
            $account_id = sb_isset($_POST, 'cloud_user_id');
        }
        return $account_id && defined('CLOUD_PUSHER_' . $account_id) ? constant('CLOUD_PUSHER_' . $account_id) : [CLOUD_PUSHER_KEY, CLOUD_PUSHER_SECRET, CLOUD_PUSHER_ID, CLOUD_PUSHER_CLUSTER];
    }
    $settings = sb_get_setting('pusher');
    return [$settings['pusher-key'], $settings['pusher-secret'], $settings['pusher-id'], $settings['pusher-cluster']];
}

function sb_pusher_curl($url_part, $post_fields = '') {
    $instance_ID = sb_get_multi_setting('push-notifications', 'push-notifications-id');
    return sb_curl('https://' . $instance_ID . '.pushnotifications.pusher.com/publish_api/v1/instances/' . $instance_ID . '/' . $url_part, is_string($post_fields) ? $post_fields : json_encode($post_fields, JSON_INVALID_UTF8_IGNORE), ['Content-Type: application/json', 'Authorization: Bearer ' . sb_get_multi_setting('push-notifications', 'push-notifications-key')]);
}

function sb_onesignal_curl($url_part, $post_fields = []) {
    $post_fields['app_id'] = sb_is_cloud() ? ONESIGNAL_APP_ID : sb_get_multi_setting('push-notifications', 'push-notifications-onesignal-app-id');
    return sb_curl('https://onesignal.com/api/v1/' . $url_part, json_encode($post_fields, JSON_INVALID_UTF8_IGNORE), ['Authorization: basic ' . (sb_is_cloud() ? ONESIGNAL_API_KEY : trim(sb_get_multi_setting('push-notifications', 'push-notifications-onesignal-api-key'))), 'Content-Type: application/json']);
}

/*
 * -----------------------------------------------------------
 * MISCELLANEOUS
 * -----------------------------------------------------------
 *
 * 1. Check if a value and key of an array exists and is not empty and return it
 * 2. Check if a number and key of an array exists and is not empty and return it
 * 3. Check if a constant exists
 * 4. Encrypt a string or decrypt an encrypted string
 * 5. Convert a string to a slug or a slug to a string
 * 6. Send a curl request
 * 7. Return the content of a URL as a string
 * 8. Return the content of a URL as a string via GET
 * 9. Create a CSV file from an array
 * 10. Read a CSV and return the array
 * 11. Create a new file containing the given content and save it in the destination path.
 * 12. Delete a file
 * 13. Debug function
 * 14. Convert a JSON string to an array
 * 15. Get max server file size
 * 16. Delete visitors older than 24h, messages in trash older than 30 days. Archive conversation older than 24h with status code equal to 4 (pending user reply).
 * 17. Chat editor
 * 18. Return the position of the least occurence on left searching from right to left
 * 19. Verification cookie
 * 20. On Support Board close
 * 21. Check if the chatbot is active
 * 22. Logs
 * 23. Webhook
 * 24. Add a cron job
 * 25. Run cron jobs
 * 26. Sanatize string
 * 27. Amazon S3
 * 28. Return the current unix UTC time
 * 29. Convert a GMT date to local time and date
 * 30. Support Board error reporting
 * 31. Return an array from a JSON string of the resources folder
 * 32. Return the file name without the initial random number
 * 33. Round date time to closest hour or half hour
 * 34. Return the timezone from the UTC offset setting
 * 35. Return the UTC offset from the timezone setting
 * 36. Run async process
 * 37. Check if it is the admin area
 *
 */

function sb_isset($array, $keys, $default = false) {
    if (sb_is_error($array) || sb_is_validation_error($array)) {
        return $array;
    }
    if (!is_array($keys)) {
        $keys = [$keys];
    }
    foreach ($keys as $key) {
        if (!is_array($array) || !array_key_exists($key, $array)) {
            return $default;
        }
        $array = $array[$key];
    }
    return empty($array) ? $default : $array;
}

function sb_isset_num($value) {
    return $value != -1 && $value && !is_null($value) && !is_bool($value) && is_numeric($value);
}

function sb_defined($name, $default = false) {
    return defined($name) ? constant($name) : $default;
}

function sb_encryption($string, $encrypt = true) {
    $output = false;
    $encrypt_method = 'AES-256-CBC';
    if (defined('SB_WP')) {
        sb_load_wp_auth_key();
    }
    $key = hash('sha256', defined('SB_CLOUD_KEY') && !empty(SB_CLOUD_KEY) ? SB_CLOUD_KEY : (defined('SB_DB_PASSWORD') && !empty(SB_DB_PASSWORD) ? SB_DB_PASSWORD : sb_defined('AUTH_KEY', 'supportboard')));
    $iv = substr(hash('sha256', 'supportboard_iv'), 0, 16);
    if ($encrypt) {
        $output = openssl_encrypt($string, $encrypt_method, $key, 0, $iv);
        $output = base64_encode($output);
        if (substr($output, -1) == '=') {
            $output = substr($output, 0, -1);
        }
    } else {
        $output = openssl_decrypt(base64_decode($string), $encrypt_method, $key, 0, $iv);
        if ($output === false) {
            $output = openssl_decrypt(base64_decode($string), $encrypt_method, hash('sha256', 'supportboard'), 0, $iv);
        }
    }
    return $output;
}

function sb_string_slug($string, $action = 'slug', $is_alphanumeric = false) {
    $string = trim($string);
    if ($action == 'slug') {
        $string = mb_strtolower(str_replace([' ', ' '], '-', $string), 'UTF-8');
        $string = preg_replace('/[^\p{L}\p{N}._\-]/u', '', sb_sanatize_string($string, true));
        if ($is_alphanumeric) {
            $string_ = preg_replace('/[^a-z0-9\-]/', '', $string);
            $string = empty($string_) ? 'slug-' . strlen($string) : $string_;
        }
        $string = str_replace('---', '-', $string);
    } else if ($action == 'string') {
        return ucfirst(strtolower(str_replace(['-', '_'], ' ', $string)));
    }
    return $string;
}

function sb_curl($url, $post_fields = '', $header = [], $method = 'POST', $timeout = false, $include_headers = false) {
    $ch = curl_init($url);
    $headers = [];
    $post_value = $post_fields ? (is_string($post_fields) ? $post_fields : (in_array('Content-Type: multipart/form-data', $header) ? $post_fields : http_build_query($post_fields))) : false;
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/114.0.0.0 Safari/537.36');
    switch ($method) {
        case 'DELETE':
        case 'PUT':
        case 'PATCH':
        case 'POST':
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
            curl_setopt($ch, CURLOPT_TIMEOUT, $timeout ? $timeout : 10);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post_value);
            if ($method != 'POST') {
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            }
            break;
        case 'GET-SC':
        case 'GET':
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
            curl_setopt($ch, CURLOPT_TIMEOUT, $timeout ? $timeout : 70);
            curl_setopt($ch, CURLOPT_HEADER, false);
            if ($post_fields) {
                $header_ = [];
                if (is_array($post_fields)) {
                    foreach ($post_fields as $key => $value) {
                        if (empty($value)) {
                            continue;
                        }
                        if (is_array($value)) {
                            $value = json_encode($value, JSON_UNESCAPED_SLASHES);
                        } elseif (is_bool($value)) {
                            $value = $value ? 'true' : 'false';
                        }
                        array_push($header_, '"' . $key . ': ' . $value . '"');
                    }
                } else {
                    $header_ = ['sb: ' . $post_fields];
                }
                $header = array_merge($header, $header_);
            }
            if ($method == 'GET-SC') {
                curl_setopt($ch, CURLOPT_ENCODING, 'gzip,deflate');
            }
            break;
        case 'DOWNLOAD':
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
            curl_setopt($ch, CURLOPT_TIMEOUT, $timeout ? $timeout : 70);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            break;
        case 'FILE':
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
            curl_setopt($ch, CURLOPT_TIMEOUT, $timeout ? $timeout : 400);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            $path = sb_upload_path(false, true);
            if (!file_exists($path)) {
                mkdir($path, 0755, true);
            }
            if (strpos($url, '?')) {
                $url = substr($url, 0, strpos($url, '?'));
            }
            $basename = sb_sanatize_file_name(basename($url));
            $extension = pathinfo($basename, PATHINFO_EXTENSION);
            if ($extension && !sb_is_allowed_extension($extension)) {
                return 'extension-not-allowed';
            }
            while (file_exists($path . '/' . $basename)) {
                $basename = rand(100, 1000000) . $basename;
            }
            $file = fopen($path . '/' . $basename, 'wb');
            curl_setopt($ch, CURLOPT_FILE, $file);
            break;
        case 'UPLOAD':
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 300);
            curl_setopt($ch, CURLOPT_TIMEOUT, 400);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post_fields);
            $header = array_merge($header, ['Content-Type: multipart/form-data']);
            break;
    }
    if (!empty($header)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
    }
    if ($include_headers) {
        curl_setopt($ch, CURLOPT_HEADER, true);
    }
    $response = curl_exec($ch);
    $status_code = $method == 'GET-SC' ? curl_getinfo($ch, CURLINFO_HTTP_CODE) : false;
    if (curl_errno($ch) > 0) {
        $error = curl_error($ch);
        curl_close($ch);
        return $error;
    }
    if ($include_headers) {
        $size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $headers_ = substr($response, 0, $size);
        $response = substr($response, $size);
        foreach (explode("\r\n", $headers_) as $header) {
            if (strpos($header, ':')) {
                list($key, $value) = explode(':', $header, 2);
                $headers[trim($key)] = trim($value);
            }
        }
    }
    curl_close($ch);
    if ($include_headers) {
        return [json_decode($response, true), $headers];
    }
    switch ($method) {
        case 'UPLOAD':
        case 'PATCH':
        case 'POST':
            $response_ = json_decode($response, true);
            return JSON_ERROR_NONE !== json_last_error() ? $response : $response_;
        case 'FILE':
            return sb_upload_path(true, true) . '/' . $basename;
        case 'GET-SC':
            return [$response, $status_code];
    }
    return $response;
}

function sb_download($url) {
    return sb_curl($url, '', '', 'DOWNLOAD');
}

function sb_download_file($url, $file_name = false, $mime = false, $header = [], $recursion = 0, $return_path = false) {
    if (empty($url)) {
        return false;
    }
    $url = sb_curl($url, '', $header, 'FILE');
    $path_2 = false;
    $extension = pathinfo(basename($file_name ? $file_name : $url), PATHINFO_EXTENSION);
    if (!$extension && $file_name) {
        $extension = pathinfo(basename($url), PATHINFO_EXTENSION);
        $file_name .= '.' . $extension;
    }
    if ($extension && !sb_is_allowed_extension($extension)) {
        return 'extension-not-allowed';
    }
    if ($file_name && !sb_is_error($url) && !empty($url)) {
        $date = date('d-m-y');
        $path = sb_upload_path() . '/' . $date;
        if ($mime) {
            $mime_types = [['image/gif', 'gif'], ['image/jpeg', 'jpg'], ['video/quicktime', 'mov'], ['video/mpeg', 'mp3'], ['application/pdf', 'pdf'], ['image/png', 'png'], ['image/x-png', 'png'], ['application/rtf', 'rtf'], ['text/plain', 'txt'], ['x-zip-compressed', 'zip'], ['video/mp4', 'mp4'], ['audio/mp4', 'mp4']];
            $mime = $mime === true ? mime_content_type($path . '/' . basename($url)) : $mime;
            for ($i = 0; $i < count($mime_types); $i++) {
                if ($mime == $mime_types[$i][0] && substr_compare($file_name, '.' . $mime_types[$i][1], -strlen('.' . $mime_types[$i][1])) !== 0) {
                    $file_name .= '.' . $mime_types[$i][1];
                    break;
                }
            }
        }
        $file_name = sb_string_slug($file_name);
        $path_2 = $path . '/' . $file_name;
        rename($path . '/' . basename($url), $path_2);
        if ((!file_exists($path_2) || !filesize($path_2)) && $recursion < 3) {
            return sb_download_file($url, $file_name, $mime, $header, $recursion + 1);
        }
        if (!file_exists($path_2)) {
            return false;
        }
        if (!filesize($path_2)) {
            unlink($path_2);
            return false;
        }
        $url = sb_upload_path(true) . '/' . $date . '/' . $file_name;
        if (!$return_path && (sb_get_multi_setting('amazon-s3', 'amazon-s3-active') || defined('SB_CLOUD_AWS_S3'))) {
            $url_aws = sb_aws_s3($path_2);
            if (strpos($url_aws, 'http') === 0) {
                $url = $url_aws;
                unlink($path_2);
            }
        }
    }
    return $return_path ? $path_2 : $url;
}

function sb_is_allowed_extension($extension) {
    $extension = strtolower($extension);
    $allowed_extensions = ['step', 'stl', 'obj', 'rtf', '3mf', 'bmp', 'aac', 'webm', 'oga', 'json', 'psd', 'ai', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'doc', 'docx', 'key', 'ppt', 'odt', 'xls', 'xlsx', 'zip', 'rar', 'mp3', 'm4a', 'ogg', 'wav', 'mp4', 'mov', 'wmv', 'avi', 'mpg', 'ogv', '3gp', '3g2', 'mkv', 'txt', 'ico', 'csv', 'ttf', 'font', 'css', 'scss'];
    return in_array($extension, $allowed_extensions) || (defined('SB_FILE_EXTENSIONS') && in_array($extension, SB_FILE_EXTENSIONS));
}

function sb_get($url, $is_json = false) {
    $response = sb_curl($url, '', '', 'GET');
    return $is_json ? json_decode($response, true) : $response;
}

function sb_csv($items, $header, $filename, $return_url = true) {
    $filename = rand(100000000, 99999999999) . '-' . $filename . '.csv';
    $file = fopen(sb_upload_path() . '/' . $filename, 'w');
    fprintf($file, chr(0xEF) . chr(0xBB) . chr(0xBF));
    if ($header) {
        fputcsv($file, $header);
    }
    for ($i = 0; $i < count($items); $i++) {
        fputcsv($file, $items[$i]);
    }
    fclose($file);
    return sb_upload_path($return_url) . '/' . $filename;
}

function sb_csv_read($path) {
    $rows = [];
    if (($handle = fopen($path, 'r')) !== false) {
        $index = 0;
        while (($data = fgetcsv($handle, 0, ',')) !== false) {
            $rows[$index] = $data;
            $index++;
        }
        fclose($handle);
    }
    return $rows;
}

function sb_file($path, $content) {
    try {
        $file = fopen($path, 'w');
        fwrite($file, (substr($path, -4) == '.txt' ? "\xEF\xBB\xBF" : '') . $content);
        fclose($file);
        return true;
    } catch (Exception $e) {
        return $e->getMessage();
    }
}

function sb_file_delete($url_or_path) {
    $aws = (defined('SB_CLOUD_AWS_S3') || sb_get_multi_setting('amazon-s3', 'amazon-s3-active')) && (strpos($url_or_path, '.s3.') || strpos($url_or_path, 'amazonaws.com'));
    if ($aws) {
        return sb_aws_s3($url_or_path, 'DELETE');
    } else {
        $path = strpos($url_or_path, 'http') === 0 ? sb_upload_path() . str_replace(sb_upload_path(true), '', $url_or_path) : $url_or_path;
        if (sb_is_valid_path($path)) {
            return unlink($path);
        }
    }
    return false;
}

function sb_debug($value) {
    $value = is_string($value) ? $value : json_encode($value, JSON_INVALID_UTF8_IGNORE | JSON_UNESCAPED_UNICODE);
    $path = __DIR__ . '/debug.txt';
    if (file_exists($path)) {
        $value = file_get_contents($path) . PHP_EOL . PHP_EOL . $value;
    }
    sb_file($path, $value);
}

function sb_json_array($json, $default = []) {
    if (is_string($json)) {
        $json = json_decode($json, true);
        return $json === false || $json === null ? $default : $json;
    } else if (is_bool($json)) {
        return [];
    } else {
        return $json;
    }
}

function sb_get_server_max_file_size() {
    $size = ini_get('post_max_size');
    if (empty($size)) {
        return 9999;
    }
    $suffix = strtoupper(substr($size, -1));
    $size = substr($size, 0, -1);
    if ($size === 0) {
        return 9999;
    }
    switch ($suffix) {
        case 'G':
            $size *= 1024;
            break;
        case 'K':
            $size /= 1024;
            break;
    }
    return $size;
}

function sb_clean_data() {
    $time_24h = sb_gmt_now(86400);
    $time_30d = sb_gmt_now(2592000);
    try {
        $ids = sb_db_get('SELECT id FROM sb_conversations WHERE status_code = 4 AND creation_time < "' . $time_30d . '"', false);
        sb_db_query('DELETE FROM sb_users WHERE user_type = "visitor" AND creation_time < "' . $time_24h . '"');
        for ($i = 0; $i < count($ids); $i++) {
            try {
                sb_delete_attachments($ids[$i]['id']);
            } catch (Exception $exception) {
            }
        }
    } catch (Exception $exception) {
    }
    try {
        sb_db_query('DELETE FROM sb_conversations WHERE status_code = 4 AND creation_time < "' . $time_30d . '"');
        if (sb_get_setting('admin-auto-archive')) {
            try {
                sb_db_query('UPDATE sb_conversations SET status_code = 3 WHERE (status_code = 1 OR status_code = 0) AND id IN (SELECT conversation_id FROM sb_messages WHERE id IN (SELECT max(id) FROM sb_messages GROUP BY conversation_id) AND creation_time < "' . $time_24h . '")');
            } catch (Exception $exception) {
            }
        }
    } catch (Exception $exception) {
    }
    try {
        $interval = sb_get_multi_setting('performance', 'performance-messages');
        if ($interval) {
            $seconds = ['1w' => 604800, '2w' => 1209600, '3w' => 1814400, '1m' => 2592000, '2m' => 5184000, '3m' => 7776000, '6m' => 15552000, '1y' => 31536000];
            sb_db_query('DROP TEMPORARY TABLE IF EXISTS sb_latest_messages');
            sb_db_query('CREATE TEMPORARY TABLE sb_latest_messages (id BIGINT PRIMARY KEY) ENGINE=MEMORY AS SELECT MAX(id) AS id FROM sb_messages GROUP BY conversation_id');
            $conversation_ids = array_column(sb_db_get('SELECT m.conversation_id FROM sb_latest_messages l, sb_messages m WHERE l.id = m.id AND m.creation_time < "' . sb_gmt_now($seconds[$interval]) . '"', false), 'conversation_id');
            foreach ($conversation_ids as $conversation_id) {
                try {
                    sb_messages_archiviation($conversation_id);
                } catch (Exception $exception) {
                }
            }
        }
    } catch (Exception $exception) {
    }
    return true;
}

function sb_component_editor($admin = false) {
    $enabled = [$admin || !sb_get_setting('disable-uploads'), !sb_get_setting('disable-voice-messages')];
    ?>
    <div class="sb-editor<?php echo !$enabled[0] || !$enabled[1] ? ' sb-disabled-' . (!$enabled[0] && !$enabled[1] ? '2' : '1') : '' ?>">
        <?php
        if ($admin) {
            echo '<div class="sb-labels"></div>';
        }
        ?>
        <div id="sb-call-bar">
            <i class="sb-icon-phone"></i>
            <span><span><?php sb_e('Incoming voice call') ?></span><span id="call-timer">0:00</span></span>
            <div>
                <div id="sb-call-mute" class="sb-btn-icon sb-btn-red"><i class="sb-icon-microphone-mute"></i></div>
                <div id="sb-call-decline" class="sb-btn sb-icon"><i class="sb-icon-phone-call-2"></i><?php sb_e('Decline') ?></div>
                <div id="sb-call-answer" class="sb-btn sb-icon"><i class="sb-icon-phone-call-1"></i><?php sb_e('Answer') ?></div>
            </div>
        </div>
        <div class="sb-textarea">
            <textarea placeholder="<?php sb_e('Write a message...') ?>"></textarea>
        </div>
        <div class="sb-attachments"></div>
        <?php
        $code = ($admin ? '<div class="sb-suggestions"></div>' : '') . '<div class="sb-bar"><div class="sb-bar-icons">';
        if ($enabled[0]) {
            $code .= '<div class="sb-btn-attachment" data-sb-tooltip="' . sb_('Attach a file') . '"></div>';
        }
        $code .= '<div class="sb-btn-saved-replies" data-sb-tooltip="' . sb_('Add a saved reply') . '"></div>';
        if ($enabled[1]) {
            $code .= '<div class="sb-btn-audio-clip" data-sb-tooltip="' . sb_('Voice message') . '"></div>';
        }
        $code .= '<div class="sb-btn-emoji" data-sb-tooltip="' . sb_('Add an emoji') . '"></div>';
        if ($admin && defined('SB_DIALOGFLOW') && sb_get_multi_setting('open-ai-rewrite', 'open-ai-rewrite-active') && sb_get_multi_setting('open-ai-rewrite', 'open-ai-rewrite-execution-mode') != 'auto') {
            $code .= '<div class="sb-btn-open-ai sb-icon-openai sb-btn-open-ai-editor" data-sb-tooltip="' . sb_('Rewrite') . '"></div>';
        }
        if ($admin && defined('SB_WOOCOMMERCE')) {
            $code .= '<div class="sb-btn-woocommerce" data-sb-tooltip="' . sb_('Add a product') . '"></div>';
        }
        echo $code . '</div><div class="sb-icon-send sb-submit" data-sb-tooltip="' . sb_('Send message') . '"></div>' . ($admin ? '<div class="sb-icon-close sb-clear-text"></div>' : '') . '<i class="sb-loader"></i></div>';
        ?>
        <div class="sb-popup sb-emoji">
            <div class="sb-header">
                <div class="sb-select">
                    <p>
                        <?php sb_e('All') ?>
                    </p>
                    <ul>
                        <li data-value="all" class="sb-active">
                            <?php sb_e('All') ?>
                        </li>
                        <li data-value="Smileys">
                            <?php sb_e('Smileys & Emotions') ?>
                        </li>
                        <li data-value="People">
                            <?php sb_e('People & Body') ?>
                        </li>
                        <li data-value="Animals">
                            <?php sb_e('Animals & Nature') ?>
                        </li>
                        <li data-value="Food">
                            <?php sb_e('Food & Drink') ?>
                        </li>
                        <li data-value="Travel">
                            <?php sb_e('Travel & Places') ?>
                        </li>
                        <li data-value="Activities">
                            <?php sb_e('Activities') ?>
                        </li>
                        <li data-value="Objects">
                            <?php sb_e('Objects') ?>
                        </li>
                        <li data-value="Symbols">
                            <?php sb_e('Symbols') ?>
                        </li>
                    </ul>
                </div>
                <div class="sb-search-btn">
                    <i class="sb-icon sb-icon-search"></i>
                    <input type="text" placeholder="<?php sb_e('Search emoji...') ?>" />
                </div>
            </div>
            <div class="sb-emoji-list">
                <ul></ul>
            </div>
            <div class="sb-emoji-bar"></div>
        </div>
        <?php if ($admin) { ?>
            <div class="sb-popup sb-replies">
                <div class="sb-header">
                    <div class="sb-title">
                        <?php sb_e('Saved replies') ?>
                    </div>
                    <div>
                        <a class="sb-add-saved-reply">
                            <i class="sb-icon-plus"></i>
                        </a>
                        <div class="sb-search-btn">
                            <i class="sb-icon sb-icon-search"></i>
                            <input type="text" autocomplete="false" placeholder="<?php sb_e(sb_get_multi_setting('open-ai', 'open-ai-active') || sb_get_setting('ai-smart-reply') ? 'Search replies or ask the chatbot' : 'Search replies') ?>" />
                        </div>
                    </div>
                </div>
                <div class="sb-replies-add-area">
                    <div class="sb-setting sb-type-text">
                        <input type="text" placeholder="<?php sb_e('Name') ?>" />
                    </div>
                    <div class="sb-setting sb-type-text">
                        <input type="text" placeholder="<?php sb_e('Text') ?>" />
                    </div>
                    <a class="sb-btn"><?php sb_e('Save changes') ?></a>
                </div>
                <div class="sb-replies-list sb-scroll-area">
                    <ul class="sb-loading"></ul>
                </div>
            </div>
            <?php
            if (defined('SB_WOOCOMMERCE')) {
                sb_woocommerce_products_popup();
            }
        } ?>
        <form class="sb-upload-form-editor" action="#" method="post" enctype="multipart/form-data">
            <input type="file" name="files[]" class="sb-upload-files" multiple />
        </form>
        <div id="sb-audio-clip">
            <div class="sb-icon sb-icon-close"></div>
            <div class="sb-audio-clip-cnt">
                <div class="sb-audio-clip-time"></div>
                <div class="sb-icon sb-icon-play sb-btn-clip-player"></div>
            </div>
            <div class="sb-icon sb-icon-pause sb-btn-mic"></div>
        </div>
    </div>
    <?php
    if (!$admin) {
        echo '<div class="sb-overlay-panel"><div><div></div><i class="sb-btn-icon sb-icon-close"></i></div><div></div></div>';
    }
}

function sb_strpos_reverse($string, $search, $offset) {
    return strrpos(substr($string, 0, $offset), $search);
}

function sb_mb_strpos_reverse($string, $search, $offset) {
    $index = mb_strrpos(mb_substr($string, 0, $offset), $search);
    return $index ? $index : $offset;
}

function sb_verification_cookie($code, $domain, $user = false) {
    $is_installation = empty(sb_db_get('SHOW TABLES LIKE "sb_settings"'));
    if ($code == 'auto' && !$is_installation) {
        $code = sb_get_setting('en' . 'vato-purc' . 'hase-code');
    }
    if (empty($code) || $code == 'auto') {
        return [false, ''];
    }
    $response = sb_get('https://bo' . 'ard.supp' . 'ort/syn' . 'ch/verifi' . 'cation.php' . '?ve' . 'rification&code=' . $code . '&domain=' . $domain);
    if ($response == 'verifi' . 'cation-success') {
        if ($is_installation) {
            $response = sb_installation_db(SB_DB_HOST, SB_DB_USER, SB_DB_PASSWORD, SB_DB_NAME, SB_DB_PORT, $code, $domain, $user);
            if (!empty($response['error'])) {
                return [false, $response['error']];
            }
        }
        return [true, password_hash('VGCKME' . 'NS', PASSWORD_DEFAULT)];
    }
    return [false, sb_string_slug($response, 'string')];
}

function sb_on_close() {
    sb_set_agent_active_conversation(0);
}

function sb_is_chatbot_active($source = false, $conversation_id = false) {
    if (defined('SB_DIALOGFLOW')) {
        if ($source && sb_get_setting(($source == 'ig' ? 'fb' : $source) . '-disable-chatbot')) {
            return false;
        }
        if (($source == 'ig' || $source == 'fb') && sb_get_setting('fb-disable-chatbot-comments') && !empty(sb_db_get('SELECT extra_3 FROM sb_conversations WHERE id = ' . sb_db_escape($conversation_id, true)))) {
            return false;
        }
        return sb_get_multi_setting('open-ai', 'open-ai-active');
    }
    return false;
}

function sb_logs($string, $user = false) {
    if (sb_is_cloud()) {
        return false;
    }
    $string = date('d-m-Y H:i:s') . ' Agent ' . sb_get_user_name($user) . ' #' . ($user ? $user['id'] : sb_get_active_user_ID()) . ' ' . $string;
    $path = SB_PATH . '/log.txt';
    if (file_exists($path)) {
        $string = file_get_contents($path) . PHP_EOL . $string;
    }
    return sb_file($path, $string);
}

function sb_webhooks($function_name, $parameters) {
    $names = ['SBSMSSent' => 'sms-sent', 'SBLoginForm' => 'login', 'SBRegistrationForm' => 'registration', 'SBUserDeleted' => 'user-deleted', 'SBMessageSent' => 'message-sent', 'SBBotMessage' => 'bot-message', 'SBEmailSent' => 'email-sent', 'SBNewMessagesReceived' => 'new-messages', 'SBNewConversationReceived' => 'new-conversation', 'SBNewConversationCreated' => 'new-conversation-created', 'SBActiveConversationStatusUpdated' => 'conversation-status-updated', 'SBSlackMessageSent' => 'slack-message-sent', 'SBMessageDeleted' => 'message-deleted', 'SBRichMessageSubmit' => 'rich-message', 'SBNewEmailAddress' => 'new-email-address'];
    $webhook_name = sb_isset($names, $function_name);
    if ($webhook_name) {
        $webhooks = sb_get_setting('webhooks');
        $webhook_url = sb_isset($webhooks, 'webhooks-url');
        if ($webhooks && $webhook_url && $webhooks['webhooks-active']) {
            $allowed_webhooks = $webhooks['webhooks-allowed'];
            if ($allowed_webhooks && $allowed_webhooks !== true) {
                $allowed_webhooks = explode(',', str_replace(' ', '', $allowed_webhooks));
                if (!in_array($webhook_name, $allowed_webhooks)) {
                    return false;
                }
            }
            $query = json_encode(['function' => $webhook_name, 'key' => $webhooks['webhooks-key'], 'data' => $parameters, 'sender-url' => (isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '')], JSON_INVALID_UTF8_IGNORE | JSON_UNESCAPED_UNICODE);
            if ($query) {
                if (sb_is_cloud() && $webhook_url == 'zapier') {
                    $webhook_url = sb_isset(sb_get_external_setting('zapier', []), $function_name);
                    if (!$webhook_url) {
                        return false;
                    }
                }
                return sb_curl($webhook_url, $query, ['Content-Type: application/json', 'Content-Length: ' . strlen($query)]);
            } else {
                return sb_error('webhook-json-error', 'sb_webhooks', $function_name);
            }
        } else {
            return new SBValidationError('webhook-not-active-or-empty-url');
        }
    } else {
        return new SBValidationError('webhook-not-found');
    }
}

function sb_cron_jobs_add($key, $content = false, $job_time = false) {

    // Add the job to the cron jobs
    $cron_functions = sb_get_external_setting('cron-functions');
    if (empty($cron_functions) || empty($cron_functions['value'])) {
        sb_save_external_setting('cron-functions', [$key]);
    } else {
        $cron_functions = json_decode($cron_functions['value'], true);
        if (!in_array($key, $cron_functions)) {
            array_push($cron_functions, $key);
            sb_db_query('UPDATE sb_settings SET value = \'' . sb_db_json_escape($cron_functions) . '\' WHERE name = "cron-functions"');
        }
    }

    // Set the cron job data
    if (!empty($content) && !empty($job_time)) {
        $user = sb_get_active_user();
        if ($user) {
            $key = 'cron-' . $key;
            $scheduled = sb_get_external_setting($key);
            if (empty($scheduled)) {
                $scheduled = [];
            }
            $scheduled[$user['id']] = [$content, strtotime('+' . $job_time)];
            sb_save_external_setting($key, $scheduled);
        }
    }
}

function sb_cron_jobs() {
    ignore_user_abort(true);
    set_time_limit(180);
    $now = date('H');
    $cron_functions = sb_get_external_setting('cron-functions');
    if (defined('SB_WOOCOMMERCE')) {
        sb_woocommerce_cron_jobs($cron_functions);
    }
    if (defined('SB_AECOMMERCE')) {
        sb_aecommerce_clean_carts();
    }
    if (sb_is_chatbot_active() && sb_get_multi_setting('open-ai', 'open-ai-user-train-conversations')) {
        sb_open_ai_conversations_training();
    }
    if (sb_get_multi_setting('rating-message', 'rating-active') && sb_get_multi_setting('rating-message', 'rating-email')) {
        sb_rating_email();
    }
    sb_clean_data();
    sb_delete_external_setting('cron-functions');
    sb_save_external_setting('cron', $now);
}

function sb_sanatize_string($string, $is_secure = false) {
    do {
        $previous = $string;
        $string = str_ireplace(['onload', 'javascript:', 'onclick', 'onerror', 'onmouseover', 'oncontextmenu', 'ondblclick', 'onmousedown', 'onmouseenter', 'onmouseleave', 'onmousemove', 'onmouseout', 'onmouseup', 'ontoggle'], '', $string);
    } while ($string !== $previous);
    while (str_contains($string, '<script')) {
        $string = str_ireplace('<script', '&lt;script', $string);
    }
    while (str_contains($string, '</script')) {
        $string = str_ireplace('</script', '&lt;/script', $string);
    }
    return $is_secure ? htmlspecialchars($string, ENT_NOQUOTES | ENT_SUBSTITUTE | ENT_HTML401, 'UTF-8') : $string;
}

function sb_sanatize_file_name($file_name) {
    do {
        $previous = $file_name;
        $file_name = str_ireplace(['../', '\\', '/', ':', '?', '"', '*', '<', '>', '|', '..\/'], '', $file_name);
    } while ($file_name !== $previous);
    return sb_sanatize_string($file_name, true);
}

function sb_aws_s3($file_path, $action = 'PUT', $bucket_name = false) {
    $settings = sb_get_setting('amazon-s3');
    if ((!$settings || empty($settings['amazon-s3-bucket-name'])) && defined('SB_CLOUD_AWS_S3')) {
        $settings = SB_CLOUD_AWS_S3;
    }
    if ($settings) {
        if (!$bucket_name) {
            $bucket_name = $settings['amazon-s3-bucket-name'];
        }
        $recursion = 0;
        $put = $action == 'PUT';
        $host_name = $bucket_name . '.s3.amazonaws.com';
        $file = '';
        $timeout = false;
        if ($put) {
            $file_size = strlen($file);
            while ((!$file_size || $file_size < filesize($file_path)) && $recursion < 10) {
                $file = file_get_contents($file_path);
                $file_size = strlen($file);
                if ($recursion) {
                    sleep(1);
                }
                $recursion++;
            }
            $timeout = intval(filesize($file_path) / 1000000);
            $timeout = $timeout < 7 ? 7 : ($timeout > 600 ? 600 : $timeout);
        }
        $file_name = basename($file_path);
        $timestamp = gmdate('Ymd\THis\Z');
        $date = gmdate('Ymd');
        $request_headers = ['Content-Type' => $put ? mime_content_type($file_path) : '', 'Date' => $timestamp, 'Host' => $bucket_name . '.s3.amazonaws.com', 'x-amz-acl' => 'public-read', 'x-amz-content-sha256' => hash('sha256', $file)];
        ksort($request_headers);
        $canonical_headers = [];
        $signed_headers = [];
        foreach ($request_headers as $key => $value) {
            $canonical_headers[] = strtolower($key) . ':' . $value;
        }
        foreach ($request_headers as $key => $value) {
            $signed_headers[] = strtolower($key);
        }
        $canonical_headers = implode("\n", $canonical_headers);
        $signed_headers = implode(';', $signed_headers);
        $hashed_canonical_request = hash('sha256', implode("\n", [$action, '/' . $file_name, '', $canonical_headers, '', $signed_headers, hash('sha256', $file)]));
        $scope = [$date, $settings['amazon-s3-region'], 's3', 'aws4_request'];
        $string_to_sign = implode("\n", ['AWS4-HMAC-SHA256', $timestamp, implode('/', $scope), $hashed_canonical_request]);
        $kSecret = 'AWS4' . $settings['amazon-s3-secret-access-key'];
        $kDate = hash_hmac('sha256', $date, $kSecret, true);
        $kRegion = hash_hmac('sha256', $settings['amazon-s3-region'], $kDate, true);
        $kService = hash_hmac('sha256', 's3', $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        $authorization = 'AWS4-HMAC-SHA256' . ' ' . implode(',', ['Credential=' . $settings['amazon-s3-access-key'] . '/' . implode('/', $scope), 'SignedHeaders=' . $signed_headers, 'Signature=' . hash_hmac('sha256', $string_to_sign, $kSigning)]);
        $curl_headers = ['Authorization: ' . $authorization];
        foreach ($request_headers as $key => $value) {
            $curl_headers[] = $key . ": " . $value;
        }
        $url = 'https://' . $host_name . '/' . $file_name;
        $response = sb_curl($url, $file, $curl_headers, $action, $timeout);
        return $response ? $response : $url;
    }
    return false;
}

function sb_error($error_code, $function_name, $message = '', $force = false) {
    $message_2 = (sb_is_cloud() ? SB_CLOUD_BRAND_NAME : 'Support Board ') . '[' . $function_name . '][' . $error_code . ']' . ($message ? ': ' . (is_string($message) ? $message : json_encode($message)) : '');
    if (($force && !sb_is_cloud()) || sb_is_debug()) {
        sb_debug($message_2);
        trigger_error($message_2);
        die($message_2);
    }
    return new SBError($error_code, $function_name, $message_2, $message);
}

function sb_is_debug() {
    return isset($_GET['debug']) || sb_isset($_POST, 'debug') || strpos(sb_isset($_SERVER, 'HTTP_REFERER'), 'debug');
}

function sb_get_json_resource($path_part) {
    global $SB_RESOURCES;
    if ($SB_RESOURCES && isset($SB_RESOURCES[$path_part])) {
        return $SB_RESOURCES[$path_part];
    }
    $SB_RESOURCES[$path_part] = json_decode(file_get_contents(SB_PATH . '/resources/' . $path_part), true);
    return $SB_RESOURCES[$path_part];
}

function sb_is_valid_path($path) {
    $real_path = realpath($path);
    return ((defined('SB_URL') && strpos($real_path, SB_URL) === 0) || (defined('SB_PATH') && strpos($real_path, SB_PATH) === 0) || (defined('CLOUD_URL') && strpos($real_path, CLOUD_URL) === 0) || (defined('SB_CLOUD_PATH') && strpos($real_path, SB_CLOUD_PATH) === 0)) && file_exists($path);
}

function sb_beautify_file_name($file_name) {
    $parts = explode('_', $file_name, 2);
    return isset($parts[1]) ? $parts[1] : $file_name;
}

function sb_gmt_now($less_seconds = 0, $is_unix = false) {
    $now = gmdate('Y-m-d H:i:s', time() - $less_seconds);
    return $is_unix ? strtotime($now) : $now;
}

function sb_convert_date($date_string, $date_format = 'd/m/Y H:i:s', $is_to_gmt = false) {
    global $SB_UTC_OFFSET;
    $SB_UTC_OFFSET = is_numeric($SB_UTC_OFFSET) ? $SB_UTC_OFFSET : sb_utc_offset();
    return date($date_format, strtotime($date_string) + $SB_UTC_OFFSET * ($is_to_gmt ? 1 : -1) * 3600);
}

function sb_beautify_date($date_string, $is_short = false) {
    $timezone = sb_utc_offset(true);
    if ($timezone) {
        $region = strtolower(explode('/', $timezone)[0] ?? '');
        $locale = match ($region) {
            'america' => 'en_US',
            'europe' => 'en_GB',
            'australia' => 'en_AU',
            'asia' => 'en_SG',
            default => 'en_GB'
        };
        $formatter = new IntlDateFormatter($locale, $is_short ? IntlDateFormatter::RELATIVE_MEDIUM : IntlDateFormatter::RELATIVE_LONG, IntlDateFormatter::SHORT, $timezone);
        $date_string = $formatter->format(new DateTime($date_string, new DateTimeZone($timezone)));
        return ucfirst(rtrim(trim(str_replace('00:00', '', $date_string)), ','));
    }
    return $date_string;
}

function sb_get_timezone_offset() {
    global $SB_UTC_OFFSET;
    $SB_UTC_OFFSET = is_numeric($SB_UTC_OFFSET) ? $SB_UTC_OFFSET : sb_utc_offset();
    $hoursFloat = -1 * (float) $SB_UTC_OFFSET;
    $sign = $hoursFloat >= 0 ? '+' : '-';
    $abs = abs($hoursFloat);
    $hours = str_pad((string) floor($abs), 2, '0', STR_PAD_LEFT);
    $minutes = str_pad((string) round(($abs - floor($abs)) * 60), 2, '0', STR_PAD_LEFT);
    return $sign . $hours . ':' . $minutes;
}

function sb_utc_offset($is_timezone = false) {
    $slug = 'SB_UTC_OFFSET_2' . ($is_timezone ? 'B' : '');
    if (isset($GLOBALS[$slug])) {
        return $GLOBALS[$slug];
    }
    $timezone = explode('|', sb_get_setting('timetable-utc', 0));
    $response = sb_isset($timezone, $is_timezone ? 1 : 0, $is_timezone ? '' : 0);
    if (!$is_timezone && isset($timezone[1])) {
        $timezone = new DateTimeZone($timezone[1]);
        $now = new DateTime('now', $timezone);
        $current_offset_hours = -($timezone->getOffset($now) / 3600);
        if ($current_offset_hours !== $response) {
            $difference = $current_offset_hours - $response;
            $response += $difference;
        }
    }
    $GLOBALS[$slug] = $response;
    return $response;
}

function sb_worker_run($data) {
    $data = escapeshellarg(base64_encode(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
    $command = false;
    if (defined('SB_WINDOWS') && SB_WINDOWS) {
        $command = 'start /B cmd /C "php worker.php ' . $data;
    } else if (defined('SB_PHP_PATH')) {
        $command = SB_PHP_PATH . ' ' . __DIR__ . '/worker.php ' . $data . ' >> ' . escapeshellarg(__DIR__ . '/debug_worker.txt') . ' 2>&1 &';
    }
    if (!$command) {
        return false;
    }
    $command = popen($command, 'r');
    return pclose($command);
}

function sb_is_admin_area() {
    return sb_is_agent(false, true) && empty($GLOBALS['SB_FORCE_ADMIN']);
}

/*
 * -----------------------------------------------------------
 * AUTOMATIONS
 * -----------------------------------------------------------
 *
 * 1. Get all automations
 * 2. Save all automations
 * 3. Run all valid automations and return the ones which need client-side validations
 * 4. Check if an automation is valid and can be executed
 * 5. Execute an automation
 *
 */

function sb_automations_get() {
    $types = ['messages', 'emails', 'sms', 'popups', 'design', 'more'];
    $automations = sb_get_external_setting('automations', []);
    $translations = [];
    $rows = sb_db_get('SELECT name, value FROM sb_settings WHERE name LIKE "automations-translations-%"', false);
    for ($i = 0; $i < count($rows); $i++) {
        $translations[substr($rows[$i]['name'], -2)] = json_decode($rows[$i]['value'], true);
    }
    for ($i = 0; $i < count($types); $i++) {
        if (!$automations || !isset($automations[$types[$i]]))
            $automations[$types[$i]] = [];
    }
    return [$automations, $translations];
}

function sb_automations_save($automations, $translations = false) {
    if ($translations) {
        $db = '';
        foreach ($translations as $key => $value) {
            $name = 'automations-translations-' . $key;
            sb_save_external_setting($name, $value);
            $db .= '"' . $name . '",';
        }
        sb_db_query('DELETE FROM sb_settings WHERE name LIKE "automations-translations-%" AND name NOT IN (' . substr($db, 0, -1) . ')');
    }
    return sb_save_external_setting('automations', empty($automations) ? [] : $automations);
}

function sb_automations_run_all() {
    if (sb_is_agent()) {
        return false;
    }
    $response = [];
    $automations_all = sb_automations_get();
    $user_language = sb_get_user_language();
    foreach ($automations_all[0] as $type => $automations) {
        for ($i = 0; $i < count($automations); $i++) {
            $automations[$i]['type'] = $type;
            $validation = sb_automations_validate($automations[$i]);
            if ($validation) {
                $automation_id = $automations[$i]['id'];
                $conditions = sb_isset($validation, 'conditions', []);

                // Translations
                if ($user_language && isset($automations_all[1][$user_language])) {
                    $translations = sb_isset($automations_all[1][$user_language], $type, []);
                    for ($x = 0; $x < count($translations); $x++) {
                        if ($translations[$x]['id'] == $automation_id) {
                            $automations[$i] = $translations[$x];
                            $automations[$i]['type'] = $type;
                            break;
                        }
                    }
                }
                if (!empty($validation['repeat_id'])) {
                    $automations[$i]['repeat_id'] = $validation['repeat_id'];
                }
                if (count($conditions) || $type == 'popups' || $type == 'design' || $type == 'more' || !sb_get_active_user()) {

                    // Automation with client-side conditions, server-side invalid conditions, or popup, design
                    $automations[$i]['conditions'] = $conditions;
                    array_push($response, $automations[$i]);
                } else {

                    // Run automation
                    sb_automations_run($automations[$i]);
                }
            }
        }
    }
    return $response;
}

function sb_automations_validate($automation, $is_flow = false) {
    $conditions = $is_flow ? $automation : sb_isset($automation, 'conditions', []);
    if (empty($conditions)) {
        return true;
    }
    $return_conditions = [];
    $repeat_id = false;
    $valid = false;
    $active_user = sb_get_active_user();
    $active_user_id = sb_isset($active_user, 'id');
    $custom_fields = array_column(sb_get_setting('user-additional-fields', []), 'extra-field-slug');
    for ($i = 0; $i < count($conditions); $i++) {
        $valid = false;
        $criteria = $conditions[$i][1];
        $type = $conditions[$i][0];
        $check = in_array($type, $custom_fields) || in_array($type, ['url', 'website', 'email', 'phone']);
        switch ($type) {
            case 'birthdate':
                if ($active_user) {
                    $user_value = date('d/m', strtotime(sb_get_user_extra($active_user_id, 'birthdate')));
                    if (($criteria == 'is-set' && !empty($user_value)) || ($criteria == 'is-not-set' && empty($user_value))) {
                        $valid = true;
                    } else {
                        $dates = str_replace(' ', '', $conditions[$i][2]);
                        if ($criteria == 'is-between') {
                            $dates = explode('-', $dates);
                            if (count($dates) == 2) {
                                $dates = [strtotime(str_replace('/', '-', $dates[0]) . '-1900'), strtotime(str_replace('/', '-', $dates[1]) . '-1900')];
                                $user_value = strtotime(str_replace('/', '-', $user_value) . '-1900');
                                $valid = ($user_value >= $dates[0] && $user_value <= $dates[1]);
                            }
                        } else if ($criteria == 'is-exactly') {
                            $valid = $user_value == $dates;
                        }
                    }
                } else {
                    $valid = true;
                    array_push($return_conditions, $conditions[$i]);
                }
                break;
            case 'creation_time':
            case 'datetime':
                $user_value = $type == 'datetime' ? time() : strtotime(sb_get_user(sb_get_active_user_ID())[$type]);
                $offset = floatval(sb_utc_offset()) * 3600;
                if ($criteria == 'is-between') {
                    $dates = array_map('trim', explode('-', $conditions[$i][2]));
                    if (count($dates) == 2) {
                        $unix = DateTime::createFromFormat('d/m/Y H:i', $dates[0] . (strpos($dates[0], ':') ? '' : ' 00:00'));
                        $unix_end = DateTime::createFromFormat('d/m/Y H:i', $dates[1] . (strpos($dates[1], ':') ? '' : ' 23:59'));
                        if ($unix && $unix_end) {
                            $unix = date_timestamp_get($unix) + (strpos($dates[0], ':') ? $offset : 0);
                            $unix_end = date_timestamp_get($unix_end) + (strpos($dates[1], ':') ? $offset : 0);
                            $valid = ($user_value >= $unix) && ($user_value <= $unix_end);
                            $continue = true;
                        }
                    }
                } else {
                    $is_time = strpos($conditions[$i][2], ':');
                    $unix = DateTime::createFromFormat('d/m/Y H:i', $conditions[$i][2] . ($is_time ? '' : ' 00:00'));
                    if ($unix) {
                        $unix = date_timestamp_get($unix) + $offset;
                        $valid = $user_value == $unix || (!$is_time && $user_value > $unix && $user_value < $unix + 86400);
                    }
                }
                if (!$valid) {
                    for ($j = 0; $j < count($conditions); $j++) {
                        if ($conditions[$j][0] == 'repeat') {
                            $condition = $conditions[$j][1];
                            if ($criteria == 'is-between' && $continue) {
                                $hhmm = false;
                                $hhmm_end = false;
                                if (strpos($dates[0], ':') && strpos($dates[1], ':')) {
                                    $hhmm = strtotime(date('Y-m-d ' . explode(' ', $dates[0])[1])) + $offset;
                                    $hhmm_end = strtotime(date('Y-m-d ' . explode(' ', $dates[1])[1])) + $offset;
                                }
                                if ($condition == 'every-day') {
                                    $valid = $hhmm ? ($user_value >= $hhmm) && ($user_value <= $hhmm_end) : true;
                                    $repeat_id = $valid ? date('z') : false;
                                } else {
                                    $letter = $condition == 'every-week' ? 'w' : ($condition == 'every-month' ? 'd' : 'z');
                                    $letter_value_now = date($letter);
                                    $letter_value_unix = date($letter, $unix);
                                    $letter_value_unix_end = date($letter, $unix_end);
                                    if ($letter == 'z') {
                                        $letter_value_now -= date('L');
                                        $letter_value_unix -= date('L', $unix);
                                        $letter_value_unix_end -= date('L', $unix_end);
                                    }
                                    $valid = ($letter_value_now >= $letter_value_unix) && (date($letter, strtotime('+' . ($letter_value_unix_end - $letter_value_unix - (($letter_value_now >= $letter_value_unix) && ($letter_value_now <= $letter_value_unix_end) ? $letter_value_now - $letter_value_unix : 0)) . ' days')) <= $letter_value_unix_end);
                                    if ($valid && $hhmm) {
                                        $valid = ($user_value >= $hhmm) && ($user_value <= $hhmm_end);
                                    }
                                    $repeat_id = $valid ? $letter_value_now : false;
                                }
                            } else if ($criteria == 'is-exactly') {
                                if ($condition == 'every-day') {
                                    $valid = true;
                                    $repeat_id = date('z');
                                } else {
                                    $letter = $condition == 'every-week' ? 'w' : ($condition == 'every-month' ? 'd' : 'z');
                                    $valid = $letter == 'z' ? ((date($letter, $unix) - date('L', $unix)) == (date($letter) - date('L'))) : (date($letter, $unix) == date($letter));
                                    $repeat_id = $valid ? date($letter) : false;
                                }
                            }
                            break;
                        }
                    }
                }
                break;
            case 'include_urls': // Deprecated: all this block
            case 'exclude_urls': // Deprecated: all this block
                $url = strtolower(str_replace(['https://', 'http://', 'www.'], '', sb_isset($_POST, 'current_url', sb_isset($_SERVER, 'HTTP_REFERER'))));
                $checks = explode(',', strtolower(str_replace(['https://', 'http://', 'www.', ' '], '', $conditions[$i][2])));
                if (($criteria == 'is-set' && !empty($checks)) || ($criteria == 'is-not-set' && empty($checks))) {
                    $valid = true;
                } else {
                    $include = $criteria != 'exclude_urls';
                    if (!$include) {
                        $valid = true;
                    }
                    for ($j = 0; $j < count($checks); $j++) {
                        if (($criteria == 'contains' && str_contains($url . '/', $checks[$j])) || ($criteria == 'does-not-contain' && !str_contains($url, $checks[$j])) || ($criteria == 'is-exactly' && $checks[$j] == $url) || ($criteria == 'is-not' && $checks[$j] != $url)) {
                            $valid = $include;
                            break;
                        }
                    }
                }
                break;
            case 'user_type':
                if ($active_user) {
                    $user_type = sb_isset($active_user, 'user_type');
                    $valid = ($criteria == 'is-visitor' && $user_type == 'visitor') || ($criteria == 'is-lead' && $user_type == 'is-lead') || ($criteria == 'is-user' && $user_type == 'user') || ($criteria == 'is-not-visitor' && $user_type != 'visitor') || ($criteria == 'is-not-lead' && $user_type != 'lead') || ($criteria == 'is-not-user' && $user_type != 'user');
                } else {
                    $valid = true;
                    array_push($return_conditions, $conditions[$i]);
                }
                break;
            case 'postal_code':
            case 'company':
            case 'cities':
            case 'languages':
            case 'countries':
                if ($active_user || $type == 'languages') {
                    if ($type == 'languages') {
                        $user_value = sb_get_user_language($active_user_id, true);
                    } else if ($type == 'cities') {
                        $user_value = sb_get_user_extra($active_user_id, 'location');
                        if ($user_value) {
                            $user_value = substr($user_value, 0, strpos($user_value, ','));
                        } else {
                            $user_value = sb_get_user_extra($active_user_id, 'city');
                        }
                    } else if ($type == 'countries') {
                        $user_value = sb_get_user_extra($active_user_id, 'country_code');
                        if (!$user_value) {
                            $user_value = sb_get_user_extra($active_user_id, 'country');
                            if (!$user_value) {
                                $user_value = sb_get_user_extra($active_user_id, 'location');
                                if ($user_value) {
                                    $user_value = trim(substr($user_value, strpos($user_value, ',')));
                                }
                            }
                            if ($user_value) {
                                $countries = sb_get_json_resource('json/countries.json');
                                if (isset($countries[$user_value])) {
                                    $user_value = $countries[$user_value];
                                } else if (strlen($user_value) > 2) {
                                    $user_value = substr($user_value, 0, 2);
                                }
                            }
                        }
                    } else {
                        $user_value = sb_get_user_extra($active_user_id, $type);
                    }
                    if ($user_value) {
                        $user_value = strtolower(trim($user_value));
                        $values = explode(',', strtolower(str_replace(' ', '', $conditions[$i][2])));
                        if ($criteria == 'is-included' && in_array($user_value, $values)) {
                            $valid = true;
                        } else if ($criteria == 'is-not-included' && !in_array($user_value, $values)) {
                            $valid = true;
                        } else {
                            $valid = ($criteria == 'is-set' && !empty($user_value)) || ($criteria == 'is-not-set' && empty($user_value));
                        }
                    }
                } else {
                    $valid = true;
                    array_push($return_conditions, $conditions[$i]);
                }
                break;
            case 'returning_visitor':
                $is_first_visitor = $criteria == 'first-time-visitor';
                if ($active_user) {
                    $times = sb_db_get('SELECT creation_time, last_activity FROM sb_users WHERE id = ' . $active_user_id);
                    if ($times) {
                        $difference = strtotime($times['last_activity']) - strtotime($times['creation_time']);
                        $valid = $is_first_visitor ? $difference < 86400 : $difference > 86400;
                    }
                } else if ($is_first_visitor) {
                    $valid = true;
                }
                break;
            default:
                if (!$check) {
                    $valid = true;
                    array_push($return_conditions, $conditions[$i]);
                }
                break;
        }
        if ($check) {
            $valid = false;
            if ($active_user) {
                $user_value = strtolower($type == 'email' ? $active_user['email'] : ($type == 'url' ? sb_isset($_POST, 'current_url', sb_isset($_SERVER, 'HTTP_REFERER')) : sb_get_user_extra($active_user_id, $type)));
                if ($type == 'url' || $type == 'website') {
                    $conditions[$i][2] = str_replace(['https://', 'http://', 'www.'], '', $conditions[$i][2]);
                    $user_value = str_replace(['https://', 'http://', 'www.'], '', $user_value);
                }
                $checks = explode(',', strtolower(str_replace(' ', '', $conditions[$i][2])));
                if ($criteria == 'is-set' || $criteria == 'is-not-set') {
                    $valid = ($criteria == 'is-set' && !empty($user_value)) || ($criteria == 'is-not-set' && empty($user_value));
                } else {
                    $valid = $criteria != 'contains';
                    for ($j = 0; $j < count($checks); $j++) {
                        if (str_contains($user_value, $checks[$j])) {
                            $valid = $criteria == 'contains';
                            break;
                        }
                    }
                }
            } else {
                $valid = true;
                array_push($return_conditions, $conditions[$i]);
            }
        }
        if (!$valid) {
            break;
        }
    }
    if ($is_flow) {
        return $valid && empty($return_conditions);
    }
    if ($valid && !sb_automations_is_sent($active_user_id, $automation, $repeat_id)) {

        // Check user details conditions
        if ($automation['type'] == 'emails' && (!$active_user || empty($active_user['email']))) {
            array_push($return_conditions, ['email']);
        } else if ($automation['type'] == 'sms' && !sb_get_user_extra($active_user_id, 'phone')) {
            array_push($return_conditions, ['phone']);
        }

        // Return the result
        return ['conditions' => $return_conditions, 'repeat_id' => $repeat_id];
    }
    return false;
}

function sb_automations_run($automation, $validate = false) {
    $active_user = sb_get_active_user();
    $response = false;
    if ($validate) {
        $validation = sb_automations_validate($automation);
        if (!$validation || count($validation['conditions']) > 0) {
            return false;
        }
    }
    if ($active_user) {
        $active_user_id = $active_user['id'];
        if (sb_automations_is_sent($active_user_id, $automation)) {
            return false;
        }
        switch ($automation['type']) {
            case 'messages':
                $response = sb_send_message(sb_get_bot_ID(), sb_get_last_conversation_id_or_create($active_user_id, 3), sb_t($automation['message']), [], 3, '{ "event": "open-chat" }');
                sb_reports_update('message-automations');
                break;
            case 'emails':
                $response = empty($active_user['email']) ? false : sb_email_send($active_user['email'], sb_merge_fields(sb_t($automation['subject'])), sb_merge_fields(sb_t($automation['message'])), '', false, false, $active_user_id);
                sb_reports_update('email-automations');
                break;
            case 'sms':
                $phone = sb_get_user_extra($active_user_id, 'phone');
                $response = $phone ? sb_send_sms(sb_merge_fields(sb_t($automation['message'])), $phone, false) : false;
                sb_reports_update('sms-automations');
                break;
            default:
                trigger_error('Invalid automation type in sb_automations_run()');
                return false;
        }
        $history = sb_get_external_setting('automations-history', []);
        $history_value = [$active_user['id'], $automation['id']];
        if (count($history) > 10000) {
            $history = array_slice($history, 1000);
        }
        if (isset($automation['repeat_id'])) {
            array_push($history_value, $automation['repeat_id']);
        }
        if ($response) {
            array_push($history, $history_value);
        }
        sb_save_external_setting('automations-history', $history);
    }
    return $response;
}

function sb_automations_is_sent($user_id, $automation, $repeat_id = false) {
    $history = sb_get_external_setting('automations-history', []);
    if ($user_id) {
        for ($x = 0, $length = count($history); $x < $length; $x++) {
            if ($history[$x][0] == $user_id && $history[$x][1] == $automation['id'] && (!$repeat_id || (count($history[$x]) > 2 && $history[$x][2] == $repeat_id))) {
                return true;
            }
        }
    }
    return false;
}

/*
 * -----------------------------------------------------------
 * CLOUD
 * -----------------------------------------------------------
 *
 * 1. Increase the membership messages count for the current month
 * 2. Check if the membership is valid
 * 3. Cloud account
 * 4. Load the config.php file
 * 5. Load cloud environment from token URL
 * 6. Load reseller js and css codes
 * 7. Add or delete agent
 * 8. Set and return cloud login data
 * 9. Check if cloud version
 * 10. Check the the user has credits
 * 11. Reset the login data and reload
 *
 */

function sb_cloud_increase_count() {
    require_once(SB_CLOUD_PATH . '/account/functions.php');
    cloud_increase_count();
}

function sb_cloud_membership_validation($die = false) {
    require_once(SB_CLOUD_PATH . '/account/functions.php');
    $membership = membership_get_active();
    $expiration = DateTime::createFromFormat('d-m-y', $membership['expiration']);
    return !$membership || !isset($membership['count']) || intval($membership['count']) > intval($membership['quota']) || (isset($membership['count_agents']) && isset($membership['quota_agents']) && intval($membership['count_agents']) > intval($membership['quota_agents'])) || ($membership['price'] != 0 && (!$expiration || time() > $expiration->getTimestamp())) ? ($die || !sb_isset(account(), 'owner') ? die('account-suspended') : '<script>document.location = "' . CLOUD_URL . '/account"</script>') : '<script>var SB_CLOUD_FREE = ' . (empty($membership['id']) || $membership['id'] == 'free' ? 'true' : 'false') . '</script>';
}

function sb_cloud_account() {
    return json_decode(sb_encryption(isset($_POST['cloud']) ? $_POST['cloud'] : sb_isset($_GET, 'cloud'), false), true);
}

function sb_cloud_account_id() {
    global $ACTIVE_ACCOUNT;
    global $ACTIVE_ACCOUNT_ID;
    if ($ACTIVE_ACCOUNT_ID) {
        return $ACTIVE_ACCOUNT_ID;
    }
    if ($ACTIVE_ACCOUNT) {
        $ACTIVE_ACCOUNT_ID = $ACTIVE_ACCOUNT['user_id'];
        return $ACTIVE_ACCOUNT_ID;
    }
    require_once(SB_CLOUD_PATH . '/account/functions.php');
    $ACTIVE_ACCOUNT_ID = get_active_account_id();
    return $ACTIVE_ACCOUNT_ID;
}

function sb_cloud_ajax_function_forbidden($function_name) {
    return in_array($function_name, ['installation', 'upload-path', 'get-versions', 'update', 'app-activation', 'app-get-key', 'system-requirements', 'path']);
}

function sb_cloud_load() {
    if (!defined('SB_DB_NAME')) {
        $data = !empty($_POST['cloud']) ? $_POST['cloud'] : (!empty($_GET['cloud']) ? $_GET['cloud'] : (empty($_COOKIE['sb-cloud']) ? false : $_COOKIE['sb-cloud']));
        if ($data) {
            $cookie = json_decode(sb_encryption($data, false), true);
            $path = SB_CLOUD_PATH . '/script/config/config_' . $cookie['token'] . '.php';
            if (file_exists($path)) {
                require_once($path);
                return true;
            }
            return 'config-file-not-found';
        } else {
            return 'cloud-data-not-found';
        }
    }
    return true;
}

function sb_cloud_load_by_url() {
    if (sb_is_cloud()) {
        $token = isset($_GET['envato_purchase_code']) ? $_GET['envato_purchase_code'] : (isset($_GET['cloud']) ? $_GET['cloud'] : false);
        if ($token) {
            $token = sb_sanatize_file_name($token);
            $path = SB_CLOUD_PATH . '/script/config/config_' . $token . '.php';
            if (sb_is_valid_path($path)) {
                require_once($path);
                sb_cloud_set_login($token);
            } else {
                sb_error('config-file-not-found', 'sb_cloud_load_by_url', 'Config file not found: ' . $path);
            }
            return $token;
        }
    }
    return false;
}

function sb_cloud_css_js() {
    require_once(SB_CLOUD_PATH . '/account/functions.php');
    cloud_css_js();
}

function sb_cloud_set_agent($email, $action = 'add', $extra = false) {
    if ($email) {
        require_once(SB_CLOUD_PATH . '/account/functions.php');
        $cloud = sb_cloud_account();
        membership_delete_cache($cloud['user_id']);
        if ($action == 'add') {
            return db_query('INSERT INTO agents(admin_id, email) VALUE ("' . $cloud['user_id'] . '", "' . $email . '")');
        }
        if ($action == 'update') {
            return db_query('UPDATE agents SET email = "' . $extra . '" WHERE email = "' . $email . '"');
        }
        if ($action == 'delete') {
            return db_query('DELETE FROM agents WHERE email = "' . $email . '"');
        }
    }
    return false;
}

function sb_cloud_set_login($token) {
    require_once(SB_CLOUD_PATH . '/account/functions.php');
    $cloud_user = db_get('SELECT id AS `user_id`, first_name, last_name, email, password, token, customer_id FROM users WHERE token = "' . $token . '" LIMIT 1');
    if ($cloud_user) {
        $cloud_user = sb_encryption(json_encode($cloud_user));
        $_POST['cloud'] = $cloud_user;
        return $cloud_user;
    }
    return false;
}

function sb_is_cloud() {
    return defined('SB_CLOUD');
}

function sb_cloud_membership_has_credits($source = false, $notification = true) {
    if (sb_is_cloud() && (!$source || !sb_ai_is_manual_sync($source))) {
        require_once(SB_CLOUD_PATH . '/account/functions.php');
        $user_id = db_escape(account()['user_id'], true);
        if ($user_id) {
            $user_info = db_get('SELECT credits, email FROM users WHERE id = ' . $user_id);
            $credits = sb_isset($user_info, 'credits', 0);
            $continue = $credits < 1 && super_get_user_data('auto_recharge', $user_id) ? membership_auto_recharge() : true;
            if ($continue === true && $credits <= 0 && $notification && cloud_suspended_notifications_counter($user_id, false, true) < 2) {
                $email = [super_get_setting('email_subject_no_credits'), super_get_setting('email_template_no_credits')];
                send_email(sb_isset($user_info, 'email'), $email[0], $email[1]);
                cloud_suspended_notifications_counter($user_id, true, true);
            }
            return $credits > 0;
        }
        return false;
    }
    return true;
}

function sb_cloud_membership_use_credits($spending_source, $source, $extra = false) {
    if (sb_is_cloud() && !sb_ai_is_manual_sync($source)) {
        require_once(SB_CLOUD_PATH . '/account/functions.php');
        membership_use_credits($spending_source, $extra);
    }
}

function sb_cloud_reset_login() {
    die('<script>document.cookie="sb-login=;expires=Thu, 01 Jan 1970 00:00:01 GMT;path=/";document.cookie="sb-cloud=;expires=Thu, 01 Jan 1970 00:00:01 GMT;path=/";document.location="' . CLOUD_URL . '/account?login";</script>');
}

?>
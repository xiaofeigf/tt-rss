<?php
class PluginHost {
	private $pdo;
	/* separate handle for plugin data so transaction while saving wouldn't clash with possible main
		tt-rss code transactions; only initialized when first needed */
	private $pdo_data;
	private $hooks = array();
	private $plugins = array();
	private $handlers = array();
	private $commands = array();
	private $storage = array();
	private $feeds = array();
	private $api_methods = array();
	private $plugin_actions = array();
	private $owner_uid;
	private $last_registered;
	private $data_loaded;
	private static $instance;

	const API_VERSION = 2;

	// Hooks marked with *1 are run in global context and available
	// to plugins loaded in config.php only

	const HOOK_ARTICLE_BUTTON = "hook_article_button";
	const HOOK_ARTICLE_FILTER = "hook_article_filter";
	const HOOK_PREFS_TAB = "hook_prefs_tab";
	const HOOK_PREFS_TAB_SECTION = "hook_prefs_tab_section";
	const HOOK_PREFS_TABS = "hook_prefs_tabs";
	const HOOK_FEED_PARSED = "hook_feed_parsed";
	const HOOK_UPDATE_TASK = "hook_update_task"; //*1
	const HOOK_AUTH_USER = "hook_auth_user";
	const HOOK_HOTKEY_MAP = "hook_hotkey_map";
	const HOOK_RENDER_ARTICLE = "hook_render_article";
	const HOOK_RENDER_ARTICLE_CDM = "hook_render_article_cdm";
	const HOOK_FEED_FETCHED = "hook_feed_fetched";
	const HOOK_SANITIZE = "hook_sanitize";
	const HOOK_RENDER_ARTICLE_API = "hook_render_article_api";
	const HOOK_TOOLBAR_BUTTON = "hook_toolbar_button";
	const HOOK_ACTION_ITEM = "hook_action_item";
	const HOOK_HEADLINE_TOOLBAR_BUTTON = "hook_headline_toolbar_button";
	const HOOK_HOTKEY_INFO = "hook_hotkey_info";
	const HOOK_ARTICLE_LEFT_BUTTON = "hook_article_left_button";
	const HOOK_PREFS_EDIT_FEED = "hook_prefs_edit_feed";
	const HOOK_PREFS_SAVE_FEED = "hook_prefs_save_feed";
	const HOOK_FETCH_FEED = "hook_fetch_feed";
	const HOOK_QUERY_HEADLINES = "hook_query_headlines";
	const HOOK_HOUSE_KEEPING = "hook_house_keeping"; //*1
	const HOOK_SEARCH = "hook_search";
	const HOOK_FORMAT_ENCLOSURES = "hook_format_enclosures";
	const HOOK_SUBSCRIBE_FEED = "hook_subscribe_feed";
	const HOOK_HEADLINES_BEFORE = "hook_headlines_before";
	const HOOK_RENDER_ENCLOSURE = "hook_render_enclosure";
	const HOOK_ARTICLE_FILTER_ACTION = "hook_article_filter_action";
	const HOOK_ARTICLE_EXPORT_FEED = "hook_article_export_feed";
	const HOOK_MAIN_TOOLBAR_BUTTON = "hook_main_toolbar_button";
	const HOOK_ENCLOSURE_ENTRY = "hook_enclosure_entry";
	const HOOK_FORMAT_ARTICLE = "hook_format_article";
	const HOOK_FORMAT_ARTICLE_CDM = "hook_format_article_cdm"; /* RIP */
	const HOOK_FEED_BASIC_INFO = "hook_feed_basic_info";
	const HOOK_SEND_LOCAL_FILE = "hook_send_local_file";
	const HOOK_UNSUBSCRIBE_FEED = "hook_unsubscribe_feed";
	const HOOK_SEND_MAIL = "hook_send_mail";
	const HOOK_FILTER_TRIGGERED = "hook_filter_triggered";
	const HOOK_GET_FULL_TEXT = "hook_get_full_text";
	const HOOK_ARTICLE_IMAGE = "hook_article_image";
	const HOOK_FEED_TREE = "hook_feed_tree";
	const HOOK_IFRAME_WHITELISTED = "hook_iframe_whitelisted";
	const HOOK_ENCLOSURE_IMPORTED = "hook_enclosure_imported";
	const HOOK_HEADLINES_CUSTOM_SORT_MAP = "hook_headlines_custom_sort_map";
	const HOOK_HEADLINES_CUSTOM_SORT_OVERRIDE = "hook_headlines_custom_sort_override";
	const HOOK_HEADLINE_TOOLBAR_SELECT_MENU_ITEM = "hook_headline_toolbar_select_menu_item";

	const KIND_ALL = 1;
	const KIND_SYSTEM = 2;
	const KIND_USER = 3;

	static function object_to_domain($plugin) {
		return strtolower(get_class($plugin));
	}

	function __construct() {
		$this->pdo = Db::pdo();
		$this->storage = array();
	}

	private function __clone() {
		//
	}

	public static function getInstance() {
		if (self::$instance == null)
			self::$instance = new self();

		return self::$instance;
	}

	private function register_plugin($name, $plugin) {
		//array_push($this->plugins, $plugin);
		$this->plugins[$name] = $plugin;
	}

	// needed for compatibility with API 1
	function get_link() {
		return false;
	}

	function get_dbh() {
		return Db::get();
	}

	function get_pdo() {
		return $this->pdo;
	}

	function get_plugin_names() {
		$names = array();

		foreach ($this->plugins as $p) {
			array_push($names, get_class($p));
		}

		return $names;
	}

	function get_plugins() {
		return $this->plugins;
	}

	function get_plugin($name) {
		return $this->plugins[strtolower($name)] ?? null;
	}

	function run_hooks($hook, ...$args) {
		$method = strtolower($hook);

		foreach ($this->get_hooks($hook) as $plugin) {
			//Debug::log("invoking: " . get_class($plugin) . "->$hook()", Debug::$LOG_VERBOSE);

			try {
				$plugin->$method(...$args);
			} catch (Exception $ex) {
				user_error($ex, E_USER_WARNING);
			} catch (Error $err) {
				user_error($err, E_USER_WARNING);
			}
		}
	}

	function run_hooks_until($hook, $check, ...$args) {
		$method = strtolower($hook);

		foreach ($this->get_hooks($hook) as $plugin) {
			try {
				$result = $plugin->$method(...$args);

				if ($result == $check)
					return true;

			} catch (Exception $ex) {
				user_error($ex, E_USER_WARNING);
			} catch (Error $err) {
				user_error($err, E_USER_WARNING);
			}
		}

		return false;
	}

	function run_hooks_callback($hook, $callback, ...$args) {
		$method = strtolower($hook);

		foreach ($this->get_hooks($hook) as $plugin) {
			//Debug::log("invoking: " . get_class($plugin) . "->$hook()", Debug::$LOG_VERBOSE);

			try {
				if ($callback($plugin->$method(...$args), $plugin))
					break;
			} catch (Exception $ex) {
				user_error($ex, E_USER_WARNING);
			} catch (Error $err) {
				user_error($err, E_USER_WARNING);
			}
		}
	}

	function chain_hooks_callback($hook, $callback, &...$args) {
		$method = strtolower($hook);

		foreach ($this->get_hooks($hook) as $plugin) {
			//Debug::log("invoking: " . get_class($plugin) . "->$hook()", Debug::$LOG_VERBOSE);

			try {
				if ($callback($plugin->$method(...$args), $plugin))
					break;
			} catch (Exception $ex) {
				user_error($ex, E_USER_WARNING);
			} catch (Error $err) {
				user_error($err, E_USER_WARNING);
			}
		}
	}

	function add_hook($type, $sender, $priority = 50) {
		$priority = (int) $priority;

		if (!method_exists($sender, strtolower($type))) {
			user_error(
				sprintf("Plugin %s tried to register a hook without implementation: %s",
					get_class($sender), $type),
				E_USER_WARNING
			);
			return;
		}

		if (empty($this->hooks[$type])) {
			$this->hooks[$type] = [];
		}

		if (empty($this->hooks[$type][$priority])) {
			$this->hooks[$type][$priority] = [];
		}

		array_push($this->hooks[$type][$priority], $sender);
		ksort($this->hooks[$type]);
	}

	function del_hook($type, $sender) {
		if (is_array($this->hooks[$type])) {
			foreach (array_keys($this->hooks[$type]) as $prio) {
				$key = array_search($sender, $this->hooks[$type][$prio]);

				if ($key !== false) {
					unset($this->hooks[$type][$prio][$key]);
				}
			}
		}
	}

	function get_hooks($type) {
		if (isset($this->hooks[$type])) {
			$tmp = [];

			foreach (array_keys($this->hooks[$type]) as $prio) {
				$tmp = array_merge($tmp, $this->hooks[$type][$prio]);
			}

			return $tmp;
		} else {
			return [];
		}
	}
	function load_all($kind, $owner_uid = false, $skip_init = false) {

		$plugins = array_merge(glob("plugins/*"), glob("plugins.local/*"));
		$plugins = array_filter($plugins, "is_dir");
		$plugins = array_map("basename", $plugins);

		asort($plugins);

		$this->load(join(",", $plugins), $kind, $owner_uid, $skip_init);
	}

	function load($classlist, $kind, $owner_uid = false, $skip_init = false) {
		$plugins = explode(",", $classlist);

		$this->owner_uid = (int) $owner_uid;

		foreach ($plugins as $class) {
			$class = trim($class);
			$class_file = strtolower(basename(clean($class)));

			if (!is_dir(__DIR__."/../plugins/$class_file") &&
					!is_dir(__DIR__."/../plugins.local/$class_file")) continue;

			// try system plugin directory first
			$file = __DIR__ . "/../plugins/$class_file/init.php";
			$vendor_dir = __DIR__ . "/../plugins/$class_file/vendor";

			if (!file_exists($file)) {
				$file = __DIR__ . "/../plugins.local/$class_file/init.php";
				$vendor_dir = __DIR__ . "/../plugins.local/$class_file/vendor";
			}

			if (!isset($this->plugins[$class])) {
				try {
					if (file_exists($file)) require_once $file;
				} catch (Error $err) {
					user_error($err, E_USER_WARNING);
					continue;
				}

				if (class_exists($class) && is_subclass_of($class, "Plugin")) {

					// register plugin autoloader if necessary, for namespaced classes ONLY
					// layout corresponds to tt-rss main /vendor/author/Package/Class.php

					if (file_exists($vendor_dir)) {
						spl_autoload_register(function($class) use ($vendor_dir) {

							if (strpos($class, '\\') !== false) {
								list ($namespace, $class_name) = explode('\\', $class, 2);

								if ($namespace && $class_name) {
									$class_file = "$vendor_dir/$namespace/" . str_replace('\\', '/', $class_name) . ".php";

									if (file_exists($class_file))
										require_once $class_file;
								}
							}
						});
					}

					$plugin = new $class($this);

					$plugin_api = $plugin->api_version();

					if ($plugin_api < self::API_VERSION) {
						user_error("Plugin $class is not compatible with current API version (need: " . self::API_VERSION . ", got: $plugin_api)", E_USER_WARNING);
						continue;
					}

					if (file_exists(dirname($file) . "/locale")) {
						_bindtextdomain($class, dirname($file) . "/locale");
						_bind_textdomain_codeset($class, "UTF-8");
					}

					$this->last_registered = $class;

					try {
						switch ($kind) {
							case $this::KIND_SYSTEM:
								if ($this->is_system($plugin)) {
									if (!$skip_init) $plugin->init($this);
									$this->register_plugin($class, $plugin);
								}
								break;
							case $this::KIND_USER:
								if (!$this->is_system($plugin)) {
									if (!$skip_init) $plugin->init($this);
									$this->register_plugin($class, $plugin);
								}
								break;
							case $this::KIND_ALL:
								if (!$skip_init) $plugin->init($this);
								$this->register_plugin($class, $plugin);
								break;
							}
					} catch (Exception $ex) {
						user_error($ex, E_USER_WARNING);
					} catch (Error $err) {
						user_error($err, E_USER_WARNING);
					}
				}
			}
		}

		$this->load_data();
	}

	function is_system($plugin) {
		$about = $plugin->about();

		return $about[3] ?? false;
	}

	// only system plugins are allowed to modify routing
	function add_handler($handler, $method, $sender) {
		$handler = str_replace("-", "_", strtolower($handler));
		$method = strtolower($method);

		if ($this->is_system($sender)) {
			if (!is_array($this->handlers[$handler])) {
				$this->handlers[$handler] = array();
			}

			$this->handlers[$handler][$method] = $sender;
		}
	}

	function del_handler($handler, $method, $sender) {
		$handler = str_replace("-", "_", strtolower($handler));
		$method = strtolower($method);

		if ($this->is_system($sender)) {
			unset($this->handlers[$handler][$method]);
		}
	}

	function lookup_handler($handler, $method) {
		$handler = str_replace("-", "_", strtolower($handler));
		$method = strtolower($method);

		if (isset($this->handlers[$handler])) {
			if (isset($this->handlers[$handler]["*"])) {
				return $this->handlers[$handler]["*"];
			} else {
				return $this->handlers[$handler][$method];
			}
		}

		return false;
	}

	function add_command($command, $description, $sender, $suffix = "", $arghelp = "") {
		$command = str_replace("-", "_", strtolower($command));

		$this->commands[$command] = array("description" => $description,
			"suffix" => $suffix,
			"arghelp" => $arghelp,
			"class" => $sender);
	}

	function del_command($command) {
		$command = "-" . strtolower($command);

		unset($this->commands[$command]);
	}

	function lookup_command($command) {
		$command = "-" . strtolower($command);

		if (is_array($this->commands[$command])) {
			return $this->commands[$command]["class"];
		} else {
			return false;
		}
	}

	function get_commands() {
		return $this->commands;
	}

	function run_commands($args) {
		foreach ($this->get_commands() as $command => $data) {
			if (isset($args[$command])) {
				$command = str_replace("-", "", $command);
				$data["class"]->$command($args);
			}
		}
	}

	private function load_data() {
		if ($this->owner_uid && !$this->data_loaded && get_schema_version() > 100)  {
			$sth = $this->pdo->prepare("SELECT name, content FROM ttrss_plugin_storage
				WHERE owner_uid = ?");
			$sth->execute([$this->owner_uid]);

			while ($line = $sth->fetch()) {
				$this->storage[$line["name"]] = unserialize($line["content"]);
			}

			$this->data_loaded = true;
		}
	}

	private function save_data($plugin) {
		if ($this->owner_uid) {

			if (!$this->pdo_data)
				$this->pdo_data = Db::instance()->pdo_connect();

			$this->pdo_data->beginTransaction();

			$sth = $this->pdo_data->prepare("SELECT id FROM ttrss_plugin_storage WHERE
				owner_uid= ? AND name = ?");
			$sth->execute([$this->owner_uid, $plugin]);

			if (!isset($this->storage[$plugin]))
				$this->storage[$plugin] = array();

			$content = serialize($this->storage[$plugin]);

			if ($sth->fetch()) {
				$sth = $this->pdo_data->prepare("UPDATE ttrss_plugin_storage SET content = ?
					WHERE owner_uid= ? AND name = ?");
				$sth->execute([$content, $this->owner_uid, $plugin]);

			} else {
				$sth = $this->pdo_data->prepare("INSERT INTO ttrss_plugin_storage
					(name,owner_uid,content) VALUES
					(?, ?, ?)");
				$sth->execute([$plugin, $this->owner_uid, $content]);
			}

			$this->pdo_data->commit();
		}
	}

	function set($sender, $name, $value, $sync = true) {
		$idx = get_class($sender);

		if (!isset($this->storage[$idx]))
			$this->storage[$idx] = array();

		$this->storage[$idx][$name] = $value;

		if ($sync) $this->save_data(get_class($sender));
	}

	function get($sender, $name, $default_value = false) {
		$idx = get_class($sender);

		$this->load_data();

		if (isset($this->storage[$idx][$name])) {
			return $this->storage[$idx][$name];
		} else {
			return $default_value;
		}
	}

	function get_all($sender) {
		$idx = get_class($sender);

		return $this->storage[$idx] ?? [];
	}

	function clear_data($sender) {
		if ($this->owner_uid) {
			$idx = get_class($sender);

			unset($this->storage[$idx]);

			$sth = $this->pdo->prepare("DELETE FROM ttrss_plugin_storage WHERE name = ?
				AND owner_uid = ?");
			$sth->execute([$idx, $this->owner_uid]);
		}
	}

	// Plugin feed functions are *EXPERIMENTAL*!

	// cat_id: only -1 is supported (Special)
	function add_feed($cat_id, $title, $icon, $sender) {
		if (!$this->feeds[$cat_id]) $this->feeds[$cat_id] = array();

		$id = count($this->feeds[$cat_id]);

		array_push($this->feeds[$cat_id],
			array('id' => $id, 'title' => $title, 'sender' => $sender, 'icon' => $icon));

		return $id;
	}

	function get_feeds($cat_id) {
		return $this->feeds[$cat_id] ?? [];
	}

	// convert feed_id (e.g. -129) to pfeed_id first
	function get_feed_handler($pfeed_id) {
		foreach ($this->feeds as $cat) {
			foreach ($cat as $feed) {
				if ($feed['id'] == $pfeed_id) {
					return $feed['sender'];
				}
			}
		}
	}

	static function pfeed_to_feed_id($label) {
		return PLUGIN_FEED_BASE_INDEX - 1 - abs($label);
	}

	static function feed_to_pfeed_id($feed) {
		return PLUGIN_FEED_BASE_INDEX - 1 + abs($feed);
	}

	function add_api_method($name, $sender) {
		if ($this->is_system($sender)) {
			$this->api_methods[strtolower($name)] = $sender;
		}
	}

	function get_api_method($name) {
		return $this->api_methods[$name];
	}

	function add_filter_action($sender, $action_name, $action_desc) {
		$sender_class = get_class($sender);

		if (!isset($this->plugin_actions[$sender_class]))
			$this->plugin_actions[$sender_class] = array();

		array_push($this->plugin_actions[$sender_class],
			array("action" => $action_name, "description" => $action_desc, "sender" => $sender));
	}

	function get_filter_actions() {
		return $this->plugin_actions;
	}

	function get_owner_uid() {
		return $this->owner_uid;
	}

	// handled by classes/pluginhandler.php, requires valid session
	function get_method_url($sender, $method, $params)  {
		return get_self_url_prefix() . "/backend.php?" .
			http_build_query(
				array_merge(
					[
						"op" => "pluginhandler",
						"plugin" => strtolower(get_class($sender)),
						"method" => $method
					],
					$params));
	}

	// WARNING: endpoint in public.php, exposed to unauthenticated users
	function get_public_method_url($sender, $method, $params)  {
		if ($sender->is_public_method($method)) {
			return get_self_url_prefix() . "/public.php?" .
				http_build_query(
					array_merge(
						[
							"op" => "pluginhandler",
							"plugin" => strtolower(get_class($sender)),
							"pmethod" => $method
						],
						$params));
		} else {
			user_error("get_public_method_url: requested method '$method' of '" . get_class($sender) . "' is private.");
		}
	}
}

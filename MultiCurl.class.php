<?php

/**
* MultiCurl Class
 *
 * @category  Utility
 * @package   php-multicurl-class
 * @author    Likecyber <cyber2friends@gmail.com>
 * @copyright Copyright (c) 2018-2019
 * @license   https://creativecommons.org/licenses/by/4.0/ Attribution 4.0 International (CC BY 4.0)
 * @link      https://github.com/likecyber/php-multicurl-class
 * @version   1.2.0
**/

class MultiCurl {
	private $max_worker = 0;
	private $multi_options = array();
	private $curl_options = array();

	private $operation = array();
	private $queue = array();
	private $next_queue = array();
	private $autofill = null;

	private $mh, $ch = null;
	private $status, $active = 0;
	private $worker = array();
	private $current_worker = null;
	private $running = false;

	public function __construct ($max_worker = 0) {
		$this->SetMaxWorker($max_worker);
	}

	private function _perform_reset () {
		foreach ($this->operation as $operation => $operation_array) {
			$this->operation[$operation]["abort"] = false;
		}
		$this->mh = null;
		$this->ch = null;
		$this->status = 0;
		$this->active = 0;
		$this->worker = array();
		$this->current_worker = null;
		$this->running = false;
	}

	public function SetMaxWorker ($max_worker) {
		if (!is_int($max_worker) || $max_worker < 0) return false;
		$this->max_worker = $max_worker;
		if ($max_worker === 0) $this->Autofill(null);
		return true;
	}

	public function SetMultiOptions ($multi_options = array()) {
		$this->multi_options = $multi_options;
		return true;
	}

	public function SetCurlOptions ($curl_options = array()) {
		$default_options = array(
			CURLOPT_RETURNTRANSFER => true
		);
		$this->curl_options = $default_options + $curl_options;
		return true;
	}

	public function SetOperation ($operation, $init_function, $response_function) {
		if (!isset($this->operation[$operation])) $this->operation[$operation] = array();
		$this->operation[$operation]["init"] = $init_function;
		$this->operation[$operation]["response"] = $response_function;
		if (!isset($this->operation[$operation]["active_worker"])) $this->operation[$operation]["active_worker"] = array();
		return true;
	}

	public function AddQueue ($operation, $data = null, $callback = null, $amount = 1) {
		while ($amount-- > 0) {
			$this->queue[] = array(
				"operation" => $operation,
				"data" => $data,
				"callback" => $callback
			);
		}
		return true;
	}

	public function ClearQueue () {
		$this->queue = array();
		return true;
	}

	public function AddNextQueue ($operation, $data = null, $amount = 1) {
		while ($amount-- > 0) {
			$this->next_queue[] = array(
				"operation" => $operation,
				"data" => $data
			);
		}
		return true;
	}

	public function ClearNextQueue () {
		$this->next_queue = array();
		return true;
	}

	public function Autofill ($operation = null, $data = null) {
		if ($this->max_worker === 0) return false;
		if (is_null($operation)) {
			$this->autofill = null;
		} else {
			$this->autofill = array(
				"operation" => $operation,
				"data" => $data
			);
		}
		return true;
	}

	public function FulfillWorker () {
		if (!$this->running) return false;
		if (!is_null($this->autofill) && $this->max_worker > 0) {
			while (count($this->queue) < $this->max_worker) {
				$this->queue[] = $this->autofill;
			}
		}
		while (count($this->queue) > 0 && ($this->max_worker === 0 || count($this->worker) < $this->max_worker)) {
			$worker_array = array_shift($this->queue);
			if ($this->operation[$worker_array["operation"]]["abort"]) continue;
			if (is_null($this->current_worker)) {
				$this->ch = curl_init();
			}
			curl_setopt_array($this->ch, $this->curl_options);
			call_user_func_array($this->operation[$worker_array["operation"]]["init"], array(&$this->ch, &$worker_array["data"]));
			curl_multi_add_handle($this->mh, $this->ch);
			$this->worker[(int) $this->ch] = $worker_array;
			$this->operation[$worker_array["operation"]]["active_worker"][(int) $this->ch] = true;
			if (!is_null($this->current_worker)) {
				$this->status = curl_multi_exec($this->mh, $this->active);
				$this->current_worker = null;
			}
		}
		$this->queue = array_values($this->queue);
		return true;
	}

	public function Execute () {
		$this->_perform_reset();
		$this->running = true;

		$this->mh = curl_multi_init();
		curl_multi_setopt($this->mh, CURLMOPT_MAXCONNECTS, $this->max_worker);
		foreach ($this->multi_options as $key => $value) {
			curl_multi_setopt($this->mh, $key, $value);
		}

		$this->FulfillWorker();

		do {
			$this->status = curl_multi_exec($this->mh, $this->active);
			while ($read = curl_multi_info_read($this->mh)) {
				curl_multi_remove_handle($this->mh, $read["handle"]);

				$this->ch = $read["handle"];
				$this->current_worker = (int) $this->ch;
				$response = curl_multi_getcontent($this->ch);
				$info = curl_getinfo($this->ch);
				$errno = curl_errno($this->ch);
				$data = $this->worker[$this->current_worker]["data"];

				unset($this->operation[$this->worker[(int) $this->ch]["operation"]]["active_worker"][(int) $this->ch]);

				curl_reset($this->ch);
				call_user_func_array($this->operation[$this->worker[$this->current_worker]["operation"]]["response"], array($response, $info, $errno, $this, &$data));
				if (!is_null($this->worker[$this->current_worker]["callback"])) {
					call_user_func_array($this->worker[$this->current_worker]["callback"], array($this, &$data));
				}
				unset($this->worker[$this->current_worker]);

				$this->FulfillWorker();
			}
			curl_multi_select($this->mh);
		} while (($this->status === CURLM_OK && $this->active > 0) || count($this->worker) > 0);

		curl_multi_close($this->mh);
		$this->queue = $this->next_queue;
		$this->next_queue = array();
		$this->_perform_reset();
	}

	public function ResumeWorker ($operation, $data = null) {
		if (!$this->running) return false;
		if (is_null($this->current_worker)) return false;
		curl_setopt_array($this->ch, $this->curl_options);
		call_user_func_array($this->operation[$operation]["init"], array(&$this->ch, &$data));
		curl_multi_add_handle($this->mh, $this->ch);
		$this->worker[(int) $this->ch] = array("operation" => $operation, "data" => $data);
		$this->operation[$operation]["active_worker"][(int) $this->ch] = true;
		$this->status = curl_multi_exec($this->mh, $this->active);
		$this->current_worker = null;
		return true;
	}

	public function TempWorker ($operation, $data = null) {
		if (!$this->running) return false;
		$ch = curl_init();
		curl_setopt_array($ch, $this->curl_options);
		call_user_func_array($this->operation[$operation]["init"], array(&$ch, &$data));
		curl_multi_add_handle($this->mh, $ch);
		$this->worker[(int) $ch] = array("operation" => $operation, "data" => $data);
		$this->operation[$operation]["active_worker"][(int) $ch] = true;
		$this->status = curl_multi_exec($this->mh, $this->active);
	}

	public function KillWorker () {
		if (!$this->running) return false;
		if (is_null($this->current_worker)) return false;
		$this->current_worker = null;
		return true;
	}

	public function Abort () {
		if (!$this->running) return false;
		foreach ($this->operation as $operation => $operation_array) {
			$this->AbortOperation($operation);
		}
		return true;
	}

	public function AbortOperation ($operation) {
		if (!$this->running) return false;
		$this->operation[$operation]["abort"] = true;
		$resources = get_resources("curl");
		foreach ($this->operation[$operation]["active_worker"] as $worker_id => $bool) {
			curl_multi_remove_handle($this->mh, $resources[$worker_id]);
			unset($this->operation[$operation]["active_worker"][$worker_id]);
			unset($this->worker[$worker_id]);
		}
		return true;
	}

	public function Continue () {
		if (!$this->running) return false;
		foreach ($this->operation as $operation => $operation_array) {
			$this->ContinueOperation($operation);
		}
		return true;
	}

	public function ContinueOperation ($operation) {
		if (!$this->running) return false;
		$this->operation[$operation]["abort"] = false;
		return true;
	}


}

?>

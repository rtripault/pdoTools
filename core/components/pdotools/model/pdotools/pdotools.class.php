<?php

class pdoTools {
	/* @var modX $modx */
	public $modx;
	public $timings = array();
	public $config = array();
	public $elements = array();
	protected $time;


	function __construct(modX & $modx) {
		$this->modx = $modx;
		$this->time = microtime(true);
	}


	/**
	 * Add new record to timings log
	 *
	 * @param $message
	 */
	public function addTime($message) {
		$time = microtime(true);
		$this->timings[] = array(
			'time' =>  number_format(round(($time - $this->time), 7), 7)
			,'message' => $message
		);
		$this->time = $time;
	}


	/**
	 * Return timings log
	 *
	 * @param bool $string Return array or formatted string
	 *
	 * @return array|string
	 */
	public function getTime($string = true) {
		if (!$string) {
			return $this->timings;
		}
		else {
			$res = $sum = null;
			foreach ($this->timings as $v) {
				$res .= $v['time'] . ': ' . $v['message'] . "\n";
				$sum += $v['time'];
			}

			$res .= number_format(round($sum, 7), 7) . ': <b>Total time</b>';
			return $res;
		}
	}


	/**
	 * Add element to cache
	 *
	 * @return boolean
	 * */
	public function addElement($name, $object) {
		$this->elements[$name] = $object;

		return $this->inCache($name);
	}


	/**
	 * Return element from cache
	 *
	 * @return array|boolean
	 * */
	public function getElement($name) {
		return $this->inCache($name) ? $this->elements[$name] : false;
	}


	/**
	 * Check for existing element
	 *
	 * @return boolean
	 * */
	public function inCache($name) {
		return isset($this->elements[$name]);
	}


	/**
	 * Return quick placeholders from cached element
	 *
	 * @return array
	 */
	public function getPlaceholders($name) {
		return $this->inCache($name) ? $this->elements[$name]['placeholders'] : array();
	}


	/**
	 * Transform array to placeholdres
	 *
	 * @param array $array
	 * @param string $prefix
	 *
	 * @return array
	 */public function makePlaceholders(array $array = array(), $prefix = '') {
		$result = array(
			'pl' => array()
			,'vl' => array()
		);
		foreach ($array as $k => $v) {
			if (is_array($v)) {
				$result = array_merge_recursive($result, $this->makePlaceholders($v, $k.'.'));
			}
			else {
				$result['pl'][$prefix.$k] = '[[+'.$prefix.$k.']]';
				$result['vl'][$prefix.$k] = $v;
			}
		}

		return $result;
	}


	/**
	 * Process and return the output from a Chunk by name.
	 *
	 * @param string $chunkName The name of the chunk.
	 * @param array $properties An associative array of properties to process the Chunk with, treated as placeholders within the scope of the Element.
	 * @param boolean $fastMode If true, all MODX tags in chunk will be processed.
	 *
	 * @return string|boolean The processed output of the Chunk.
	 */
	public function getChunk($name, array $properties = array(), $fastMode = false) {
		$output = null;

		if (!$this->inCache($name)) {
			/* @var modChunk $element */
			if ($element = $this->modx->getObject('modChunk', array('name' => $name))) {
				$element->setCacheable(false);
				$content = $element->getContent();

				// processing lexicon placeholders
				preg_match_all('/\[\[%(.*?)\]\]/',$content, $matches);
				$src = $dst = array();
				foreach ($matches[1] as $k => $v) {
					$tmp = $this->modx->lexicon($v);
					if ($tmp != $v) {
						$src[] = $matches[0][$k];
						$dst[] = $tmp;
					}
				}
				if (!empty($src) && !empty($dst)) {
					$content = str_replace($src,$dst,$content);
				}

				// Preparing special tags
				preg_match_all('/\<!--'.$this->config['nestedChunkPrefix'].'(.*?)[\s|\n|\r\n](.*?)-->/s', $content, $matches);
				$src = $dst = $placeholders = array();
				foreach ($matches[1] as $k => $v) {
					$src[] = $matches[0][$k];
					$dst[] = '';
					$placeholders[$v] = $matches[2][$k];
				}
				if (!empty($src) && !empty($dst)) {
					$content = str_replace($src,$dst,$content);
				}

				$chunk = array(
					'object' => $element
					,'content' => $content
					,'placeholders' => $placeholders
				);

				$this->addElement($name, $chunk);
			}
			else {
				return false;
			}
		}
		else {
			$chunk = $this->getElement($name);
		}

		if (!empty($properties) && $chunk['object'] instanceof modChunk) {
			$chunk['object']->_processed = false;
			$chunk['object']->_content = '';
			$pl = $this->makePlaceholders($properties);

			// Processing quick placeholders
			$element_pls = $this->getPlaceholders($name);
			if (!empty($element_pls)) {
				$tmp = array_keys($element_pls);
				foreach ($tmp as $v) {
					$properties[$v] = !empty($properties[$v]) ? str_replace($pl['pl'], $pl['vl'], $element_pls[$v]) : '';
				}
				$pl = $this->makePlaceholders($properties);
			}

			$content = str_replace($pl['pl'], $pl['vl'], $chunk['content']);
			$content = str_replace($pl['pl'], $pl['vl'], $content);

			if ($fastMode) {
				$matches = $tags = array();
				$this->modx->parser->collectElementTags($content, $matches);
				foreach ($matches as $v) {
					$tags[] = $v[0];
				}
				$output = str_replace($tags, '', $content);
			}
			else {
				$output = $chunk['object']->process($properties, $content);
			}
		}
		else {
			$output = $chunk['content'];
		}

		return $output;
	}

}
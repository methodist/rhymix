<?php
    /**
     * @class TemplateHandler
     * @author NHN (developers@xpressengine.com)
     * @brief template compiler
     * @version 0.1
     * @remarks It compiles template file by using regular expression into php
     *          code, and XE caches compiled code for further uses
     **/

    class TemplateHandler {

        var $compiled_path = './files/cache/template_compiled/'; ///< path of compiled caches files

        var $path = null; ///< target directory
        var $filename = null; ///< target filename
        var $file = null; ///< target file (fullpath)
		var $xe_path = null;  ///< XpressEngine base path
		var $web_path = null; ///< tpl file web path
		var $compiled_file = null; ///< tpl file web path

		var $handler_mtime = 0;

		function TemplateHandler()
		{
			// TODO: replace this with static variable in PHP5
			global $__templatehandler_root_tpl;

			$__templatehandler_root_tpl = null;

			$this->xe_path  = rtrim(preg_replace('/([^\.^\/]+)\.php$/i','',$_SERVER['SCRIPT_NAME']),'/');
		}

        /**
         * @brief returns TemplateHandler's singleton object
         * @return TemplateHandler instance
         **/
		function &getInstance()
		{
			static $oTemplate = null;

            if(__DEBUG__==3 ) {
                if(!isset($GLOBALS['__TemplateHandlerCalled__'])) $GLOBALS['__TemplateHandlerCalled__']=1;
                else $GLOBALS['__TemplateHandlerCalled__']++;
            }

			if(!$oTemplate) $oTemplate = new TemplateHandler();

            return $oTemplate;
        }

		/**
		 * @brief set variables for template compile
		 **/
		function init($tpl_path, $tpl_filename, $tpl_file='')
		{
            // verify arguments
            if(substr($tpl_path,-1)!='/') $tpl_path .= '/';
			if(!file_exists($tpl_path.$tpl_filename)&&file_exists($tpl_path.$tpl_filename.'.html')) $tpl_filename .= '.html';

            // create tpl_file variable
            if(!$tpl_file) $tpl_file = $tpl_path.$tpl_filename;

			// set template file infos.
			$this->path = $tpl_path;
			$this->filename = $tpl_filename;
			$this->file = $tpl_file;

			$this->web_path = $this->xe_path.'/'.ltrim(preg_replace('@^'.preg_quote(_XE_PATH_,'@').'|\./@','',$this->path),'/');

			// get compiled file name
			$hash = md5($this->file . __ZBXE_VERSION__);
			$this->compiled_file = "{$this->compiled_path}{$hash}.compiled.php";

			// compare various file's modified time for check changed
			$this->handler_mtime = filemtime(__FILE__);
		}

        /**
         * @brief compiles specified tpl file and execution result in Context into resultant content
         * @param[in] $tpl_path path of the directory containing target template file
         * @param[in] $tpl_filename target template file's name
         * @param[in] $tpl_file if specified use it as template file's full path
         * @return Returns compiled result in case of success, NULL otherwise
         */
        function compile($tpl_path, $tpl_filename, $tpl_file='') {
			global $__templatehandler_root_tpl;

			$buff = '';

            // store the starting time for debug information
            if(__DEBUG__==3 ) $start = getMicroTime();

			// initiation
			$this->init($tpl_path, $tpl_filename, $tpl_file);

            // if target file does not exist exit
            if(!$this->file || !file_exists($this->file)) return "Err : '{$this->file}' template file does not exists.";

			// for backward compatibility
			if(is_null($__templatehandler_root_tpl)) {
				$__templatehandler_root_tpl = $this->file;
			}

            $source_template_mtime = filemtime($this->file);
			$latest_mtime = $source_template_mtime>$this->handler_mtime?$source_template_mtime:$this->handler_mtime;

			// cache control
			$oCacheHandler = &CacheHandler::getInstance('template');

			// get cached buff
			if($oCacheHandler->isSupport()){
				$cache_key = 'template:'.$this->file;
				$buff = $oCacheHandler->get($cache_key, $latest_mtime);
			} else {
				if(is_readable($this->compiled_file) && filemtime($this->compiled_file)>$latest_mtime && filesize($this->compiled_file)) {
					$buff = 'file://'.$this->compiled_file;
				}
			}

			if(!$buff) {
				$buff = $this->parse();
				if($oCacheHandler->isSupport()) $oCacheHandler->put($cache_key, $buff);
				else FileHandler::writeFile($this->compiled_file, $buff);
			}

			$output = $this->_fetch($buff);

			if($__templatehandler_root_tpl == $this->file) {
				$__templatehandler_root_tpl = null;
			}

			// store the ending time for debug information
            if(__DEBUG__==3 ) $GLOBALS['__template_elapsed__'] += getMicroTime() - $start;

            return $output;
        }

        /**
         * @brief compile specified file and immediately return
         * @param[in] $tpl_path path of the directory containing target template file
         * @param[in] $tpl_filename target template file's name
         * @return Returns compiled content in case of success or NULL in case of failure
         **/
        function compileDirect($tpl_path, $tpl_filename) {
			$this->init($tpl_path, $tpl_filename, null);

            // if target file does not exist exit
            if(!$this->file || !file_exists($this->file)) {
				Context::close();
				exit("Cannot find the template file: '{$this->file}'");
			}

			return $this->parse();
        }

        /**
         * @brief compile a template file specified in $tpl_file and
         * @pre files specified by $tpl_file exists.
         * @param[in] $tpl_file path of tpl file
         * @param[in] $compiled_tpl_file if specified, write compiled result into the file
         * @return compiled result in case of success or NULL in case of error
         **/
        function parse($buff=null) {
			if(is_null($buff)) {
				if(!is_readable($this->file)) return;

				// read tpl file
				$buff = FileHandler::readFile($this->file);
			}

			// replace comments
			$buff = preg_replace('@<!--//.*?-->@', '', $buff);

			// replace value of src in img/input/script tag
			$buff = preg_replace_callback('/<(?:img|input|script)(?:(?!["\'\/]\s*>).)* src="(?!https?:\/\/|[\/\{])([^"]+)"/is', array($this, '_replacePath'), $buff);

			// replace loop and cond template syntax
			$buff = $this->_parseInline($buff);

			// include, unload/load, import
			$buff = preg_replace_callback('/{(@[\s\S]+?|(?=\$\w+|__[A-Z]+|\w+(?:\(|::)|\d+|[\'"].*?[\'"]).+?)}|<(!--[#%])?(include|import|(un)?load(?(4)|(?:_js_plugin)?))(?(2)\("([^"]+)")(.*?)(?(2)\)--|\/)>|<!--(@[a-z@]+)(.*?)-->/', array($this, '_parseResource'), $buff);

			// remove block which is a virtual tag and remove comments
			$buff = preg_replace('@</?block\s*>|\s?<!--//(.*?)-->@is','',$buff);

			// form auto generation
			$buff = preg_replace_callback('/(<form(?:<\?php.+?\?>|[^<>]+)*?>)(.*?)(<\/form>)/is', array($this, '_compileFormAuthGeneration'), $buff);

            // prevent from calling directly before writing into file
            $buff = '<?php if(!defined("__XE__"))exit;?>'.$buff;

			return $buff;
        }

		/**
		 * @brief 1. remove ruleset from form tag
		 * 2. add hidden tag with ruleset value
		 * 3. if empty default hidden tag, generate hidden tag (ex:mid, vid, act...)
		 * 4. generate return url, return url use in server side validator
		 **/
		function _compileFormAuthGeneration($matches)
		{
			// form ruleset attribute move to hidden tag
			if($matches[1])
			{
				preg_match('/ruleset="([^"]*?)"/is', $matches[1], $m);
				if($m[0])
				{
					$matches[1] = preg_replace('/'.$m[0].'/i', '', $matches[1]);
					$matches[2] = '<input type="hidden" name="ruleset" value="'.$m[1].'" />'.$matches[2];

					if (strpos($m[1],'@') !== false){
						$path = str_replace('@', '', $m[1]);
						$path = './files/ruleset/'.$path.'.xml';
					}else if(preg_match('@(?:^|\.?/)(modules/[\w-]+)@', $this->path, $mm)) {
						$module_path = $mm[1];
						$path = $module_path.'/ruleset/'.$m[1].'.xml';
					}
					//assign to addJsFile method for js dynamic recache
					$matches[1]  = '<?php Context::addJsFile("'.$path.'", false, "", 0, "head", true) ?'.'>'.$matches[1];
				}
			}

			// if not exists default hidden tag, generate hidden tag
			preg_match_all('/<input[^>]* name="(act|mid|vid)"/is', $matches[2], $m2);
			$checkVar = array('act', 'mid', 'vid');
			$resultArray = array_diff($checkVar, $m2[1]);
			if(is_array($resultArray))
			{
				$generatedHidden = '';
				foreach($resultArray AS $key=>$value)
				{
					$generatedHidden .= '<input type="hidden" name="'.$value.'" value="<?php echo $'.$value.' ?>">';
				}
				$matches[2] = $generatedHidden.$matches[2];
			}

			// return url generate
			preg_match('/<input[^>]*name="error_return_url"[^>]*>/is', $matches[2], $m3);
			if(!$m3[0]) $matches[2] = '<input type="hidden" name="error_return_url" value="<?php echo getRequestUriByServerEnviroment() ?>" />'.$matches[2];

			$matches[0] = '';
			return implode($matches);
		}

        /**
         * @brief fetch using ob_* function
         * @param[in] $compiled_tpl_file path of compiled template file
         * @param[in] $buff if buff is not null, eval it instead of including compiled template file
         * @param[in] $tpl_path set context's tpl path
         * @return result string
         **/
        function _fetch($buff) {
			if(!$buff) return;

            $__Context = &$GLOBALS['__Context__'];
            $__Context->tpl_path = $this->path;

			if($_SESSION['is_logged']) {
				$__Context->logged_info = Context::get('logged_info');
			}

            ob_start();
			if(substr($buff, 0, 7) == 'file://') {
				include(substr($buff, 7));
			} else {
				$eval_str = "?>".$buff;
				eval($eval_str);
			}

            return ob_get_clean();
        }

        /**
         * @brief change image path
         * @pre $matches is an array containg three elements
         * @param[in] $matches match
         * @return changed result
         **/
		function _replacePath($match)
		{
			$src = preg_replace('@^(\./)+@', '', trim($match[1]));

			$src = $this->web_path.$src;
			$src = str_replace('/./', '/', $src);

			// for backward compatibility
			$src = preg_replace('@((?:[\w-]+/)+)\1@', '\1', $src);

			while(($tmp=preg_replace('@[^/]+/\.\./@', '', $src))!==$src) $src = $tmp;

			return substr($match[0],0,-strlen($match[1])-6)."src=\"{$src}\"";
		}

		function _parseInline($buff)
		{
			if(preg_match_all('/<([a-zA-Z]+\d?)(?>(?!<[a-z]+\d?[\s>]).)*?(?:[ \|]cond| loop)="/s', $buff, $match) === false) return $buff;

			$tags = '(?:'.implode('|',array_unique($match[1])).')';
			$split_regex = "@(<(?>/?{$tags})(?>[^<>\{\}\"']+|<!--.*?-->|{[^}]+}|\".*?\"|'.*?'|.)*?>)@s";

			$nodes = preg_split($split_regex, $buff, -1, PREG_SPLIT_DELIM_CAPTURE);

			// list of self closing tags
			$self_closing = explode(',', 'area,base,basefont,br,hr,input,img,link,meta,param,frame,col');

			for($idx=1,$node_len=count($nodes); $idx < $node_len; $idx+=2) {
				if(!($node=$nodes[$idx])) continue;

				if(preg_match_all('@\s(loop|cond)="([^"]+)"@', $node, $matches)) {
					$closing = 0;

					// process opening tag
					foreach($matches[1] as $n=>$stmt) {
						$expr = $matches[2][$n];
						$expr = $this->_replaceVar($expr);
						$closing++;

						switch($stmt) {
						case 'cond':
							$nodes[$idx-1] .= "<?php if({$expr}){ ?>";
							break;
						case 'loop':
							if(!preg_match('@^(?:(.+?)=>(.+?)(?:,(.+?))?|(.*?;.*?;.*?)|(.+?)\s*=\s*(.+?))$@', $expr, $expr_m)) break;
							if($expr_m[1]) {
								if($expr_m[3]) $expr_m[2] .= '=>'.$expr_m[3];
								$nodes[$idx-1] .= "<?php if({$expr_m[1]}&&count({$expr_m[1]}))foreach({$expr_m[1]} as {$expr_m[2]}){ ?>";
							}elseif($expr_m[4]) {
								$nodes[$idx-1] .= "<?php for({$expr_m[4]}){ ?>";
							}elseif($expr_m[5]) {
								$nodes[$idx-1] .= "<?php while({$expr_m[5]}={$expr_m[6]}){ ?>";
							}
							break;
						}
					}
					$node = preg_replace('@\s(loop|cond)="([^"]+)"@', '', $node);

					// this tag
					$tag = substr($node, 1, strpos($node, ' ')-1);

					// find closing tag
					$close_php = '<?php '.str_repeat('}', $closing).' ?>';
					if($node{1} == '!' || substr($node,-2,1) == '/' || in_array($tag, $self_closing)) { //  self closing tag
						$nodes[$idx+1] = $close_php.$nodes[$idx+1];
					} else {
						$depth = 1;
						for($i=$idx+2; $i < $node_len; $i+=2) {
							$nd = $nodes[$i];
							if(strpos($nd, $tag) === 1) {
								$depth++;
							} elseif(strpos($nd, '/'.$tag) === 1) {
								$depth--;
								if(!$depth) {
									$nodes[$i-1] .= $nodes[$i].$close_php;
									$nodes[$i] = '';
									break;
								}
							}
						}
					}
				}

				if(strpos($node, '|cond="') !== false) {
 					$node = preg_replace('@(\s[\w:]+="[^"]+?")\|cond="(.+?)"@s', '<?php if($2){ ?>$1<?php } ?>', $node);
					$node = $this->_replaceVar($node);
				}

				if($nodes[$idx] != $node) $nodes[$idx] = $node;
			}

			$buff = implode('', $nodes);

			return $buff;
		}

		function _parseResource($m)
		{
			// {@ ... } or {$var} or {func(...)}
			if($m[1])
			{
				$echo = 'echo ';
				if($m[1]{0} == '@') {
					$echo = '';
					$m[1] = substr($m[1], 1);
				}
				return '<?php '.$echo.$this->_replaceVar($m[1]).' ?>';
			}

			if($m[3])
			{
				$attr = array();
				if($m[5]) {
					if(preg_match_all('@,(\w+)="([^"]+)"@', $m[6], $mm)) {
						foreach($mm[1] as $idx=>$name) {
							$attr[$name] = $mm[2][$idx];
						}
					}
					$attr['target'] = $m[5];
				} else {
					if(!preg_match_all('@ (\w+)="([^"]+)"@', $m[6], $mm)) return $m[0];
					foreach($mm[1] as $idx=>$name) {
						$attr[$name] = $mm[2][$idx];
					}
				}

				switch($m[3])
				{
					// <!--#include--> or <include ..>
					case 'include':
						if(!$this->file || !$attr['target']) return '';

						$pathinfo = pathinfo($attr['target']);
						$fileDir  = $this->_getRelativeDir($pathinfo['dirname']);

						if(!$fileDir) return '';

						return "<?php echo TemplateHandler::getInstance()->compile('{$fileDir}','{$pathinfo['basename']}') ?>";

					// <!--%load_js_plugin-->
					case 'load_js_plugin': 
						$plugin = $this->_replaceVar($m[5]);
						if(strpos($plugin, '$__Context') === false) $plugin = "'{$plugin}'";

						return "<?php Context::loadJavascriptPlugin({$plugin}); ?>";

					// <load ...> or <unload ...> or <!--%import ...--> or <!--%unload ...-->
					case 'import':
					case 'load':
					case 'unload':
						$metafile = '';
						$pathinfo = pathinfo($attr['target']);
						$doUnload = ($m[3] === 'unload');
						$isRemote = !!preg_match('@^https?://@i', $attr['target']);

						if(!$isRemote) {
							if(!preg_match('@^\.?/@',$attr['target'])) $attr['target'] = './'.$attr['target'];

							$relativeDir = $this->_getRelativeDir($pathinfo['dirname']);

							$attr['target'] = $relativeDir.'/'.$pathinfo['basename'];
						}

						switch($pathinfo['extension'])
						{
							case 'xml':
								if($isRemote || $doUnload) return '';
								// language file?
								if($pathinfo['basename'] == 'lang.xml' && substr($pathinfo['dirname'],-5) == '/lang') {
									$result = "Context::loadLang('{$relativeDir}');";
								} else {
									$result = "require_once('./classes/xml/XmlJsFilter.class.php');\$__xmlFilter = new XmlJsFilter('{$relativeDir}','{$pathinfo['basename']}');\$__xmlFilter->compile();";
								}
								break;
							case 'js':
								if($doUnload) {
									$result = "Context::unloadFile('{$attr['target']}','{$attr['targetie']}');";
								} else {
									$metafile = $attr['target'];
									$result = "\$__tmp=array('{$attr['target']}','{$attr['type']}','{$attr['targetie']}','{$attr['index']}');Context::loadFile(\$__tmp,'{$attr['usecdn']}','{$attr['cdnprefix']}','{$attr['cdnversion']}');unset(\$__tmp);";
								}
								break;
							case 'css':
								if($doUnload) {
									$result = "Context::unloadFile('{$attr['target']}','{$attr['targetie']}','{$attr['media']}');";
								} else {
									$metafile = $attr['target'];
									$result = "\$__tmp=array('{$attr['target']}','{$attr['media']}','{$attr['targetie']}','{$attr['index']}');Context::loadFile(\$__tmp,'{$attr['usecdn']}','{$attr['cdnprefix']}','{$attr['cdnversion']}');unset(\$__tmp);";
								}
								break;
						}

						$result = "<?php {$result} ?>";
						if($metafile) $result = "<!--#Meta:{$metafile}-->".$result;

						return $result;
				}
			}

			// <!--@..--> such as <!--@if($cond)-->, <!--@else-->, <!--@end-->
			if($m[7])
			{
				$m[7] = substr($m[7],1);
				if(!preg_match('/^(?:((?:end)?(?:if|switch|for(?:each)?|while)|end)|(else(?:if)?)|(break@)?(case|default)|(break))$/', $m[7], $mm)) return '';
				if($mm[1]) {
					if($mm[1]{0} == 'e') return '<?php } ?>';

					$precheck = '';
					if($mm[1] == 'foreach') {
						$var = preg_replace('/^\s*\(\s*(.+?) .*$/', '$1', $m[8]);
						$precheck = "if({$var}&&count({$var}))";
					}
					return '<?php '.$this->_replaceVar($precheck.$m[7].$m[8]).'{ ?>';
				}
				if($mm[2]) return "<?php }{$m[7]}".$this->_replaceVar($m[8])."{ ?>";
				if($mm[4]) return "<?php ".($mm[3]?'break;':'')."{$m[7]} ".trim($m[8],'()').": ?>";
				if($mm[5]) return "<?php break; ?>";
				return '';
			}

			return $m[0];
		}

		function _getRelativeDir($path)
		{
			$_path = $path;

			$fileDir = strtr(realpath(dirname($this->file)),'\\','/');
			if($path{0} != '/') $path = strtr(realpath($fileDir.'/'.$path),'\\','/');

			// for backward compatibility
			if(!$path) {
				$dirs  = explode('/', $fileDir);
				$paths = explode('/', $_path);
				$idx   = array_search($paths[0], $dirs);

				if($idx !== false) {
					while($dirs[$idx] && $dirs[$idx] === $paths[0]) {
						array_splice($dirs, $idx, 1);
						array_shift($paths);
					}
					$path = strtr(realpath($fileDir.'/'.implode('/', $paths)),'\\','/');
				}
			}

			$path = preg_replace('/^'.preg_quote(_XE_PATH_,'/').'/', '', $path);

			return $path;
		}

		/**
		 * @brief replace PHP variables of $ character
		 **/
		function _replaceVar($php) {
			if(!$php) return '';
			return preg_replace('@(?<!::|\\\\)\$([a-z]|_[a-z0-9])@i', '\$__Context->$1', $php);
		}
    }
?>

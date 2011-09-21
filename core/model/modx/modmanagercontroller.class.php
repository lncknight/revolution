<?php
/**
 * @package modx
 */
/**
 * Abstract class for manager controllers. Not to be initialized directly; must be extended by the implementing
 * controller.
 * 
 * @package modx
 */
abstract class modManagerController {
    /** @var modX A reference to the modX object */
    public $modx;
    /** @var array A configuration array of options related to this controller's action object. */
    public $config = array();
    /** @var bool Set to false to prevent loading of the header HTML. */
    public $loadHeader = true;
    /** @var bool Set to false to prevent loading of the footer HTML. */
    public $loadFooter = true;
    /** @var bool Set to false to prevent loading of the base MODExt JS classes. */
    public $loadBaseJavascript = true;
    /** @var array An array of possible paths to this controller's templates directory. */
    public $templatesPaths;
    /** @var array An array of possible paths to this controller's directory. */
    public $controllersPaths;
    /** @var The current working context. */
    public $workingContext;
    /** @var string The current output content */
    public $content = '';
    /** @var array An array of request parameters sent to the controller */
    public $scriptProperties = array();
    /** @var array An array of css/js/html to load into the HEAD of the page */
    public $head = array('css' => array(),'js' => array(),'html' => array(),'lastjs' => array());
    /** @var array An array of placeholders that are being set to the page */
    public $placeholders = array();

    /** @var string Any Form Customization rule output that was created. */
    protected $ruleOutput = array();
    /** @var string The current manager theme. */
    protected $theme = 'default';
    /** @var string The pagetitle for this controller. */
    protected $title = '';
    /** @var bool Whether or not a failure message was sent by this controller. */
    protected $isFailure = false;
    /** @var string The failure message, if existent, for this controller. */
    protected $failureMessage = '';

    /**
     * The constructor for the modManaagerController class.
     *
     * @param modX $modx A reference to the modX object.
     * @param array $config A configuration array of options related to this controller's action object.
     */
    function __construct(modX &$modx,$config = array()) {
        $this->modx =& $modx;
        $this->config = !empty($config) && is_array($config) ? $config : array();
    }

    /**
     * Can be used to provide custom methods prior to processing
     * @return void
     */
    public function initialize() {}

    /**
     * Return the proper instance of the derived class. This can be used to override how the manager loads a controller
     * class; for example, when handling derivative classes with class_key settings.
     * 
     * @static
     * @param modX $modx A reference to the modX object.
     * @param string $className The name of the class that is being requested.
     * @param array $config A configuration array of options related to this controller's action object.
     * @return The class specified by $className
     */
    public static function getInstance(modX &$modx,$className,$config = array()) {
        /** @var modManagerController $controller */
        $controller = new $className($modx,$config);
        return $controller;
    }

    /**
     * Sets the properties array for this controller
     * @param array $properties
     * @return void
     */
    public function setProperties(array $properties) {
        $this->scriptProperties = $properties;
    }

    /**
     * Set a property for this controller
     * @param string $key
     * @param mixed $value
     * @return void
     */
    public function setProperty($key,$value) {
        $this->scriptProperties[$key] = $value;
    }

    /**
     * Render the controller.
     * 
     * @return string
     */
    public function render() {
        if (!$this->checkPermissions()) {
            return $this->modx->error->failure($this->modx->lexicon('access_denied'));
        }

        $this->theme = $this->modx->getOption('manager_theme',null,'default');
        
        $this->modx->lexicon->load('action');
        $languageTopics = $this->getLanguageTopics();
        foreach ($languageTopics as $topic) { $this->modx->lexicon->load($topic); }
        $this->setPlaceholder('_lang_topics',implode(',',$languageTopics));
        $this->setPlaceholder('_lang',$this->modx->lexicon->fetch());
        $this->setPlaceholder('_ctx',$this->modx->context->get('key'));


        $this->loadControllersPath();
        $this->loadTemplatesPath();
        $content = '';

        $this->registerBaseScripts();

        $this->checkFormCustomizationRules();

        $this->setPlaceholder('_config',$this->modx->config);

        $this->modx->invokeEvent('OnBeforeManagerPageInit',array(
            'action' => $this->config,
        ));
        $placeholders = $this->process($this->scriptProperties);
        if (!$this->isFailure && !empty($placeholders) && is_array($placeholders)) {
            $this->setPlaceholders($placeholders);
        } elseif (!empty($placeholders)) {
            $content = $placeholders;
        }
        if (!$this->isFailure) {
            $this->loadCustomCssJs();
        }
        $this->firePreRenderEvents();

        /* handle FC rules */
        if (!empty($this->ruleOutput)) {
            $this->addHtml(implode("\n",$this->ruleOutput));
        }

        /* register CSS/JS */
        $this->registerCssJs();

        $this->setPlaceholder('_pagetitle',$this->getPageTitle());

        $this->content = '';
        if ($this->loadHeader) {
            $this->content .= $this->getHeader();
        }

        $tpl = $this->getTemplateFile();
        if ($this->isFailure) {
            $this->setPlaceholder('_e', $this->modx->error->failure($this->failureMessage));
            $content = $this->fetchTemplate('error.tpl');
        } else if (!empty($tpl)) {
            $content = $this->fetchTemplate($tpl);
        }

        $this->content .= $content;

        if ($this->loadFooter) {
            $this->content .= $this->getFooter();
        }

        $this->firePostRenderEvents();

        return $this->content;
    }

    /**
     * @return void
     */
    protected function assignPlaceholders() {
        foreach ($this->placeholders as $k => $v) {
            $this->modx->smarty->assign($k,$v);
        }
    }

    /**
     * Set a placeholder for this controller's template
     *
     * @param string $k The key of the placeholder
     * @param mixed $v The value of the placeholder
     * @return void
     */
    public function setPlaceholder($k,$v) {
        $this->placeholders[$k] = $v;
        $this->modx->smarty->assign($k,$v);
    }

    /**
     * Set an array of placeholders
     *
     * @param array $keys
     * @return void
     */
    public function setPlaceholders($keys) {
        foreach ($keys as $k => $v) {
            $this->placeholders[$k] = $v;
            $this->modx->smarty->assign($k,$v);
        }
    }

    /**
     * Get all the set placeholders
     * @return array
     */
    public function getPlaceholders() {
        return $this->placeholders;
    }

    /**
     * Get a specific placeholder set
     * @param string $k
     * @param mixed $default
     * @return mixed
     */
    public function getPlaceholder($k,$default = null) {
        return isset($this->placeholders[$k]) ? $this->placeholders[$k] : $default;
    }

    /**
     * Fetch the template content
     * @param string $tpl The path to the template
     * @return string The output of the template
     */
    public function fetchTemplate($tpl) {
        $templatePath = '';
        foreach ($this->templatesPaths as $path) {
            if (file_exists($path.$tpl)) {
                $templatePath = $path;
                break;
            }
        }
        $this->modx->smarty->setTemplatePath($templatePath);
        return $this->modx->smarty->fetch($tpl);
    }

    /**
     * Load another manual controller file (such as header/footer)
     *
     * @param $controller
     * @param bool $coreOnly
     * @return mixed|string
     */
    public function loadController($controller,$coreOnly = false) {
        /** @var modX $modx */
        $modx =& $this->modx;
        $paths = $this->getControllersPaths($coreOnly);
        $o = '';
        foreach ($paths as $path) {
            if (file_exists($path.$controller)) {
                $o = include_once $path.$controller;
                break;
            }
        }
        return $o;
    }

    /**
     * Set a failure on this controller. This will return the error message.
     * 
     * @param string $message
     * @return void
     */
    public function failure($message) {
        $this->isFailure = true;
        $this->failureMessage .= $message;
    }

    /**
     * Load the path to this controller's template's directory. Only override this if you want to override default
     * behavior; otherwise, overriding getTemplatesPath is preferred.
     * 
     * @return string
     */
    public function loadTemplatesPath() {
        if (empty($this->templatesPaths)) {
            $templatesPaths = $this->getTemplatesPaths();
            if (is_string($templatesPaths)) {
                $templatesPaths = array($templatesPaths);
            }
            $this->setTemplatePaths($templatesPaths);
        }
        return $this->templatesPaths;
    }

    /**
     * Set the possible template paths for this controller
     * 
     * @param array $paths
     * @return void
     */
    public function setTemplatePaths(array $paths) {
        $this->templatesPaths = $paths;
    }

    /**
     * Load an array of possible paths to this controller's directory. Only override this if you want to override
     * default behavior; otherwise, overriding getControllersPath is preferred.
     * 
     * @return array
     */
    public function loadControllersPath() {
        if (empty($this->controllersPaths)) {
            $this->controllersPaths = $this->getControllersPaths();
        }
        return $this->controllersPaths;
    }


    /**
     * Get the path to this controller's directory. Override this to point to a custom directory.
     *
     * @param bool $coreOnly Ensure that it grabs the path from the core namespace only.
     * @return array
     */
    public function getControllersPaths($coreOnly = false) {
        if ($this->config['namespace'] != 'core' && !$coreOnly) { /* for non-core controllers */
            $managerPath = $this->modx->getOption('manager_path',null,MODX_MANAGER_PATH);
            $paths[] = $this->config['namespace_path'].'controllers/'.$this->theme.'/';
            $paths[] = $this->config['namespace_path'].'controllers/default/';
            $paths[] = $this->config['namespace_path'].'controllers/';
            $paths[] = $this->config['namespace_path'].$this->theme.'/';
            $paths[] = $this->config['namespace_path'].'default/';
            $paths[] = $this->config['namespace_path'];
            $paths[] = $managerPath.'controllers/'.$this->theme.'/';
            $paths[] = $managerPath.'controllers/default/';

        } else { /* for core controllers only */
            $managerPath = $this->modx->getOption('manager_path',null,MODX_MANAGER_PATH);
            $paths[] = $managerPath.'controllers/'.$this->theme.'/';
            $paths[] = $managerPath.'controllers/default/';
        }
        return $paths;
    }
    
    /**
     * Get an array of possible paths to this controller's template's directory.
     * Override this to point to a custom directory.
     * 
     * @param bool $coreOnly Ensure that it grabs the path from the core namespace only.
     * @return array|string
     */
    public function getTemplatesPaths($coreOnly = false) {
        $namespacePath = $this->modx->getOption('manager_path',null,MODX_MANAGER_PATH);
        /* extras */
        if ($this->config['namespace'] != 'core' && !$coreOnly) {
            $paths[] = $namespacePath . 'templates/'.$this->theme.'/';
            $paths[] = $namespacePath . 'templates/default/';
            $paths[] = $namespacePath . 'templates/';
        } else { /* core */
            $managerPath = $this->modx->getOption('manager_path',null,MODX_MANAGER_PATH);
            $paths[] = $managerPath . 'templates/'.$this->theme.'/';
            $paths[] = $managerPath . 'templates/default/';
        }
        return $paths;
    }

    /**
     * Do permission checking in this method. Returning false will present a "permission denied" message.
     * 
     * @abstract
     * @return boolean
     */
    abstract public function checkPermissions();

    /**
     * Process the controller, returning an array of placeholders to set.
     *
     * @abstract
     * @param array $scriptProperties A array of REQUEST parameters.
     * @return mixed Either an error or output string, or an array of placeholders to set.
     */
    abstract public function process(array $scriptProperties = array());

    /**
     * Return a string to set as the controller's page title.
     * 
     * @abstract
     * @return string
     */
    abstract public function getPageTitle();

    /**
     * Register any custom CSS or JS in this method.
     * @abstract
     * @return void
     */
    abstract public function loadCustomCssJs();

    /**
     * Return the relative path to the template file to load
     * @abstract
     * @return string
     */
    abstract public function getTemplateFile();

    /**
     * Specify an array of language topics to load for this controller
     * 
     * @return array
     */
    public function getLanguageTopics() {
        return array();
    }

    /**
     * Can be used to fire events after all the CSS/JS is loaded for a page
     * @return void
     */
    public function firePostRenderEvents() {}
    
    /**
     * Fire any pre-render events for the controller
     * @return void
     */
    public function firePreRenderEvents() {}

    /**
     * Get the page header for the controller.
     * 
     * @return string
     */
    public function getHeader() {
        $this->loadController('header.php',true);
        return $this->fetchTemplate('header.tpl');
    }

    /**
     * Get the page footer for the controller.
     * @return string
     */
    public function getFooter() {
        $this->loadController('footer.php',true);
        return $this->fetchTemplate('footer.tpl');
    }

    /**
     * Registers the core and base JS scripts
     *
     * @access public
     * @return void
     */
    public function registerBaseScripts() {
        $managerUrl = $this->modx->getOption('manager_url');
        $externals = array();
        $externals2 = array();


        if ($this->loadBaseJavascript) {
            if ($this->modx->getOption('concat_js',null,false)) {
                $externals[] = $managerUrl.'assets/modext/modext.js';
            } else {
                $externals[] = $managerUrl.'assets/modext/core/modx.localization.js';
                $externals[] = $managerUrl.'assets/modext/util/utilities.js';

                $externals[] = $managerUrl.'assets/modext/core/modx.component.js';
                $externals[] = $managerUrl.'assets/modext/widgets/core/modx.panel.js';
                $externals[] = $managerUrl.'assets/modext/widgets/core/modx.tabs.js';
                $externals[] = $managerUrl.'assets/modext/widgets/core/modx.window.js';
                $externals[] = $managerUrl.'assets/modext/widgets/core/modx.tree.js';
                $externals[] = $managerUrl.'assets/modext/widgets/core/modx.combo.js';
                $externals2[] = $managerUrl.'assets/modext/widgets/core/modx.grid.js';
                $externals2[] = $managerUrl.'assets/modext/widgets/core/modx.console.js';
                $externals2[] = $managerUrl.'assets/modext/widgets/core/modx.portal.js';
                $externals2[] = $managerUrl.'assets/modext/widgets/modx.treedrop.js';
                $externals2[] = $managerUrl.'assets/modext/widgets/windows.js';

                $externals2[] = $managerUrl.'assets/modext/widgets/resource/modx.tree.resource.js';
                $externals2[] = $managerUrl.'assets/modext/widgets/element/modx.tree.element.js';
                $externals2[] = $managerUrl.'assets/modext/widgets/system/modx.tree.directory.js';
                $externals2[] = $managerUrl.'assets/modext/core/modx.view.js';
            }
            
            $siteId = $_SESSION["modx.{$this->modx->context->get('key')}.user.token"];

            $externals2[] = $managerUrl.'assets/modext/core/modx.layout.js';

            $o = '';
            if ($this->modx->getOption('compress_js',null,true)) {
                if (!empty($externals)) {
                    $minDir = $this->modx->getOption('manager_url',null,MODX_MANAGER_URL).'min/';
                    $o .= '<script type="text/javascript" src="'.$minDir.'?f='.implode(',',$externals).'"></script>';
                    $o .= '<script type="text/javascript" src="'.$minDir.'?f='.implode(',',$externals2).'"></script>';
                }
                $this->modx->setOption('compress_js',true);
            } else {
                $files = array_merge($externals,$externals2);
                foreach ($files as $js) {
                    $o .= '<script type="text/javascript" src="'.$js.'"></script>'."\n";
                }
            }
            if ($this->modx->getOption('compress_css',null,true)) {
                $this->modx->setOption('compress_css',true);
            }

            $o .= '<script type="text/javascript">Ext.onReady(function() {
    MODx.load({xtype: "modx-layout",accordionPanels: MODx.accordionPanels || [],auth: "'.$siteId.'"});
});</script>';
            $this->modx->smarty->assign('maincssjs',$o);
        }
    }
    
    /**
     * Grabs a stripped version of modx to prevent caching of JS after upgrades
     *
     * @access private
     * @return string The parsed version string
     */
    private function _prepareVersionPostfix() {
        $version = $this->modx->getVersionData();
        return str_replace(array('.','-'),'',$version['full_version']);
    }

    /**
     * Appends a version postfix to a script tag
     *
     * @access private
     * @param string $str The script tag to append the version to
     * @param string $version The version to append
     * @return string The adjusted script tag
     */
    private function _postfixVersionToScript($str,$version) {
        $pos = strpos($str,'.js');
        $pos2 = strpos($str,'src="'); /* only apply to externals */
        if ($pos && $pos2) {
            $s = substr($str,0,strpos($str,'"></script>'));
            if (!empty($s) && substr($s,strlen($s)-3,strlen($s)) == '.js') {
                $str = $s.'?v='.$version.'"></script>';
            }
        }
        return $str;
    }

    /**
     * Registers CSS/JS to manager interface
     */
    public function registerCssJs() {
        $this->_prepareHead();
        $versionPostFix = $this->_prepareVersionPostfix();

        $jsToCompress = array();
        foreach ($this->head['js'] as $js) {
            $jsToCompress[] = $js;
        }
        $cssjs = array();
        if (!empty($jsToCompress)) {
            if ($this->modx->getOption('compress_js',null,true)) {
                $cssjs[] = '<script src="'.$this->modx->getOption('manager_url',null,MODX_MANAGER_URL).'min/?f='.implode(',',$jsToCompress).'" type="text/javascript"></script>';
            } else {
                foreach ($jsToCompress as $scr) {
                    $cssjs[] = '<script src="'.$scr.'" type="text/javascript"></script>';
                }
            }
        }

        $cssToCompress = array();
        foreach ($this->head['css'] as $css) {
            $cssToCompress[] = $css;
        }
        if (!empty($cssToCompress)) {
            if ($this->modx->getOption('compress_css',null,true)) {
                $cssjs[] = '<link href="'.$this->modx->getOption('manager_url',null,MODX_MANAGER_URL).'min/?f='.implode(',',$cssToCompress).'" rel="stylesheet" type="text/css" />';
            } else {
                foreach ($cssToCompress as $scr) {
                    $cssjs[] = '<link href="'.$scr.'" rel="stylesheet" type="text/css" />';
                }
            }
        }

        foreach ($this->head['html'] as $html) {
            $cssjs[] = $html;
        }

        foreach ($this->modx->sjscripts as $scr) {
            $scr = $this->_postfixVersionToScript($scr,$versionPostFix);
            $cssjs[] = $scr;
        }


        $lastjs = array();
        foreach ($this->head['lastjs'] as $js) {
            $lastjs[] = $js;
        }
        if (!empty($lastjs)) {
            if ($this->modx->getOption('compress_js',null,true)) {
                $cssjs[] = '<script type="text/javascript" src="'.$this->modx->getOption('manager_url',null,MODX_MANAGER_URL).'min/?f='.implode(',',$lastjs).'"></script>';
            } else {
                foreach ($lastjs as $scr) {
                    $cssjs[] = '<script src="'.$scr.'" type="text/javascript"></script>';
                }
            }
        }

        
        $this->modx->smarty->assign('cssjs',$cssjs);
    }

    /**
     * Prepare the set html/css/js to be added
     * @return void
     */
    private function _prepareHead() {
        $this->head['js'] = array_unique($this->head['js']);
        $this->head['html'] = array_unique($this->head['html']);
        $this->head['css'] = array_unique($this->head['css']);
        $this->head['lastjs'] = array_unique($this->head['lastjs']);
    }

    /**
     * Add an external Javascript file to the head of the page
     *
     * @param string $script
     * @return void
     */
    public function addJavascript($script) {
        $this->head['js'][] = $script;
    }

    /**
     * Add a block of HTML to the head of the page
     *
     * @param string $script
     * @return void
     */
    public function addHtml($script) {
        $this->head['html'][] = $script;
    }

    /**
     * Add a external CSS file to the head of the page
     * @param string $script
     * @return void
     */
    public function addCss($script) {
        $this->head['css'][] = $script;
    }
    /**
     * Add an external Javascript file to the head of the page
     *
     * @param string $script
     * @return void
     */
    public function addLastJavascript($script) {
        $this->head['lastjs'][] = $script;
    }


    /**
     * Checks Form Customization rules for an object.
     *
     * @param xPDOObject $obj If passed, will validate against for rules with constraints.
     * @param bool $forParent
     * @return bool
     */
    public function checkFormCustomizationRules(&$obj = null,$forParent = false) {
        $overridden = array();

        $userGroups = $this->modx->user->getUserGroups();
        $c = $this->modx->newQuery('modActionDom');
        $c->innerJoin('modFormCustomizationSet','FCSet');
        $c->innerJoin('modFormCustomizationProfile','Profile','FCSet.profile = Profile.id');
        $c->leftJoin('modFormCustomizationProfileUserGroup','ProfileUserGroup','Profile.id = ProfileUserGroup.profile');
        $c->leftJoin('modFormCustomizationProfile','UGProfile','UGProfile.id = ProfileUserGroup.profile');
        $c->where(array(
            'modActionDom.action' => $this->config['id'],
            'modActionDom.for_parent' => $forParent,
            'FCSet.active' => true,
            'Profile.active' => true,
        ));
        $c->where(array(
            array(
                'ProfileUserGroup.usergroup:IN' => $userGroups,
                array(
                    'OR:ProfileUserGroup.usergroup:IS' => null,
                    'AND:UGProfile.active:=' => true,
                ),
            ),
            'OR:ProfileUserGroup.usergroup:=' => null,
        ),xPDOQuery::SQL_AND,null,2);
        $c->select($this->modx->getSelectColumns('modActionDom', 'modActionDom'));
        $c->select($this->modx->getSelectColumns('modFormCustomizationSet', 'FCSet', '', array(
            'constraint_class',
            'constraint_field',
            'constraint',
            'template'
        )));
        $c->sortby('modActionDom.rank','ASC');
        $domRules = $this->modx->getCollection('modActionDom',$c);
        $rules = array();
        foreach ($domRules as $rule) {
            $template = $rule->get('template');
            if (!empty($template) && $obj) {
                if ($template != $obj->get('template')) continue;
            }
            $constraintClass = $rule->get('constraint_class');
            if (!empty($constraintClass)) {
                if (empty($obj) || !($obj instanceof $constraintClass)) continue;
                $constraintField = $rule->get('constraint_field');
                $constraint = $rule->get('constraint');
                if ($obj->get($constraintField) != $constraint) {
                    continue;
                }
            }
            if ($rule->get('rule') == 'fieldDefault') {
                $field = $rule->get('name');
                if ($field == 'modx-resource-content') $field = 'content';
                $overridden[$field] = $rule->get('value');
                if ($field == 'parent-cmb') {
                    $overridden['parent'] = (int)$rule->get('value');
                    $overridden['parent-cmb'] = (int)$rule->get('value');
                }
            }
            $r = $rule->apply();
            if (!empty($r)) $rules[] = $r;
        }
        if (!empty($rules)) {
            $this->ruleOutput[] = '<script type="text/javascript">Ext.onReady(function() {'.implode("\n",$rules).'});</script>';
        }
        return $overridden;
    }

    /**
     * Load the working context for this controller.
     * 
     * @return modContext|string
     */
    public function loadWorkingContext() {
        $wctx = !empty($_GET['wctx']) ? $_GET['wctx'] : $this->modx->context->get('key');
        if (!empty($wctx)) {
            $this->workingContext = $this->modx->getContext($wctx);
            if (!$this->workingContext) {
                $this->failure($this->modx->lexicon('permission_denied'));
            }
        } else {
            $this->workingContext =& $this->modx->context;
        }
        return $this->workingContext;
    }
}

/**
 * Utility abstract class for usage by Extras that has a subrequest handler that does auto-routing by the &action
 * REQUEST parameter. You must extend this class in your Extra to use it.
 *
 * @abstract
 * @package modx
 */
abstract class modExtraManagerController extends modManagerController {
    /**
     * Define the default controller action for this namespace
     * @static
     * @return string A default controller action
     */
    public static function getDefaultController() { return 'index'; }

    /**
     * Get an instance of this extra controller
     * @static
     * @param modX $modx A reference to the modX object
     * @param string $className The string className that is being requested to load
     * @param array $config An array of configuration options built from the modAction object
     * @return modManagerController A newly created modManagerController instance
     */
    public static function getInstance(modX &$modx,$className,array $config = array()) {
        $action = call_user_func(array($className,'getDefaultController'));
        if (isset($_REQUEST['action'])) {
            $action = str_replace(array('../','./','.','-','@'),'',$_REQUEST['action']);
        }
        $className = self::getControllerClassName($action,$config['namespace']);
        $classPath = $config['namespace_path'].'controllers/'.$action.'.class.php';
        require_once $classPath;
        /** @var modManagerController $controller */
        $controller = new $className($modx,$config);
        return $controller;
    }

    /**
     * Return the class name of a controller given the action
     * @static
     * @param string $action The action name, eg: "home" or "create"
     * @param string $namespace The namespace of the Exra
     * @param string $postFix The string to postfix to the class name
     * @return string A full class name of the controller class
     */
    public static function getControllerClassName($action,$namespace = '',$postFix = 'ManagerController') {
        $className = explode('/',$action);
        $o = array();
        foreach ($className as $k) {
            $o[] = ucfirst(str_replace(array('.','_','-'),'',$k));
        }
        return ucfirst($namespace).implode('',$o).$postFix;
    }

    /**
     * Do any page-specific logic and/or processing here
     * @param array $scriptProperties
     * @return void
     */
    public function process(array $scriptProperties = array()) {}

    /**
     * The page title for this controller
     * @return string The string title of the page
     */
    public function getPageTitle() { return ''; }
    
    /**
     * Loads any page-specific CSS/JS for the controller
     * @return void
     */
    public function loadCustomCssJs() {}

    /**
     * Specify the location of the template file
     * @return string The absolute path to the template file
     */
    public function getTemplateFile() { return ''; }

    /**
     * Check whether the active user has access to view this page
     * @return bool True if the user passes permission checks
     */
    public function checkPermissions() { return true;}
}
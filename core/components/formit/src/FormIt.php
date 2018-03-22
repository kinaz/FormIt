<?php

namespace Sterc;

use Sterc\FormIt\Hook;
use Sterc\FormIt\Module\Module;
use Sterc\FormIt\Request;

/**
 * Class FormIt
 *
 * @package Sterc\FormIt
 */
class FormIt
{
    /**
     * @var \modX $modx
     */
    public $modx = null;

    /**
     * @var array $config
     */
    public $config = [];

    /**
     * In debug mode, will monitor execution time.
     * @var int $debugTimer
     */
    public $debugTimer = 0;

    /**
     * True if the class has been initialized or not.
     * @var bool $_initialized
     */
    private $_initialized = false;

    /**
     * The fiHooks instance for processing preHooks
     * @var Hook $preHooks
     */
    public $preHooks;

    /**
     * The fiHooks instance for processing postHooks
     * @var Hook $postHooks
     */
    public $postHooks;

    /**
     * The request handling class
     * @var Request $request
     */
    public $request;

    /**
     * An array of cached chunk templates for processing
     * @var array $chunks
     */
    public $chunks = [];

    /**
     * Used when running unit tests to prevent emails/headers from being sent
     * @var bool $inTestMode
     */
    public $inTestMode = false;

    /**
     * FormIt constructor.
     *
     * @param \modX $modx
     * @param array $config
     */
    public function __construct($modx, $config = [])
    {
        $this->modx = $modx;

        $corePath = $this->modx->getOption('formit.core_path', null, MODX_CORE_PATH . 'components/formit/');
        $assetsPath = $this->modx->getOption('formit.assets_path', null, MODX_ASSETS_PATH . 'components/formit/');
        $assetsUrl = $this->modx->getOption('formit.assets_url', null, MODX_ASSETS_URL . 'components/formit/');
        $connectorUrl = $assetsUrl . 'connector.php';

        $this->config = array_merge([
            'corePath' => $corePath,
            'modelPath' => $corePath . 'model/',
            'chunksPath' => $corePath . 'elements/chunks/',
            'snippetsPath' => $corePath . 'elements/snippets/',
            'controllersPath' => $corePath . 'controllers/',
            'includesPath' => $corePath . 'includes/',
            'testsPath' => $corePath . 'test/',
            'templatesPath' => $corePath . 'templates/',
            'assetsPath' => $assetsPath,
            'assetsUrl' => $assetsUrl,
            'cssUrl' => $assetsUrl . 'css/',
            'jsUrl' => $assetsUrl . 'js/',
            'connectorUrl' => $connectorUrl,
            'debug' => $this->modx->getOption('formit.debug', null, false),
            'use_multibyte' => (bool) $this->modx->getOption('use_multibyte', null, false),
            'encoding' => $this->modx->getOption('modx_charset', null, 'UTF-8'),
            'mcryptAvailable' => function_exists('mcrypt_encrypt'),
            'opensslAvailable' => function_exists('openssl_encrypt')
        ], $config);

        if ($this->modx->getOption('formit.debug', $this->config, true)) {
            $this->startDebugTimer();
        }

        $this->modx->addPackage('formit', $this->config['modelPath']);
    }

    /**
     * Initialize the component into a context and provide context-specific
     * handling actions.
     *
     * @param string $context The context to initialize FormIt into
     *
     * @return mixed
     */
    public function initialize($context = 'web')
    {
        switch ($context) {
            case 'mgr': break;
            case 'web':
            default:
                $language = isset($this->config['language']) ? $this->config['language'] . ':' : '';
                $this->modx->lexicon->load($language . 'formit:default');
                $this->_initialized = true;
                break;
        }

        return $this->_initialized;
    }

    /**
     * Sees if the FormIt class has been initialized already
     *
     * @return boolean
     */
    public function isInitialized()
    {
        return $this->_initialized;
    }

    /**
     * Load the fiRequest class
     *
     * @return Request
     */
    public function loadRequest()
    {
        $className = $this->modx->getOption('request_class',$this->config,'fiRequest');
        $classPath = $this->modx->getOption('request_class_path',$this->config,'');

        if (empty($classPath)) {
            $classPath = $this->config['modelPath'].'formit/';
        }

        if ($this->modx->loadClass($className,$classPath,true,true)) {
            $this->request = new \fiRequest($this,$this->config);
        } else {
            $this->modx->log(\modX::LOG_LEVEL_ERROR,'[FormIt] Could not load fiRequest class.');
        }

        return $this->request;

    }

    /**
     * @param string $className
     * @param string $serviceName
     * @param array $config
     *
     * @return Module
     */
    public function loadModule($className,$serviceName,array $config = array())
    {
        if (empty($this->$serviceName)) {
            $classPath = $this->modx->getOption('formit.modules_path',null,$this->config['modelPath'].'formit/module/');

            if ($this->modx->loadClass($className,$classPath,true,true)) {
                $this->$serviceName = new $className($this,$config);
            } else {
                $this->modx->log(\modX::LOG_LEVEL_ERROR,'[FormIt] Could not load module: '.$className.' from '.$classPath);
            }
        }

        return $this->$serviceName;
    }

    /**
     * Loads the Hooks class.
     *
     * @access public
     * @param $type string The type of hook to load.
     * @param $config array An array of configuration parameters for the
     * hooks class
     *
     * @return false|Hook An instance of the fiHooks class.
     */
    public function loadHooks($type = 'post', $config = [])
    {
        if (!$this->modx->loadClass('formit.fiHooks', $this->config['modelPath'], true, true)) {
            $this->modx->log(\modX::LOG_LEVEL_ERROR,'[FormIt] Could not load Hooks class.');

            return false;
        }

        $typeVar = $type . 'Hooks';
        $this->$typeVar = new \fiHooks($this, $config, $type);

        return $this->$typeVar;
    }

    /**
     * Gets a unique session-based store key for storing form submissions.
     *
     * @return string
     */
    public function getStoreKey()
    {
        return $this->modx->context->get('key') . '/elements/formit/submission/' . md5(session_id());
    }

    /**
     * Gets a Chunk and caches it; also falls back to file-based templates
     * for easier debugging.
     *
     * Will always use the file-based chunk if $debug is set to true.
     *
     * @access public
     * @param string $name The name of the Chunk
     * @param array $properties The properties for the Chunk
     * @return string The processed content of the Chunk
     */
    public function getChunk($name,$properties = array())
    {
        if (class_exists('pdoTools') && $pdo = $this->modx->getService('pdoTools')) {
            return $pdo->getChunk($name, $properties);
        }

        $chunk = null;

        if(substr($name, 0, 6) == "@CODE:"){
            $content = substr($name, 6);
            $chunk = $this->modx->newObject('modChunk');
            $chunk->setContent($content);
        } elseif (!isset($this->chunks[$name])) {
            if (!$this->config['debug']) {
                $chunk = $this->modx->getObject('modChunk', array('name' => $name),true);
            }

            if (empty($chunk)) {
                $chunk = $this->_getTplChunk($name);
                if ($chunk === false) {
                    return false;
                }
            }

            $this->chunks[$name] = $chunk->getContent();
        } else {
            $content = $this->chunks[$name];
            $chunk = $this->modx->newObject('modChunk');
            $chunk->setContent($content);
        }

        $chunk->setCacheable(false);

        return $chunk->process($properties);
    }

    /**
     * Returns a modChunk object from a template file.
     *
     * @access private
     * @param string $name The name of the Chunk. Will parse to name.chunk.tpl
     *
     * @return \modChunk|boolean Returns the modChunk object if found, otherwise
     * false.
     */
    public function _getTplChunk($name)
    {
        $chunk = false;
        if (file_exists($name)) {
            $file = $name;
        } else {
            $lowerCaseName = $this->config['use_multibyte'] ? mb_strtolower($name, $this->config['encoding']) : strtolower($name);
            $file = $this->config['chunksPath'] . $lowerCaseName . '.chunk.tpl';
        }

        if (file_exists($file)) {
            $content = file_get_contents($file);
            /** @var \modChunk $chunk */
            $chunk = $this->modx->newObject('modChunk');
            $chunk->set('name', $name);
            $chunk->setContent($content);
        }

        return $chunk;
    }

    /**
     * Output the final output and wrap in the wrapper chunk. Optional, but
     * recommended for debugging as it outputs the execution time to the output.
     *
     * Also, it is good to output your snippet code with wrappers for easier
     * CSS isolation and styling.
     *
     * @param string $output The output to process
     *
     * @return string The final wrapped output
     */
    public function output($output)
    {
        if ($this->debugTimer !== false) {
            $output .= "<br />\nExecution time: " . $this->endDebugTimer() . "\n";
        }

        return $output;
    }

    /**
     * Starts the debug timer.
     *
     * @return int The start time.
     */
    protected function startDebugTimer()
    {
        $mtime = microtime();
        $mtime = explode(' ', $mtime);
        $mtime = $mtime[1] + $mtime[0];
        $tstart = $mtime;

        $this->debugTimer = $tstart;

        return $this->debugTimer;
    }

    /**
     * Ends the debug timer and returns the total number of seconds script took
     * to run.
     *
     * @access protected
     * @return int The end total time to execute the script.
     */
    protected function endDebugTimer()
    {
        $mtime = microtime();
        $mtime = explode(" ", $mtime);
        $mtime = $mtime[1] + $mtime[0];
        $tend = $mtime;

        $totalTime = ($tend - $this->debugTimer);
        $totalTime = sprintf("%2.4f s", $totalTime);

        $this->debugTimer = false;

        return $totalTime;
    }

    public function setOption($key, $value)
    {
        $this->config[$key] = $value;
    }

    public function setOptions($options)
    {
        foreach ($options as $key => $value) {
            $this->setOption($key, $value);
        }
    }

    public function encryptionMigrationStatus()
    {
        $migrationStatus = true;
        if ($this->modx->getCount('FormItForm', array('encrypted' => 1, 'encryption_type' => 1))) {
            $migrationStatus = false;
        }

        return $migrationStatus;
    }
}
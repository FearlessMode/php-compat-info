<?php
/**
 * Base class to all CompatInfo analysers accessible through the AnalyserPlugin.
 *
 * @category PHP
 * @package  PHP_CompatInfo
 * @author   Laurent Laville <pear@laurent-laville.org>
 * @license  http://www.opensource.org/licenses/bsd-license.php  BSD License
 * @version  GIT: $Id$
 * @link     http://php5.laurent-laville.org/compatinfo/
 */

namespace Bartlett\CompatInfo\Analyser;

use Bartlett\CompatInfo\ConsoleApplication;
use Bartlett\CompatInfo\Reference\ReferenceLoader;
use Bartlett\CompatInfo\Reference\Strategy\PreFetchStrategy;
use Bartlett\CompatInfo\Reference\Strategy\AutoDiscoverStrategy;

use Bartlett\Reflect\Analyser\AbstractAnalyser as ReflectAnalyser;

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableSeparator;

/**
 * Provides common metrics for all CompatInfo analysers.
 *
 * @category PHP
 * @package  PHP_CompatInfo
 * @author   Laurent Laville <pear@laurent-laville.org>
 * @license  http://www.opensource.org/licenses/bsd-license.php  BSD License
 * @version  Release: @package_version@
 * @link     http://php5.laurent-laville.org/compatinfo/
 * @since    Class available since Release 3.0.0RC1
 */
abstract class AbstractAnalyser extends ReflectAnalyser
{
    protected static $php4 = array(
        'ext.min' => '',
        'ext.max' => '',
        'php.min' => '4.0.0',
        'php.max' => '',
    );
    protected $loader;

    /**
     *
     */
    protected function listHelper(OutputInterface $output, array $args, $versions, $filter, $title)
    {
        $rows = ConsoleApplication::versionHelper($args, $filter);

        $headers = array($title, 'REF', 'EXT min/Max', 'PHP min/Max');

        $versions = empty($versions['php.max'])
            ? $versions['php.min']
            : $versions['php.min'] . ' => ' . $versions['php.max']
        ;

        if ($filter) {
            $footers = array(
                sprintf('<info>Total [%d/%d]</info>', count($rows), count($args)),
                '',
                '',
                $versions
            );
        } else {
            $footers = array(
                sprintf('<info>Total [%d]</info>', count($args)),
                '',
                '',
                $versions
            );
        }
        $rows[] = new TableSeparator();
        $rows[] = $footers;

        $table = new Table($output);
        $table
            ->setHeaders($headers)
            ->setRows($rows)
            ->setStyle('compact')
        ;
        $table->render();
    }

    /**
     * Explore dependencies (DependencyModel) of each namespace (PackageModel).
     *
     * @param object $package Reflect the current namespace explored
     *
     * @return void
     */
    public function visitPackageModel($package)
    {
        parent::visitPackageModel($package);

        foreach ($package->getDependencies() as $dependency) {
            $dependency->accept($this);
        }
    }

    /**
     * Explore user classes (ClassModel) found in the current namespace.
     *
     * @param object $class Reflect the current user class explored
     *
     * @return void
     */
    public function visitClassModel($class)
    {
        parent::visitClassModel($class);

        if ($this->testClass) {
            // do not explore classes of PHPUnit tests suites
            return;
        }

        $name = $class->getName();
        $type = static::METRICS_PREFIX;

        if ($class->isTrait()) {
            $type .= '.traits';
        } elseif ($class->isInterface()) {
            $type .= '.interfaces';
        } else {
            $type .= '.classes';
        }
        $this->count[$type][$name] = self::$php4;

        list($min, $max) = $this->processClass($class);
        $this->count[$type][$name]['php.min'] = $min;
        $this->count[$type][$name]['php.max'] = $max;

        $this->updateGlobalVersion($min, $max);

        foreach ($class->getProperties() as $property) {
            $property->accept($this);
        }

        foreach ($class->getMethods() as $method) {
            $method->accept($this);
        }

        $this->updatePackageVersion($min, $max, $class->getNamespaceName());
    }

    /**
     * Explore properties (PropertyModel) of each user classes
     * found in the current namespace.
     *
     * @param object $property Reflect the current class property explored
     *
     * @return void
     */
    public function visitPropertyModel($property)
    {
        // Property visibility
        if ($property->isPublic()
            || $property->isPrivate()
            || $property->isProtected()
        ) {
            $min = '5.0.0';
            $max = '';

            // update object versions
            $class = $property->getClassName();
            self::updateVersion(
                $min,
                $this->count[static::METRICS_PREFIX . '.classes'][$class]['php.min']
            );

            $this->updateGlobalVersion($min, $max);
        }
    }

    /**
     * Explore methods (MethodModel) of each user classes
     * found in the current namespace.
     *
     * @param object $method Reflect the current method explored
     *
     * @return void
     */
    public function visitMethodModel($method)
    {
        $name = $method->getName();
        $this->count[static::METRICS_PREFIX . '.methods'][$name] = self::$php4;

        // Methods constructor/destructor, visibility
        // and modifiers (final, static, abstract)
        if ($method->isFinal()
            || $method->isStatic()
            || $method->isAbstract()
            || $method->isPrivate()
            || $method->isProtected()
            || $method->isPublic()
            || $method->isConstructor()
            || $method->isDestructor()
        ) {
            $min = '5.0.0';
            $this->count[static::METRICS_PREFIX . '.methods'][$name]['php.min'] = $min;

            // update object versions
            $class = $method->getClassName();
            self::updateVersion(
                $min,
                $this->count[static::METRICS_PREFIX . '.classes'][$class]['php.min']
            );
        }

        $this->updateGlobalVersion(
            $this->count[static::METRICS_PREFIX . '.methods'][$name]['php.min'],
            $this->count[static::METRICS_PREFIX . '.methods'][$name]['php.max']
        );

        foreach ($method->getParameters() as $parameter) {
            $parameter->accept($this);
        }
    }

    /**
     * Explore user functions (FunctionModel) found in the current namespace.
     *
     * @param object $function Reflect the current user function explored
     *
     * @return void
     */
    public function visitFunctionModel($function)
    {
        $name = $function->getName();
        $vers = self::$php4;
        $vers['ref'] = 'user';

        $this->count[static::METRICS_PREFIX . '.functions'][$name] = $vers;

        if ($function->inNamespace()) {
            $min = '5.3.0';
            $max = '';
            $this->count[static::METRICS_PREFIX . '.functions'][$name]['php.min'] = $min;

            $this->updateGlobalVersion($min, $max);

            $this->updatePackageVersion($min, $max, $function->getNamespaceName());
        }
    }

    /**
     * Explore user or magic constants (ConstantModel)
     * found in the current namespace.
     *
     * @param object $constant Reflect the current constant explored
     *
     * @return void
     */
    public function visitConstantModel($constant)
    {
        $name     = $constant->getName();

        $versions = $this->processInternal($name);
        $type     = $this->loader->getTypeElement();

        $this->count[static::METRICS_PREFIX . ".$type"][$name] = $versions;
        $this->updateGlobalVersion($versions['php.min'], $versions['php.max']);
    }

    /**
     * Explore contents of each dependency (DependencyModel)
     * found in the current namespace.
     *
     * @param object $dependency Reflect the current dependency explored
     *
     * @return void
     */
    public function visitDependencyModel($dependency)
    {
        $name = $dependency->getName();
        $argc = 0;

        if ($dependency->isClassMethod()) {
            list($element, $method) = explode('::', $name);
            $name = $element;
        } else {
            $element = $name;
        }

        $ref = $this->findReference($element);
        if (!$ref) {
            // stop here if user element
            return;
        }

        if ($dependency->isClassMethod()) {
            $elements = $ref->getClassMethods();

            if (!isset($elements[$element][$method])) {
                return;
            }
            $this->count[static::METRICS_PREFIX . '.methods'][$dependency->getName()]
                = $elements[$element][$method];

            $versions = $elements[$element][$method];
            $type     = 'classes';

            self::updateVersion(
                $versions['php.min'],
                $this->count[static::METRICS_PREFIX . '.extensions'][$ref->getName()]['php.min']
            );
            self::updateVersion(
                $versions['php.max'],
                $this->count[static::METRICS_PREFIX . '.extensions'][$ref->getName()]['php.max']
            );
        } else {
            $versions = $this->processInternal($name, $argc);
            $type     = $this->loader->getTypeElement();
        }

        if (in_array(static::METRICS_GROUP, array($type, 'internals', 'extensions', 'namespaces'))) {
            $this->count[static::METRICS_PREFIX . ".$type"][$name] = $versions;
            $this->updateGlobalVersion($versions['php.min'], $versions['php.max']);

            $this->updatePackageVersion(
                $versions['php.min'],
                $versions['php.max'],
                $dependency->getNamespaceName()
            );
        }

        if ('extension_loaded' == $name) {
            $args = $dependency->getArguments();

            if ('Scalar_String' == $args[0]['type']) {
                $name     = $args[0]['value'];
                $versions = $this->processInternal($name);

                if (!isset($this->count[static::METRICS_PREFIX . ".extensions"][$name])) {
                    unset($versions['ref']);
                    $this->count[static::METRICS_PREFIX . ".extensions"][$name] = $versions;
                    $this->updateGlobalVersion($versions['php.min'], $versions['php.max']);
                }
            }
        }
    }

    /**
     * Explore contents of each function parameter (ParameterModel)
     * found in the current namespace.
     *
     * @param object $parameter Reflect the current parameter explored
     *
     * @return void
     */
    public function visitParameterModel($parameter)
    {
        $name = $parameter->getTypeHint();

        if (empty($name)
            || in_array(strtolower($name), array('callable', 'array'))
        ) {
            return;
        }

        // object instance
        $versions = $this->processInternal($name);
        $type     = $this->loader->getTypeElement();

        if ($type == static::METRICS_GROUP) {
            $this->count[static::METRICS_PREFIX . ".$type"][$name] = $versions;
            $this->updateGlobalVersion($versions['php.min'], $versions['php.max']);
        }
    }

    /**
     * Update the base version if current ref version is greater
     *
     * @param string $current Current version
     * @param string &$base   Base version
     *
     * @return void
     */
    protected static function updateVersion($current, &$base)
    {
        if (version_compare($current, $base, 'gt')) {
            $base = $current;
        }
    }

    /**
     * Update the global versions of the project
     *
     * @param string $min The PHP min version to check
     * @param string $max The PHP max version to check
     *
     * @return void
     */
    protected function updateGlobalVersion($min, $max)
    {
        self::updateVersion(
            $min,
            $this->count[static::METRICS_PREFIX . '.versions']['php.min']
        );
        self::updateVersion(
            $max,
            $this->count[static::METRICS_PREFIX . '.versions']['php.max']
        );
    }

    /**
     * Update the current namespace versions, only if metric is required.
     *
     * @param string $min The PHP min version to check
     * @param string $max The PHP max version to check
     * @param string $pkg The current namespace name
     *
     * @return void
     */
    protected function updatePackageVersion($min, $max, $pkg)
    {
        if (isset($this->count[static::METRICS_PREFIX . '.packages'])) {
            if (empty($pkg)) {
                $pkg = '+global';
                if (!isset($this->count[static::METRICS_PREFIX . '.packages'][$pkg]['ext.min'])) {
                    $this->count[static::METRICS_PREFIX . '.packages'][$pkg]['ext.min'] = '';
                    $this->count[static::METRICS_PREFIX . '.packages'][$pkg]['ext.max'] = '';
                }
            }
            self::updateVersion(
                $min,
                $this->count[static::METRICS_PREFIX . '.packages'][$pkg]['php.min']
            );
            self::updateVersion(
                $max,
                $this->count[static::METRICS_PREFIX . '.packages'][$pkg]['php.max']
            );
        }
    }

    /**
     * Finds Reference versions of any elements found in the data source code.
     *
     * @param string $element Name of element to search for Reference versions
     *
     * @return null|object Instance of ReferenceInterface
     */
    protected function findReference($element)
    {
        if (!$this->loader) {
            // list of extensions that cannot be automatic discover
            $extensions = array(
                'standard',
                'core',
                'apcu',
                'bcmath',
                'bz2',
                'calendar',
                'date',
                'dom',
                'ereg',
                'fileinfo',
                'filter',
                'gd',
                'gettext',
                'imap',
                'intl',
                'libevent',
                'mailparse',
                'mbstring',
                'mcrypt',
                'mongo',
                'msgpack',
                'mssql',
                'odbc',
                'openssl',
                'pcntl',
                'pcre',
                'pdflib',
                'pgsql',
                'pthreads',
                'readline',
                'soap',
                'sockets',
                'sphinx',
                'spl',
                'sysvmsg',
                'sysvsem',
                'sysvshm',
                'tokenizer',
                'XCache',
                'xml',
                'xsl',
                'zendopcache',
                'zlib',
            );
            $this->loader = new ReferenceLoader;
            $this->loader->register(
                new PreFetchStrategy($extensions)
            );
            // all other extensions may be (lazy) load on demand
            $this->loader->register(new AutoDiscoverStrategy);
        }
        return $this->loader->loadRef($element);
    }

    /**
     * @param string $element Name of element to search for Reference versions
     * @param int    $argc    (optional) Number of arguments used
     *                        in current $element signature
     */
    protected function processInternal($element, $argc = 0)
    {
        $ref = $this->findReference($element);

        if ($ref) {
            $elements = $ref->{'get' . ucfirst($this->loader->getTypeElement())}();

            // because case found in code does not always match the PHP Ref. declaration
            $elements = array_change_key_case($elements);
            $versions = $elements[strtolower($element)];
            $versions['ref'] = $ref::REF_NAME;
        } else {
            // not found; probably user component or reference not yet supported
            $versions = self::$php4;
            $versions['ref'] = 'user';
        }

        $refName = $versions['ref'];

        if ('user' !== $refName) {
            if (!isset($this->count[static::METRICS_PREFIX . '.extensions'][$refName])) {
                $this->count[static::METRICS_PREFIX . '.extensions'][$refName] = self::$php4;
            }

            static::updateVersion(
                $versions['ext.min'],
                $this->count[static::METRICS_PREFIX . '.extensions'][$refName]['ext.min']
            );
            static::updateVersion(
                $versions['ext.max'],
                $this->count[static::METRICS_PREFIX . '.extensions'][$refName]['ext.max']
            );

            static::updateVersion(
                $versions['php.min'],
                $this->count[static::METRICS_PREFIX . '.extensions'][$refName]['php.min']
            );
            static::updateVersion(
                $versions['php.max'],
                $this->count[static::METRICS_PREFIX . '.extensions'][$refName]['php.max']
            );

            $this->updateGlobalVersion($versions['php.min'], $versions['php.max']);
        }

        return $versions;
    }

    /**
     * Explore class/interface/trait signature
     *
     * @param object $model A ClassModel definition
     */
    protected function processClass($model)
    {
        $max        = '';
        $name       = $model->getName();
        $parent     = $model->getParentClass();
        $interfaces = $model->getInterfaceNames();

        if ($model->getName() == 'Generator') {
            $min = '5.5.0';
            $this->count[static::METRICS_PREFIX . '.classes'][$name]['php.min'] = $min;

        } elseif ($model->isTrait()) {
            $min = '5.4.0';
            $this->count[static::METRICS_PREFIX . '.traits'][$name]['php.min'] = $min;

        } elseif ($parent) {
            // inspect parent class and checks versions
            return $this->processClass($parent);

        } elseif ($model->inNamespace()) {
            $min = '5.3.0';

        } elseif ($model->isInterface() || !empty($interfaces)) {
            $min = '5.0.0';

        } else {
            /**
             * Look for PHP5 features
             */
            if ($model->isAbstract()
                || $model->isFinal()
            ) {
                $min = '5.0.0';
            } else {
                $min = '4.0.0';
            }
        }
        return array($min, $max);
    }
}

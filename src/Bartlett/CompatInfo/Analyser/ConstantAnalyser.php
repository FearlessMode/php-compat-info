<?php
/**
 * The CompatInfo Constant analyser accessible through the AnalyserPlugin.
 *
 * @category PHP
 * @package  PHP_CompatInfo
 * @author   Laurent Laville <pear@laurent-laville.org>
 * @license  http://www.opensource.org/licenses/bsd-license.php  BSD License
 * @version  GIT: $Id$
 * @link     http://php5.laurent-laville.org/compatinfo/
 */

namespace Bartlett\CompatInfo\Analyser;

use Bartlett\CompatInfo\Metrics;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * This analyzer collects versions on all constants of a project.
 *
 * @category PHP
 * @package  PHP_CompatInfo
 * @author   Laurent Laville <pear@laurent-laville.org>
 * @license  http://www.opensource.org/licenses/bsd-license.php  BSD License
 * @version  Release: @package_version@
 * @link     http://php5.laurent-laville.org/compatinfo/
 * @since    Class available since Release 3.0.0RC1
 */
class ConstantAnalyser extends AbstractAnalyser
{
    const METRICS_PREFIX = Metrics::CONSTANT_ANALYSER;
    const METRICS_GROUP  = Metrics::CONSTANTS;

    /**
     * Initializes all metrics.
     *
     * @return void
     */
    protected function init()
    {
        $this->count = array(
            self::METRICS_PREFIX . '.' . self::METRICS_GROUP => array(),
            self::METRICS_PREFIX . '.' . Metrics::VERSIONS   => self::$php4
        );
    }

    /**
     * Renders analyser report to output.
     *
     * @param object OutputInterface $output    Console Output
     *
     * @return void
     */
    public function render(OutputInterface $output)
    {
        $output->writeln('<info>Constants Analysis</info>' . PHP_EOL);

        $this->listHelper(
            $output,
            $this->count[self::METRICS_PREFIX . '.' . self::METRICS_GROUP],
            $this->count[self::METRICS_PREFIX . '.' . Metrics::VERSIONS],
            func_get_arg(1),
            'Constant'
        );
    }

    /**
     * Explore all constants (ConstantModel) in each namespace (PackageModel).
     *
     * @param object $package Reflect the current namespace explored
     *
     * @return void
     */
    public function visitPackageModel($package)
    {
        // explore dependencies (DependencyModel)
        parent::visitPackageModel($package);

        // explore all constants (ConstantModel)
        foreach ($package->getConstants() as $constant) {
            $constant->accept($this);
        }
    }
}

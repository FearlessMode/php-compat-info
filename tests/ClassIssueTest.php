<?php
/**
 * Unit tests for PHP_CompatInfo package, issues reported
 *
 * PHP version 5
 *
 * @category   PHP
 * @package    PHP_CompatInfo
 * @subpackage Tests
 * @author     Laurent Laville <pear@laurent-laville.org>
 * @author     Remi Collet <Remi@FamilleCollet.com>
 * @license    http://www.opensource.org/licenses/bsd-license.php  BSD License
 * @version    GIT: $Id$
 * @link       http://php5.laurent-laville.org/compatinfo/
 * @since      Class available since Release 3.2.0
 */

namespace Bartlett\Tests\CompatInfo;

use Bartlett\CompatInfo;

use Bartlett\Reflect\ProviderManager;
use Bartlett\Reflect\Provider\SymfonyFinderProvider;
use Bartlett\Reflect\Plugin\Analyser\AnalyserPlugin;

use Symfony\Component\Finder\Finder;

/**
 * Tests for PHP_CompatInfo, retrieving class elements,
 * and versioning information.
 *
 * @category   PHP
 * @package    PHP_CompatInfo
 * @subpackage Tests
 * @author     Laurent Laville <pear@laurent-laville.org>
 * @author     Remi Collet <Remi@FamilleCollet.com>
 * @license    http://www.opensource.org/licenses/bsd-license.php  BSD License
 * @version    Release: @package_version@
 * @link       http://php5.laurent-laville.org/compatinfo/
 */
class ClassIssueTest extends \PHPUnit_Framework_TestCase
{
    const GH119 = 'GH#119';

    protected static $compatinfo;

    /**
     * Sets up the shared fixture.
     *
     * @return void
     */
    public static function setUpBeforeClass()
    {
        $finder = new Finder();
        $finder->files()
            ->name('gh119.php')
            ->in(
                dirname(__FILE__) . DIRECTORY_SEPARATOR .
                '_files' . DIRECTORY_SEPARATOR
            )
        ;

        $pm = new ProviderManager;
        $pm->set(self::GH119, new SymfonyFinderProvider($finder));

        self::$compatinfo = new CompatInfo;
        self::$compatinfo->setProviderManager($pm);

        $plugins = array(
            new CompatInfo\Analyser\SummaryAnalyser
        );
        $analyser = new AnalyserPlugin($plugins);
        self::$compatinfo->addPlugin($analyser);
    }

    /**
     * Regression test for bug GH#119
     *
     * @link https://github.com/llaville/php-compat-info/issues/119
     *       "private" keyword reports as "Required PHP 4.0.0 (min)"
     * @group regression
     * @return void
     */
    public function testBugGH119()
    {
        self::$compatinfo->parse(array(self::GH119));

        $key = CompatInfo\Analyser\SummaryAnalyser::METRICS_PREFIX . '.versions';

        $expected = '5.0.0';
        $metrics  = self::$compatinfo->getMetrics();  #error_log ( print_r($metrics, true) );

        $this->assertEquals(
            $expected,
            $metrics[self::GH119][$key]['php.min']
        );
    }
}

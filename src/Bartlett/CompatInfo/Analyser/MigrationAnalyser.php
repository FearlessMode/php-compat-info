<?php
/**
 * Migration Analyser
 *
 * @category PHP
 * @package  PHP_CompatInfo
 * @author   Laurent Laville <pear@laurent-laville.org>
 * @license  http://www.opensource.org/licenses/bsd-license.php  BSD License
 * @version  GIT: $Id$
 * @link     http://php5.laurent-laville.org/compatinfo/
 */

namespace Bartlett\CompatInfo\Analyser;

use Bartlett\CompatInfo\Sniffs\Sniffer;

use Bartlett\Reflect\Analyser\AbstractSniffAnalyser;

/**
 * This analyzer collects different metrics to find out, e.g :
 * - keywords reserved
 * - deprecated elements
 * - removed elements
 * and lot more.
 *
 * @category PHP
 * @package  PHP_CompatInfo
 * @author   Laurent Laville <pear@laurent-laville.org>
 * @license  http://www.opensource.org/licenses/bsd-license.php  BSD License
 * @version  Release: @package_version@
 * @link     http://php5.laurent-laville.org/compatinfo/
 * @since    Class available since Release 5.0.0
 */
class MigrationAnalyser extends AbstractSniffAnalyser
{
    /**
     * Initializes the migration analyser
     */
    public function __construct()
    {
        $this->sniffs = [
            // detect when an element was introduced in PHP
            Sniffer::introducedSniff(),

            // check for reserved keywords
            Sniffer::keywordReservedSniff(),

            // checks for deprecated elements
            Sniffer::deprecatedSniff(),

            // detect when an element was removed from PHP
            Sniffer::removedSniff(),

            // detect when short open tag syntax is used
            Sniffer::shortOpenTagSniff(),

            // detect when short array syntax is used
            Sniffer::shortArraySyntaxSniff(),

            // detect when array dereferencing syntax is used
            Sniffer::arrayDereferencingSyntaxSniff(),

            // detect when class member access on instantiation syntax is used
            Sniffer::classMemberAccessOnInstantiationSniff(),

            // check usage of CONST keyword outside of a class
            Sniffer::constSyntaxSniff(),

            // report use of magic methods not supported
            Sniffer::magicMethodsSniff(),

            // check for anonymous functions
            Sniffer::anonymousFunctionSniff(),

            // detect Null Coalesce Operator syntax
            Sniffer::nullCoalesceOperatorSniff(),

            // check usage of Variadic functions
            Sniffer::variadicFunctionSniff(),

            // check usage of use const, use function in classes
            Sniffer::useConstFunctionSniff(),

            // detect Exponentiation
            Sniffer::exponantiationSniff(),

            // check usage of nowdoc and heredoc syntax
            Sniffer::docStringSyntaxSniff(),

            // detect Class::{expr}() syntax
            Sniffer::classExprSyntaxSniff(),

            // detect Binary Number Format (with 0b prefix)
            Sniffer::binaryNumberFormatSniff(),

            // detect Combined Comparison (Spaceship) Operator syntax
            Sniffer::combinedComparisonOperatorSniff(),

            // detect Return Type Declarations
            Sniffer::returnTypeDeclarationSniff(),

            // check usage of Anonymous classes
            Sniffer::anonymousClassSniff(),

            // detect use of Elvis syntax
            Sniffer::shortTernaryOperatorSyntaxSniff(),

            //
            Sniffer::noCompatCallFromGlobalSniff(),

            //
            Sniffer::noCompatMagicMethodsSniff(),

            //
            Sniffer::noCompatBreakContinueSniff(),

            //
            Sniffer::noCompatParamSniff(),

            //
            Sniffer::noCompatRegisterGlobalsSniff(),

            //
            Sniffer::noCompatKeywordCaseInsensitiveSniff(),
        ];
    }
}

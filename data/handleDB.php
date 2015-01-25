<?php
/**
 * Script to handle compatinfo.sqlite file, that provides all references.
 *
 * CAUTION: uses at your own risk.
 *
 * @category PHP
 * @package  PHP_CompatInfo
 * @author   Laurent Laville <pear@laurent-laville.org>
 * @license  http://www.opensource.org/licenses/bsd-license.php BSD License
 * @since    Release 4.0.0alpha3
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once __DIR__ . '/ReferenceCollection.php';

use Bartlett\CompatInfo\Reference\ExtensionFactory;

use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Backup copy of the database
 */
class DbBackupCommand extends Command
{
    protected function configure()
    {
        $this->setName('db:backup')
            ->setDescription('Backup the current SQLite compatinfo database')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $source  = $this->getApplication()->getDbFilename();
        $tempDir = $this->getApplication()->getAppTempDir() . '/backups';

        if (!file_exists($tempDir)) {
            mkdir($tempDir, 0755, true);
        }
        $sha1 = sha1_file($source);
        $dest = $tempDir . '/' . basename($source) . ".$sha1";

        copy($source, $dest);

        $output->writeln(
            sprintf(
                'Database <info>%s</info> sha1: <comment>%s</comment>' .
                ' was copied to <comment>%s</comment>',
                $source,
                $sha1,
                $dest
            )
        );
    }
}

/**
 * Initiliaze the database with JSON files for one or all extensions.
 */
class DbInitCommand extends Command
{
    private $extensions;

    protected function configure()
    {
        $this->setName('db:init')
            ->setDescription('Load JSON file(s) in SQLite database')
            ->addArgument(
                'extension',
                InputArgument::OPTIONAL,
                'extension to load in database (case insensitive)'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $path = $this->getApplication()->getRefDir();

        $iterator = new \DirectoryIterator($path);
        $suffix   = '.extensions.json';

        foreach ($iterator as $file) {
            if (fnmatch('*'.$suffix, $file->getPathName())) {
                $className = str_replace($suffix, '', basename($file));
                $extName   = strtolower($className);

                $this->extensions[] = $extName;
            }
        }

        $extension = trim($input->getArgument('extension'));
        $extension = strtolower($extension);

        if (empty($extension)) {
            $extensions = $this->extensions;
        } else {
            if (!in_array($extension, $this->extensions)) {
                $output->writeln(
                    sprintf('<error>Extension %s does not exist.</error>', $extension)
                );
                return;
            }
            $extensions = array($extension);
        }

        // do a DB backup first
        $command = $this->getApplication()->find('db:backup');
        $arguments = array(
            'command' => 'db:backup',
        );
        $input = new ArrayInput($arguments);
        $returnCode = $command->run($input, $output);

        if ($returnCode !== 0) {
            $output->writeln('<error>DB backup not performed</error>');
            return;
        }

        // then delete current DB before to init a new copy again
        unlink($this->getApplication()->getDbFilename());

        $pdo = new \PDO('sqlite:' . $this->getApplication()->getDbFilename());
        $ref = new ReferenceCollection($pdo);

        $max = count($extensions);

        $progress = new ProgressBar($output, $max);
        $progress->setFormat(' %percent:3s%% %elapsed:6s% %memory:6s% %message%');
        $progress->setMessage('');

        $progress->start();

        foreach ($extensions as $refName) {
            $pdo->beginTransaction();

            $ext  = 'extensions';
            $progress->setMessage(
                sprintf("Building %s (%s)", $ext, $refName)
            );
            $progress->display();
            $data = $this->readJsonFile($refName, $ext);
            $ref->addExtension($data);

            $ext  = 'releases';
            $data = $this->readJsonFile($refName, $ext);
            foreach ($data as $rec) {
                $ref->addRelease($rec);
            }

            $ext  = 'interfaces';
            $data = $this->readJsonFile($refName, $ext);
            foreach ($data as $rec) {
                $ref->addInterface($rec);
            }

            $ext  = 'classes';
            $data = $this->readJsonFile($refName, $ext);
            foreach ($data as $rec) {
                $ref->addClass($rec);
            }

            $ext  = 'functions';
            $data = $this->readJsonFile($refName, $ext);
            foreach ($data as $rec) {
                $ref->addFunction($rec);
            }

            $ext  = 'constants';
            $data = $this->readJsonFile($refName, $ext);
            foreach ($data as $rec) {
                $ref->addConstant($rec);
            }

            $ext  = 'iniEntries';
            $data = $this->readJsonFile($refName, $ext);
            foreach ($data as $rec) {
                $ref->addIniEntry($rec);
            }

            $ext  = 'const';
            $data = $this->readJsonFile($refName, $ext);
            foreach ($data as $rec) {
                $ref->addClassConstant($rec);
            }

            $ext  = 'methods';
            $data = $this->readJsonFile($refName, $ext);
            foreach ($data as $rec) {
                $ref->addMethod($rec);
            }

            $pdo->commit();
            $progress->advance();
        }
        $ref->addVersion(
            array(
                'build_string'  => date('M d Y H:i:s T'),
                'build_date'    => date('YmdHis'),
                'build_release' => '@package_version@',
            )
        );
        $progress->setMessage('Database is built');
        $progress->display();
        $progress->finish();
        $output->writeln('');
    }

    private function readJsonFile($refName, $ext)
    {
        $filename = $this->getApplication()->getRefDir() . '/' . ucfirst($refName) . ".$ext.json";
        if (!file_exists($filename)) {
            return array();
        }
        $jsonStr = file_get_contents($filename);
        $data    = json_decode($jsonStr, true);
        return $data;
    }
}

/**
 * Update the database with one or more JSON files.
 */
class DbUpdateCommand extends Command
{
    protected function configure()
    {
        $this->setName('db:update')
            ->setDescription('Update an extension with a JSON file')
            ->addArgument(
                'extension',
                InputArgument::REQUIRED,
                'extension to update in database (case insensitive)'
            )
            ->addArgument(
                'group',
                InputArgument::REQUIRED,
                'group of information to update in database (releases, classes, ...)'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $extension = trim($input->getArgument('extension'));
        $group     = trim($input->getArgument('group'));

        $refName = ucfirst(strtolower($extension));
        $ext     = $group;
        $data    = $this->readJsonFile($refName, $ext);

        if (!$data) {
            if (json_last_error() == JSON_ERROR_NONE) {
                $error = sprintf('File %s.%s.json does not exist.', $refName, $ext);
            } else {
                $error = sprintf('Cannot decode file %s.%s.json', $refName, $ext);
            }

            $output->writeln(
                sprintf('<error>%s</error>', $error)
            );
            return;
        }

        $pdo = new \PDO('sqlite:' . $this->getApplication()->getDbFilename());
        $ref = new ReferenceCollection($pdo);

        $max = count($data);

        $progress = new ProgressBar($output, $max);
        $progress->setFormat(' %percent:3s%% %elapsed:6s% %memory:6s% %message%');

        $progress->setMessage(
            sprintf("Building %s (%s)", $ext, $refName)
        );
        $progress->start();

        $updates = 0;
        $adds    = 0;

        foreach ($data as $rec) {
            switch ($ext) {
                case 'releases':
                    $res = $ref->addRelease($rec);
                    break;
                case 'iniEntries':
                    $res = $ref->addIniEntry($rec);
                    break;
                case 'classes':
                    $res = $ref->addClass($rec);
                    break;
                case 'interfaces':
                    $res = $ref->addInterface($rec);
                    break;
                case 'methods':
                    $res = $ref->addMethod($rec);
                    break;
                case 'const':
                    $res = $ref->addClassConstant($rec);
                    break;
                case 'functions':
                    $res = $ref->addFunction($rec);
                    break;
                case 'constants':
                    $res = $ref->addConstant($rec);
                    break;
                default:
                    // do nothing
                    $res = 0;
            }

            if ($res < 0) {
                // update applied
                ++$updates;

            } elseif ($res > 0) {
                // add applied
                ++$adds;
            }

            $progress->advance();
        }
        $progress->finish();
        $progress->clear();

        if (($adds + $updates) === 0) {
            $message = sprintf('No changes on <info>%s</info>', $extension);
        } else {
            $message = sprintf(
                'Extension <info>%s</info> was updated with %d adds, %d updates.',
                $extension,
                $adds,
                $updates
            );
        }

        $output->writeln(PHP_EOL . $message);
    }

    private function readJsonFile($refName, $ext)
    {
        $filename = $this->getApplication()->getRefDir() . '/' . ucfirst($refName) . ".$ext.json";
        if (!file_exists($filename)) {
            return false;
        }
        $jsonStr = file_get_contents($filename);
        $data    = json_decode($jsonStr, true);
        return $data;
    }
}

/**
 * Update JSON files when a new PHP version is released.
 */
class DbReleaseCommand extends Command
{
    const JSON_PRETTY_PRINT = 128;

    protected function configure()
    {
        $this->setName('db:release:php')
            ->setDescription('Fix php.max versions on new PHP release')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $latest  = array();

        $refName = 'Curl';
        $ext     = 'constants';
        $entry   = 'php_max';
        $names   = array(
            'CURLCLOSEPOLICY_CALLBACK'              => ExtensionFactory::LATEST_PHP_5_5,
            'CURLCLOSEPOLICY_LEAST_RECENTLY_USED'   => ExtensionFactory::LATEST_PHP_5_5,
            'CURLCLOSEPOLICY_LEAST_TRAFFIC'         => ExtensionFactory::LATEST_PHP_5_5,
            'CURLCLOSEPOLICY_OLDEST'                => ExtensionFactory::LATEST_PHP_5_5,
            'CURLCLOSEPOLICY_SLOWEST'               => ExtensionFactory::LATEST_PHP_5_5,
            'CURLOPT_CLOSEPOLICY'                   => ExtensionFactory::LATEST_PHP_5_5,
        );
        $latest[] = array($refName, $ext, $entry, $names);

        $refName = 'Core';
        $ext     = 'iniEntries';
        $entry   = 'php_max';
        $names   = array(
            'allow_call_time_pass_reference'        => ExtensionFactory::LATEST_PHP_5_3,
            'define_syslog_variables'               => ExtensionFactory::LATEST_PHP_5_3,
            'highlight.bg'                          => ExtensionFactory::LATEST_PHP_5_3,
            'magic_quotes_gpc'                      => ExtensionFactory::LATEST_PHP_5_3,
            'magic_quotes_runtime'                  => ExtensionFactory::LATEST_PHP_5_3,
            'magic_quotes_sybase'                   => ExtensionFactory::LATEST_PHP_5_3,
            'register_globals'                      => ExtensionFactory::LATEST_PHP_5_3,
            'safe_mode'                             => ExtensionFactory::LATEST_PHP_5_3,
            'safe_mode_exec_dir'                    => ExtensionFactory::LATEST_PHP_5_3,
            'y2k_compliance'                        => ExtensionFactory::LATEST_PHP_5_3,
            'safe_mode_gid'                         => ExtensionFactory::LATEST_PHP_5_3,
            'safe_mode_include_dir'                 => ExtensionFactory::LATEST_PHP_5_3,
            'register_long_arrays'                  => ExtensionFactory::LATEST_PHP_5_3,
        );
        $latest[] = array($refName, $ext, $entry, $names);

        $refName = 'Core';
        $ext     = 'constants';
        $entry   = 'php_max';
        $names   = array(
            'ZEND_MULTIBYTE'                        => ExtensionFactory::LATEST_PHP_5_3,
        );
        $latest[] = array($refName, $ext, $entry, $names);

        $refName = 'Fileinfo';
        $ext     = 'constants';
        $entry   = 'php_max';
        $names   = array(
            'FILEINFO_COMPRESS'                     => ExtensionFactory::LATEST_PHP_5_2,
        );
        $latest[] = array($refName, $ext, $entry, $names);

        $refName = 'Http';
        $ext     = 'releases';
        $entry   = 'php_max';
        $names   = array(
            '0.7.0'                                 => ExtensionFactory::LATEST_PHP_5_5,
            '1.0.0'                                 => ExtensionFactory::LATEST_PHP_5_5,
            '1.3.0'                                 => ExtensionFactory::LATEST_PHP_5_5,
            '1.5.0'                                 => ExtensionFactory::LATEST_PHP_5_5,
        );
        $latest[] = array($refName, $ext, $entry, $names);

        $refName = 'Iconv';
        $ext     = 'functions';
        $entry   = 'php_max';
        $names   = array(
            'ob_iconv_handler'                      => ExtensionFactory::LATEST_PHP_5_3,
        );
        $latest[] = array($refName, $ext, $entry, $names);

        $refName = 'Mysqli';
        $ext     = 'functions';
        $entry   = 'php_max';
        $names   = array(
            'mysqli_bind_param'                     => ExtensionFactory::LATEST_PHP_5_3,
            'mysqli_bind_result'                    => ExtensionFactory::LATEST_PHP_5_3,
            'mysqli_client_encoding'                => ExtensionFactory::LATEST_PHP_5_3,
            'mysqli_disable_reads_from_master'      => ExtensionFactory::LATEST_PHP_5_2,
            'mysqli_disable_rpl_parse'              => ExtensionFactory::LATEST_PHP_5_2,
            'mysqli_enable_reads_from_master'       => ExtensionFactory::LATEST_PHP_5_2,
            'mysqli_enable_rpl_parse'               => ExtensionFactory::LATEST_PHP_5_2,
            'mysqli_fetch'                          => ExtensionFactory::LATEST_PHP_5_3,
            'mysqli_get_metadata'                   => ExtensionFactory::LATEST_PHP_5_3,
            'mysqli_master_query'                   => ExtensionFactory::LATEST_PHP_5_2,
            'mysqli_param_count'                    => ExtensionFactory::LATEST_PHP_5_3,
            'mysqli_rpl_parse_enabled'              => ExtensionFactory::LATEST_PHP_5_2,
            'mysqli_rpl_probe'                      => ExtensionFactory::LATEST_PHP_5_2,
            'mysqli_rpl_query_type'                 => ExtensionFactory::LATEST_PHP_5_2,
            'mysqli_send_long_data'                 => ExtensionFactory::LATEST_PHP_5_3,
            'mysqli_send_query'                     => ExtensionFactory::LATEST_PHP_5_2,
            'mysqli_slave_query'                    => ExtensionFactory::LATEST_PHP_5_2,
        );
        $latest[] = array($refName, $ext, $entry, $names);

        $refName = 'Mysqli';
        $ext     = 'constants';
        $entry   = 'php_max';
        $names   = array(
            'MYSQLI_RPL_ADMIN'                      => ExtensionFactory::LATEST_PHP_5_2,
            'MYSQLI_RPL_MASTER'                     => ExtensionFactory::LATEST_PHP_5_2,
            'MYSQLI_RPL_SLAVE'                      => ExtensionFactory::LATEST_PHP_5_2,
        );
        $latest[] = array($refName, $ext, $entry, $names);

        $refName = 'Session';
        $ext     = 'functions';
        $entry   = 'php_max';
        $names   = array(
            'session_is_registered'                 => ExtensionFactory::LATEST_PHP_5_3,
            'session_register'                      => ExtensionFactory::LATEST_PHP_5_3,
            'session_unregister'                    => ExtensionFactory::LATEST_PHP_5_3,
        );
        $latest[] = array($refName, $ext, $entry, $names);

        $refName = 'Spl';
        $ext     = 'interfaces';
        $entry   = 'ext_max';
        $names   = array(
            'ArrayAccess'                           => ExtensionFactory::LATEST_PHP_5_2,
            'Iterator'                              => ExtensionFactory::LATEST_PHP_5_2,
            'IteratorAggregate'                     => ExtensionFactory::LATEST_PHP_5_2,
            'Serializable'                          => ExtensionFactory::LATEST_PHP_5_2,
            'Traversable'                           => ExtensionFactory::LATEST_PHP_5_2,
        );
        $latest[] = array($refName, $ext, $entry, $names);

        $refName = 'Spl';
        $ext     = 'classes';
        $entry   = 'ext_max';
        $names   = array(
            'SimpleXMLIterator'                     => ExtensionFactory::LATEST_PHP_5_2,
        );
        $latest[] = array($refName, $ext, $entry, $names);

        $refName = 'Standard';
        $ext     = 'iniEntries';
        $entry   = 'php_max';
        $names   = array(
            'safe_mode_allowed_env_vars'            => ExtensionFactory::LATEST_PHP_5_3,
            'safe_mode_protected_env_vars'          => ExtensionFactory::LATEST_PHP_5_3,
        );
        $latest[] = array($refName, $ext, $entry, $names);

        $refName = 'Standard';
        $ext     = 'functions';
        $entry   = 'php_max';
        $names   = array(
            'define_syslog_variables'               => ExtensionFactory::LATEST_PHP_5_3,
            'php_logo_guid'                         => ExtensionFactory::LATEST_PHP_5_4,
            'php_real_logo_guid'                    => ExtensionFactory::LATEST_PHP_5_4,
            'zend_logo_guid'                        => ExtensionFactory::LATEST_PHP_5_4,
            'php_egg_logo_guid'                     => ExtensionFactory::LATEST_PHP_5_4,
            'import_request_variables'              => ExtensionFactory::LATEST_PHP_5_3,
        );
        $latest[] = array($refName, $ext, $entry, $names);

        $refName = 'Standard';
        $ext     = 'constants';
        $entry   = 'php_max';
        $names   = array(
            'STREAM_ENFORCE_SAFE_MODE'              => ExtensionFactory::LATEST_PHP_5_3,
        );
        $latest[] = array($refName, $ext, $entry, $names);

        $refName = 'Tidy';
        $ext     = 'functions';
        $entry   = 'php_max';
        $names   = array(
            'ob_tidyhandler'                        => ExtensionFactory::LATEST_PHP_5_3,
        );
        $latest[] = array($refName, $ext, $entry, $names);

        // tag MAX version
        while (!empty($latest)) {
            list($refName, $ext, $entry, $names) = array_pop($latest);

            $data = $this->readJsonFile($refName, $ext);

            if (!$data) {
                if (json_last_error() == JSON_ERROR_NONE) {
                    $error = sprintf('File %s.%s.json does not exist.', $refName, $ext);
                } else {
                    $error = sprintf('Cannot decode file %s.%s.json', $refName, $ext);
                }

                $output->writeln(
                    sprintf('<error>%s</error>', $error)
                );
                return;
            }

            $key = $ext == 'releases' ? 'rel_version' : 'name';

            foreach ($data as &$element) {
                if (array_key_exists($element[$key], $names)) {
                    $element[$entry] = $names[$element[$key]];
                }
            }
            $this->writeJsonFile($refName, $ext, $data);
        }
    }

    private function readJsonFile($refName, $ext)
    {
        $filename = $this->getApplication()->getRefDir() . '/' . ucfirst($refName) . ".$ext.json";
        if (!file_exists($filename)) {
            return false;
        }
        $jsonStr = file_get_contents($filename);
        $data    = json_decode($jsonStr, true);
        return $data;
    }

    private function writeJsonFile($refName, $ext, $data)
    {
        $filename = $this->getApplication()->getRefDir() . '/' . ucfirst($refName) . ".$ext.json";
        if (!file_exists($filename)) {
            return false;
        }
        $jsonStr = json_encode($data, self::JSON_PRETTY_PRINT);
        file_put_contents($filename, $jsonStr);
    }
}

/**
 * Symfony Console Application to handle the SQLite compatinfo database.
 */
class DbHandleApplication extends Application
{
    protected function getDefaultCommands()
    {
        $defaultCommands = parent::getDefaultCommands();

        $defaultCommands[] = new DbBackupCommand();
        $defaultCommands[] = new DbInitCommand();
        $defaultCommands[] = new DbUpdateCommand();
        $defaultCommands[] = new DbReleaseCommand();

        return $defaultCommands;
    }

    public function getDbFilename()
    {
        $database = 'compatinfo.sqlite';
        $source   = __DIR__ . '/' . $database;

        return $source;
    }

    public function getAppTempDir()
    {
        return sys_get_temp_dir() . '/bartlett';
    }

    public function getRefDir()
    {
        return __DIR__ . '/references';
    }
}

$application = new DbHandleApplication();
$application->run();

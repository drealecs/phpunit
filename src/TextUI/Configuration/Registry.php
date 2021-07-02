<?php declare(strict_types=1);
/*
 * This file is part of PHPUnit.
 *
 * (c) Sebastian Bergmann <sebastian@phpunit.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace PHPUnit\TextUI\Configuration;

use const DIRECTORY_SEPARATOR;
use function array_diff;
use function assert;
use function defined;
use function dirname;
use function implode;
use function is_dir;
use function is_file;
use function is_int;
use function is_readable;
use function realpath;
use function substr;
use function time;
use PHPUnit\Event\Facade;
use PHPUnit\Framework\TestSuite;
use PHPUnit\Logging\TeamCityLogger;
use PHPUnit\Logging\TestDox\CliTestDoxPrinter;
use PHPUnit\Runner\TestSuiteLoader;
use PHPUnit\Runner\TestSuiteSorter;
use PHPUnit\TextUI\BootstrapException;
use PHPUnit\TextUI\CliArguments\Configuration as CliConfiguration;
use PHPUnit\TextUI\DefaultResultPrinter;
use PHPUnit\TextUI\InvalidBootstrapException;
use PHPUnit\TextUI\TestFileNotFoundException;
use PHPUnit\TextUI\XmlConfiguration\CodeCoverage\FilterMapper;
use PHPUnit\TextUI\XmlConfiguration\Configuration as XmlConfiguration;
use PHPUnit\TextUI\XmlConfiguration\LoadedFromFileConfiguration;
use PHPUnit\TextUI\XmlConfiguration\TestSuiteMapper;
use PHPUnit\Util\Filesystem;
use SebastianBergmann\CodeCoverage\Filter as CodeCoverageFilter;
use SebastianBergmann\Environment\Console;
use SebastianBergmann\FileIterator\Facade as FileIteratorFacade;
use Throwable;

/**
 * CLI options and XML configuration are static within a single PHPUnit process.
 * It is therefore okay to use a Singleton registry here.
 *
 * @internal This class is not covered by the backward compatibility promise for PHPUnit
 */
final class Registry
{
    private static ?Configuration $instance = null;

    public static function get(): Configuration
    {
        assert(self::$instance instanceof Configuration);

        return self::$instance;
    }

    /**
     * @throws TestFileNotFoundException
     */
    public static function init(CliConfiguration $cliConfiguration, XmlConfiguration $xmlConfiguration): Configuration
    {
        $warnings = [];

        $bootstrap = null;

        $configurationFile = null;

        if ($xmlConfiguration->wasLoadedFromFile()) {
            assert($xmlConfiguration instanceof LoadedFromFileConfiguration);

            $configurationFile = $xmlConfiguration->filename();
        }

        if ($cliConfiguration->hasBootstrap()) {
            $bootstrap = $cliConfiguration->bootstrap();
        } elseif ($xmlConfiguration->phpunit()->hasBootstrap()) {
            $bootstrap = $xmlConfiguration->phpunit()->bootstrap();
        }

        if ($bootstrap !== null) {
            self::handleBootstrap($bootstrap);
        }

        if ($cliConfiguration->hasArgument()) {
            $argument = realpath($cliConfiguration->argument());

            if (!$argument) {
                throw new TestFileNotFoundException($cliConfiguration->argument());
            }

            $testSuite = self::testSuiteFromPath(
                $argument,
                self::testSuffixes($cliConfiguration)
            );
        } else {
            $includeTestSuite = '';

            if ($cliConfiguration->hasTestSuite()) {
                $includeTestSuite = $cliConfiguration->testSuite();
            } elseif ($xmlConfiguration->phpunit()->hasDefaultTestSuite()) {
                $includeTestSuite = $xmlConfiguration->phpunit()->defaultTestSuite();
            }

            $testSuite = (new TestSuiteMapper)->map(
                $xmlConfiguration->testSuite(),
                $includeTestSuite,
                $cliConfiguration->hasExcludedTestSuite() ? $cliConfiguration->excludedTestSuite() : ''
            );
        }

        if ($cliConfiguration->hasCacheResult()) {
            $cacheResult = $cliConfiguration->cacheResult();
        } else {
            $cacheResult = $xmlConfiguration->phpunit()->cacheResult();
        }

        $cacheDirectory         = null;
        $coverageCacheDirectory = null;

        if ($cliConfiguration->hasCacheDirectory() && Filesystem::createDirectory($cliConfiguration->cacheDirectory())) {
            $cacheDirectory = realpath($cliConfiguration->cacheDirectory());
        } elseif ($xmlConfiguration->phpunit()->hasCacheDirectory() && Filesystem::createDirectory($xmlConfiguration->phpunit()->cacheDirectory())) {
            $cacheDirectory = realpath($xmlConfiguration->phpunit()->cacheDirectory());
        }

        if ($cacheDirectory !== null) {
            $coverageCacheDirectory = $cacheDirectory . DIRECTORY_SEPARATOR . 'code-coverage';
            $testResultCacheFile    = $cacheDirectory . DIRECTORY_SEPARATOR . 'test-results';
        }

        if ($coverageCacheDirectory === null) {
            if ($cliConfiguration->hasCoverageCacheDirectory() && Filesystem::createDirectory($cliConfiguration->coverageCacheDirectory())) {
                $coverageCacheDirectory = realpath($cliConfiguration->coverageCacheDirectory());
            } elseif ($xmlConfiguration->codeCoverage()->hasCacheDirectory()) {
                $coverageCacheDirectory = $xmlConfiguration->codeCoverage()->cacheDirectory()->path();
            }
        }

        if (!isset($testResultCacheFile)) {
            if ($cliConfiguration->hasCacheResultFile()) {
                $testResultCacheFile = $cliConfiguration->cacheResultFile();
            } elseif ($xmlConfiguration->phpunit()->hasCacheResultFile()) {
                $testResultCacheFile = $xmlConfiguration->phpunit()->cacheResultFile();
            } elseif ($xmlConfiguration->wasLoadedFromFile()) {
                $testResultCacheFile = dirname(realpath($xmlConfiguration->filename())) . DIRECTORY_SEPARATOR . '.phpunit.result.cache';
            } else {
                $candidate = realpath($_SERVER['PHP_SELF']);

                if ($candidate) {
                    $testResultCacheFile = dirname($candidate) . DIRECTORY_SEPARATOR . '.phpunit.result.cache';
                } else {
                    $testResultCacheFile = '.phpunit.result.cache';
                }
            }
        }

        $codeCoverageFilter = new CodeCoverageFilter;

        if ($cliConfiguration->hasCoverageFilter()) {
            foreach ($cliConfiguration->coverageFilter() as $directory) {
                $codeCoverageFilter->includeDirectory($directory);
            }
        }

        if ($xmlConfiguration->codeCoverage()->hasNonEmptyListOfFilesToBeIncludedInCodeCoverageReport()) {
            (new FilterMapper)->map(
                $codeCoverageFilter,
                $xmlConfiguration->codeCoverage()
            );
        }

        if ($cliConfiguration->hasDisableCodeCoverageIgnore()) {
            $disableCodeCoverageIgnore = $cliConfiguration->disableCodeCoverageIgnore();
        } else {
            $disableCodeCoverageIgnore = $xmlConfiguration->codeCoverage()->disableCodeCoverageIgnore();
        }

        if ($cliConfiguration->hasFailOnEmptyTestSuite()) {
            $failOnEmptyTestSuite = $cliConfiguration->failOnEmptyTestSuite();
        } else {
            $failOnEmptyTestSuite = $xmlConfiguration->phpunit()->failOnEmptyTestSuite();
        }

        if ($cliConfiguration->hasFailOnIncomplete()) {
            $failOnIncomplete = $cliConfiguration->failOnIncomplete();
        } else {
            $failOnIncomplete = $xmlConfiguration->phpunit()->failOnIncomplete();
        }

        if ($cliConfiguration->hasFailOnRisky()) {
            $failOnRisky = $cliConfiguration->failOnRisky();
        } else {
            $failOnRisky = $xmlConfiguration->phpunit()->failOnRisky();
        }

        if ($cliConfiguration->hasFailOnSkipped()) {
            $failOnSkipped = $cliConfiguration->failOnSkipped();
        } else {
            $failOnSkipped = $xmlConfiguration->phpunit()->failOnSkipped();
        }

        if ($cliConfiguration->hasFailOnWarning()) {
            $failOnWarning = $cliConfiguration->failOnWarning();
        } else {
            $failOnWarning = $xmlConfiguration->phpunit()->failOnWarning();
        }

        if ($cliConfiguration->hasStderr() && $cliConfiguration->stderr()) {
            $outputToStandardErrorStream = true;
        } else {
            $outputToStandardErrorStream = $xmlConfiguration->phpunit()->stderr();
        }

        $tooFewColumnsRequested = false;

        if ($cliConfiguration->hasColumns()) {
            $columns = $cliConfiguration->columns();
        } else {
            $columns = $xmlConfiguration->phpunit()->columns();
        }

        if (is_int($columns) && $columns < 16) {
            $columns                = 16;
            $tooFewColumnsRequested = true;
        }

        $loadPharExtensions = true;

        if ($cliConfiguration->hasNoExtensions() && $cliConfiguration->noExtensions()) {
            $loadPharExtensions = false;
        }

        $pharExtensionDirectory = null;

        if ($xmlConfiguration->phpunit()->hasExtensionsDirectory()) {
            $pharExtensionDirectory = $xmlConfiguration->phpunit()->extensionsDirectory();
        }

        if ($cliConfiguration->hasPathCoverage() && $cliConfiguration->pathCoverage()) {
            $pathCoverage = $cliConfiguration->pathCoverage();
        } else {
            $pathCoverage = $xmlConfiguration->codeCoverage()->pathCoverage();
        }

        $debug = false;

        if ($cliConfiguration->hasDebug() && $cliConfiguration->debug()) {
            $debug = true;

            if (!defined('PHPUNIT_TESTSUITE')) {
                $warnings[] = 'The --debug option is deprecated';
            }
        }

        $coverageClover                 = null;
        $coverageCobertura              = null;
        $coverageCrap4j                 = null;
        $coverageCrap4jThreshold        = 30;
        $coverageHtml                   = null;
        $coverageHtmlLowUpperBound      = 50;
        $coverageHtmlHighLowerBound     = 90;
        $coveragePhp                    = null;
        $coverageText                   = null;
        $coverageTextShowUncoveredFiles = false;
        $coverageTextShowOnlySummary    = false;
        $coverageXml                    = null;

        if (!($cliConfiguration->hasNoCoverage() && $cliConfiguration->noCoverage())) {
            if ($cliConfiguration->hasCoverageClover()) {
                $coverageClover = $cliConfiguration->coverageClover();
            } elseif ($xmlConfiguration->codeCoverage()->hasClover()) {
                $coverageClover = $xmlConfiguration->codeCoverage()->clover()->target()->path();
            }

            if ($cliConfiguration->hasCoverageCobertura()) {
                $coverageCobertura = $cliConfiguration->coverageCobertura();
            } elseif ($xmlConfiguration->codeCoverage()->hasCobertura()) {
                $coverageCobertura = $xmlConfiguration->codeCoverage()->cobertura()->target()->path();
            }

            if ($xmlConfiguration->codeCoverage()->hasCrap4j()) {
                $coverageCrap4jThreshold = $xmlConfiguration->codeCoverage()->crap4j()->threshold();
            }

            if ($cliConfiguration->hasCoverageCrap4J()) {
                $coverageCrap4j = $cliConfiguration->coverageCrap4J();
            } elseif ($xmlConfiguration->codeCoverage()->hasCrap4j()) {
                $coverageCrap4j = $xmlConfiguration->codeCoverage()->crap4j()->target()->path();
            }

            if ($xmlConfiguration->codeCoverage()->hasHtml()) {
                $coverageHtmlHighLowerBound = $xmlConfiguration->codeCoverage()->html()->highLowerBound();
                $coverageHtmlLowUpperBound  = $xmlConfiguration->codeCoverage()->html()->lowUpperBound();
            }

            if ($cliConfiguration->hasCoverageHtml()) {
                $coverageHtml = $cliConfiguration->coverageHtml();
            } elseif ($xmlConfiguration->codeCoverage()->hasHtml()) {
                $coverageHtml = $xmlConfiguration->codeCoverage()->html()->target()->path();
            }

            if ($cliConfiguration->hasCoveragePhp()) {
                $coveragePhp = $cliConfiguration->coveragePhp();
            } elseif ($xmlConfiguration->codeCoverage()->hasPhp()) {
                $coveragePhp = $xmlConfiguration->codeCoverage()->php()->target()->path();
            }

            if ($xmlConfiguration->codeCoverage()->hasText()) {
                $coverageTextShowUncoveredFiles = $xmlConfiguration->codeCoverage()->text()->showUncoveredFiles();
                $coverageTextShowOnlySummary    = $xmlConfiguration->codeCoverage()->text()->showOnlySummary();
            }

            if ($cliConfiguration->hasCoverageText()) {
                $coverageText = $cliConfiguration->coverageText();
            } elseif ($xmlConfiguration->codeCoverage()->hasText()) {
                $coverageText = $xmlConfiguration->codeCoverage()->text()->target()->path();
            }

            if ($cliConfiguration->hasCoverageXml()) {
                $coverageXml = $cliConfiguration->coverageXml();
            } elseif ($xmlConfiguration->codeCoverage()->hasXml()) {
                $coverageXml = $xmlConfiguration->codeCoverage()->xml()->target()->path();
            }
        }

        if ($cliConfiguration->hasBackupGlobals()) {
            $backupGlobals = $cliConfiguration->backupGlobals();
        } else {
            $backupGlobals = $xmlConfiguration->phpunit()->backupGlobals();
        }

        if ($cliConfiguration->hasBackupStaticProperties()) {
            $backupStaticProperties = $cliConfiguration->backupStaticProperties();
        } else {
            $backupStaticProperties = $xmlConfiguration->phpunit()->backupStaticProperties();
        }

        if ($cliConfiguration->hasBeStrictAboutChangesToGlobalState()) {
            $beStrictAboutChangesToGlobalState = $cliConfiguration->beStrictAboutChangesToGlobalState();
        } else {
            $beStrictAboutChangesToGlobalState = $xmlConfiguration->phpunit()->beStrictAboutChangesToGlobalState();
        }

        $convertDeprecationsToExceptions = $xmlConfiguration->phpunit()->convertDeprecationsToExceptions();
        $convertErrorsToExceptions       = $xmlConfiguration->phpunit()->convertErrorsToExceptions();
        $convertNoticesToExceptions      = $xmlConfiguration->phpunit()->convertNoticesToExceptions();
        $convertWarningsToExceptions     = $xmlConfiguration->phpunit()->convertWarningsToExceptions();

        if ($cliConfiguration->hasProcessIsolation()) {
            $processIsolation = $cliConfiguration->processIsolation();
        } else {
            $processIsolation = $xmlConfiguration->phpunit()->processIsolation();
        }

        if ($cliConfiguration->hasStopOnDefect()) {
            $stopOnDefect = $cliConfiguration->stopOnDefect();
        } else {
            $stopOnDefect = $xmlConfiguration->phpunit()->stopOnDefect();
        }

        if ($cliConfiguration->hasStopOnError()) {
            $stopOnError = $cliConfiguration->stopOnError();
        } else {
            $stopOnError = $xmlConfiguration->phpunit()->stopOnError();
        }

        if ($cliConfiguration->hasStopOnFailure()) {
            $stopOnFailure = $cliConfiguration->stopOnFailure();
        } else {
            $stopOnFailure = $xmlConfiguration->phpunit()->stopOnFailure();
        }

        if ($cliConfiguration->hasStopOnWarning()) {
            $stopOnWarning = $cliConfiguration->stopOnWarning();
        } else {
            $stopOnWarning = $xmlConfiguration->phpunit()->stopOnWarning();
        }

        if ($cliConfiguration->hasStopOnIncomplete()) {
            $stopOnIncomplete = $cliConfiguration->stopOnIncomplete();
        } else {
            $stopOnIncomplete = $xmlConfiguration->phpunit()->stopOnIncomplete();
        }

        if ($cliConfiguration->hasStopOnRisky()) {
            $stopOnRisky = $cliConfiguration->stopOnRisky();
        } else {
            $stopOnRisky = $xmlConfiguration->phpunit()->stopOnRisky();
        }

        if ($cliConfiguration->hasStopOnSkipped()) {
            $stopOnSkipped = $cliConfiguration->stopOnSkipped();
        } else {
            $stopOnSkipped = $xmlConfiguration->phpunit()->stopOnSkipped();
        }

        if ($cliConfiguration->hasEnforceTimeLimit()) {
            $enforceTimeLimit = $cliConfiguration->enforceTimeLimit();
        } else {
            $enforceTimeLimit = $xmlConfiguration->phpunit()->enforceTimeLimit();
        }

        if ($cliConfiguration->hasDefaultTimeLimit()) {
            $defaultTimeLimit = $cliConfiguration->defaultTimeLimit();
        } else {
            $defaultTimeLimit = $xmlConfiguration->phpunit()->defaultTimeLimit();
        }

        $timeoutForSmallTests  = $xmlConfiguration->phpunit()->timeoutForSmallTests();
        $timeoutForMediumTests = $xmlConfiguration->phpunit()->timeoutForMediumTests();
        $timeoutForLargeTests  = $xmlConfiguration->phpunit()->timeoutForLargeTests();

        if ($cliConfiguration->hasReportUselessTests()) {
            $reportUselessTests = $cliConfiguration->reportUselessTests();
        } else {
            $reportUselessTests = $xmlConfiguration->phpunit()->beStrictAboutTestsThatDoNotTestAnything();
        }

        if ($cliConfiguration->hasStrictCoverage()) {
            $strictCoverage = $cliConfiguration->strictCoverage();
        } else {
            $strictCoverage = $xmlConfiguration->phpunit()->beStrictAboutCoversAnnotation();
        }

        if ($cliConfiguration->hasDisallowTestOutput()) {
            $disallowTestOutput = $cliConfiguration->disallowTestOutput();
        } else {
            $disallowTestOutput = $xmlConfiguration->phpunit()->beStrictAboutOutputDuringTests();
        }

        if ($cliConfiguration->hasVerbose()) {
            $verbose = $cliConfiguration->verbose();
        } else {
            $verbose = $xmlConfiguration->phpunit()->verbose();
        }

        if ($cliConfiguration->hasReverseList()) {
            $reverseDefectList = $cliConfiguration->reverseList();
        } else {
            $reverseDefectList = $xmlConfiguration->phpunit()->reverseDefectList();
        }

        $forceCoversAnnotation                           = $xmlConfiguration->phpunit()->forceCoversAnnotation();
        $registerMockObjectsFromTestArgumentsRecursively = $xmlConfiguration->phpunit()->registerMockObjectsFromTestArgumentsRecursively();

        if ($cliConfiguration->hasNoInteraction()) {
            $noInteraction = $cliConfiguration->noInteraction();
        } else {
            $noInteraction = $xmlConfiguration->phpunit()->noInteraction();
        }

        if ($cliConfiguration->hasExecutionOrder()) {
            $executionOrder = $cliConfiguration->executionOrder();
        } else {
            $executionOrder = $xmlConfiguration->phpunit()->executionOrder();
        }

        $executionOrderDefects = TestSuiteSorter::ORDER_DEFAULT;

        if ($cliConfiguration->hasExecutionOrderDefects()) {
            $executionOrderDefects = $cliConfiguration->executionOrderDefects();
        } elseif ($xmlConfiguration->phpunit()->defectsFirst()) {
            $executionOrderDefects = TestSuiteSorter::ORDER_DEFECTS_FIRST;
        }

        if ($cliConfiguration->hasResolveDependencies()) {
            $resolveDependencies = $cliConfiguration->resolveDependencies();
        } else {
            $resolveDependencies = $xmlConfiguration->phpunit()->resolveDependencies();
        }

        $colors          = false;
        $colorsSupported = (new Console)->hasColorSupport();

        if ($cliConfiguration->hasColors()) {
            if ($cliConfiguration->colors() === DefaultResultPrinter::COLOR_ALWAYS) {
                $colors = true;
            } elseif ($cliConfiguration->colors() === DefaultResultPrinter::COLOR_AUTO && $colorsSupported) {
                $colors = true;
            }
        } elseif ($xmlConfiguration->phpunit()->colors() === DefaultResultPrinter::COLOR_ALWAYS) {
            $colors = true;
        } elseif ($xmlConfiguration->phpunit()->colors() === DefaultResultPrinter::COLOR_AUTO && $colorsSupported) {
            $colors = true;
        }

        $logfileText                 = null;
        $logfileTeamcity             = null;
        $logfileJunit                = null;
        $logfileTestdoxHtml          = null;
        $logfileTestdoxText          = null;
        $logfileTestdoxXml           = null;
        $loggingFromXmlConfiguration = true;

        if ($cliConfiguration->hasNoLogging() && $cliConfiguration->noLogging()) {
            $loggingFromXmlConfiguration = false;
        }

        if ($loggingFromXmlConfiguration && $xmlConfiguration->logging()->hasText()) {
            $logfileText = $xmlConfiguration->logging()->text()->target()->path();
        }

        if ($cliConfiguration->hasTeamcityLogfile()) {
            $logfileTeamcity = $cliConfiguration->teamcityLogfile();
        } elseif ($loggingFromXmlConfiguration && $xmlConfiguration->logging()->hasTeamCity()) {
            $logfileTeamcity = $xmlConfiguration->logging()->teamCity()->target()->path();
        }

        if ($cliConfiguration->hasJunitLogfile()) {
            $logfileJunit = $cliConfiguration->junitLogfile();
        } elseif ($loggingFromXmlConfiguration && $xmlConfiguration->logging()->hasJunit()) {
            $logfileJunit = $xmlConfiguration->logging()->junit()->target()->path();
        }

        if ($cliConfiguration->hasTestdoxHtmlFile()) {
            $logfileTestdoxHtml = $cliConfiguration->testdoxHtmlFile();
        } elseif ($loggingFromXmlConfiguration && $xmlConfiguration->logging()->hasTestDoxHtml()) {
            $logfileTestdoxHtml = $xmlConfiguration->logging()->testDoxHtml()->target()->path();
        }

        if ($cliConfiguration->hasTestdoxTextFile()) {
            $logfileTestdoxText = $cliConfiguration->testdoxTextFile();
        } elseif ($loggingFromXmlConfiguration && $xmlConfiguration->logging()->hasTestDoxText()) {
            $logfileTestdoxText = $xmlConfiguration->logging()->testDoxText()->target()->path();
        }

        if ($cliConfiguration->hasTestdoxXmlFile()) {
            $logfileTestdoxXml = $cliConfiguration->testdoxXmlFile();
        } elseif ($loggingFromXmlConfiguration && $xmlConfiguration->logging()->hasTestDoxXml()) {
            $logfileTestdoxXml = $xmlConfiguration->logging()->testDoxXml()->target()->path();
        }

        $plainTextTrace = null;

        if ($cliConfiguration->hasPlainTextTrace()) {
            $plainTextTrace = $cliConfiguration->plainTextTrace();
        }

        $printerClassName = DefaultResultPrinter::class;

        if ($cliConfiguration->hasTeamCityPrinter() && $cliConfiguration->teamCityPrinter()) {
            $printerClassName = TeamCityLogger::class;
        } elseif ($cliConfiguration->hasTestDoxPrinter() && $cliConfiguration->testdoxPrinter()) {
            $printerClassName = CliTestDoxPrinter::class;
        }

        $repeat = 0;

        if ($cliConfiguration->hasRepeat()) {
            $repeat = $cliConfiguration->repeat();
        }

        $testsCovering = null;

        if ($cliConfiguration->hasTestsCovering()) {
            $testsCovering = $cliConfiguration->testsCovering();
        }

        $testsUsing = null;

        if ($cliConfiguration->hasTestsUsing()) {
            $testsUsing = $cliConfiguration->testsUsing();
        }

        $filter = null;

        if ($cliConfiguration->hasFilter()) {
            $filter = $cliConfiguration->filter();
        }

        if ($cliConfiguration->hasGroups()) {
            $groups = $cliConfiguration->groups();
        } else {
            $groups = $xmlConfiguration->groups()->include()->asArrayOfStrings();
        }

        if ($cliConfiguration->hasExcludeGroups()) {
            $excludeGroups = $cliConfiguration->excludeGroups();
        } else {
            $excludeGroups = $xmlConfiguration->groups()->exclude()->asArrayOfStrings();
        }

        $excludeGroups = array_diff($excludeGroups, $groups);

        if ($cliConfiguration->hasTestdoxGroups()) {
            $testdoxGroups = $cliConfiguration->testdoxGroups();
        } else {
            $testdoxGroups = $xmlConfiguration->testdoxGroups()->include()->asArrayOfStrings();
        }

        if ($cliConfiguration->hasTestdoxExcludeGroups()) {
            $testdoxExcludeGroups = $cliConfiguration->testdoxExcludeGroups();
        } else {
            $testdoxExcludeGroups = $xmlConfiguration->testdoxGroups()->exclude()->asArrayOfStrings();
        }

        $includePath = null;

        if ($cliConfiguration->hasIncludePath()) {
            $includePath = $cliConfiguration->includePath();
        } elseif (!$xmlConfiguration->php()->includePaths()->isEmpty()) {
            $includePathsAsStrings = [];

            foreach ($xmlConfiguration->php()->includePaths() as $includePath) {
                $includePathsAsStrings[] = $includePath->path();
            }

            $includePath = implode(PATH_SEPARATOR, $includePathsAsStrings);
        }

        if ($cliConfiguration->hasRandomOrderSeed()) {
            $randomOrderSeed = $cliConfiguration->randomOrderSeed();
        } else {
            $randomOrderSeed = time();
        }

        $xmlValidationErrors = null;

        if ($xmlConfiguration->wasLoadedFromFile() && $xmlConfiguration->hasValidationErrors()) {
            $xmlValidationErrors = $xmlConfiguration->validationErrors();
        }

        self::$instance = new Configuration(
            $testSuite,
            $configurationFile,
            $bootstrap,
            $cacheResult,
            $cacheDirectory,
            $coverageCacheDirectory,
            $testResultCacheFile,
            $codeCoverageFilter,
            $coverageClover,
            $coverageCobertura,
            $coverageCrap4j,
            $coverageCrap4jThreshold,
            $coverageHtml,
            $coverageHtmlLowUpperBound,
            $coverageHtmlHighLowerBound,
            $coveragePhp,
            $coverageText,
            $coverageTextShowUncoveredFiles,
            $coverageTextShowOnlySummary,
            $coverageXml,
            $pathCoverage,
            $xmlConfiguration->codeCoverage()->ignoreDeprecatedCodeUnits(),
            $disableCodeCoverageIgnore,
            $failOnEmptyTestSuite,
            $failOnIncomplete,
            $failOnRisky,
            $failOnSkipped,
            $failOnWarning,
            $outputToStandardErrorStream,
            $columns,
            $tooFewColumnsRequested,
            $loadPharExtensions,
            $pharExtensionDirectory,
            $debug,
            $backupGlobals,
            $backupStaticProperties,
            $beStrictAboutChangesToGlobalState,
            $colors,
            $convertDeprecationsToExceptions,
            $convertErrorsToExceptions,
            $convertNoticesToExceptions,
            $convertWarningsToExceptions,
            $processIsolation,
            $stopOnDefect,
            $stopOnError,
            $stopOnFailure,
            $stopOnWarning,
            $stopOnIncomplete,
            $stopOnRisky,
            $stopOnSkipped,
            $enforceTimeLimit,
            $defaultTimeLimit,
            $timeoutForSmallTests,
            $timeoutForMediumTests,
            $timeoutForLargeTests,
            $reportUselessTests,
            $strictCoverage,
            $disallowTestOutput,
            $verbose,
            $reverseDefectList,
            $forceCoversAnnotation,
            $registerMockObjectsFromTestArgumentsRecursively,
            $noInteraction,
            $executionOrder,
            $executionOrderDefects,
            $resolveDependencies,
            $logfileText,
            $logfileTeamcity,
            $logfileJunit,
            $logfileTestdoxHtml,
            $logfileTestdoxText,
            $logfileTestdoxXml,
            $plainTextTrace,
            $printerClassName,
            $repeat,
            $testsCovering,
            $testsUsing,
            $filter,
            $groups,
            $excludeGroups,
            $testdoxGroups,
            $testdoxExcludeGroups,
            $includePath,
            $randomOrderSeed,
            $xmlValidationErrors,
            $warnings
        );

        return self::$instance;
    }

    /**
     * @psalm-param list<string> $suffixes
     */
    private static function testSuiteFromPath(string $path, array $suffixes): TestSuite
    {
        if (is_dir($path)) {
            $files = (new FileIteratorFacade)->getFilesAsArray($path, $suffixes);

            $suite = new TestSuite($path);
            $suite->addTestFiles($files);

            return $suite;
        }

        if (is_file($path) && substr($path, -5, 5) === '.phpt') {
            $suite = new TestSuite;
            $suite->addTestFile($path);

            return $suite;
        }

        try {
            $testClass = (new TestSuiteLoader)->load($path);
        } catch (\PHPUnit\Exception $e) {
            print $e->getMessage() . PHP_EOL;

            exit(1);
        }

        return new TestSuite($testClass);
    }

    private static function testSuffixes(CliConfiguration $cliConfiguration): array
    {
        $testSuffixes = ['Test.php', '.phpt'];

        if ($cliConfiguration->hasTestSuffixes()) {
            $testSuffixes = $cliConfiguration->testSuffixes();
        }

        return $testSuffixes;
    }

    /**
     * @throws InvalidBootstrapException
     */
    private static function handleBootstrap(string $filename): void
    {
        if (!is_readable($filename)) {
            throw new InvalidBootstrapException($filename);
        }

        try {
            include $filename;
        } catch (Throwable $t) {
            throw new BootstrapException($t);
        }

        Facade::emitter()->bootstrapFinished($filename);
    }
}

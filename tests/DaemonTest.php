<?php declare(strict_types = 1);

namespace Mikk3lRo\atomix\Tests;

use Exception;
use Mikk3lRo\atomix\daemond\DaemonAbstract;
use Mikk3lRo\atomix\Tests\TestDaemon;
use Mikk3lRo\atomix\utilities\Processes;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../testFiles/TestDaemon.php';

putenv('isUnitTest=1');

/**
 * @covers Mikk3lRo\atomix\daemond\Traits\SystemServiceTrait
 * @covers Mikk3lRo\atomix\daemond\Traits\CliInvocationTrait
 * @covers Mikk3lRo\atomix\daemond\Traits\IpcInvocationTrait
 * @covers Mikk3lRo\atomix\daemond\DaemonAbstract
 * @covers Mikk3lRo\atomix\daemond\IpcCommand
 * @covers Mikk3lRo\atomix\daemond\IpcResponse
 * @covers Mikk3lRo\atomix\daemond\IpcSuccess
 * @covers Mikk3lRo\atomix\daemond\IpcError
 *
 * TODO: Specify covers for each function instead. Maybe rewrite tests completely.
 */
final class DaemonTest extends TestCase
{
    protected function setUp()
    {
        $daemon = new TestDaemon(__DIR__ . '/../testFiles/TestDaemonCtl.php');
        self::uninstallDaemon($daemon);
    }


    public static function tearDownAfterClass()
    {
        $daemon = new TestDaemon(__DIR__ . '/../testFiles/TestDaemonCtl.php');
        self::uninstallDaemon($daemon);
    }


    public static function uninstallDaemon($daemon)
    {
        global $argv;
        $argv = array('dummy', 'uninstall');
        $daemon->handleCliInvocation();
    }


    /**
     * @covers Mikk3lRo\atomix\daemond\DaemonAbstract::__construct
     */
    public function testCanConstruct()
    {
        $daemon = new TestDaemon(__DIR__ . '/../testFiles/TestDaemonCtl.php');
        $this->assertInstanceOf(DaemonAbstract::class, $daemon);
    }


    /**
     * @covers Mikk3lRo\atomix\daemond\DaemonAbstract::__construct
     */
    public function testCanDefineVersioningFiles()
    {
        $theTestFile = '/tmp/testVersioning.tmp';
        touch($theTestFile);
        $expectedVersion = filemtime($theTestFile);

        $daemon = new TestDaemon(__DIR__ . '/../testFiles/TestDaemonCtl.php', array($theTestFile));

        $this->assertEquals($expectedVersion, $daemon->version);

        unlink($theTestFile);
    }


    /**
     * @covers Mikk3lRo\atomix\daemond\DaemonAbstract::printUsage
     */
    public function testCanPrintUsage()
    {
        global $argv;
        $daemon = new TestDaemon(__DIR__ . '/../testFiles/TestDaemonCtl.php');

        $this->expectOutputRegex('#EXPECTED USAGE#');
        $daemon->printUsage();
    }


    /**
     * @covers Mikk3lRo\atomix\daemond\DaemonAbstract::handleCliInvocation
     */
    public function testCanPrintUsageFromCli()
    {
        global $argv;
        $daemon = new TestDaemon(__DIR__ . '/../testFiles/TestDaemonCtl.php');

        $this->expectOutputRegex('#EXPECTED USAGE#');
        $argv = array('dummy');
        $daemon->handleCliInvocation();
    }


    /**
     * @covers Mikk3lRo\atomix\daemond\DaemonAbstract::writeSystemdConfig
     * @covers Mikk3lRo\atomix\daemond\DaemonAbstract::writeProfiledConfig
     */
    public function testCanInstallDaemon()
    {
        global $argv;
        $daemon = new TestDaemon(__DIR__ . '/../testFiles/TestDaemonCtl.php');

        $argv = array('dummy', 'install');
        $daemon->handleCliInvocation();

        $this->assertContains('active (running)', `systemctl status TestDaemon`);

        $this->assertContains('alias TestDaemon=\'/', file_get_contents('/etc/profile.d/daemon_TestDaemon.sh'));

        self::uninstallDaemon($daemon);

        $this->assertContains('could not be found', `systemctl status TestDaemon 2>&1`);
    }


    /**
     * @covers Mikk3lRo\atomix\daemond\DaemonAbstract::isRunning
     */
    public function testCanOnlyRunOnce()
    {
        global $argv;
        $daemon = new TestDaemon(__DIR__ . '/../testFiles/TestDaemonCtl.php');

        $argv = array('dummy', 'install');
        $daemon->handleCliInvocation();

        $this->assertContains('active (running)', `systemctl status TestDaemon`);

        $this->expectOutputRegex('#already running#');
        $this->expectExceptionMessage('exit(1)');
        $argv = array('dummy', 'startForeground');
        $daemon->handleCliInvocation();
    }


    /**
     * @covers Mikk3lRo\atomix\daemond\DaemonAbstract::getVersion
     * @covers Mikk3lRo\atomix\daemond\DaemonAbstract::hasNewVersion
     */
    public function testDoesCheckForVersions()
    {
        global $argv;

        $theTestFile = '/tmp/testVersioning.tmp';
        touch($theTestFile);

        $daemon = new TestDaemon(__DIR__ . '/../testFiles/TestDaemonCtl.php', array($theTestFile));

        $argv = array('dummy', 'install');
        $daemon->handleCliInvocation();

        $argv = array('dummy', 'stop');
        $daemon->handleCliInvocation();

        $cmd = 'sleep 1;touch ' .escapeshellarg($theTestFile);
        Processes::executeNonBlocking($cmd);

        $this->expectOutputString('BeforeLoop.Deconstruct.Restart.');
        try {
            $argv = array('dummy', 'startForeground');
            $daemon->handleCliInvocation();
        } catch (Exception $e) {
            $this->assertEquals('exit(0)', $e->getMessage());
        }
    }


    /**
     * @covers Mikk3lRo\atomix\daemond\DaemonAbstract::restart
     */
    public function testCanRestart()
    {
        global $argv;
        $daemon = new TestDaemon(__DIR__ . '/../testFiles/TestDaemonCtl.php');

        $argv = array('dummy', 'install');
        $daemon->handleCliInvocation();

        ob_start();
        $argv = array('dummy', 'status');
        $daemon->handleCliInvocation();
        $output = ob_get_clean();

        $this->assertRegExp('#(running)#', $output);
        if (preg_match('#Main PID:\s+([0-9]+)\s+#', $output, $matches)) {
            $oldPid = intval($matches[1]);
        }

        $argv = array('dummy', 'restart');
        $daemon->handleCliInvocation();

        ob_start();
        $argv = array('dummy', 'status');
        $daemon->handleCliInvocation();
        $output = ob_get_clean();

        $this->assertRegExp('#(running)#', $output);
        if (preg_match('#Main PID:\s+([0-9]+)\s+#', $output, $matches)) {
            $newPid = intval($matches[1]);
        }

        $this->assertNotEquals($newPid, $oldPid);
    }


    /**
     * @covers Mikk3lRo\atomix\daemond\DaemonAbstract::stop
     * @covers Mikk3lRo\atomix\daemond\DaemonAbstract::start
     */
    public function testCanStopAndStart()
    {
        global $argv;
        $daemon = new TestDaemon(__DIR__ . '/../testFiles/TestDaemonCtl.php');

        $argv = array('dummy', 'install');
        $daemon->handleCliInvocation();

        ob_start();
        $argv = array('dummy', 'status');
        $daemon->handleCliInvocation();
        $output = ob_get_clean();
        $this->assertRegExp('#(running)#', $output);

        $argv = array('dummy', 'stop');
        $daemon->handleCliInvocation();

        ob_start();
        $argv = array('dummy', 'status');
        $daemon->handleCliInvocation();
        $output = ob_get_clean();
        $this->assertRegExp('#(dead)#', $output);

        $argv = array('dummy', 'start');
        $daemon->handleCliInvocation();

        ob_start();
        $argv = array('dummy', 'status');
        $daemon->handleCliInvocation();
        $output = ob_get_clean();
        $this->assertRegExp('#(running)#', $output);
    }


    /**
     * @covers Mikk3lRo\atomix\daemond\DaemonAbstract::reload
     */
    public function testCanReload()
    {
        global $argv;
        $daemon = new TestDaemon(__DIR__ . '/../testFiles/TestDaemonCtl.php');

        $argv = array('dummy', 'install');
        $daemon->handleCliInvocation();

        ob_start();
        $argv = array('dummy', 'status');
        $daemon->handleCliInvocation();
        $output = ob_get_clean();

        $this->assertRegExp('#(running)#', $output);
        if (preg_match('#Main PID:\s+([0-9]+)\s+#', $output, $matches)) {
            $oldPid = intval($matches[1]);
        }

        $argv = array('dummy', 'reload');
        $daemon->handleCliInvocation();

        ob_start();
        $argv = array('dummy', 'status');
        $daemon->handleCliInvocation();
        $output = ob_get_clean();

        $this->assertRegExp('#(running)#', $output);
        if (preg_match('#Main PID:\s+([0-9]+)\s+#', $output, $matches)) {
            $newPid = intval($matches[1]);
        }

        $this->assertEquals($newPid, $oldPid);
    }


    /**
     * @covers Mikk3lRo\atomix\daemond\IpcError
     */
    public function testFailingIpc()
    {
        global $argv;
        $daemon = new TestDaemon(__DIR__ . '/../testFiles/TestDaemonCtl.php');

        $argv = array('dummy', 'install');
        $daemon->handleCliInvocation();

        ob_start();
        $argv = array('dummy', 'failingIPC');
        $daemon->handleCliInvocation();
        $output = ob_get_clean();

        $this->assertRegExp('#FailingIPC-output#', $output);
    }


    /**
     * @covers Mikk3lRo\atomix\daemond\IpcSuccess
     */
    public function testGetMemoryUsageFromOutside()
    {
        global $argv;
        $daemon = new TestDaemon(__DIR__ . '/../testFiles/TestDaemonCtl.php');

        $argv = array('dummy', 'install');
        $daemon->handleCliInvocation();

        ob_start();
        $argv = array('dummy', 'ram');
        $daemon->handleCliInvocation();
        $output = ob_get_clean();

        $this->assertRegExp('#Current:#', $output);
        $this->assertRegExp('#Peak:#', $output);
    }


    public function testGetMemoryUsageFromInside()
    {
        global $argv;
        $daemon = new TestDaemon(__DIR__ . '/../testFiles/TestDaemonCtl.php');

        $cmd = 'sleep .2;php ' .escapeshellarg(__DIR__ . '/../testFiles/TestDaemonCtl.php') . ' ram;sleep .2;kill -SIGHUP ' . getmypid();
        Processes::executeNonBlocking($cmd, '/tmp/output');

        $this->expectOutputString('BeforeLoop.Deconstruct.');
        try {
            $argv = array('dummy', 'startForeground');
            $daemon->handleCliInvocation();
        } catch (Exception $e) {
            $this->assertEquals('exit(0)', $e->getMessage());
        }

        $this->assertRegExp('#Current:#', file_get_contents('/tmp/output'));
        $this->assertRegExp('#Peak:#', file_get_contents('/tmp/output'));
    }


    public function testCanHandleSignals1()
    {
        global $argv;
        $daemon = new TestDaemon(__DIR__ . '/../testFiles/TestDaemonCtl.php');

        $cmd = 'sleep .2;kill -SIGHUP ' . getmypid();
        Processes::executeNonBlocking($cmd);

        $this->expectOutputString('BeforeLoop.Deconstruct.');
        try {
            $argv = array('dummy', 'startForeground');
            $daemon->handleCliInvocation();
        } catch (Exception $e) {
            $this->assertEquals('exit(0)', $e->getMessage());
        }
    }


    public function testCanHandleSignals2()
    {
        global $argv;
        $daemon = new TestDaemon(__DIR__ . '/../testFiles/TestDaemonCtl.php');

        $cmd = 'sleep .2;kill -SIGHUP ' . getmypid();
        Processes::executeNonBlocking($cmd);

        $this->expectOutputString('BeforeLoop.Deconstruct.');
        try {
            $argv = array('dummy', 'startForeground');
            $daemon->handleCliInvocation();
        } catch (Exception $e) {
            $this->assertEquals('exit(0)', $e->getMessage());
        }
    }


    public function testCanHandleSignals3()
    {
        global $argv;
        $daemon = new TestDaemon(__DIR__ . '/../testFiles/TestDaemonCtl.php');

        $cmd = 'sleep .2;kill -SIGUSR2 ' . getmypid() . ';sleep .2;kill -SIGTERM ' . getmypid();
        Processes::executeNonBlocking($cmd);

        $this->expectOutputString('BeforeLoop.Reload.Deconstruct.');
        try {
            $argv = array('dummy', 'startForeground');
            $daemon->handleCliInvocation();
        } catch (Exception $e) {
            $this->assertEquals('exit(0)', $e->getMessage());
        }
    }


    public function testCanSendAndReceiveIpc()
    {
        global $argv;
        $daemon = new TestDaemon(__DIR__ . '/../testFiles/TestDaemonCtl.php');

        $cmd = 'sleep .2;' . $daemon->command(array('specialCLI')) . ';kill -SIGTERM ' . getmypid();
        Processes::executeNonBlocking($cmd, '/tmp/output');

        $this->expectOutputString('BeforeLoop.SpecialIPC.Deconstruct.');
        try {
            $argv = array('dummy', 'startForeground');
            $daemon->handleCliInvocation();
        } catch (Exception $e) {
            $this->assertEquals('exit(0)', $e->getMessage());
        }

        $this->assertEquals("'SpecialIPC-output'\n", file_get_contents('/tmp/output'));
    }


    public function testIpcWithArgs()
    {
        global $argv;
        $daemon = new TestDaemon(__DIR__ . '/../testFiles/TestDaemonCtl.php');

        $cmd = 'sleep .2;' . $daemon->command(array('withargsCLI', 'theVal')) . ';kill -SIGTERM ' . getmypid();
        Processes::executeNonBlocking($cmd, '/tmp/output', '/tmp/errout_ww');

        $this->expectOutputString('BeforeLoop.WithArgsIPC:theVal.Deconstruct.');
        try {
            $argv = array('dummy', 'startForeground');
            $daemon->handleCliInvocation();
        } catch (Exception $e) {
            $this->assertEquals('exit(0)', $e->getMessage());
        }

        $this->assertEquals("'WithArgsIPC-theVal'\n", file_get_contents('/tmp/output'));
    }


    public function testIpcWithInvalidArgs()
    {
        global $argv;
        $daemon = new TestDaemon(__DIR__ . '/../testFiles/TestDaemonCtl.php');

        $cmd = 'sleep .2;' . $daemon->command(array('withinvalidargCLI')) . ';kill -SIGTERM ' . getmypid();
        Processes::executeNonBlocking($cmd, '/tmp/output', '/tmp/errout');

        $this->expectOutputString('BeforeLoop.Deconstruct.');
        try {
            $argv = array('dummy', 'startForeground');
            $daemon->handleCliInvocation();
        } catch (Exception $e) {
            $this->assertEquals('exit(0)', $e->getMessage());
        }

        $this->assertRegExp('#Argument 1 passed to#', file_get_contents('/tmp/output'));
        $this->assertEquals('', file_get_contents('/tmp/errout'));
    }


    public function testUnknownIpc()
    {
        global $argv;
        $daemon = new TestDaemon(__DIR__ . '/../testFiles/TestDaemonCtl.php');

        $cmd = 'sleep .2;' . $daemon->command(array('unknownCLI')) . ';kill -SIGTERM ' . getmypid();
        Processes::executeNonBlocking($cmd, '/tmp/output', '/tmp/errout');

        $this->expectOutputString('BeforeLoop.Deconstruct.');
        try {
            $argv = array('dummy', 'startForeground');
            $daemon->handleCliInvocation();
        } catch (Exception $e) {
            $this->assertEquals('exit(0)', $e->getMessage());
        }

        $this->assertRegExp('#Unknown command: whatever#', file_get_contents('/tmp/output'));
    }


    public function testNoLogger()
    {
        $daemon = new TestDaemon(__DIR__ . '/../testFiles/TestDaemonCtl.php', null, true);
        $this->assertInstanceOf(DaemonAbstract::class, $daemon);
    }


    public function testNoIpc()
    {
        global $argv;

        $daemon = new TestDaemon(__DIR__ . '/../testFiles/TestDaemonCtl.php', null, false, true);

        $cmd = 'sleep .2;' . $daemon->command(array('specialCLI', 'noIpc')) . ';kill -SIGTERM ' . getmypid();
        Processes::executeNonBlocking($cmd, '/tmp/output', '/tmp/errout');

        $this->expectOutputString('BeforeLoop.Deconstruct.');
        try {
            $argv = array('dummy', 'startForeground');
            $daemon->handleCliInvocation();
        } catch (Exception $e) {
            $this->assertEquals('exit(0)', $e->getMessage());
        }

        $this->assertEquals("Error: 'IPC not enabled!'\n", file_get_contents('/tmp/output'));
        $this->assertEquals("", file_get_contents('/tmp/errout'));
    }
}

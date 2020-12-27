<?php declare(strict_types = 1);

namespace Mikk3lRo\atomix\Tests;

use PHPUnit\Framework\TestCase;

use Mikk3lRo\atomix\Tests\TestDaemon;
use Mikk3lRo\atomix\daemond\Systemctl;

require_once __DIR__ . '/../testFiles/TestDaemon.php';

putenv('isUnitTest=1');

final class SystemctlTest extends TestCase
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


    public static function installDaemon()
    {
        global $argv;
        $daemon = new TestDaemon(__DIR__ . '/../testFiles/TestDaemonCtl.php');

        $argv = array('dummy', 'install');
        $daemon->handleCliInvocation();

        return $daemon;
    }


    public static function uninstallDaemon($daemon)
    {
        global $argv;
        $argv = array('dummy', 'uninstall');
        $daemon->handleCliInvocation();
    }


    /**
     * @covers Mikk3lRo\atomix\daemond\Systemctl::isInstalled
     * @covers Mikk3lRo\atomix\daemond\Systemctl::isEnabled
     * @covers Mikk3lRo\atomix\daemond\Systemctl::isActive
     * @covers Mikk3lRo\atomix\daemond\Systemctl::status
     * @covers Mikk3lRo\atomix\daemond\Systemctl::reloadServices
     */
    public function testCanGetStatus()
    {
        $this->assertFalse(Systemctl::isInstalled('TestDaemon'));
        $this->assertFalse(Systemctl::isEnabled('TestDaemon'));
        $this->assertFalse(Systemctl::isActive('TestDaemon'));
        $this->assertContains('could not be found', Systemctl::status('TestDaemon'));

        self::installDaemon();

        $this->assertTrue(Systemctl::isInstalled('TestDaemon'));
        $this->assertTrue(Systemctl::isEnabled('TestDaemon'));
        $this->assertTrue(Systemctl::isActive('TestDaemon'));

        $this->assertRegExp('#TestDaemon.*Main PID#s', Systemctl::status('TestDaemon'));
    }


    /**
     * @covers Mikk3lRo\atomix\daemond\Systemctl::stop
     * @covers Mikk3lRo\atomix\daemond\Systemctl::start
     */
    public function testCanStopAndStartDaemon()
    {
        self::installDaemon();

        $this->assertTrue(Systemctl::isActive('TestDaemon'));

        Systemctl::stop('TestDaemon');

        $this->assertFalse(Systemctl::isActive('TestDaemon'));

        Systemctl::start('TestDaemon');

        $this->assertTrue(Systemctl::isActive('TestDaemon'));
    }


    /**
     * @covers Mikk3lRo\atomix\daemond\Systemctl::enable
     * @covers Mikk3lRo\atomix\daemond\Systemctl::disable
     */
    public function testCanEnableAndDisableDaemon()
    {
        self::installDaemon();

        $this->assertTrue(Systemctl::isEnabled('TestDaemon'));

        Systemctl::disable('TestDaemon');

        $this->assertFalse(Systemctl::isEnabled('TestDaemon'));

        Systemctl::enable('TestDaemon');

        $this->assertTrue(Systemctl::isEnabled('TestDaemon'));
    }


    /**
     * @covers Mikk3lRo\atomix\daemond\Systemctl::restart
     */
    public function testCanRestartDaemon()
    {
        self::installDaemon();

        if (preg_match('#TestDaemon.*Main PID: ([0-9]+)#s', Systemctl::status('TestDaemon'), $matches)) {
            $oldPid = $matches[1];
        }
        $this->assertTrue(is_numeric($oldPid) && intval($oldPid) > 0);

        Systemctl::restart('TestDaemon');

        if (preg_match('#TestDaemon.*Main PID: ([0-9]+)#s', Systemctl::status('TestDaemon'), $matches)) {
            $newPid = $matches[1];
        }
        $this->assertTrue(is_numeric($newPid) && intval($newPid) > 0);

        $this->assertNotEquals($oldPid, $newPid);
    }
}

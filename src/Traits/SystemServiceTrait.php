<?php declare(strict_types = 1);

namespace Mikk3lRo\atomix\daemond\Traits;

use Exception;
use Mikk3lRo\atomix\daemond\Systemctl;
use Mikk3lRo\atomix\utilities\Processes;

trait SystemServiceTrait
{
    /**
     * Used for system.d daemon, logs etc. to identify the daemon.
     *
     * @var string
     */
    public $daemonName = null;

    /**
     * User to run daemon as.
     *
     * @var string
     */
    public $runUser = 'root';

    /**
     * Group to run daemon as.
     *
     * @var string
     */
    public $runGroup = 'root';

    /**
     * Tell systemd to wait this long for daemon to start.
     *
     * @var integer
     */
    public $sdTimeoutStartSec = 10;

    /**
     * Tell systemd to wait this long for daemon to end.
     *
     * @var integer
     */
    public $sdTimeoutStopSec = 10;

    /**
     * Tell systemd to wait this long before restarting if it dies unexpectedly.
     *
     * @var integer
     */
    public $sdRestartSec = 10;

    /**
     * Set to false to disable watchdog (automated restart if daemon dies)
     *
     * @var integer
     */
    public $sdWatchdogAutoRestart = true;

    /**
     * The maximum time running the loop can reasonably be expected to take
     * without being considered frozen. Watchdog (systemd) will be told to wait
     * for this amount of time *plus* the min_loop_interval. If the loop actually
     * takes longer than that it will not ping watchdog, and so it will consider
     * the daemon dead, and forcibly restart it.
     * A minute seems a reasonable default - if the loop takes longer than this
     * the structure should probably be adjusted.
     *
     * A low number will detect failure and restart fast, but at the risk of
     * forcing a restart if a loop takes a bit longer for some reason.
     *
     * A high number will accommodate major fluctuations in loop duration, but
     * also means a dead daemon will take longer to restart.
     *
     * @var integer
     */
    public $maxLoopDuration = 60;

    /**
     * Default (minimum) interval in seconds between loop iterations.
     *
     * @var float
     */
    public $minLoopDuration = 10.0;


    /**
     * Helper function to get a full path to the pid file
     *
     * @return string Full path to pid file
     */
    private function pidFile() : string
    {
        return '/var/run/' . $this->daemonName . '.pid';
    }


    /**
     * Additional check to ensure that the process is actually running... If the
     * daemon is somehow killed incorrectly the pid file may exist even if the
     * daemon is not running
     *
     * @return boolean true if the daemon is actually running, false if not
     */
    private function isRunning() : bool
    {
        //If the pid file doesn't exist we are definitely not running
        if (!is_file($this->pidFile())) {
            return false;
        }

        //Read the pid file
        $lastPid = intval(file_get_contents($this->pidFile()));

        return Processes::isRunning($lastPid);
    }


    /**
     * Writes a systemd config file allowing us to actually run at boot etc.
     *
     * @return void
     */
    private function writeSystemdConfig() : void
    {
        $retval = array();
        $retval[] = '#http://www.freedesktop.org/software/systemd/man/systemd.service.html';
        $retval[] = '';
        $retval[] = '[Unit]';
        $retval[] = 'Description=PHP Daemon ' . $this->daemonName;
        $retval[] = '';
        $retval[] = '[Service]';
        $retval[] = 'PIDFile=' . $this->pidFile();
        $retval[] = 'User=' . $this->runUser;
        $retval[] = 'Group=' . $this->runGroup;
        $retval[] = 'ExecStart=' . $this->command(array('startDaemon'));
        $retval[] = 'TimeoutStartSec=' . $this->sdTimeoutStartSec;
        $retval[] = 'TimeoutStopSec=' . $this->sdTimeoutStopSec;
        $retval[] = 'ExecReload=/bin/kill -USR2 $MAINPID';
        $retval[] = 'ExecStop=/bin/kill -HUP $MAINPID';
        $retval[] = 'RestartSec=' . $this->sdRestartSec;
        $retval[] = '';
        if ($this->sdWatchdogAutoRestart) {
            $retval[] = '#automatic restart if dead';
            $retval[] = 'Restart=on-failure';
            $retval[] = 'Type=notify';
            $retval[] = 'NotifyAccess=all';
            $retval[] = 'WatchdogSec=' . intval($this->maxLoopDuration + $this->minLoopDuration);
        }
        $retval[] = '';
        $retval[] = '[Install]';
        $retval[] = 'WantedBy=multi-user.target';
        file_put_contents('/etc/systemd/system/' . $this->daemonName . '.service', implode("\n", $retval));
        Systemctl::reloadServices();
    }


    /**
     * Override function in extending classes to perform some additional actions
     * when installing the daemon.
     *
     * @return void
     */
    protected function onInstall() : void
    {
    }


    /**
     * Override function in extending classes to perform some additional actions
     * when uninstalling the daemon.
     *
     * @return void
     */
    protected function onUninstall() : void
    {
    }


    /**
     * Remove the systemd config, aka. uninstall service.
     *
     * @return void
     */
    private function removeSystemdConfig() : void
    {
        @unlink('/etc/systemd/system/' . $this->daemonName . '.service');
        Systemctl::reloadServices();
    }


    /**
     * Writes a neat shortcut to the control script in the profile.d folder.
     *
     * @return void
     */
    private function writeProfiledConfig() : void
    {
        $retval = array();
        $retval[] = 'alias ' . $this->daemonName . '=' . escapeshellarg($this->command(array()));
        $retval[] = '';

        file_put_contents('/etc/profile.d/daemon_' . $this->daemonName . '.sh', implode("\n", $retval));
    }


    /**
     * Remove the profile.d shortcut.
     *
     * @return void
     */
    private function removeProfiledConfig() : void
    {
        @unlink('/etc/profile.d/daemon_' . $this->daemonName . '.sh');
    }


    /**
     * Notify systemd of what we're doing, and change the title in ps too.
     *
     * @param string $status The status message.
     *
     * @return void
     */
    protected function setStatus(string $status) : void
    {
        cli_set_process_title($this->daemonName . ': ' . $status);
        self::notifySystemd('STATUS=' . $status);
    }


    /**
     * Send a status / notification to systemd.
     *
     * @param string $what The string to send.
     *
     * @return void
     *
     * @throws Exception If the socket cannot be reached.
     */
    public function notifySystemd(string $what = 'READY=1') : void
    {
        $notifySocket = getenv('NOTIFY_SOCKET');
        if ($notifySocket) {
            //For some reason the following was needed at one point, but after some
            //updates it suddenly prevented us from connecting and had to be removed
            //$mod_socket = "\x00" . substr($notify_socket, 1);

            $sock = socket_create(AF_UNIX, SOCK_RAW, 0);
            if (!socket_connect($sock, $notifySocket)) {
                throw new Exception('Unable to connect to ' . $notifySocket);
            }

            socket_write($sock, $what);
            socket_close($sock);
        }
    }
}

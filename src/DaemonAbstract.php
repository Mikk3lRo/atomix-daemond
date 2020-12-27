<?php declare(strict_types = 1);

namespace Mikk3lRo\atomix\daemond;

use Exception;
use Mikk3lRo\atomix\daemond\Traits\CliInvocationTrait;
use Mikk3lRo\atomix\daemond\Traits\IpcInvocationTrait;
use Mikk3lRo\atomix\daemond\Traits\PeriodicActionsTrait;
use Mikk3lRo\atomix\daemond\Traits\SystemServiceTrait;
use Mikk3lRo\atomix\utilities\CLI;
use Mikk3lRo\atomix\utilities\Processes;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\NullLogger;

abstract class DaemonAbstract implements LoggerAwareInterface
{
    use LoggerAwareTrait;
    use SystemServiceTrait;
    use PeriodicActionsTrait;
    use CliInvocationTrait;
    use IpcInvocationTrait;

    /**
     * Absolute path to the PHP-file that controls the daemon - normally the file
     * containing the child-class.
     *
     * @var string
     */
    public $daemonScript = null;

    /**
     * Array containing files used for version checks.
     *
     * @var array
     */
    public $versioningFiles = array();

    /**
     * Keeps track of running version (timestamp of the newest file in $versioningFiles)
     *
     * @var integer
     */
    public $version = null;

    /**
     * Keeps track of the previous loop's start time to ensure a reasonable
     * resource usage by delaying the next loop.
     *
     * @var integer
     */
    private $lastLoopStarted = 0;

    /**
     * Keeps track of total number of loops.
     *
     * @var integer
     */
    private $numberOfLoops = 0;

    /**
     * Minimum interval in seconds between version checks - during development
     * a low value makes sense.
     *
     * Can be easily overridden by the child class (and should be!)
     *
     * @var integer
     */
    public $versionCheckInterval = 5;

    /**
     * Are we running in the foreground?
     *
     * @var boolean
     */
    private $isForeground = false;


    /**
     * Initialization before the loop is in this function
     *
     * @return void
     */
    abstract protected function beforeLoop() : void;


    /**
     * Configuration reloading goes in this (ie. SIGUSR2)
     *
     * @return void
     */
    abstract protected function reload() : void;


    /**
     * The actual purpose of the daemon will be in this function
     *
     * @return void
     */
    abstract protected function eternalLoop() : void;


    /**
     * Deconstruct is in this one - graceful shutdown.
     *
     * @return void
     */
    abstract protected function deconstruct() : void;


    /**
     * Construct function MUST be called from child class construct function:
     *
     * parent::__construct($daemonName, $daemonScript, $versioningFiles);
     *
     * @param string     $daemonName      Name of the daemon. Used for logs, systemd etc.
     * @param string     $daemonScript    Path to the control script.
     * @param array|null $versioningFiles An array of files to check periodically for new versions. Defaults to all included files up to the point this is called.
     */
    public function __construct(string $daemonName, string $daemonScript, ?array $versioningFiles = null)
    {
        if (!$this->logger) {
            $this->setLogger(new NullLogger());
        }

        $this->daemonName = $daemonName;
        $this->daemonScript = $daemonScript;

        if (is_array($versioningFiles)) {
            $this->versioningFiles = $versioningFiles;
        } else {
            $this->versioningFiles = get_included_files();
        }

        //Make sure that changes to the parent class (ie. this file) fires an
        //automagic restart
        if (!in_array(__FILE__, $this->versioningFiles)) {
            $this->versioningFiles[] = __FILE__;
        }

        //Figure out which version is running
        $this->version = $this->getVersion();

        $this->addDefaultCliCommands();

        //Handle signals
        pcntl_signal(SIGTERM, array($this, "sigHandler"));
        pcntl_signal(SIGHUP, array($this, "sigHandler"));
        pcntl_signal(SIGUSR1, array($this, "sigHandler"));
        pcntl_signal(SIGUSR2, array($this, "sigHandler"));


        //Check for new versions periodically
        $this->addPeriodicAction(function () {
            $this->setStatus('Checking for new version of daemon...');
            if ($this->hasNewVersion()) {
                $this->restart();
            }
        }, $this->versionCheckInterval);
    }


    /**
     * Handles signals.
     *
     * @param integer $signal The signal code.
     *
     * @return void
     */
    public function sigHandler(int $signal) : void
    {
        $this->logger->debug("Received signal: $signal");
        switch ($signal) {
            case SIGUSR2:
                //Handle reload
                $this->logger->debug("Reloading configuration...");
                $this->reload();
                break;
            case SIGHUP:
            case SIGTERM:
                //Handle shutdown
                $this->logger->debug("Stopping daemon...");
                $this->setStatus('Stopping daemon...');
                $this->stop();
        }
    }


    /**
     * Determines the current version by checking the filemtime of each required
     * file.
     *
     * @return integer Unix timestamp of the newest file
     */
    protected function getVersion() : int
    {
        //Make sure we get up-to-date results
        clearstatcache();

        //Track each file
        $filetimes = array();

        foreach ($this->versioningFiles as $file) {
            if (is_file($file)) {
                $filetimes[$file] = filemtime($file);
            } else {
                //File has disappeared, assume we have a (major) version update.
                $filetimes[$file] = time();
            }
        }

        return max($filetimes);
    }


    /**
     * Cleans up pid-file and whatever mess the child has made.
     *
     * @return void
     */
    private function cleanup() : void
    {
        //Run the childs deconstruct / cleanup function
        $this->deconstruct();

        //Remove the pid file
        unlink($this->pidFile());
    }


    /**
     * Construct a command for the daemon.
     *
     * @param array $args Argument(s) to pass to the control-script.
     *
     * @return string The command
     */
    public function command(array $args) : string
    {
        return CLI::getPhpCommand($this->daemonScript, $args);
    }


    /**
     * Function to automatically stop and re-launch daemon.
     *
     * @return void
     */
    protected function restart() : void
    {
        $this->cleanup();

        if ($this->isForeground) {
            $cmd = $this->command(array('startForeground'));
        } else {
            $cmd = $this->command(array('startDaemon'));
        }
        $this->logger->notice('Restarting daemon: ' . $cmd);

        if (!getenv('isUnitTest')) {
            // @codeCoverageIgnoreStart
            $this->setStatus('Restarting...');
            if ($this->isForeground) {
                passthru($cmd);
            } else {
                Processes::executeNonBlocking($cmd);
                sleep(1);
            }
            // @codeCoverageIgnoreEnd
        } else {
            echo 'Restart.';
        }

        //Exit this instance
        $this->exit(0);
    }


    /**
     * Function to stop the daemon cleanly
     *
     * @return void
     */
    protected function stop() : void
    {
        //Cleanup
        $this->cleanup();
        $this->exit(0);
    }


    /**
     * Forking stuff - this is where the actual daemonizing takes place.
     *
     * @return void
     *
     * TODO: Can this be tested?
     */
    protected function start() : void
    {
        global $STDIN, $STDOUT, $STDERR;

        if ($this->isRunning()) {
            $this->logger->warning('Tried to start daemon {name} when it was already running...', array('name' => $this->daemonName));
            $this->exit(1);
        }

        //Fork off child - returns a pid if we are the parent, and zero if we
        //are the child...and -1 on failure
        $pid = pcntl_fork();

        //If we are the parent
        if ($pid > 0) {
            if (!is_dir(dirname($this->pidFile()))) {
                mkdir(dirname($this->pidFile()), 0755, true);
            }

            //Parent writes the pid to the pid file
            file_put_contents($this->pidFile(), $pid);

            //And exits cleanly
            $this->exit(0);
        } else if ($pid < 0) {
            //Fork failed, exit indicating failure
            $this->exit(1);
        }
        //Else: We are the child

        //Set root and close all standard pipes
        chdir("/");
        fclose(STDIN);
        fclose(STDOUT);
        fclose(STDERR);

        //Redirect standard pipes to /dev/null
        $STDIN = fopen('/dev/null', 'r');
        $STDOUT = fopen('/dev/null', 'wb');
        $STDERR = fopen('/dev/null', 'wb');

        //Become session leader
        posix_setsid();

        $this->logger->notice("Daemon {name} started with pid {pid}...", array('name' => $this->daemonName, 'pid' => getmypid()));

        //Let systemd know that we have successfully launched
        $this->notifySystemd('READY=1');

        //Set an initial status so we can easily see if the daemon fails before
        //reaching the main loop
        $this->setStatus('Initializing');

        //Do whatever the child needs done before starting the main loop
        $this->beforeLoop();

        $this->cleanIpc();

        //Start looping
        $this->doLoop();
    }


    /**
     * A slimmed down version to start the daemon in the foreground.
     *
     * @return void
     */
    protected function startInForeground() : void
    {
        $this->isForeground = true;

        if ($this->isRunning()) {
            $this->logger->warning('Tried to start daemon {name} when it was already running...', array('name' => $this->daemonName));
            $this->exit(1);
        }

        if (!is_dir(dirname($this->pidFile()))) {
            mkdir(dirname($this->pidFile()), 0755, true);
        }

        file_put_contents($this->pidFile(), getmypid());

        chdir("/");
        $this->logger->notice("Daemon {name} started in foreground with pid {pid}...", array('name' => $this->daemonName, 'pid' => getmypid()));

        //Set an initial status so we can easily see if the daemon fails before
        //reaching the main loop
        $this->setStatus('Initializing');

        //Do whatever the child needs done before starting the main loop
        $this->beforeLoop();

        //Start looping
        $this->doLoop();
    }


    /**
     * Starts the eternal loop.
     *
     * @return void
     */
    private function doLoop() : void
    {
        while (true) {
            $this->oneLoop();
        }
    }


    /**
     * Execute a single iteration of the loop.
     *
     * @return void
     */
    private function oneLoop() : void
    {
        //When should the next loop commence?
        $nextLoop = $this->lastLoopStarted + $this->minLoopDuration;

        //Wait for it...
        do {
            //Make sure the systemd watchdog doesn't kill us while waiting
            //for the action to start if we have a long loop interval
            $this->notifySystemd('WATCHDOG=1');

            $this->setStatus('Waiting for loop interval to pass for loop ' . $this->numberOfLoops . '...');

            //Find out how long we need to sleep
            $sleepFor = $nextLoop - microtime(true);

            //But never sleep more than 100 ms - we don't want signals to be
            //significantly delayed
            if ($sleepFor > 0.1) {
                $sleepFor = 0.1;
            }

            //Make sure we don't pass a negative number (if the previous
            //loop took longer than the min_loop_interval)
            if ($sleepFor > 0) {
                usleep(intval($sleepFor * 1000000));
            }

            $this->setStatus('Reading signals for loop ' . $this->numberOfLoops . '...');

            //Catch signals that came during the nap
            pcntl_signal_dispatch();

            //Catch IPC
            $this->handleIpc();
        } while (microtime(true) < $nextLoop);

        //Keep track of last start-time
        $this->lastLoopStarted = microtime(true);

        //Do the actual action...
        $this->setStatus('Executing daemon loop ' . $this->numberOfLoops . '...');
        $this->eternalLoop();

        //Do stuff that needs to run with a certain interval
        $this->doPeriodicActions();

        $this->numberOfLoops++;
    }


    /**
     * Compares the newest file on disk to the newest file when daemon was
     * launched.
     *
     * @return boolean true if changes are detected, false if not
     */
    private function hasNewVersion() : bool
    {
        //Get the current version from filemtime of disk files
        $newVersion = $this->getVersion();

        //If launched version was not the same return true
        if ($this->version !== $newVersion) {
            $this->logger->notice('New version: ' . date('Y-m-d H:i:s', $newVersion) . ' (running version: ' . date('Y-m-d H:i:s', $this->version) . ')');
            return true;
        }
        //Otherwise return false
        return false;
    }


    /**
     * Simple wrapper around exit, so we can do unit tests without killing the test runner.
     *
     * @param integer $status The exit status = 0 for normal, > 0 for error.
     *
     * @return void
     *
     * @throws Exception When running in the foreground during unit testing.
     */
    public function exit(int $status = 0)
    {
        if (getenv('isUnitTest')) {
            throw new Exception('exit(' . $status . ')');
        } else {
            exit($status); // @codeCoverageIgnore
        }
    }
}

<?php declare(strict_types = 1);

namespace Mikk3lRo\atomix\daemond\Traits;

use Mikk3lRo\atomix\daemond\IpcCommand;
use Mikk3lRo\atomix\daemond\Systemctl;
use Mikk3lRo\atomix\utilities\Formatters;
use Mikk3lRo\atomix\utilities\Reflections;

trait CliInvocationTrait
{
    /**
     * Keep track of possible CLI commands
     *
     * @var array
     */
    private $handlersCLI = array();


    /**
     * Adds the most common CLI-commands common to all our daemons - most are
     * shallow wrappers around equivalent systemctl calls
     *
     * @return void
     */
    private function addDefaultCliCommands() : void
    {
        $this->addCliCommand('status', 'Display the status via systemctl', function () {
            echo Systemctl::status($this->daemonName) . "\n";
        });

        $this->addCliCommand('install', 'Install, enable and start service', function () {
            $this->writeSystemdConfig();
            $this->writeProfiledConfig();
            $this->onInstall();
            Systemctl::enable($this->daemonName);
            Systemctl::start($this->daemonName);
        });

        $this->addCliCommand('uninstall', 'Stop, disable and uninstall service', function () {
            Systemctl::stop($this->daemonName);
            Systemctl::disable($this->daemonName);
            $this->onUninstall();
            $this->removeSystemdConfig();
            $this->removeProfiledConfig();
        });

        $this->addCliCommand('start', 'Start service via systemctl', function () {
            Systemctl::start($this->daemonName);
        });

        $this->addCliCommand('stop', 'Stop service via systemctl', function () {
            Systemctl::stop($this->daemonName);
        });

        $this->addCliCommand('restart', 'Restart service via systemctl', function () {
            Systemctl::restart($this->daemonName);
        });

        $this->addCliCommand('reload', 'Reload service via systemctl', function () {
            Systemctl::reload($this->daemonName);
        });

        $this->addCliCommand('startDaemon', null, array($this, 'start'));

        $this->addCliCommand('startForeground', 'Start "daemon" in foreground - for tests and debugging only!', array($this, 'startInForeground'));

        $this->addCliCommand('ram', 'Get current and peak memory usage', function () {
            $resp = $this->sendIPC(new IpcCommand('get_memory_usage'));
            if ($resp->isSuccess()) {
                $mem = $resp->payload;
                echo 'Current: ' . Formatters::niceBytes($mem['current']) . "\n" . 'Peak:    ' . Formatters::niceBytes($mem['peak']) . "\n";
            } else {
                echo 'Error: ' . var_export($resp->payload, true) . "\n";
            }
        });
    }


    /**
     * Handles calls through the control-script
     *
     * @return void
     */
    public function handleCliInvocation() : void
    {
        global $argv;
        if (!isset($argv[1]) || !isset($this->handlersCLI[$argv[1]])) {
            $this->printUsage();
        } else {
            $commandArgs = array_slice($argv, 2);
            call_user_func_array($this->handlersCLI[$argv[1]]['callable'], $commandArgs);
        }
    }


    /**
     * Adds a command to the CLI interface (typically to invoke an IPC-call)
     *
     * @param string   $command     This is the first argument, ie. argv[1].
     * @param string   $description A short description which is used to print usage help - passing NULL will result in the command being "hidden".
     * @param callable $callable    A callable function that is executed when the command is invoked.
     * @param array    $args        An optional array of arguments that will be passed to the function [argName] => 'Arg description' - NOTE that actual passed arguments are whatever is provided on the command line.
     *
     * @return void
     */
    protected function addCliCommand(string $command, ?string $description, callable $callable, array $args = array()) : void
    {
        Reflections::checkArgumentsExistExact($callable, $args);

        $this->handlersCLI[$command] = array(
            'args' => $args,
            'description' => $description,
            'callable' => $callable
        );
    }


    /**
     * Prints some helpful info when someone calls the control script with invalid parameters.
     *
     * @return void
     */
    public function printUsage() : void
    {
        echo "\n" . str_pad('   EXPECTED USAGE   ', 80, '-', STR_PAD_BOTH) . "\n\n";
        echo str_repeat('-', 80) . "\n";
        foreach ($this->handlersCLI as $commandHandler => $details) {
            if ($details['description'] === null) {
                //Hidden command like 'startDaemon'
                continue;
            }
            echo $this->daemonName . ' ' . $commandHandler . ' ' . implode(' ', array_keys($details['args']));
            echo "\n\n";
            echo "    " . str_replace("\n", "\n    ", wordwrap($details['description'], 76)) . "\n";
            if (!empty($details['args'])) {
                echo "\n";
                foreach ($details['args'] as $argName => $argDesc) {
                    echo str_pad($argName, 18, ' ', STR_PAD_LEFT) . ': ' . str_replace("\n", "\n" . str_repeat(' ', 20), wordwrap($argDesc, 60)) . "\n";
                }
            }
            echo str_repeat('-', 80) . "\n";
        }
    }
}

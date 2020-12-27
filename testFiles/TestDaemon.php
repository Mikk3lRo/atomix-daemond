<?php declare(strict_types = 1);

namespace Mikk3lRo\atomix\Tests;

use Mikk3lRo\atomix\daemond\DaemonAbstract;
use Mikk3lRo\atomix\daemond\IpcCommand;
use Mikk3lRo\atomix\daemond\IpcError;
use Mikk3lRo\atomix\daemond\IpcSuccess;
use Mikk3lRo\atomix\logger\OutputLogger;

class TestDaemon extends DaemonAbstract
{
    public function __construct(string $controlScript, $additionalVersioningFiles = null, $nologger = false, $noIpc = false)
    {
        error_reporting(E_ALL);
        ini_set('display_errors', 'stderr');
        ini_set('error_log', '/var/log/php_errors');
        ini_set('log_errors', '1');

        if (!$noIpc) {
            $this->enableIpc(__DIR__ . '/test.ipc');
        }

        $this->maxLoopDuration = 4;
        $this->minLoopDuration = .2;

        $this->versionCheckInterval = .1;
        $this->addCliCommand('specialCLI', 'Call a function via IPC', function () {
            echo $this->sendIpc(new IpcCommand('specialIPC')) . "\n";
        });
        $this->addCliCommand('unknownCLI', 'Call a function via IPC', function () {
            echo $this->sendIpc(new IpcCommand('whatever')) . "\n";
        });
        $this->addCliCommand('withargsCLI', 'Make an IPC call with args', function ($theArg) {
            global $argv;
            echo $this->sendIpc(new IpcCommand('withargsIPC', array('theArg' => $argv[2]))) . "\n";
        }, array(
            'theArg' => 'A test argument'
        ));
        $this->addCliCommand('withinvalidargCLI', 'Make an IPC call with an invalid argument type', function () {
            echo $this->sendIpc(new IpcCommand('withargsIPC', array('theArg' => array('this' => 'should be a string instead...')))) . "\n";
        });

        $this->addIpcCommand('specialIPC', 'Call a special command', function () : \Mikk3lRo\atomix\daemond\IpcResponse {
            echo 'SpecialIPC.';
            return new IpcSuccess('SpecialIPC-output');
        });

        $this->addIpcCommand('withargsIPC', 'Call a special command', function (string $theArg) : \Mikk3lRo\atomix\daemond\IpcResponse {
            echo 'WithArgsIPC:' . $theArg . '.';
            return new IpcSuccess('WithArgsIPC-' . $theArg);
        }, array(
            'theArg' => 'A test argument'
        ));

        $this->addIpcCommand('failingIPC', 'Call a failing command', function () : \Mikk3lRo\atomix\daemond\IpcResponse {
            echo 'FailingIPC.';
            $response = new IpcError('FailingIPC-output');
            return $response;
        });
        $this->addCliCommand('failingIPC', 'Make an IPC call that returns an error', function () {
            echo $this->sendIpc(new IpcCommand('failingIPC')) . "\n";
        });


        if (!$nologger) {
            $logInstance = new OutputLogger();
            $this->setLogger($logInstance);
        }

        parent::__construct('TestDaemon', $controlScript, $additionalVersioningFiles);
    }


    public function defineInvalidIpcCommand()
    {
        $this->addIpcCommand('withargsIPC', 'This will trigger an error', function () {
        }, array(
            'nonExistingArg' => 'Should error...'
        ));
    }


    protected function beforeLoop(): void
    {
        echo 'BeforeLoop.';
    }


    protected function deconstruct(): void
    {
        echo 'Deconstruct.';
    }


    protected function reload(): void
    {
        echo 'Reload.';
    }


    private $loopcounter = 0;


    protected function eternalLoop(): void
    {
        $this->loopcounter++;
    }
}

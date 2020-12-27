<?php declare(strict_types = 1);

namespace Mikk3lRo\atomix\daemond\Traits;

use Exception;
use Mikk3lRo\atomix\daemond\IpcCommand;
use Mikk3lRo\atomix\daemond\IpcError;
use Mikk3lRo\atomix\daemond\IpcResponse;
use Mikk3lRo\atomix\daemond\IpcSuccess;
use Mikk3lRo\atomix\utilities\Reflections;
use Throwable;

trait IpcInvocationTrait
{
    /**
     * Keep track of possible IPC commands
     *
     * @var array
     */
    private $handlersIPC = array();

    /**
     * Filename to use for IPC.
     * @var string
     */
    private $ipcFilename = null;


    /**
     * Enables IPC for the daemon.
     *
     * @param string $ipcFilename Absolute path to a file used for IPC.
     *
     * @return void
     */
    protected function enableIpc(string $ipcFilename) : void
    {
        $this->ipcFilename = $ipcFilename;
        $this->addDefaultIpcCommands();
    }


    /**
     * Clean up IPC-command and -response files left from dead processes.
     *
     * @return void
     */
    protected function cleanIpc() : void
    {
        if ($this->ipcFilename) {
            if (file_exists($this->ipcFilename)) {
                $this->logger->warning("IPC command file from previous process found - and deleted...");
                unlink($this->ipcFilename);
            }
            foreach (glob($this->ipcFilename . '.*') as $responseFile) {
                $this->logger->warning("IPC response file from previous process found - and deleted...");
                unlink($this->responseFile);
            }
        }
    }


    /**
     * Send a command through IPC to trigger "something" in the running process.
     *
     * @param IpcCommand $command Command to run.
     *
     * @return IpcResponse An IpcResponse object with a payload.
     *
     * @throws Exception If IPC call fails.
     */
    protected function sendIpc(IpcCommand $command) : IpcResponse
    {
        if (!$this->ipcFilename) {
            return new IpcError('IPC not enabled!');
        }
        $timeoutLock = microtime(true) + 5;
        while (file_exists($this->ipcFilename)) {
            usleep(10000);
            if (microtime(true) > $timeoutLock) {
                throw new Exception('IPC file busy: ' . $this->ipcFilename);
            }
        }

        file_put_contents($this->ipcFilename, serialize($command));

        $timeoutSend = microtime(true) + 5;
        while (file_exists($this->ipcFilename)) {
            usleep(10000);
            if (microtime(true) > $timeoutSend) {
                unlink($this->ipcFilename);
                throw new Exception('IPC file not read by daemon :(');
            }
        }

        $timeoutReceive = microtime(true) + 5;
        while (!file_exists($this->ipcFilename . '.' . $command->id)) {
            usleep(10000);
            if (microtime(true) > $timeoutReceive) {
                throw new Exception('No response after 10 seconds :(');
            }
        }

        $ret = unserialize(file_get_contents($this->ipcFilename . '.' . $command->id));

        unlink($this->ipcFilename . '.' . $command->id);

        return $ret;
    }


    /**
     * Handle any waiting IPC call.
     *
     * @return void
     */
    public function handleIpc() : void
    {
        if ($this->ipcFilename && file_exists($this->ipcFilename)) {
            $cmd = unserialize(file_get_contents($this->ipcFilename));
            if (is_a($cmd, IpcCommand::class)) {
                unlink($this->ipcFilename);
                $response = $this->handleIpcCommand($cmd);

                file_put_contents($this->ipcFilename . '.' . $cmd->id, serialize($response));
            }
        }
    }


    /**
     * Adds IPC-commands common to all our daemons
     *
     * @return void
     */
    private function addDefaultIpcCommands() : void
    {
        $this->addIpcCommand('get_memory_usage', 'Get current and peak memory usage', function () : IpcResponse {
            return new IpcSuccess(array(
                'current' => memory_get_usage(true),
                'peak' => memory_get_peak_usage(true)
            ));
        });
    }


    /**
     * Handles calls through the IPC interface.
     *
     * @param IpcCommand $command Called command.
     *
     * @return IpcResponse An object with a "payload" which is the return of the called function.
     */
    private function handleIpcCommand(IpcCommand $command) : IpcResponse
    {
        if (!isset($this->handlersIPC[$command->command])) {
            return new IpcError('Unknown command: ' . $command->command);
        }
        try {
            $callable = $this->handlersIPC[$command->command]['callable'];
            $commandArgs = Reflections::getArgumentArrayForCallUserFunc($callable, $command->args);
            $response = call_user_func_array($callable, $commandArgs);
        } catch (Throwable $e) {
            return new IpcError($e->getMessage());
        }
        return $response;
    }


    /**
     * Add a command to the IPC interface
     *
     * @param string   $command     The action name.
     * @param string   $description A short description which is used to print usage help.
     * @param callable $callable    A callable function that is executed when the command is invoked.
     * @param array    $args        An optional array of arguments that will be passed to the function [argName] => 'Arg description'.
     *
     * @return void
     */
    protected function addIpcCommand(string $command, ?string $description, callable $callable, array $args = array()) : void
    {
        Reflections::checkArgumentsExistExact($callable, $args);

        Reflections::requireFunctionToHaveReturnType($callable, IpcResponse::class);

        $this->handlersIPC[$command] = array(
            'args' => $args,
            'description' => $description,
            'callable' => $callable
        );
    }
}

<?php declare(strict_types = 1);

namespace Mikk3lRo\atomix\daemond;

class IpcCommand
{
    /**
     * Random id to identify response.
     *
     * @var string
     */
    public $id;

    /**
     * Name of command.
     *
     * @var string
     */
    public $command;

    /**
     * Optional arguments to command.
     *
     * @var array
     */
    public $args = [];


    /**
     * Simple object representing an IPC command.
     *
     * @param string $command The command name.
     * @param array  $args    An optional array of arguments.
     */
    public function __construct(string $command, array $args = [])
    {
        $this->id = \Mikk3lRo\atomix\utilities\Random::token();

        $this->command = $command;

        $this->args = $args;
    }
}

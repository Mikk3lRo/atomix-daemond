<?php declare(strict_types = 1);

namespace Mikk3lRo\atomix\daemond;

class IpcSuccess extends IpcResponse
{
    /**
     * Response status
     *
     * @var integer
     */
    protected $status = 1;
}

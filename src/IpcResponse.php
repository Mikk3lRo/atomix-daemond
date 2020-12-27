<?php declare(strict_types = 1);

namespace Mikk3lRo\atomix\daemond;

class IpcResponse
{
    /**
     * Response payload
     *
     * @var mixed
     */
    public $payload = null;

    /**
     * Response status
     *
     * @var integer
     */
    protected $status = 0;


    /**
     * Simple object representing an IPC response.
     *
     * @param mixed $payload The response payload (if any).
     */
    public function __construct($payload = null)
    {
        $this->payload = $payload;
    }


    /**
     * Is the request successful?
     *
     * @return boolean True if successful
     */
    public function isSuccess() : bool
    {
        return $this->status == 1;
    }


    /**
     * Make it easy to echo the response.
     *
     * @return string
     */
    public function __toString()
    {
        if ($this->isSuccess()) {
            return var_export($this->payload, true);
        }
        return 'Error: ' . var_export($this->payload, true);
    }
}

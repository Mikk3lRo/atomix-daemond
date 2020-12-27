<?php declare(strict_types = 1);

namespace Mikk3lRo\atomix\daemond\Traits;

use Mikk3lRo\atomix\daemond\IpcCommand;
use Mikk3lRo\atomix\daemond\Systemctl;
use Mikk3lRo\atomix\utilities\Formatters;
use Mikk3lRo\atomix\utilities\Reflections;

trait PeriodicActionsTrait
{
    /**
     * Keep track of all periodic actions
     *
     * @var array
     */
    private $periodicActions = array();


    /**
     * Adds a command that will run every so often.
     *
     * @param callable $callable          A callable function that is executed when the command is invoked.
     * @param float    $intervalInSeconds How often the callable should be executed.
     *
     * @return void
     */
    protected function addPeriodicAction(callable $callable, float $intervalInSeconds) : void
    {
        $this->periodicActions[] = array(
            'callable' => $callable,
            'interval' => $intervalInSeconds,
            'lastRun' => 0,
        );
    }


    /**
     * Do actions that are past their interval.
     *
     * @return void
     */
    protected function doPeriodicActions() : void
    {
        $looptime = microtime(true);
        foreach ($this->periodicActions as $periodicAction) {
            if ($looptime >= $periodicAction['lastRun'] + $periodicAction['interval']) {
                $periodicAction['lastRun'] = $looptime;
                call_user_func($periodicAction['callable']);
            }
        }
    }
}

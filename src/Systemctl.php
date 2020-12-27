<?php declare(strict_types = 1);

namespace Mikk3lRo\atomix\daemond;

class Systemctl
{
    /**
     * Enable a service.
     *
     * @param string $service Name of the service.
     *
     * @return string The output from the systemctl call.
     */
    public static function enable(string $service) : ?string
    {
        $cmd = 'systemctl enable ' . escapeshellarg($service) . ' 2>&1';
        return `$cmd`;
    }


    /**
     * Disable a service.
     *
     * @param string $service Name of the service.
     *
     * @return string The output from the systemctl call.
     */
    public static function disable(string $service) : ?string
    {
        $cmd = 'systemctl disable ' . escapeshellarg($service) . ' 2>&1';
        return `$cmd`;
    }


    /**
     * Get status for a service.
     *
     * @param string $service Name of the service.
     *
     * @return string The output from the systemctl call.
     */
    public static function status(string $service) : string
    {
        $cmd = 'SYSTEMD_COLORS=1 systemctl -l status ' . escapeshellarg($service) . ' 2>&1';
        return `$cmd`;
    }


    /**
     * Restart a service.
     *
     * @param string $service Name of the service.
     *
     * @return string The output from the systemctl call.
     */
    public static function restart(string $service) : ?string
    {
        $cmd = 'systemctl restart ' . escapeshellarg($service) . ' 2>&1';
        return `$cmd`;
    }


    /**
     * Reload a service.
     *
     * @param string $service Name of the service.
     *
     * @return string The output from the systemctl call.
     */
    public static function reload(string $service) : ?string
    {
        $cmd = 'systemctl reload ' . escapeshellarg($service) . ' 2>&1';
        return `$cmd`;
    }


    /**
     * Start a service.
     *
     * @param string $service Name of the service.
     *
     * @return string The output from the systemctl call.
     */
    public static function start(string $service) : ?string
    {
        $cmd = 'systemctl start ' . escapeshellarg($service) . ' 2>&1';
        return `$cmd`;
    }


    /**
     * Stop a service.
     *
     * @param string $service Name of the service.
     *
     * @return string The output from the systemctl call.
     */
    public static function stop(string $service) : ?string
    {
        $cmd = 'systemctl stop ' . escapeshellarg($service) . ' 2>&1';
        return `$cmd`;
    }


    /**
     * Check if a service is installed.
     *
     * @param string $service Name of the service.
     *
     * @return boolean
     */
    public static function isInstalled(string $service) : bool
    {
        return (trim(`systemctl is-active $service`) !== 'unknown');
    }


    /**
     * Check if a service is enabled.
     *
     * @param string $service Name of the service.
     *
     * @return boolean
     */
    public static function isEnabled(string $service) : bool
    {
        if (!self::isInstalled($service)) {
            return false;
        }
        return (trim(`systemctl is-enabled $service`) === 'enabled');
    }


    /**
     * Check if a service is running.
     *
     * @param string $service Name of the service.
     *
     * @return boolean
     */
    public static function isActive(string $service) : bool
    {
        return (trim(`systemctl is-active $service`) === 'active');
    }


    /**
     * Reload the list of services known by systemd.
     *
     * @return void
     */
    public static function reloadServices() : void
    {
        `systemctl daemon-reload`;
    }
}
